#!/usr/bin/env python3
"""
Simple MySQL to PostgreSQL Migration Script
Migrates data from mi_core (MySQL) to mi_core_db (PostgreSQL)
"""

import sys
import pymysql
import psycopg2
from datetime import datetime

# MySQL Configuration
MYSQL_CONFIG = {
    'host': 'localhost',
    'user': 'v_admin',
    'password': 'Arbitr09102022!',
    'database': 'mi_core',
    'charset': 'utf8mb4'
}

# PostgreSQL Configuration
POSTGRES_CONFIG = {
    'host': 'localhost',
    'user': 'mi_core_user',
    'password': 'MiCore2025SecurePass!',
    'database': 'mi_core_db',
    'port': 5432
}

def log(message):
    """Print timestamped log message"""
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {message}")

def connect_mysql():
    """Connect to MySQL database"""
    try:
        conn = pymysql.connect(**MYSQL_CONFIG)
        log("✅ Connected to MySQL")
        return conn
    except Exception as e:
        log(f"❌ MySQL connection failed: {e}")
        sys.exit(1)

def connect_postgres():
    """Connect to PostgreSQL database"""
    try:
        conn = psycopg2.connect(**POSTGRES_CONFIG)
        log("✅ Connected to PostgreSQL")
        return conn
    except Exception as e:
        log(f"❌ PostgreSQL connection failed: {e}")
        sys.exit(1)

def migrate_dim_products(mysql_conn, pg_conn):
    """Migrate dim_products table"""
    log("📦 Migrating dim_products...")
    
    mysql_cursor = mysql_conn.cursor(pymysql.cursors.DictCursor)
    pg_cursor = pg_conn.cursor()
    
    # Fetch data from MySQL
    mysql_cursor.execute("SELECT * FROM dim_products")
    products = mysql_cursor.fetchall()
    
    log(f"   Found {len(products)} products in MySQL")
    
    # Insert into PostgreSQL
    inserted = 0
    for product in products:
        try:
            pg_cursor.execute("""
                INSERT INTO dim_products (
                    sku_ozon, barcode, product_name, cost_price, 
                    created_at, updated_at
                ) VALUES (%s, %s, %s, %s, %s, %s)
                ON CONFLICT (sku_ozon) DO UPDATE SET
                    barcode = EXCLUDED.barcode,
                    product_name = EXCLUDED.product_name,
                    cost_price = EXCLUDED.cost_price,
                    updated_at = EXCLUDED.updated_at
            """, (
                product['sku_ozon'],
                product['barcode'],
                product['product_name'],
                product['cost_price'],
                product['created_at'],
                product['updated_at']
            ))
            inserted += 1
        except Exception as e:
            log(f"   ⚠️  Error inserting product {product['sku_ozon']}: {e}")
    
    pg_conn.commit()
    log(f"✅ Migrated {inserted}/{len(products)} products")
    
    mysql_cursor.close()
    pg_cursor.close()
    
    return inserted

def migrate_inventory_data(mysql_conn, pg_conn):
    """Migrate inventory_data table to inventory table"""
    log("📦 Migrating inventory_data...")
    
    mysql_cursor = mysql_conn.cursor(pymysql.cursors.DictCursor)
    pg_cursor = pg_conn.cursor()
    
    # Fetch data from MySQL
    mysql_cursor.execute("SELECT * FROM inventory_data")
    inventory_items = mysql_cursor.fetchall()
    
    log(f"   Found {len(inventory_items)} inventory items in MySQL")
    
    # Get product IDs mapping
    pg_cursor.execute("SELECT id, sku_ozon FROM dim_products")
    product_mapping = {row[1]: row[0] for row in pg_cursor.fetchall()}
    
    # Insert into PostgreSQL
    inserted = 0
    for item in inventory_items:
        try:
            # Find product_id by SKU
            product_id = product_mapping.get(item['sku'])
            
            if not product_id:
                log(f"   ⚠️  Product not found for SKU: {item['sku']}")
                continue
            
            pg_cursor.execute("""
                INSERT INTO inventory (
                    product_id, warehouse_name, quantity_present, 
                    quantity_reserved, last_sync_at, created_at, updated_at
                ) VALUES (%s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (product_id, warehouse_name) DO UPDATE SET
                    quantity_present = EXCLUDED.quantity_present,
                    quantity_reserved = EXCLUDED.quantity_reserved,
                    last_sync_at = EXCLUDED.last_sync_at,
                    updated_at = EXCLUDED.updated_at
            """, (
                product_id,
                item['warehouse_name'],
                item['current_stock'],
                item['reserved_stock'],
                item['last_sync_at'],
                item['created_at'],
                item['last_sync_at']  # Use last_sync_at as updated_at
            ))
            inserted += 1
        except Exception as e:
            log(f"   ⚠️  Error inserting inventory for SKU {item['sku']}: {e}")
    
    pg_conn.commit()
    log(f"✅ Migrated {inserted}/{len(inventory_items)} inventory items")
    
    mysql_cursor.close()
    pg_cursor.close()
    
    return inserted

def verify_migration(pg_conn):
    """Verify migrated data"""
    log("🔍 Verifying migration...")
    
    pg_cursor = pg_conn.cursor()
    
    # Check products
    pg_cursor.execute("SELECT COUNT(*) FROM dim_products")
    products_count = pg_cursor.fetchone()[0]
    log(f"   Products in PostgreSQL: {products_count}")
    
    # Check inventory
    pg_cursor.execute("SELECT COUNT(*) FROM inventory")
    inventory_count = pg_cursor.fetchone()[0]
    log(f"   Inventory items in PostgreSQL: {inventory_count}")
    
    # Check sample data
    pg_cursor.execute("""
        SELECT p.sku_ozon, p.product_name, i.quantity_present, i.warehouse_name
        FROM dim_products p
        LEFT JOIN inventory i ON p.id = i.product_id
        LIMIT 5
    """)
    samples = pg_cursor.fetchall()
    
    log("   Sample data:")
    for sample in samples:
        log(f"      SKU: {sample[0]}, Product: {sample[1][:50]}..., Stock: {sample[2]}, Warehouse: {sample[3]}")
    
    pg_cursor.close()
    
    return products_count, inventory_count

def main():
    """Main migration function"""
    log("=" * 60)
    log("MI Core ETL - MySQL to PostgreSQL Migration")
    log("=" * 60)
    
    # Connect to databases
    mysql_conn = connect_mysql()
    pg_conn = connect_postgres()
    
    try:
        # Migrate data
        products_migrated = migrate_dim_products(mysql_conn, pg_conn)
        inventory_migrated = migrate_inventory_data(mysql_conn, pg_conn)
        
        # Verify migration
        products_count, inventory_count = verify_migration(pg_conn)
        
        # Summary
        log("=" * 60)
        log("Migration Summary:")
        log(f"   Products migrated: {products_migrated}")
        log(f"   Inventory items migrated: {inventory_migrated}")
        log(f"   Total products in PostgreSQL: {products_count}")
        log(f"   Total inventory in PostgreSQL: {inventory_count}")
        log("=" * 60)
        
        if products_count > 0 and inventory_count > 0:
            log("✅ Migration completed successfully!")
            return 0
        else:
            log("⚠️  Migration completed with warnings")
            return 1
            
    except Exception as e:
        log(f"❌ Migration failed: {e}")
        import traceback
        traceback.print_exc()
        return 1
    finally:
        mysql_conn.close()
        pg_conn.close()
        log("Connections closed")

if __name__ == "__main__":
    sys.exit(main())
