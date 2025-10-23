#!/usr/bin/env python3
"""
Migrate inventory data only from MySQL to PostgreSQL
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
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {message}")

def main():
    log("=" * 60)
    log("Migrating inventory data to PostgreSQL")
    log("=" * 60)
    
    # Connect
    mysql_conn = pymysql.connect(**MYSQL_CONFIG)
    pg_conn = psycopg2.connect(**POSTGRES_CONFIG)
    
    mysql_cursor = mysql_conn.cursor(pymysql.cursors.DictCursor)
    pg_cursor = pg_conn.cursor()
    
    try:
        # Get product mapping
        log("Getting product mapping...")
        pg_cursor.execute("SELECT id, sku_ozon FROM dim_products")
        product_mapping = {row[1]: row[0] for row in pg_cursor.fetchall()}
        log(f"   Found {len(product_mapping)} products in PostgreSQL")
        
        # Fetch inventory from MySQL
        log("Fetching inventory from MySQL...")
        mysql_cursor.execute("SELECT * FROM inventory_data")
        inventory_items = mysql_cursor.fetchall()
        log(f"   Found {len(inventory_items)} inventory items")
        
        # Insert into PostgreSQL
        log("Inserting into PostgreSQL...")
        inserted = 0
        skipped = 0
        
        for item in inventory_items:
            product_id = product_mapping.get(item['sku'])
            
            if not product_id:
                log(f"   ⚠️  SKU not found: {item['sku']}")
                skipped += 1
                continue
            
            try:
                # Insert with required fields
                pg_cursor.execute("""
                    INSERT INTO inventory (
                        product_id, warehouse_name, stock_type,
                        quantity_present, quantity_reserved, source,
                        created_at, updated_at
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                    ON CONFLICT (product_id, warehouse_name, source) DO UPDATE SET
                        quantity_present = EXCLUDED.quantity_present,
                        quantity_reserved = EXCLUDED.quantity_reserved,
                        updated_at = EXCLUDED.updated_at
                """, (
                    product_id,
                    item['warehouse_name'],
                    'FBO',  # Default stock type
                    item['current_stock'] or 0,
                    item['reserved_stock'] or 0,
                    'OZON',  # Default source
                    item['created_at'],
                    item['last_sync_at']
                ))
                inserted += 1
                
                if inserted % 50 == 0:
                    log(f"   Inserted {inserted} items...")
                    
            except Exception as e:
                log(f"   ⚠️  Error for SKU {item['sku']}: {e}")
                skipped += 1
        
        pg_conn.commit()
        
        # Verify
        pg_cursor.execute("SELECT COUNT(*) FROM inventory")
        total = pg_cursor.fetchone()[0]
        
        log("=" * 60)
        log(f"✅ Migration complete!")
        log(f"   Inserted: {inserted}")
        log(f"   Skipped: {skipped}")
        log(f"   Total in PostgreSQL: {total}")
        log("=" * 60)
        
        # Show sample
        pg_cursor.execute("""
            SELECT p.sku_ozon, p.product_name, i.quantity_present, i.warehouse_name
            FROM dim_products p
            JOIN inventory i ON p.id = i.product_id
            LIMIT 5
        """)
        
        log("Sample data:")
        for row in pg_cursor.fetchall():
            log(f"   {row[0]}: {row[2]} units in {row[3]}")
        
        return 0 if inserted > 0 else 1
        
    except Exception as e:
        log(f"❌ Error: {e}")
        import traceback
        traceback.print_exc()
        pg_conn.rollback()
        return 1
    finally:
        mysql_cursor.close()
        pg_cursor.close()
        mysql_conn.close()
        pg_conn.close()

if __name__ == "__main__":
    sys.exit(main())
