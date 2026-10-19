# AGENTS.md

Instructions for agents working on this repo. The house rules of
[`synaplan/AGENTS.md`](https://github.com/metadist/synaplan/blob/main/AGENTS.md)
apply: English in code and commits, the UX and stability bar, no secrets.

## Development workflow

- `make dev-up` mounts the app into a local Nextcloud 35 on `:8081`, with
  MariaDB, cron, the users `ada`, `bob` and `carol` and organisation login.
  `NC_IMAGE=nextcloud:33-apache make dev-reset dev-up` runs the oldest
  supported version. Details: README, section Development.
- `npm run dev` rebuilds `js/` on every change; reload the page.
- The app supports Nextcloud 33–35 (`appinfo/info.xml`) on PHP 8.2 or newer.

## Before you push

`make ci-local` runs every CI job except e2e: php-cs-fixer, ESLint, Prettier,
PHPStan, PHPUnit, the contract test, vue-tsc, Vitest, build and package. CI
(`.github/workflows/ci.yml`) runs the PHP checks against the Nextcloud 33 and
35 APIs; **All Checks Passed** is the merge gate. Never push red.

## Prerequisites

- The Synaplan dev stack (`docker compose --profile oidc up -d` in
  `synaplan`); Nextcloud reaches it as `http://backend`.
- PHP 8.2+, Composer and Node 24 on the host.

## Tool usage

- Prefer the Makefile targets (`make help`) over raw tools.
- The PHPStan level in `phpstan.neon` only goes up: fix findings, no baseline.
- Controller access rules are PHP attributes; `ControllerAccessTest` lists
  every route with its rules.
- A new call to Synaplan needs a line in `SynaplanContractTest::callEverything()`;
  the test checks it against the Synaplan pinned in `ci.yml`
  (`SYNAPLAN_SRC_CONTAINER=synaplan-backend make test-contract` tries a local one).
- Every user-facing string goes through `t()` / `n()`.
- Never commit `js/`, tarballs or anything from `.local/` (signing keys).

## Commits

- Conventional Commits on a feature branch, merged through a pull request;
  never push to `main`.
- No AI attribution.
