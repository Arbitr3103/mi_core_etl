#!/usr/bin/env python3
"""
Verification script for Ozon Analytics database tables
This script checks if all required tables and indexes exist
Requirements: 4.1, 4.2
"""

import sys
import os

# Add current directory to path to import config
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

try:
    # Try to import local config first, then fallback to main config
    try:
        from config_local import *
    except ImportError:
        from config import *
except ImportError:
    print("ERROR: Could not import database configuration")
    sys.exit(1)

import mysql.connector
from mysql.connector import Error

def get_db_connection():
    """Get database connection using config"""
    try:
        # Try to get config from config_local.py first, then config.py
        try:
            from config_local import DB_CONFIG
            connection = mysql.connector.connect(**DB_CONFIG)
        except ImportError:
            # Fallback to main config.py format
            from config import DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_PORT
            connection = mysql.connector.connect(
                host=DB_HOST,
                port=DB_PORT,
                database=DB_NAME,
                user=DB_USER,
                password=DB_PASSWORD,
                charset='utf8mb4',
                autocommit=True
            )
        return connection
    except Error as e:
        print(f"ERROR: Could not connect to database: {e}")
        return None

def check_table_exists(cursor, table_name):
    """Check if a table exists"""
    try:
        cursor.execute(f"SHOW TABLES LIKE '{table_name}'")
        result = cursor.fetchone()
        return result is not None
    except Error as e:
        print(f"ERROR checking table {table_name}: {e}")
        return False

def check_table_structure(cursor, table_name, expected_columns):
    """Check if table has expected columns"""
    try:
        cursor.execute(f"DESCRIBE {table_name}")
        columns = cursor.fetchall()
        column_names = [col[0] for col in columns]
        
        missing_columns = []
        for expected_col in expected_columns:
            if expected_col not in column_names:
                missing_columns.append(expected_col)
        
        return missing_columns
    except Error as e:
        print(f"ERROR checking structure of {table_name}: {e}")
        return ["ERROR"]

def check_indexes(cursor, table_name, expected_indexes):
    """Check if table has expected indexes"""
    try:
        cursor.execute(f"SHOW INDEX FROM {table_name}")
        indexes = cursor.fetchall()
        index_names = set([idx[2] for idx in indexes])  # Key_name is at index 2
        
        missing_indexes = []
        for expected_idx in expected_indexes:
            if expected_idx not in index_names:
                missing_indexes.append(expected_idx)
        
        return missing_indexes
    except Error as e:
        print(f"ERROR checking indexes of {table_name}: {e}")
        return ["ERROR"]

def main():
    """Main verification function"""
    print("Ozon Analytics Tables Verification")
    print("=" * 40)
    
    # Expected table structures
    expected_tables = {
        'ozon_api_settings': {
            'columns': ['id', 'client_id', 'api_key_hash', 'is_active', 'created_at', 'updated_at'],
            'indexes': ['PRIMARY', 'idx_active', 'idx_created']
        },
        'ozon_funnel_data': {
            'columns': ['id', 'date_from', 'date_to', 'product_id', 'campaign_id', 'views', 
                       'cart_additions', 'orders', 'conversion_view_to_cart', 'conversion_cart_to_order', 
                       'conversion_overall', 'cached_at'],
            'indexes': ['PRIMARY', 'idx_date_range', 'idx_product', 'idx_campaign', 'idx_cached_at', 
                       'idx_product_date', 'idx_campaign_date']
        },
        'ozon_demographics': {
            'columns': ['id', 'date_from', 'date_to', 'age_group', 'gender', 'region', 
                       'orders_count', 'revenue', 'cached_at'],
            'indexes': ['PRIMARY', 'idx_date_range', 'idx_demographics', 'idx_age_group', 
                       'idx_gender', 'idx_region', 'idx_cached_at', 'idx_demo_date']
        },
        'ozon_campaigns': {
            'columns': ['id', 'campaign_id', 'campaign_name', 'date_from', 'date_to', 'impressions', 
                       'clicks', 'spend', 'orders', 'revenue', 'ctr', 'cpc', 'roas', 'cached_at'],
            'indexes': ['PRIMARY', 'unique_campaign_period', 'idx_campaign', 'idx_date_range', 
                       'idx_campaign_name', 'idx_cached_at', 'idx_performance']
        }
    }
    
    connection = get_db_connection()
    if not connection:
        sys.exit(1)
    
    cursor = connection.cursor()
    all_good = True
    
    try:
        for table_name, expected in expected_tables.items():
            print(f"\nChecking table: {table_name}")
            
            # Check if table exists
            if not check_table_exists(cursor, table_name):
                print(f"  ❌ Table {table_name} does not exist")
                all_good = False
                continue
            else:
                print(f"  ✅ Table {table_name} exists")
            
            # Check table structure
            missing_columns = check_table_structure(cursor, table_name, expected['columns'])
            if missing_columns:
                if "ERROR" in missing_columns:
                    print(f"  ❌ Error checking columns for {table_name}")
                    all_good = False
                else:
                    print(f"  ❌ Missing columns in {table_name}: {', '.join(missing_columns)}")
                    all_good = False
            else:
                print(f"  ✅ All expected columns present in {table_name}")
            
            # Check indexes
            missing_indexes = check_indexes(cursor, table_name, expected['indexes'])
            if missing_indexes:
                if "ERROR" in missing_indexes:
                    print(f"  ❌ Error checking indexes for {table_name}")
                    all_good = False
                else:
                    print(f"  ❌ Missing indexes in {table_name}: {', '.join(missing_indexes)}")
                    all_good = False
            else:
                print(f"  ✅ All expected indexes present in {table_name}")
    
    except Error as e:
        print(f"ERROR during verification: {e}")
        all_good = False
    
    finally:
        cursor.close()
        connection.close()
    
    print("\n" + "=" * 40)
    if all_good:
        print("✅ All Ozon analytics tables are properly configured!")
        sys.exit(0)
    else:
        print("❌ Some issues found with Ozon analytics tables")
        sys.exit(1)

if __name__ == "__main__":
    main()