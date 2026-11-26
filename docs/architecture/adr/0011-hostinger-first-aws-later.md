# ADR 0011: Host on Hostinger first, move to AWS when a trigger is met

- **Status:** Accepted
- **Date:** 2026-11-26
- **Refines:** [ADR 0004](0004-host-in-eu-region.md) (EU region still applies)

## Context

We want the lowest cost that still meets ADR 0010, and the option to move to managed AWS services later without a rebuild.

- **Hostinger KVM VPS** is very cheap. For example, KVM 8 has 8 vCPU, 32 GB RAM, 400 GB NVMe and 32 TB of traffic a month, at about $26–50/month. It has no managed services, a maximum of 8 vCPU per server, and the page doesn't say whether CPU is dedicated or whether there is private networking.
- **AWS** is managed and multi-zone (RDS, MSK, S3), but traffic leaving AWS costs about $0.05–0.09/GB. At a 30k/s peak the traffic is the biggest variable cost.

## Decision

**Start on Hostinger in an EU data centre and grow there.** Use AWS S3 for backups from day one. Move to AWS service by service when a trigger below is met.

### Portability rules (enforced in review)

1. Everything runs in **Docker images** built in CI. Servers are configured from the repo (Ansible), never by hand.
2. Only **standard interfaces**: PostgreSQL, the Kafka protocol (Redpanda ↔ MSK), the S3 API for files and backups, and the Redis protocol. No provider-specific APIs.
3. App servers keep **no state**. State lives only in PostgreSQL, Redpanda, Redis, ClickHouse and S3.
4. Servers talk over **WireGuard** plus a firewall if Hostinger has no private networking.
5. **Backups**: pgBackRest to S3 (continuous WAL plus daily full), and a restore drill every quarter.

### Before production (#350)

- Benchmark a KVM 8: CPU steal under load, disk fsync latency, network throughput between two servers.
- Confirm EU locations, private networking, port speed, DDoS protection and the backup options with Hostinger.
- PostgreSQL goes on the best-measured servers. If CPU steal is high, PostgreSQL moves first (to a dedicated server or RDS).

### Triggers to move to AWS

Any one of these:

- A customer contract needs multi-zone availability or AWS-backed compliance evidence.
- Operating our own PostgreSQL/Redpanda/ClickHouse takes more than about 20% of engineering time.
- Monthly revenue covers the AWS estimate, including traffic out, with margin.
- Hostinger can't meet the availability target (99.9%) two months in a row.

### How we move (no big bang)

1. **PostgreSQL → RDS**: logical replication, then a cutover with seconds of downtime.
2. **Redpanda → MSK**: mirror the topics, then switch consumers and producers.
3. **App and Go services**: redeploy the same images on ECS or EKS. They keep no state.
4. **ClickHouse**: ClickHouse Cloud or self-managed on EC2.

## Consequences

- Infrastructure at the 30k/s peak is roughly **$460/month at renewal prices** (12 servers, see below), against an estimated $3–6k plus traffic on AWS.
- We operate the databases and the event log ourselves until a trigger is met. We need monitoring, backups and runbooks from the start.
- Prices are from Hostinger's public page (November 2026) and must be re-checked before buying.

| Role | Servers | Renewal $/month |
|---|---|---|
| Redpanda | 3 × KVM 8 | 150 |
| PostgreSQL primary + replica | 2 × KVM 8 | 100 |
| ClickHouse | 1 × KVM 8 | 50 |
| Go intake + dispatcher | 3 × KVM 4 | 87 |
| Laravel API + workers | 2 × KVM 4 | 58 |
| Redis | 1 × KVM 2 | 15 |
