# Contributing

Thanks for your interest in contributing to the Synaplan Nextcloud Integration!

## Development Setup

1. Clone the repo into Nextcloud's `custom_apps/` directory (or symlink it)
2. Run `composer install` for PHP dependencies
3. Run `npm install` for frontend dependencies
4. Enable the app: `php occ app:enable synaplan_integration`

## Quality Checks

Run these before submitting changes:

```bash
make lint    # PHP (PSR-12) + JS (ESLint) + Prettier
make test    # PHPUnit
make build   # Frontend production build
```

## Code Style

- **PHP**: PSR-12, strict types, type hints everywhere
- **TypeScript**: ESLint with `@nextcloud/eslint-config`
- **Vue**: Composition API, `<script setup lang="ts">`

## Commit Messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add document summarization
fix: handle empty API response
docs: update install instructions
```

## Pull Requests

1. Create a feature branch from `main`
2. Make your changes with tests
3. Ensure all quality checks pass
4. Submit a PR with a clear description
