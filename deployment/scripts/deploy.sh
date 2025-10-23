#!/bin/bash

# MI Core ETL Deployment Script
# This script handles the deployment of the refactored MI Core ETL system

set -e  # Exit on any error

# Configuration
PROJECT_ROOT="/var/www/mi_core_etl"
BACKUP_DIR="$PROJECT_ROOT/storage/backups"
LOG_FILE="$PROJECT_ROOT/storage/logs/deployment_$(date +%Y%m%d_%H%M%S).log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a "$LOG_FILE"
    exit 1
}

warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a "$LOG_FILE"
}

# Create necessary directories
mkdir -p "$BACKUP_DIR"
mkdir -p "$(dirname "$LOG_FILE")"

log "Starting MI Core ETL deployment..."

# Step 1: Create backup
log "Creating backup..."
bash "$PROJECT_ROOT/deployment/scripts/backup.sh" || error "Backup failed"

# Step 2: Update dependencies
log "Installing PHP dependencies..."
cd "$PROJECT_ROOT"
composer install --no-dev --optimize-autoloader || error "Composer install failed"

# Step 3: Build frontend (if exists)
if [ -d "$PROJECT_ROOT/frontend" ]; then
    log "Building React frontend..."
    cd "$PROJECT_ROOT/frontend"
    npm ci || error "NPM install failed"
    npm run build || error "Frontend build failed"
    
    # Copy build to public directory
    mkdir -p "$PROJECT_ROOT/public/build"
    cp -r dist/* "$PROJECT_ROOT/public/build/" || error "Failed to copy frontend build"
fi

# Step 4: Set permissions
log "Setting file permissions..."
cd "$PROJECT_ROOT"
chmod -R 755 storage/
chmod -R 755 public/
chmod +x deployment/scripts/*.sh

# Step 5: Clear cache
log "Clearing cache..."
rm -rf storage/cache/*

# Step 6: Run database migrations (if any)
if [ -f "$PROJECT_ROOT/src/utils/migrate.php" ]; then
    log "Running database migrations..."
    php "$PROJECT_ROOT/src/utils/migrate.php" || warning "Migration warnings occurred"
fi

# Step 7: Restart services
log "Restarting services..."
sudo systemctl reload nginx || warning "Failed to reload nginx"
sudo systemctl restart php8.1-fpm || warning "Failed to restart PHP-FPM"

# Step 8: Health check
log "Running health check..."
sleep 5

# Check if health endpoint exists
if [ -f "$PROJECT_ROOT/public/api/health.php" ]; then
    curl -f http://localhost/api/health || warning "Health check endpoint failed"
elif [ -f "$PROJECT_ROOT/src/api/controllers/HealthController.php" ]; then
    php "$PROJECT_ROOT/src/api/controllers/HealthController.php" || warning "Health check failed"
else
    warning "No health check endpoint found"
fi

log "Deployment completed successfully!"
log "Deployment log saved to: $LOG_FILE"

# Display summary
echo ""
echo "=== Deployment Summary ==="
echo "Project: MI Core ETL System"
echo "Deployed at: $(date)"
echo "Log file: $LOG_FILE"
echo "=========================="