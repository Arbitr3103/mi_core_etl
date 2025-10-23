#!/usr/bin/env python3
"""
Утилита для массового обновления названий товаров
Получает названия для всех товаров с product_id = 0 в inventory_data
"""

import sys
import time
import logging
from typing import List, Dict
from product_name_resolver import ProductNameResolver
import mysql.connector
from config_local import DB_HOST, DB_NAME, DB_USER, DB_PASSWORD

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('update_product_names.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

class ProductNameUpdater:
    """Утилита для массового обновления названий товаров"""
    
    def __init__(self):
        self.resolver = ProductNameResolver()
        self.db_connection = None
        self._connect_to_db()
        
        # Статистика
        self.stats = {
            'total_skus': 0,
            'processed_skus': 0,
            'resolved_names': 0,
            'failed_skus': 0,
            'start_time': time.time()
        }
    
    def _connect_to_db(self):
        """Подключение к базе данных"""
        try:
            self.db_connection = mysql.connector.connect(
                host=DB_HOST,
                database=DB_NAME,
                user=DB_USER,
                password=DB_PASSWORD,
                charset='utf8mb4'
            )
            logger.info("Подключение к БД установлено")
        except Exception as e:
            logger.error(f"Ошибка подключения к БД: {e}")
            sys.exit(1)
    
    def get_skus_without_names(self) -> List[str]:
        """Получает список SKU без названий"""
        try:
            cursor = self.db_connection.cursor()
            
            # Получаем SKU с product_id = 0, которых нет в product_names
            query = """
                SELECT DISTINCT i.sku 
                FROM inventory_data i
                LEFT JOIN product_names p ON i.sku = p.sku
                WHERE i.product_id = 0 
                AND i.current_stock > 0
                AND p.sku IS NULL
                ORDER BY i.sku
            """
            
            cursor.execute(query)
            skus = [row[0] for row in cursor.fetchall()]
            cursor.close()
            
            logger.info(f"Найдено {len(skus)} SKU без названий")
            return skus
            
        except Exception as e:
            logger.error(f"Ошибка получения SKU: {e}")
            return []
    
    def update_names_batch(self, skus: List[str], batch_size: int = 50) -> Dict[str, int]:
        """
        Обновляет названия батчами
        
        Args:
            skus: Список SKU для обработки
            batch_size: Размер батча
            
        Returns:
            Статистика обработки
        """
        total_batches = (len(skus) + batch_size - 1) // batch_size
        batch_stats = {'resolved': 0, 'failed': 0}
        
        logger.info(f"Начинаем обработку {len(skus)} SKU в {total_batches} батчах по {batch_size}")
        
        for i in range(0, len(skus), batch_size):
            batch_num = (i // batch_size) + 1
            batch = skus[i:i + batch_size]
            
            logger.info(f"Обрабатываем батч {batch_num}/{total_batches} ({len(batch)} SKU)")
            
            try:
                # Получаем названия для батча
                names = self.resolver.batch_resolve_names(batch)
                
                resolved_count = len(names)
                failed_count = len(batch) - resolved_count
                
                batch_stats['resolved'] += resolved_count
                batch_stats['failed'] += failed_count
                
                self.stats['processed_skus'] += len(batch)
                self.stats['resolved_names'] += resolved_count
                self.stats['failed_skus'] += failed_count
                
                logger.info(f"Батч {batch_num}: получено {resolved_count} названий, не найдено {failed_count}")
                
                # Показываем прогресс
                progress = (self.stats['processed_skus'] / self.stats['total_skus']) * 100
                elapsed = time.time() - self.stats['start_time']
                logger.info(f"Прогресс: {progress:.1f}% ({self.stats['processed_skus']}/{self.stats['total_skus']}) за {elapsed:.1f}с")
                
                # Пауза между батчами для соблюдения rate limits
                if i + batch_size < len(skus):
                    time.sleep(2)
                    
            except Exception as e:
                logger.error(f"Ошибка обработки батча {batch_num}: {e}")
                batch_stats['failed'] += len(batch)
                self.stats['failed_skus'] += len(batch)
        
        return batch_stats
    
    def update_inventory_product_ids(self):
        """
        Обновляет product_id в inventory_data на основе данных из product_names
        """
        try:
            cursor = self.db_connection.cursor()
            
            # Обновляем product_id для записей, где он равен 0, но есть соответствие в product_names
            update_query = """
                UPDATE inventory_data i
                JOIN product_names p ON i.sku = p.sku
                SET i.product_id = p.product_id
                WHERE i.product_id = 0
                AND p.product_id > 0
            """
            
            cursor.execute(update_query)
            updated_rows = cursor.rowcount
            self.db_connection.commit()
            cursor.close()
            
            logger.info(f"Обновлено product_id для {updated_rows} записей в inventory_data")
            
        except Exception as e:
            logger.error(f"Ошибка обновления product_id: {e}")
    
    def print_statistics(self):
        """Выводит статистику обработки"""
        elapsed = time.time() - self.stats['start_time']
        
        print("\n" + "="*60)
        print("СТАТИСТИКА ОБНОВЛЕНИЯ НАЗВАНИЙ ТОВАРОВ")
        print("="*60)
        print(f"Всего SKU для обработки: {self.stats['total_skus']}")
        print(f"Обработано SKU: {self.stats['processed_skus']}")
        print(f"Получено названий: {self.stats['resolved_names']}")
        print(f"Не найдено названий: {self.stats['failed_skus']}")
        print(f"Успешность: {(self.stats['resolved_names']/max(self.stats['processed_skus'], 1)*100):.1f}%")
        print(f"Время выполнения: {elapsed:.1f} секунд")
        print(f"Скорость: {(self.stats['processed_skus']/max(elapsed, 1)):.1f} SKU/сек")
        print("="*60)
    
    def run(self, batch_size: int = 50):
        """Запускает процесс обновления"""
        logger.info("Начинаем массовое обновление названий товаров")
        
        # Получаем список SKU без названий
        skus = self.get_skus_without_names()
        
        if not skus:
            logger.info("Все SKU уже имеют названия")
            return
        
        self.stats['total_skus'] = len(skus)
        
        try:
            # Обновляем названия
            batch_stats = self.update_names_batch(skus, batch_size)
            
            # Обновляем product_id в inventory_data
            self.update_inventory_product_ids()
            
            # Выводим статистику
            self.print_statistics()
            
            logger.info("Обновление названий завершено успешно")
            
        except KeyboardInterrupt:
            logger.info("Обновление прервано пользователем")
            self.print_statistics()
        except Exception as e:
            logger.error(f"Критическая ошибка: {e}")
            self.print_statistics()
        finally:
            self.cleanup()
    
    def cleanup(self):
        """Очистка ресурсов"""
        if self.resolver:
            self.resolver.close()
        if self.db_connection:
            self.db_connection.close()

def main():
    """Главная функция"""
    import argparse
    
    parser = argparse.ArgumentParser(description='Массовое обновление названий товаров')
    parser.add_argument('--batch-size', type=int, default=50, 
                       help='Размер батча для обработки (по умолчанию: 50)')
    parser.add_argument('--dry-run', action='store_true',
                       help='Только показать количество SKU без выполнения обновления')
    
    args = parser.parse_args()
    
    updater = ProductNameUpdater()
    
    if args.dry_run:
        skus = updater.get_skus_without_names()
        print(f"Найдено {len(skus)} SKU без названий")
        if skus[:10]:  # Показываем первые 10 для примера
            print("Примеры SKU:")
            for sku in skus[:10]:
                print(f"  {sku}")
        updater.cleanup()
    else:
        updater.run(args.batch_size)

if __name__ == "__main__":
    main()