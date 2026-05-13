#!/bin/bash
BRANCH_NAME="agent-work-$(date +%s)"
git checkout -b "$BRANCH_NAME"

docker-compose exec -T db mysqldump -u root -proot bikerflow > .snapshots/latest_db.sql

echo "✅ Snapshot created: $BRANCH_NAME and latest_db.sql"