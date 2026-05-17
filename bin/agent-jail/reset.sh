#!/bin/bash
set -e

# Post-merge cleanup: stash uncommitted work, sync main, delete merged
# feature branch, restore stash on main. Safe to run with dirty tree.

CURRENT_BRANCH=$(git branch --show-current)

if [[ "$CURRENT_BRANCH" == "main" ]]; then
  echo "⚠️  Already on main. Pulling latest..."
  git pull origin main
  echo "✅ main is up to date."
  exit 0
fi

# Stash any uncommitted changes (including untracked files) so checkout works
STASH_LABEL="reset-${CURRENT_BRANCH}-$(date +%s)"
if ! git diff --quiet || [[ -n $(git ls-files --others --exclude-standard) ]]; then
  echo "📦 Stashing uncommitted changes..."
  git stash push -u -m "$STASH_LABEL"
  STASHED=true
else
  STASHED=false
fi

echo "📌 Current branch: $CURRENT_BRANCH"
echo "🔄 Switching to main and pulling merged changes..."

git checkout main
git pull origin main

echo "🧹 Deleting local branch: $CURRENT_BRANCH"
git branch -d "$CURRENT_BRANCH"

echo "🧹 Deleting remote branch: origin/$CURRENT_BRANCH"
git push origin --delete "$CURRENT_BRANCH" 2>/dev/null || echo "   (remote branch already deleted)"

# Restore stashed changes onto main
if [[ "$STASHED" == true ]]; then
  echo "📦 Restoring stashed changes on main..."
  git stash pop
fi

echo ""
echo "✅ Reset complete. You are on main, ready for the next task."
echo "   Run ./bin/agent-jail/snapshot.sh to start a new work branch."
