# infra

| Folder | Purpose |
|---|---|
| `docker/` | Config mounted by the local `docker-compose.yml` |
| `k8s/` | Kubernetes manifests: `base/` plus `overlays/` per environment (Kustomize) |
| `terraform/` | Cloud resources per environment. Hosted in an EU region (see ADR 0004) |
| `observability/` | Grafana dashboards, Prometheus alert rules, OpenTelemetry collector config |

Environments: `local` → `staging` → `production`. Production changes go through pull requests only.
