#!/usr/bin/env python3
"""
Модуль импорта остатков товаров с маркетплейсов Ozon и Wildberries.

Функционал:
- Получение остатков товаров через API Ozon и Wildberries
- Обновление таблицы inventory с использованием UPSERT логики
- Поддержка различных типов складов (FBO/FBS)
- Детальное логирование всех операций

Автор: ETL System
Дата: 20 сентября 2025
"""

import os
import sys
import logging
import requests
import time
from datetime import datetime
from typing import List, Dict, Any, Optional

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

from ozon_importer import connect_to_db
import config

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class StockImporter:
    """Класс для импорта остатков товаров с маркетплейсов."""
    
    def __init__(self):
        """Инициализация импортера."""
        self.connection = None
        self.cursor = None
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            logger.info("Успешное подключение к базе данных")
        except Exception as e:
            logger.error(f"Ошибка подключения к БД: {e}")
            raise
    
    def close_database_connection(self):
        """Закрытие подключения к базе данных."""
        if self.cursor:
            self.cursor.close()
        if self.connection:
            self.connection.close()
        logger.info("Подключение к БД закрыто")

    def get_ozon_inventory(self) -> List[Dict[str, Any]]:
        """
        Получение остатков товаров с Ozon через API.
        
        Returns:
            List[Dict]: Список остатков товаров
        """
        logger.info("🔄 Начинаем получение остатков с Ozon...")
        
        url = "https://api-seller.ozon.ru/v4/product/info/stocks"
        headers = {
            "Client-Id": config.OZON_CLIENT_ID,
            "Api-Key": config.OZON_API_KEY,
            "Content-Type": "application/json"
        }
        
        inventory_data = []
        cursor = ""
        limit = 100
        
        try:
            while True:
                payload = {
                    "filter": {
                        "visibility": "ALL"
                    },
                    "limit": limit
                }
                
                # Добавляем cursor для пагинации, если он есть
                if cursor:
                    payload["cursor"] = cursor
                
                response = requests.post(url, json=payload, headers=headers)
                response.raise_for_status()
                
                data = response.json()
                
                # Исправляем структуру ответа - убираем 'result'
                if not data.get('items'):
                    break
                
                items = data['items']
                logger.info(f"Получено {len(items)} товаров с Ozon (cursor: {cursor[:20]}...)")
                
                for item in items:
                    # Получаем информацию о товаре из БД
                    product_id = self._get_product_id_by_ozon_sku(item.get('offer_id', ''))
                    
                    if not product_id:
                        logger.warning(f"Товар с offer_id {item.get('offer_id')} не найден в БД")
                        continue
                    
                    # Обрабатываем остатки по складам
                    for stock in item.get('stocks', []):
                        # Формируем название склада из warehouse_ids или используем тип склада
                        warehouse_name = f"Ozon-{stock.get('type', 'FBO').upper()}"
                        if stock.get('warehouse_ids'):
                            warehouse_name += f"-{stock['warehouse_ids'][0]}"
                        
                        inventory_record = {
                            'product_id': product_id,
                            'warehouse_name': warehouse_name,
                            'stock_type': stock.get('type', 'fbo').upper(),  # FBO или FBS
                            'quantity_present': stock.get('present', 0),
                            'quantity_reserved': stock.get('reserved', 0),
                            'source': 'Ozon'
                        }
                        inventory_data.append(inventory_record)
                
                # Проверяем есть ли еще страницы
                cursor = data.get('cursor', '')
                if not cursor or len(items) < limit:
                    break
                
                time.sleep(0.1)  # Небольшая задержка между запросами
                
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при запросе к Ozon API: {e}")
            raise
        except Exception as e:
            logger.error(f"Неожиданная ошибка при получении остатков Ozon: {e}")
            raise
        
        logger.info(f"✅ Получено {len(inventory_data)} записей остатков с Ozon")
        return inventory_data

    def get_wb_inventory(self) -> List[Dict[str, Any]]:
        """
        Получение остатков товаров с Wildberries через API.
        
        Returns:
            List[Dict]: Список остатков товаров
        """
        logger.info("🔄 Начинаем получение остатков с Wildberries...")
        
        inventory_data = []
        
        try:
            # Сначала получаем список складов
            warehouses = self._get_wb_warehouses()
            logger.info(f"Найдено {len(warehouses)} складов WB")
            
            # Для каждого склада получаем остатки
            for warehouse in warehouses:
                warehouse_id = warehouse.get('id')
                warehouse_name = warehouse.get('name', f'Склад-{warehouse_id}')
                
                stocks = self._get_wb_stocks_for_warehouse(warehouse_id, warehouse_name)
                inventory_data.extend(stocks)
                
                time.sleep(0.5)  # Задержка между запросами к разным складам
                
        except Exception as e:
            logger.error(f"Ошибка при получении остатков WB: {e}")
            raise
        
        logger.info(f"✅ Получено {len(inventory_data)} записей остатков с Wildberries")
        return inventory_data

    def _get_wb_warehouses(self) -> List[Dict[str, Any]]:
        """Получение списка складов Wildberries."""
        url = "https://statistics-api.wildberries.ru/api/v1/supplier/warehouses"
        headers = {
            "Authorization": config.WB_API_TOKEN
        }
        
        try:
            response = requests.get(url, headers=headers)
            response.raise_for_status()
            
            warehouses = response.json()
            return warehouses if isinstance(warehouses, list) else []
            
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при получении списка складов WB: {e}")
            return []

    def _get_wb_stocks_for_warehouse(self, warehouse_id: int, warehouse_name: str) -> List[Dict[str, Any]]:
        """
        Получение остатков для конкретного склада WB.
        
        Args:
            warehouse_id: ID склада
            warehouse_name: Название склада
            
        Returns:
            List[Dict]: Список остатков для данного склада
        """
        url = f"https://statistics-api.wildberries.ru/api/v1/supplier/stocks"
        headers = {
            "Authorization": config.WB_API_TOKEN
        }
        
        # Параметры для получения остатков
        params = {
            "dateFrom": datetime.now().strftime("%Y-%m-%d")
        }
        
        stocks_data = []
        
        try:
            response = requests.get(url, headers=headers, params=params)
            response.raise_for_status()
            
            data = response.json()
            
            for item in data:
                # Получаем информацию о товаре из БД
                product_id = self._get_product_id_by_wb_sku(item.get('nmId', ''))
                
                if not product_id:
                    logger.warning(f"Товар с nmId {item.get('nmId')} не найден в БД")
                    continue
                
                # Проверяем, относится ли товар к нужному складу
                if warehouse_id and item.get('warehouseId') != warehouse_id:
                    continue
                
                stock_record = {
                    'product_id': product_id,
                    'warehouse_name': warehouse_name,
                    'stock_type': 'FBS',  # WB в основном использует FBS
                    'quantity_present': item.get('quantity', 0),
                    'quantity_reserved': item.get('inWayToClient', 0),  # Товары в пути к клиенту
                    'source': 'Wildberries'
                }
                stocks_data.append(stock_record)
                
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при получении остатков склада {warehouse_name}: {e}")
        
        logger.info(f"Получено {len(stocks_data)} остатков со склада {warehouse_name}")
        return stocks_data

    def _get_product_id_by_ozon_sku(self, sku_ozon: str) -> Optional[int]:
        """
        Получение product_id по SKU Ozon.
        
        Args:
            sku_ozon: SKU товара в Ozon
            
        Returns:
            int: ID товара в БД или None
        """
        if not sku_ozon:
            return None
            
        try:
            self.cursor.execute(
                "SELECT id FROM dim_products WHERE sku_ozon = %s",
                (sku_ozon,)
            )
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            logger.error(f"Ошибка при поиске товара по sku_ozon {sku_ozon}: {e}")
            return None

    def _get_product_id_by_wb_sku(self, sku_wb: str) -> Optional[int]:
        """
        Получение product_id по SKU Wildberries.
        
        Args:
            sku_wb: SKU товара в Wildberries
            
        Returns:
            int: ID товара в БД или None
        """
        if not sku_wb:
            return None
            
        try:
            self.cursor.execute(
                "SELECT id FROM dim_products WHERE sku_wb = %s",
                (str(sku_wb),)
            )
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            logger.error(f"Ошибка при поиске товара по sku_wb {sku_wb}: {e}")
            return None

    def update_inventory(self, inventory_data: List[Dict[str, Any]], source: str):
        """
        Обновление таблицы inventory с использованием UPSERT логики.
        
        Args:
            inventory_data: Список данных об остатках
            source: Источник данных ('Ozon' или 'Wildberries')
        """
        logger.info(f"🔄 Начинаем обновление остатков для источника {source}")
        
        try:
            # Сначала удаляем все старые записи для данного источника
            delete_query = "DELETE FROM inventory_data WHERE source = %s"
            self.cursor.execute(delete_query, (source,))
            deleted_count = self.cursor.rowcount
            logger.info(f"Удалено {deleted_count} старых записей для {source}")
            
            # Вставляем новые данные
            if inventory_data:
                insert_query = """
                INSERT INTO inventory_data 
                (product_id, warehouse_name, stock_type, quantity_present, quantity_reserved, source, last_sync_at)
                VALUES (%(product_id)s, %(warehouse_name)s, %(stock_type)s, 
                       %(quantity_present)s, %(quantity_reserved)s, %(source)s, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity_present = VALUES(quantity_present),
                    quantity_reserved = VALUES(quantity_reserved),
                    last_sync_at = NOW()
                """
                
                self.cursor.executemany(insert_query, inventory_data)
                inserted_count = self.cursor.rowcount
                
                self.connection.commit()
                logger.info(f"✅ Обновлено/вставлено {inserted_count} записей остатков для {source}")
            else:
                logger.warning(f"Нет данных для обновления остатков {source}")
                
        except Exception as e:
            logger.error(f"Ошибка при обновлении остатков {source}: {e}")
            self.connection.rollback()
            raise

    def run_inventory_update(self):
        """Основная функция запуска обновления остатков."""
        logger.info("🚀 Запуск обновления остатков товаров")
        
        try:
            self.connect_to_database()
            
            # Получаем и обновляем остатки Ozon
            logger.info("=" * 50)
            logger.info("ОБНОВЛЕНИЕ ОСТАТКОВ OZON")
            logger.info("=" * 50)
            
            ozon_inventory = self.get_ozon_inventory()
            self.update_inventory(ozon_inventory, 'Ozon')
            
            # Получаем и обновляем остатки Wildberries
            logger.info("=" * 50)
            logger.info("ОБНОВЛЕНИЕ ОСТАТКОВ WILDBERRIES")
            logger.info("=" * 50)
            
            wb_inventory = self.get_wb_inventory()
            self.update_inventory(wb_inventory, 'Wildberries')
            
            # Выводим итоговую статистику
            self._print_inventory_statistics()
            
            logger.info("✅ Обновление остатков завершено успешно")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при обновлении остатков: {e}")
            raise
        finally:
            self.close_database_connection()

    def _print_inventory_statistics(self):
        """Вывод статистики по остаткам."""
        logger.info("📊 СТАТИСТИКА ОСТАТКОВ:")
        logger.info("=" * 40)
        
        try:
            # Общая статистика
            self.cursor.execute("""
                SELECT 
                    source,
                    COUNT(*) as total_records,
                    COUNT(DISTINCT product_id) as unique_products,
                    SUM(quantity_present) as total_present,
                    SUM(quantity_reserved) as total_reserved
                FROM inventory_data 
                GROUP BY source
            """)
            
            stats = self.cursor.fetchall()
            
            for stat in stats:
                logger.info(f"{stat['source']}:")
                logger.info(f"  Записей: {stat['total_records']}")
                logger.info(f"  Уникальных товаров: {stat['unique_products']}")
                logger.info(f"  Доступно: {stat['total_present']}")
                logger.info(f"  Зарезервировано: {stat['total_reserved']}")
                logger.info("-" * 30)
                
        except Exception as e:
            logger.error(f"Ошибка при получении статистики: {e}")


def main():
    """Главная функция."""
    importer = StockImporter()
    importer.run_inventory_update()


if __name__ == "__main__":
    main()
