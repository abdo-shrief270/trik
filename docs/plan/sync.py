#!/usr/bin/env python3
"""Idempotently syncs plan.json (labels, milestones, epics, issues) to a GitHub repo using the gh CLI.
Usage: python3 sync.py plan.json owner/repo
Issues are matched by a hidden marker <!-- plan-key: ... --> so re-running never duplicates."""
import json, subprocess, sys, time

PLAN, REPO = sys.argv[1], sys.argv[2]
plan = json.load(open(PLAN))
PACE = float(sys.argv[3]) if len(sys.argv) > 3 else 3.0


def gh(method, path, data=None, paginate=False):
    cmd = ["gh", "api", "-X", method, f"repos/{REPO}/{path}" if not path.startswith("/") else path, "-H", "Accept: application/vnd.github+json"]
    if paginate:
        cmd.append("--paginate")
    inp = None
    if data is not None:
        cmd += ["--input", "-"]
        inp = json.dumps(data)
    for attempt in range(6):
        r = subprocess.run(cmd, input=inp, capture_output=True, text=True)
        if r.returncode == 0:
            out = r.stdout.strip()
            if paginate and out.startswith("[") and "][" in out:
                out = "[" + out[1:-1].replace("]\n[", ",").replace("][", ",") + "]"
            return json.loads(out) if out else None
        err = r.stdout + r.stderr
        if "secondary rate limit" in err.lower() or "abuse" in err.lower() or "HTTP 403" in err or "HTTP 429" in err or "HTTP 502" in err:
            wait = 60 * (attempt + 1)
            print(f"  rate limited, sleeping {wait}s", flush=True)
            time.sleep(wait)
            continue
        raise RuntimeError(f"{method} {path}: {err[:400]}")
    raise RuntimeError(f"{method} {path}: gave up")


def marker(key):
    return f"\n\n<!-- plan-key: {key} -->"


# labels
existing = {l["name"] for l in gh("GET", "labels?per_page=100", paginate=True)}
for l in plan["labels"]:
    if l["name"] in existing:
        gh("PATCH", f"labels/{l['name'].replace(':', '%3A')}", {"color": l["color"], "description": l["description"][:100]})
    else:
        gh("POST", "labels", {"name": l["name"], "color": l["color"], "description": l["description"][:100]})
print("labels done", flush=True)

# milestones
ms_existing = {m["title"]: m["number"] for m in gh("GET", "milestones?state=all&per_page=100", paginate=True)}
ms_num = {}
for m in plan["milestones"]:
    payload = {"title": m["title"], "description": m["description"], "due_on": m["due_on"] + "T23:59:59Z"}
    if m["title"] in ms_existing:
        ms_num[m["key"]] = ms_existing[m["title"]]
    else:
        ms_num[m["key"]] = gh("POST", "milestones", payload)["number"]
print("milestones done", ms_num, flush=True)

# existing issues by marker
have = {}
for it in gh("GET", "issues?state=all&per_page=100", paginate=True):
    body = it.get("body") or ""
    if "<!-- plan-key: " in body:
        k = body.split("<!-- plan-key: ")[1].split(" -->")[0]
        have[k] = it["number"]
print("existing plan issues:", len(have), flush=True)


def create(it):
    if it["key"] in have:
        return have[it["key"]]
    payload = {"title": it["title"], "body": it["body"] + marker(it["key"]), "labels": it["labels"]}
    if it.get("milestone"):
        payload["milestone"] = ms_num[it["milestone"]]
    n = gh("POST", "issues", payload)["number"]
    have[it["key"]] = n
    print(f"  #{n} {it['title']}", flush=True)
    time.sleep(PACE)
    return n


for e in plan["epics"]:
    create(e)
order = {k["key"]: i for i, k in enumerate(plan["milestones"])}
for it in sorted(plan["issues"], key=lambda i: order[i["milestone"]]):
    create(it)

# epic bodies: child task lists
children = {}
for it in plan["issues"]:
    children.setdefault(it["parent"], []).append(it)
for e in plan["epics"]:
    kids = sorted(children.get(e["key"], []), key=lambda i: order[i["milestone"]])
    lines, cur = [], None
    for k in kids:
        mt = next(m["title"] for m in plan["milestones"] if m["key"] == k["milestone"])
        if mt != cur:
            lines.append(f"\n**{mt}**\n")
            cur = mt
        lines.append(f"- [ ] #{have[k['key']]}")
    body = e["body"] + "\n\n## Issues\n" + "\n".join(lines) + marker(e["key"])
    gh("PATCH", f"issues/{have[e['key']]}", {"body": body})
    time.sleep(1)
print("epics linked. total plan issues:", len(have), flush=True)
json.dump(have, open(PLAN.replace(".json", ".numbers.json"), "w"), indent=1)
