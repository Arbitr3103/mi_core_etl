#!/usr/bin/env python3
"""
Сервис синхронизации остатков с интегрированной обработкой ошибок и восстановлением.

Интегрирует APIErrorHandler, DataRecoveryManager и FallbackManager
для надежной синхронизации данных об остатках товаров.

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
    from sync_logger import SyncLogger, SyncType, SyncStatus as LogSyncStatus
    from inventory_error_handler import (
        APIErrorHandler, DataRecoveryManager, FallbackManager,
        RetryConfig, ErrorType, ErrorContext
    )
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
    FALLBACK = "fallback"


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
    fallback_used: bool = False
    recovery_actions: List[str] = None
    
    def __post_init__(self):
        if self.recovery_actions is None:
            self.recovery_actions = []
    
    @property
    def duration_seconds(self) -> int:
        """Длительность выполнения в секундах."""
        if self.completed_at:
            return int((self.completed_at - self.started_at).total_seconds())
        return 0


class RobustInventorySyncService:
    """
    Надежный сервис синхронизации остатков с обработкой ошибок и восстановлением.
    
    Включает:
    - Retry логику с экспоненциальной задержкой
    - Обработку rate limits маркетплейсов
    - Fallback механизмы при недоступности API
    - Автоматическое восстановление после сбоев
    - Принудительную пересинхронизацию
    """
    
    def __init__(self):
        """Инициализация сервиса."""
        self.connection = None
        self.cursor = None
        self.validator = InventoryDataValidator()
        self.sync_logger: Optional[SyncLogger] = None
        
        # Инициализируем компоненты обработки ошибок
        retry_config = RetryConfig(
            max_attempts=3,
            base_delay=2.0,
            max_delay=120.0,
            exponential_base=2.0,
            jitter=True
        )
        self.error_handler = APIErrorHandler(retry_config)
        self.recovery_manager: Optional[DataRecoveryManager] = None
        self.fallback_manager: Optional[FallbackManager] = None
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            
            # Инициализируем компоненты после подключения к БД
            self.sync_logger = SyncLogger(self.cursor, self.connection, "RobustInventorySyncService")
            self.recovery_manager = DataRecoveryManager(self.cursor, self.connection)
            self.fallback_manager = FallbackManager(self.cursor, self.connection)
            
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

    def get_product_id_by_ozon_sku(self, sku_ozon: str) -> Optional[int]:
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
            logger.error(f"❌ Ошибка при поиске товара по sku_ozon {sku_ozon}: {e}")
            return None

    def get_product_id_by_wb_sku(self, sku_wb: str) -> Optional[int]:
        """Получение product_id по SKU Wildberries."""
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
        """Получение product_id по штрихкоду."""
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

    def make_api_request(self, method: str, url: str, source: str, **kwargs) -> Tuple[Optional[requests.Response], Optional[ErrorContext]]:
        """
        Выполнение API запроса с обработкой ошибок.
        
        Args:
            method: HTTP метод
            url: URL для запроса
            source: Источник API
            **kwargs: Дополнительные параметры для requests
            
        Returns:
            Tuple[Optional[Response], Optional[ErrorContext]]: Ответ и контекст ошибки
        """
        def _make_request():
            if method.upper() == 'GET':
                return requests.get(url, **kwargs)
            elif method.upper() == 'POST':
                return requests.post(url, **kwargs)
            else:
                raise ValueError(f"Неподдерживаемый HTTP метод: {method}")
        
        return self.error_handler.execute_with_retry(_make_request, source)

    def sync_ozon_inventory_with_recovery(self) -> SyncResult:
        """
        Синхронизация остатков с Ozon с обработкой ошибок и восстановлением.
        
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        source = 'Ozon'
        
        if self.sync_logger:
            self.sync_logger.start_sync_session(SyncType.INVENTORY, source)
            self.sync_logger.log_info("Начинаем надежную синхронизацию остатков с Ozon")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        inventory_records = []
        recovery_actions = []
        fallback_used = False
        
        try:
            # Проверяем необходимость восстановления после предыдущих сбоев
            if self.recovery_manager:
                recovery_result = self.recovery_manager.recover_from_failure(source)
                if recovery_result['status'] == 'success':
                    recovery_actions.append(f"Восстановление после сбоя: {recovery_result['message']}")
                    if self.sync_logger:
                        self.sync_logger.log_info(f"Выполнено восстановление: {recovery_result['message']}")
            
            # Подготавливаем API запрос
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
                
                # Выполняем API запрос с обработкой ошибок
                response, error_context = self.make_api_request(
                    'POST', url, source,
                    json=payload, headers=headers, timeout=30
                )
                
                api_requests += 1
                
                if error_context:
                    # Обработка ошибки API
                    error_msg = f"Ошибка API Ozon: {error_context.error_message}"
                    logger.error(error_msg)
                    
                    if self.sync_logger:
                        self.sync_logger.log_error(error_msg)
                    
                    # Пытаемся использовать fallback механизмы
                    if self.fallback_manager and not fallback_used:
                        fallback_result = self.fallback_manager.use_cached_data(source, max_age_hours=24)
                        if fallback_result['status'] == 'success':
                            fallback_used = True
                            recovery_actions.append(f"Использованы кэшированные данные: {fallback_result['copied_records']} записей")
                            if self.sync_logger:
                                self.sync_logger.log_warning(f"Использован fallback: {fallback_result['message']}")
                            
                            return SyncResult(
                                source=source,
                                status=SyncStatus.FALLBACK,
                                records_processed=fallback_result['copied_records'],
                                records_updated=0,
                                records_inserted=fallback_result['copied_records'],
                                records_failed=0,
                                started_at=started_at,
                                completed_at=datetime.now(),
                                api_requests_count=api_requests,
                                fallback_used=True,
                                recovery_actions=recovery_actions
                            )
                    
                    # Если fallback не сработал, возвращаем ошибку
                    return SyncResult(
                        source=source,
                        status=SyncStatus.FAILED,
                        records_processed=records_processed,
                        records_updated=0,
                        records_inserted=records_inserted,
                        records_failed=records_failed,
                        started_at=started_at,
                        completed_at=datetime.now(),
                        error_message=error_context.error_message,
                        api_requests_count=api_requests,
                        recovery_actions=recovery_actions
                    )
                
                # Обрабатываем успешный ответ
                data = response.json()
                items = data.get('result', {}).get('items', [])
                
                if not items:
                    if self.sync_logger:
                        self.sync_logger.log_info("Больше нет товаров для обработки")
                    break
                
                logger.info(f"Получено {len(items)} товаров с Ozon (offset: {offset})")
                
                # Обрабатываем каждый товар
                for item in items:
                    records_processed += 1
                    
                    try:
                        offer_id = item.get('offer_id', '')
                        product_id = self.get_product_id_by_ozon_sku(offer_id)
                        
                        if not product_id:
                            if self.sync_logger:
                                self.sync_logger.log_warning(f"Товар с offer_id {offer_id} не найден в БД")
                            records_failed += 1
                            continue
                        
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
                                source=source,
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
                
                # Если получили меньше лимита, значит это последняя страница
                if len(items) < limit:
                    break
                
                offset += limit
                time.sleep(config.OZON_REQUEST_DELAY)
            
            # Валидация и сохранение данных
            if inventory_records:
                validation_result = self.validate_inventory_data(inventory_records, source)
                valid_records = self.filter_valid_records(inventory_records, validation_result)
                
                if valid_records:
                    updated, inserted, failed = self.update_inventory_data(valid_records, source)
                    records_inserted = inserted
                    records_failed += failed + (len(inventory_records) - len(valid_records))
                    
                    success_msg = (f"Синхронизация Ozon завершена: обработано {records_processed}, "
                                 f"валидных {len(valid_records)}, вставлено {records_inserted}, ошибок {records_failed}")
                    if self.sync_logger:
                        self.sync_logger.log_info(success_msg)
                else:
                    error_msg = "Нет валидных данных для сохранения в БД"
                    if self.sync_logger:
                        self.sync_logger.log_error(error_msg)
                    records_failed = records_processed
            
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
                source=source,
                status=sync_status,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                api_requests_count=api_requests,
                fallback_used=fallback_used,
                recovery_actions=recovery_actions
            )
            
        except Exception as e:
            error_msg = f"Критическая ошибка синхронизации Ozon: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg, e)
                self.sync_logger.end_sync_session(status=LogSyncStatus.FAILED, error_message=str(e))
            
            return SyncResult(
                source=source,
                status=SyncStatus.FAILED,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                error_message=str(e),
                api_requests_count=api_requests,
                recovery_actions=recovery_actions
            )

    def sync_wb_inventory_with_recovery(self) -> SyncResult:
        """
        Синхронизация остатков с Wildberries с обработкой ошибок и восстановлением.
        
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        source = 'Wildberries'
        
        if self.sync_logger:
            self.sync_logger.start_sync_session(SyncType.INVENTORY, source)
            self.sync_logger.log_info("Начинаем надежную синхронизацию остатков с Wildberries")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        inventory_records = []
        recovery_actions = []
        fallback_used = False
        
        try:
            # Проверяем необходимость восстановления
            if self.recovery_manager:
                recovery_result = self.recovery_manager.recover_from_failure(source)
                if recovery_result['status'] == 'success':
                    recovery_actions.append(f"Восстановление после сбоя: {recovery_result['message']}")
                    if self.sync_logger:
                        self.sync_logger.log_info(f"Выполнено восстановление: {recovery_result['message']}")
            
            # Подготавливаем API запрос
            url = f"{config.WB_SUPPLIERS_API_URL}/api/v1/supplier/stocks"
            headers = {
                "Authorization": config.WB_API_TOKEN
            }
            
            params = {
                'dateFrom': datetime.now().replace(hour=0, minute=0, second=0, microsecond=0).isoformat()
            }
            
            # Выполняем API запрос с обработкой ошибок
            response, error_context = self.make_api_request(
                'GET', url, source,
                headers=headers, params=params, timeout=30
            )
            
            api_requests += 1
            
            if error_context:
                # Обработка ошибки API
                error_msg = f"Ошибка API Wildberries: {error_context.error_message}"
                logger.error(error_msg)
                
                if self.sync_logger:
                    self.sync_logger.log_error(error_msg)
                
                # Пытаемся использовать fallback механизмы
                if self.fallback_manager and not fallback_used:
                    fallback_result = self.fallback_manager.use_cached_data(source, max_age_hours=24)
                    if fallback_result['status'] == 'success':
                        fallback_used = True
                        recovery_actions.append(f"Использованы кэшированные данные: {fallback_result['copied_records']} записей")
                        if self.sync_logger:
                            self.sync_logger.log_warning(f"Использован fallback: {fallback_result['message']}")
                        
                        return SyncResult(
                            source=source,
                            status=SyncStatus.FALLBACK,
                            records_processed=fallback_result['copied_records'],
                            records_updated=0,
                            records_inserted=fallback_result['copied_records'],
                            records_failed=0,
                            started_at=started_at,
                            completed_at=datetime.now(),
                            api_requests_count=api_requests,
                            fallback_used=True,
                            recovery_actions=recovery_actions
                        )
                
                # Если fallback не сработал, возвращаем ошибку
                return SyncResult(
                    source=source,
                    status=SyncStatus.FAILED,
                    records_processed=records_processed,
                    records_updated=0,
                    records_inserted=records_inserted,
                    records_failed=records_failed,
                    started_at=started_at,
                    completed_at=datetime.now(),
                    error_message=error_context.error_message,
                    api_requests_count=api_requests,
                    recovery_actions=recovery_actions
                )
            
            # Обрабатываем успешный ответ
            data = response.json()
            
            if not isinstance(data, list):
                warning_msg = "Неожиданный формат ответа от WB API"
                if self.sync_logger:
                    self.sync_logger.log_warning(warning_msg)
                data = []
            
            logger.info(f"Получено {len(data)} записей остатков с Wildberries")
            
            # Обрабатываем каждую запись
            for item in data:
                records_processed += 1
                
                try:
                    barcode = item.get('barcode', '')
                    nm_id = item.get('nmId', '')
                    
                    # Ищем товар в БД
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
                    quantity_reserved = quantity_in_way_to_client + quantity_in_way_from_client
                    
                    inventory_record = InventoryRecord(
                        product_id=product_id,
                        sku=str(nm_id) if nm_id else barcode,
                        source=source,
                        warehouse_name=warehouse_name,
                        stock_type='FBS',
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
            
            # Валидация и сохранение данных
            if inventory_records:
                validation_result = self.validate_inventory_data(inventory_records, source)
                valid_records = self.filter_valid_records(inventory_records, validation_result)
                
                if valid_records:
                    updated, inserted, failed = self.update_inventory_data(valid_records, source)
                    records_inserted = inserted
                    records_failed += failed + (len(inventory_records) - len(valid_records))
                    
                    success_msg = (f"Синхронизация Wildberries завершена: обработано {records_processed}, "
                                 f"валидных {len(valid_records)}, вставлено {records_inserted}, ошибок {records_failed}")
                    if self.sync_logger:
                        self.sync_logger.log_info(success_msg)
                else:
                    error_msg = "Нет валидных данных для сохранения в БД"
                    if self.sync_logger:
                        self.sync_logger.log_error(error_msg)
                    records_failed = records_processed
            
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
                source=source,
                status=sync_status,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                api_requests_count=api_requests,
                fallback_used=fallback_used,
                recovery_actions=recovery_actions
            )
            
        except Exception as e:
            error_msg = f"Критическая ошибка синхронизации Wildberries: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg, e)
                self.sync_logger.end_sync_session(status=LogSyncStatus.FAILED, error_message=str(e))
            
            return SyncResult(
                source=source,
                status=SyncStatus.FAILED,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=datetime.now(),
                error_message=str(e),
                api_requests_count=api_requests,
                recovery_actions=recovery_actions
            )

    def force_full_resync(self, source: str, days_back: int = 7) -> Dict[str, Any]:
        """
        Принудительная полная пересинхронизация.
        
        Args:
            source: Источник данных
            days_back: Количество дней для очистки
            
        Returns:
            Dict[str, Any]: Результат пересинхронизации
        """
        logger.info(f"Запуск принудительной пересинхронизации для {source}")
        
        if not self.recovery_manager:
            return {'status': 'error', 'message': 'Recovery manager не инициализирован'}
        
        try:
            # Выполняем принудительную пересинхронизацию
            resync_result = self.recovery_manager.force_resync(source, days_back)
            
            if resync_result['status'] == 'success':
                # Запускаем синхронизацию
                if source == 'Ozon':
                    sync_result = self.sync_ozon_inventory_with_recovery()
                elif source == 'Wildberries':
                    sync_result = self.sync_wb_inventory_with_recovery()
                else:
                    return {'status': 'error', 'message': f'Неподдерживаемый источник: {source}'}
                
                return {
                    'status': 'success',
                    'source': source,
                    'resync_result': resync_result,
                    'sync_result': {
                        'status': sync_result.status.value,
                        'records_processed': sync_result.records_processed,
                        'records_inserted': sync_result.records_inserted,
                        'records_failed': sync_result.records_failed,
                        'fallback_used': sync_result.fallback_used,
                        'recovery_actions': sync_result.recovery_actions
                    }
                }
            else:
                return resync_result
                
        except Exception as e:
            logger.error(f"Ошибка принудительной пересинхронизации: {e}")
            return {'status': 'error', 'message': str(e)}

    def get_error_statistics(self) -> Dict[str, Any]:
        """
        Получение статистики ошибок.
        
        Returns:
            Dict[str, Any]: Статистика ошибок
        """
        return self.error_handler.get_error_statistics()

    def validate_data_integrity(self, source: str) -> Dict[str, Any]:
        """
        Проверка целостности данных.
        
        Args:
            source: Источник данных
            
        Returns:
            Dict[str, Any]: Результат проверки
        """
        if not self.recovery_manager:
            return {'status': 'error', 'message': 'Recovery manager не инициализирован'}
        
        return self.recovery_manager.validate_data_integrity(source)

    # Методы из базового класса (сокращенные версии)
    def validate_inventory_data(self, records: List[InventoryRecord], source: str) -> ValidationResult:
        """Валидация данных об остатках."""
        return self.validator.validate_inventory_records(records, source)

    def filter_valid_records(self, records: List[InventoryRecord], validation_result: ValidationResult) -> List[InventoryRecord]:
        """Фильтрация валидных записей."""
        if validation_result.is_valid:
            return records
        
        # Простая фильтрация - возвращаем записи с корректными данными
        valid_records = []
        for record in records:
            if (record.product_id and record.sku and 
                record.quantity_present >= 0 and record.quantity_reserved >= 0):
                valid_records.append(record)
        
        return valid_records

    def update_inventory_data(self, inventory_records: List[InventoryRecord], source: str) -> Tuple[int, int, int]:
        """Обновление таблицы inventory_data."""
        if not inventory_records:
            return 0, 0, 0
        
        try:
            # Удаляем старые записи
            today = date.today()
            delete_query = "DELETE FROM inventory_data WHERE source = %s AND snapshot_date = %s"
            self.cursor.execute(delete_query, (source, today))
            
            # Вставляем новые данные
            insert_query = """
                INSERT INTO inventory_data 
                (product_id, sku, source, warehouse_name, stock_type, 
                 snapshot_date, current_stock, reserved_stock, available_stock,
                 quantity_present, quantity_reserved, last_sync_at)
                VALUES (%(product_id)s, %(sku)s, %(source)s, %(warehouse_name)s, %(stock_type)s,
                       %(snapshot_date)s, %(current_stock)s, %(reserved_stock)s, %(available_stock)s,
                       %(quantity_present)s, %(quantity_reserved)s, NOW())
            """
            
            batch_data = []
            for record in inventory_records:
                batch_data.append({
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
                })
            
            self.cursor.executemany(insert_query, batch_data)
            self.connection.commit()
            
            return 0, len(batch_data), 0
            
        except Exception as e:
            logger.error(f"Ошибка обновления inventory_data: {e}")
            self.connection.rollback()
            return 0, 0, len(inventory_records)

    def run_full_sync_with_recovery(self) -> Dict[str, SyncResult]:
        """
        Запуск полной синхронизации с обработкой ошибок.
        
        Returns:
            Dict[str, SyncResult]: Результаты синхронизации
        """
        logger.info("🚀 Запуск полной надежной синхронизации остатков")
        
        results = {}
        
        try:
            self.connect_to_database()
            
            # Синхронизация Ozon
            logger.info("=" * 50)
            logger.info("НАДЕЖНАЯ СИНХРОНИЗАЦИЯ ОСТАТКОВ OZON")
            logger.info("=" * 50)
            
            results['Ozon'] = self.sync_ozon_inventory_with_recovery()
            
            # Синхронизация Wildberries
            logger.info("=" * 50)
            logger.info("НАДЕЖНАЯ СИНХРОНИЗАЦИЯ ОСТАТКОВ WILDBERRIES")
            logger.info("=" * 50)
            
            results['Wildberries'] = self.sync_wb_inventory_with_recovery()
            
            # Выводим итоговую статистику
            self.print_sync_summary(results)
            
            logger.info("✅ Полная надежная синхронизация остатков завершена")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка при полной синхронизации: {e}")
            raise
        finally:
            self.close_database_connection()
        
        return results

    def print_sync_summary(self, results: Dict[str, SyncResult]):
        """Вывод итоговой статистики синхронизации."""
        logger.info("=" * 60)
        logger.info("ИТОГОВАЯ СТАТИСТИКА СИНХРОНИЗАЦИИ")
        logger.info("=" * 60)
        
        for source, result in results.items():
            logger.info(f"\n📊 {source}:")
            logger.info(f"   Статус: {result.status.value}")
            logger.info(f"   Обработано: {result.records_processed}")
            logger.info(f"   Вставлено: {result.records_inserted}")
            logger.info(f"   Ошибок: {result.records_failed}")
            logger.info(f"   API запросов: {result.api_requests_count}")
            logger.info(f"   Время выполнения: {result.duration_seconds}с")
            logger.info(f"   Fallback использован: {'Да' if result.fallback_used else 'Нет'}")
            
            if result.recovery_actions:
                logger.info(f"   Действия восстановления:")
                for action in result.recovery_actions:
                    logger.info(f"     - {action}")
            
            if result.error_message:
                logger.info(f"   Ошибка: {result.error_message}")


if __name__ == "__main__":
    """Точка входа для запуска надежной синхронизации."""
    service = RobustInventorySyncService()
    
    try:
        results = service.run_full_sync_with_recovery()
        
        # Выводим статистику ошибок
        error_stats = service.get_error_statistics()
        if error_stats['total_errors'] > 0:
            logger.info(f"\n📈 Статистика ошибок: {error_stats['total_errors']} всего, {error_stats['recent_errors']} за последний час")
        
    except Exception as e:
        logger.error(f"Критическая ошибка: {e}")
        sys.exit(1)