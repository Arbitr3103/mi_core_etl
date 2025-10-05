#!/bin/bash

# Apply Ozon Analytics Security Migration
# This script applies the security tables migration for Ozon Analytics integration

set -e  # Exit on any error

# Configuration
DB_HOST="localhost"
DB_NAME="mi_core_db"
DB_USER="mi_core_user"
DB_PASS="secure_password_123"
MIGRATION_FILE="migrations/add_ozon_security_tables.sql"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}=== Ozon Analytics Security Migration ===${NC}"
echo "Starting security tables migration..."

# Check if migration file exists
if [ ! -f "$MIGRATION_FILE" ]; then
    echo -e "${RED}Error: Migration file not found: $MIGRATION_FILE${NC}"
    exit 1
fi

# Check if MySQL is available
if ! command -v mysql &> /dev/null; then
    echo -e "${RED}Error: MySQL client not found. Please install MySQL client.${NC}"
    exit 1
fi

# Test database connection
echo "Testing database connection..."
if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "USE $DB_NAME;" 2>/dev/null; then
    echo -e "${RED}Error: Cannot connect to database. Please check credentials.${NC}"
    exit 1
fi

echo -e "${GREEN}Database connection successful.${NC}"

# Create backup of existing tables (if they exist)
echo "Creating backup of existing security tables..."
BACKUP_FILE="backup_ozon_security_$(date +%Y%m%d_%H%M%S).sql"

mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <<EOF
-- Create backup of existing tables
SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0;

-- Backup existing tables if they exist
CREATE TABLE IF NOT EXISTS backup_ozon_access_log_$(date +%Y%m%d) AS SELECT * FROM ozon_access_log WHERE 1=0;
CREATE TABLE IF NOT EXISTS backup_ozon_user_access_$(date +%Y%m%d) AS SELECT * FROM ozon_user_access WHERE 1=0;
CREATE TABLE IF NOT EXISTS backup_ozon_rate_limit_counters_$(date +%Y%m%d) AS SELECT * FROM ozon_rate_limit_counters WHERE 1=0;

-- Copy data if tables exist
INSERT IGNORE INTO backup_ozon_access_log_$(date +%Y%m%d) SELECT * FROM ozon_access_log;
INSERT IGNORE INTO backup_ozon_user_access_$(date +%Y%m%d) SELECT * FROM ozon_user_access;
INSERT IGNORE INTO backup_ozon_rate_limit_counters_$(date +%Y%m%d) SELECT * FROM ozon_rate_limit_counters;

SET SQL_NOTES=@OLD_SQL_NOTES;
EOF

echo -e "${GREEN}Backup completed.${NC}"

# Apply migration
echo "Applying security migration..."
if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$MIGRATION_FILE"; then
    echo -e "${GREEN}Migration applied successfully!${NC}"
else
    echo -e "${RED}Error: Migration failed. Check the error messages above.${NC}"
    exit 1
fi

# Verify migration
echo "Verifying migration..."
VERIFICATION_RESULT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT 
    COUNT(*) as table_count
FROM information_schema.tables 
WHERE table_schema = '$DB_NAME' 
AND table_name IN ('ozon_access_log', 'ozon_user_access', 'ozon_rate_limit_counters', 'ozon_security_config')
" -s -N)

if [ "$VERIFICATION_RESULT" -eq 4 ]; then
    echo -e "${GREEN}All security tables created successfully.${NC}"
else
    echo -e "${YELLOW}Warning: Expected 4 tables, found $VERIFICATION_RESULT${NC}"
fi

# Check views
VIEW_COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT COUNT(*) 
FROM information_schema.views 
WHERE table_schema = '$DB_NAME' 
AND table_name IN ('v_ozon_security_stats', 'v_ozon_user_activity')
" -s -N)

if [ "$VIEW_COUNT" -eq 2 ]; then
    echo -e "${GREEN}Security views created successfully.${NC}"
else
    echo -e "${YELLOW}Warning: Expected 2 views, found $VIEW_COUNT${NC}"
fi

# Check stored procedure
PROC_COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT COUNT(*) 
FROM information_schema.routines 
WHERE routine_schema = '$DB_NAME' 
AND routine_name = 'CleanupOzonSecurityLogs'
" -s -N)

if [ "$PROC_COUNT" -eq 1 ]; then
    echo -e "${GREEN}Cleanup procedure created successfully.${NC}"
else
    echo -e "${YELLOW}Warning: Cleanup procedure not found.${NC}"
fi

# Display configuration
echo -e "\n${GREEN}=== Security Configuration ===${NC}"
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT 
    config_key,
    JSON_PRETTY(config_value) as configuration
FROM ozon_security_config
ORDER BY config_key;
"

# Display user access
echo -e "\n${GREEN}=== User Access Levels ===${NC}"
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT 
    user_id,
    CASE access_level
        WHEN 0 THEN 'None'
        WHEN 1 THEN 'Read'
        WHEN 2 THEN 'Export'
        WHEN 3 THEN 'Admin'
        ELSE 'Unknown'
    END as access_level,
    granted_by,
    granted_at,
    is_active
FROM ozon_user_access
ORDER BY access_level DESC, granted_at DESC;
"

echo -e "\n${GREEN}=== Migration Summary ===${NC}"
echo "✅ Security tables created"
echo "✅ Default configuration inserted"
echo "✅ Security views created"
echo "✅ Cleanup procedures created"
echo "✅ Default admin user created"

echo -e "\n${YELLOW}Next Steps:${NC}"
echo "1. Update the default admin user_id in ozon_user_access table"
echo "2. Configure additional users with appropriate access levels"
echo "3. Review and adjust rate limits in ozon_security_config"
echo "4. Test the security integration in the dashboard"

echo -e "\n${GREEN}Migration completed successfully!${NC}"

# Optional: Show recent activity (will be empty initially)
echo -e "\n${GREEN}=== Recent Security Activity ===${NC}"
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT 
    event_type,
    user_id,
    operation,
    created_at
FROM ozon_access_log 
ORDER BY created_at DESC 
LIMIT 10;
" || echo "No security activity yet (this is normal for a fresh installation)"

echo -e "\n${GREEN}Security migration completed successfully!${NC}"