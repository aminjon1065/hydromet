# Claude Code prompt 01 — application foundation

Work in the current `C:\codes\hydromet` directory.

Read `CLAUDE.md`, `README.ru.md` and every Markdown file under `docs/` before changing anything. Treat them as the project specification. Do not delete, rename or overwrite the two existing DOCX files.

Implement only Phase 1: the application foundation. Do not implement station imports, AQI, SmartMet, MeteoAlert or SILAM in this task.

## Required outcome

Create a production-oriented Laravel modular-monolith skeleton using:

- Laravel 13 or the current officially supported Laravel version if compatibility requires it;
- Inertia.js + React + TypeScript;
- shadcn/ui configured with the current official Laravel/Inertia installation method;
- Filament 5 for the administration shell;
- PostgreSQL with PostGIS-ready configuration;
- Redis for cache and queues;
- Docker Compose for local development and the future VPS;
- Vite for frontend assets.

If a version differs from the specification, verify it against current official documentation and explain the reason before installing it.

## Constraints

- The directory already contains project documentation, so scaffold without losing or overwriting it.
- Do not build a separate SPA backend or add Next.js/NestJS.
- Do not add microservices, CQRS, event buses, repository abstractions or speculative infrastructure.
- Do not add secrets. Provide `.env.example` values only.
- Do not commit or push.

## Foundation requirements

1. Initialize Git if this directory is not already a repository.
2. Scaffold Laravel in the existing directory while preserving all documentation and DOCX files.
3. Install and configure Inertia React with TypeScript.
4. Install and configure Filament admin authentication.
5. Initialize shadcn/ui for the Inertia React application using TSX, React Server Components disabled and CSS variables enabled. Use the current official recommended style and record the choice. Add only components used by this foundation; do not add the entire registry.
6. Configure application locale keys `tj`, `ru`, `en`, fallback `ru`, and timezone `Asia/Dushanbe` for display. Database timestamps remain UTC. Map internal `tj` to `tg` or `tg-TJ` only for HTML language metadata, CAP and other standards-based external protocols.
7. Add Docker Compose services for Nginx, PHP application, queue worker, scheduler, PostgreSQL/PostGIS and Redis. Use health checks and persistent named volumes.
8. Add an application health endpoint that can check the application, database and Redis without revealing secrets.
9. Add a minimal public layout and home page proving Inertia + React + TypeScript + shadcn/ui work. Use shadcn/ui rather than hand-written replacements for standard interface primitives.
10. Add a minimal Filament admin dashboard proving authentication and authorization work.
11. Configure code quality using Laravel Pint, PHPStan/Larastan, ESLint and TypeScript typecheck. Prefer existing official presets and minimal configuration.
12. Add backend and frontend smoke tests for the health endpoint, public page and protected admin access.
13. Add developer commands to the README for install, start, migrate, test, lint/typecheck and stop.

## Verification

Run all commands that are safe in the available environment:

- dependency installation;
- backend tests;
- frontend tests if configured;
- PHP static analysis and formatter check;
- ESLint and TypeScript typecheck;
- production frontend build;
- Docker Compose configuration validation.

Do not claim Docker services started if Docker is unavailable. Report that as an environment limitation.

## Final report

Return:

1. selected versions;
2. architecture/folder structure created;
3. changed files;
4. verification commands and exact results;
5. assumptions or blockers;
6. the smallest recommended Phase 2 task.

Stop after Phase 1. Do not continue into data-model or integration implementation.
