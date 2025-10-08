#!/usr/bin/env python3
"""
Утилита для синхронизации данных из мастер таблицы dim_products
в локальную таблицу product_names для улучшения производительности
"""

import mysql.connector
import os
import sys
import time
import logging
from typing import Dict, List, Tuple
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('sync_master_table.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

class MasterTableSyncService:
    """Сервис синхронизации данных из мастер таблицы dim_products"""
    
    def __init__(self):
        self.core_connection = None
        self.master_connection = None
        self._connect_to_databases()
        
        # Статистика
        self.stats = {
            'total_master_records': 0,
            'processed_records': 0,
            'inserted_records': 0,
            'updated_records': 0,
            'failed_records': 0,
            'start_time': time.time()
        }
    
    def _connect_to_databases(self):
        """Подключение к базам данных"""
        try:
            # Подключение к основной базе (mi_core)
            self.core_connection = mysql.connector.connect(
                host=os.getenv('DB_HOST', 'localhost'),
                database='mi_core',
                user=os.getenv('DB_USER', 'v_admin'),
                password=os.getenv('DB_PASSWORD'),
                charset='utf8mb4'
            )
            logger.info("Подключение к mi_core установлено")
            
            # Подключение к базе с мастер таблицей (mi_core_db)
            self.master_connection = mysql.connector.connect(
                host=os.getenv('DB_HOST', 'localhost'),
                database='mi_core_db',
                user=os.getenv('DB_USER', 'v_admin'),
                password=os.getenv('DB_PASSWORD'),
                charset='utf8mb4'
            )
            logger.info("Подключение к mi_core_db установлено")
            
        except Exception as e:
            logger.error(f"Ошибка подключения к БД: {e}")
            sys.exit(1)
    
    def get_master_table_data(self) -> List[Dict]:
        """Получает данные из мастер таблицы dim_products"""
        try:
            cursor = self.master_connection.cursor(dictionary=True)
            
            query = """
                SELECT 
                    id,
                    sku_ozon,
                    sku_wb,
                    product_name,
                    name,
                    brand,
                    category,
                    cost_price,
                    created_at,
                    updated_at
                FROM dim_products 
                WHERE sku_ozon IS NOT NULL 
                AND sku_ozon != ''
                ORDER BY updated_at DESC
            """
            
            cursor.execute(query)
            results = cursor.fetchall()
            cursor.close()
            
            self.stats['total_master_records'] = len(results)
            logger.info(f"Получено {len(results)} записей из мастер таблицы")
            
            return results
            
        except Exception as e:
            logger.error(f"Ошибка получения данных из мастер таблицы: {e}")
            return []
    
    def sync_to_product_names(self, master_data: List[Dict]) -> Dict[str, int]:
        """Синхронизирует данные в таблицу product_names"""
        if not master_data:
            return {'inserted': 0, 'updated': 0, 'failed': 0}
        
        cursor = self.core_connection.cursor()
        inserted = updated = failed = 0
        
        try:
            # Подготавливаем запрос для UPSERT
            upsert_query = """
                INSERT INTO product_names (
                    product_id, sku, product_name, source, created_at, updated_at
                ) VALUES (%s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                product_name = VALUES(product_name),
                updated_at = VALUES(updated_at)
            """
            
            for record in master_data:
                try:
                    # Формируем полное название товара
                    product_name_parts = []
                    
                    if record.get('product_name'):
                        product_name_parts.append(record['product_name'])
                    elif record.get('name'):
                        product_name_parts.append(record['name'])
                    
                    if record.get('brand'):
                        product_name_parts.append(f"({record['brand']})")
                    
                    if record.get('category'):
                        product_name_parts.append(f"[{record['category']}]")
                    
                    full_product_name = ' '.join(product_name_parts) if product_name_parts else f"Товар {record['sku_ozon']}"
                    
                    # Проверяем, существует ли запись
                    check_cursor = self.core_connection.cursor()
                    check_cursor.execute(
                        "SELECT id FROM product_names WHERE sku = %s AND source = 'MasterTable'",
                        (record['sku_ozon'],)
                    )
                    exists = check_cursor.fetchone()
                    check_cursor.close()
                    
                    # Выполняем UPSERT
                    cursor.execute(upsert_query, (
                        record['id'],  # Используем ID из мастер таблицы как product_id
                        record['sku_ozon'],
                        full_product_name,
                        'MasterTable',
                        record.get('created_at') or time.strftime('%Y-%m-%d %H:%M:%S'),
                        record.get('updated_at') or time.strftime('%Y-%m-%d %H:%M:%S')
                    ))
                    
                    if exists:
                        updated += 1
                    else:
                        inserted += 1
                    
                    self.stats['processed_records'] += 1
                    
                    # Показываем прогресс каждые 100 записей
                    if self.stats['processed_records'] % 100 == 0:
                        progress = (self.stats['processed_records'] / self.stats['total_master_records']) * 100
                        logger.info(f"Прогресс: {progress:.1f}% ({self.stats['processed_records']}/{self.stats['total_master_records']})")
                    
                except Exception as e:
                    logger.error(f"Ошибка обработки записи {record.get('sku_ozon', 'unknown')}: {e}")
                    failed += 1
                    self.stats['failed_records'] += 1
            
            self.core_connection.commit()
            
            self.stats['inserted_records'] = inserted
            self.stats['updated_records'] = updated
            
            logger.info(f"Синхронизация завершена: вставлено {inserted}, обновлено {updated}, ошибок {failed}")
            
            return {'inserted': inserted, 'updated': updated, 'failed': failed}
            
        except Exception as e:
            logger.error(f"Ошибка синхронизации: {e}")
            self.core_connection.rollback()
            return {'inserted': 0, 'updated': 0, 'failed': len(master_data)}
        finally:
            cursor.close()
    
    def update_inventory_product_ids(self):
        """Обновляет product_id в inventory_data на основе синхронизированных данных"""
        try:
            cursor = self.core_connection.cursor()
            
            # Обновляем product_id для записей, где он равен 0
            update_query = """
                UPDATE inventory_data i
                JOIN product_names p ON i.sku = p.sku AND p.source = 'MasterTable'
                SET i.product_id = p.product_id
                WHERE i.product_id = 0
                AND p.product_id > 0
            """
            
            cursor.execute(update_query)
            updated_rows = cursor.rowcount
            self.core_connection.commit()
            cursor.close()
            
            logger.info(f"Обновлено product_id для {updated_rows} записей в inventory_data")
            return updated_rows
            
        except Exception as e:
            logger.error(f"Ошибка обновления product_id: {e}")
            return 0
    
    def get_sync_statistics(self) -> Dict:
        """Получает статистику синхронизации"""
        try:
            cursor = self.core_connection.cursor()
            
            # Статистика по источникам данных в product_names
            cursor.execute("""
                SELECT 
                    source,
                    COUNT(*) as count,
                    MAX(updated_at) as last_updated
                FROM product_names 
                GROUP BY source
                ORDER BY count DESC
            """)
            
            sources_stats = cursor.fetchall()
            
            # Статистика покрытия товаров названиями
            cursor.execute("""
                SELECT 
                    COUNT(*) as total_inventory_items,
                    COUNT(p.product_name) as with_names,
                    COUNT(CASE WHEN p.source = 'MasterTable' THEN 1 END) as from_master_table
                FROM inventory_data i
                LEFT JOIN product_names p ON (
                    (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                    (i.product_id = 0 AND i.sku = p.sku)
                )
                WHERE i.current_stock > 0
            """)
            
            coverage_stats = cursor.fetchone()
            cursor.close()
            
            return {
                'sources_stats': sources_stats,
                'coverage_stats': coverage_stats,
                'sync_stats': self.stats
            }
            
        except Exception as e:
            logger.error(f"Ошибка получения статистики: {e}")
            return {}
    
    def print_statistics(self):
        """Выводит статистику синхронизации"""
        elapsed = time.time() - self.stats['start_time']
        
        print("\n" + "="*60)
        print("СТАТИСТИКА СИНХРОНИЗАЦИИ МАСТЕР ТАБЛИЦЫ")
        print("="*60)
        print(f"Записей в мастер таблице: {self.stats['total_master_records']}")
        print(f"Обработано записей: {self.stats['processed_records']}")
        print(f"Вставлено новых: {self.stats['inserted_records']}")
        print(f"Обновлено существующих: {self.stats['updated_records']}")
        print(f"Ошибок: {self.stats['failed_records']}")
        print(f"Время выполнения: {elapsed:.1f} секунд")
        print(f"Скорость: {(self.stats['processed_records']/max(elapsed, 1)):.1f} записей/сек")
        
        # Получаем дополнительную статистику
        additional_stats = self.get_sync_statistics()
        
        if additional_stats.get('coverage_stats'):
            coverage = additional_stats['coverage_stats']
            total = coverage[0]
            with_names = coverage[1]
            from_master = coverage[2]
            
            print(f"\nПокрытие товаров названиями:")
            print(f"  Всего товаров с остатками: {total}")
            print(f"  С названиями: {with_names} ({(with_names/max(total,1)*100):.1f}%)")
            print(f"  Из мастер таблицы: {from_master} ({(from_master/max(total,1)*100):.1f}%)")
        
        print("="*60)
    
    def run(self, incremental: bool = False):
        """Запускает процесс синхронизации"""
        logger.info("Начинаем синхронизацию данных из мастер таблицы")
        
        try:
            # Получаем данные из мастер таблицы
            master_data = self.get_master_table_data()
            
            if not master_data:
                logger.info("Нет данных для синхронизации")
                return
            
            # Синхронизируем в product_names
            sync_result = self.sync_to_product_names(master_data)
            
            # Обновляем product_id в inventory_data
            updated_inventory = self.update_inventory_product_ids()
            
            # Выводим статистику
            self.print_statistics()
            
            logger.info("Синхронизация завершена успешно")
            
        except KeyboardInterrupt:
            logger.info("Синхронизация прервана пользователем")
            self.print_statistics()
        except Exception as e:
            logger.error(f"Критическая ошибка: {e}")
            self.print_statistics()
        finally:
            self.cleanup()
    
    def cleanup(self):
        """Очистка ресурсов"""
        if self.core_connection:
            self.core_connection.close()
        if self.master_connection:
            self.master_connection.close()

def main():
    """Главная функция"""
    import argparse
    
    parser = argparse.ArgumentParser(description='Синхронизация данных из мастер таблицы dim_products')
    parser.add_argument('--incremental', action='store_true',
                       help='Инкрементальная синхронизация (только измененные записи)')
    parser.add_argument('--dry-run', action='store_true',
                       help='Только показать статистику без выполнения синхронизации')
    
    args = parser.parse_args()
    
    sync_service = MasterTableSyncService()
    
    if args.dry_run:
        master_data = sync_service.get_master_table_data()
        print(f"Найдено {len(master_data)} записей в мастер таблице для синхронизации")
        
        if master_data[:5]:  # Показываем первые 5 для примера
            print("\nПримеры записей:")
            for record in master_data[:5]:
                print(f"  SKU: {record['sku_ozon']} | Название: {record.get('product_name', 'N/A')} | Бренд: {record.get('brand', 'N/A')}")
        
        sync_service.cleanup()
    else:
        sync_service.run(args.incremental)

if __name__ == "__main__":
    main()