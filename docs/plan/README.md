# docs/plan

| File | Purpose |
|---|---|
| `PLAN.md` | The delivery plan: phases, milestones, non-functional requirements and every issue by milestone |
| `plan.json` | Source data: labels, milestones, epics and issues |
| `sync.py` | Imports `plan.json` into GitHub (idempotent; issues are matched by a hidden `plan-key` marker) |

Re-sync after editing `plan.json`:

```bash
python3 docs/plan/sync.py docs/plan/plan.json abdo-shrief270/trik
```

GitHub Issues is the live source of truth once imported. Edit there; use `plan.json` only for bulk changes.
