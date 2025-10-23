#!/bin/bash

# MI Core ETL - Smoke Tests for Production
# Tests critical functionality after deployment

set -e

PROJECT_ROOT="/var/www/mi_core_etl"
TEST_LOG="$PROJECT_ROOT/storage/logs/smoke_test_$(date +%Y%m%d_%H%M%S).log"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Test counters
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_TOTAL=0

# Logging
log() {
    echo -e "${BLUE}[$(date '+%H:%M:%S')]${NC} $1" | tee -a "$TEST_LOG"
}

pass() {
    echo -e "${GREEN}✅ PASS:${NC} $1" | tee -a "$TEST_LOG"
    ((TESTS_PASSED++))
    ((TESTS_TOTAL++))
}

fail() {
    echo -e "${RED}❌ FAIL:${NC} $1" | tee -a "$TEST_LOG"
    ((TESTS_FAILED++))
    ((TESTS_TOTAL++))
}

warn() {
    echo -e "${YELLOW}⚠️  WARN:${NC} $1" | tee -a "$TEST_LOG"
}

# Create log directory
mkdir -p "$(dirname "$TEST_LOG")"

echo "╔════════════════════════════════════════════════════════════╗"
echo "║        MI Core ETL - Production Smoke Tests               ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
log "Starting smoke tests..."
echo ""

# Test 1: PostgreSQL Connection
log "Test 1: PostgreSQL Database Connection"
if php -r "
require '$PROJECT_ROOT/config/database_postgresql.php';
try {
    \$pdo = getDatabaseConnection();
    \$result = \$pdo->query('SELECT version()')->fetch();
    echo 'Connected to: ' . \$result['version'] . '\n';
    exit(0);
} catch (Exception \$e) {
    echo 'Error: ' . \$e->getMessage() . '\n';
    exit(1);
}
" >> "$TEST_LOG" 2>&1; then
    pass "PostgreSQL connection successful"
else
    fail "PostgreSQL connection failed"
fi

# Test 2: Database Tables Exist
log "Test 2: Critical Database Tables"
REQUIRED_TABLES=("products" "inventory_data" "warehouses")
for table in "${REQUIRED_TABLES[@]}"; do
    if psql -h localhost -U mi_core_user -d mi_core_db -t -c "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '$table');" 2>/dev/null | grep -q "t"; then
        pass "Table '$table' exists"
    else
        fail "Table '$table' not found"
    fi
done

# Test 3: Materialized Views
log "Test 3: Materialized Views"
if psql -h localhost -U mi_core_user -d mi_core_db -t -c "SELECT EXISTS (SELECT FROM pg_matviews WHERE schemaname = 'public' AND matviewname = 'mv_dashboard_inventory');" 2>/dev/null | grep -q "t"; then
    pass "Materialized view 'mv_dashboard_inventory' exists"
else
    warn "Materialized view 'mv_dashboard_inventory' not found (optional)"
fi

# Test 4: Database Indexes
log "Test 4: Database Indexes"
INDEX_COUNT=$(psql -h localhost -U mi_core_user -d mi_core_db -t -c "SELECT COUNT(*) FROM pg_indexes WHERE schemaname = 'public';" 2>/dev/null | tr -d ' ')
if [ "$INDEX_COUNT" -gt 5 ]; then
    pass "Database has $INDEX_COUNT indexes"
else
    warn "Only $INDEX_COUNT indexes found (expected more)"
fi

# Test 5: API Health Endpoint
log "Test 5: API Health Endpoint"
if [ -f "$PROJECT_ROOT/public/api/health.php" ] || [ -f "$PROJECT_ROOT/src/api/controllers/HealthController.php" ]; then
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/health 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        pass "API health endpoint responding (HTTP $HTTP_CODE)"
    else
        fail "API health endpoint failed (HTTP $HTTP_CODE)"
    fi
else
    warn "Health endpoint not found"
fi

# Test 6: Inventory API
log "Test 6: Inventory API Endpoint"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/inventory-v4.php?limit=1 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    pass "Inventory API responding (HTTP $HTTP_CODE)"
    
    # Test response content
    RESPONSE=$(curl -s http://localhost/api/inventory-v4.php?limit=1 2>/dev/null)
    if echo "$RESPONSE" | grep -q "critical_products\|low_stock_products\|overstock_products"; then
        pass "Inventory API returns expected data structure"
    else
        warn "Inventory API response structure unexpected"
    fi
else
    fail "Inventory API failed (HTTP $HTTP_CODE)"
fi

# Test 7: Frontend Build
log "Test 7: React Frontend Build"
if [ -f "$PROJECT_ROOT/public/build/index.html" ]; then
    pass "Frontend build exists"
    
    # Check if frontend is accessible
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/ 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        pass "Frontend is accessible (HTTP $HTTP_CODE)"
    else
        fail "Frontend not accessible (HTTP $HTTP_CODE)"
    fi
else
    fail "Frontend build not found"
fi

# Test 8: Frontend Assets
log "Test 8: Frontend Assets"
ASSET_COUNT=$(find "$PROJECT_ROOT/public/build" -type f \( -name "*.js" -o -name "*.css" \) 2>/dev/null | wc -l)
if [ "$ASSET_COUNT" -gt 0 ]; then
    pass "Frontend has $ASSET_COUNT JS/CSS assets"
else
    fail "No frontend assets found"
fi

# Test 9: File Permissions
log "Test 9: File Permissions"
if [ -r "$PROJECT_ROOT/.env" ]; then
    ENV_PERMS=$(stat -c "%a" "$PROJECT_ROOT/.env" 2>/dev/null || stat -f "%A" "$PROJECT_ROOT/.env" 2>/dev/null)
    if [ "$ENV_PERMS" = "600" ] || [ "$ENV_PERMS" = "400" ]; then
        pass ".env file has secure permissions ($ENV_PERMS)"
    else
        warn ".env file permissions are $ENV_PERMS (should be 600)"
    fi
else
    fail ".env file not readable"
fi

# Test 10: Storage Directories
log "Test 10: Storage Directories"
STORAGE_DIRS=("storage/logs" "storage/cache" "storage/backups")
for dir in "${STORAGE_DIRS[@]}"; do
    if [ -d "$PROJECT_ROOT/$dir" ] && [ -w "$PROJECT_ROOT/$dir" ]; then
        pass "Directory '$dir' exists and is writable"
    else
        fail "Directory '$dir' not writable"
    fi
done

# Test 11: Services Status
log "Test 11: System Services"
SERVICES=("nginx" "php8.1-fpm" "postgresql")
for service in "${SERVICES[@]}"; do
    if systemctl is-active --quiet "$service" 2>/dev/null; then
        pass "Service '$service' is active"
    else
        # Try alternative names
        if [ "$service" = "php8.1-fpm" ]; then
            if systemctl is-active --quiet "php-fpm" 2>/dev/null; then
                pass "Service 'php-fpm' is active"
                continue
            fi
        fi
        fail "Service '$service' is not active"
    fi
done

# Test 12: Disk Space
log "Test 12: Disk Space"
DISK_USAGE=$(df -h "$PROJECT_ROOT" | awk 'NR==2 {print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -lt 80 ]; then
    pass "Disk usage is ${DISK_USAGE}% (healthy)"
elif [ "$DISK_USAGE" -lt 90 ]; then
    warn "Disk usage is ${DISK_USAGE}% (warning threshold)"
else
    fail "Disk usage is ${DISK_USAGE}% (critical)"
fi

# Test 13: Log Files
log "Test 13: Log Files"
if [ -d "$PROJECT_ROOT/storage/logs" ]; then
    LOG_COUNT=$(find "$PROJECT_ROOT/storage/logs" -type f -name "*.log" 2>/dev/null | wc -l)
    if [ "$LOG_COUNT" -gt 0 ]; then
        pass "Found $LOG_COUNT log files"
    else
        warn "No log files found"
    fi
fi

# Test 14: Backup System
log "Test 14: Backup System"
if [ -f "$PROJECT_ROOT/scripts/postgresql_backup.sh" ] && [ -x "$PROJECT_ROOT/scripts/postgresql_backup.sh" ]; then
    pass "Backup script exists and is executable"
else
    fail "Backup script not found or not executable"
fi

# Test 15: Cron Jobs
log "Test 15: Cron Jobs"
CRON_COUNT=$(crontab -l 2>/dev/null | grep -c "mi_core_etl" || echo "0")
if [ "$CRON_COUNT" -gt 0 ]; then
    pass "Found $CRON_COUNT cron jobs configured"
else
    warn "No cron jobs found for mi_core_etl"
fi

# Test 16: API Response Time
log "Test 16: API Response Time"
START_TIME=$(date +%s%N)
curl -s http://localhost/api/inventory-v4.php?limit=1 > /dev/null 2>&1
END_TIME=$(date +%s%N)
RESPONSE_TIME=$(( (END_TIME - START_TIME) / 1000000 ))

if [ "$RESPONSE_TIME" -lt 2000 ]; then
    pass "API response time: ${RESPONSE_TIME}ms (excellent)"
elif [ "$RESPONSE_TIME" -lt 5000 ]; then
    warn "API response time: ${RESPONSE_TIME}ms (acceptable)"
else
    fail "API response time: ${RESPONSE_TIME}ms (too slow)"
fi

# Test 17: Database Performance
log "Test 17: Database Query Performance"
START_TIME=$(date +%s%N)
psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT COUNT(*) FROM products;" > /dev/null 2>&1
END_TIME=$(date +%s%N)
QUERY_TIME=$(( (END_TIME - START_TIME) / 1000000 ))

if [ "$QUERY_TIME" -lt 100 ]; then
    pass "Database query time: ${QUERY_TIME}ms (excellent)"
elif [ "$QUERY_TIME" -lt 500 ]; then
    warn "Database query time: ${QUERY_TIME}ms (acceptable)"
else
    fail "Database query time: ${QUERY_TIME}ms (slow)"
fi

# Test 18: Cache Directory
log "Test 18: Cache System"
if [ -d "$PROJECT_ROOT/storage/cache" ]; then
    if [ -w "$PROJECT_ROOT/storage/cache" ]; then
        pass "Cache directory is writable"
    else
        fail "Cache directory is not writable"
    fi
else
    fail "Cache directory not found"
fi

# Test 19: Configuration Files
log "Test 19: Configuration Files"
CONFIG_FILES=("config/database_postgresql.php" "config/api.php" "config/app.php")
for config in "${CONFIG_FILES[@]}"; do
    if [ -f "$PROJECT_ROOT/$config" ]; then
        pass "Configuration file '$config' exists"
    else
        fail "Configuration file '$config' not found"
    fi
done

# Test 20: Environment Variables
log "Test 20: Environment Variables"
if php -r "
require '$PROJECT_ROOT/config/database_postgresql.php';
\$required = ['PG_HOST', 'PG_USER', 'PG_NAME'];
\$missing = [];
foreach (\$required as \$var) {
    if (!getenv(\$var)) {
        \$missing[] = \$var;
    }
}
if (empty(\$missing)) {
    echo 'All required variables present\n';
    exit(0);
} else {
    echo 'Missing: ' . implode(', ', \$missing) . '\n';
    exit(1);
}
" >> "$TEST_LOG" 2>&1; then
    pass "All required environment variables are set"
else
    fail "Some environment variables are missing"
fi

# Summary
echo ""
echo "╔════════════════════════════════════════════════════════════╗"
echo "║                   SMOKE TEST SUMMARY                       ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
echo "Total Tests: $TESTS_TOTAL"
echo -e "${GREEN}Passed: $TESTS_PASSED${NC}"
echo -e "${RED}Failed: $TESTS_FAILED${NC}"
echo ""

if [ "$TESTS_FAILED" -eq 0 ]; then
    echo -e "${GREEN}✅ ALL SMOKE TESTS PASSED${NC}"
    echo ""
    echo "System is ready for production use!"
    EXIT_CODE=0
else
    echo -e "${RED}❌ SOME TESTS FAILED${NC}"
    echo ""
    echo "Please review the failures before proceeding to production."
    EXIT_CODE=1
fi

echo ""
echo "Test log saved to: $TEST_LOG"
echo ""

exit $EXIT_CODE
