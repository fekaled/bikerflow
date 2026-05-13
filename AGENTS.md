# BikerFlow Project Context

## Environment

- Docker devcontainer via `.devcontainer/docker-compose.yml`
- **All commands** must use `docker exec devcontainer_app_1` as prefix
- Two containers: `app` (PHP 8.4-FPM Alpine) and `db` (MySQL 8.4)
- App served via `php artisan serve` on port 8000 → `localhost:8000`
- Project mounted at `/workspaces/bikerflow` inside the container
- All commands run as `www-data` (uid 1000), matching the host user

## Database

- MySQL 8.4 at hostname `db`, port 3306
- Database: `bikerflow`, user: `root`, password: `root`
- `storage/framework/cache` and `sessions` use tmpfs — lost on container restart
- Session and cache drivers: `database`

## Agent Workflow

- **Snapshot before risky changes:** `./bin/agent-jail/snapshot.sh`
- **Rollback to restore:** `./bin/agent-jail/rollback.sh`
- Git branches use `agent-work-<timestamp>` pattern for agent experiments
- Stable code lives on `main` branch

## Common Commands

```bash
# Artisan
docker exec devcontainer_app_1 php artisan migrate
docker exec devcontainer_app_1 php artisan test

# Composer
docker exec devcontainer_app_1 composer install

# NPM
docker exec devcontainer_app_1 npm install
docker exec devcontainer_app_1 npm run build
```

## Business Rules

- Payout Formula (BR-03):
  - If trips_count = 0 → Payout = 0.00
  - If trips_count > 0 → Payout = base_fee + (biker_rate × trips_count)
- All financial values use **BCMath** for precision
- Currency: BRL

## Architecture Notes

- Laravel 13 (latest)
- Frontend: Blade + Vite (vanilla JS, no Inertia/Livewire unless specified)
- This is a restaurant-to-biker payout management system
