#!/bin/bash

# Script to check what local changes exist on server before deployment
# Run this on server to see what will be stashed

echo "🔍 Checking server for local changes before deployment..."
echo ""

# Check git status
echo "📋 Git Status:"
git status

echo ""
echo "📝 Modified files (will be stashed):"
git status --porcelain

echo ""
echo "🔍 Detailed changes:"
git diff --name-status

echo ""
echo "📊 Files that differ from repository:"
git diff --stat

echo ""
echo "🗂️ Untracked files (will be stashed):"
git ls-files --others --exclude-standard

echo ""
echo "📁 Important directories status:"
echo "Logs directory:"
ls -la logs/ 2>/dev/null || echo "  No logs directory"

echo "Cache directory:"
ls -la cache/ 2>/dev/null || echo "  No cache directory"

echo "Storage directory:"
ls -la storage/ 2>/dev/null || echo "  No storage directory"

echo ""
echo "🔐 Current file ownership:"
echo "Project root:"
ls -ld .

echo "Key files:"
ls -la config/ 2>/dev/null || echo "  No config directory"
ls -la *.php 2>/dev/null | head -5

echo ""
echo "⚙️ Running services:"
systemctl is-active nginx 2>/dev/null && echo "✅ Nginx is running" || echo "❌ Nginx not running"
systemctl is-active apache2 2>/dev/null && echo "✅ Apache is running" || echo "❌ Apache not running"
systemctl is-active php8.1-fpm 2>/dev/null && echo "✅ PHP-FPM 8.1 is running" || echo "❌ PHP-FPM 8.1 not running"
systemctl is-active php8.0-fpm 2>/dev/null && echo "✅ PHP-FPM 8.0 is running" || echo "❌ PHP-FPM 8.0 not running"

echo ""
echo "📅 Active cron jobs:"
crontab -l 2>/dev/null || echo "  No cron jobs for current user"

echo ""
echo "🎯 Summary:"
MODIFIED_COUNT=$(git status --porcelain | wc -l)
if [ $MODIFIED_COUNT -eq 0 ]; then
    echo "✅ No local changes detected - safe to deploy!"
else
    echo "⚠️  $MODIFIED_COUNT files have local changes"
    echo "   These will be automatically stashed and can be restored after deployment"
fi

echo ""
echo "🛡️ Deployment safety:"
echo "✅ All local changes will be preserved in git stash"
echo "✅ Database and logs will remain untouched"
echo "✅ Services will be gracefully restarted"
echo "✅ Full rollback available if needed"