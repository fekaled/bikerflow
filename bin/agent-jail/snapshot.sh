#!/bin/bash
set -e
BRANCH_NAME="agent-work-$(date +%s)"
git checkout -b "$BRANCH_NAME"

docker exec devcontainer_db_1 mysqldump -u root -proot bikerflow > .snapshots/latest_db.sql 2>/dev/null

echo "✅ Snapshot created: $BRANCH_NAME and latest_db.sql"