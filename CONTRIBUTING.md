# Contributing to Trik

## Flow

1. Pick or open an issue. Every change starts from one.
2. Branch from `main`: `feat/<area>-<short-name>`, for example `feat/orders-confirmation-ladder`.
3. If the change touches an API or event, update `packages/contracts` first.
4. Write the code and the tests together.
5. Run `make lint test` locally.
6. Open a pull request using the template. Keep it small enough to review in 20 minutes.
7. Squash merge after one approval and green CI.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org):

```
feat(confirmation): escalate to call queue after 4 hours
fix(inbox): keep order bar visible on narrow screens
docs(adr): record decision on deposit gateway ownership
```

Scopes match module names (`inbox`, `orders`, `confirmation`, `risk`, `couriers`, `payments`, `campaigns`, …) or app names (`web`, `mobile`, `dispatcher`, …).

## Code standards

| Area | Standard | Tooling |
|---|---|---|
| PHP | PSR-12, strict types, final classes by default | Pint, PHPStan (level 8), Pest |
| TypeScript / Vue | Composition API, `<script setup lang="ts">` | ESLint, Prettier, vue-tsc, Vitest |
| Go | Effective Go, no global state | gofmt, go vet, staticcheck, `go test -race` |
| Dart / Flutter | Effective Dart | `flutter analyze`, `dart format`, `flutter test` |

## Definition of done

- Tests cover the change, including the unhappy path.
- Arabic and English strings added, RTL checked.
- Logs, metrics and traces for anything that runs in the background.
- Feature flag for anything user-visible that ships before it is finished.
- Docs updated: module README, contracts, ADR if a decision was made.
