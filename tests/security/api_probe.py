#!/usr/bin/env python3
"""
Black-box security probe for the Trik API. Run it against local or staging, never production:

    python3 tests/security/api_probe.py http://localhost:8099 --db "host=127.0.0.1 port=5433 user=trik password=trik dbname=trik_dev"

Needs APP_ENV != production (it uses the fake WhatsApp provider) and direct database access to seed
members with chosen roles (there is no invitation endpoint yet). Each probe prints PASS (the API
defended itself) or FAIL (a finding), with the evidence. Exit code = number of FAILs.
"""
import argparse
import json
import subprocess
import sys
import uuid

import requests

ap = argparse.ArgumentParser()
ap.add_argument("base")
ap.add_argument("--db", required=True, help="libpq connection string for seeding members")
args = ap.parse_args()
BASE = args.base.rstrip("/")
results = []


def check(name, ok, evidence=""):
    results.append((name, ok, evidence))
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else f"\n     ↳ {evidence}"))


def sql(q):
    return subprocess.run(["psql", args.db, "-tAc", q], capture_output=True, text=True, check=True).stdout.strip()


def call(method, path, token=None, ws=None, body=None, headers=None, raw=None):
    h = {"Accept": "application/json"}
    if token:
        h["Authorization"] = f"Bearer {token}"
    if ws:
        h["X-Workspace-Id"] = ws
    h.update(headers or {})
    if raw is not None:
        h.setdefault("Content-Type", "application/json")
        return requests.request(method, BASE + path, headers=h, data=raw, timeout=60)
    return requests.request(method, BASE + path, headers=h, json=body, timeout=60)


def signup(tag, verify=True):
    email = f"{tag}-{uuid.uuid4().hex[:8]}@probe.test"
    r = call("POST", "/v1/auth/register", body={
        "name": tag, "email": email, "password": "Probe-pass-123", "password_confirmation": "Probe-pass-123",
        "workspace_name": f"{tag} store", "device_name": "probe",
        # mass-assignment attempts
        "email_verified_at": "2020-01-01", "id": str(uuid.uuid4()), "is_admin": True, "role": "owner",
    })
    assert r.status_code == 201, r.text
    j = r.json()
    if verify:  # as if the emailed link was opened
        sql(f"update users set email_verified_at = now() where id = '{j['user']['id']}'")
    return {"token": j["token"], "ws": j["workspace"]["id"], "id": j["user"]["id"], "email": email, "verified": j["user"]["email_verified"]}


def member(ws, role, custom_role=None):
    u = signup("member")
    sql(f"insert into workspaces_members (id, workspace_id, user_id, role, custom_role_id, created_at, updated_at) "
        f"values (gen_random_uuid(), '{ws}', '{u['id']}', '{role}', {('%s' % repr(custom_role)) if custom_role else 'null'}, now(), now())")
    return u


A = signup("alice")
B = signup("bob")
check("mass assignment on sign-up is ignored", A["verified"] is False, "email_verified_at was accepted")

r = call("POST", "/v1/whatsapp/numbers", A["token"], A["ws"], {"provider": "fake", "external_id": f"pn-{uuid.uuid4().hex[:6]}", "credentials": {"api_key": "k"}})
assert r.status_code == 201, r.text
NA = r.json()["data"]["id"]

# ---------------- tenant isolation (IDOR)
r = call("GET", "/v1/whatsapp/numbers", B["token"], A["ws"])
check("cannot act in a workspace you are not a member of", r.status_code == 403, r.status_code)
for method, path, body in [
    ("GET", f"/v1/whatsapp/numbers/{NA}/messages", None),
    ("POST", f"/v1/whatsapp/numbers/{NA}/messages", {"to": "01012345678", "type": "text", "text": "x"}),
    ("GET", f"/v1/whatsapp/numbers/{NA}/templates", None),
    ("DELETE", f"/v1/whatsapp/numbers/{NA}/templates/x", None),
]:
    r = call(method, path, B["token"], B["ws"], body, {"Idempotency-Key": str(uuid.uuid4())})
    check(f"IDOR {method} {path.replace(NA, '{A-number}')} from another workspace", r.status_code == 404, f"{r.status_code} {r.text[:120]}")
r = call("PATCH", f"/v1/workspace/members/{A['id']}", B["token"], B["ws"], {"role": "agent"})
check("cannot change a member of another workspace", r.status_code == 404, r.status_code)
for bad in ["not-a-uuid", "' or 1=1 --", A["ws"] + "' --"]:
    r = call("GET", "/v1/workspace", A["token"], bad)
    check(f"bad workspace header rejected ({bad[:12]})", r.status_code == 400, r.status_code)

# ---------------- privilege escalation
agent = member(A["ws"], "agent")
r = call("POST", "/v1/whatsapp/numbers", agent["token"], A["ws"], {"provider": "fake", "external_id": "x", "credentials": {"api_key": "k"}})
check("agent cannot connect numbers", r.status_code == 403, r.status_code)
r = call("PATCH", f"/v1/workspace/members/{agent['id']}", agent["token"], A["ws"], {"role": "owner"})
check("agent cannot promote themselves", r.status_code == 403, r.status_code)

r = call("POST", "/v1/workspace/roles", A["token"], A["ws"], {"name": f"role-editor-{uuid.uuid4().hex[:4]}", "permissions": ["workspace.roles.manage", "workspace.members.view"]})
role_editor = r.json()["data"]["id"]
editor = member(A["ws"], "custom", role_editor)
r = call("PATCH", f"/v1/workspace/roles/{role_editor}", editor["token"], A["ws"], {"permissions": ["workspace.roles.manage", "whatsapp.numbers.manage", "workspace.members.manage"]})
check("a role editor cannot grant their own role new powers", r.status_code in (403, 422), f"{r.status_code}: edited own role to add numbers.manage and members.manage")

admin = member(A["ws"], "admin")
r = call("POST", "/v1/workspace/roles", admin["token"], A["ws"], {"name": f"billing-{uuid.uuid4().hex[:4]}", "permissions": ["billing.*"]})
check("an admin cannot create a role with powers admins lack (billing)", r.status_code in (403, 422), f"{r.status_code}: created a billing.* role")

# ---------------- authentication
r = call("POST", "/v1/auth/login", body={"email": "' OR 1=1 --", "password": "x", "device_name": "p"})
check("SQL-ish email rejected by validation", r.status_code == 422, r.status_code)
codes = [call("POST", "/v1/auth/login", body={"email": B["email"], "password": "wrong", "device_name": "p"},
              headers={"X-Forwarded-For": f"10.0.0.{i}"}).status_code for i in range(7)]
check("lockout not bypassed with X-Forwarded-For", codes[-1] == 429, codes)
C = signup("carol", verify=False)
r = call("POST", "/v1/whatsapp/numbers", C["token"], C["ws"], {"provider": "fake", "external_id": f"u-{uuid.uuid4().hex[:6]}", "credentials": {"api_key": "k"}})
check("unverified email cannot connect a WhatsApp number", r.status_code == 403, f"{r.status_code}: unverified account connected a number")
key = str(uuid.uuid4())
login_body = {"email": A["email"], "password": "Probe-pass-123", "device_name": "p"}
r1 = call("POST", "/v1/auth/login", body=login_body, headers={"Idempotency-Key": key})
r2 = call("POST", "/v1/auth/login", body=login_body, headers={"Idempotency-Key": key})
check("sign-in tokens are never replayed from the idempotency cache", r2.headers.get("Idempotent-Replayed") != "true", "login response with token was cached and replayed")

# ---------------- input handling
r = call("GET", f"/v1/whatsapp/numbers/{NA}/messages?contact=1'%20or%20'1'='1", A["token"], A["ws"])
check("bad contact filter answers 422, not 500", r.status_code == 422, r.status_code)
r = call("GET", f"/v1/whatsapp/numbers/{NA}/messages?per_page=100000&cursor=garbage", A["token"], A["ws"])
check("per_page capped and garbage cursor tolerated", r.status_code == 200 and r.json()["meta"]["per_page"] == 100, f"{r.status_code} {r.text[:100]}")
r = call("POST", "/v1/whatsapp/numbers", A["token"], A["ws"], {"provider": "meta_cloud", "external_id": "123/../../me?fields=x", "waba_id": "1", "credentials": {"access_token": "t"}})
check("provider ids are validated (no path injection into provider URLs)", r.status_code == 422 and r.json().get("code") == "validation-failed", f"{r.status_code} {r.text[:120]}")

# ---------------- webhooks
r = call("POST", f"/webhooks/whatsapp/fake/{NA}", body={"entry": []})
check("webhook without secret rejected", r.status_code == 401, r.status_code)
r = call("POST", f"/webhooks/whatsapp/fake/{NA}", body={}, headers={"X-Trik-Webhook-Secret": "guess"})
check("webhook with wrong secret rejected", r.status_code == 401, r.status_code)
r = call("POST", "/webhooks/whatsapp/meta_cloud", body={"entry": []})
check("Meta webhook without signature rejected", r.status_code == 401, r.status_code)
r = call("GET", "/webhooks/whatsapp/meta_cloud?hub.mode=subscribe&hub.verify_token=&hub.challenge=1")
check("Meta handshake refused when no verify token is configured", r.status_code == 403, r.status_code)
big = '{"entry":[{"x":"' + "A" * (3 * 1024 * 1024) + '"}]}'
r = call("POST", f"/webhooks/whatsapp/fake/{uuid.uuid4()}", raw=big)
check("oversized webhook body refused before any work", r.status_code == 413, f"{r.status_code} for a 3 MB body")
codes = {call("POST", f"/webhooks/whatsapp/fake/{uuid.uuid4()}", body={}).status_code for _ in range(400)}
check("webhook endpoint throttles floods of bad requests", 429 in codes, f"400 junk requests got {sorted(codes)}")

# ---------------- transport and browser
r = requests.options(BASE + "/v1/auth/login", headers={"Origin": "https://evil.example", "Access-Control-Request-Method": "POST"})
check("CORS does not allow arbitrary origins", r.headers.get("Access-Control-Allow-Origin") not in ("*", "https://evil.example"), r.headers.get("Access-Control-Allow-Origin"))
r = requests.options(BASE + "/v1/auth/login", headers={"Origin": "http://localhost:3000", "Access-Control-Request-Method": "POST"})
check("CORS allows the web app with credentials", r.headers.get("Access-Control-Allow-Origin") == "http://localhost:3000" and r.headers.get("Access-Control-Allow-Credentials") == "true", dict(r.headers))
r = call("GET", "/healthz")
check("nosniff header set", r.headers.get("X-Content-Type-Options") == "nosniff", r.headers.get("X-Content-Type-Options"))
r = call("GET", "/v1/does-not-exist")
check("errors do not leak internals", "vendor/" not in r.text and "Stack" not in r.text, r.text[:200])

fails = [n for n, ok, _ in results if not ok]
print(f"\n{len(results) - len(fails)} passed, {len(fails)} failed")
sys.exit(len(fails))
