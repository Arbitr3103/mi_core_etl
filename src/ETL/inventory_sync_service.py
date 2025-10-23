#!/usr/bin/env python3
"""
Сервис синхронизации остатков товаров с маркетплейсами.

Исправленная версия для работы с таблицей inventory_data и новой схемой БД.
Поддерживает новые поля: warehouse_name, stock_type, quantity_present, quantity_reserved.

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import logging
import requests
import time
from datetime import datetime, date
from typing import List, Dict, Any, Optional, Tuple
from dataclasses import dataclass
from enum import Enum

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


class StockType(Enum):
    """Типы складов маркетплейсов."""
    FBO = "FBO"
    FBS = "FBS"
    REAL_FBS = "realFBS"


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
        # Обеспечиваем корректность типов
        self.current_stock = max(0, int(self.current_stock or 0))
        self.reserved_stock = max(0, int(self.reserved_stock or 0))
        self.available_stock = max(0, int(self.available_stock or 0))
        self.quantity_present = max(0, int(self.quantity_present or 0))
        self.quantity_reserved = max(0, int(self.quantity_reserved or 0))
        
        # Если available_stock не задан, вычисляем его
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
    
    @property
    def duration_seconds(self) -> int:
        """Длительность выполнения в секундах."""
        if self.completed_at:
            return int((self.completed_at - self.started_at).total_seconds())
        return 0


class InventorySyncService:
    """Сервис синхронизации остатков товаров с маркетплейсами."""
    
    def __init__(self):
        """Инициализация сервиса."""
        self.connection = None
        self.cursor = None
        self.validator = InventoryDataValidator()
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
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

    def get_product_id_by_ozon_sku(self, sku_ozon: str) -> Optional[int]:
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
            logger.error(f"❌ Ошибка при поиске товара по sku_ozon {sku_ozon}: {e}")
            return None

    def get_product_id_by_wb_sku(self, sku_wb: str) -> Optional[int]:
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
            logger.error(f"❌ Ошибка при поиске товара по sku_wb {sku_wb}: {e}")
            return None

    def get_product_id_by_barcode(self, barcode: str) -> Optional[int]:
        """
        Получение product_id по штрихкоду.
        
        Args:
            barcode: Штрихкод товара
            
        Returns:
            int: ID товара в БД или None
        """
        if not barcode:
            return None
            
        try:
            self.cursor.execute(
                "SELECT id FROM dim_products WHERE barcode = %s",
                (barcode,)
            )
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            logger.error(f"❌ Ошибка при поиске товара по barcode {barcode}: {e}")
            return None

    def update_inventory_data(self, inventory_records: List[InventoryRecord], source: str) -> Tuple[int, int, int]:
        """
        Обновление таблицы inventory_data с использованием UPSERT логики.
        
        Args:
            inventory_records: Список записей об остатках
            source: Источник данных ('Ozon' или 'Wildberries')
            
        Returns:
            Tuple[int, int, int]: (обновлено, вставлено, ошибок)
        """
        logger.info(f"🔄 Начинаем обновление inventory_data для источника {source}")
        
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
            
            # Подготавливаем запрос для вставки новых данных
            insert_query = """
                INSERT INTO inventory_data 
                (product_id, sku, source, warehouse_name, stock_type, 
                 snapshot_date, current_stock, reserved_stock, available_stock,
                 quantity_present, quantity_reserved, last_sync_at)
                VALUES (%(product_id)s, %(sku)s, %(source)s, %(warehouse_name)s, %(stock_type)s,
                       %(snapshot_date)s, %(current_stock)s, %(reserved_stock)s, %(available_stock)s,
                       %(quantity_present)s, %(quantity_reserved)s, NOW())
            """
            
            # Вставляем новые данные пакетами
            batch_size = 100
            for i in range(0, len(inventory_records), batch_size):
                batch = inventory_records[i:i + batch_size]
                batch_data = []
                
                for record in batch:
                    try:
                        record_data = {
                            'product_id': record.product_id,
                            'sku': record.sku,
                            'source': record.source,
                            'warehouse_name': record.warehouse_name,
                            'stock_type': record.stock_type,
                            'snapshot_date': record.snapshot_date,
                            'current_stock': record.current_stock,
                            'reserved_stock': record.reserved_stock,
                            'available_stock': record.available_stock,
                            'quantity_present': record.quantity_present,
                            'quantity_reserved': record.quantity_reserved
                        }
                        batch_data.append(record_data)
                    except Exception as e:
                        logger.error(f"❌ Ошибка подготовки записи: {e}")
                        failed_count += 1
                
                if batch_data:
                    try:
                        self.cursor.executemany(insert_query, batch_data)
                        inserted_count += len(batch_data)
                        logger.info(f"✅ Вставлено {len(batch_data)} записей (батч {i//batch_size + 1})")
                    except Exception as e:
                        logger.error(f"❌ Ошибка вставки батча: {e}")
                        failed_count += len(batch_data)
            
            self.connection.commit()
            logger.info(f"✅ Обновление inventory_data завершено: вставлено {inserted_count}, ошибок {failed_count}")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при обновлении inventory_data: {e}")
            self.connection.rollback()
            raise
        
        return updated_count, inserted_count, failed_count

    def log_sync_result(self, result: SyncResult) -> None:
        """
        Запись результата синхронизации в таблицу sync_logs.
        
        Args:
            result: Результат синхронизации
        """
        try:
            insert_query = """
                INSERT INTO sync_logs 
                (sync_type, source, status, records_processed, records_updated, 
                 records_inserted, records_failed, started_at, completed_at, 
                 api_requests_count, error_message)
                VALUES ('inventory', %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
            
            self.cursor.execute(insert_query, (
                result.source,
                result.status.value,
                result.records_processed,
                result.records_updated,
                result.records_inserted,
                result.records_failed,
                result.started_at,
                result.completed_at,
                result.api_requests_count,
                result.error_message
            ))
            
            self.connection.commit()
            logger.info(f"📝 Результат синхронизации {result.source} записан в sync_logs")
            
        except Exception as e:
            logger.error(f"❌ Ошибка записи в sync_logs: {e}")

    def get_last_sync_time(self, source: str) -> Optional[datetime]:
        """
        Получение времени последней успешной синхронизации.
        
        Args:
            source: Источник данных
            
        Returns:
            datetime: Время последней синхронизации или None
        """
        try:
            self.cursor.execute("""
                SELECT MAX(completed_at) as last_sync
                FROM sync_logs 
                WHERE source = %s AND status = 'success' AND sync_type = 'inventory'
            """, (source,))
            
            result = self.cursor.fetchone()
            return result['last_sync'] if result and result['last_sync'] else None
            
        except Exception as e:
            logger.error(f"❌ Ошибка получения времени последней синхронизации: {e}")
            return None

    def check_data_freshness(self) -> Dict[str, Any]:
        """
        Проверка актуальности данных об остатках.
        
        Returns:
            Dict: Отчет о свежести данных
        """
        try:
            self.cursor.execute("""
                SELECT 
                    source,
                    MAX(last_sync_at) as last_update,
                    COUNT(DISTINCT product_id) as unique_products,
                    SUM(quantity_present) as total_present,
                    SUM(quantity_reserved) as total_reserved
                FROM inventory_data 
                WHERE snapshot_date = CURDATE()
                GROUP BY source
            """)
            
            results = self.cursor.fetchall()
            
            freshness_report = {
                'check_time': datetime.now(),
                'sources': {}
            }
            
            for row in results:
                source = row['source']
                last_update = row['last_update']
                
                # Вычисляем возраст данных в часах
                if last_update:
                    age_hours = (datetime.now() - last_update).total_seconds() / 3600
                    is_fresh = age_hours <= 6  # Данные считаются свежими, если младше 6 часов
                else:
                    age_hours = None
                    is_fresh = False
                
                freshness_report['sources'][source] = {
                    'last_update': last_update,
                    'age_hours': age_hours,
                    'is_fresh': is_fresh,
                    'unique_products': row['unique_products'],
                    'total_present': row['total_present'],
                    'total_reserved': row['total_reserved']
                }
            
            return freshness_report
            
        except Exception as e:
            logger.error(f"❌ Ошибка проверки свежести данных: {e}")
            return {'error': str(e)}

    def get_inventory_statistics(self) -> Dict[str, Any]:
        """
        Получение статистики по остаткам.
        
        Returns:
            Dict: Статистика остатков
        """
        try:
            # Общая статистика по источникам
            self.cursor.execute("""
                SELECT 
                    source,
                    warehouse_name,
                    stock_type,
                    COUNT(*) as total_records,
                    COUNT(DISTINCT product_id) as unique_products,
                    SUM(quantity_present) as total_present,
                    SUM(quantity_reserved) as total_reserved,
                    SUM(available_stock) as total_available,
                    MAX(last_sync_at) as last_sync
                FROM inventory_data 
                WHERE snapshot_date = CURDATE()
                GROUP BY source, warehouse_name, stock_type
                ORDER BY source, warehouse_name, stock_type
            """)
            
            stats = self.cursor.fetchall()
            
            # Группируем статистику по источникам
            statistics = {}
            for stat in stats:
                source = stat['source']
                if source not in statistics:
                    statistics[source] = {
                        'warehouses': {},
                        'totals': {
                            'records': 0,
                            'products': set(),
                            'present': 0,
                            'reserved': 0,
                            'available': 0
                        }
                    }
                
                warehouse_key = f"{stat['warehouse_name']} ({stat['stock_type']})"
                statistics[source]['warehouses'][warehouse_key] = {
                    'records': stat['total_records'],
                    'unique_products': stat['unique_products'],
                    'present': stat['total_present'],
                    'reserved': stat['total_reserved'],
                    'available': stat['total_available'],
                    'last_sync': stat['last_sync']
                }
                
                # Обновляем общие итоги
                statistics[source]['totals']['records'] += stat['total_records']
                statistics[source]['totals']['present'] += stat['total_present']
                statistics[source]['totals']['reserved'] += stat['total_reserved']
                statistics[source]['totals']['available'] += stat['total_available']
            
            # Конвертируем множества в числа для уникальных товаров
            for source_stats in statistics.values():
                source_stats['totals']['products'] = len(source_stats['totals']['products'])
            
            return statistics
            
        except Exception as e:
            logger.error(f"❌ Ошибка получения статистики: {e}")
            return {'error': str(e)}

    def run_full_sync(self) -> Dict[str, SyncResult]:
        """
        Запуск полной синхронизации остатков со всех маркетплейсов.
        
        Returns:
            Dict[str, SyncResult]: Результаты синхронизации по источникам
        """
        logger.info("🚀 Запуск полной синхронизации остатков")
        
        results = {}
        
        try:
            self.connect_to_database()
            
            # Синхронизация Ozon
            logger.info("=" * 50)
            logger.info("СИНХРОНИЗАЦИЯ ОСТАТКОВ OZON")
            logger.info("=" * 50)
            
            try:
                ozon_result = self.sync_ozon_inventory()
                results['Ozon'] = ozon_result
                self.log_sync_result(ozon_result)
            except Exception as e:
                logger.error(f"❌ Ошибка синхронизации Ozon: {e}")
                results['Ozon'] = SyncResult(
                    source='Ozon',
                    status=SyncStatus.FAILED,
                    records_processed=0,
                    records_updated=0,
                    records_inserted=0,
                    records_failed=0,
                    started_at=datetime.now(),
                    completed_at=datetime.now(),
                    error_message=str(e)
                )
                self.log_sync_result(results['Ozon'])
            
            # Синхронизация Wildberries
            logger.info("=" * 50)
            logger.info("СИНХРОНИЗАЦИЯ ОСТАТКОВ WILDBERRIES")
            logger.info("=" * 50)
            
            try:
                wb_result = self.sync_wb_inventory()
                results['Wildberries'] = wb_result
                self.log_sync_result(wb_result)
            except Exception as e:
                logger.error(f"❌ Ошибка синхронизации Wildberries: {e}")
                results['Wildberries'] = SyncResult(
                    source='Wildberries',
                    status=SyncStatus.FAILED,
                    records_processed=0,
                    records_updated=0,
                    records_inserted=0,
                    records_failed=0,
                    started_at=datetime.now(),
                    completed_at=datetime.now(),
                    error_message=str(e)
                )
                self.log_sync_result(results['Wildberries'])
            
            # Выводим итоговую статистику
            self.print_sync_summary(results)
            
            logger.info("✅ Полная синхронизация остатков завершена")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при полной синхронизации: {e}")
            raise
        finally:
            self.close_database_connection()
        
        return results

    def sync_ozon_inventory(self) -> SyncResult:
        """
        Синхронизация остатков с Ozon через API.
        
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        logger.info("🔄 Начинаем синхронизацию остатков с Ozon...")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        inventory_records = []
        
        try:
            # Получаем остатки товаров через API Ozon
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
                    response = requests.post(url, json=payload, headers=headers, timeout=30)
                    response.raise_for_status()
                    api_requests += 1
                    
                    data = response.json()
                    items = data.get('result', {}).get('items', [])
                    
                    if not items:
                        logger.info("Больше нет товаров для обработки")
                        break
                    
                    logger.info(f"Получено {len(items)} товаров с Ozon (offset: {offset})")
                    
                    # Обрабатываем каждый товар
                    for item in items:
                        records_processed += 1
                        
                        try:
                            offer_id = item.get('offer_id', '')
                            product_id = self.get_product_id_by_ozon_sku(offer_id)
                            
                            if not product_id:
                                logger.warning(f"Товар с offer_id {offer_id} не найден в БД")
                                records_failed += 1
                                continue
                            
                            # Обрабатываем остатки по складам
                            stocks = item.get('stocks', [])
                            if not stocks:
                                # Если нет остатков, создаем запись с нулевыми значениями
                                stocks = [{'warehouse_name': 'Ozon Main', 'type': 'FBO', 'present': 0, 'reserved': 0}]
                            
                            for stock in stocks:
                                warehouse_name = stock.get('warehouse_name', 'Ozon Main')
                                stock_type = stock.get('type', 'FBO')
                                quantity_present = max(0, int(stock.get('present', 0)))
                                quantity_reserved = max(0, int(stock.get('reserved', 0)))
                                
                                # Создаем запись об остатках
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
                                
                                inventory_records.append(inventory_record)
                                
                        except Exception as e:
                            logger.error(f"Ошибка обработки товара {item.get('offer_id', 'unknown')}: {e}")
                            records_failed += 1
                    
                    # Если получили меньше лимита, значит это последняя страница
                    if len(items) < limit:
                        break
                    
                    offset += limit
                    time.sleep(config.OZON_REQUEST_DELAY)  # Задержка между запросами
                    
                except requests.exceptions.RequestException as e:
                    logger.error(f"Ошибка запроса к Ozon API: {e}")
                    records_failed += records_processed
                    break
            
            # Валидация и сохранение данных в БД
            if inventory_records:
                # Валидируем данные
                validation_result = self.validate_inventory_data(inventory_records, 'Ozon')
                
                # Проверяем аномалии
                anomalies = self.check_data_anomalies(inventory_records, 'Ozon')
                if anomalies['anomalies']:
                    logger.warning(f"⚠️ Обнаружены аномалии в данных Ozon: {len(anomalies['anomalies'])} типов")
                
                # Фильтруем валидные записи
                valid_records = self.filter_valid_records(inventory_records, validation_result)
                
                if valid_records:
                    updated, inserted, failed = self.update_inventory_data(valid_records, 'Ozon')
                    records_inserted = inserted
                    records_failed += failed + (len(inventory_records) - len(valid_records))
                    
                    logger.info(f"✅ Синхронизация Ozon завершена: обработано {records_processed}, "
                               f"валидных {len(valid_records)}, вставлено {records_inserted}, ошибок {records_failed}")
                else:
                    logger.error("❌ Нет валидных данных для сохранения в БД")
                    records_failed = records_processed
            else:
                logger.warning("⚠️ Нет данных для сохранения в БД")
            
            return SyncResult(
                source='Ozon',
                status=SyncStatus.SUCCESS if records_failed == 0 else SyncStatus.PARTIAL,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                api_requests_count=api_requests
            )
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка синхронизации Ozon: {e}")
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
                api_requests_count=api_requests
            )

    def sync_wb_inventory(self) -> SyncResult:
        """
        Синхронизация остатков с Wildberries через API.
        
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        logger.info("🔄 Начинаем синхронизацию остатков с Wildberries...")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        inventory_records = []
        
        try:
            # Получаем остатки товаров через API Wildberries
            url = f"{config.WB_SUPPLIERS_API_URL}/api/v1/supplier/stocks"
            headers = {
                "Authorization": config.WB_API_TOKEN
            }
            
            # Получаем остатки за сегодня
            params = {
                'dateFrom': datetime.now().replace(hour=0, minute=0, second=0, microsecond=0).isoformat()
            }
            
            try:
                response = requests.get(url, headers=headers, params=params, timeout=30)
                response.raise_for_status()
                api_requests += 1
                
                data = response.json()
                
                if not isinstance(data, list):
                    logger.warning("Неожиданный формат ответа от WB API")
                    data = []
                
                logger.info(f"Получено {len(data)} записей остатков с Wildberries")
                
                # Обрабатываем каждую запись
                for item in data:
                    records_processed += 1
                    
                    try:
                        # Получаем информацию о товаре
                        barcode = item.get('barcode', '')
                        nm_id = item.get('nmId', '')
                        
                        # Ищем товар в БД по штрихкоду или nmId
                        product_id = None
                        if barcode:
                            product_id = self.get_product_id_by_barcode(barcode)
                        if not product_id and nm_id:
                            product_id = self.get_product_id_by_wb_sku(str(nm_id))
                        
                        if not product_id:
                            logger.warning(f"Товар с barcode {barcode} или nmId {nm_id} не найден в БД")
                            records_failed += 1
                            continue
                        
                        # Извлекаем данные об остатках
                        warehouse_name = item.get('warehouseName', 'WB Main')
                        quantity_present = max(0, int(item.get('quantity', 0)))
                        quantity_reserved = max(0, int(item.get('inWayToClient', 0)))
                        quantity_coming = max(0, int(item.get('inWayFromClient', 0)))
                        
                        # Создаем запись об остатках
                        inventory_record = InventoryRecord(
                            product_id=product_id,
                            sku=str(nm_id) if nm_id else barcode,
                            source='Wildberries',
                            warehouse_name=warehouse_name,
                            stock_type='FBS',  # WB в основном использует FBS
                            current_stock=quantity_present,
                            reserved_stock=quantity_reserved,
                            available_stock=max(0, quantity_present - quantity_reserved),
                            quantity_present=quantity_present,
                            quantity_reserved=quantity_reserved,
                            snapshot_date=date.today()
                        )
                        
                        inventory_records.append(inventory_record)
                        
                    except Exception as e:
                        logger.error(f"Ошибка обработки товара WB {item.get('nmId', 'unknown')}: {e}")
                        records_failed += 1
                
                time.sleep(config.WB_REQUEST_DELAY)  # Задержка после запроса
                
            except requests.exceptions.RequestException as e:
                logger.error(f"Ошибка запроса к WB API: {e}")
                records_failed = records_processed
                raise
            
            # Валидация и сохранение данных в БД
            if inventory_records:
                # Валидируем данные
                validation_result = self.validate_inventory_data(inventory_records, 'Wildberries')
                
                # Проверяем аномалии
                anomalies = self.check_data_anomalies(inventory_records, 'Wildberries')
                if anomalies['anomalies']:
                    logger.warning(f"⚠️ Обнаружены аномалии в данных Wildberries: {len(anomalies['anomalies'])} типов")
                
                # Фильтруем валидные записи
                valid_records = self.filter_valid_records(inventory_records, validation_result)
                
                if valid_records:
                    updated, inserted, failed = self.update_inventory_data(valid_records, 'Wildberries')
                    records_inserted = inserted
                    records_failed += failed + (len(inventory_records) - len(valid_records))
                    
                    logger.info(f"✅ Синхронизация Wildberries завершена: обработано {records_processed}, "
                               f"валидных {len(valid_records)}, вставлено {records_inserted}, ошибок {records_failed}")
                else:
                    logger.error("❌ Нет валидных данных для сохранения в БД")
                    records_failed = records_processed
            else:
                logger.warning("⚠️ Нет данных для сохранения в БД")
            
            return SyncResult(
                source='Wildberries',
                status=SyncStatus.SUCCESS if records_failed == 0 else SyncStatus.PARTIAL,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                api_requests_count=api_requests
            )
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка синхронизации Wildberries: {e}")
            return SyncResult(
                source='Wildberries',
                status=SyncStatus.FAILED,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                error_message=str(e),
                api_requests_count=api_requests
            )

    def print_sync_summary(self, results: Dict[str, SyncResult]) -> None:
        """
        Вывод сводки результатов синхронизации.
        
        Args:
            results: Результаты синхронизации по источникам
        """
        logger.info("📊 СВОДКА РЕЗУЛЬТАТОВ СИНХРОНИЗАЦИИ:")
        logger.info("=" * 50)
        
        for source, result in results.items():
            logger.info(f"{source}:")
            logger.info(f"  Статус: {result.status.value}")
            logger.info(f"  Обработано записей: {result.records_processed}")
            logger.info(f"  Обновлено: {result.records_updated}")
            logger.info(f"  Вставлено: {result.records_inserted}")
            logger.info(f"  Ошибок: {result.records_failed}")
            logger.info(f"  Длительность: {result.duration_seconds} сек")
            if result.error_message:
                logger.info(f"  Ошибка: {result.error_message}")
            logger.info("-" * 30)

    def get_ozon_inventory_via_reports(self) -> List[InventoryRecord]:
        """
        Альтернативный метод получения остатков Ozon через CSV отчеты.
        Используется как fallback если основной API недоступен.
        
        Returns:
            List[InventoryRecord]: Список записей об остатках
        """
        logger.info("🔄 Получение остатков Ozon через CSV отчеты...")
        
        inventory_records = []
        
        try:
            # Импортируем функции для работы с отчетами
            from importers.ozon_importer import request_report, get_report_data
            
            # Заказываем отчет по товарам
            report_code = request_report('products')
            logger.info(f"Заказан отчет по товарам, код: {report_code}")
            
            # Ждем готовности отчета
            max_attempts = 30
            for attempt in range(max_attempts):
                try:
                    report_data = get_report_data(report_code)
                    if report_data:
                        break
                except Exception:
                    if attempt < max_attempts - 1:
                        logger.info(f"Отчет еще не готов, попытка {attempt + 1}/{max_attempts}")
                        time.sleep(10)
                    else:
                        raise Exception("Отчет не готов после максимального времени ожидания")
            
            # Парсим CSV данные
            if report_data:
                csv_reader = csv.DictReader(io.StringIO(report_data))
                
                for row in csv_reader:
                    try:
                        offer_id = row.get('Артикул', '')
                        product_id = self.get_product_id_by_ozon_sku(offer_id)
                        
                        if not product_id:
                            continue
                        
                        # Обрабатываем разные типы складов
                        warehouse_types = [
                            ('FBO', 'Доступно к продаже по схеме FBO, шт.', 'Зарезервировано, шт'),
                            ('FBS', 'Доступно к продаже по схеме FBS, шт.', 'Зарезервировано на моих складах, шт'),
                            ('realFBS', 'Доступно к продаже по схеме realFBS, шт.', None)
                        ]
                        
                        for stock_type, present_field, reserved_field in warehouse_types:
                            quantity_present = max(0, int(row.get(present_field, 0) or 0))
                            quantity_reserved = max(0, int(row.get(reserved_field, 0) or 0)) if reserved_field else 0
                            
                            if quantity_present > 0 or quantity_reserved > 0:
                                inventory_record = InventoryRecord(
                                    product_id=product_id,
                                    sku=offer_id,
                                    source='Ozon',
                                    warehouse_name=f'Ozon {stock_type}',
                                    stock_type=stock_type,
                                    current_stock=quantity_present,
                                    reserved_stock=quantity_reserved,
                                    available_stock=max(0, quantity_present - quantity_reserved),
                                    quantity_present=quantity_present,
                                    quantity_reserved=quantity_reserved,
                                    snapshot_date=date.today()
                                )
                                
                                inventory_records.append(inventory_record)
                                
                    except Exception as e:
                        logger.error(f"Ошибка парсинга строки CSV: {e}")
                        continue
            
            logger.info(f"Получено {len(inventory_records)} записей остатков из CSV отчета")
            return inventory_records
            
        except Exception as e:
            logger.error(f"Ошибка получения остатков через CSV отчеты: {e}")
            return []

    def get_wb_warehouses(self) -> List[Dict[str, Any]]:
        """
        Получение списка складов Wildberries.
        
        Returns:
            List[Dict]: Список складов
        """
        try:
            url = f"{config.WB_SUPPLIERS_API_URL}/api/v1/warehouses"
            headers = {
                "Authorization": config.WB_API_TOKEN
            }
            
            response = requests.get(url, headers=headers, timeout=30)
            response.raise_for_status()
            
            warehouses = response.json()
            logger.info(f"Получено {len(warehouses)} складов WB")
            
            return warehouses if isinstance(warehouses, list) else []
            
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка получения списка складов WB: {e}")
            return []

    def get_wb_stocks_by_warehouse(self, warehouse_id: int, warehouse_name: str) -> List[InventoryRecord]:
        """
        Получение остатков для конкретного склада WB.
        
        Args:
            warehouse_id: ID склада
            warehouse_name: Название склада
            
        Returns:
            List[InventoryRecord]: Список остатков для данного склада
        """
        inventory_records = []
        
        try:
            url = f"{config.WB_SUPPLIERS_API_URL}/api/v3/stocks/{warehouse_id}"
            headers = {
                "Authorization": config.WB_API_TOKEN
            }
            
            response = requests.get(url, headers=headers, timeout=30)
            response.raise_for_status()
            
            data = response.json()
            
            for item in data.get('stocks', []):
                try:
                    nm_id = item.get('nmId', '')
                    product_id = self.get_product_id_by_wb_sku(str(nm_id))
                    
                    if not product_id:
                        continue
                    
                    quantity_present = max(0, int(item.get('quantity', 0)))
                    quantity_full = max(0, int(item.get('quantityFull', 0)))
                    quantity_reserved = max(0, quantity_full - quantity_present)
                    
                    inventory_record = InventoryRecord(
                        product_id=product_id,
                        sku=str(nm_id),
                        source='Wildberries',
                        warehouse_name=warehouse_name,
                        stock_type='FBS',
                        current_stock=quantity_present,
                        reserved_stock=quantity_reserved,
                        available_stock=quantity_present,
                        quantity_present=quantity_present,
                        quantity_reserved=quantity_reserved,
                        snapshot_date=date.today()
                    )
                    
                    inventory_records.append(inventory_record)
                    
                except Exception as e:
                    logger.error(f"Ошибка обработки товара {item.get('nmId', 'unknown')}: {e}")
                    continue
            
            logger.info(f"Получено {len(inventory_records)} остатков со склада {warehouse_name}")
            return inventory_records
            
        except requests.exceptions.RequestException as e:
            logger.error(f"Ошибка получения остатков склада {warehouse_name}: {e}")
            return []

    def sync_wb_inventory_by_warehouses(self) -> SyncResult:
        """
        Альтернативный метод синхронизации WB по складам.
        Получает остатки отдельно для каждого склада.
        
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        logger.info("🔄 Синхронизация WB остатков по складам...")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        all_inventory_records = []
        
        try:
            # Получаем список складов
            warehouses = self.get_wb_warehouses()
            api_requests += 1
            
            if not warehouses:
                logger.warning("Не удалось получить список складов WB")
                return SyncResult(
                    source='Wildberries',
                    status=SyncStatus.FAILED,
                    records_processed=0,
                    records_updated=0,
                    records_inserted=0,
                    records_failed=0,
                    started_at=started_at,
                    completed_at=datetime.now(),
                    error_message="Не удалось получить список складов",
                    api_requests_count=api_requests
                )
            
            # Получаем остатки для каждого склада
            for warehouse in warehouses:
                warehouse_id = warehouse.get('id')
                warehouse_name = warehouse.get('name', f'Склад-{warehouse_id}')
                
                try:
                    warehouse_stocks = self.get_wb_stocks_by_warehouse(warehouse_id, warehouse_name)
                    api_requests += 1
                    
                    all_inventory_records.extend(warehouse_stocks)
                    records_processed += len(warehouse_stocks)
                    
                    time.sleep(config.WB_REQUEST_DELAY)  # Задержка между запросами
                    
                except Exception as e:
                    logger.error(f"Ошибка получения остатков склада {warehouse_name}: {e}")
                    records_failed += 1
            
            # Сохраняем все данные в БД
            if all_inventory_records:
                updated, inserted, failed = self.update_inventory_data(all_inventory_records, 'Wildberries')
                records_inserted = inserted
                records_failed += failed
                
                logger.info(f"✅ Синхронизация WB по складам завершена: обработано {records_processed}, "
                           f"вставлено {records_inserted}, ошибок {records_failed}")
            
            return SyncResult(
                source='Wildberries',
                status=SyncStatus.SUCCESS if records_failed == 0 else SyncStatus.PARTIAL,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                api_requests_count=api_requests
            )
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка синхронизации WB по складам: {e}")
            return SyncResult(
                source='Wildberries',
                status=SyncStatus.FAILED,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                error_message=str(e),
                api_requests_count=api_requests
            )

    def validate_inventory_data(self, inventory_records: List[InventoryRecord], source: str) -> ValidationResult:
        """
        Валидация данных об остатках перед сохранением в БД.
        
        Args:
            inventory_records: Список записей об остатках
            source: Источник данных
            
        Returns:
            ValidationResult: Результат валидации
        """
        logger.info(f"🔍 Валидация данных об остатках от {source}")
        
        # Конвертируем InventoryRecord в словари для валидатора
        records_dict = []
        for record in inventory_records:
            record_dict = {
                'product_id': record.product_id,
                'sku': record.sku,
                'source': record.source,
                'warehouse_name': record.warehouse_name,
                'stock_type': record.stock_type,
                'current_stock': record.current_stock,
                'reserved_stock': record.reserved_stock,
                'available_stock': record.available_stock,
                'quantity_present': record.quantity_present,
                'quantity_reserved': record.quantity_reserved,
                'snapshot_date': record.snapshot_date
            }
            records_dict.append(record_dict)
        
        # Выполняем валидацию
        validation_result = self.validator.validate_inventory_records(records_dict, source)
        
        # Дополнительно проверяем существование товаров в БД
        if inventory_records:
            product_ids = [record.product_id for record in inventory_records if record.product_id]
            if product_ids:
                existence_result = self.validator.validate_product_existence(product_ids, self.cursor)
                
                # Объединяем результаты валидации
                validation_result.issues.extend(existence_result.issues)
                if not existence_result.is_valid:
                    validation_result.is_valid = False
        
        return validation_result

    def filter_valid_records(self, inventory_records: List[InventoryRecord], 
                           validation_result: ValidationResult) -> List[InventoryRecord]:
        """
        Фильтрация валидных записей на основе результатов валидации.
        
        Args:
            inventory_records: Исходный список записей
            validation_result: Результат валидации
            
        Returns:
            List[InventoryRecord]: Список валидных записей
        """
        if validation_result.is_valid:
            return inventory_records
        
        # Получаем ID записей с критическими ошибками
        error_record_ids = set()
        for issue in validation_result.issues:
            if issue.severity.value == 'error' and issue.record_id:
                error_record_ids.add(issue.record_id)
        
        # Фильтруем записи
        valid_records = []
        for i, record in enumerate(inventory_records):
            record_id = f"{record.source}_{i}"
            if record_id not in error_record_ids:
                valid_records.append(record)
        
        logger.info(f"🔍 Отфильтровано {len(valid_records)} валидных записей из {len(inventory_records)}")
        return valid_records

    def check_data_anomalies(self, inventory_records: List[InventoryRecord], source: str) -> Dict[str, Any]:
        """
        Проверка аномалий в данных об остатках.
        
        Args:
            inventory_records: Список записей об остатках
            source: Источник данных
            
        Returns:
            Dict: Отчет об аномалиях
        """
        logger.info(f"🔍 Проверка аномалий в данных {source}")
        
        anomalies = {
            'source': source,
            'total_records': len(inventory_records),
            'anomalies': [],
            'statistics': {}
        }
        
        if not inventory_records:
            return anomalies
        
        # Статистика по количествам
        quantities = [record.quantity_present for record in inventory_records]
        reserved_quantities = [record.quantity_reserved for record in inventory_records]
        
        anomalies['statistics'] = {
            'total_present': sum(quantities),
            'total_reserved': sum(reserved_quantities),
            'avg_present': sum(quantities) / len(quantities),
            'max_present': max(quantities),
            'min_present': min(quantities),
            'zero_stock_count': len([q for q in quantities if q == 0]),
            'high_stock_count': len([q for q in quantities if q > 1000])
        }
        
        # Проверка аномалий
        
        # 1. Товары с очень большими остатками
        high_stock_threshold = 10000
        high_stock_records = [r for r in inventory_records if r.quantity_present > high_stock_threshold]
        if high_stock_records:
            anomalies['anomalies'].append({
                'type': 'high_stock',
                'description': f'Товары с остатками > {high_stock_threshold}',
                'count': len(high_stock_records),
                'examples': [{'sku': r.sku, 'quantity': r.quantity_present} for r in high_stock_records[:5]]
            })
        
        # 2. Товары с зарезервированным количеством больше доступного
        invalid_reserved = [r for r in inventory_records if r.quantity_reserved > r.quantity_present]
        if invalid_reserved:
            anomalies['anomalies'].append({
                'type': 'invalid_reserved',
                'description': 'Зарезервировано больше чем доступно',
                'count': len(invalid_reserved),
                'examples': [{'sku': r.sku, 'present': r.quantity_present, 'reserved': r.quantity_reserved} 
                           for r in invalid_reserved[:5]]
            })
        
        # 3. Дублирующиеся SKU
        sku_counts = {}
        for record in inventory_records:
            key = f"{record.sku}_{record.warehouse_name}_{record.stock_type}"
            sku_counts[key] = sku_counts.get(key, 0) + 1
        
        duplicates = {k: v for k, v in sku_counts.items() if v > 1}
        if duplicates:
            anomalies['anomalies'].append({
                'type': 'duplicate_sku',
                'description': 'Дублирующиеся SKU в одном складе',
                'count': len(duplicates),
                'examples': list(duplicates.items())[:5]
            })
        
        # 4. Товары без остатков (возможно, ошибка синхронизации)
        zero_stock_records = [r for r in inventory_records if r.quantity_present == 0 and r.quantity_reserved == 0]
        if len(zero_stock_records) > len(inventory_records) * 0.5:  # Более 50% товаров без остатков
            anomalies['anomalies'].append({
                'type': 'too_many_zero_stock',
                'description': 'Слишком много товаров без остатков (возможна ошибка синхронизации)',
                'count': len(zero_stock_records),
                'percentage': (len(zero_stock_records) / len(inventory_records)) * 100
            })
        
        # 5. Проверка соответствия типов складов источнику
        if source == 'Ozon':
            invalid_stock_types = [r for r in inventory_records if r.stock_type not in ['FBO', 'FBS', 'realFBS']]
        elif source == 'Wildberries':
            invalid_stock_types = [r for r in inventory_records if r.stock_type not in ['FBS', 'FBO']]
        else:
            invalid_stock_types = []
        
        if invalid_stock_types:
            anomalies['anomalies'].append({
                'type': 'invalid_stock_type',
                'description': f'Некорректные типы складов для {source}',
                'count': len(invalid_stock_types),
                'examples': [{'sku': r.sku, 'stock_type': r.stock_type} for r in invalid_stock_types[:5]]
            })
        
        logger.info(f"🔍 Найдено {len(anomalies['anomalies'])} типов аномалий в данных {source}")
        return anomalies

    def compare_with_previous_sync(self, current_records: List[InventoryRecord], source: str) -> Dict[str, Any]:
        """
        Сравнение текущих данных с предыдущей синхронизацией.
        
        Args:
            current_records: Текущие записи об остатках
            source: Источник данных
            
        Returns:
            Dict: Отчет о сравнении
        """
        logger.info(f"🔍 Сравнение с предыдущей синхронизацией {source}")
        
        comparison = {
            'source': source,
            'current_count': len(current_records),
            'previous_count': 0,
            'changes': {
                'new_products': [],
                'removed_products': [],
                'quantity_changes': [],
                'significant_changes': []
            }
        }
        
        try:
            # Получаем данные предыдущей синхронизации
            yesterday = date.today().replace(day=date.today().day - 1)
            
            self.cursor.execute("""
                SELECT product_id, sku, warehouse_name, stock_type, 
                       quantity_present, quantity_reserved
                FROM inventory_data 
                WHERE source = %s AND snapshot_date = %s
            """, (source, yesterday))
            
            previous_data = self.cursor.fetchall()
            comparison['previous_count'] = len(previous_data)
            
            # Создаем словари для сравнения
            current_dict = {}
            for record in current_records:
                key = f"{record.product_id}_{record.warehouse_name}_{record.stock_type}"
                current_dict[key] = {
                    'sku': record.sku,
                    'present': record.quantity_present,
                    'reserved': record.quantity_reserved
                }
            
            previous_dict = {}
            for row in previous_data:
                key = f"{row['product_id']}_{row['warehouse_name']}_{row['stock_type']}"
                previous_dict[key] = {
                    'sku': row['sku'],
                    'present': row['quantity_present'],
                    'reserved': row['quantity_reserved']
                }
            
            # Находим новые товары
            new_keys = set(current_dict.keys()) - set(previous_dict.keys())
            comparison['changes']['new_products'] = [
                {'key': key, 'sku': current_dict[key]['sku'], 'quantity': current_dict[key]['present']}
                for key in list(new_keys)[:10]  # Ограничиваем количество примеров
            ]
            
            # Находим удаленные товары
            removed_keys = set(previous_dict.keys()) - set(current_dict.keys())
            comparison['changes']['removed_products'] = [
                {'key': key, 'sku': previous_dict[key]['sku'], 'quantity': previous_dict[key]['present']}
                for key in list(removed_keys)[:10]
            ]
            
            # Находим изменения в количествах
            common_keys = set(current_dict.keys()) & set(previous_dict.keys())
            for key in common_keys:
                current_qty = current_dict[key]['present']
                previous_qty = previous_dict[key]['present']
                
                if current_qty != previous_qty:
                    change = {
                        'sku': current_dict[key]['sku'],
                        'previous': previous_qty,
                        'current': current_qty,
                        'difference': current_qty - previous_qty
                    }
                    
                    comparison['changes']['quantity_changes'].append(change)
                    
                    # Значительные изменения (более 50% или более 100 единиц)
                    if previous_qty > 0:
                        percent_change = abs(current_qty - previous_qty) / previous_qty * 100
                        if percent_change > 50 or abs(current_qty - previous_qty) > 100:
                            comparison['changes']['significant_changes'].append(change)
            
            # Ограничиваем количество примеров
            comparison['changes']['quantity_changes'] = comparison['changes']['quantity_changes'][:20]
            comparison['changes']['significant_changes'] = comparison['changes']['significant_changes'][:10]
            
            logger.info(f"🔍 Сравнение завершено: новых {len(new_keys)}, "
                       f"удаленных {len(removed_keys)}, изменений {len(comparison['changes']['quantity_changes'])}")
            
        except Exception as e:
            logger.error(f"❌ Ошибка сравнения с предыдущей синхронизацией: {e}")
            comparison['error'] = str(e)
        
        return comparison


def main():
    """Главная функция для тестирования сервиса."""
    service = InventorySyncService()
    
    try:
        # Тестируем подключение к БД
        service.connect_to_database()
        
        # Проверяем свежесть данных
        freshness = service.check_data_freshness()
        logger.info(f"📈 Отчет о свежести данных: {freshness}")
        
        # Получаем статистику
        stats = service.get_inventory_statistics()
        logger.info(f"📊 Статистика остатков: {stats}")
        
    except Exception as e:
        logger.error(f"❌ Ошибка тестирования сервиса: {e}")
    finally:
        service.close_database_connection()


if __name__ == "__main__":
    main()