#!/bin/bash

# Execute visibility migration script
# Task: 1.2 Execute schema migration with backup
# Date: 2025-10-27

# Database connection parameters
DB_HOST="localhost"
DB_PORT="5432"
DB_NAME="mi_core_db"
DB_USER="mi_core_user"
DB_PASSWORD="PostgreSQL_MDM_2025_SecurePass!"

echo "🚀 Starting visibility migration process..."

# Step 1: Create backup
echo "📦 Step 1: Creating backup..."
./backup_schema_before_visibility_migration.sh

if [ $? -ne 0 ]; then
    echo "❌ Backup failed! Aborting migration."
    exit 1
fi

# Step 2: Execute migration on development environment first
echo "🔧 Step 2: Executing migration..."

# Export password to avoid prompts
export PGPASSWORD="$DB_PASSWORD"

# Execute the migration script
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -f "migrations/008_add_visibility_to_dim_products.sql"

if [ $? -eq 0 ]; then
    echo "✅ Migration executed successfully!"
else
    echo "❌ Migration failed!"
    echo "🔄 You can rollback using: psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_NAME -f migrations/rollback_008_add_visibility_to_dim_products.sql"
    exit 1
fi

# Step 3: Validate schema changes
echo "🔍 Step 3: Validating schema changes..."

# Check if visibility column exists
COLUMN_EXISTS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -t -c "
SELECT COUNT(*) 
FROM information_schema.columns 
WHERE table_name = 'dim_products' 
AND column_name = 'visibility'
AND table_schema = 'public';")

if [ "$COLUMN_EXISTS" -eq 1 ]; then
    echo "✅ Visibility column added successfully"
else
    echo "❌ Visibility column not found!"
    exit 1
fi

# Check if index exists
INDEX_EXISTS=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -t -c "
SELECT COUNT(*) 
FROM pg_indexes 
WHERE tablename = 'dim_products' 
AND indexname = 'idx_dim_products_visibility';")

if [ "$INDEX_EXISTS" -eq 1 ]; then
    echo "✅ Visibility index created successfully"
else
    echo "❌ Visibility index not found!"
    exit 1
fi

# Step 4: Display table structure
echo "📋 Step 4: Current dim_products table structure:"
psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "\d dim_products"

# Unset password
unset PGPASSWORD

echo "🎉 Migration completed successfully!"
echo "📝 Migration log has been recorded in etl_execution_log table"