#!/usr/bin/env python3
"""
PostgreSQL ETL Base Class
Updated ETL processes for PostgreSQL migration
"""

import os
import sys
import logging
import psycopg2
import psycopg2.extras
from datetime import datetime
from typing import Dict, List, Any, Optional
import json

class PostgreSQLETLBase:
    """Base class for PostgreSQL ETL processes"""
    
    def __init__(self, config: Dict[str, Any] = None):
        self.config = config or self.load_config()
        self.connection = None
        self.logger = self.setup_logging()
        
    def load_config(self) -> Dict[str, Any]:
        """Load configuration from environment variables"""
        return {
            'host': os.getenv('PG_HOST', 'localhost'),
            'port': int(os.getenv('PG_PORT', '5432')),
            'database': os.getenv('PG_NAME', 'mi_core_db'),
            'user': os.getenv('PG_USER', 'mi_core_user'),
            'password': os.getenv('PG_PASSWORD', ''),
            'connect_timeout': int(os.getenv('DB_CONNECT_TIMEOUT', '30')),
            'command_timeout': int(os.getenv('DB_COMMAND_TIMEOUT', '300'))
        }
    
    def setup_logging(self) -> logging.Logger:
        """Setup logging configuration"""
        logger = logging.getLogger(self.__class__.__name__)
        logger.setLevel(logging.INFO)
        
        if not logger.handlers:
            # Create logs directory if it doesn't exist
            log_dir = os.path.join(os.path.dirname(__file__), '..', '..', 'logs')
            os.makedirs(log_dir, exist_ok=True)
            
            # File handler
            log_file = os.path.join(log_dir, f'etl_{datetime.now().strftime("%Y%m%d")}.log')
            file_handler = logging.FileHandler(log_file)
            file_handler.setLevel(logging.INFO)
            
            # Console handler
            console_handler = logging.StreamHandler()
            console_handler.setLevel(logging.INFO)
            
            # Formatter
            formatter = logging.Formatter(
                '%(asctime)s - %(name)s - %(levelname)s - %(message)s'
            )
            file_handler.setFormatter(formatter)
            console_handler.setFormatter(formatter)
            
            logger.addHandler(file_handler)
            logger.addHandler(console_handler)
        
        return logger
    
    def connect(self) -> bool:
        """Establish PostgreSQL connection"""
        try:
            self.connection = psycopg2.connect(
                host=self.config['host'],
                port=self.config['port'],
                database=self.config['database'],
                user=self.config['user'],
                password=self.config['password'],
                connect_timeout=self.config['connect_timeout']
            )
            
            # Set autocommit to False for transaction control
            self.connection.autocommit = False
            
            # Set timezone
            with self.connection.cursor() as cursor:
                cursor.execute("SET timezone = 'Europe/Moscow'")
                self.connection.commit()
            
            self.logger.info("PostgreSQL connection established successfully")
            return True
            
        except psycopg2.Error as e:
            self.logger.error(f"PostgreSQL connection failed: {e}")
            return False
    
    def disconnect(self):
        """Close PostgreSQL connection"""
        if self.connection:
            self.connection.close()
            self.connection = None
            self.logger.info("PostgreSQL connection closed")
    
    def execute_query(self, query: str, params: tuple = None, fetch: bool = False) -> Any:
        """Execute SQL query with error handling"""
        if not self.connection:
            raise Exception("No database connection available")
        
        try:
            with self.connection.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cursor:
                cursor.execute(query, params)
                
                if fetch:
                    if query.strip().upper().startswith('SELECT'):
                        return cursor.fetchall()
                    else:
                        return cursor.rowcount
                else:
                    self.connection.commit()
                    return cursor.rowcount
                    
        except psycopg2.Error as e:
            self.connection.rollback()
            self.logger.error(f"Query execution failed: {e}")
            self.logger.error(f"Query: {query}")
            self.logger.error(f"Params: {params}")
            raise
    
    def execute_many(self, query: str, params_list: List[tuple]) -> int:
        """Execute query with multiple parameter sets"""
        if not self.connection:
            raise Exception("No database connection available")
        
        try:
            with self.connection.cursor() as cursor:
                psycopg2.extras.execute_batch(cursor, query, params_list, page_size=1000)
                self.connection.commit()
                return cursor.rowcount
                
        except psycopg2.Error as e:
            self.connection.rollback()
            self.logger.error(f"Batch execution failed: {e}")
            raise
    
    def begin_transaction(self):
        """Begin database transaction"""
        if self.connection:
            # PostgreSQL is already in transaction mode by default
            pass
    
    def commit_transaction(self):
        """Commit database transaction"""
        if self.connection:
            self.connection.commit()
    
    def rollback_transaction(self):
        """Rollback database transaction"""
        if self.connection:
            self.connection.rollback()
    
    def table_exists(self, table_name: str) -> bool:
        """Check if table exists"""
        query = """
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' AND table_name = %s
            )
        """
        
        result = self.execute_query(query, (table_name,), fetch=True)
        return result[0]['exists'] if result else False
    
    def get_table_count(self, table_name: str) -> int:
        """Get row count for table"""
        if not self.table_exists(table_name):
            return 0
        
        query = f"SELECT COUNT(*) as count FROM {table_name}"
        result = self.execute_query(query, fetch=True)
        return result[0]['count'] if result else 0
    
    def upsert_product(self, product_data: Dict[str, Any]) -> int:
        """Insert or update product in dim_products table"""
        
        # Check if product exists
        check_query = "SELECT id FROM dim_products WHERE sku_ozon = %s"
        existing = self.execute_query(check_query, (product_data['sku_ozon'],), fetch=True)
        
        if existing:
            # Update existing product
            product_id = existing[0]['id']
            update_query = """
                UPDATE dim_products 
                SET product_name = %s, cost_price = %s, margin_percent = %s, 
                    sku_wb = %s, barcode = %s, updated_at = CURRENT_TIMESTAMP
                WHERE id = %s
            """
            
            self.execute_query(update_query, (
                product_data.get('product_name'),
                product_data.get('cost_price'),
                product_data.get('margin_percent'),
                product_data.get('sku_wb'),
                product_data.get('barcode'),
                product_id
            ))
            
            return product_id
        else:
            # Insert new product
            insert_query = """
                INSERT INTO dim_products (sku_ozon, product_name, cost_price, margin_percent, sku_wb, barcode)
                VALUES (%s, %s, %s, %s, %s, %s)
                RETURNING id
            """
            
            result = self.execute_query(insert_query, (
                product_data['sku_ozon'],
                product_data.get('product_name'),
                product_data.get('cost_price'),
                product_data.get('margin_percent'),
                product_data.get('sku_wb'),
                product_data.get('barcode')
            ), fetch=True)
            
            return result[0]['id'] if result else None
    
    def upsert_inventory(self, inventory_data: Dict[str, Any]) -> bool:
        """Insert or update inventory record"""
        
        # Check if inventory record exists
        check_query = """
            SELECT id FROM inventory 
            WHERE product_id = %s AND warehouse_name = %s AND source = %s
        """
        
        existing = self.execute_query(check_query, (
            inventory_data['product_id'],
            inventory_data['warehouse_name'],
            inventory_data['source']
        ), fetch=True)
        
        if existing:
            # Update existing inventory
            update_query = """
                UPDATE inventory 
                SET quantity_present = %s, quantity_reserved = %s, 
                    stock_type = %s, updated_at = CURRENT_TIMESTAMP
                WHERE id = %s
            """
            
            self.execute_query(update_query, (
                inventory_data['quantity_present'],
                inventory_data.get('quantity_reserved', 0),
                inventory_data.get('stock_type', 'FBO'),
                existing[0]['id']
            ))
        else:
            # Insert new inventory record
            insert_query = """
                INSERT INTO inventory (product_id, warehouse_name, stock_type, 
                                     quantity_present, quantity_reserved, source)
                VALUES (%s, %s, %s, %s, %s, %s)
            """
            
            self.execute_query(insert_query, (
                inventory_data['product_id'],
                inventory_data['warehouse_name'],
                inventory_data.get('stock_type', 'FBO'),
                inventory_data['quantity_present'],
                inventory_data.get('quantity_reserved', 0),
                inventory_data['source']
            ))
        
        return True
    
    def insert_stock_movement(self, movement_data: Dict[str, Any]) -> bool:
        """Insert stock movement record"""
        
        insert_query = """
            INSERT INTO stock_movements (movement_id, product_id, movement_date, 
                                       movement_type, quantity, warehouse_name, 
                                       order_id, source)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            ON CONFLICT (movement_id, product_id, source) DO NOTHING
        """
        
        self.execute_query(insert_query, (
            movement_data['movement_id'],
            movement_data['product_id'],
            movement_data['movement_date'],
            movement_data['movement_type'],
            movement_data['quantity'],
            movement_data.get('warehouse_name'),
            movement_data.get('order_id'),
            movement_data['source']
        ))
        
        return True
    
    def log_job_run(self, job_name: str, status: str, rows_in: int = 0, 
                   rows_out: int = 0, error_message: str = None) -> int:
        """Log ETL job run"""
        
        insert_query = """
            INSERT INTO job_runs (job_name, started_at, finished_at, status, 
                                rows_in, rows_out, error_message)
            VALUES (%s, %s, CURRENT_TIMESTAMP, %s, %s, %s, %s)
            RETURNING id
        """
        
        result = self.execute_query(insert_query, (
            job_name,
            datetime.now(),
            status,
            rows_in,
            rows_out,
            error_message
        ), fetch=True)
        
        return result[0]['id'] if result else None
    
    def get_system_setting(self, key: str, default_value: str = None) -> str:
        """Get system setting value"""
        
        query = "SELECT setting_value FROM system_settings WHERE setting_key = %s"
        result = self.execute_query(query, (key,), fetch=True)
        
        if result:
            return result[0]['setting_value']
        else:
            return default_value
    
    def set_system_setting(self, key: str, value: str, description: str = None) -> bool:
        """Set system setting value"""
        
        upsert_query = """
            INSERT INTO system_settings (setting_key, setting_value, description)
            VALUES (%s, %s, %s)
            ON CONFLICT (setting_key) 
            DO UPDATE SET setting_value = EXCLUDED.setting_value,
                         description = COALESCE(EXCLUDED.description, system_settings.description),
                         updated_at = CURRENT_TIMESTAMP
        """
        
        self.execute_query(upsert_query, (key, value, description))
        return True
    
    def cleanup_old_data(self, table_name: str, date_column: str, days_to_keep: int) -> int:
        """Clean up old data from specified table"""
        
        cleanup_query = f"""
            DELETE FROM {table_name} 
            WHERE {date_column} < CURRENT_DATE - INTERVAL '{days_to_keep} days'
        """
        
        deleted_count = self.execute_query(cleanup_query)
        self.logger.info(f"Cleaned up {deleted_count} old records from {table_name}")
        
        return deleted_count
    
    def get_database_stats(self) -> Dict[str, Any]:
        """Get database statistics"""
        
        stats = {}
        
        try:
            # Database size
            size_query = "SELECT pg_size_pretty(pg_database_size(current_database())) as size"
            result = self.execute_query(size_query, fetch=True)
            stats['database_size'] = result[0]['size'] if result else 'Unknown'
            
            # Table counts
            tables = ['dim_products', 'inventory', 'stock_movements', 'fact_orders']
            for table in tables:
                stats[f'{table}_count'] = self.get_table_count(table)
            
            # Connection count
            conn_query = "SELECT count(*) as connections FROM pg_stat_activity"
            result = self.execute_query(conn_query, fetch=True)
            stats['active_connections'] = result[0]['connections'] if result else 0
            
        except Exception as e:
            self.logger.error(f"Error getting database stats: {e}")
            stats['error'] = str(e)
        
        return stats
    
    def __enter__(self):
        """Context manager entry"""
        self.connect()
        return self
    
    def __exit__(self, exc_type, exc_val, exc_tb):
        """Context manager exit"""
        if exc_type:
            self.rollback_transaction()
        self.disconnect()
        
        if exc_type:
            self.logger.error(f"ETL process failed: {exc_val}")
        
        return False  # Don't suppress exceptions


# Example usage
if __name__ == "__main__":
    # Test the PostgreSQL ETL base class
    with PostgreSQLETLBase() as etl:
        print("Testing PostgreSQL ETL Base Class...")
        
        # Test connection
        if etl.connection:
            print("✅ Database connection successful")
        else:
            print("❌ Database connection failed")
            sys.exit(1)
        
        # Test table existence
        tables = ['dim_products', 'inventory', 'stock_movements']
        for table in tables:
            exists = etl.table_exists(table)
            count = etl.get_table_count(table) if exists else 0
            print(f"Table {table}: {'✅' if exists else '❌'} exists, {count} rows")
        
        # Test database stats
        stats = etl.get_database_stats()
        print(f"Database stats: {json.dumps(stats, indent=2)}")
        
        print("PostgreSQL ETL Base Class test completed!")