# Hydromet portal — repository instructions

## Read first

Before planning or changing code, read:

1. `README.ru.md`
2. `docs/01-product-scope.md`
3. `docs/02-architecture.md`
4. `docs/03-data-contracts.md`
5. `docs/04-smartmet-and-alerts.md`
6. `docs/05-api-contract.md`
7. `docs/06-testing-and-acceptance.md`
8. `docs/07-delivery-plan.md`
9. `docs/08-hydromet-input-checklist.md`

The two DOCX files are source requirements and contract references. Never delete, rename or overwrite them.

## Product decisions

- This is a new standalone environmental monitoring portal.
- Hydromet supplies station registry, current/history data, SmartMet endpoints and MeteoAlert data.
- SILAM is iframe-only. Do not implement NetCDF/GRIB processing, COG or GeoServer.
- Recommended stack: Laravel 13, Inertia.js, React, TypeScript, shadcn/ui, Filament 5, PostgreSQL/PostGIS, React-Leaflet, ECharts, Redis and Docker Compose.
- Use a modular monolith. Do not introduce NestJS, microservices, CQRS, Kafka or Kubernetes.
- External systems are isolated behind adapters and mapped into the canonical contracts in `docs/03-data-contracts.md`.
- `smartmet-alert-client` is a reference, not the portal's core or database contract.

## Engineering rules

- Prefer the simplest implementation that satisfies a documented current requirement.
- Organize backend code by business capability: Stations, Measurements, AirQuality, Alerts, Content, Identity, Audit and Integrations.
- Keep controllers thin. Put validation in Form Requests and authorization in policies.
- Do not create repository interfaces for ordinary local Eloquent access. Use interfaces for external/volatile integrations.
- Use strict TypeScript. Do not add `any` without a documented reason.
- Build the public interface from shadcn/ui components and semantic design tokens. Do not recreate a component already provided by shadcn/ui unless the product requires materially different behaviour.
- Add only shadcn/ui components actually used by the current feature; do not install the entire registry.
- Store timestamps in UTC and display them in `Asia/Dushanbe`.
- Use `tj`, `ru`, `en` as application locale keys. Map internal `tj` to the standards-based `tg` / `tg-TJ` tag only at external protocol and HTML metadata boundaries.
- A missing measurement is `null`, never zero, empty string or a magic number.
- Imported data must be idempotent. Manual correction must preserve the original value and audit history.
- Do not implement or publish AQI until an approved versioned rule fixture exists.
- Treat all upstream text, CAP, GeoJSON, CSV and JSON as untrusted input.
- Keep credentials out of source control, browser bundles, logs and fixtures.
- Preserve third-party licence notices when code/assets are reused.

## Workflow

1. Inspect existing files and the current Git status before editing.
2. State a short implementation plan and acceptance checks.
3. Preserve unrelated/user changes.
4. Make small, reviewable changes.
5. Add or update automated tests with every behaviour change.
6. Run the relevant formatter, static analysis, typecheck and tests.
7. Report changed files, commands run, results, assumptions and remaining blockers.
8. Do not commit, push, deploy or change external systems unless explicitly requested.

## External-data development

Until real Hydromet samples arrive:

- use sanitized deterministic fixtures matching `docs/03-data-contracts.md`;
- label adapters and screens as mock-backed;
- do not invent source-specific SmartMet parameter names, quality codes, alert event codes or AQI breakpoints;
- keep mock providers replaceable through the integration adapter boundary.
