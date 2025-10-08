#!/bin/bash

# Production Deployment Script for MDM System

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="/opt/mdm"
BACKUP_DIR="/opt/mdm/backups"
LOG_FILE="/var/log/mdm-deploy.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a "$LOG_FILE"
}

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   error "This script should not be run as root for security reasons"
   exit 1
fi

# Load environment variables
if [ -f "$PROJECT_DIR/.env" ]; then
    source "$PROJECT_DIR/.env"
else
    error "Environment file not found at $PROJECT_DIR/.env"
    exit 1
fi

# Pre-deployment checks
pre_deployment_checks() {
    log "Running pre-deployment checks..."
    
    # Check if Docker is running
    if ! docker info > /dev/null 2>&1; then
        error "Docker is not running"
        exit 1
    fi
    
    # Check if docker-compose is available
    if ! command -v docker-compose &> /dev/null; then
        error "docker-compose is not installed"
        exit 1
    fi
    
    # Check disk space (require at least 5GB free)
    AVAILABLE_SPACE=$(df "$PROJECT_DIR" | awk 'NR==2 {print $4}')
    REQUIRED_SPACE=5242880  # 5GB in KB
    
    if [ "$AVAILABLE_SPACE" -lt "$REQUIRED_SPACE" ]; then
        error "Insufficient disk space. Required: 5GB, Available: $(($AVAILABLE_SPACE/1024/1024))GB"
        exit 1
    fi
    
    # Check if required environment variables are set
    REQUIRED_VARS=("DB_PASSWORD" "DB_ROOT_PASSWORD" "REDIS_PASSWORD")
    for var in "${REQUIRED_VARS[@]}"; do
        if [ -z "${!var}" ]; then
            error "Required environment variable $var is not set"
            exit 1
        fi
    done
    
    log "Pre-deployment checks passed"
}

# Create database backup
create_backup() {
    log "Creating database backup..."
    
    BACKUP_FILE="$BACKUP_DIR/mdm_backup_$(date +%Y%m%d_%H%M%S).sql"
    mkdir -p "$BACKUP_DIR"
    
    if docker-compose -f "$PROJECT_DIR/docker-compose.prod.yml" exec -T mdm-db-master mysqldump \
        -u root -p"$DB_ROOT_PASSWORD" \
        --single-transaction \
        --routines \
        --triggers \
        "$DB_NAME" > "$BACKUP_FILE"; then
        log "Database backup created: $BACKUP_FILE"
        
        # Compress backup
        gzip "$BACKUP_FILE"
        log "Backup compressed: ${BACKUP_FILE}.gz"
    else
        error "Failed to create database backup"
        exit 1
    fi
}

# Pull latest images
pull_images() {
    log "Pulling latest Docker images..."
    
    cd "$PROJECT_DIR"
    if docker-compose -f docker-compose.prod.yml pull; then
        log "Images pulled successfully"
    else
        error "Failed to pull images"
        exit 1
    fi
}

# Run database migrations
run_migrations() {
    log "Running database migrations..."
    
    cd "$PROJECT_DIR"
    if docker-compose -f docker-compose.prod.yml run --rm mdm-app npm run migrate; then
        log "Database migrations completed"
    else
        error "Database migrations failed"
        exit 1
    fi
}

# Deploy services with zero downtime
deploy_services() {
    log "Deploying services..."
    
    cd "$PROJECT_DIR"
    
    # Start new containers
    if docker-compose -f docker-compose.prod.yml up -d --remove-orphans; then
        log "Services started successfully"
    else
        error "Failed to start services"
        exit 1
    fi
    
    # Wait for health check
    log "Waiting for application to be healthy..."
    TIMEOUT=300
    COUNTER=0
    
    while [ $COUNTER -lt $TIMEOUT ]; do
        if curl -f http://localhost:8080/health > /dev/null 2>&1; then
            log "Application is healthy"
            break
        fi
        
        sleep 5
        COUNTER=$((COUNTER + 5))
        
        if [ $COUNTER -ge $TIMEOUT ]; then
            error "Application health check timeout"
            exit 1
        fi
    done
}

# Verify deployment
verify_deployment() {
    log "Verifying deployment..."
    
    cd "$PROJECT_DIR"
    
    # Check if all services are running
    if ! docker-compose -f docker-compose.prod.yml ps | grep -q "Up"; then
        error "Some services are not running"
        exit 1
    fi
    
    # Test API endpoints
    if ! curl -f http://localhost:8080/api/health > /dev/null 2>&1; then
        error "API health check failed"
        exit 1
    fi
    
    # Test database connectivity
    if ! docker-compose -f docker-compose.prod.yml exec -T mdm-db-master mysql \
        -u "$DB_USER" -p"$DB_PASSWORD" \
        -e "SELECT 1" "$DB_NAME" > /dev/null 2>&1; then
        error "Database connectivity test failed"
        exit 1
    fi
    
    log "Deployment verification passed"
}

# Cleanup old resources
cleanup() {
    log "Cleaning up old resources..."
    
    # Remove unused Docker images
    docker image prune -f
    
    # Remove old backups (keep last 30 days)
    find "$BACKUP_DIR" -name "*.gz" -mtime +30 -delete
    
    log "Cleanup completed"
}

# Send notification
send_notification() {
    local status=$1
    local message=$2
    
    if [ -n "$SLACK_WEBHOOK_URL" ]; then
        curl -X POST -H 'Content-type: application/json' \
            --data "{\"text\":\"$message\"}" \
            "$SLACK_WEBHOOK_URL" > /dev/null 2>&1 || true
    fi
    
    if [ -n "$EMAIL_ALERTS_TO" ] && [ -n "$SMTP_HOST" ]; then
        echo "$message" | mail -s "MDM Deployment $status" "$EMAIL_ALERTS_TO" || true
    fi
}

# Rollback function
rollback() {
    error "Deployment failed. Starting rollback..."
    
    cd "$PROJECT_DIR"
    
    # Stop current containers
    docker-compose -f docker-compose.prod.yml down
    
    # Restore from backup if available
    LATEST_BACKUP=$(ls -t "$BACKUP_DIR"/*.gz 2>/dev/null | head -n1)
    if [ -n "$LATEST_BACKUP" ]; then
        log "Restoring database from backup: $LATEST_BACKUP"
        gunzip -c "$LATEST_BACKUP" | docker-compose -f docker-compose.prod.yml exec -T mdm-db-master mysql \
            -u root -p"$DB_ROOT_PASSWORD" "$DB_NAME"
    fi
    
    # Start services with previous version
    docker-compose -f docker-compose.prod.yml up -d
    
    send_notification "FAILED" "❌ MDM System deployment failed and rollback completed"
    exit 1
}

# Main deployment process
main() {
    log "Starting MDM System deployment..."
    
    # Set trap for rollback on error
    trap rollback ERR
    
    pre_deployment_checks
    create_backup
    pull_images
    run_migrations
    deploy_services
    verify_deployment
    cleanup
    
    # Remove trap
    trap - ERR
    
    log "✅ MDM System deployment completed successfully!"
    send_notification "SUCCESS" "✅ MDM System deployed successfully to production"
}

# Run main function
main "$@"