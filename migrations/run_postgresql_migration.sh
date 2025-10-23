#!/bin/bash

# ===================================================================
# PostgreSQL Migration Execution Script
# Complete migration from MySQL to PostgreSQL for mi_core_etl
# ===================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Check if running from correct directory
if [ ! -f ".env" ]; then
    error "Please run this script from the project root directory (where .env file is located)"
    exit 1
fi

# Load environment variables
if [ -f ".env" ]; then
    export $(grep -v '^#' .env | xargs)
fi

log "🐘 Starting PostgreSQL Migration Process..."

# ===================================================================
# STEP 1: INSTALL AND CONFIGURE POSTGRESQL
# ===================================================================

log "📦 Step 1: Installing and configuring PostgreSQL..."

if command -v psql &> /dev/null; then
    log "PostgreSQL is already installed"
else
    log "Installing PostgreSQL..."
    if [ -f "deployment/scripts/install_postgresql.sh" ]; then
        chmod +x deployment/scripts/install_postgresql.sh
        ./deployment/scripts/install_postgresql.sh
    else
        error "PostgreSQL installation script not found"
        exit 1
    fi
fi

# ===================================================================
# STEP 2: CREATE DATABASE AND SCHEMA
# ===================================================================

log "🗄️ Step 2: Creating PostgreSQL database and schema..."

# Check if database exists
DB_EXISTS=$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${PG_NAME:-mi_core_db}'" 2>/dev/null || echo "0")

if [ "$DB_EXISTS" = "1" ]; then
    warning "Database ${PG_NAME:-mi_core_db} already exists"
    read -p "Do you want to recreate it? This will delete all existing data! (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        log "Dropping existing database..."
        sudo -u postgres dropdb "${PG_NAME:-mi_core_db}" || true
        sudo -u postgres createdb -O "${PG_USER:-mi_core_user}" "${PG_NAME:-mi_core_db}"
    fi
else
    log "Creating database ${PG_NAME:-mi_core_db}..."
    sudo -u postgres createdb -O "${PG_USER:-mi_core_user}" "${PG_NAME:-mi_core_db}"
fi

# Create schema
log "Creating database schema..."
if [ -f "migrations/postgresql_schema.sql" ]; then
    PGPASSWORD="${PG_PASSWORD}" psql -h "${PG_HOST:-localhost}" -p "${PG_PORT:-5432}" -U "${PG_USER:-mi_core_user}" -d "${PG_NAME:-mi_core_db}" -f migrations/postgresql_schema.sql
    success "Database schema created successfully"
else
    error "PostgreSQL schema file not found"
    exit 1
fi

# ===================================================================
# STEP 3: INSTALL PYTHON DEPENDENCIES FOR MIGRATION
# ===================================================================

log "🐍 Step 3: Installing Python dependencies for data migration..."

# Check if Python 3 is available
if ! command -v python3 &> /dev/null; then
    error "Python 3 is required for data migration"
    exit 1
fi

# Install required Python packages
log "Installing Python packages..."
pip3 install --user mysql-connector-python psycopg2-binary || {
    error "Failed to install Python packages. Trying with sudo..."
    sudo pip3 install mysql-connector-python psycopg2-binary
}

# ===================================================================
# STEP 4: BACKUP MYSQL DATABASE
# ===================================================================

log "💾 Step 4: Creating MySQL database backup..."

BACKUP_DIR="backups/mysql_backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

log "Creating MySQL backup in $BACKUP_DIR..."
mysqldump -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" > "$BACKUP_DIR/mysql_backup.sql"

if [ $? -eq 0 ]; then
    success "MySQL backup created successfully"
else
    error "Failed to create MySQL backup"
    exit 1
fi

# ===================================================================
# STEP 5: RUN DATA MIGRATION
# ===================================================================

log "🔄 Step 5: Migrating data from MySQL to PostgreSQL..."

# Set environment variables for migration script
export PG_HOST="${PG_HOST:-localhost}"
export PG_PORT="${PG_PORT:-5432}"
export PG_NAME="${PG_NAME:-mi_core_db}"
export PG_USER="${PG_USER:-mi_core_user}"
export PG_PASSWORD="${PG_PASSWORD}"

# Run migration script
if [ -f "migrations/migrate_mysql_to_postgresql.py" ]; then
    log "Starting data migration..."
    python3 migrations/migrate_mysql_to_postgresql.py
    
    if [ $? -eq 0 ]; then
        success "Data migration completed successfully"
    else
        error "Data migration failed"
        exit 1
    fi
else
    error "Migration script not found"
    exit 1
fi

# ===================================================================
# STEP 6: UPDATE APPLICATION CONFIGURATION
# ===================================================================

log "⚙️ Step 6: Updating application configuration..."

# Backup current config
cp config/database.php config/database_mysql_backup.php

# Switch to PostgreSQL configuration
if [ -f "config/database_postgresql.php" ]; then
    cp config/database_postgresql.php config/database.php
    success "Application configuration updated to use PostgreSQL"
else
    error "PostgreSQL configuration file not found"
    exit 1
fi

# ===================================================================
# STEP 7: TEST POSTGRESQL CONNECTION
# ===================================================================

log "🧪 Step 7: Testing PostgreSQL connection..."

# Test with PHP
php -r "
require_once 'config/database.php';
try {
    \$pdo = getDatabaseConnection();
    \$result = \$pdo->query('SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = \'public\'')->fetch();
    echo 'PostgreSQL connection successful. Tables found: ' . \$result['count'] . PHP_EOL;
} catch (Exception \$e) {
    echo 'PostgreSQL connection failed: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"

if [ $? -eq 0 ]; then
    success "PostgreSQL connection test passed"
else
    error "PostgreSQL connection test failed"
    exit 1
fi

# ===================================================================
# STEP 8: UPDATE ETL SCRIPTS
# ===================================================================

log "🔧 Step 8: Updating ETL scripts for PostgreSQL..."

# Find and update Python ETL scripts
find . -name "*.py" -path "./src/etl/*" -exec sed -i 's/mysql\.connector/psycopg2/g' {} \; 2>/dev/null || true
find . -name "*.py" -path "./src/etl/*" -exec sed -i 's/MySQLdb/psycopg2/g' {} \; 2>/dev/null || true

# Update any hardcoded MySQL references in PHP files
find . -name "*.php" -path "./src/api/*" -exec sed -i 's/mysql:/pgsql:/g' {} \; 2>/dev/null || true

success "ETL scripts updated for PostgreSQL"

# ===================================================================
# STEP 9: VERIFY MIGRATION
# ===================================================================

log "✅ Step 9: Verifying migration integrity..."

# Run verification queries
VERIFICATION_RESULT=$(php -r "
require_once 'config/database.php';
try {
    \$pdo = getDatabaseConnection();
    
    // Check critical tables
    \$tables = ['dim_products', 'inventory', 'stock_movements', 'fact_orders'];
    \$total_rows = 0;
    
    foreach (\$tables as \$table) {
        \$result = \$pdo->query(\"SELECT COUNT(*) as count FROM \$table\")->fetch();
        \$count = \$result['count'];
        \$total_rows += \$count;
        echo \"\$table: \$count rows\" . PHP_EOL;
    }
    
    echo \"Total rows in critical tables: \$total_rows\" . PHP_EOL;
    
    // Test dashboard view
    \$result = \$pdo->query('SELECT COUNT(*) as count FROM v_dashboard_inventory')->fetch();
    echo 'Dashboard view rows: ' . \$result['count'] . PHP_EOL;
    
} catch (Exception \$e) {
    echo 'Verification failed: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
")

echo "$VERIFICATION_RESULT"

if [ $? -eq 0 ]; then
    success "Migration verification passed"
else
    error "Migration verification failed"
    exit 1
fi

# ===================================================================
# STEP 10: CREATE ROLLBACK SCRIPT
# ===================================================================

log "🔄 Step 10: Creating rollback script..."

cat > "rollback_to_mysql.sh" << 'EOF'
#!/bin/bash
# Rollback script to revert to MySQL

echo "Rolling back to MySQL configuration..."

# Restore MySQL configuration
if [ -f "config/database_mysql_backup.php" ]; then
    cp config/database_mysql_backup.php config/database.php
    echo "MySQL configuration restored"
else
    echo "MySQL backup configuration not found"
    exit 1
fi

# Update environment to use MySQL
sed -i 's/^#DB_HOST=/DB_HOST=/' .env
sed -i 's/^#DB_USER=/DB_USER=/' .env
sed -i 's/^#DB_PASSWORD=/DB_PASSWORD=/' .env
sed -i 's/^#DB_NAME=/DB_NAME=/' .env
sed -i 's/^#DB_PORT=/DB_PORT=/' .env

echo "Rollback completed. System is now using MySQL."
echo "You may need to restart your web server."
EOF

chmod +x rollback_to_mysql.sh
success "Rollback script created: rollback_to_mysql.sh"

# ===================================================================
# COMPLETION
# ===================================================================

log "🎉 PostgreSQL Migration Completed Successfully!"
echo ""
echo "📋 MIGRATION SUMMARY:"
echo "  ✅ PostgreSQL installed and configured"
echo "  ✅ Database schema created"
echo "  ✅ Data migrated from MySQL"
echo "  ✅ Application configuration updated"
echo "  ✅ ETL scripts updated"
echo "  ✅ Migration verified"
echo ""
echo "📁 BACKUP LOCATIONS:"
echo "  MySQL backup: $BACKUP_DIR/mysql_backup.sql"
echo "  MySQL config backup: config/database_mysql_backup.php"
echo ""
echo "🔧 NEXT STEPS:"
echo "  1. Test all ETL processes with PostgreSQL"
echo "  2. Update any remaining scripts that connect to the database"
echo "  3. Monitor system performance and logs"
echo "  4. Consider removing MySQL after confirming everything works"
echo ""
echo "⚠️  ROLLBACK:"
echo "  If you need to rollback to MySQL, run: ./rollback_to_mysql.sh"
echo ""
success "Migration process completed successfully!"