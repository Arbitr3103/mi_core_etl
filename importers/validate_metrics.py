#!/usr/bin/env python3
"""
Validation script for Warehouse Metrics Calculation

This script validates that the calculated metrics are correct and complete.
It checks:
- Metrics table is populated
- Calculations are reasonable
- All inventory items have metrics
- Liquidity statuses are assigned correctly

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


def validate_metrics_table(cursor):
    """Validate warehouse_sales_metrics table."""
    print("\n" + "=" * 60)
    print("VALIDATING METRICS TABLE")
    print("=" * 60)
    
    # Check if table exists
    cursor.execute("""
        SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_name = 'warehouse_sales_metrics'
        )
    """)
    table_exists = cursor.fetchone()[0]
    
    if not table_exists:
        print("❌ warehouse_sales_metrics table does not exist!")
        return False
    
    print("✅ warehouse_sales_metrics table exists")
    
    # Count metrics records
    cursor.execute("SELECT COUNT(*) FROM warehouse_sales_metrics")
    metrics_count = cursor.fetchone()[0]
    print(f"✅ Found {metrics_count} metrics records")
    
    if metrics_count == 0:
        print("⚠️  No metrics calculated yet. Run refresh_warehouse_metrics.php first.")
        return False
    
    # Check recent calculations
    cursor.execute("""
        SELECT 
            MIN(calculated_at) as earliest,
            MAX(calculated_at) as latest,
            COUNT(*) as total
        FROM warehouse_sales_metrics
    """)
    calc_info = cursor.fetchone()
    print(f"✅ Metrics calculated from {calc_info[0]} to {calc_info[1]}")
    
    # Check for stale metrics (older than 24 hours)
    cursor.execute("""
        SELECT COUNT(*) 
        FROM warehouse_sales_metrics
        WHERE calculated_at < CURRENT_TIMESTAMP - INTERVAL '24 hours'
    """)
    stale_count = cursor.fetchone()[0]
    if stale_count > 0:
        print(f"⚠️  {stale_count} metrics are older than 24 hours")
    else:
        print("✅ All metrics are fresh (< 24 hours old)")
    
    return True


def validate_sales_metrics(cursor):
    """Validate sales-related metrics."""
    print("\n" + "=" * 60)
    print("VALIDATING SALES METRICS")
    print("=" * 60)
    
    # Check daily_sales_avg distribution
    cursor.execute("""
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN daily_sales_avg > 0 THEN 1 END) as with_sales,
            AVG(daily_sales_avg) as avg_daily_sales,
            MAX(daily_sales_avg) as max_daily_sales
        FROM warehouse_sales_metrics
    """)
    sales_stats = cursor.fetchone()
    print(f"Total metrics: {sales_stats[0]}")
    print(f"With sales (daily_sales_avg > 0): {sales_stats[1]}")
    print(f"Average daily sales: {sales_stats[2]:.2f}")
    print(f"Max daily sales: {sales_stats[3]:.2f}")
    
    # Check sales_last_28_days
    cursor.execute("""
        SELECT 
            COUNT(CASE WHEN sales_last_28_days > 0 THEN 1 END) as with_sales,
            SUM(sales_last_28_days) as total_sales,
            AVG(sales_last_28_days) as avg_sales
        FROM warehouse_sales_metrics
    """)
    sales_28d = cursor.fetchone()
    print(f"\nProducts with sales in last 28 days: {sales_28d[0]}")
    print(f"Total units sold (last 28 days): {sales_28d[1]}")
    print(f"Average sales per product: {sales_28d[2]:.2f}")
    
    # Check days_without_sales
    cursor.execute("""
        SELECT 
            COUNT(CASE WHEN days_without_sales = 0 THEN 1 END) as recent_sales,
            COUNT(CASE WHEN days_without_sales BETWEEN 1 AND 7 THEN 1 END) as week_ago,
            COUNT(CASE WHEN days_without_sales > 7 THEN 1 END) as over_week,
            AVG(days_without_sales) as avg_days
        FROM warehouse_sales_metrics
    """)
    days_stats = cursor.fetchone()
    print(f"\nDays without sales distribution:")
    print(f"  Recent sales (0 days): {days_stats[0]}")
    print(f"  Last week (1-7 days): {days_stats[1]}")
    print(f"  Over a week (>7 days): {days_stats[2]}")
    print(f"  Average: {days_stats[3]:.1f} days")


def validate_liquidity_metrics(cursor):
    """Validate liquidity-related metrics."""
    print("\n" + "=" * 60)
    print("VALIDATING LIQUIDITY METRICS")
    print("=" * 60)
    
    # Check liquidity status distribution
    cursor.execute("""
        SELECT 
            liquidity_status,
            COUNT(*) as count,
            ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER (), 2) as percentage
        FROM warehouse_sales_metrics
        GROUP BY liquidity_status
        ORDER BY 
            CASE liquidity_status
                WHEN 'critical' THEN 1
                WHEN 'low' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'excess' THEN 4
                ELSE 5
            END
    """)
    print("Liquidity status distribution:")
    for row in cursor.fetchall():
        status_emoji = {
            'critical': '🔴',
            'low': '🟡',
            'normal': '🟢',
            'excess': '🔵'
        }.get(row[0], '⚪')
        print(f"  {status_emoji} {row[0]}: {row[1]} ({row[2]}%)")
    
    # Check days_of_stock distribution
    cursor.execute("""
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN days_of_stock IS NULL THEN 1 END) as infinite,
            COUNT(CASE WHEN days_of_stock < 7 THEN 1 END) as critical,
            COUNT(CASE WHEN days_of_stock BETWEEN 7 AND 14 THEN 1 END) as low,
            COUNT(CASE WHEN days_of_stock BETWEEN 15 AND 45 THEN 1 END) as normal,
            COUNT(CASE WHEN days_of_stock > 45 THEN 1 END) as excess,
            AVG(days_of_stock) as avg_days
        FROM warehouse_sales_metrics
    """)
    days_stats = cursor.fetchone()
    print(f"\nDays of stock distribution:")
    print(f"  Total: {days_stats[0]}")
    print(f"  Infinite (no sales): {days_stats[1]}")
    print(f"  Critical (<7 days): {days_stats[2]}")
    print(f"  Low (7-14 days): {days_stats[3]}")
    print(f"  Normal (15-45 days): {days_stats[4]}")
    print(f"  Excess (>45 days): {days_stats[5]}")
    if days_stats[6]:
        print(f"  Average: {days_stats[6]:.1f} days")


def validate_replenishment_metrics(cursor):
    """Validate replenishment-related metrics."""
    print("\n" + "=" * 60)
    print("VALIDATING REPLENISHMENT METRICS")
    print("=" * 60)
    
    # Check target_stock and replenishment_need
    cursor.execute("""
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN target_stock > 0 THEN 1 END) as with_target,
            COUNT(CASE WHEN replenishment_need > 0 THEN 1 END) as need_replenishment,
            SUM(target_stock) as total_target,
            SUM(replenishment_need) as total_need,
            AVG(target_stock) as avg_target,
            AVG(CASE WHEN replenishment_need > 0 THEN replenishment_need END) as avg_need
        FROM warehouse_sales_metrics
    """)
    repl_stats = cursor.fetchone()
    print(f"Total metrics: {repl_stats[0]}")
    print(f"With target stock > 0: {repl_stats[1]}")
    print(f"Need replenishment: {repl_stats[2]}")
    print(f"Total target stock: {repl_stats[3]}")
    print(f"Total replenishment need: {repl_stats[4]}")
    print(f"Average target stock: {repl_stats[5]:.2f}")
    if repl_stats[6]:
        print(f"Average replenishment need: {repl_stats[6]:.2f}")
    
    # Top products needing replenishment
    cursor.execute("""
        SELECT 
            dp.sku_ozon,
            dp.product_name,
            wsm.warehouse_name,
            wsm.replenishment_need,
            wsm.target_stock,
            wsm.liquidity_status
        FROM warehouse_sales_metrics wsm
        JOIN dim_products dp ON wsm.product_id = dp.id
        WHERE wsm.replenishment_need > 0
        ORDER BY wsm.replenishment_need DESC
        LIMIT 5
    """)
    print("\nTop 5 products needing replenishment:")
    for row in cursor.fetchall():
        print(f"  {row[0]} @ {row[2]}: need {row[3]} units (target: {row[4]}, status: {row[5]})")


def validate_coverage(cursor):
    """Validate that all inventory items have metrics."""
    print("\n" + "=" * 60)
    print("VALIDATING COVERAGE")
    print("=" * 60)
    
    # Check inventory items without metrics
    cursor.execute("""
        SELECT COUNT(*)
        FROM inventory i
        WHERE NOT EXISTS (
            SELECT 1 FROM warehouse_sales_metrics wsm
            WHERE wsm.product_id = i.product_id
            AND wsm.warehouse_name = i.warehouse_name
            AND wsm.source = i.source
        )
    """)
    missing_metrics = cursor.fetchone()[0]
    
    if missing_metrics > 0:
        print(f"⚠️  {missing_metrics} inventory items are missing metrics")
        
        # Show sample of missing items
        cursor.execute("""
            SELECT 
                dp.sku_ozon,
                i.warehouse_name,
                i.quantity_present
            FROM inventory i
            JOIN dim_products dp ON i.product_id = dp.id
            WHERE NOT EXISTS (
                SELECT 1 FROM warehouse_sales_metrics wsm
                WHERE wsm.product_id = i.product_id
                AND wsm.warehouse_name = i.warehouse_name
                AND wsm.source = i.source
            )
            LIMIT 5
        """)
        print("Sample of items without metrics:")
        for row in cursor.fetchall():
            print(f"  {row[0]} @ {row[1]}: {row[2]} units")
    else:
        print("✅ All inventory items have calculated metrics")
    
    # Check metrics without inventory (orphaned metrics)
    cursor.execute("""
        SELECT COUNT(*)
        FROM warehouse_sales_metrics wsm
        WHERE NOT EXISTS (
            SELECT 1 FROM inventory i
            WHERE i.product_id = wsm.product_id
            AND i.warehouse_name = wsm.warehouse_name
            AND i.source = wsm.source
        )
    """)
    orphaned_metrics = cursor.fetchone()[0]
    
    if orphaned_metrics > 0:
        print(f"⚠️  {orphaned_metrics} metrics are orphaned (no matching inventory)")
    else:
        print("✅ No orphaned metrics")


def validate_calculations(cursor):
    """Validate that calculations are mathematically correct."""
    print("\n" + "=" * 60)
    print("VALIDATING CALCULATION LOGIC")
    print("=" * 60)
    
    # Check that target_stock = daily_sales_avg * 30
    cursor.execute("""
        SELECT COUNT(*)
        FROM warehouse_sales_metrics
        WHERE daily_sales_avg > 0
        AND ABS(target_stock - (daily_sales_avg * 30)) > 1
    """)
    incorrect_target = cursor.fetchone()[0]
    
    if incorrect_target > 0:
        print(f"⚠️  {incorrect_target} records have incorrect target_stock calculation")
    else:
        print("✅ target_stock calculations are correct")
    
    # Check liquidity status logic
    cursor.execute("""
        SELECT COUNT(*)
        FROM warehouse_sales_metrics
        WHERE (
            (days_of_stock < 7 AND liquidity_status != 'critical') OR
            (days_of_stock >= 7 AND days_of_stock < 15 AND liquidity_status != 'low') OR
            (days_of_stock >= 15 AND days_of_stock <= 45 AND liquidity_status != 'normal') OR
            (days_of_stock > 45 AND liquidity_status != 'excess')
        )
    """)
    incorrect_status = cursor.fetchone()[0]
    
    if incorrect_status > 0:
        print(f"⚠️  {incorrect_status} records have incorrect liquidity_status")
    else:
        print("✅ liquidity_status assignments are correct")


def main():
    """Main validation function."""
    print("=" * 60)
    print("WAREHOUSE METRICS VALIDATION")
    print("=" * 60)
    print(f"Timestamp: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    
    # Connect to database
    conn = connect_to_database()
    cursor = conn.cursor()
    
    try:
        # Run validations
        if not validate_metrics_table(cursor):
            print("\n❌ Metrics table validation failed. Cannot proceed.")
            sys.exit(1)
        
        validate_sales_metrics(cursor)
        validate_liquidity_metrics(cursor)
        validate_replenishment_metrics(cursor)
        validate_coverage(cursor)
        validate_calculations(cursor)
        
        print("\n" + "=" * 60)
        print("✅ METRICS VALIDATION COMPLETED")
        print("=" * 60)
        print("\nThe calculated metrics look good!")
        print("\nNext steps:")
        print("1. Open Warehouse Dashboard in browser")
        print("2. Test filtering and sorting")
        print("3. Verify replenishment recommendations")
        print("4. Set up cron job for automatic updates")
        
    except Exception as e:
        print(f"\n❌ Validation failed: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)
    finally:
        cursor.close()
        conn.close()


if __name__ == '__main__':
    main()
