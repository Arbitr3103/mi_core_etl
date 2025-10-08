# MDM System Migration Guide

## Overview

The MDM (Master Data Management) system uses a robust migration system to manage database schema changes and data transformations. This guide explains how to use and extend the migration system.

## Features

- **Version Control**: Track database schema versions
- **Dependency Management**: Handle migration dependencies automatically
- **Rollback Support**: Safely rollback migrations when needed
- **Validation**: Validate migrations before execution
- **Audit Trail**: Complete audit trail of all migration activities
- **Data Integrity**: Ensure data integrity during migrations

## Quick Start

### 1. Setup Database Configuration

Set environment variables or modify the configuration in `migrate.php`:

```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=mdm_system
export DB_USER=root
export DB_PASS=your_password
```

### 2. Run Initial Migrations

```bash
# Check migration status
php migrate.php status

# Run all pending migrations
php migrate.php migrate

# Validate migrations
php migrate.php validate
```

### 3. Check Results

```bash
# View current status
php migrate.php status
```

## Migration Commands

### Basic Commands

```bash
# Run all pending migrations
php migrate.php migrate
php migrate.php up

# Rollback last migration
php migrate.php rollback
php migrate.php down

# Rollback to specific version
php migrate.php rollback 001_create_initial_schema

# Show migration status
php migrate.php status

# Validate all migrations
php migrate.php validate

# Create new migration template
php migrate.php create add_new_field

# Reset all migrations (DANGEROUS - drops all data)
php migrate.php reset

# Show help
php migrate.php help
```

## Migration Structure

### Core Migrations

1. **001_create_initial_schema**: Creates core MDM tables

   - `master_products`: Canonical product data
   - `sku_mapping`: External SKU mappings
   - `data_quality_metrics`: Quality monitoring

2. **002_create_audit_tables**: Creates audit and history tables

   - `matching_history`: Matching decision history
   - `audit_log`: Complete audit trail

3. **003_create_views_and_triggers**: Creates database views and triggers
   - Views for common queries
   - Audit triggers for automatic logging

### Migration Dependencies

```
001_create_initial_schema
    ↓
002_create_audit_tables
    ↓
003_create_views_and_triggers
```

## Creating New Migrations

### 1. Generate Migration Template

```bash
php migrate.php create add_product_tags
```

This creates a new migration file with the following structure:

```php
<?php

namespace MDM\Migrations\Migrations;

use MDM\Migrations\BaseMigration;
use PDO;

class Migration_2024_01_15_10_30_00_AddProductTags extends BaseMigration
{
    public function __construct()
    {
        parent::__construct(
            version: '2024_01_15_10_30_00_add_product_tags',
            description: 'Add product tags',
            dependencies: ['003_create_views_and_triggers'] // Previous migration
        );
    }

    public function up(PDO $pdo): bool
    {
        $this->log('Adding product tags...');

        $sql = "ALTER TABLE master_products
                ADD COLUMN tags JSON NULL COMMENT 'Product tags'";
        $this->executeSql($pdo, $sql);

        $this->log('Product tags added successfully');
        return true;
    }

    public function down(PDO $pdo): bool
    {
        $this->log('Removing product tags...');

        $sql = "ALTER TABLE master_products DROP COLUMN tags";
        $this->executeSql($pdo, $sql);

        $this->log('Product tags removed successfully');
        return true;
    }

    public function canExecute(PDO $pdo): bool
    {
        return !$this->columnExists($pdo, 'master_products', 'tags');
    }

    public function canRollback(PDO $pdo): bool
    {
        return $this->columnExists($pdo, 'master_products', 'tags');
    }
}
```

### 2. Register Migration

Add the new migration to `migrate.php`:

```php
$migrationManager->addMigrations([
    new Migration_001_CreateInitialSchema(),
    new Migration_002_CreateAuditTables(),
    new Migration_003_CreateViewsAndTriggers(),
    new Migration_2024_01_15_10_30_00_AddProductTags(), // Add here
]);
```

### 3. Run Migration

```bash
php migrate.php migrate
```

## Migration Best Practices

### 1. Always Test Migrations

```bash
# Test on development environment first
php migrate.php validate
php migrate.php migrate

# Test rollback
php migrate.php rollback
```

### 2. Use Transactions

Migrations automatically run in transactions, but for complex operations:

```php
public function up(PDO $pdo): bool
{
    $pdo->beginTransaction();

    try {
        // Multiple operations
        $this->executeSql($pdo, $sql1);
        $this->executeSql($pdo, $sql2);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}
```

### 3. Backup Data Before Major Changes

```php
public function up(PDO $pdo): bool
{
    // Backup table before major changes
    $this->backupTable($pdo, 'master_products');

    // Perform migration
    $this->executeSql($pdo, $sql);

    return true;
}
```

### 4. Validate Before Execution

```php
public function canExecute(PDO $pdo): bool
{
    // Check prerequisites
    if (!$this->tableExists($pdo, 'master_products')) {
        return false;
    }

    // Check data conditions
    $count = $this->getTableRowCount($pdo, 'master_products');
    if ($count > 1000000) {
        // Large table - might need special handling
        $this->log("Warning: Large table detected ({$count} rows)");
    }

    return true;
}
```

## Data Migration Examples

### Adding New Column with Default Values

```php
public function up(PDO $pdo): bool
{
    // Add column
    $sql = "ALTER TABLE master_products
            ADD COLUMN priority INT DEFAULT 0 COMMENT 'Product priority'";
    $this->executeSql($pdo, $sql);

    // Update existing records
    $sql = "UPDATE master_products
            SET priority = 1
            WHERE status = 'active'";
    $this->executeSql($pdo, $sql);

    return true;
}
```

### Migrating Data Between Tables

```php
public function up(PDO $pdo): bool
{
    // Create new table
    $sql = "CREATE TABLE product_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        parent_id INT NULL
    )";
    $this->executeSql($pdo, $sql);

    // Migrate data
    $sql = "INSERT INTO product_categories (name)
            SELECT DISTINCT canonical_category
            FROM master_products
            WHERE canonical_category IS NOT NULL";
    $this->executeSql($pdo, $sql);

    return true;
}
```

### Complex Data Transformation

```php
public function up(PDO $pdo): bool
{
    // Get data that needs transformation
    $stmt = $pdo->query("SELECT master_id, attributes FROM master_products WHERE attributes IS NOT NULL");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $attributes = json_decode($row['attributes'], true);

        // Transform attributes
        if (isset($attributes['old_field'])) {
            $attributes['new_field'] = $this->transformValue($attributes['old_field']);
            unset($attributes['old_field']);

            // Update record
            $updateSql = "UPDATE master_products SET attributes = ? WHERE master_id = ?";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([json_encode($attributes), $row['master_id']]);
        }
    }

    return true;
}
```

## Troubleshooting

### Common Issues

1. **Migration Fails**

   ```bash
   # Check validation
   php migrate.php validate

   # Check database connection
   php migrate.php status
   ```

2. **Rollback Fails**

   ```bash
   # Check if rollback is possible
   # Look at canRollback() method in migration
   ```

3. **Dependency Issues**
   ```bash
   # Validate dependencies
   php migrate.php validate
   ```

### Recovery Procedures

1. **Manual Rollback**

   ```sql
   -- Check migration status
   SELECT * FROM schema_migrations ORDER BY executed_at DESC;

   -- Manually remove migration record if needed
   DELETE FROM schema_migrations WHERE version = 'problematic_version';
   ```

2. **Data Recovery**
   ```sql
   -- Restore from backup table
   DROP TABLE master_products;
   RENAME TABLE master_products_backup_2024_01_15 TO master_products;
   ```

## Security Considerations

1. **Database Permissions**: Ensure migration user has appropriate permissions
2. **Backup Strategy**: Always backup before running migrations in production
3. **Access Control**: Restrict access to migration commands in production
4. **Audit Trail**: All migration activities are logged in `schema_migrations` table

## Production Deployment

### Pre-deployment Checklist

- [ ] Test migrations on staging environment
- [ ] Validate all migrations
- [ ] Backup production database
- [ ] Plan rollback strategy
- [ ] Schedule maintenance window
- [ ] Notify stakeholders

### Deployment Steps

```bash
# 1. Backup database
mysqldump -u user -p mdm_system > backup_$(date +%Y%m%d_%H%M%S).sql

# 2. Run migrations
php migrate.php validate
php migrate.php migrate

# 3. Verify results
php migrate.php status
```

### Post-deployment Verification

```bash
# Check migration status
php migrate.php status

# Verify data integrity
# Run application tests
# Monitor system performance
```

## Monitoring and Maintenance

### Regular Tasks

1. **Monitor Migration Performance**

   ```sql
   SELECT version, description, execution_time_ms
   FROM schema_migrations
   ORDER BY execution_time_ms DESC;
   ```

2. **Clean Old Migration Records**

   ```sql
   -- Keep last 100 migration records
   DELETE FROM schema_migrations
   WHERE id NOT IN (
       SELECT id FROM (
           SELECT id FROM schema_migrations
           ORDER BY executed_at DESC
           LIMIT 100
       ) t
   );
   ```

3. **Backup Migration History**
   ```bash
   mysqldump -u user -p mdm_system schema_migrations > migrations_backup.sql
   ```

## Advanced Features

### Custom Migration Base Classes

Create specialized migration classes for specific types of changes:

```php
abstract class DataMigration extends BaseMigration
{
    protected function migrateInBatches(PDO $pdo, string $sql, int $batchSize = 1000): void
    {
        // Implementation for batch processing
    }
}
```

### Migration Hooks

Add hooks for pre/post migration actions:

```php
public function up(PDO $pdo): bool
{
    $this->preUp($pdo);

    // Migration logic

    $this->postUp($pdo);
    return true;
}
```

This migration system provides a robust foundation for managing database changes in the MDM system while ensuring data integrity and providing rollback capabilities.
