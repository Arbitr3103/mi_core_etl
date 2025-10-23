#!/usr/bin/env python3
"""
Улучшенный сервис синхронизации остатков с получением названий товаров.

Новые возможности:
- Автоматическое получение названий товаров для аналитических данных
- Обогащение данных из Ozon_Analytics читаемыми названиями
- Сохранение названий в таблицу product_names
- Fallback логика для товаров без названий

Автор: ETL System
Дата: 08 января 2025
"""

import os
import sys
import logging
import requests
import time
import json
from datetime import datetime, date
from typing import List, Dict, Any, Optional, Tuple
from dataclasses import dataclass
from enum import Enum

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    import config
    from inventory_data_validator import InventoryDataValidator, ValidationResult
    from sync_logger import SyncLogger, SyncType, SyncStatus as LogSyncStatus, ProcessingStats
    from product_name_resolver import ProductNameResolver
    import mysql.connector
    from dotenv import load_dotenv
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

# Загружаем переменные окружения
load_dotenv()

def connect_to_db():
    """Подключение к базе данных MySQL."""
    try:
        connection = mysql.connector.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            user=os.getenv('DB_USER', 'v_admin'),
            password=os.getenv('DB_PASSWORD'),
            database=os.getenv('DB_NAME', 'mi_core'),
            charset='utf8mb4',
            collation='utf8mb4_unicode_ci',
            autocommit=True
        )
        return connection
    except mysql.connector.Error as e:
        print(f"❌ Ошибка подключения к БД: {e}")
        raise

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class SyncStatus(Enum):
    """Статусы синхронизации."""
    SUCCESS = "success"
    PARTIAL = "partial"
    FAILED = "failed"


@dataclass
class InventoryRecord:
    """Модель записи об остатках товара для БД."""
    product_id: int
    sku: str
    source: str
    warehouse_name: str
    stock_type: str
    current_stock: int
    reserved_stock: int
    available_stock: int
    quantity_present: int
    quantity_reserved: int
    snapshot_date: date
    product_name: Optional[str] = None  # Добавляем поле для названия
    
    def __post_init__(self):
        """Валидация и нормализация данных после создания."""
        self.current_stock = max(0, int(self.current_stock or 0))
        self.reserved_stock = max(0, int(self.reserved_stock or 0))
        self.available_stock = max(0, int(self.available_stock or 0))
        self.quantity_present = max(0, int(self.quantity_present or 0))
        self.quantity_reserved = max(0, int(self.quantity_reserved or 0))
        
        # Если available_stock не задан, вычисляем его
        if self.available_stock == 0 and self.current_stock > 0:
            self.available_stock = max(0, self.current_stock - self.reserved_stock)


class InventorySyncServiceWithNames:
    """Сервис синхронизации остатков с получением названий товаров."""
    
    def __init__(self):
        """Инициализация сервиса."""
        self.connection = connect_to_db()
        self.validator = InventoryDataValidator()
        self.sync_logger = SyncLogger(self.connection)
        self.name_resolver = ProductNameResolver()  # Новый сервис для названий
        
        # Настройки API
        self.ozon_client_id = config.OZON_CLIENT_ID
        self.ozon_api_key = config.OZON_API_KEY
        self.wb_api_key = config.WB_API_KEY
        
        # Базовые URL
        self.ozon_base_url = "https://api-seller.ozon.ru"
        self.wb_base_url = "https://statistics-api.wildberries.ru"
        
        # Настройки для rate limiting
        self.request_delay = 0.5
        self.last_request_time = 0
        
        # Статистика
        self.stats = {
            'total_processed': 0,
            'total_inserted': 0,
            'total_updated': 0,
            'total_failed': 0,
            'names_resolved': 0,
            'names_failed': 0
        }
    
    def _rate_limit(self):
        """Контроль частоты запросов."""
        current_time = time.time()
        time_since_last = current_time - self.last_request_time
        if time_since_last < self.request_delay:
            time.sleep(self.request_delay - time_since_last)
        self.last_request_time = time.time()
    
    def sync_ozon_inventory_with_names(self) -> Dict[str, Any]:
        """
        Синхронизация остатков Ozon с получением названий товаров.
        
        Returns:
            Результат синхронизации с статистикой
        """
        logger.info("🚀 Начинаем синхронизацию Ozon с получением названий товаров")
        
        start_time = datetime.now()
        sync_id = self.sync_logger.start_sync(SyncType.INVENTORY, "Ozon")
        
        try:
            # 1. Получаем основные остатки через v4 API
            main_stocks = self._get_ozon_stocks_v4()
            logger.info(f"Получено {len(main_stocks)} записей из основного API")
            
            # 2. Получаем аналитические данные
            analytics_stocks = self._get_ozon_analytics_stocks()
            logger.info(f"Получено {len(analytics_stocks)} записей из аналитического API")
            
            # 3. Обогащаем аналитические данные названиями товаров
            enriched_analytics = self._enrich_analytics_with_names(analytics_stocks)
            logger.info(f"Обогащено названиями {len(enriched_analytics)} записей")
            
            # 4. Объединяем и нормализуем данные
            all_records = self._merge_ozon_data(main_stocks, enriched_analytics)
            logger.info(f"Объединено {len(all_records)} записей")
            
            # 5. Валидируем данные
            validation_result = self.validator.validate_inventory_records(all_records)
            if not validation_result.is_valid:
                logger.warning(f"Найдены проблемы валидации: {len(validation_result.errors)} ошибок")
            
            # 6. Сохраняем в БД
            save_result = self._save_inventory_records(all_records, "Ozon")
            
            # 7. Логируем результат
            end_time = datetime.now()
            duration = (end_time - start_time).total_seconds()
            
            processing_stats = ProcessingStats(
                records_processed=len(all_records),
                records_inserted=save_result['inserted'],
                records_updated=save_result['updated'],
                records_failed=save_result['failed'],
                validation_errors=len(validation_result.errors),
                duration_seconds=duration
            )
            
            self.sync_logger.complete_sync(
                sync_id, 
                LogSyncStatus.SUCCESS if save_result['failed'] == 0 else LogSyncStatus.PARTIAL,
                processing_stats
            )
            
            result = {
                'status': 'success',
                'records_processed': len(all_records),
                'records_inserted': save_result['inserted'],
                'records_updated': save_result['updated'],
                'records_failed': save_result['failed'],
                'names_resolved': self.stats['names_resolved'],
                'names_failed': self.stats['names_failed'],
                'duration_seconds': duration,
                'validation_errors': len(validation_result.errors)
            }
            
            logger.info(f"✅ Синхронизация Ozon завершена: {result}")
            return result
            
        except Exception as e:
            logger.error(f"❌ Ошибка синхронизации Ozon: {e}")
            
            self.sync_logger.complete_sync(
                sync_id,
                LogSyncStatus.FAILED,
                ProcessingStats(records_processed=0, records_failed=1, duration_seconds=0),
                str(e)
            )
            
            return {
                'status': 'failed',
                'error': str(e),
                'records_processed': 0,
                'records_inserted': 0,
                'records_updated': 0,
                'records_failed': 1
            }
    
    def _get_ozon_stocks_v4(self) -> List[Dict]:
        """Получает остатки через Ozon v4 API."""
        try:
            self._rate_limit()
            
            url = f"{self.ozon_base_url}/v4/product/info/stocks"
            headers = {
                'Client-Id': self.ozon_client_id,
                'Api-Key': self.ozon_api_key,
                'Content-Type': 'application/json'
            }
            
            payload = {
                "filter": {
                    "visibility": "ALL"
                },
                "limit": 1000
            }
            
            response = requests.post(url, headers=headers, json=payload)
            response.raise_for_status()
            
            data = response.json()
            
            if not data.get('result') or not data['result'].get('items'):
                logger.warning("Нет данных в ответе v4 API")
                return []
            
            stocks = []
            for item in data['result']['items']:
                product_id = item.get('id', 0)
                offer_id = item.get('offer_id', '')
                
                # Обрабатываем остатки по складам
                for stock in item.get('stocks', []):
                    stocks.append({
                        'product_id': product_id,
                        'offer_id': offer_id,
                        'sku': stock.get('sku', offer_id),
                        'warehouse_id': stock.get('warehouse_id', 0),
                        'warehouse_name': stock.get('warehouse_name', 'Unknown'),
                        'present': stock.get('present', 0),
                        'reserved': stock.get('reserved', 0),
                        'stock_type': 'FBO'  # Основной API обычно FBO
                    })
            
            logger.info(f"Получено {len(stocks)} записей из v4 API")
            return stocks
            
        except Exception as e:
            logger.error(f"Ошибка получения данных v4 API: {e}")
            return []
    
    def _get_ozon_analytics_stocks(self) -> List[Dict]:
        """Получает аналитические данные об остатках."""
        try:
            self._rate_limit()
            
            url = f"{self.ozon_base_url}/v2/analytics/stock_on_warehouses"
            headers = {
                'Client-Id': self.ozon_client_id,
                'Api-Key': self.ozon_api_key,
                'Content-Type': 'application/json'
            }
            
            payload = {
                "limit": 1000,
                "offset": 0
            }
            
            response = requests.post(url, headers=headers, json=payload)
            response.raise_for_status()
            
            data = response.json()
            
            if not data.get('result') or not data['result'].get('rows'):
                logger.warning("Нет данных в аналитическом API")
                return []
            
            stocks = []
            for row in data['result']['rows']:
                stocks.append({
                    'product_id': 0,  # Аналитический API не возвращает product_id
                    'offer_id': row.get('sku', ''),
                    'sku': row.get('sku', ''),
                    'warehouse_id': 0,
                    'warehouse_name': row.get('warehouse_name', 'Analytics'),
                    'present': row.get('free_to_sell_amount', 0),
                    'reserved': row.get('reserved_amount', 0),
                    'promised': row.get('promised_amount', 0),
                    'stock_type': 'Analytics'
                })
            
            logger.info(f"Получено {len(stocks)} записей из аналитического API")
            return stocks
            
        except Exception as e:
            logger.error(f"Ошибка получения аналитических данных: {e}")
            return []
    
    def _enrich_analytics_with_names(self, analytics_stocks: List[Dict]) -> List[Dict]:
        """
        Обогащает аналитические данные названиями товаров.
        
        Args:
            analytics_stocks: Список записей из аналитического API
            
        Returns:
            Обогащенные записи с названиями товаров
        """
        if not analytics_stocks:
            return []
        
        logger.info(f"Начинаем обогащение {len(analytics_stocks)} записей названиями")
        
        try:
            # Извлекаем уникальные SKU
            skus = list(set(record.get('sku', '') for record in analytics_stocks if record.get('sku')))
            logger.info(f"Найдено {len(skus)} уникальных SKU для обогащения")
            
            # Получаем названия через сервис
            sku_names = self.name_resolver.batch_resolve_names(skus)
            self.stats['names_resolved'] = len(sku_names)
            self.stats['names_failed'] = len(skus) - len(sku_names)
            
            logger.info(f"Получено названий: {len(sku_names)} из {len(skus)}")
            
            # Обогащаем записи
            enriched_records = []
            for record in analytics_stocks:
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
            
            logger.info(f"Обогащено {len(enriched_records)} записей названиями")
            return enriched_records
            
        except Exception as e:
            logger.error(f"Ошибка обогащения названиями: {e}")
            # Возвращаем исходные данные с fallback названиями
            for record in analytics_stocks:
                sku = record.get('sku', '')
                if sku.isdigit():
                    record['product_name'] = f"Товар артикул {sku}"
                else:
                    record['product_name'] = sku
            return analytics_stocks
    
    def _merge_ozon_data(self, main_stocks: List[Dict], analytics_stocks: List[Dict]) -> List[InventoryRecord]:
        """Объединяет данные из основного и аналитического API."""
        records = []
        today = date.today()
        
        # Обрабатываем основные данные
        for stock in main_stocks:
            record = InventoryRecord(
                product_id=stock.get('product_id', 0),
                sku=stock.get('sku', ''),
                source='Ozon',
                warehouse_name=stock.get('warehouse_name', 'Main'),
                stock_type=stock.get('stock_type', 'FBO'),
                current_stock=stock.get('present', 0),
                reserved_stock=stock.get('reserved', 0),
                available_stock=max(0, stock.get('present', 0) - stock.get('reserved', 0)),
                quantity_present=stock.get('present', 0),
                quantity_reserved=stock.get('reserved', 0),
                snapshot_date=today,
                product_name=stock.get('product_name')  # Может быть None для основных данных
            )
            records.append(record)
        
        # Обрабатываем аналитические данные
        for stock in analytics_stocks:
            record = InventoryRecord(
                product_id=0,  # Аналитические данные не имеют product_id
                sku=stock.get('sku', ''),
                source='Ozon_Analytics',
                warehouse_name=stock.get('warehouse_name', 'Analytics'),
                stock_type='Analytics',
                current_stock=stock.get('present', 0),
                reserved_stock=stock.get('reserved', 0),
                available_stock=max(0, stock.get('present', 0) - stock.get('reserved', 0)),
                quantity_present=stock.get('present', 0),
                quantity_reserved=stock.get('reserved', 0),
                snapshot_date=today,
                product_name=stock.get('product_name')  # Обогащенное название
            )
            records.append(record)
        
        return records
    
    def _save_inventory_records(self, records: List[InventoryRecord], source: str) -> Dict[str, int]:
        """Сохраняет записи об остатках в БД."""
        if not records:
            return {'inserted': 0, 'updated': 0, 'failed': 0}
        
        cursor = self.connection.cursor()
        inserted = updated = failed = 0
        
        try:
            # Очищаем старые данные для источника
            cursor.execute(
                "DELETE FROM inventory_data WHERE source IN (%s, %s)",
                (source, f"{source}_Analytics")
            )
            
            # Подготавливаем запрос для вставки
            insert_query = """
                INSERT INTO inventory_data (
                    product_id, sku, source, warehouse_name, stock_type,
                    current_stock, reserved_stock, available_stock,
                    quantity_present, quantity_reserved, snapshot_date, last_sync_at
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
            """
            
            # Вставляем записи
            for record in records:
                try:
                    cursor.execute(insert_query, (
                        record.product_id,
                        record.sku,
                        record.source,
                        record.warehouse_name,
                        record.stock_type,
                        record.current_stock,
                        record.reserved_stock,
                        record.available_stock,
                        record.quantity_present,
                        record.quantity_reserved,
                        record.snapshot_date
                    ))
                    inserted += 1
                    
                    # Сохраняем название товара если есть
                    if record.product_name and record.sku:
                        self._save_product_name(record.product_id, record.sku, record.product_name, record.source)
                    
                except Exception as e:
                    logger.error(f"Ошибка вставки записи {record.sku}: {e}")
                    failed += 1
            
            self.connection.commit()
            
        except Exception as e:
            logger.error(f"Ошибка сохранения данных: {e}")
            self.connection.rollback()
            failed = len(records)
        finally:
            cursor.close()
        
        return {'inserted': inserted, 'updated': updated, 'failed': failed}
    
    def _save_product_name(self, product_id: int, sku: str, name: str, source: str):
        """Сохраняет название товара в таблицу product_names."""
        try:
            cursor = self.connection.cursor()
            
            query = """
                INSERT INTO product_names (product_id, sku, product_name, source, created_at, updated_at)
                VALUES (%s, %s, %s, %s, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                product_name = VALUES(product_name),
                updated_at = NOW()
            """
            
            cursor.execute(query, (product_id, sku, name, source))
            cursor.close()
            
        except Exception as e:
            logger.error(f"Ошибка сохранения названия для SKU {sku}: {e}")
    
    def close(self):
        """Закрывает соединения."""
        if self.name_resolver:
            self.name_resolver.close()
        if self.connection:
            self.connection.close()

def main():
    """Тестирование сервиса."""
    service = InventorySyncServiceWithNames()
    
    try:
        result = service.sync_ozon_inventory_with_names()
        print(f"Результат синхронизации: {result}")
        
    finally:
        service.close()

if __name__ == "__main__":
    main()