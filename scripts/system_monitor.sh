#!/bin/bash

# System Monitoring Script for MI Core ETL
# Monitors system health and generates alerts

set -e

PROJECT_ROOT="/var/www/mi_core_etl"
LOG_FILE="$PROJECT_ROOT/storage/logs/monitoring.log"
ALERT_EMAIL="${ALERT_EMAIL:-admin@example.com}"

# Thresholds
DISK_THRESHOLD=85
MEMORY_THRESHOLD=85
CPU_THRESHOLD=90
LOG_SIZE_THRESHOLD=1000  # MB

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a "$LOG_FILE"
}

alert() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ALERT:${NC} $1" | tee -a "$LOG_FILE"
    echo "$1" | mail -s "MI Core ETL Alert" "$ALERT_EMAIL" 2>/dev/null || true
}

# Check disk space
check_disk_space() {
    log "Checking disk space..."
    DISK_USAGE=$(df -h "$PROJECT_ROOT" | awk 'NR==2 {print $5}' | sed 's/%//')
    
    if [ "$DISK_USAGE" -ge "$DISK_THRESHOLD" ]; then
        alert "Disk usage is at ${DISK_USAGE}% (threshold: ${DISK_THRESHOLD}%)"
    else
        log "Disk usage: ${DISK_USAGE}% - OK"
    fi
}

# Check memory usage
check_memory() {
    log "Checking memory usage..."
    MEMORY_USAGE=$(free | awk 'NR==2 {printf "%.0f", $3/$2 * 100}')
    
    if [ "$MEMORY_USAGE" -ge "$MEMORY_THRESHOLD" ]; then
        warning "Memory usage is at ${MEMORY_USAGE}% (threshold: ${MEMORY_THRESHOLD}%)"
    else
        log "Memory usage: ${MEMORY_USAGE}% - OK"
    fi
}

# Check CPU usage
check_cpu() {
    log "Checking CPU usage..."
    CPU_USAGE=$(top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\1/" | awk '{print 100 - $1}')
    CPU_USAGE_INT=$(printf "%.0f" "$CPU_USAGE")
    
    if [ "$CPU_USAGE_INT" -ge "$CPU_THRESHOLD" ]; then
        warning "CPU usage is at ${CPU_USAGE_INT}% (threshold: ${CPU_THRESHOLD}%)"
    else
        log "CPU usage: ${CPU_USAGE_INT}% - OK"
    fi
}

# Check database connection
check_database() {
    log "Checking database connection..."
    
    if [ -f "$PROJECT_ROOT/.env" ]; then
        export $(grep -v '^#' "$PROJECT_ROOT/.env" | xargs)
        
        if [ "${DB_CONNECTION:-mysql}" = "pgsql" ]; then
            PGPASSWORD="$DB_PASS" psql -h "${DB_HOST:-localhost}" -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1" > /dev/null 2>&1
        else
            mysql -h"${DB_HOST:-localhost}" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" > /dev/null 2>&1
        fi
        
        if [ $? -eq 0 ]; then
            log "Database connection - OK"
        else
            alert "Database connection failed"
        fi
    else
        warning "No .env file found, skipping database check"
    fi
}

# Check log directory size
check_log_size() {
    log "Checking log directory size..."
    LOG_SIZE=$(du -sm "$PROJECT_ROOT/storage/logs" | cut -f1)
    
    if [ "$LOG_SIZE" -ge "$LOG_SIZE_THRESHOLD" ]; then
        warning "Log directory size is ${LOG_SIZE}MB (threshold: ${LOG_SIZE_THRESHOLD}MB)"
        log "Consider running log rotation: bash scripts/rotate_logs.sh"
    else
        log "Log directory size: ${LOG_SIZE}MB - OK"
    fi
}

# Check web server
check_web_server() {
    log "Checking web server..."
    
    if systemctl is-active --quiet nginx; then
        log "Nginx is running - OK"
    else
        alert "Nginx is not running"
    fi
    
    if systemctl is-active --quiet php8.1-fpm || systemctl is-active --quiet php-fpm; then
        log "PHP-FPM is running - OK"
    else
        alert "PHP-FPM is not running"
    fi
}

# Check API health endpoint
check_api_health() {
    log "Checking API health endpoint..."
    
    HEALTH_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/health 2>/dev/null || echo "000")
    
    if [ "$HEALTH_RESPONSE" = "200" ]; then
        log "API health check - OK"
    else
        alert "API health check failed (HTTP $HEALTH_RESPONSE)"
    fi
}

# Check recent errors in logs
check_recent_errors() {
    log "Checking for recent errors..."
    
    ERROR_COUNT=$(find "$PROJECT_ROOT/storage/logs" -name "*.log" -mtime -1 -exec grep -i "error\|critical\|fatal" {} \; 2>/dev/null | wc -l)
    
    if [ "$ERROR_COUNT" -gt 100 ]; then
        warning "Found $ERROR_COUNT errors in logs from last 24 hours"
    else
        log "Recent errors: $ERROR_COUNT - OK"
    fi
}

# Main monitoring routine
log "========================================="
log "Starting system monitoring..."
log "========================================="

check_disk_space
check_memory
check_cpu
check_database
check_log_size
check_web_server
check_api_health
check_recent_errors

log "========================================="
log "Monitoring completed"
log "========================================="
