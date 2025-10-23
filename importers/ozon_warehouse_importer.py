#!/usr/bin/env python3
"""
Ozon Warehouse Data Importer

This script imports Ozon warehouse data from CSV reports into PostgreSQL database.
It handles two types of reports:
1. Product-Warehouse report (Товар-склад) - inventory levels by warehouse
2. Sales report (Отчет о продажах) - sales transactions

Requirements: 1, 2, 4

Usage:
    python ozon_warehouse_importer.py --inventory <path_to_inventory_csv>
    python ozon_warehouse_importer.py --sales <path_to_sales_csv> --start-date YYYY-MM-DD --end-date YYYY-MM-DD
    python ozon_warehouse_importer.py --both <inventory_csv> <sales_csv> --start-date YYYY-MM-DD --end-date YYYY-MM-DD

Author: Warehouse Dashboard System
Date: October 22, 2025
"""

import os
import sys
import csv
import logging
import argparse
import psycopg2
from psycopg2.extras import execute_values
from datetime import datetime, timedelta
from typing import List, Dict, Any, Optional
from dotenv import load_dotenv

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Load environment variables
load_dotenv()


class OzonWarehouseImporter:
    """Importer for Ozon warehouse data from CSV reports."""
    
    # Mapping of warehouse names to clusters
    WAREHOUSE_CLUSTERS = {
        'АДЫГЕЙСК_РФЦ': 'Юг',
        'Ростов-на-Дону_РФЦ': 'Юг',
        'Краснодар_РФЦ': 'Юг',
        'Екатеринбург_РФЦ': 'Урал',
        'Челябинск_РФЦ': 'Урал',
        'Тюмень_РФЦ': 'Урал',
        'Новосибирск_РФЦ': 'Сибирь',
        'Красноярск_РФЦ': 'Сибирь',
        'Москва_РФЦ': 'Центр',
        'Подольск_РФЦ': 'Центр',
        'Санкт-Петербург_РФЦ': 'Северо-Запад',
        'Казань_РФЦ': 'Поволжье',
        'Самара_РФЦ': 'Поволжье',
    }
    
    def __init__(self):
        """Initialize the importer with database connection."""
        self.conn = None
        self.cursor = None
        self._connect_to_database()
    
    def _connect_to_database(self):
        """Establish connection to PostgreSQL database."""
        try:
            self.conn = psycopg2.connect(
                host=os.getenv('DB_HOST', 'localhost'),
                port=os.getenv('DB_PORT', '5432'),
                database=os.getenv('DB_NAME', 'mi_core_db'),
                user=os.getenv('DB_USER'),
                password=os.getenv('DB_PASSWORD')
            )
            self.cursor = self.conn.cursor()
            logger.info("✅ Connected to PostgreSQL database")
        except Exception as e:
            logger.error(f"❌ Failed to connect to database: {e}")
            raise
    
    def close(self):
        """Close database connection."""
        if self.cursor:
            self.cursor.close()
        if self.conn:
            self.conn.close()
        logger.info("Database connection closed")
    
    def _get_cluster_for_warehouse(self, warehouse_name: str) -> str:
        """
        Get cluster name for a warehouse.
        
        Args:
            warehouse_name: Name of the warehouse
            
        Returns:
            Cluster name or 'Другие' if not found
        """
        # Try exact match first
        if warehouse_name in self.WAREHOUSE_CLUSTERS:
            return self.WAREHOUSE_CLUSTERS[warehouse_name]
        
        # Try partial match
        for wh_key, cluster in self.WAREHOUSE_CLUSTERS.items():
            if wh_key in warehouse_name or warehouse_name in wh_key:
                return cluster
        
        return 'Другие'
    
    def _get_or_create_product(self, sku_ozon: str, product_name: str = None) -> Optional[int]:
        """
        Get product_id by SKU or create new product if not exists.
        
        Args:
            sku_ozon: Ozon SKU
            product_name: Product name (optional)
            
        Returns:
            product_id or None if failed
        """
        try:
            # Try to find existing product
            self.cursor.execute(
                "SELECT id FROM dim_products WHERE sku_ozon = %s",
                (sku_ozon,)
            )
            result = self.cursor.fetchone()
            
            if result:
                return result[0]
            
            # Create new product if not found
            if product_name:
                self.cursor.execute(
                    """
                    INSERT INTO dim_products (sku_ozon, product_name, cost_price)
                    VALUES (%s, %s, NULL)
                    RETURNING id
                    """,
                    (sku_ozon, product_name)
                )
                self.conn.commit()
                product_id = self.cursor.fetchone()[0]
                logger.info(f"Created new product: {sku_ozon} - {product_name}")
                return product_id
            else:
                logger.warning(f"Product not found and no name provided: {sku_ozon}")
                return None
                
        except Exception as e:
            logger.error(f"Error getting/creating product {sku_ozon}: {e}")
            self.conn.rollback()
            return None
    
    def import_inventory_report(self, csv_file_path: str) -> int:
        """
        Import Ozon Product-Warehouse report (Товар-склад).
        
        Expected CSV columns (Russian):
        - Артикул (SKU)
        - Название товара
        - Склад
        - Доступно к продаже
        - Зарезервировано
        - Готовим к продаже
        - В заявках на поставку
        - В поставках в пути
        - Проходят проверку
        - Возвращаются от покупателей
        - Истекает срок годности
        - Брак, доступный к вывозу
        
        Args:
            csv_file_path: Path to CSV file
            
        Returns:
            Number of records imported
        """
        logger.info(f"📥 Importing inventory from: {csv_file_path}")
        
        if not os.path.exists(csv_file_path):
            logger.error(f"File not found: {csv_file_path}")
            return 0
        
        imported_count = 0
        skipped_count = 0
        
        try:
            with open(csv_file_path, 'r', encoding='utf-8-sig') as f:
                # Try to detect delimiter
                sample = f.read(1024)
                f.seek(0)
                delimiter = ';' if ';' in sample else ','
                
                reader = csv.DictReader(f, delimiter=delimiter)
                
                batch = []
                batch_size = 100
                
                for row in reader:
                    # Extract data from CSV
                    sku_ozon = row.get('Артикул', '').strip()
                    product_name = row.get('Название товара', '').strip()
                    warehouse_name = row.get('Склад', '').strip()
                    
                    if not sku_ozon or not warehouse_name:
                        skipped_count += 1
                        continue
                    
                    # Get or create product
                    product_id = self._get_or_create_product(sku_ozon, product_name)
                    if not product_id:
                        skipped_count += 1
                        continue
                    
                    # Get cluster for warehouse
                    cluster = self._get_cluster_for_warehouse(warehouse_name)
                    
                    # Parse numeric values
                    def parse_int(value):
                        try:
                            return int(value.replace(',', '').replace(' ', '')) if value else 0
                        except:
                            return 0
                    
                    inventory_data = {
                        'product_id': product_id,
                        'warehouse_name': warehouse_name,
                        'cluster': cluster,
                        'quantity_present': parse_int(row.get('Доступно к продаже', '0')),
                        'quantity_reserved': parse_int(row.get('Зарезервировано', '0')),
                        'preparing_for_sale': parse_int(row.get('Готовим к продаже', '0')),
                        'in_supply_requests': parse_int(row.get('В заявках на поставку', '0')),
                        'in_transit': parse_int(row.get('В поставках в пути', '0')),
                        'in_inspection': parse_int(row.get('Проходят проверку', '0')),
                        'returning_from_customers': parse_int(row.get('Возвращаются от покупателей', '0')),
                        'expiring_soon': parse_int(row.get('Истекает срок годности', '0')),
                        'defective': parse_int(row.get('Брак, доступный к вывозу', '0')),
                        'excess_from_supply': parse_int(row.get('Излишки от поставки', '0')),
                        'awaiting_upd': parse_int(row.get('Ожидают приёмки', '0')),
                        'preparing_for_removal': parse_int(row.get('Готовятся к вывозу', '0')),
                        'source': 'ozon',
                        'stock_type': 'FBO',  # Default to FBO for Ozon warehouses
                        'updated_at': datetime.now()
                    }
                    
                    batch.append(inventory_data)
                    
                    # Insert batch when it reaches batch_size
                    if len(batch) >= batch_size:
                        self._insert_inventory_batch(batch)
                        imported_count += len(batch)
                        batch = []
                
                # Insert remaining records
                if batch:
                    self._insert_inventory_batch(batch)
                    imported_count += len(batch)
            
            logger.info(f"✅ Imported {imported_count} inventory records")
            logger.info(f"⚠️  Skipped {skipped_count} records")
            
            return imported_count
            
        except Exception as e:
            logger.error(f"❌ Error importing inventory: {e}")
            self.conn.rollback()
            raise
    
    def _insert_inventory_batch(self, batch: List[Dict[str, Any]]):
        """Insert a batch of inventory records using UPSERT."""
        try:
            # Prepare data for execute_values
            values = [
                (
                    item['product_id'],
                    item['warehouse_name'],
                    item['cluster'],
                    item['quantity_present'],
                    item['quantity_reserved'],
                    item['preparing_for_sale'],
                    item['in_supply_requests'],
                    item['in_transit'],
                    item['in_inspection'],
                    item['returning_from_customers'],
                    item['expiring_soon'],
                    item['defective'],
                    item['excess_from_supply'],
                    item['awaiting_upd'],
                    item['preparing_for_removal'],
                    item['source'],
                    item['stock_type'],
                    item['updated_at']
                )
                for item in batch
            ]
            
            query = """
                INSERT INTO inventory (
                    product_id, warehouse_name, cluster,
                    quantity_present, quantity_reserved,
                    preparing_for_sale, in_supply_requests, in_transit,
                    in_inspection, returning_from_customers, expiring_soon,
                    defective, excess_from_supply, awaiting_upd, preparing_for_removal,
                    source, stock_type, updated_at
                ) VALUES %s
                ON CONFLICT (product_id, warehouse_name, source)
                DO UPDATE SET
                    cluster = EXCLUDED.cluster,
                    quantity_present = EXCLUDED.quantity_present,
                    quantity_reserved = EXCLUDED.quantity_reserved,
                    preparing_for_sale = EXCLUDED.preparing_for_sale,
                    in_supply_requests = EXCLUDED.in_supply_requests,
                    in_transit = EXCLUDED.in_transit,
                    in_inspection = EXCLUDED.in_inspection,
                    returning_from_customers = EXCLUDED.returning_from_customers,
                    expiring_soon = EXCLUDED.expiring_soon,
                    defective = EXCLUDED.defective,
                    excess_from_supply = EXCLUDED.excess_from_supply,
                    awaiting_upd = EXCLUDED.awaiting_upd,
                    preparing_for_removal = EXCLUDED.preparing_for_removal,
                    updated_at = EXCLUDED.updated_at
            """
            
            execute_values(self.cursor, query, values)
            self.conn.commit()
            
        except Exception as e:
            logger.error(f"Error inserting inventory batch: {e}")
            self.conn.rollback()
            raise
    
    def import_sales_report(self, csv_file_path: str, start_date: str, end_date: str) -> int:
        """
        Import Ozon Sales report (Отчет о продажах).
        
        Expected CSV columns (Russian):
        - Номер заказа
        - Принят в обработку (date)
        - Артикул
        - Название товара
        - Количество
        - Ваша цена
        - Склад отгрузки
        
        Args:
            csv_file_path: Path to CSV file
            start_date: Start date for the report period (YYYY-MM-DD)
            end_date: End date for the report period (YYYY-MM-DD)
            
        Returns:
            Number of records imported
        """
        logger.info(f"📥 Importing sales from: {csv_file_path}")
        logger.info(f"Period: {start_date} to {end_date}")
        
        if not os.path.exists(csv_file_path):
            logger.error(f"File not found: {csv_file_path}")
            return 0
        
        imported_count = 0
        skipped_count = 0
        
        try:
            with open(csv_file_path, 'r', encoding='utf-8-sig') as f:
                # Try to detect delimiter
                sample = f.read(1024)
                f.seek(0)
                delimiter = ';' if ';' in sample else ','
                
                reader = csv.DictReader(f, delimiter=delimiter)
                
                batch = []
                batch_size = 100
                
                for row in reader:
                    # Extract data from CSV
                    order_id = row.get('Номер заказа', '').strip()
                    sku_ozon = row.get('Артикул', '').strip()
                    product_name = row.get('Название товара', '').strip()
                    warehouse_name = row.get('Склад отгрузки', '').strip()
                    order_date_str = row.get('Принят в обработку', '').strip()
                    
                    if not order_id or not sku_ozon:
                        skipped_count += 1
                        continue
                    
                    # Get or create product
                    product_id = self._get_or_create_product(sku_ozon, product_name)
                    if not product_id:
                        skipped_count += 1
                        continue
                    
                    # Parse date (format: "2025-09-02 00:00:39" or "2025-09-02")
                    try:
                        order_date = datetime.strptime(order_date_str[:10], '%Y-%m-%d').date()
                    except:
                        logger.warning(f"Invalid date format: {order_date_str}")
                        skipped_count += 1
                        continue
                    
                    # Parse numeric values
                    def parse_int(value):
                        try:
                            return int(value.replace(',', '').replace(' ', '')) if value else 0
                        except:
                            return 0
                    
                    def parse_float(value):
                        try:
                            return float(value.replace(',', '.').replace(' ', '')) if value else 0.0
                        except:
                            return 0.0
                    
                    quantity = parse_int(row.get('Количество', '0'))
                    price = parse_float(row.get('Ваша цена', '0'))
                    
                    if quantity <= 0:
                        skipped_count += 1
                        continue
                    
                    # Create movement record (negative quantity for sales)
                    movement_data = {
                        'movement_id': f"ozon_sale_{order_id}_{sku_ozon}",
                        'product_id': product_id,
                        'movement_date': order_date,
                        'movement_type': 'sale',
                        'quantity': -quantity,  # Negative for sales
                        'warehouse_name': warehouse_name if warehouse_name else 'Unknown',
                        'order_id': order_id,
                        'source': 'ozon'
                    }
                    
                    batch.append(movement_data)
                    
                    # Insert batch when it reaches batch_size
                    if len(batch) >= batch_size:
                        self._insert_movements_batch(batch)
                        imported_count += len(batch)
                        batch = []
                
                # Insert remaining records
                if batch:
                    self._insert_movements_batch(batch)
                    imported_count += len(batch)
            
            logger.info(f"✅ Imported {imported_count} sales records")
            logger.info(f"⚠️  Skipped {skipped_count} records")
            
            return imported_count
            
        except Exception as e:
            logger.error(f"❌ Error importing sales: {e}")
            self.conn.rollback()
            raise
    
    def _insert_movements_batch(self, batch: List[Dict[str, Any]]):
        """Insert a batch of stock movement records using UPSERT."""
        try:
            # Prepare data for execute_values
            values = [
                (
                    item['movement_id'],
                    item['product_id'],
                    item['movement_date'],
                    item['movement_type'],
                    item['quantity'],
                    item['warehouse_name'],
                    item['order_id'],
                    item['source']
                )
                for item in batch
            ]
            
            query = """
                INSERT INTO stock_movements (
                    movement_id, product_id, movement_date, movement_type,
                    quantity, warehouse_name, order_id, source
                ) VALUES %s
                ON CONFLICT (movement_id, product_id, source)
                DO UPDATE SET
                    movement_date = EXCLUDED.movement_date,
                    movement_type = EXCLUDED.movement_type,
                    quantity = EXCLUDED.quantity,
                    warehouse_name = EXCLUDED.warehouse_name,
                    order_id = EXCLUDED.order_id
            """
            
            execute_values(self.cursor, query, values)
            self.conn.commit()
            
        except Exception as e:
            logger.error(f"Error inserting movements batch: {e}")
            self.conn.rollback()
            raise
    
    def print_statistics(self):
        """Print import statistics."""
        logger.info("=" * 60)
        logger.info("📊 IMPORT STATISTICS")
        logger.info("=" * 60)
        
        try:
            # Inventory statistics
            self.cursor.execute("""
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(DISTINCT product_id) as unique_products,
                    COUNT(DISTINCT warehouse_name) as unique_warehouses,
                    SUM(quantity_present) as total_available,
                    SUM(quantity_reserved) as total_reserved
                FROM inventory
                WHERE source = 'ozon'
            """)
            inv_stats = self.cursor.fetchone()
            
            logger.info("Inventory (Ozon):")
            logger.info(f"  Total records: {inv_stats[0]}")
            logger.info(f"  Unique products: {inv_stats[1]}")
            logger.info(f"  Unique warehouses: {inv_stats[2]}")
            logger.info(f"  Total available: {inv_stats[3]}")
            logger.info(f"  Total reserved: {inv_stats[4]}")
            
            # Sales statistics
            self.cursor.execute("""
                SELECT 
                    COUNT(*) as total_movements,
                    COUNT(DISTINCT product_id) as unique_products,
                    SUM(ABS(quantity)) as total_quantity,
                    MIN(movement_date) as earliest_date,
                    MAX(movement_date) as latest_date
                FROM stock_movements
                WHERE source = 'ozon' AND movement_type = 'sale'
            """)
            sales_stats = self.cursor.fetchone()
            
            logger.info("\nSales (Ozon):")
            logger.info(f"  Total sales records: {sales_stats[0]}")
            logger.info(f"  Unique products: {sales_stats[1]}")
            logger.info(f"  Total units sold: {sales_stats[2]}")
            logger.info(f"  Date range: {sales_stats[3]} to {sales_stats[4]}")
            
            logger.info("=" * 60)
            
        except Exception as e:
            logger.error(f"Error getting statistics: {e}")


def main():
    """Main function to run the importer."""
    parser = argparse.ArgumentParser(
        description='Import Ozon warehouse data from CSV reports'
    )
    parser.add_argument(
        '--inventory',
        help='Path to inventory CSV file (Товар-склад report)'
    )
    parser.add_argument(
        '--sales',
        help='Path to sales CSV file (Отчет о продажах)'
    )
    parser.add_argument(
        '--start-date',
        help='Start date for sales report (YYYY-MM-DD)'
    )
    parser.add_argument(
        '--end-date',
        help='End date for sales report (YYYY-MM-DD)'
    )
    parser.add_argument(
        '--both',
        nargs=2,
        metavar=('INVENTORY_CSV', 'SALES_CSV'),
        help='Import both inventory and sales reports'
    )
    
    args = parser.parse_args()
    
    # Validate arguments
    if not any([args.inventory, args.sales, args.both]):
        parser.error('At least one of --inventory, --sales, or --both is required')
    
    if args.sales and not (args.start_date and args.end_date):
        parser.error('--sales requires --start-date and --end-date')
    
    if args.both and not (args.start_date and args.end_date):
        parser.error('--both requires --start-date and --end-date')
    
    # Create importer
    importer = OzonWarehouseImporter()
    
    try:
        # Import inventory
        if args.inventory:
            importer.import_inventory_report(args.inventory)
        
        # Import sales
        if args.sales:
            importer.import_sales_report(args.sales, args.start_date, args.end_date)
        
        # Import both
        if args.both:
            inventory_csv, sales_csv = args.both
            importer.import_inventory_report(inventory_csv)
            importer.import_sales_report(sales_csv, args.start_date, args.end_date)
        
        # Print statistics
        importer.print_statistics()
        
        logger.info("✅ Import completed successfully!")
        
    except Exception as e:
        logger.error(f"❌ Import failed: {e}")
        sys.exit(1)
    finally:
        importer.close()


if __name__ == '__main__':
    main()
