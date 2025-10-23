#!/usr/bin/env python3
"""
Оптимизированный сервис синхронизации остатков товаров с маркетплейсами.

Улучшения производительности:
- Пакетная обработка записей
- Оптимизированные UPSERT операции
- Кэширование данных о товарах
- Параллельная обработка API запросов

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import logging
import asyncio
import aiohttp
import time
from datetime import datetime, date
from typing import List, Dict, Any, Optional, Tuple, Set
from dataclasses import dataclass
from enum import Enum
from concurrent.futures import ThreadPoolExecutor, as_completed
import threading
from collections import defaultdict

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from importers.ozon_importer import connect_to_db
    import config
    from inventory_data_validator import InventoryDataValidator, ValidationResult
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

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
    """Модель записи об остатках товара."""
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
    
    def __post_init__(self):
        """Валидация и нормализация данных после создания."""
        self.current_stock = max(0, int(self.current_stock or 0))
        self.reserved_stock = max(0, int(self.reserved_stock or 0))
        self.available_stock = max(0, int(self.available_stock or 0))
        self.quantity_present = max(0, int(self.quantity_present or 0))
        self.quantity_reserved = max(0, int(self.quantity_reserved or 0))
        
        if self.available_stock == 0 and self.current_stock > 0:
            self.available_stock = max(0, self.current_stock - self.reserved_stock)


@dataclass
class SyncResult:
    """Результат синхронизации."""
    source: str
    status: SyncStatus
    records_processed: int
    records_updated: int
    records_inserted: int
    records_failed: int
    started_at: datetime
    completed_at: Optional[datetime] = None
    error_message: Optional[str] = None
    api_requests_count: int = 0
    cache_hits: int = 0
    
    @property
    def duration_seconds(self) -> int:
        """Длительность выполнения в секундах."""
        if self.completed_at:
            return int((self.completed_at - self.started_at).total_seconds())
        return 0


class ProductCache:
    """Кэш для быстрого поиска товаров по SKU."""
    
    def __init__(self):
        self._ozon_cache: Dict[str, int] = {}
        self._wb_cache: Dict[str, int] = {}
        self._barcode_cache: Dict[str, int] = {}
        self._lock = threading.Lock()
        self._loaded = False
    
    def load_cache(self, cursor) -> None:
        """Загрузка кэша из базы данных."""
        with self._lock:
            if self._loaded:
                return
            
            logger.info("🔄 Загружаем кэш товаров...")
            
            # Загружаем все товары одним запросом
            cursor.execute("""
                SELECT id, sku_ozon, sku_wb, barcode 
                FROM dim_products 
                WHERE sku_ozon IS NOT NULL OR sku_wb IS NOT NULL OR barcode IS NOT NULL
            """)
            
            products = cursor.fetchall()
            
            for product in products:
                product_id = product['id']
                
                if product['sku_ozon']:
                    self._ozon_cache[product['sku_ozon']] = product_id
                
                if product['sku_wb']:
                    self._wb_cache[str(product['sku_wb'])] = product_id
                
                if product['barcode']:
                    self._barcode_cache[product['barcode']] = product_id
            
            self._loaded = True
            logger.info(f"✅ Кэш загружен: Ozon={len(self._ozon_cache)}, WB={len(self._wb_cache)}, Barcode={len(self._barcode_cache)}")
    
    def get_product_id_by_ozon_sku(self, sku: str) -> Optional[int]:
        """Получение product_id по SKU Ozon из кэша."""
        return self._ozon_cache.get(sku)
    
    def get_product_id_by_wb_sku(self, sku: str) -> Optional[int]:
        """Получение product_id по SKU Wildberries из кэша."""
        return self._wb_cache.get(str(sku))
    
    def get_product_id_by_barcode(self, barcode: str) -> Optional[int]:
        """Получение product_id по штрихкоду из кэша."""
        return self._barcode_cache.get(barcode)
    
    def clear_cache(self) -> None:
        """Очистка кэша."""
        with self._lock:
            self._ozon_cache.clear()
            self._wb_cache.clear()
            self._barcode_cache.clear()
            self._loaded = False


class OptimizedInventorySyncService:
    """Оптимизированный сервис синхронизации остатков товаров."""
    
    def __init__(self, batch_size: int = 1000, max_workers: int = 4):
        """
        Инициализация сервиса.
        
        Args:
            batch_size: Размер батча для обработки данных
            max_workers: Максимальное количество потоков для параллельной обработки
        """
        self.connection = None
        self.cursor = None
        self.validator = InventoryDataValidator()
        self.product_cache = ProductCache()
        self.batch_size = batch_size
        self.max_workers = max_workers
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            
            # Загружаем кэш товаров
            self.product_cache.load_cache(self.cursor)
            
            logger.info("✅ Успешное подключение к базе данных")
        except Exception as e:
            logger.error(f"❌ Ошибка подключения к БД: {e}")
            raise
    
    def close_database_connection(self):
        """Закрытие подключения к базе данных."""
        if self.cursor:
            self.cursor.close()
        if self.connection:
            self.connection.close()
        logger.info("🔌 Подключение к БД закрыто")

    def batch_upsert_inventory_data(self, inventory_records: List[InventoryRecord], source: str) -> Tuple[int, int, int]:
        """
        Оптимизированное пакетное обновление таблицы inventory_data.
        
        Args:
            inventory_records: Список записей об остатках
            source: Источник данных ('Ozon' или 'Wildberries')
            
        Returns:
            Tuple[int, int, int]: (обновлено, вставлено, ошибок)
        """
        logger.info(f"🔄 Начинаем пакетное обновление inventory_data для источника {source}")
        
        if not inventory_records:
            logger.warning(f"⚠️ Нет данных для обновления остатков {source}")
            return 0, 0, 0
        
        updated_count = 0
        inserted_count = 0
        failed_count = 0
        
        try:
            # Сначала удаляем все старые записи для данного источника и даты
            today = date.today()
            delete_query = """
                DELETE FROM inventory_data 
                WHERE source = %s AND snapshot_date = %s
            """
            self.cursor.execute(delete_query, (source, today))
            deleted_count = self.cursor.rowcount
            logger.info(f"🗑️ Удалено {deleted_count} старых записей для {source} за {today}")
            
            # Подготавливаем оптимизированный запрос для пакетной вставки
            insert_query = """
                INSERT INTO inventory_data 
                (product_id, sku, source, warehouse_name, stock_type, 
                 snapshot_date, current_stock, reserved_stock, available_stock,
                 quantity_present, quantity_reserved, last_sync_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
            """
            
            # Обрабатываем данные батчами для оптимальной производительности
            total_batches = (len(inventory_records) + self.batch_size - 1) // self.batch_size
            
            for batch_num in range(total_batches):
                start_idx = batch_num * self.batch_size
                end_idx = min(start_idx + self.batch_size, len(inventory_records))
                batch = inventory_records[start_idx:end_idx]
                
                batch_data = []
                
                for record in batch:
                    try:
                        record_tuple = (
                            record.product_id,
                            record.sku,
                            record.source,
                            record.warehouse_name,
                            record.stock_type,
                            record.snapshot_date,
                            record.current_stock,
                            record.reserved_stock,
                            record.available_stock,
                            record.quantity_present,
                            record.quantity_reserved
                        )
                        batch_data.append(record_tuple)
                    except Exception as e:
                        logger.error(f"❌ Ошибка подготовки записи: {e}")
                        failed_count += 1
                
                if batch_data:
                    try:
                        # Используем executemany для пакетной вставки
                        self.cursor.executemany(insert_query, batch_data)
                        inserted_count += len(batch_data)
                        
                        # Логируем прогресс каждые 10 батчей
                        if (batch_num + 1) % 10 == 0 or batch_num == total_batches - 1:
                            logger.info(f"✅ Обработано батчей: {batch_num + 1}/{total_batches}, "
                                       f"вставлено записей: {inserted_count}")
                        
                    except Exception as e:
                        logger.error(f"❌ Ошибка вставки батча {batch_num + 1}: {e}")
                        failed_count += len(batch_data)
            
            self.connection.commit()
            logger.info(f"✅ Пакетное обновление inventory_data завершено: "
                       f"вставлено {inserted_count}, ошибок {failed_count}")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при пакетном обновлении inventory_data: {e}")
            self.connection.rollback()
            raise
        
        return updated_count, inserted_count, failed_count

    def process_inventory_batch(self, items: List[Dict], source: str) -> List[InventoryRecord]:
        """
        Параллельная обработка батча данных об остатках.
        
        Args:
            items: Список элементов API ответа
            source: Источник данных
            
        Returns:
            List[InventoryRecord]: Обработанные записи
        """
        inventory_records = []
        cache_hits = 0
        
        # Разделяем данные на чанки для параллельной обработки
        chunk_size = max(1, len(items) // self.max_workers)
        chunks = [items[i:i + chunk_size] for i in range(0, len(items), chunk_size)]
        
        def process_chunk(chunk: List[Dict]) -> Tuple[List[InventoryRecord], int]:
            """Обработка чанка данных."""
            chunk_records = []
            chunk_cache_hits = 0
            
            for item in chunk:
                try:
                    if source == 'Ozon':
                        records, hits = self._process_ozon_item(item)
                    else:  # Wildberries
                        records, hits = self._process_wb_item(item)
                    
                    chunk_records.extend(records)
                    chunk_cache_hits += hits
                    
                except Exception as e:
                    logger.error(f"❌ Ошибка обработки элемента: {e}")
            
            return chunk_records, chunk_cache_hits
        
        # Параллельная обработка чанков
        with ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            future_to_chunk = {executor.submit(process_chunk, chunk): chunk for chunk in chunks}
            
            for future in as_completed(future_to_chunk):
                try:
                    chunk_records, chunk_cache_hits = future.result()
                    inventory_records.extend(chunk_records)
                    cache_hits += chunk_cache_hits
                except Exception as e:
                    logger.error(f"❌ Ошибка обработки чанка: {e}")
        
        logger.info(f"📊 Обработано {len(inventory_records)} записей, попаданий в кэш: {cache_hits}")
        return inventory_records

    def _process_ozon_item(self, item: Dict) -> Tuple[List[InventoryRecord], int]:
        """Обработка элемента Ozon API."""
        records = []
        cache_hits = 0
        
        offer_id = item.get('offer_id', '')
        product_id = self.product_cache.get_product_id_by_ozon_sku(offer_id)
        
        if product_id:
            cache_hits += 1
        else:
            logger.warning(f"Товар с offer_id {offer_id} не найден в кэше")
            return records, cache_hits
        
        # Обрабатываем остатки по складам
        stocks = item.get('stocks', [])
        if not stocks:
            stocks = [{'warehouse_name': 'Ozon Main', 'type': 'FBO', 'present': 0, 'reserved': 0}]
        
        for stock in stocks:
            warehouse_name = stock.get('warehouse_name', 'Ozon Main')
            stock_type = stock.get('type', 'FBO')
            quantity_present = max(0, int(stock.get('present', 0)))
            quantity_reserved = max(0, int(stock.get('reserved', 0)))
            
            inventory_record = InventoryRecord(
                product_id=product_id,
                sku=offer_id,
                source='Ozon',
                warehouse_name=warehouse_name,
                stock_type=stock_type,
                current_stock=quantity_present,
                reserved_stock=quantity_reserved,
                available_stock=max(0, quantity_present - quantity_reserved),
                quantity_present=quantity_present,
                quantity_reserved=quantity_reserved,
                snapshot_date=date.today()
            )
            
            records.append(inventory_record)
        
        return records, cache_hits

    def _process_wb_item(self, item: Dict) -> Tuple[List[InventoryRecord], int]:
        """Обработка элемента Wildberries API."""
        records = []
        cache_hits = 0
        
        barcode = item.get('barcode', '')
        nm_id = item.get('nmId', '')
        
        # Ищем товар в кэше
        product_id = None
        if barcode:
            product_id = self.product_cache.get_product_id_by_barcode(barcode)
        if not product_id and nm_id:
            product_id = self.product_cache.get_product_id_by_wb_sku(str(nm_id))
        
        if product_id:
            cache_hits += 1
        else:
            logger.warning(f"Товар с barcode {barcode} или nmId {nm_id} не найден в кэше")
            return records, cache_hits
        
        # Извлекаем данные об остатках
        warehouse_name = item.get('warehouseName', 'WB Main')
        quantity_present = max(0, int(item.get('quantity', 0)))
        quantity_reserved = max(0, int(item.get('inWayToClient', 0)))
        
        inventory_record = InventoryRecord(
            product_id=product_id,
            sku=barcode or str(nm_id),
            source='Wildberries',
            warehouse_name=warehouse_name,
            stock_type='FBS',  # WB использует FBS модель
            current_stock=quantity_present,
            reserved_stock=quantity_reserved,
            available_stock=max(0, quantity_present - quantity_reserved),
            quantity_present=quantity_present,
            quantity_reserved=quantity_reserved,
            snapshot_date=date.today()
        )
        
        records.append(inventory_record)
        return records, cache_hits

    async def fetch_ozon_inventory_async(self) -> List[Dict]:
        """Асинхронное получение остатков с Ozon API."""
        all_items = []
        
        async with aiohttp.ClientSession() as session:
            url = f"{config.OZON_API_BASE_URL}/v3/product/info/stocks"
            headers = {
                "Client-Id": config.OZON_CLIENT_ID,
                "Api-Key": config.OZON_API_KEY,
                "Content-Type": "application/json"
            }
            
            offset = 0
            limit = 1000
            
            while True:
                payload = {
                    "filter": {},
                    "last_id": "",
                    "limit": limit
                }
                
                try:
                    async with session.post(url, json=payload, headers=headers, timeout=30) as response:
                        response.raise_for_status()
                        data = await response.json()
                        items = data.get('result', {}).get('items', [])
                        
                        if not items:
                            break
                        
                        all_items.extend(items)
                        logger.info(f"Получено {len(items)} товаров с Ozon (всего: {len(all_items)})")
                        
                        if len(items) < limit:
                            break
                        
                        offset += limit
                        await asyncio.sleep(config.OZON_REQUEST_DELAY)
                        
                except Exception as e:
                    logger.error(f"Ошибка запроса к Ozon API: {e}")
                    break
        
        return all_items

    def sync_ozon_inventory_optimized(self) -> SyncResult:
        """Оптимизированная синхронизация остатков с Ozon."""
        started_at = datetime.now()
        logger.info("🚀 Начинаем оптимизированную синхронизацию остатков с Ozon...")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        cache_hits = 0
        
        try:
            # Асинхронно получаем все данные
            loop = asyncio.new_event_loop()
            asyncio.set_event_loop(loop)
            items = loop.run_until_complete(self.fetch_ozon_inventory_async())
            loop.close()
            
            records_processed = len(items)
            logger.info(f"📦 Получено {records_processed} товаров с Ozon API")
            
            if items:
                # Параллельно обрабатываем данные
                inventory_records = self.process_inventory_batch(items, 'Ozon')
                cache_hits = len([r for r in inventory_records if r.product_id])
                
                # Валидируем данные
                validation_result = self.validator.validate_inventory_data(
                    [r.__dict__ for r in inventory_records], 'Ozon'
                )
                
                # Фильтруем валидные записи
                valid_records = [r for r in inventory_records if r.product_id]
                
                if valid_records:
                    # Пакетно сохраняем в БД
                    updated, inserted, failed = self.batch_upsert_inventory_data(valid_records, 'Ozon')
                    records_inserted = inserted
                    records_failed = failed + (len(inventory_records) - len(valid_records))
                    
                    logger.info(f"✅ Оптимизированная синхронизация Ozon завершена: "
                               f"обработано {records_processed}, вставлено {records_inserted}, "
                               f"ошибок {records_failed}, попаданий в кэш {cache_hits}")
                else:
                    logger.error("❌ Нет валидных данных для сохранения в БД")
                    records_failed = records_processed
            
            return SyncResult(
                source='Ozon',
                status=SyncStatus.SUCCESS if records_failed == 0 else SyncStatus.PARTIAL,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                cache_hits=cache_hits
            )
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка оптимизированной синхронизации Ozon: {e}")
            return SyncResult(
                source='Ozon',
                status=SyncStatus.FAILED,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                error_message=str(e),
                cache_hits=cache_hits
            )

    def refresh_stats_cache(self) -> None:
        """Обновление кэша статистики."""
        try:
            self.cursor.callproc('RefreshInventoryStatsCache')
            self.connection.commit()
            logger.info("✅ Кэш статистики обновлен")
        except Exception as e:
            logger.error(f"❌ Ошибка обновления кэша статистики: {e}")

    def analyze_performance(self) -> Dict[str, Any]:
        """Анализ производительности системы."""
        try:
            self.cursor.callproc('AnalyzeInventoryQueryPerformance')
            
            # Получаем результаты анализа
            results = []
            for result in self.cursor.stored_results():
                results.extend(result.fetchall())
            
            return {
                'analysis_time': datetime.now(),
                'results': results
            }
        except Exception as e:
            logger.error(f"❌ Ошибка анализа производительности: {e}")
            return {'error': str(e)}

    def run_optimized_full_sync(self) -> Dict[str, SyncResult]:
        """Запуск оптимизированной полной синхронизации."""
        logger.info("🚀 Запуск оптимизированной полной синхронизации остатков")
        
        results = {}
        
        try:
            self.connect_to_database()
            
            # Синхронизация Ozon
            logger.info("=" * 50)
            logger.info("ОПТИМИЗИРОВАННАЯ СИНХРОНИЗАЦИЯ ОСТАТКОВ OZON")
            logger.info("=" * 50)
            
            ozon_result = self.sync_ozon_inventory_optimized()
            results['Ozon'] = ozon_result
            
            # Обновляем кэш статистики после синхронизации
            self.refresh_stats_cache()
            
            # Анализируем производительность
            performance_analysis = self.analyze_performance()
            logger.info(f"📊 Анализ производительности: {len(performance_analysis.get('results', []))} метрик")
            
            logger.info("✅ Оптимизированная полная синхронизация остатков завершена")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при оптимизированной синхронизации: {e}")
            raise
        finally:
            self.close_database_connection()
        
        return results


if __name__ == "__main__":
    # Пример использования оптимизированного сервиса
    service = OptimizedInventorySyncService(batch_size=1000, max_workers=4)
    results = service.run_optimized_full_sync()
    
    for source, result in results.items():
        print(f"\n{source} синхронизация:")
        print(f"  Статус: {result.status.value}")
        print(f"  Обработано: {result.records_processed}")
        print(f"  Вставлено: {result.records_inserted}")
        print(f"  Ошибок: {result.records_failed}")
        print(f"  Попаданий в кэш: {result.cache_hits}")
        print(f"  Время выполнения: {result.duration_seconds} сек")