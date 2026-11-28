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
ap.add_argument("--shopify-secret", default="probe-shopify-secret", help="SHOPIFY_CLIENT_SECRET of the app under test")
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

# ---------------- organizations (ADR 0012)
r = call("GET", "/v1/auth/me", A["token"])
org_a = r.json()["organizations"][0]["id"]
r = requests.get(BASE + "/v1/organization", headers={"Accept": "application/json", "Authorization": f"Bearer {B['token']}", "X-Organization-Id": org_a})
check("cannot open another person's organization", r.status_code == 403, r.status_code)
r = requests.post(BASE + f"/v1/organization/workspaces/{B['ws']}/members", json={"user_id": A["id"], "role": "agent"},
                  headers={"Accept": "application/json", "Authorization": f"Bearer {A['token']}", "X-Organization-Id": org_a})
check("cannot add people to another organization's workspace", r.status_code == 404, r.status_code)
r = call("POST", "/v1/workspace/roles", A["token"], A["ws"], {"name": "org-sneak", "permissions": ["organization.members.manage"]})
check("organization powers cannot go into a workspace role", r.status_code == 422, r.status_code)

# ---------------- inbox
CA, CB, CON, OLD = (str(uuid.uuid4()) for _ in range(4))
sql(f"insert into contacts_contacts (id, workspace_id, phone, name, created_at, updated_at) values "
    f"('{CA}', '{A['ws']}', '201000000001', 'probe', now(), now()), ('{CB}', '{A['ws']}', '201000000002', 'old', now(), now())")
sql(f"insert into inbox_conversations (id, workspace_id, contact_id, number_id, unread_count, last_message_at, last_message_direction, last_message_type, last_inbound_at, created_at, updated_at) values "
    f"('{CON}', '{A['ws']}', '{CA}', '{NA}', 1, now(), 'in', 'text', now(), now(), now()), "
    f"('{OLD}', '{A['ws']}', '{CB}', '{NA}', 1, now() - interval '2 days', 'in', 'text', now() - interval '2 days', now(), now())")
for method, path, body in [
    ("GET", f"/v1/inbox/conversations/{CON}", None),
    ("PATCH", f"/v1/inbox/conversations/{CON}", {"status": "closed"}),
    ("POST", f"/v1/inbox/conversations/{CON}/read", None),
    ("GET", f"/v1/inbox/conversations/{CON}/messages", None),
    ("POST", f"/v1/inbox/conversations/{CON}/messages", {"type": "text", "text": "x"}),
]:
    r = call(method, path, B["token"], B["ws"], body, {"Idempotency-Key": str(uuid.uuid4())})
    check(f"IDOR {method} {path.replace(CON, '{A-conversation}')} from another workspace", r.status_code == 404, f"{r.status_code} {r.text[:120]}")
r = call("GET", "/v1/auth/me", B["token"])
check("badges in /me cover only your own workspaces", [w["id"] for w in r.json()["workspaces"]] == [B["ws"]] and r.json()["workspaces"][0]["badges"]["unread"] == 0, r.text[:200])
r = call("GET", "/v1/inbox/conversations", B["token"], B["ws"])
check("another workspace's inbox list is empty", r.status_code == 200 and r.json()["data"] == [], r.text[:200])
r = call("PATCH", f"/v1/inbox/conversations/{CON}", agent["token"], A["ws"], {"assignee_id": admin["id"]})
check("an agent cannot assign conversations to others", r.status_code == 403, r.status_code)
r = call("PATCH", f"/v1/inbox/conversations/{CON}", A["token"], A["ws"], {"assignee_id": B["id"]})
check("conversations cannot be assigned to someone outside the team", r.status_code == 422, r.status_code)
r = call("POST", f"/v1/inbox/conversations/{OLD}/messages", A["token"], A["ws"], {"type": "text", "text": "late"}, {"Idempotency-Key": str(uuid.uuid4())})
check("free-form reply refused after the 24-hour window", r.status_code == 409 and r.json().get("code") == "window-closed", f"{r.status_code} {r.text[:120]}")
r = call("GET", "/v1/inbox/conversations?cursor=eyJsYXN0X21lc3NhZ2VfYXQiOiInIG9yIDE9MSAtLSIsImlkIjoieCIsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0", A["token"], A["ws"])
check("crafted inbox cursor answers 400", r.status_code == 400, r.status_code)

# ---------------- orders
r = call("POST", "/v1/orders", A["token"], A["ws"], {"customer_phone": "01000000001", "items": [{"title": "probe", "quantity": 1, "unit_price": 1000}],
         "address": {"raw": "x"}, "workspace_id": B["ws"], "status": "delivered", "total": 1, "contact_id": None}, {"Idempotency-Key": str(uuid.uuid4())})
check("order create ignores forged fields (status, total, workspace)", r.status_code == 201 and r.json()["data"]["status"] == "new" and r.json()["data"]["total"]["amount"] == 1000, f"{r.status_code} {r.text[:160]}")
OA = r.json()["data"]["id"] if r.status_code == 201 else str(uuid.uuid4())
for method, path, body in [
    ("GET", f"/v1/orders/{OA}", None), ("PATCH", f"/v1/orders/{OA}", {"note": "x"}),
    ("POST", f"/v1/orders/{OA}/confirm", None), ("POST", f"/v1/orders/{OA}/cancel", None),
    ("GET", f"/v1/orders/{OA}/timeline", None), ("GET", f"/v1/inbox/conversations/{CON}/orders", None),
]:
    r = call(method, path, B["token"], B["ws"], body)
    check(f"IDOR {method} {path.replace(OA, '{A-order}').replace(CON, '{A-conversation}')} from another workspace", r.status_code == 404, f"{r.status_code} {r.text[:120]}")
r = call("POST", "/v1/orders", B["token"], B["ws"], {"contact_id": CA, "items": [{"title": "x", "quantity": 1, "unit_price": 1}]}, {"Idempotency-Key": str(uuid.uuid4())})
check("cannot create an order for another workspace's contact", r.status_code == 422, f"{r.status_code} {r.text[:120]}")
r = call("POST", "/v1/orders", A["token"], A["ws"], {"customer_phone": "01000000001", "items": [{"title": "x", "quantity": -5, "unit_price": 100}]}, {"Idempotency-Key": str(uuid.uuid4())})
check("negative quantities are refused", r.status_code == 422, r.status_code)

# ---------------- confirmation engine
r = call("PUT", "/v1/confirmation/settings", agent["token"], A["ws"], {"enabled": False})
check("an agent cannot change confirmation settings", r.status_code == 403, r.status_code)
r = call("PUT", "/v1/confirmation/settings", B["token"], B["ws"], {"number_id": NA})
check("confirmation cannot send from another workspace's number", r.status_code == 422, r.status_code)
r = call("PUT", "/v1/confirmation/settings", A["token"], A["ws"], {"template_name": "x'); drop table orders_orders; --"})
check("template names are restricted to Meta's format", r.status_code == 422, r.status_code)
r = call("GET", f"/v1/orders/{OA}/confirmation", B["token"], B["ws"])
check("IDOR GET /v1/orders/{A-order}/confirmation from another workspace", r.status_code == 404, r.status_code)

r = call("POST", "/v1/confirmation/bulk", B["token"], B["ws"], {"action": "cancel", "order_ids": [OA]}, {"Idempotency-Key": str(uuid.uuid4())})
check("bulk actions cannot touch another workspace's orders", r.status_code == 200 and r.json()["data"][0]["result"] == "not_found", f"{r.status_code} {r.text[:160]}")
r = call("GET", "/v1/orders/board?governorate=C%27%20or%201%3D1--", A["token"], A["ws"])
check("board filters are validated", r.status_code == 422, r.status_code)
r = call("PATCH", f"/v1/orders/{OA}", A["token"], A["ws"], {"owner_id": B["id"]})
check("orders cannot be given to someone outside the team", r.status_code == 422, r.status_code)

# ---------------- authentication
r = call("POST", "/v1/auth/login", body={"email": "' OR 1=1 --", "password": "x", "device_name": "p"})
check("SQL-ish email rejected by validation", r.status_code == 422, r.status_code)
# Rotating X-Forwarded-For: through the real proxy it is overwritten; probing from loopback (a trusted
# proxy address) it is honoured, so the per-account limit (20 failures/hour, any IP) must still stop it.
codes = [call("POST", "/v1/auth/login", body={"email": B["email"], "password": "wrong", "device_name": "p"},
              headers={"X-Forwarded-For": f"10.0.{i // 250}.{i % 250}"}).status_code for i in range(22)]
check("lockout not bypassed by rotating IPs", codes[-1] == 429, codes)
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
r = call("GET", f"/v1/whatsapp/numbers/{NA}/messages?per_page=100000", A["token"], A["ws"])
check("per_page capped", r.status_code == 200 and r.json()["meta"]["per_page"] == 100, f"{r.status_code} {r.text[:100]}")
r = call("GET", f"/v1/whatsapp/numbers/{NA}/messages?cursor=garbage", A["token"], A["ws"])
check("garbage cursor answers 400", r.status_code == 400, f"{r.status_code} {r.text[:100]}")
r = call("POST", "/v1/whatsapp/numbers", A["token"], A["ws"], {"provider": "meta_cloud", "external_id": "123/../../me?fields=x", "waba_id": "1", "credentials": {"access_token": "t"}})
check("provider ids are validated (no path injection into provider URLs)", r.status_code == 422 and r.json().get("code") == "validation-failed", f"{r.status_code} {r.text[:120]}")

import base64
evil_cursor = base64.urlsafe_b64encode(json.dumps({"created_at": "x' or 1=1 --", "id": "nope", "_pointsToNextItems": True}).encode()).decode().rstrip("=")
r = call("GET", f"/v1/whatsapp/numbers/{NA}/messages?cursor={evil_cursor}", A["token"], A["ws"])
check("crafted pagination cursor answers 4xx, not 500", 400 <= r.status_code < 500, f"{r.status_code} {r.text[:120]}")
nested = "[" * 5000 + "]" * 5000
r = call("POST", "/v1/auth/login", raw=nested)
check("deeply nested JSON is refused cleanly", 400 <= r.status_code < 500, f"{r.status_code} {r.text[:120]}")
r = call("POST", "/v1/workspaces", A["token"], body={"name": "x"}, headers={"X-HTTP-Method-Override": "DELETE"})
check("method override is refused", r.status_code == 400, r.status_code)
r = call("DELETE", "/v1/auth/me", A["token"])
check("wrong method answers 405 problem+json", r.status_code == 405 and r.headers.get("Content-Type", "").startswith("application/problem+json"), f"{r.status_code} {r.headers.get('Content-Type')}")

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

# ---------------- store connectors (Shopify)
import base64 as _b64
import hashlib
import hmac as _hmac
import time

SHOP = f"probe-{uuid.uuid4().hex[:8]}.myshopify.com"
AGENT = member(A["ws"], "agent")


def shopify_hook(topic, payload, shop=SHOP, secret=None, delivery=None):
    raw = json.dumps(payload)
    sig = _b64.b64encode(_hmac.new((secret or args.shopify_secret).encode(), raw.encode(), hashlib.sha256).digest()).decode()
    return call("POST", "/webhooks/shopify", raw=raw, headers={
        "X-Shopify-Topic": topic, "X-Shopify-Shop-Domain": shop, "X-Shopify-Hmac-Sha256": sig,
        "X-Shopify-Webhook-Id": delivery or str(uuid.uuid4())})


r = call("POST", "/v1/commerce/shopify/install", AGENT["token"], A["ws"], {"shop": SHOP})
check("an agent cannot connect a store", r.status_code == 403, r.status_code)
for evil in ["x.myshopify.com.evil.io", "evil.io", "x.myshopify.com@evil.io", "x.myshopify.com:444", "127.0.0.1", "169.254.169.254"]:
    r = call("POST", "/v1/commerce/shopify/install", A["token"], A["ws"], {"shop": evil})
    check(f"install refuses a non-Shopify host ({evil})", r.status_code == 422, f"{r.status_code} {r.text[:120]}")
r = call("POST", "/v1/commerce/shopify/install", A["token"], A["ws"], {"shop": SHOP})
check("install returns Shopify's consent URL for the shop only", r.status_code == 200 and r.json()["data"]["authorize_url"].startswith(f"https://{SHOP}/admin/oauth/authorize?"), f"{r.status_code} {r.text[:160]}")

q = f"code=c&shop={SHOP}&state=forged&timestamp={int(time.time())}&hmac=" + "0" * 64
r = requests.get(BASE + "/webhooks/shopify/oauth/callback?" + q, allow_redirects=False, timeout=30)
check("forged OAuth callback is refused", r.status_code in (302, 303) and "shopify_error=invalid-signature" in r.headers.get("Location", ""), f"{r.status_code} {r.headers.get('Location')}")
r = requests.get(BASE + f"/webhooks/shopify/app?shop={SHOP}&timestamp={int(time.time())}&hmac=" + "0" * 64, allow_redirects=False, timeout=30)
check("forged app launch is refused", "shopify_error=invalid-signature" in r.headers.get("Location", ""), f"{r.status_code} {r.headers.get('Location')}")

store_id = str(uuid.uuid4())
sql(f"insert into commerce_stores (id, workspace_id, platform, shop_domain, scopes, status, backfill, created_at, updated_at) "
    f"values ('{store_id}', '{A['ws']}', 'shopify', '{SHOP}', 'read_orders', 'active', '{{}}', now(), now())")

r = call("GET", "/v1/commerce/stores", B["token"], B["ws"])
check("another workspace does not see the store", r.status_code == 200 and all(s["id"] != store_id for s in r.json()["data"]), r.text[:160])
r = call("GET", "/v1/commerce/stores", B["token"], A["ws"])
check("a non-member cannot list a workspace's stores", r.status_code in (403, 404), r.status_code)
r = call("DELETE", f"/v1/commerce/stores/{store_id}", B["token"], B["ws"])
check("another workspace cannot disconnect the store (IDOR)", r.status_code == 404, r.status_code)
r = call("POST", f"/v1/commerce/stores/{store_id}/backfill", B["token"], B["ws"], {}, headers={"Idempotency-Key": str(uuid.uuid4())})
check("another workspace cannot re-import the store (IDOR)", r.status_code == 404, r.status_code)
r = call("DELETE", f"/v1/commerce/stores/{store_id}", AGENT["token"], A["ws"])
check("an agent cannot disconnect the store", r.status_code == 403, r.status_code)
r = call("GET", "/v1/commerce/stores", A["token"], A["ws"])
check("store listing never returns the token", r.status_code == 200 and "access_token" not in r.text, r.text[:160])
r = call("PATCH", f"/v1/commerce/stores/{store_id}", B["token"], B["ws"], {"settings": {"cancel_in_store": True}})
check("another workspace cannot change a store's write-back (IDOR)", r.status_code == 404, r.status_code)
r = call("PATCH", f"/v1/commerce/stores/{store_id}", AGENT["token"], A["ws"], {"settings": {"cancel_in_store": True}})
check("an agent cannot turn on cancelling orders in the store", r.status_code == 403, r.status_code)
r = call("PATCH", f"/v1/commerce/stores/{store_id}", A["token"], A["ws"], {"settings": {"access_token": "x", "status": "active"}})
check("store settings refuse unknown fields (no mass assignment)", r.status_code == 422, f"{r.status_code} {r.text[:120]}")

order = {"id": 990000000 + int(uuid.uuid4().int % 1000000), "name": "#P1", "created_at": "2026-11-27T10:00:00Z", "updated_at": "2026-11-27T10:00:00Z",
         "financial_status": "pending", "currency": "EGP", "current_total_price": "100.00",
         "shipping_address": {"phone": "01012345678", "address1": "x", "city": "y", "province_code": "C", "country_code": "EG"},
         "line_items": [{"id": 1, "title": "t", "quantity": 1, "price": "100.00"}]}
r = shopify_hook("orders/create", order, secret="attacker")
check("Shopify webhook with a wrong signature is refused", r.status_code == 401, r.status_code)
r = call("POST", "/webhooks/shopify", raw=json.dumps(order), headers={"X-Shopify-Topic": "orders/create", "X-Shopify-Shop-Domain": SHOP})
check("Shopify webhook without a signature is refused", r.status_code == 401, r.status_code)
r = shopify_hook("orders/create", order, shop="evil.io")
check("Shopify webhook with a forged shop header is refused", r.status_code == 400, r.status_code)
delivery = str(uuid.uuid4())
r1 = shopify_hook("orders/create", order, delivery=delivery)
r2 = shopify_hook("orders/create", order, delivery=delivery)
stored = sql(f"select count(*) || ' ' || coalesce(string_agg(distinct workspace_id::text, ','), '') from commerce_webhook_events where store_id = '{store_id}'")
check("a signed delivery is stored once, in the store's workspace, however often it is sent",
      r1.status_code == 200 and r2.status_code == 200 and stored == f"1 {A['ws']}", f"{r1.status_code} {r2.status_code} stored={stored}")
time.sleep(3)
landed = sql(f"select string_agg(distinct workspace_id::text, ',') from orders_orders where source = 'shopify' and external_id = '{order['id']}'")
# processed only where a queue worker runs: never in another workspace
check("the order never lands in another workspace", landed in ("", A["ws"]), f"landed in {landed}")

# ---------------- EasyOrders
r = call("POST", "/v1/commerce/easyorders/install", AGENT["token"], A["ws"])
check("an agent cannot start an EasyOrders install", r.status_code == 403, r.status_code)
r = call("POST", f"/webhooks/easyorders/install/{uuid.uuid4().hex}{uuid.uuid4().hex[:16]}", body={"api_key": "attacker", "store_id": str(uuid.uuid4())})
check("a forged EasyOrders install callback is refused", r.status_code == 404, f"{r.status_code} {r.text[:120]}")
r = call("POST", f"/webhooks/easyorders/{uuid.uuid4().hex}{uuid.uuid4().hex[:16]}", body={"id": str(uuid.uuid4())})
check("an unknown EasyOrders webhook URL is refused", r.status_code == 404, r.status_code)
r = call("POST", "/v1/commerce/easyorders/install", A["token"], A["ws"])
eo_url = r.json().get("data", {}).get("install_url", "") if r.status_code == 200 else ""
check("the EasyOrders install link only points at EasyOrders", eo_url.startswith("https://app.easy-orders.net/#/install-app?"), eo_url[:80])
eo_store = str(uuid.uuid4())
sql(f"insert into commerce_stores (id, workspace_id, platform, shop_domain, scopes, status, backfill, settings, created_at, updated_at) "
    f"values ('{eo_store}', '{A['ws']}', 'easyorders', '{uuid.uuid4()}', 'orders:read', 'active', '{{}}', '{{}}', now(), now())")
r = call("PATCH", f"/v1/commerce/stores/{eo_store}", A["token"], A["ws"], {"settings": {"cancel_in_store": True}})
check("a setting of another platform is refused", r.status_code == 422, f"{r.status_code} {r.text[:120]}")
r = call("PATCH", f"/v1/commerce/stores/{eo_store}", B["token"], B["ws"], {"settings": {"write_status": False}})
check("another workspace cannot change an EasyOrders store (IDOR)", r.status_code == 404, r.status_code)

# ---------------- refusal risk
r = call("PUT", "/v1/risk/settings", AGENT["token"], A["ws"], {"threshold": 99})
check("an agent cannot change the risk threshold", r.status_code == 403, r.status_code)
r = call("PUT", "/v1/risk/settings", A["token"], A["ws"], {"weights": {"first_order": 500, "is_admin": 1}})
check("risk weights are bounded and unknown rules refused", r.status_code == 422, f"{r.status_code} {r.text[:120]}")
r = call("PUT", "/v1/risk/settings", B["token"], A["ws"], {"threshold": 1})
check("a non-member cannot change another workspace's risk settings", r.status_code in (403, 404), r.status_code)
risk_order = sql(f"select id from orders_orders where workspace_id = '{A['ws']}' limit 1")
if risk_order:
    r = call("GET", f"/v1/orders/{risk_order}/risk", B["token"], B["ws"])
    check("another workspace cannot read an order's risk (IDOR)", r.status_code == 404, r.status_code)

# ---------------- pilot results
r = call("GET", "/v1/analytics/pilot", AGENT["token"], A["ws"])
check("an agent cannot see the pilot results", r.status_code == 403, r.status_code)
r = call("GET", "/v1/analytics/pilot/weekly.csv", B["token"], A["ws"])
check("a non-member cannot download another workspace's results", r.status_code in (403, 404), r.status_code)
r = call("PUT", "/v1/analytics/pilot", A["token"], A["ws"], {"live_at": "2099-01-01T00:00:00Z"})
check("the go-live date cannot be in the future", r.status_code == 422, f"{r.status_code} {r.text[:100]}")

# ---------------- privacy requests (Shopify's mandatory webhooks)
def privacy_hook(topic, payload, secret=None, delivery=None):
    raw = json.dumps({"shop_id": 1, "shop_domain": SHOP, **payload})
    sig = _b64.b64encode(_hmac.new((secret or args.shopify_secret).encode(), raw.encode(), hashlib.sha256).digest()).decode()
    return call("POST", "/webhooks/shopify/compliance", raw=raw, headers={
        "X-Shopify-Topic": topic, "X-Shopify-Shop-Domain": SHOP, "X-Shopify-Hmac-Sha256": sig,
        "X-Shopify-Webhook-Id": delivery or str(uuid.uuid4())})


r = privacy_hook("customers/redact", {"customer": {"phone": "01012345678"}, "orders_to_redact": []}, secret="attacker")
check("privacy webhook with a wrong signature is refused", r.status_code == 401, r.status_code)
r = call("POST", "/webhooks/shopify/compliance", raw=json.dumps({"shop_domain": SHOP}), headers={"X-Shopify-Topic": "shop/redact", "X-Shopify-Shop-Domain": SHOP})
check("privacy webhook without a signature is refused", r.status_code == 401, r.status_code)
r = privacy_hook("customers/data_request", {"customer": {"phone": "01012345678"}, "orders_requested": [order["id"]], "data_request": {"id": 1}})
pr = sql(f"select string_agg(workspace_id::text || ':' || id::text, ',') from compliance_requests where origin_ref = '{SHOP}'")
check("a signed privacy request lands in the store's workspace only", r.status_code == 200 and pr.count(",") == 0 and pr.startswith(A["ws"] + ":"), f"{r.status_code} {pr}")
PR_ID = pr.split(":")[1] if ":" in pr else str(uuid.uuid4())
r = call("GET", f"/v1/compliance/requests/{PR_ID}/export", B["token"], B["ws"])
check("another workspace cannot read a privacy export (IDOR)", r.status_code == 404, r.status_code)
r = call("GET", "/v1/compliance/requests", B["token"], B["ws"])
check("another workspace does not see the privacy request", r.status_code == 200 and PR_ID not in r.text, r.text[:160])
r = call("GET", f"/v1/compliance/requests/{PR_ID}/export", AGENT["token"], A["ws"])
check("an agent cannot download a customer's data export", r.status_code == 403, r.status_code)
r = call("GET", "/v1/compliance/requests", A["token"], A["ws"])
check("privacy requests never name the customer", r.status_code == 200 and "01012345678" not in r.text and "201012345678" not in r.text, r.text[:160])

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
