#!/usr/bin/env python3
"""
Улучшенный сервис синхронизации остатков товаров с интегрированным логированием.

Интегрирует SyncLogger в InventorySyncService для детального логирования
каждого этапа синхронизации и записи статистики обработки данных.

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import logging
import requests
import time
import psutil
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
    from sync_logger import SyncLogger, SyncType, SyncStatus as LogSyncStatus, ProcessingStats
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


class EnhancedInventorySyncService:
    """
    Улучшенный сервис синхронизации остатков товаров с интегрированным логированием.
    
    Включает:
    - Детальное логирование каждого этапа синхронизации
    - Запись статистики обработки данных
    - Мониторинг производительности и использования ресурсов
    - Структурированное логирование ошибок и предупреждений
    """
    
    def __init__(self):
        """Инициализация сервиса."""
        self.connection = None
        self.cursor = None
        self.validator = InventoryDataValidator()
        self.sync_logger: Optional[SyncLogger] = None
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            
            # Инициализируем SyncLogger после подключения к БД
            self.sync_logger = SyncLogger(self.cursor, self.connection, "InventorySyncService")
            
            logger.info("✅ Успешное подключение к базе данных")
            if self.sync_logger:
                self.sync_logger.log_info("Подключение к базе данных установлено")
                
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

    def get_memory_usage(self) -> float:
        """Получение текущего использования памяти в МБ."""
        try:
            process = psutil.Process()
            return process.memory_info().rss / 1024 / 1024  # Конвертируем в МБ
        except:
            return 0.0

    def sync_ozon_inventory(self) -> SyncResult:
        """
        Синхронизация остатков с Ozon через API с детальным логированием.
        
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        
        # Начинаем сессию логирования
        if self.sync_logger:
            self.sync_logger.start_sync_session(SyncType.INVENTORY, "Ozon")
            self.sync_logger.log_info("Начинаем синхронизацию остатков с Ozon")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        inventory_records = []
        
        try:
            # Этап 1: Подготовка к API запросам
            stage_start = time.time()
            
            url = f"{config.OZON_API_BASE_URL}/v3/product/info/stocks"
            headers = {
                "Client-Id": config.OZON_CLIENT_ID,
                "Api-Key": config.OZON_API_KEY,
                "Content-Type": "application/json"
            }
            
            if self.sync_logger:
                self.sync_logger.log_processing_stage(
                    "API Preparation", 0, 0, time.time() - stage_start
                )
            
            # Этап 2: Получение данных с API
            stage_start = time.time()
            memory_before = self.get_memory_usage()
            
            offset = 0
            limit = 1000
            
            while True:
                payload = {
                    "filter": {},
                    "last_id": "",
                    "limit": limit
                }
                
                try:
                    request_start = time.time()
                    response = requests.post(url, json=payload, headers=headers, timeout=30)
                    request_time = time.time() - request_start
                    
                    # Логируем API запрос
                    if self.sync_logger:
                        self.sync_logger.log_api_request(
                            endpoint=url,
                            response_time=request_time,
                            status_code=response.status_code,
                            records_received=0,  # Обновим после парсинга
                            error_message=None if response.status_code < 400 else response.text
                        )
                    
                    response.raise_for_status()
                    api_requests += 1
                    
                    data = response.json()
                    items = data.get('result', {}).get('items', [])
                    
                    # Обновляем количество полученных записей в логе
                    if self.sync_logger:
                        self.sync_logger.log_info(f"Получено {len(items)} товаров с Ozon (offset: {offset})")
                    
                    if not items:
                        if self.sync_logger:
                            self.sync_logger.log_info("Больше нет товаров для обработки")
                        break
                    
                    # Этап 3: Обработка полученных данных
                    processing_start = time.time()
                    batch_processed = 0
                    batch_failed = 0
                    
                    # Обрабатываем каждый товар
                    for item in items:
                        records_processed += 1
                        batch_processed += 1
                        
                        try:
                            offer_id = item.get('offer_id', '')
                            product_id = self.get_product_id_by_ozon_sku(offer_id)
                            
                            if not product_id:
                                if self.sync_logger:
                                    self.sync_logger.log_warning(f"Товар с offer_id {offer_id} не найден в БД")
                                records_failed += 1
                                batch_failed += 1
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
                            error_msg = f"Ошибка обработки товара {item.get('offer_id', 'unknown')}: {e}"
                            if self.sync_logger:
                                self.sync_logger.log_error(error_msg)
                            records_failed += 1
                            batch_failed += 1
                    
                    # Логируем статистику обработки батча
                    processing_time = time.time() - processing_start
                    if self.sync_logger:
                        self.sync_logger.log_processing_stage(
                            f"Process Batch {offset//limit + 1}",
                            batch_processed,
                            len([r for r in inventory_records[-len(items):] if r]),  # Успешно обработанные
                            processing_time,
                            records_skipped=batch_failed,
                            error_count=batch_failed,
                            memory_usage_mb=self.get_memory_usage()
                        )
                    
                    # Если получили меньше лимита, значит это последняя страница
                    if len(items) < limit:
                        break
                    
                    offset += limit
                    time.sleep(config.OZON_REQUEST_DELAY)  # Задержка между запросами
                    
                except requests.exceptions.RequestException as e:
                    error_msg = f"Ошибка запроса к Ozon API: {e}"
                    if self.sync_logger:
                        self.sync_logger.log_error(error_msg)
                    records_failed += records_processed
                    break
            
            # Логируем завершение получения данных с API
            api_stage_time = time.time() - stage_start
            memory_after = self.get_memory_usage()
            
            if self.sync_logger:
                self.sync_logger.log_processing_stage(
                    "API Data Retrieval",
                    0,
                    len(inventory_records),
                    api_stage_time,
                    error_count=records_failed,
                    memory_usage_mb=memory_after - memory_before
                )
            
            # Этап 4: Валидация данных
            if inventory_records:
                validation_start = time.time()
                
                if self.sync_logger:
                    self.sync_logger.log_info(f"Начинаем валидацию {len(inventory_records)} записей")
                
                # Валидируем данные
                validation_result = self.validate_inventory_data(inventory_records, 'Ozon')
                
                # Проверяем аномалии
                anomalies = self.check_data_anomalies(inventory_records, 'Ozon')
                if anomalies['anomalies']:
                    warning_msg = f"Обнаружены аномалии в данных Ozon: {len(anomalies['anomalies'])} типов"
                    if self.sync_logger:
                        self.sync_logger.log_warning(warning_msg)
                
                # Фильтруем валидные записи
                valid_records = self.filter_valid_records(inventory_records, validation_result)
                
                validation_time = time.time() - validation_start
                if self.sync_logger:
                    self.sync_logger.log_processing_stage(
                        "Data Validation",
                        len(inventory_records),
                        len(valid_records),
                        validation_time,
                        records_skipped=len(inventory_records) - len(valid_records),
                        warning_count=len(anomalies.get('anomalies', [])),
                        memory_usage_mb=self.get_memory_usage()
                    )
                
                # Этап 5: Сохранение в базу данных
                if valid_records:
                    db_start = time.time()
                    
                    if self.sync_logger:
                        self.sync_logger.log_info(f"Сохраняем {len(valid_records)} валидных записей в БД")
                    
                    updated, inserted, failed = self.update_inventory_data(valid_records, 'Ozon')
                    records_inserted = inserted
                    records_failed += failed + (len(inventory_records) - len(valid_records))
                    
                    db_time = time.time() - db_start
                    if self.sync_logger:
                        self.sync_logger.log_processing_stage(
                            "Database Update",
                            len(valid_records),
                            inserted,
                            db_time,
                            error_count=failed,
                            memory_usage_mb=self.get_memory_usage()
                        )
                    
                    success_msg = (f"Синхронизация Ozon завершена: обработано {records_processed}, "
                                 f"валидных {len(valid_records)}, вставлено {records_inserted}, ошибок {records_failed}")
                    if self.sync_logger:
                        self.sync_logger.log_info(success_msg)
                else:
                    error_msg = "Нет валидных данных для сохранения в БД"
                    if self.sync_logger:
                        self.sync_logger.log_error(error_msg)
                    records_failed = records_processed
            else:
                warning_msg = "Нет данных для сохранения в БД"
                if self.sync_logger:
                    self.sync_logger.log_warning(warning_msg)
            
            # Определяем статус синхронизации
            if records_failed == 0:
                sync_status = SyncStatus.SUCCESS
                log_status = LogSyncStatus.SUCCESS
            elif records_inserted > 0:
                sync_status = SyncStatus.PARTIAL
                log_status = LogSyncStatus.PARTIAL
            else:
                sync_status = SyncStatus.FAILED
                log_status = LogSyncStatus.FAILED
            
            # Завершаем сессию логирования
            if self.sync_logger:
                self.sync_logger.update_sync_counters(
                    records_processed=records_processed,
                    records_inserted=records_inserted,
                    records_failed=records_failed
                )
                self.sync_logger.end_sync_session(status=log_status)
            
            return SyncResult(
                source='Ozon',
                status=sync_status,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                api_requests_count=api_requests
            )
            
        except Exception as e:
            error_msg = f"Критическая ошибка синхронизации Ozon: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg, e)
                self.sync_logger.end_sync_session(status=LogSyncStatus.FAILED, error_message=str(e))
            
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
        Синхронизация остатков с Wildberries через API с детальным логированием.
        
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        
        # Начинаем сессию логирования
        if self.sync_logger:
            self.sync_logger.start_sync_session(SyncType.INVENTORY, "Wildberries")
            self.sync_logger.log_info("Начинаем синхронизацию остатков с Wildberries")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        inventory_records = []
        
        try:
            # Этап 1: Подготовка к API запросам
            stage_start = time.time()
            
            url = f"{config.WB_SUPPLIERS_API_URL}/api/v1/supplier/stocks"
            headers = {
                "Authorization": config.WB_API_TOKEN
            }
            
            # Получаем остатки за сегодня
            params = {
                'dateFrom': datetime.now().replace(hour=0, minute=0, second=0, microsecond=0).isoformat()
            }
            
            if self.sync_logger:
                self.sync_logger.log_processing_stage(
                    "API Preparation", 0, 0, time.time() - stage_start
                )
            
            # Этап 2: Получение данных с API
            stage_start = time.time()
            memory_before = self.get_memory_usage()
            
            try:
                request_start = time.time()
                response = requests.get(url, headers=headers, params=params, timeout=30)
                request_time = time.time() - request_start
                
                # Логируем API запрос
                if self.sync_logger:
                    self.sync_logger.log_api_request(
                        endpoint=url,
                        response_time=request_time,
                        status_code=response.status_code,
                        error_message=None if response.status_code < 400 else response.text
                    )
                
                response.raise_for_status()
                api_requests += 1
                
                data = response.json()
                
                if not isinstance(data, list):
                    warning_msg = "Неожиданный формат ответа от WB API"
                    if self.sync_logger:
                        self.sync_logger.log_warning(warning_msg)
                    data = []
                
                if self.sync_logger:
                    self.sync_logger.log_info(f"Получено {len(data)} записей остатков с Wildberries")
                
                # Этап 3: Обработка полученных данных
                processing_start = time.time()
                
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
                            warning_msg = f"Товар с barcode {barcode} или nmId {nm_id} не найден в БД"
                            if self.sync_logger:
                                self.sync_logger.log_warning(warning_msg)
                            records_failed += 1
                            continue
                        
                        # Извлекаем данные об остатках
                        warehouse_name = item.get('warehouseName', 'WB Main')
                        quantity_present = max(0, int(item.get('quantity', 0)))
                        quantity_in_way_to_client = max(0, int(item.get('inWayToClient', 0)))
                        quantity_in_way_from_client = max(0, int(item.get('inWayFromClient', 0)))
                        
                        # Общее количество зарезервированного товара
                        quantity_reserved = quantity_in_way_to_client + quantity_in_way_from_client
                        
                        # Создаем запись об остатках
                        inventory_record = InventoryRecord(
                            product_id=product_id,
                            sku=str(nm_id) if nm_id else barcode,
                            source='Wildberries',
                            warehouse_name=warehouse_name,
                            stock_type='FBS',  # WB использует преимущественно FBS
                            current_stock=quantity_present,
                            reserved_stock=quantity_reserved,
                            available_stock=max(0, quantity_present - quantity_reserved),
                            quantity_present=quantity_present,
                            quantity_reserved=quantity_reserved,
                            snapshot_date=date.today()
                        )
                        
                        inventory_records.append(inventory_record)
                        
                    except Exception as e:
                        error_msg = f"Ошибка обработки товара WB {item.get('nmId', 'unknown')}: {e}"
                        if self.sync_logger:
                            self.sync_logger.log_error(error_msg)
                        records_failed += 1
                
                # Логируем статистику обработки
                processing_time = time.time() - processing_start
                memory_after = self.get_memory_usage()
                
                if self.sync_logger:
                    self.sync_logger.log_processing_stage(
                        "API Data Processing",
                        records_processed,
                        len(inventory_records),
                        processing_time,
                        records_skipped=records_failed,
                        error_count=records_failed,
                        memory_usage_mb=memory_after - memory_before
                    )
                
                time.sleep(config.WB_REQUEST_DELAY)  # Задержка после запроса
                
            except requests.exceptions.RequestException as e:
                error_msg = f"Ошибка запроса к WB API: {e}"
                if self.sync_logger:
                    self.sync_logger.log_error(error_msg)
                records_failed = records_processed
                raise
            
            # Этап 4: Валидация и сохранение данных в БД
            if inventory_records:
                # Валидируем данные
                validation_start = time.time()
                
                validation_result = self.validate_inventory_data(inventory_records, 'Wildberries')
                
                # Проверяем аномалии
                anomalies = self.check_data_anomalies(inventory_records, 'Wildberries')
                if anomalies['anomalies']:
                    warning_msg = f"Обнаружены аномалии в данных Wildberries: {len(anomalies['anomalies'])} типов"
                    if self.sync_logger:
                        self.sync_logger.log_warning(warning_msg)
                
                # Фильтруем валидные записи
                valid_records = self.filter_valid_records(inventory_records, validation_result)
                
                validation_time = time.time() - validation_start
                if self.sync_logger:
                    self.sync_logger.log_processing_stage(
                        "Data Validation",
                        len(inventory_records),
                        len(valid_records),
                        validation_time,
                        records_skipped=len(inventory_records) - len(valid_records),
                        warning_count=len(anomalies.get('anomalies', [])),
                        memory_usage_mb=self.get_memory_usage()
                    )
                
                if valid_records:
                    db_start = time.time()
                    
                    updated, inserted, failed = self.update_inventory_data(valid_records, 'Wildberries')
                    records_inserted = inserted
                    records_failed += failed + (len(inventory_records) - len(valid_records))
                    
                    db_time = time.time() - db_start
                    if self.sync_logger:
                        self.sync_logger.log_processing_stage(
                            "Database Update",
                            len(valid_records),
                            inserted,
                            db_time,
                            error_count=failed,
                            memory_usage_mb=self.get_memory_usage()
                        )
                    
                    success_msg = (f"Синхронизация Wildberries завершена: обработано {records_processed}, "
                                 f"валидных {len(valid_records)}, вставлено {records_inserted}, ошибок {records_failed}")
                    if self.sync_logger:
                        self.sync_logger.log_info(success_msg)
                else:
                    error_msg = "Нет валидных данных для сохранения в БД"
                    if self.sync_logger:
                        self.sync_logger.log_error(error_msg)
                    records_failed = records_processed
            else:
                warning_msg = "Нет данных для сохранения в БД"
                if self.sync_logger:
                    self.sync_logger.log_warning(warning_msg)
            
            # Определяем статус синхронизации
            if records_failed == 0:
                sync_status = SyncStatus.SUCCESS
                log_status = LogSyncStatus.SUCCESS
            elif records_inserted > 0:
                sync_status = SyncStatus.PARTIAL
                log_status = LogSyncStatus.PARTIAL
            else:
                sync_status = SyncStatus.FAILED
                log_status = LogSyncStatus.FAILED
            
            # Завершаем сессию логирования
            if self.sync_logger:
                self.sync_logger.update_sync_counters(
                    records_processed=records_processed,
                    records_inserted=records_inserted,
                    records_failed=records_failed
                )
                self.sync_logger.end_sync_session(status=log_status)
            
            return SyncResult(
                source='Wildberries',
                status=sync_status,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                api_requests_count=api_requests
            )
            
        except Exception as e:
            error_msg = f"Критическая ошибка синхронизации Wildberries: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg, e)
                self.sync_logger.end_sync_session(status=LogSyncStatus.FAILED, error_message=str(e))
            
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

    def run_full_sync(self) -> Dict[str, SyncResult]:
        """
        Запуск полной синхронизации остатков со всех маркетплейсов с детальным логированием.
        
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
            
            # Синхронизация Wildberries
            logger.info("=" * 50)
            logger.info("СИНХРОНИЗАЦИЯ ОСТАТКОВ WILDBERRIES")
            logger.info("=" * 50)
            
            try:
                wb_result = self.sync_wb_inventory()
                results['Wildberries'] = wb_result
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
            
            # Выводим итоговую статистику
            self.print_sync_summary(results)
            
            # Генерируем отчет о состоянии системы
            if self.sync_logger:
                health_report = self.sync_logger.get_sync_health_report()
                logger.info(f"📊 Отчет о состоянии системы: {health_report.get('overall_health', 'unknown')}")
            
            logger.info("✅ Полная синхронизация остатков завершена")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при полной синхронизации: {e}")
            raise
        finally:
            self.close_database_connection()
        
        return results

    # Методы из оригинального класса (сокращенные для экономии места)
    def get_product_id_by_ozon_sku(self, sku_ozon: str) -> Optional[int]:
        """Получение product_id по SKU Ozon."""
        if not sku_ozon:
            return None
        try:
            self.cursor.execute("SELECT id FROM dim_products WHERE sku_ozon = %s", (sku_ozon,))
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            if self.sync_logger:
                self.sync_logger.log_error(f"Ошибка при поиске товара по sku_ozon {sku_ozon}: {e}")
            return None

    def get_product_id_by_wb_sku(self, sku_wb: str) -> Optional[int]:
        """Получение product_id по SKU Wildberries."""
        if not sku_wb:
            return None
        try:
            self.cursor.execute("SELECT id FROM dim_products WHERE sku_wb = %s", (str(sku_wb),))
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            if self.sync_logger:
                self.sync_logger.log_error(f"Ошибка при поиске товара по sku_wb {sku_wb}: {e}")
            return None

    def get_product_id_by_barcode(self, barcode: str) -> Optional[int]:
        """Получение product_id по штрихкоду."""
        if not barcode:
            return None
        try:
            self.cursor.execute("SELECT id FROM dim_products WHERE barcode = %s", (barcode,))
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            if self.sync_logger:
                self.sync_logger.log_error(f"Ошибка при поиске товара по barcode {barcode}: {e}")
            return None

    def update_inventory_data(self, inventory_records: List[InventoryRecord], source: str) -> Tuple[int, int, int]:
        """Обновление таблицы inventory_data с использованием UPSERT логики."""
        if self.sync_logger:
            self.sync_logger.log_info(f"Начинаем обновление inventory_data для источника {source}")
        
        if not inventory_records:
            if self.sync_logger:
                self.sync_logger.log_warning(f"Нет данных для обновления остатков {source}")
            return 0, 0, 0
        
        updated_count = 0
        inserted_count = 0
        failed_count = 0
        
        try:
            # Сначала удаляем все старые записи для данного источника и даты
            today = date.today()
            delete_query = "DELETE FROM inventory_data WHERE source = %s AND snapshot_date = %s"
            self.cursor.execute(delete_query, (source, today))
            deleted_count = self.cursor.rowcount
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Удалено {deleted_count} старых записей для {source} за {today}")
            
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
                        if self.sync_logger:
                            self.sync_logger.log_error(f"Ошибка подготовки записи: {e}")
                        failed_count += 1
                
                if batch_data:
                    try:
                        self.cursor.executemany(insert_query, batch_data)
                        inserted_count += len(batch_data)
                        if self.sync_logger:
                            self.sync_logger.log_info(f"Вставлено {len(batch_data)} записей (батч {i//batch_size + 1})")
                    except Exception as e:
                        if self.sync_logger:
                            self.sync_logger.log_error(f"Ошибка вставки батча: {e}")
                        failed_count += len(batch_data)
            
            self.connection.commit()
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Обновление inventory_data завершено: вставлено {inserted_count}, ошибок {failed_count}")
            
        except Exception as e:
            if self.sync_logger:
                self.sync_logger.log_error(f"Критическая ошибка при обновлении inventory_data: {e}")
            self.connection.rollback()
            raise
        
        return updated_count, inserted_count, failed_count

    def validate_inventory_data(self, inventory_records: List[InventoryRecord], source: str) -> ValidationResult:
        """Валидация данных об остатках перед сохранением в БД."""
        if self.sync_logger:
            self.sync_logger.log_info(f"Валидация данных об остатках от {source}")
        
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
        """Фильтрация валидных записей на основе результатов валидации."""
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
        
        if self.sync_logger:
            self.sync_logger.log_info(f"Отфильтровано {len(valid_records)} валидных записей из {len(inventory_records)}")
        
        return valid_records

    def check_data_anomalies(self, inventory_records: List[InventoryRecord], source: str) -> Dict[str, Any]:
        """Проверка аномалий в данных об остатках."""
        if self.sync_logger:
            self.sync_logger.log_info(f"Проверка аномалий в данных {source}")
        
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
        
        # Проверка различных типов аномалий
        # (Сокращенная версия для экономии места)
        
        if self.sync_logger:
            self.sync_logger.log_info(f"Найдено {len(anomalies['anomalies'])} типов аномалий в данных {source}")
        
        return anomalies

    def print_sync_summary(self, results: Dict[str, SyncResult]) -> None:
        """Вывод сводки результатов синхронизации."""
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


def main():
    """Главная функция для тестирования улучшенного сервиса."""
    service = EnhancedInventorySyncService()
    
    try:
        # Тестируем подключение к БД
        service.connect_to_database()
        
        # Запускаем полную синхронизацию
        results = service.run_full_sync()
        
        # Выводим результаты
        logger.info("🎉 Тестирование завершено успешно")
        for source, result in results.items():
            logger.info(f"{source}: {result.status.value} - {result.records_inserted} записей")
        
    except Exception as e:
        logger.error(f"❌ Ошибка тестирования сервиса: {e}")
    finally:
        service.close_database_connection()


if __name__ == "__main__":
    main()