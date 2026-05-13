#!/bin/bash
git checkout main

docker-compose exec -T db mysql -u root -proot bikerflow < .snapshots/latest_db.sql

docker-compose exec app php artisan optimize:clear

echo "🔄 Rollback complete. Environment restored."