#!/bin/bash
set -e
git checkout main

docker exec -i devcontainer_db_1 mysql -u root -proot bikerflow < .snapshots/latest_db.sql 2>/dev/null

docker exec devcontainer_app_1 php artisan optimize:clear

echo "🔄 Rollback complete. Environment restored."