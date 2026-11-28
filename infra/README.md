# infra

| Folder | Purpose |
|---|---|
| `docker/` | Config mounted by the local `docker-compose.yml` |
| `production/` | **Production on one server (pilot):** `compose.yml`, `deploy.sh`, env templates. See [docs/runbooks/deploy.md](../docs/runbooks/deploy.md) |
| `edge/` | Caddy + the landing site: the only public entry point (`trik-edge` image) |
| `ansible/` | Sets up the production server from a fresh Ubuntu 24.04 |
| `k8s/` | Kubernetes manifests: `base/` plus `overlays/` per environment (Kustomize) |
| `terraform/` | Cloud resources per environment. Hosted in an EU region (see ADR 0004) |
| `observability/` | Grafana dashboards, Prometheus alert rules, OpenTelemetry collector config |

Environments: `local` → `staging` → `production`. Production changes go through pull requests only.
