# AGENTS.md

Laravel 12 app "FIFO Tracking / Kanban PPIC" — steel/forge production tracking (cast iron). UI copy is Indonesian. Session auth via custom `AuthController` (no Breeze/Fortify, no roles/permissions); every app route is behind the `auth` middleware in `routes/web.php`.

## Commands

- Run tests: `composer test` (runs `config:clear` then `php artisan test`). Tests use sqlite `:memory:` via `phpunit.xml` — no DB setup needed. Single test: `php artisan test --filter=...`
- Format: `vendor/bin/pint`
- All-in-one dev: `composer dev` (serve + queue + pail + vite)
- Seeded login: `adminppicpf@peroniks.com` / `password` (`database/seeders/DatabaseSeeder.php`)

## Frontend — do not touch Vite

The app does **not** use the Vite build. `resources/css/app.css` + `resources/js/app.js` are only referenced by the unused default `welcome.blade.php`. `npm run build` / `npm run dev` will not affect the app.

Real UI: views extend `layouts/app.blade.php`, which loads Tailwind via the standalone browser build (`asset('js/tailwindcss.js')`) plus vendored libs in `public/js|css` (Handsontable, SweetAlert2, axios, Font Awesome). Frontend behavior is inline Blade + JS. Use classic `@extends('layouts.app')` + `@section('content')` / `@yield('top_bar')`, not Blade components.

## Domain model & data flow

- Department flow (Input): `cor → netto → bubut_od → bubut_cnc → bor → finish → completed` (`InputController::store`). Kanban uses `rencana_cor` instead of `cor` (`KanbanController`); its board shows `production_plans.qty_remaining > 0`, all other boards show `production_items.current_dept`.
- `production_items` is a chain, not one row per order: each move replicates the row, decrements the source `qty_pcs`, increments `scrap_qty`, and creates a new row with `current_dept` = next dept. `code` + `heat_number` is unique and used to trace across rows; `production_histories` logs every move.
- Input validation fields: `hasil` (good qty) + `rusak` (scrap qty).
- The app accepts Indonesian comma decimal separators; controllers normalize `,` → `.` (see `InputController::updateHistory`).

## Conventions

- Controllers use inline fully-qualified model names (`\App\Models\ProductionItem::...`) instead of `use` imports — follow this.
- Weights are stored as decimal strings; several columns on `production_items` are weight fields per dept (`bruto_weight`, `netto_weight`, `bubut_weight`, `finish_weight`).

## Database

Local dev = Laragon MySQL `kanban-ppic` (root, empty password). `.env.example` defaults to sqlite — the real local DB is MySQL, per `.env`. Production is MySQL 8 in Docker.

## Migrations & production deploy (critical)

- Never edit an already-applied migration; add a new one. Keep migrations idempotent (`Schema::hasColumn` guards) since they run against prod data.
- Deploy = push to `origin/main` (github.com/afinifathur/kanban-ppic); on the Docker server: backup DB → `git pull` → clear cache → `php artisan migrate`.
- **Never** run `migrate:fresh`, `migrate:rollback`, `db:wipe`, or `docker compose down -v` in production. Full steps + recovery: `.agent/workflows/safe-deploy.md`.
- `scripts/sync_db.ps1` pulls the prod DB into local Laragon MySQL (prod host `peroniks@10.88.8.46`); only needed when you want prod data locally.
