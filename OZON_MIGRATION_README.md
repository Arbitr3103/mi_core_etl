# Ozon Analytics Database Migration

This document describes how to apply the database migration for Ozon Analytics integration.

## Overview

The migration creates four new tables for storing Ozon analytics data:

- `ozon_api_settings` - API configuration and credentials
- `ozon_funnel_data` - Cached funnel analytics data
- `ozon_demographics` - Demographic analytics data
- `ozon_campaigns` - Campaign performance data

## Files

- `migrations/add_ozon_analytics_tables.sql` - Main migration SQL file
- `apply_ozon_analytics_migration.sh` - Safe migration application script
- `rollback_ozon_analytics_migration.sh` - Rollback script to remove tables
- `verify_ozon_tables.py` - Verification script to check migration success
- `OZON_MIGRATION_README.md` - This documentation file

## Prerequisites

1. MySQL/MariaDB database server
2. Database configuration in `config.py` or `config_local.py`
3. Appropriate database permissions (CREATE, DROP, INDEX)
4. Python 3 with mysql-connector-python (for verification script)

## Migration Process

### Step 1: Apply Migration

Run the migration script:

```bash
./apply_ozon_analytics_migration.sh
```

This script will:

- Check prerequisites and database connectivity
- Create backup of existing tables (if any)
- Apply the migration
- Verify that all tables were created successfully
- Generate detailed logs

### Step 2: Verify Migration

Run the verification script to ensure everything is properly configured:

```bash
python3 verify_ozon_tables.py
```

This will check:

- All required tables exist
- All expected columns are present
- All indexes are created properly

### Step 3: Check Logs

Review the migration log file in the `logs/` directory for any issues or warnings.

## Rollback (if needed)

If you need to remove the Ozon analytics tables:

```bash
./rollback_ozon_analytics_migration.sh
```

**⚠️ WARNING**: This will permanently delete all Ozon analytics tables and data!

## Table Structure

### ozon_api_settings

Stores API configuration and credentials:

- `client_id` - Ozon API Client ID
- `api_key_hash` - Hashed API Key for security
- `is_active` - Whether configuration is active

### ozon_funnel_data

Caches funnel analytics data:

- Date range fields (`date_from`, `date_to`)
- Product and campaign identifiers
- Funnel metrics (views, cart additions, orders)
- Conversion rates between stages

### ozon_demographics

Stores demographic analytics:

- Date range fields
- Demographic segments (age, gender, region)
- Orders count and revenue per segment

### ozon_campaigns

Campaign performance data:

- Campaign identification and date range
- Performance metrics (impressions, clicks, spend)
- Calculated metrics (CTR, CPC, ROAS)

## Indexes

The migration creates optimized indexes for:

- Date range queries
- Product and campaign filtering
- Demographic segmentation
- Performance analysis
- Cache management

## Security Considerations

- API keys are stored as hashes, not plain text
- All tables use UTF8MB4 charset for proper Unicode support
- Indexes are optimized for query performance
- Backup is automatically created before migration

## Troubleshooting

### Common Issues

1. **Permission Denied**: Ensure the database user has CREATE and INDEX privileges
2. **Connection Failed**: Check database configuration in config files
3. **Table Already Exists**: The migration uses `IF NOT EXISTS` so it's safe to re-run
4. **Backup Failed**: Ensure sufficient disk space in the `backups/` directory

### Log Files

All operations are logged to files in the `logs/` directory:

- Migration logs: `ozon_migration_YYYYMMDD_HHMMSS.log`
- Rollback logs: `ozon_rollback_YYYYMMDD_HHMMSS.log`

### Manual Verification

You can manually check if tables exist:

```sql
SHOW TABLES LIKE 'ozon_%';
DESCRIBE ozon_api_settings;
SHOW INDEX FROM ozon_funnel_data;
```

## Next Steps

After successful migration:

1. Configure Ozon API credentials in the `ozon_api_settings` table
2. Implement the OzonAnalyticsAPI class (Task 2)
3. Set up data collection processes (Tasks 3-5)
4. Integrate with the dashboard frontend (Tasks 6-9)

## Requirements Satisfied

This migration satisfies the following requirements:

- **4.1**: API configuration storage and management
- **4.2**: Secure credential storage with validation
