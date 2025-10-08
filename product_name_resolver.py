#!/usr/bin/env python3
"""
Сервис для получения названий товаров по SKU через Ozon API
Решает проблему отображения числовых кодов вместо читаемых названий в дашборде
"""

import requests
import json
import time
import logging
from typing import Dict, List, Optional, Tuple
from dataclasses import dataclass
from config import OZON_CLIENT_ID, OZON_API_KEY
import mysql.connector
from mysql.connector import Error

# Настройка логирования
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

@dataclass
class ProductInfo:
    """Информация о товаре"""
    product_id: int
    sku: str
    name: str
    category_id: Optional[int] = None
    brand: Optional[str] = None

class ProductNameResolver:
    """Сервис для получения названий товаров по SKU"""
    
    def __init__(self, client_id: str = None, api_key: str = None):
        self.client_id = client_id or OZON_CLIENT_ID
        self.api_key = api_key or OZON_API_KEY
        self.base_url = "https://api-seller.ozon.ru"
        self.session = requests.Session()
        self.session.headers.update({
            'Client-Id': self.client_id,
            'Api-Key': self.api_key,
            'Content-Type': 'application/json'
        })
        
        # Кэш для избежания повторных запросов в рамках сессии
        self.name_cache: Dict[str, str] = {}
        
        # Настройки для rate limiting
        self.request_delay = 0.1  # 100ms между запросами
        self.last_request_time = 0
        
        # Подключение к БД
        self.db_connection = None
        self._connect_to_db()
    
    def _connect_to_db(self):
        """Подключение к базе данных"""
        try:
            from config_local import DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
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
            self.db_connection = None
    
    def _rate_limit(self):
        """Контроль частоты запросов"""
        current_time = time.time()
        time_since_last = current_time - self.last_request_time
        if time_since_last < self.request_delay:
            time.sleep(self.request_delay - time_since_last)
        self.last_request_time = time.time()
    
    def get_product_name_by_sku(self, sku: str) -> Optional[str]:
        """
        Получает название товара по SKU через API
        
        Args:
            sku: SKU товара (может быть числовым или текстовым)
            
        Returns:
            Название товара или None если не найдено
        """
        # Проверяем кэш
        if sku in self.name_cache:
            return self.name_cache[sku]
        
        # Проверяем БД
        cached_name = self._get_name_from_db(sku)
        if cached_name:
            self.name_cache[sku] = cached_name
            return cached_name
        
        # Запрашиваем через API
        try:
            self._rate_limit()
            
            # Используем API для получения информации о товаре по offer_id (SKU)
            url = f"{self.base_url}/v2/product/info"
            payload = {
                "offer_id": [str(sku)],
                "product_id": [],
                "sku": []
            }
            
            response = self.session.post(url, json=payload)
            response.raise_for_status()
            
            data = response.json()
            
            if data.get('result') and data['result'].get('items'):
                item = data['result']['items'][0]
                product_name = item.get('name', '')
                product_id = item.get('id', 0)
                
                if product_name:
                    # Сохраняем в кэш и БД
                    self.name_cache[sku] = product_name
                    self._save_name_to_db(product_id, sku, product_name)
                    logger.info(f"Получено название для SKU {sku}: {product_name}")
                    return product_name
            
            logger.warning(f"Название для SKU {sku} не найдено")
            return None
            
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка API запроса для SKU {sku}: {e}")
            return None
        except Exception as e:
            logger.error(f"Неожиданная ошибка при получении названия для SKU {sku}: {e}")
            return None
    
    def batch_resolve_names(self, skus: List[str], batch_size: int = 100) -> Dict[str, str]:
        """
        Пакетное получение названий для списка SKU
        
        Args:
            skus: Список SKU для обработки
            batch_size: Размер батча для API запроса
            
        Returns:
            Словарь {sku: название}
        """
        result = {}
        
        # Сначала проверяем кэш и БД
        remaining_skus = []
        for sku in skus:
            if sku in self.name_cache:
                result[sku] = self.name_cache[sku]
            else:
                cached_name = self._get_name_from_db(sku)
                if cached_name:
                    self.name_cache[sku] = cached_name
                    result[sku] = cached_name
                else:
                    remaining_skus.append(sku)
        
        logger.info(f"Из кэша/БД получено {len(result)} названий, осталось запросить {len(remaining_skus)}")
        
        # Обрабатываем оставшиеся SKU батчами
        for i in range(0, len(remaining_skus), batch_size):
            batch = remaining_skus[i:i + batch_size]
            batch_result = self._batch_api_request(batch)
            result.update(batch_result)
            
            # Пауза между батчами
            if i + batch_size < len(remaining_skus):
                time.sleep(1)
        
        return result
    
    def _batch_api_request(self, skus: List[str]) -> Dict[str, str]:
        """Выполняет батчевый запрос к API"""
        try:
            self._rate_limit()
            
            url = f"{self.base_url}/v2/product/info"
            payload = {
                "offer_id": [str(sku) for sku in skus],
                "product_id": [],
                "sku": []
            }
            
            response = self.session.post(url, json=payload)
            response.raise_for_status()
            
            data = response.json()
            result = {}
            
            if data.get('result') and data['result'].get('items'):
                for item in data['result']['items']:
                    offer_id = item.get('offer_id', '')
                    product_name = item.get('name', '')
                    product_id = item.get('id', 0)
                    
                    if offer_id and product_name:
                        result[offer_id] = product_name
                        self.name_cache[offer_id] = product_name
                        self._save_name_to_db(product_id, offer_id, product_name)
            
            logger.info(f"Получено {len(result)} названий из батча {len(skus)} SKU")
            return result
            
        except Exception as e:
            logger.error(f"Ошибка батчевого запроса: {e}")
            return {}
    
    def _get_name_from_db(self, sku: str) -> Optional[str]:
        """Получает название из БД"""
        if not self.db_connection:
            return None
        
        try:
            cursor = self.db_connection.cursor()
            cursor.execute(
                "SELECT product_name FROM product_names WHERE sku = %s LIMIT 1",
                (sku,)
            )
            result = cursor.fetchone()
            cursor.close()
            
            return result[0] if result else None
            
        except Exception as e:
            logger.error(f"Ошибка чтения из БД для SKU {sku}: {e}")
            return None
    
    def _save_name_to_db(self, product_id: int, sku: str, name: str):
        """Сохраняет название в БД"""
        if not self.db_connection:
            return
        
        try:
            cursor = self.db_connection.cursor()
            
            # Используем INSERT ... ON DUPLICATE KEY UPDATE
            query = """
                INSERT INTO product_names (product_id, sku, product_name, source, created_at, updated_at)
                VALUES (%s, %s, %s, 'Ozon_API', NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                product_name = VALUES(product_name),
                updated_at = NOW()
            """
            
            cursor.execute(query, (product_id, sku, name))
            self.db_connection.commit()
            cursor.close()
            
            logger.debug(f"Сохранено в БД: SKU {sku} -> {name}")
            
        except Exception as e:
            logger.error(f"Ошибка сохранения в БД для SKU {sku}: {e}")
    
    def resolve_names_for_analytics_data(self, analytics_records: List[Dict]) -> List[Dict]:
        """
        Обогащает аналитические данные названиями товаров
        
        Args:
            analytics_records: Список записей из аналитического API
            
        Returns:
            Обогащенные записи с добавленными названиями
        """
        # Извлекаем уникальные SKU
        skus = list(set(record.get('sku', '') for record in analytics_records if record.get('sku')))
        
        # Получаем названия
        sku_names = self.batch_resolve_names(skus)
        
        # Обогащаем записи
        enriched_records = []
        for record in analytics_records:
            sku = record.get('sku', '')
            enriched_record = record.copy()
            
            # Добавляем название товара
            if sku in sku_names:
                enriched_record['product_name'] = sku_names[sku]
            else:
                # Fallback для товаров без названий
                if sku.isdigit():
                    enriched_record['product_name'] = f"Товар артикул {sku}"
                else:
                    enriched_record['product_name'] = sku
            
            enriched_records.append(enriched_record)
        
        logger.info(f"Обогащено {len(enriched_records)} записей названиями товаров")
        return enriched_records
    
    def update_missing_names_for_inventory(self) -> Tuple[int, int]:
        """
        Обновляет названия для товаров в inventory_data с product_id = 0
        
        Returns:
            Кортеж (обработано_записей, получено_названий)
        """
        if not self.db_connection:
            logger.error("Нет подключения к БД")
            return 0, 0
        
        try:
            # Получаем уникальные SKU с product_id = 0
            cursor = self.db_connection.cursor()
            cursor.execute("""
                SELECT DISTINCT sku 
                FROM inventory_data 
                WHERE product_id = 0 
                AND current_stock > 0
                AND sku NOT IN (SELECT sku FROM product_names)
            """)
            
            skus = [row[0] for row in cursor.fetchall()]
            cursor.close()
            
            logger.info(f"Найдено {len(skus)} SKU без названий")
            
            if not skus:
                return 0, 0
            
            # Получаем названия
            sku_names = self.batch_resolve_names(skus)
            
            logger.info(f"Получено названий: {len(sku_names)} из {len(skus)}")
            
            return len(skus), len(sku_names)
            
        except Exception as e:
            logger.error(f"Ошибка обновления названий: {e}")
            return 0, 0
    
    def close(self):
        """Закрывает соединения"""
        if self.db_connection:
            self.db_connection.close()
        self.session.close()

def main():
    """Тестирование сервиса"""
    resolver = ProductNameResolver()
    
    try:
        # Тест получения одного названия
        test_sku = "257202054"
        name = resolver.get_product_name_by_sku(test_sku)
        print(f"Название для SKU {test_sku}: {name}")
        
        # Тест пакетного получения
        test_skus = ["257202054", "161875896", "161875313"]
        names = resolver.batch_resolve_names(test_skus)
        print(f"Пакетные названия: {names}")
        
        # Тест обновления недостающих названий
        processed, resolved = resolver.update_missing_names_for_inventory()
        print(f"Обработано SKU: {processed}, получено названий: {resolved}")
        
    finally:
        resolver.close()

if __name__ == "__main__":
    main()