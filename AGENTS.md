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

## Subagent Architecture

The project uses a pi extension (`.pi/extensions/bikerflow-subagents/`) that spawns each persona as an **isolated `pi` subprocess** with full observability.

### Agents

Six personas defined in `.pi/agents/*.md` with YAML frontmatter:

| Agent | Tools | Purpose |
|-------|-------|---------|
| planner | read, grep, find, ls, bash | Produces implementation blueprints |
| tester | read, grep, find, ls, bash, write, edit | Writes failing tests (TDD RED) |
| developer | all (default) | Implements code to make tests pass (GREEN) |
| validator | read, grep, find, ls, bash | Audits implementation against PRD |
| tracker | read, write, edit | Updates `docs/progress.md` |
| sandbox | bash | Manages Docker containers |

### Workflows

Defined in `.pi/workflows/*.md`:

| Workflow | Stages | Description |
|----------|--------|-------------|
| full-tdd | planner → tester → developer → validator → tracker | Complete feature pipeline |
| plan-only | planner → tracker | Blueprint only |
| implement-only | tester → developer → validator → tracker | Requires existing plan |

### Commands

| Command | Description |
|---------|-------------|
| `/tdd <task>` | Run full TDD pipeline |
| `/agents` | Show pipeline status dashboard |
| `/agents summary` | Aggregate stats across pipelines |
| `/agents logs <persona>` | View trace log for a persona |
| `/agents log <pipeline-id>` | View full pipeline manifest |
| `/agents run <workflow> <task>` | Run any workflow pipeline |

### Observability

- **Trace logs:** `docs/agents/logs/*.jsonl` — full event capture per subagent run
- **Pipeline manifests:** `docs/agents/pipelines/*.json` — stage-by-stage workflow state
- **Known issues:** `docs/agents/KNOWN-ISSUES.md`
- Both directories are gitignored (operational data, not source)

### Skills vs Agents

**Both coexist.**
- **Skills** (`.pi/skills/`) — for interactive/manual use in the current session
- **Agents** (`.pi/agents/`) — for automated pipeline execution via the extension
- They share the same persona instructions; agents have additional subagent context
