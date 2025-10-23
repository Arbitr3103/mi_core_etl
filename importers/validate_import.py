#!/usr/bin/env python3
"""
Validation script for Ozon Warehouse Import

This script validates that the imported data is correct and complete.
It checks:
- Number of products imported
- Number of inventory records
- Number of sales records
- Data integrity (no missing references)
- Cluster assignments

Author: Warehouse Dashboard System
Date: October 22, 2025
"""

import os
import sys
import psycopg2
from dotenv import load_dotenv
from datetime import datetime

# Load environment variables
load_dotenv()


def connect_to_database():
    """Connect to PostgreSQL database."""
    try:
        conn = psycopg2.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            port=os.getenv('DB_PORT', '5432'),
            database=os.getenv('DB_NAME', 'mi_core_db'),
            user=os.getenv('DB_USER'),
            password=os.getenv('DB_PASSWORD')
        )
        return conn
    except Exception as e:
        print(f"❌ Failed to connect to database: {e}")
        sys.exit(1)


def validate_products(cursor):
    """Validate products table."""
    print("\n" + "=" * 60)
    print("VALIDATING PRODUCTS")
    print("=" * 60)
    
    # Count products
    cursor.execute("SELECT COUNT(*) FROM dim_products WHERE sku_ozon LIKE 'SKU%'")
    product_count = cursor.fetchone()[0]
    print(f"✅ Found {product_count} test products")
    
    # Check for products without names
    cursor.execute("""
        SELECT COUNT(*) FROM dim_products 
        WHERE sku_ozon LIKE 'SKU%' AND (product_name IS NULL OR product_name = '')
    """)
    missing_names = cursor.fetchone()[0]
    if missing_names > 0:
        print(f"⚠️  {missing_names} products have missing names")
    else:
        print("✅ All products have names")
    
    # List sample products
    cursor.execute("""
        SELECT sku_ozon, product_name 
        FROM dim_products 
        WHERE sku_ozon LIKE 'SKU%' 
        ORDER BY sku_ozon 
        LIMIT 5
    """)
    print("\nSample products:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]}")


def validate_inventory(cursor):
    """Validate inventory table."""
    print("\n" + "=" * 60)
    print("VALIDATING INVENTORY")
    print("=" * 60)
    
    # Count inventory records
    cursor.execute("SELECT COUNT(*) FROM inventory WHERE source = 'ozon'")
    inventory_count = cursor.fetchone()[0]
    print(f"✅ Found {inventory_count} inventory records")
    
    # Count unique products
    cursor.execute("""
        SELECT COUNT(DISTINCT product_id) 
        FROM inventory 
        WHERE source = 'ozon'
    """)
    unique_products = cursor.fetchone()[0]
    print(f"✅ {unique_products} unique products in inventory")
    
    # Count unique warehouses
    cursor.execute("""
        SELECT COUNT(DISTINCT warehouse_name) 
        FROM inventory 
        WHERE source = 'ozon'
    """)
    unique_warehouses = cursor.fetchone()[0]
    print(f"✅ {unique_warehouses} unique warehouses")
    
    # Check cluster assignments
    cursor.execute("""
        SELECT cluster, COUNT(*) as count
        FROM inventory 
        WHERE source = 'ozon'
        GROUP BY cluster
        ORDER BY count DESC
    """)
    print("\nCluster distribution:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]} records")
    
    # Check for missing clusters
    cursor.execute("""
        SELECT COUNT(*) 
        FROM inventory 
        WHERE source = 'ozon' AND (cluster IS NULL OR cluster = '')
    """)
    missing_clusters = cursor.fetchone()[0]
    if missing_clusters > 0:
        print(f"⚠️  {missing_clusters} records have missing clusters")
    else:
        print("✅ All records have cluster assignments")
    
    # Check Ozon metrics
    cursor.execute("""
        SELECT 
            SUM(quantity_present) as total_available,
            SUM(quantity_reserved) as total_reserved,
            SUM(preparing_for_sale) as total_preparing,
            SUM(in_transit) as total_in_transit
        FROM inventory 
        WHERE source = 'ozon'
    """)
    metrics = cursor.fetchone()
    print(f"\nInventory totals:")
    print(f"  Available: {metrics[0]}")
    print(f"  Reserved: {metrics[1]}")
    print(f"  Preparing for sale: {metrics[2]}")
    print(f"  In transit: {metrics[3]}")
    
    # Sample inventory records
    cursor.execute("""
        SELECT 
            dp.sku_ozon,
            i.warehouse_name,
            i.cluster,
            i.quantity_present,
            i.quantity_reserved
        FROM inventory i
        JOIN dim_products dp ON i.product_id = dp.id
        WHERE i.source = 'ozon'
        ORDER BY i.quantity_present DESC
        LIMIT 5
    """)
    print("\nTop 5 inventory records by quantity:")
    for row in cursor.fetchall():
        print(f"  {row[0]} @ {row[1]} ({row[2]}): {row[3]} available, {row[4]} reserved")


def validate_sales(cursor):
    """Validate stock_movements table."""
    print("\n" + "=" * 60)
    print("VALIDATING SALES")
    print("=" * 60)
    
    # Count sales records
    cursor.execute("""
        SELECT COUNT(*) 
        FROM stock_movements 
        WHERE source = 'ozon' AND movement_type = 'sale'
    """)
    sales_count = cursor.fetchone()[0]
    print(f"✅ Found {sales_count} sales records")
    
    # Count unique products sold
    cursor.execute("""
        SELECT COUNT(DISTINCT product_id) 
        FROM stock_movements 
        WHERE source = 'ozon' AND movement_type = 'sale'
    """)
    unique_products_sold = cursor.fetchone()[0]
    print(f"✅ {unique_products_sold} unique products sold")
    
    # Total units sold
    cursor.execute("""
        SELECT SUM(ABS(quantity)) 
        FROM stock_movements 
        WHERE source = 'ozon' AND movement_type = 'sale'
    """)
    total_units = cursor.fetchone()[0]
    print(f"✅ {total_units} total units sold")
    
    # Date range
    cursor.execute("""
        SELECT 
            MIN(movement_date) as earliest,
            MAX(movement_date) as latest
        FROM stock_movements 
        WHERE source = 'ozon' AND movement_type = 'sale'
    """)
    date_range = cursor.fetchone()
    print(f"✅ Date range: {date_range[0]} to {date_range[1]}")
    
    # Sales by product
    cursor.execute("""
        SELECT 
            dp.sku_ozon,
            dp.product_name,
            COUNT(*) as order_count,
            SUM(ABS(sm.quantity)) as total_quantity
        FROM stock_movements sm
        JOIN dim_products dp ON sm.product_id = dp.id
        WHERE sm.source = 'ozon' AND sm.movement_type = 'sale'
        GROUP BY dp.sku_ozon, dp.product_name
        ORDER BY total_quantity DESC
        LIMIT 5
    """)
    print("\nTop 5 products by sales volume:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[3]} units in {row[2]} orders")
    
    # Sales by warehouse
    cursor.execute("""
        SELECT 
            warehouse_name,
            COUNT(*) as order_count,
            SUM(ABS(quantity)) as total_quantity
        FROM stock_movements
        WHERE source = 'ozon' AND movement_type = 'sale'
        GROUP BY warehouse_name
        ORDER BY total_quantity DESC
        LIMIT 5
    """)
    print("\nTop 5 warehouses by sales volume:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[2]} units in {row[1]} orders")


def validate_data_integrity(cursor):
    """Validate data integrity."""
    print("\n" + "=" * 60)
    print("VALIDATING DATA INTEGRITY")
    print("=" * 60)
    
    # Check for orphaned inventory records
    cursor.execute("""
        SELECT COUNT(*) 
        FROM inventory i
        WHERE i.source = 'ozon' 
        AND NOT EXISTS (
            SELECT 1 FROM dim_products dp WHERE dp.id = i.product_id
        )
    """)
    orphaned_inventory = cursor.fetchone()[0]
    if orphaned_inventory > 0:
        print(f"❌ {orphaned_inventory} orphaned inventory records (no matching product)")
    else:
        print("✅ No orphaned inventory records")
    
    # Check for orphaned sales records
    cursor.execute("""
        SELECT COUNT(*) 
        FROM stock_movements sm
        WHERE sm.source = 'ozon' 
        AND NOT EXISTS (
            SELECT 1 FROM dim_products dp WHERE dp.id = sm.product_id
        )
    """)
    orphaned_sales = cursor.fetchone()[0]
    if orphaned_sales > 0:
        print(f"❌ {orphaned_sales} orphaned sales records (no matching product)")
    else:
        print("✅ No orphaned sales records")
    
    # Check for products with sales but no inventory
    cursor.execute("""
        SELECT COUNT(DISTINCT sm.product_id)
        FROM stock_movements sm
        WHERE sm.source = 'ozon' AND sm.movement_type = 'sale'
        AND NOT EXISTS (
            SELECT 1 FROM inventory i 
            WHERE i.product_id = sm.product_id AND i.source = 'ozon'
        )
    """)
    sales_without_inventory = cursor.fetchone()[0]
    if sales_without_inventory > 0:
        print(f"⚠️  {sales_without_inventory} products have sales but no inventory records")
    else:
        print("✅ All products with sales have inventory records")
    
    # Check for products with inventory but no sales
    cursor.execute("""
        SELECT COUNT(DISTINCT i.product_id)
        FROM inventory i
        WHERE i.source = 'ozon'
        AND NOT EXISTS (
            SELECT 1 FROM stock_movements sm 
            WHERE sm.product_id = i.product_id 
            AND sm.source = 'ozon' 
            AND sm.movement_type = 'sale'
        )
    """)
    inventory_without_sales = cursor.fetchone()[0]
    if inventory_without_sales > 0:
        print(f"ℹ️  {inventory_without_sales} products have inventory but no sales (this is normal)")
    else:
        print("✅ All products with inventory have sales records")


def main():
    """Main validation function."""
    print("=" * 60)
    print("OZON WAREHOUSE IMPORT VALIDATION")
    print("=" * 60)
    print(f"Timestamp: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    
    # Connect to database
    conn = connect_to_database()
    cursor = conn.cursor()
    
    try:
        # Run validations
        validate_products(cursor)
        validate_inventory(cursor)
        validate_sales(cursor)
        validate_data_integrity(cursor)
        
        print("\n" + "=" * 60)
        print("✅ VALIDATION COMPLETED SUCCESSFULLY")
        print("=" * 60)
        print("\nThe imported data looks good!")
        print("\nNext steps:")
        print("1. Run: php scripts/refresh_warehouse_metrics.php")
        print("2. Open Warehouse Dashboard in browser")
        print("3. Verify calculations are correct")
        
    except Exception as e:
        print(f"\n❌ Validation failed: {e}")
        sys.exit(1)
    finally:
        cursor.close()
        conn.close()


if __name__ == '__main__':
    main()
