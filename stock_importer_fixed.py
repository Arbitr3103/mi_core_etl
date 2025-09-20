#!/usr/bin/env python3
"""
ИСПРАВЛЕННЫЙ модуль импорта остатков товаров с маркетплейсов Ozon и Wildberries.

Функционал:
- Получение остатков товаров через правильные API эндпоинты
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

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    # Пробуем импортировать из текущей директории
    from importers.ozon_importer import connect_to_db, load_config
    import config
    connection = connect_to_db()
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    print("Убедитесь, что все необходимые модули доступны")
    sys.exit(1)

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

class StockImporter:
    def __init__(self):
        """Инициализация импортера остатков"""
        self.connection = None
        
    def get_ozon_inventory(self):
        """Получение остатков товаров с Ozon через правильный API"""
        logger.info("🔄 Начинаем получение остатков с Ozon...")
        
        # Правильный эндпоинт для получения остатков товаров
        url = f"{config.OZON_API_BASE_URL}/v2/products/stocks"
        headers = {
            "Client-Id": config.OZON_CLIENT_ID,
            "Api-Key": config.OZON_API_KEY,
            "Content-Type": "application/json"
        }
        
        inventory_data = []
        last_id = ""
        limit = 1000  # Максимальное количество товаров за один запрос
        
        try:
            while True:
                # Формируем тело запроса с пагинацией
                payload = {
                    "limit": limit,
                    "filter": {
                        "visibility": "ALL"
                    }
                }
                
                # Добавляем параметр last_id для пагинации, если он есть
                if last_id:
                    payload["last_id"] = last_id
                
                logger.info(f"Запрашиваем товары с last_id={last_id}, limit={limit}")
                response = requests.post(url, headers=headers, json=payload, timeout=(10, 60))
                response.raise_for_status()
                
                data = response.json()
                
                # Проверяем наличие данных в ответе
                if not data.get('result'):
                    logger.info("Нет данных в ответе API")
                    break
                
                # Получаем список товаров из ответа
                items = data['result'].get('items', [])
                logger.info(f"Получено {len(items)} товаров")
                
                # Обрабатываем каждый товар
                for item in items:
                    if not item.get('stocks'):
                        continue
                        
                    # Создаем запись об остатках для каждого товара
                    inventory_record = {
                        'product_id': str(item.get('product_id')),
                        'offer_id': item.get('offer_id', ''),
                        'name': item.get('name', 'Неизвестный товар'),
                        'warehouse_name': 'Ozon Warehouse',  # Ozon не возвращает название склада в этом эндпоинте
                        'stock_type': 'FBO',  # По умолчанию FBO
                        'quantity_present': item['stocks'].get('present', 0),
                        'quantity_reserved': item['stocks'].get('reserved', 0),
                        'quantity_coming': item['stocks'].get('coming', 0),
                        'source': 'Ozon'
                    }
                    
                    inventory_data.append(inventory_record)
                
                # Проверяем, есть ли еще данные
                last_id = data['result'].get('last_id')
                if not last_id or len(items) < limit:
                    logger.info("Достигнут конец списка товаров")
                    break
                
                # Небольшая пауза между запросами
                time.sleep(1)
                
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при запросе к Ozon API: {e}")
            raise
        
        logger.info(f"Получено {len(inventory_data)} записей остатков с Ozon")
        return inventory_data
    
    def get_wb_inventory(self):
        """Получение остатков товаров с Wildberries"""
        logger.info("🔄 Начинаем получение остатков с Wildberries...")
        
        # Для WB используем API складов
        url = f"{config.WB_API_BASE_URL}/api/v1/supplier/stocks"
        headers = {
            "Authorization": config.WB_API_KEY
        }
        
        inventory_data = []
        
        try:
            params = {
                'dateFrom': (datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)).isoformat()
            }
            
            response = requests.get(url, headers=headers, params=params, timeout=(10, 60))
            response.raise_for_status()
            
            data = response.json()
            
            for item in data:
                inventory_record = {
                    'product_id': None,  # Будем искать по штрихкоду
                    'barcode': item.get('barcode'),
                    'sku_wb': item.get('nmId'),
                    'warehouse_name': item.get('warehouseName', 'Склад WB'),
                    'stock_type': 'FBS',
                    'quantity_present': item.get('quantity', 0),
                    'quantity_reserved': item.get('inWayToClient', 0),
                    'quantity_coming': item.get('inWayFromClient', 0),
                    'source': 'Wildberries'
                }
                
                inventory_data.append(inventory_record)
                
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при запросе к WB API: {e}")
            raise
        
        logger.info(f"Получено {len(inventory_data)} записей остатков с WB")
        return inventory_data
    
    def load_inventory_to_db(self, inventory_data):
        """Загрузка остатков в базу данных"""
        if not inventory_data:
            logger.info("Нет данных для загрузки")
            return
        
        logger.info(f"Начинаем загрузку {len(inventory_data)} записей остатков в БД")
        
        self.connection = connect_to_db()
        cursor = self.connection.cursor()
        
        try:
            # Для Ozon товаров ищем product_id по offer_id
            ozon_records = [r for r in inventory_data if r['source'] == 'Ozon']
            wb_records = [r for r in inventory_data if r['source'] == 'Wildberries']
            
            # Обрабатываем Ozon записи
            for record in ozon_records:
                if record.get('offer_id'):
                    # Ищем product_id по offer_id (sku_ozon)
                    cursor.execute("SELECT id FROM dim_products WHERE sku_ozon = %s", (record['offer_id'],))
                    result = cursor.fetchone()
                    
                    if result:
                        product_id = result[0]
                        
                        # UPSERT запись об остатках
                        query = """
                        INSERT INTO inventory (product_id, warehouse_name, stock_type, quantity_present, quantity_reserved, source)
                        VALUES (%s, %s, %s, %s, %s, %s)
                        ON DUPLICATE KEY UPDATE
                            quantity_present = VALUES(quantity_present),
                            quantity_reserved = VALUES(quantity_reserved),
                            updated_at = CURRENT_TIMESTAMP
                        """
                        
                        cursor.execute(query, (
                            product_id,
                            record['warehouse_name'],
                            record['stock_type'],
                            record['quantity_present'],
                            record['quantity_reserved'],
                            record['source']
                        ))
            
            # Обрабатываем WB записи
            for record in wb_records:
                if record.get('barcode'):
                    # Ищем product_id по штрихкоду
                    cursor.execute("SELECT id FROM dim_products WHERE barcode = %s", (record['barcode'],))
                    result = cursor.fetchone()
                    
                    if result:
                        product_id = result[0]
                        
                        # UPSERT запись об остатках
                        query = """
                        INSERT INTO inventory (product_id, warehouse_name, stock_type, quantity_present, quantity_reserved, source)
                        VALUES (%s, %s, %s, %s, %s, %s)
                        ON DUPLICATE KEY UPDATE
                            quantity_present = VALUES(quantity_present),
                            quantity_reserved = VALUES(quantity_reserved),
                            updated_at = CURRENT_TIMESTAMP
                        """
                        
                        cursor.execute(query, (
                            product_id,
                            record['warehouse_name'],
                            record['stock_type'],
                            record['quantity_present'],
                            record['quantity_reserved'],
                            record['source']
                        ))
            
            self.connection.commit()
            logger.info(f"✅ Успешно загружено остатков в БД")
            
        except Exception as e:
            self.connection.rollback()
            logger.error(f"Ошибка при загрузке остатков в БД: {e}")
            raise
        finally:
            cursor.close()
    
    def run_inventory_update(self):
        """Запуск полного обновления остатков"""
        try:
            logger.info("🚀 Запуск обновления остатков товаров")
            
            # Получаем остатки с Ozon
            logger.info("=" * 50)
            logger.info("ОБНОВЛЕНИЕ ОСТАТКОВ OZON")
            logger.info("=" * 50)
            
            ozon_inventory = self.get_ozon_inventory()
            self.load_inventory_to_db(ozon_inventory)
            
            # Получаем остатки с WB
            logger.info("=" * 50)
            logger.info("ОБНОВЛЕНИЕ ОСТАТКОВ WILDBERRIES")
            logger.info("=" * 50)
            
            wb_inventory = self.get_wb_inventory()
            self.load_inventory_to_db(wb_inventory)
            
            logger.info("✅ Обновление остатков завершено успешно")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при обновлении остатков: {e}")
            raise
        finally:
            if self.connection:
                self.connection.close()
                logger.info("Подключение к БД закрыто")

def main():
    """Главная функция"""
    importer = StockImporter()
    importer.run_inventory_update()

if __name__ == "__main__":
    main()
