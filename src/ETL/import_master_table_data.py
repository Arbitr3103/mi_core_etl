#!/usr/bin/env python3
"""
Импорт данных из мастер таблицы dim_products в локальную таблицу product_master
Поддерживает импорт из CSV файла, экспортированного из dim_products
"""

import csv
import json
import mysql.connector
import os
import sys
import time
import logging
from typing import Dict, List, Optional
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('import_master_table.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

class MasterTableImporter:
    """Импортер данных мастер таблицы"""
    
    def __init__(self):
        self.connection = None
        self._connect_to_database()
        
        # Статистика
        self.stats = {
            'total_records': 0,
            'processed_records': 0,
            'inserted_records': 0,
            'updated_records': 0,
            'failed_records': 0,
            'start_time': time.time()
        }
    
    def _connect_to_database(self):
        """Подключение к базе данных"""
        try:
            self.connection = mysql.connector.connect(
                host=os.getenv('DB_HOST', 'localhost'),
                database='mi_core',
                user=os.getenv('DB_USER', 'v_admin'),
                password=os.getenv('DB_PASSWORD'),
                charset='utf8mb4'
            )
            logger.info("Подключение к mi_core установлено")
            
        except Exception as e:
            logger.error(f"Ошибка подключения к БД: {e}")
            sys.exit(1)
    
    def create_table_if_not_exists(self):
        """Создает таблицу product_master если она не существует"""
        try:
            cursor = self.connection.cursor()
            
            # Читаем SQL скрипт создания таблицы
            sql_file = 'create_product_master_table.sql'
            if os.path.exists(sql_file):
                with open(sql_file, 'r', encoding='utf-8') as f:
                    sql_content = f.read()
                
                # Выполняем SQL команды
                for statement in sql_content.split(';'):
                    statement = statement.strip()
                    if statement and not statement.startswith('--'):
                        try:
                            cursor.execute(statement)
                        except mysql.connector.Error as e:
                            if 'already exists' not in str(e).lower():
                                logger.warning(f"SQL warning: {e}")
                
                self.connection.commit()
                logger.info("Таблица product_master готова к использованию")
            else:
                logger.warning("Файл create_product_master_table.sql не найден")
            
            cursor.close()
            
        except Exception as e:
            logger.error(f"Ошибка создания таблицы: {e}")
    
    def import_from_csv(self, csv_file: str, delimiter: str = ',') -> Dict[str, int]:
        """Импортирует данные из CSV файла"""
        if not os.path.exists(csv_file):
            logger.error(f"Файл {csv_file} не найден")
            return {'inserted': 0, 'updated': 0, 'failed': 0}
        
        try:
            with open(csv_file, 'r', encoding='utf-8') as f:
                # Определяем диалект CSV
                sample = f.read(1024)
                f.seek(0)
                sniffer = csv.Sniffer()
                dialect = sniffer.sniff(sample, delimiters=delimiter)
                
                reader = csv.DictReader(f, dialect=dialect)
                
                # Получаем все записи
                records = list(reader)
                self.stats['total_records'] = len(records)
                
                logger.info(f"Найдено {len(records)} записей в CSV файле")
                
                return self._process_records(records)
                
        except Exception as e:
            logger.error(f"Ошибка чтения CSV файла: {e}")
            return {'inserted': 0, 'updated': 0, 'failed': 0}
    
    def import_from_json(self, json_file: str) -> Dict[str, int]:
        """Импортирует данные из JSON файла"""
        if not os.path.exists(json_file):
            logger.error(f"Файл {json_file} не найден")
            return {'inserted': 0, 'updated': 0, 'failed': 0}
        
        try:
            with open(json_file, 'r', encoding='utf-8') as f:
                data = json.load(f)
                
                # Поддерживаем разные форматы JSON
                if isinstance(data, list):
                    records = data
                elif isinstance(data, dict) and 'data' in data:
                    records = data['data']
                else:
                    records = [data]
                
                self.stats['total_records'] = len(records)
                logger.info(f"Найдено {len(records)} записей в JSON файле")
                
                return self._process_records(records)
                
        except Exception as e:
            logger.error(f"Ошибка чтения JSON файла: {e}")
            return {'inserted': 0, 'updated': 0, 'failed': 0}
    
    def import_sample_data(self) -> Dict[str, int]:
        """Импортирует тестовые данные для демонстрации"""
        sample_records = [
            {
                'id': 1,
                'sku_ozon': '257202054',
                'sku_wb': 'WB257202054',
                'barcode': '4607001234567',
                'product_name': 'Хлопья овсяные ЭТОНОВО 700г',
                'name': 'Хлопья овсяные не требующие варки',
                'brand': 'ЭТОНОВО',
                'category': 'Завтраки и каши',
                'cost_price': 89.50
            },
            {
                'id': 2,
                'sku_ozon': '161875896',
                'sku_wb': 'WB161875896',
                'barcode': '4607001234568',
                'product_name': 'Конфеты ирис SOLENTO Йогурт 1,0кг',
                'name': 'Ирис со вкусом йогурта',
                'brand': 'SOLENTO',
                'category': 'Кондитерские изделия',
                'cost_price': 245.00
            },
            {
                'id': 3,
                'sku_ozon': '161875313',
                'sku_wb': 'WB161875313',
                'barcode': '4607001234569',
                'product_name': 'Лапша ЭТОНОВО рисовая органическая 300г',
                'name': 'Лапша рисовая органическая',
                'brand': 'ЭТОНОВО',
                'category': 'Макаронные изделия',
                'cost_price': 125.75
            },
            {
                'id': 4,
                'sku_ozon': '167970913',
                'sku_wb': 'WB167970913',
                'barcode': '4607001234570',
                'product_name': 'Разрыхлитель для теста ЭТОНОВО 180г',
                'name': 'Разрыхлитель для выпечки',
                'brand': 'ЭТОНОВО',
                'category': 'Ингредиенты для выпечки',
                'cost_price': 45.25
            },
            {
                'id': 5,
                'sku_ozon': '1402098152',
                'sku_wb': 'WB1402098152',
                'barcode': '4607001234571',
                'product_name': 'Смесь для выпечки ЭТОНОВО Булочка с псиллиумом 160г',
                'name': 'Смесь для булочек с псиллиумом',
                'brand': 'ЭТОНОВО',
                'category': 'Смеси для выпечки',
                'cost_price': 156.00
            }
        ]
        
        self.stats['total_records'] = len(sample_records)
        logger.info(f"Импорт {len(sample_records)} тестовых записей")
        
        return self._process_records(sample_records)
    
    def _process_records(self, records: List[Dict]) -> Dict[str, int]:
        """Обрабатывает записи и вставляет их в БД"""
        cursor = self.connection.cursor()
        inserted = updated = failed = 0
        
        try:
            # Подготавливаем запрос для UPSERT
            upsert_query = """
                INSERT INTO product_master (
                    master_id, sku_ozon, sku_wb, barcode, product_name, name, 
                    brand, category, cost_price, created_at, updated_at
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                sku_wb = VALUES(sku_wb),
                barcode = VALUES(barcode),
                product_name = VALUES(product_name),
                name = VALUES(name),
                brand = VALUES(brand),
                category = VALUES(category),
                cost_price = VALUES(cost_price),
                updated_at = VALUES(updated_at),
                synced_at = CURRENT_TIMESTAMP
            """
            
            for record in records:
                try:
                    # Нормализуем данные записи
                    normalized_record = self._normalize_record(record)
                    
                    if not normalized_record.get('sku_ozon'):
                        logger.warning(f"Пропускаем запись без sku_ozon: {record}")
                        failed += 1
                        continue
                    
                    # Проверяем, существует ли запись
                    check_cursor = self.connection.cursor()
                    check_cursor.execute(
                        "SELECT id FROM product_master WHERE sku_ozon = %s",
                        (normalized_record['sku_ozon'],)
                    )
                    exists = check_cursor.fetchone()
                    check_cursor.close()
                    
                    # Выполняем UPSERT
                    cursor.execute(upsert_query, (
                        normalized_record.get('master_id'),
                        normalized_record.get('sku_ozon'),
                        normalized_record.get('sku_wb'),
                        normalized_record.get('barcode'),
                        normalized_record.get('product_name'),
                        normalized_record.get('name'),
                        normalized_record.get('brand'),
                        normalized_record.get('category'),
                        normalized_record.get('cost_price'),
                        normalized_record.get('created_at', time.strftime('%Y-%m-%d %H:%M:%S')),
                        normalized_record.get('updated_at', time.strftime('%Y-%m-%d %H:%M:%S'))
                    ))
                    
                    if exists:
                        updated += 1
                    else:
                        inserted += 1
                    
                    self.stats['processed_records'] += 1
                    
                    # Показываем прогресс каждые 100 записей
                    if self.stats['processed_records'] % 100 == 0:
                        progress = (self.stats['processed_records'] / self.stats['total_records']) * 100
                        logger.info(f"Прогресс: {progress:.1f}% ({self.stats['processed_records']}/{self.stats['total_records']})")
                    
                except Exception as e:
                    logger.error(f"Ошибка обработки записи {record.get('sku_ozon', 'unknown')}: {e}")
                    failed += 1
                    self.stats['failed_records'] += 1
            
            self.connection.commit()
            
            self.stats['inserted_records'] = inserted
            self.stats['updated_records'] = updated
            
            logger.info(f"Импорт завершен: вставлено {inserted}, обновлено {updated}, ошибок {failed}")
            
            return {'inserted': inserted, 'updated': updated, 'failed': failed}
            
        except Exception as e:
            logger.error(f"Ошибка импорта: {e}")
            self.connection.rollback()
            return {'inserted': 0, 'updated': 0, 'failed': len(records)}
        finally:
            cursor.close()
    
    def _normalize_record(self, record: Dict) -> Dict:
        """Нормализует запись для вставки в БД"""
        normalized = {}
        
        # Маппинг полей (поддерживаем разные варианты названий)
        field_mapping = {
            'master_id': ['id', 'master_id', 'product_id'],
            'sku_ozon': ['sku_ozon', 'ozon_sku', 'sku'],
            'sku_wb': ['sku_wb', 'wb_sku', 'wildberries_sku'],
            'barcode': ['barcode', 'bar_code', 'ean'],
            'product_name': ['product_name', 'name', 'title', 'product_title'],
            'name': ['name', 'alternative_name', 'short_name'],
            'brand': ['brand', 'brand_name', 'manufacturer'],
            'category': ['category', 'category_name', 'product_category'],
            'cost_price': ['cost_price', 'price', 'cost'],
            'created_at': ['created_at', 'created', 'date_created'],
            'updated_at': ['updated_at', 'updated', 'date_updated', 'last_modified']
        }
        
        for target_field, source_fields in field_mapping.items():
            for source_field in source_fields:
                if source_field in record and record[source_field] is not None:
                    value = record[source_field]
                    
                    # Специальная обработка для некоторых полей
                    if target_field == 'cost_price' and value:
                        try:
                            normalized[target_field] = float(str(value).replace(',', '.'))
                        except (ValueError, TypeError):
                            normalized[target_field] = None
                    elif target_field in ['created_at', 'updated_at'] and value:
                        # Оставляем как есть, MySQL сам разберется с форматом
                        normalized[target_field] = str(value)
                    else:
                        # Обрезаем строки до максимальной длины
                        if isinstance(value, str):
                            max_lengths = {
                                'sku_ozon': 255,
                                'sku_wb': 50,
                                'barcode': 255,
                                'product_name': 500,
                                'name': 500,
                                'brand': 255,
                                'category': 255
                            }
                            max_len = max_lengths.get(target_field, 1000)
                            normalized[target_field] = value[:max_len] if len(value) > max_len else value
                        else:
                            normalized[target_field] = value
                    break
        
        return normalized
    
    def get_import_statistics(self) -> Dict:
        """Получает статистику импорта"""
        try:
            cursor = self.connection.cursor()
            
            # Общая статистика таблицы
            cursor.execute("""
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN product_name IS NOT NULL AND product_name != '' THEN 1 END) as with_product_names,
                    COUNT(CASE WHEN name IS NOT NULL AND name != '' THEN 1 END) as with_names,
                    COUNT(CASE WHEN brand IS NOT NULL AND brand != '' THEN 1 END) as with_brands,
                    COUNT(CASE WHEN category IS NOT NULL AND category != '' THEN 1 END) as with_categories,
                    COUNT(DISTINCT brand) as unique_brands,
                    COUNT(DISTINCT category) as unique_categories,
                    MAX(synced_at) as last_sync
                FROM product_master
            """)
            
            table_stats = cursor.fetchone()
            
            # Топ брендов
            cursor.execute("""
                SELECT brand, COUNT(*) as count
                FROM product_master 
                WHERE brand IS NOT NULL AND brand != ''
                GROUP BY brand 
                ORDER BY count DESC 
                LIMIT 10
            """)
            
            top_brands = cursor.fetchall()
            
            # Топ категорий
            cursor.execute("""
                SELECT category, COUNT(*) as count
                FROM product_master 
                WHERE category IS NOT NULL AND category != ''
                GROUP BY category 
                ORDER BY count DESC 
                LIMIT 10
            """)
            
            top_categories = cursor.fetchall()
            
            cursor.close()
            
            return {
                'table_stats': table_stats,
                'top_brands': top_brands,
                'top_categories': top_categories,
                'import_stats': self.stats
            }
            
        except Exception as e:
            logger.error(f"Ошибка получения статистики: {e}")
            return {}
    
    def print_statistics(self):
        """Выводит статистику импорта"""
        elapsed = time.time() - self.stats['start_time']
        
        print("\n" + "="*60)
        print("СТАТИСТИКА ИМПОРТА МАСТЕР ТАБЛИЦЫ")
        print("="*60)
        print(f"Всего записей для импорта: {self.stats['total_records']}")
        print(f"Обработано записей: {self.stats['processed_records']}")
        print(f"Вставлено новых: {self.stats['inserted_records']}")
        print(f"Обновлено существующих: {self.stats['updated_records']}")
        print(f"Ошибок: {self.stats['failed_records']}")
        print(f"Время выполнения: {elapsed:.1f} секунд")
        print(f"Скорость: {(self.stats['processed_records']/max(elapsed, 1)):.1f} записей/сек")
        
        # Получаем дополнительную статистику
        additional_stats = self.get_import_statistics()
        
        if additional_stats.get('table_stats'):
            stats = additional_stats['table_stats']
            print(f"\nСтатистика таблицы product_master:")
            print(f"  Всего товаров: {stats[0]}")
            print(f"  С основными названиями: {stats[1]} ({(stats[1]/max(stats[0],1)*100):.1f}%)")
            print(f"  С альтернативными названиями: {stats[2]} ({(stats[2]/max(stats[0],1)*100):.1f}%)")
            print(f"  С брендами: {stats[3]} ({(stats[3]/max(stats[0],1)*100):.1f}%)")
            print(f"  С категориями: {stats[4]} ({(stats[4]/max(stats[0],1)*100):.1f}%)")
            print(f"  Уникальных брендов: {stats[5]}")
            print(f"  Уникальных категорий: {stats[6]}")
        
        print("="*60)
    
    def cleanup(self):
        """Очистка ресурсов"""
        if self.connection:
            self.connection.close()

def main():
    """Главная функция"""
    import argparse
    
    parser = argparse.ArgumentParser(description='Импорт данных мастер таблицы товаров')
    parser.add_argument('--csv', type=str, help='Путь к CSV файлу для импорта')
    parser.add_argument('--json', type=str, help='Путь к JSON файлу для импорта')
    parser.add_argument('--sample', action='store_true', help='Импортировать тестовые данные')
    parser.add_argument('--delimiter', type=str, default=',', help='Разделитель для CSV (по умолчанию: ,)')
    parser.add_argument('--create-table', action='store_true', help='Создать таблицу product_master')
    
    args = parser.parse_args()
    
    if not any([args.csv, args.json, args.sample, args.create_table]):
        parser.print_help()
        return
    
    importer = MasterTableImporter()
    
    try:
        if args.create_table:
            importer.create_table_if_not_exists()
        
        if args.csv:
            result = importer.import_from_csv(args.csv, args.delimiter)
            print(f"Импорт из CSV завершен: {result}")
        
        if args.json:
            result = importer.import_from_json(args.json)
            print(f"Импорт из JSON завершен: {result}")
        
        if args.sample:
            result = importer.import_sample_data()
            print(f"Импорт тестовых данных завершен: {result}")
        
        importer.print_statistics()
        
    finally:
        importer.cleanup()

if __name__ == "__main__":
    main()