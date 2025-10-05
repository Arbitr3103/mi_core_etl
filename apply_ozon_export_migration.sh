#!/bin/bash

# Apply Ozon Export Migration
# This script applies the database migration for Ozon analytics export functionality

echo "🚀 Applying Ozon Export Migration..."

# Database configuration
DB_HOST="127.0.0.1"
DB_NAME="mi_core_db"
DB_USER="ingest_user"
DB_PASSWORD="xK9#mQ7\$vN2@pL!rT4wY"

# Check if migration file exists
MIGRATION_FILE="migrations/add_ozon_temp_downloads_table.sql"

if [ ! -f "$MIGRATION_FILE" ]; then
    echo "❌ Migration file not found: $MIGRATION_FILE"
    exit 1
fi

echo "📋 Migration file found: $MIGRATION_FILE"

# Apply migration
echo "🔧 Applying migration to database..."

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$MIGRATION_FILE"

if [ $? -eq 0 ]; then
    echo "✅ Migration applied successfully!"
    
    # Verify table creation
    echo "🔍 Verifying table creation..."
    
    TABLE_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "SHOW TABLES LIKE 'ozon_temp_downloads';" --batch --skip-column-names)
    
    if [ -n "$TABLE_EXISTS" ]; then
        echo "✅ Table 'ozon_temp_downloads' created successfully!"
        
        # Show table structure
        echo "📊 Table structure:"
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "DESCRIBE ozon_temp_downloads;"
        
        echo ""
        echo "🎉 Ozon Export Migration completed successfully!"
        echo ""
        echo "📝 Next steps:"
        echo "1. Test the export functionality in the dashboard"
        echo "2. Set up a cron job to clean up expired files:"
        echo "   */30 * * * * curl -X POST 'https://api.zavodprostavok.ru/src/api/ozon-analytics.php?action=cleanup-downloads'"
        echo ""
        
    else
        echo "❌ Table creation verification failed!"
        exit 1
    fi
    
else
    echo "❌ Migration failed!"
    exit 1
fi