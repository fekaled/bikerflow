---
name: sandbox
description: Manages the BikerFlow devcontainer sandbox (start, stop, status, run commands inside the PHP container). Use whenever you need to interact with Docker containers, run Laravel/Artisan/Composer/NPM commands, or ensure the development environment is running.
---

# Sandbox Management

The project runs inside a Docker devcontainer with two services:

| Service | Container Name | Purpose |
|---------|---------------|---------|
| `app` | `devcontainer_app_1` | PHP 8.4-FPM Alpine, project mounted at `/workspaces/bikerflow` |
| `db` | `devcontainer_db_1` | MySQL 8.4, database `bikerflow`, root password `root` |

The app is served via `php artisan serve` on port **8000**, mapped to `localhost:8000` on the host.

**Note:** Laravel `.env` currently uses **SQLite** by default, not the MySQL container. MySQL is available at hostname `db` on port 3306 inside the Docker network if needed.

**Note:** `storage/framework/cache` and `storage/framework/sessions` use `tmpfs` — data is lost on container restart.

## Start the Sandbox

```bash
cd .devcontainer && docker-compose up -d
```

Then start the Laravel development server:

```bash
docker exec -d devcontainer_app_1 php artisan serve --host=0.0.0.0 --port=8000
```

Verify it's running:

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000
# Expected: 200
```

## Stop the Sandbox

```bash
cd .devcontainer && docker-compose down
```

## Check Status

```bash
# Are containers running?
docker ps --filter "name=devcontainer"

# Is artisan serve responding?
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000
```

If containers are running but artisan serve is not responding, start it:

```bash
docker exec -d devcontainer_app_1 php artisan serve --host=0.0.0.0 --port=8000
```

## Rebuild the Image

After Dockerfile changes:

```bash
cd .devcontainer && docker-compose down && docker-compose up -d --build
docker exec -d devcontainer_app_1 php artisan serve --host=0.0.0.0 --port=8000
```

## Running Commands Inside the Container

Use `docker exec` to run any command inside the `app` container:

```bash
# Artisan commands
docker exec devcontainer_app_1 php artisan migrate
docker exec devcontainer_app_1 php artisan route:list
docker exec devcontainer_app_1 php artisan test

# Composer
docker exec devcontainer_app_1 composer install
docker exec devcontainer_app_1 composer require <package>

# NPM
docker exec devcontainer_app_1 npm install
docker exec devcontainer_app_1 npm run build

# Arbitrary shell commands
docker exec devcontainer_app_1 bash -c "<command>"
```

**All commands run as `www-data` (uid 1000)**, which matches the host user. No permission fixes are needed — files created inside the container are owned by the host user.

## Common Workflows

### Run database migrations

```bash
docker exec devcontainer_app_1 php artisan migrate
```

### Run tests

```bash
docker exec devcontainer_app_1 php artisan test
# or a specific test:
docker exec devcontainer_app_1 php artisan test --filter=TestName
```

### Install dependencies (both PHP and JS)

```bash
docker exec devcontainer_app_1 composer install
docker exec devcontainer_app_1 npm install
```

### Clear Laravel caches

```bash
docker exec devcontainer_app_1 php artisan cache:clear
docker exec devcontainer_app_1 php artisan config:clear
docker exec devcontainer_app_1 php artisan view:clear
docker exec devcontainer_app_1 php artisan route:clear
```
