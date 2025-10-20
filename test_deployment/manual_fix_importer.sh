#!/bin/bash

echo "🔧 РУЧНОЕ ИСПРАВЛЕНИЕ ИМПОРТЕРА"

# Получаем права на файл
sudo chown vladimir:vladimir /var/www/mi_core_api/importers/stock_importer.py

# Создаем исправленную версию импортера
sudo tee /var/www/mi_core_api/importers/stock_importer_fixed.py << 'EOF'
#!/usr/bin/env python3
"""
Исправленный модуль импорта остатков товаров с маркетплейсов Ozon и Wildberries.
Работает с таблицей inventory (не inventory_data).
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
        """Получение остатков товаров с Ozon через API."""
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
                
                if cursor:
                    payload["cursor"] = cursor
                
                response = requests.post(url, json=payload, headers=headers)
                response.raise_for_status()
                
                data = response.json()
                
                if not data.get('items'):
                    break
                
                items = data['items']
                logger.info(f"Получено {len(items)} товаров с Ozon (cursor: {cursor[:20]}...)")
                
                for item in items:
                    product_id = self._get_product_id_by_ozon_sku(item.get('offer_id', ''))
                    
                    if not product_id:
                        logger.warning(f"Товар с offer_id {item.get('offer_id')} не найден в БД")
                        continue
                    
                    for stock in item.get('stocks', []):
                        warehouse_name = f"Ozon-{stock.get('type', 'FBO').upper()}"
                        if stock.get('warehouse_ids'):
                            warehouse_name += f"-{stock['warehouse_ids'][0]}"
                        
                        inventory_record = {
                            'product_id': product_id,
                            'warehouse_name': warehouse_name,
                            'stock_type': stock.get('type', 'fbo').upper(),
                            'quantity_present': stock.get('present', 0),
                            'quantity_reserved': stock.get('reserved', 0),
                            'source': 'Ozon'
                        }
                        inventory_data.append(inventory_record)
                
                cursor = data.get('cursor', '')
                if not cursor or len(items) < limit:
                    break
                
                time.sleep(0.1)
                
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка при запросе к Ozon API: {e}")
            raise
        except Exception as e:
            logger.error(f"Неожиданная ошибка при получении остатков Ozon: {e}")
            raise
        
        logger.info(f"✅ Получено {len(inventory_data)} записей остатков с Ozon")
        return inventory_data

    def _get_product_id_by_ozon_sku(self, sku_ozon: str) -> Optional[int]:
        """Получение product_id по SKU Ozon."""
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

    def update_inventory(self, inventory_data: List[Dict[str, Any]], source: str):
        """Обновление таблицы inventory с использованием UPSERT логики."""
        logger.info(f"🔄 Начинаем обновление остатков для источника {source}")
        
        try:
            # Удаляем старые записи для данного источника
            delete_query = "DELETE FROM inventory WHERE source = %s"
            self.cursor.execute(delete_query, (source,))
            deleted_count = self.cursor.rowcount
            logger.info(f"Удалено {deleted_count} старых записей для {source}")
            
            # Вставляем новые данные
            if inventory_data:
                insert_query = """
                INSERT INTO inventory 
                (product_id, warehouse_name, stock_type, quantity_present, quantity_reserved, source)
                VALUES (%(product_id)s, %(warehouse_name)s, %(stock_type)s, 
                       %(quantity_present)s, %(quantity_reserved)s, %(source)s)
                ON DUPLICATE KEY UPDATE
                    quantity_present = VALUES(quantity_present),
                    quantity_reserved = VALUES(quantity_reserved),
                    updated_at = CURRENT_TIMESTAMP
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
            self.cursor.execute("""
                SELECT 
                    source,
                    COUNT(*) as total_records,
                    COUNT(DISTINCT product_id) as unique_products,
                    SUM(quantity_present) as total_present,
                    SUM(quantity_reserved) as total_reserved
                FROM inventory 
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
EOF

# Заменяем старый импортер на исправленный
sudo mv /var/www/mi_core_api/importers/stock_importer.py /var/www/mi_core_api/importers/stock_importer_old.py
sudo mv /var/www/mi_core_api/importers/stock_importer_fixed.py /var/www/mi_core_api/importers/stock_importer.py
sudo chmod +x /var/www/mi_core_api/importers/stock_importer.py

echo "✅ Импортер заменен на исправленную версию"

echo "🔄 Запуск исправленного импортера..."
cd /var/www/mi_core_api
python3 importers/stock_importer.py

echo ""
echo "🌐 Проверьте дашборд: https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"