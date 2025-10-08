#!/usr/bin/env python3
"""
Улучшенный сервис синхронизации остатков с интеграцией Ozon v4 API.

Новые возможности:
- Использование POST /v4/product/info/stocks для получения остатков
- Корректная обработка пагинации через cursor
- Поддержка фильтрации по offer_id и visibility
- Обработка всех типов остатков: present, reserved, fbo, fbs, realFbs

Автор: ETL System
Дата: 07 января 2025
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
            user=os.getenv('DB_USER', 'ingest_user'),
            password=os.getenv('DB_PASSWORD'),
            database=os.getenv('DB_NAME', 'mi_core_db'),
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


class StockType(Enum):
    """Типы складов маркетплейсов."""
    FBO = "fbo"
    FBS = "fbs"
    REAL_FBS = "realFbs"


class OzonAPIError(Exception):
    """Базовый класс для ошибок Ozon API."""
    def __init__(self, message: str, status_code: int = None, response_data: dict = None):
        self.message = message
        self.status_code = status_code
        self.response_data = response_data or {}
        super().__init__(self.message)


class OzonRateLimitError(OzonAPIError):
    """Ошибка превышения лимита запросов."""
    pass


class OzonAuthenticationError(OzonAPIError):
    """Ошибка аутентификации."""
    pass


class OzonValidationError(OzonAPIError):
    """Ошибка валидации запроса."""
    pass


class OzonServerError(OzonAPIError):
    """Серверная ошибка Ozon."""
    pass


@dataclass
class OzonStockRecord:
    """Модель записи об остатках товара с Ozon v4 API."""
    offer_id: str
    product_id: int
    warehouse_id: int
    warehouse_name: str
    stock_type: str
    present: int
    reserved: int
    
    def __post_init__(self):
        """Валидация и нормализация данных после создания."""
        self.present = max(0, int(self.present or 0))
        self.reserved = max(0, int(self.reserved or 0))


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


@dataclass
class OzonWarehouse:
    """Модель склада Ozon."""
    warehouse_id: int
    warehouse_name: str
    warehouse_type: str
    is_active: bool
    
    def __post_init__(self):
        """Валидация данных после создания."""
        self.warehouse_id = int(self.warehouse_id or 0)
        self.is_active = bool(self.is_active)


@dataclass
class OzonAnalyticsStock:
    """Модель аналитических данных об остатках с Ozon."""
    offer_id: str
    warehouse_id: int
    warehouse_name: str
    free_to_sell_amount: int
    promised_amount: int
    reserved_amount: int
    
    def __post_init__(self):
        """Валидация данных после создания."""
        self.free_to_sell_amount = max(0, int(self.free_to_sell_amount or 0))
        self.promised_amount = max(0, int(self.promised_amount or 0))
        self.reserved_amount = max(0, int(self.reserved_amount or 0))


@dataclass
class StockComparison:
    """Результат сравнения данных между основным и аналитическим API."""
    offer_id: str
    warehouse_id: int
    main_api_present: int
    main_api_reserved: int
    analytics_free_to_sell: int
    analytics_reserved: int
    discrepancy_present: int
    discrepancy_reserved: int
    has_significant_discrepancy: bool
    
    def __post_init__(self):
        """Вычисление расхождений."""
        self.discrepancy_present = abs(self.main_api_present - self.analytics_free_to_sell)
        self.discrepancy_reserved = abs(self.main_api_reserved - self.analytics_reserved)
        
        # Считаем расхождение значительным если разница больше 10% или больше 5 единиц
        threshold_percent = 0.1
        threshold_absolute = 5
        
        present_threshold = max(threshold_absolute, self.main_api_present * threshold_percent)
        reserved_threshold = max(threshold_absolute, self.main_api_reserved * threshold_percent)
        
        self.has_significant_discrepancy = (
            self.discrepancy_present > present_threshold or 
            self.discrepancy_reserved > reserved_threshold
        )


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


class InventorySyncServiceV4:
    """
    Улучшенный сервис синхронизации остатков с интеграцией Ozon v4 API.
    
    Новые возможности:
    - Использование POST /v4/product/info/stocks
    - Корректная пагинация через cursor
    - Поддержка фильтрации
    - Обработка всех типов остатков
    """
    
    def __init__(self):
        """Инициализация сервиса."""
        self.connection = None
        self.cursor = None
        self.validator = InventoryDataValidator()
        self.sync_logger: Optional[SyncLogger] = None
        self.warehouse_cache: Dict[int, OzonWarehouse] = {}
        self.warehouse_cache_updated: Optional[datetime] = None
        self.api_retry_count = 0
        self.max_retries = 3
        self.base_delay = 1.0
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            
            # Инициализируем SyncLogger после подключения к БД
            self.sync_logger = SyncLogger(self.cursor, self.connection, "InventorySyncServiceV4")
            
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

    def handle_ozon_api_error(self, response: requests.Response, endpoint: str) -> None:
        """
        Обработка ошибок Ozon API с детальным анализом.
        
        Args:
            response: Ответ от API
            endpoint: URL endpoint который вызвал ошибку
        """
        status_code = response.status_code
        
        try:
            error_data = response.json()
        except:
            error_data = {"message": response.text}
        
        error_message = error_data.get("message", "Unknown error")
        error_code = error_data.get("code", "UNKNOWN")
        
        # Логируем детальную информацию об ошибке
        if self.sync_logger:
            self.sync_logger.log_error(
                f"Ozon API Error - Endpoint: {endpoint}, "
                f"Status: {status_code}, Code: {error_code}, Message: {error_message}"
            )
        
        # Определяем тип ошибки и выбрасываем соответствующее исключение
        if status_code == 401:
            raise OzonAuthenticationError(
                f"Ошибка аутентификации: {error_message}",
                status_code=status_code,
                response_data=error_data
            )
        elif status_code == 400:
            raise OzonValidationError(
                f"Ошибка валидации запроса: {error_message}",
                status_code=status_code,
                response_data=error_data
            )
        elif status_code == 429:
            raise OzonRateLimitError(
                f"Превышен лимит запросов: {error_message}",
                status_code=status_code,
                response_data=error_data
            )
        elif status_code >= 500:
            raise OzonServerError(
                f"Серверная ошибка Ozon: {error_message}",
                status_code=status_code,
                response_data=error_data
            )
        else:
            raise OzonAPIError(
                f"Ошибка API: {error_message}",
                status_code=status_code,
                response_data=error_data
            )

    def calculate_retry_delay(self, attempt: int, base_delay: float = None) -> float:
        """
        Вычисление задержки для повторного запроса с экспоненциальным backoff.
        
        Args:
            attempt: Номер попытки (начиная с 1)
            base_delay: Базовая задержка в секундах
            
        Returns:
            Задержка в секундах
        """
        if base_delay is None:
            base_delay = self.base_delay
        
        # Экспоненциальная задержка с jitter
        import random
        delay = base_delay * (2 ** (attempt - 1))
        jitter = random.uniform(0.1, 0.3) * delay
        return delay + jitter

    def make_api_request_with_retry(self, url: str, headers: dict, payload: dict = None, 
                                   method: str = "POST") -> dict:
        """
        Выполнение API запроса с retry логикой и обработкой ошибок.
        
        Args:
            url: URL для запроса
            headers: Заголовки запроса
            payload: Данные для отправки
            method: HTTP метод
            
        Returns:
            Данные ответа
        """
        last_exception = None
        
        for attempt in range(1, self.max_retries + 1):
            try:
                if self.sync_logger:
                    self.sync_logger.log_info(f"API запрос (попытка {attempt}/{self.max_retries}): {url}")
                
                request_start = time.time()
                
                if method.upper() == "POST":
                    response = requests.post(url, json=payload, headers=headers, timeout=30)
                else:
                    response = requests.get(url, headers=headers, timeout=30)
                
                request_time = time.time() - request_start
                
                # Логируем запрос
                if self.sync_logger:
                    self.sync_logger.log_api_request(
                        endpoint=url,
                        response_time=request_time,
                        status_code=response.status_code,
                        error_message=None if response.status_code < 400 else response.text
                    )
                
                # Проверяем успешность запроса
                if response.status_code < 400:
                    return response.json()
                
                # Обрабатываем ошибку
                self.handle_ozon_api_error(response, url)
                
            except OzonRateLimitError as e:
                last_exception = e
                if attempt < self.max_retries:
                    # Для rate limit используем большую задержку
                    delay = self.calculate_retry_delay(attempt, base_delay=5.0)
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Rate limit exceeded, ждем {delay:.1f} сек")
                    time.sleep(delay)
                    continue
                else:
                    raise
                    
            except (OzonServerError, requests.exceptions.RequestException) as e:
                last_exception = e
                if attempt < self.max_retries:
                    delay = self.calculate_retry_delay(attempt)
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Временная ошибка, повтор через {delay:.1f} сек: {e}")
                    time.sleep(delay)
                    continue
                else:
                    raise
                    
            except (OzonAuthenticationError, OzonValidationError) as e:
                # Эти ошибки не стоит повторять
                if self.sync_logger:
                    self.sync_logger.log_error(f"Критическая ошибка API: {e}")
                raise
                
            except Exception as e:
                last_exception = e
                if attempt < self.max_retries:
                    delay = self.calculate_retry_delay(attempt)
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Неожиданная ошибка, повтор через {delay:.1f} сек: {e}")
                    time.sleep(delay)
                    continue
                else:
                    raise
        
        # Если все попытки исчерпаны
        if last_exception:
            raise last_exception
        else:
            raise OzonAPIError("Все попытки API запроса исчерпаны")

    def fallback_to_old_api(self, error: Exception) -> bool:
        """
        Определяет, стоит ли использовать fallback на старые методы API.
        
        Args:
            error: Исключение которое произошло
            
        Returns:
            True если стоит использовать fallback
        """
        # Используем fallback для серверных ошибок и недоступности новых endpoints
        if isinstance(error, (OzonServerError, requests.exceptions.ConnectionError)):
            return True
        
        # Для ошибок аутентификации и валидации fallback не поможет
        if isinstance(error, (OzonAuthenticationError, OzonValidationError)):
            return False
        
        # Для rate limit тоже не поможет
        if isinstance(error, OzonRateLimitError):
            return False
        
        return True

    def log_endpoint_usage(self, endpoint: str, success: bool, response_time: float, 
                          error_type: str = None) -> None:
        """
        Логирование использования различных endpoints для мониторинга.
        
        Args:
            endpoint: URL endpoint
            success: Успешность запроса
            response_time: Время ответа
            error_type: Тип ошибки если была
        """
        if self.sync_logger:
            status = "SUCCESS" if success else f"FAILED ({error_type})"
            self.sync_logger.log_info(
                f"ENDPOINT_USAGE: {endpoint} - {status} - {response_time:.2f}s"
            )

    def get_ozon_stocks_v4(self, cursor: str = None, offer_ids: List[str] = None, 
                          visibility: str = "ALL", limit: int = 1000) -> Dict[str, Any]:
        """
        Получение остатков товаров через Ozon v4 API.
        
        Args:
            cursor: Курсор для пагинации (lastId из предыдущего ответа)
            offer_ids: Список offer_id для фильтрации (опционально)
            visibility: Фильтр по видимости товаров ("ALL", "VISIBLE", "INVISIBLE")
            limit: Количество товаров в одном запросе (максимум 1000)
            
        Returns:
            Dict с результатами API запроса
        """
        url = f"{config.OZON_API_BASE_URL}/v4/product/info/stocks"
        headers = {
            "Client-Id": config.OZON_CLIENT_ID,
            "Api-Key": config.OZON_API_KEY,
            "Content-Type": "application/json"
        }
        
        # Формируем payload для запроса
        payload = {
            "limit": min(limit, 1000),  # Максимум 1000 согласно документации
            "filter": {
                "visibility": visibility
            }
        }
        
        # Добавляем cursor для пагинации
        if cursor:
            payload["cursor"] = cursor
            
        # Добавляем фильтр по offer_id если указан
        if offer_ids:
            payload["filter"]["offer_id"] = offer_ids[:100]  # Максимум 100 offer_id за раз
        
        try:
            if self.sync_logger:
                self.sync_logger.log_info(f"Запрос к Ozon v4 API: cursor={cursor}, limit={limit}, visibility={visibility}")
            
            request_start = time.time()
            data = self.make_api_request_with_retry(url, headers, payload)
            request_time = time.time() - request_start
            
            # Логируем использование endpoint
            self.log_endpoint_usage(url, True, request_time)
            
            # Проверяем структуру ответа
            if "items" not in data:
                raise ValueError("Неожиданная структура ответа API")
            
            items = data.get("items", [])
            cursor = data.get("cursor", "")
            total = data.get("total", 0)
            has_next = bool(cursor)  # Если есть cursor, значит есть еще данные
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Получено {len(items)} товаров, has_next={has_next}")
            
            return {
                "items": items,
                "last_id": cursor,  # Используем cursor как last_id для совместимости
                "has_next": has_next,
                "total_items": len(items)
            }
            
        except OzonAPIError as e:
            # Логируем использование endpoint с ошибкой
            self.log_endpoint_usage(url, False, 0, type(e).__name__)
            error_msg = f"Ошибка Ozon v4 API: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise
        except Exception as e:
            # Логируем использование endpoint с ошибкой
            self.log_endpoint_usage(url, False, 0, type(e).__name__)
            error_msg = f"Ошибка обработки ответа Ozon v4 API: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise

    def get_ozon_warehouses(self) -> List[OzonWarehouse]:
        """
        Получение списка складов Ozon через API.
        
        Returns:
            Список складов Ozon
        """
        url = f"{config.OZON_API_BASE_URL}/v1/warehouse/list"
        headers = {
            "Client-Id": config.OZON_CLIENT_ID,
            "Api-Key": config.OZON_API_KEY,
            "Content-Type": "application/json"
        }
        
        try:
            if self.sync_logger:
                self.sync_logger.log_info("Запрос списка складов Ozon")
            
            request_start = time.time()
            data = self.make_api_request_with_retry(url, headers, {})
            request_time = time.time() - request_start
            
            # Логируем использование endpoint
            self.log_endpoint_usage(url, True, request_time)
            
            # Проверяем структуру ответа
            if "result" not in data:
                raise ValueError("Неожиданная структура ответа API складов")
            
            warehouses_data = data["result"]
            warehouses = []
            
            for warehouse_data in warehouses_data:
                try:
                    warehouse = OzonWarehouse(
                        warehouse_id=warehouse_data.get("warehouse_id", 0),
                        warehouse_name=warehouse_data.get("name", f"Warehouse_{warehouse_data.get('warehouse_id', 0)}"),
                        warehouse_type=warehouse_data.get("type", "FBO"),
                        is_active=warehouse_data.get("is_active", True)
                    )
                    warehouses.append(warehouse)
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Ошибка обработки склада {warehouse_data}: {e}")
                    continue
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Получено {len(warehouses)} складов Ozon")
            
            return warehouses
            
        except OzonAPIError as e:
            # Логируем использование endpoint с ошибкой
            self.log_endpoint_usage(url, False, 0, type(e).__name__)
            error_msg = f"Ошибка API складов Ozon: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise
        except Exception as e:
            # Логируем использование endpoint с ошибкой
            self.log_endpoint_usage(url, False, 0, type(e).__name__)
            error_msg = f"Ошибка обработки ответа API складов: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise

    def update_warehouse_cache(self, force_update: bool = False) -> None:
        """
        Обновление кэша складов.
        
        Args:
            force_update: Принудительное обновление кэша
        """
        # Проверяем, нужно ли обновлять кэш (раз в день)
        if not force_update and self.warehouse_cache_updated:
            time_diff = datetime.now() - self.warehouse_cache_updated
            if time_diff.total_seconds() < 24 * 3600:  # 24 часа
                # Логируем только один раз, используя флаг
                if not hasattr(self, '_cache_log_shown'):
                    if self.sync_logger:
                        self.sync_logger.log_info("Кэш складов актуален, обновление не требуется")
                    self._cache_log_shown = True
                return
        
        try:
            if self.sync_logger:
                self.sync_logger.log_info("Обновляем кэш складов Ozon")
            
            warehouses = self.get_ozon_warehouses()
            
            # Обновляем кэш
            self.warehouse_cache.clear()
            for warehouse in warehouses:
                self.warehouse_cache[warehouse.warehouse_id] = warehouse
            
            self.warehouse_cache_updated = datetime.now()
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Кэш складов обновлен: {len(self.warehouse_cache)} складов")
            
        except Exception as e:
            error_msg = f"Ошибка обновления кэша складов: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            # Не прерываем выполнение, используем fallback названия складов

    def get_warehouse_name(self, warehouse_id: int) -> str:
        """
        Получение названия склада по ID с использованием кэша.
        
        Args:
            warehouse_id: ID склада
            
        Returns:
            Название склада
        """
        # Обновляем кэш если необходимо
        if not self.warehouse_cache:
            try:
                self.update_warehouse_cache()
            except Exception:
                pass  # Используем fallback название
        
        # Ищем в кэше
        warehouse = self.warehouse_cache.get(warehouse_id)
        if warehouse:
            return warehouse.warehouse_name
        
        # Fallback название
        return f"Ozon_Warehouse_{warehouse_id}" if warehouse_id > 0 else "Ozon_Main"

    def save_warehouses_to_db(self, warehouses: List[OzonWarehouse]) -> None:
        """
        Сохранение информации о складах в БД.
        
        Args:
            warehouses: Список складов для сохранения
        """
        try:
            if self.sync_logger:
                self.sync_logger.log_info(f"Сохраняем {len(warehouses)} складов в БД")
            
            # Создаем таблицу складов если не существует
            create_table_query = """
            CREATE TABLE IF NOT EXISTS ozon_warehouses (
                warehouse_id INT PRIMARY KEY,
                warehouse_name VARCHAR(255) NOT NULL,
                warehouse_type VARCHAR(50) DEFAULT 'FBO',
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_warehouse_name (warehouse_name),
                INDEX idx_warehouse_type (warehouse_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """
            self.cursor.execute(create_table_query)
            
            # Сохраняем склады
            for warehouse in warehouses:
                try:
                    upsert_query = """
                    INSERT INTO ozon_warehouses 
                    (warehouse_id, warehouse_name, warehouse_type, is_active)
                    VALUES (%s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        warehouse_name = VALUES(warehouse_name),
                        warehouse_type = VALUES(warehouse_type),
                        is_active = VALUES(is_active),
                        updated_at = CURRENT_TIMESTAMP
                    """
                    
                    self.cursor.execute(upsert_query, (
                        warehouse.warehouse_id,
                        warehouse.warehouse_name,
                        warehouse.warehouse_type,
                        warehouse.is_active
                    ))
                    
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_error(f"Ошибка сохранения склада {warehouse.warehouse_id}: {e}")
                    continue
            
            self.connection.commit()
            
            if self.sync_logger:
                self.sync_logger.log_info("Склады успешно сохранены в БД")
            
        except Exception as e:
            self.connection.rollback()
            error_msg = f"Ошибка сохранения складов в БД: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise

    def get_ozon_analytics_stocks(self, date_from: str = None, date_to: str = None) -> List[OzonAnalyticsStock]:
        """
        Получение аналитических данных об остатках через Ozon Analytics API.
        
        Args:
            date_from: Дата начала периода (YYYY-MM-DD)
            date_to: Дата окончания периода (YYYY-MM-DD)
            
        Returns:
            Список аналитических данных об остатках
        """
        url = f"{config.OZON_API_BASE_URL}/v2/analytics/stock_on_warehouses"
        headers = {
            "Client-Id": config.OZON_CLIENT_ID,
            "Api-Key": config.OZON_API_KEY,
            "Content-Type": "application/json"
        }
        
        # Если даты не указаны, используем сегодняшний день
        if not date_from:
            date_from = date.today().isoformat()
        if not date_to:
            date_to = date.today().isoformat()
        
        payload = {
            "date_from": date_from,
            "date_to": date_to,
            "metrics": [
                "free_to_sell_amount",
                "promised_amount", 
                "reserved_amount"
            ],
            "dimension": [
                "sku",
                "warehouse"
            ],
            "filters": [],
            "sort": [
                {
                    "key": "free_to_sell_amount",
                    "order": "DESC"
                }
            ],
            "limit": 1000,
            "offset": 0
        }
        
        try:
            if self.sync_logger:
                self.sync_logger.log_info(f"Запрос аналитических данных Ozon за период {date_from} - {date_to}")
            
            request_start = time.time()
            data = self.make_api_request_with_retry(url, headers, payload)
            request_time = time.time() - request_start
            
            # Логируем использование endpoint
            self.log_endpoint_usage(url, True, request_time)
            
            # Проверяем структуру ответа
            if "result" not in data:
                raise ValueError("Неожиданная структура ответа Analytics API")
            
            result = data["result"]
            analytics_data = result.get("data", [])
            analytics_stocks = []
            
            for item in analytics_data:
                try:
                    dimensions = item.get("dimensions", [])
                    metrics = item.get("metrics", [])
                    
                    # Извлекаем данные из dimensions
                    offer_id = ""
                    warehouse_name = ""
                    warehouse_id = 0
                    
                    for dimension in dimensions:
                        if dimension.get("id") == "sku":
                            offer_id = dimension.get("value", "")
                        elif dimension.get("id") == "warehouse":
                            warehouse_name = dimension.get("value", "")
                            # Пытаемся найти warehouse_id по названию
                            for wh_id, warehouse in self.warehouse_cache.items():
                                if warehouse.warehouse_name == warehouse_name:
                                    warehouse_id = wh_id
                                    break
                    
                    # Извлекаем метрики
                    free_to_sell_amount = 0
                    promised_amount = 0
                    reserved_amount = 0
                    
                    for metric in metrics:
                        metric_id = metric.get("id", "")
                        metric_value = metric.get("value", 0)
                        
                        if metric_id == "free_to_sell_amount":
                            free_to_sell_amount = int(metric_value)
                        elif metric_id == "promised_amount":
                            promised_amount = int(metric_value)
                        elif metric_id == "reserved_amount":
                            reserved_amount = int(metric_value)
                    
                    if offer_id:  # Создаем запись только если есть offer_id
                        analytics_stock = OzonAnalyticsStock(
                            offer_id=offer_id,
                            warehouse_id=warehouse_id,
                            warehouse_name=warehouse_name,
                            free_to_sell_amount=free_to_sell_amount,
                            promised_amount=promised_amount,
                            reserved_amount=reserved_amount
                        )
                        analytics_stocks.append(analytics_stock)
                
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Ошибка обработки аналитических данных: {e}")
                    continue
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Получено {len(analytics_stocks)} записей аналитических данных")
            
            return analytics_stocks
            
        except OzonAPIError as e:
            # Логируем использование endpoint с ошибкой
            self.log_endpoint_usage(url, False, 0, type(e).__name__)
            error_msg = f"Ошибка Analytics API Ozon: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise
        except Exception as e:
            # Логируем использование endpoint с ошибкой
            self.log_endpoint_usage(url, False, 0, type(e).__name__)
            error_msg = f"Ошибка обработки ответа Analytics API: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise

    def compare_stock_data(self, main_stocks: List[OzonStockRecord], 
                          analytics_stocks: List[OzonAnalyticsStock]) -> List[StockComparison]:
        """
        Сравнение данных между основным и аналитическим API.
        
        Args:
            main_stocks: Данные из основного API
            analytics_stocks: Данные из аналитического API
            
        Returns:
            Список результатов сравнения
        """
        comparisons = []
        
        # Создаем индекс аналитических данных для быстрого поиска
        analytics_index = {}
        for analytics_stock in analytics_stocks:
            key = f"{analytics_stock.offer_id}_{analytics_stock.warehouse_id}"
            analytics_index[key] = analytics_stock
        
        # Сравниваем данные
        for main_stock in main_stocks:
            key = f"{main_stock.offer_id}_{main_stock.warehouse_id}"
            analytics_stock = analytics_index.get(key)
            
            if analytics_stock:
                comparison = StockComparison(
                    offer_id=main_stock.offer_id,
                    warehouse_id=main_stock.warehouse_id,
                    main_api_present=main_stock.present,
                    main_api_reserved=main_stock.reserved,
                    analytics_free_to_sell=analytics_stock.free_to_sell_amount,
                    analytics_reserved=analytics_stock.reserved_amount,
                    discrepancy_present=0,  # Будет вычислено в __post_init__
                    discrepancy_reserved=0,  # Будет вычислено в __post_init__
                    has_significant_discrepancy=False  # Будет вычислено в __post_init__
                )
                comparisons.append(comparison)
        
        return comparisons

    def generate_discrepancy_alerts(self, comparisons: List[StockComparison]) -> List[Dict[str, Any]]:
        """
        Генерация алертов при значительных расхождениях данных.
        
        Args:
            comparisons: Результаты сравнения данных
            
        Returns:
            Список алертов
        """
        alerts = []
        significant_discrepancies = [c for c in comparisons if c.has_significant_discrepancy]
        
        if not significant_discrepancies:
            return alerts
        
        # Группируем расхождения по типам
        high_present_discrepancy = [c for c in significant_discrepancies if c.discrepancy_present > 10]
        high_reserved_discrepancy = [c for c in significant_discrepancies if c.discrepancy_reserved > 10]
        
        if high_present_discrepancy:
            alerts.append({
                "type": "HIGH_PRESENT_DISCREPANCY",
                "message": f"Обнаружены значительные расхождения в остатках для {len(high_present_discrepancy)} товаров",
                "count": len(high_present_discrepancy),
                "severity": "HIGH",
                "details": [
                    {
                        "offer_id": c.offer_id,
                        "warehouse_id": c.warehouse_id,
                        "main_api": c.main_api_present,
                        "analytics_api": c.analytics_free_to_sell,
                        "discrepancy": c.discrepancy_present
                    }
                    for c in high_present_discrepancy[:10]  # Показываем только первые 10
                ]
            })
        
        if high_reserved_discrepancy:
            alerts.append({
                "type": "HIGH_RESERVED_DISCREPANCY", 
                "message": f"Обнаружены значительные расхождения в резерве для {len(high_reserved_discrepancy)} товаров",
                "count": len(high_reserved_discrepancy),
                "severity": "MEDIUM",
                "details": [
                    {
                        "offer_id": c.offer_id,
                        "warehouse_id": c.warehouse_id,
                        "main_api": c.main_api_reserved,
                        "analytics_api": c.analytics_reserved,
                        "discrepancy": c.discrepancy_reserved
                    }
                    for c in high_reserved_discrepancy[:10]  # Показываем только первые 10
                ]
            })
        
        return alerts

    def save_stock_comparisons(self, comparisons: List[StockComparison]) -> None:
        """
        Сохранение результатов сравнения в БД для анализа.
        
        Args:
            comparisons: Результаты сравнения для сохранения
        """
        try:
            if self.sync_logger:
                self.sync_logger.log_info(f"Сохраняем {len(comparisons)} результатов сравнения в БД")
            
            # Создаем таблицу для сравнений если не существует
            create_table_query = """
            CREATE TABLE IF NOT EXISTS ozon_stock_comparisons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                offer_id VARCHAR(255) NOT NULL,
                warehouse_id INT NOT NULL,
                main_api_present INT DEFAULT 0,
                main_api_reserved INT DEFAULT 0,
                analytics_free_to_sell INT DEFAULT 0,
                analytics_reserved INT DEFAULT 0,
                discrepancy_present INT DEFAULT 0,
                discrepancy_reserved INT DEFAULT 0,
                has_significant_discrepancy BOOLEAN DEFAULT FALSE,
                comparison_date DATE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_offer_warehouse (offer_id, warehouse_id),
                INDEX idx_comparison_date (comparison_date),
                INDEX idx_significant_discrepancy (has_significant_discrepancy)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """
            self.cursor.execute(create_table_query)
            
            # Сохраняем результаты сравнения
            for comparison in comparisons:
                try:
                    insert_query = """
                    INSERT INTO ozon_stock_comparisons 
                    (offer_id, warehouse_id, main_api_present, main_api_reserved,
                     analytics_free_to_sell, analytics_reserved, discrepancy_present,
                     discrepancy_reserved, has_significant_discrepancy, comparison_date)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """
                    
                    self.cursor.execute(insert_query, (
                        comparison.offer_id,
                        comparison.warehouse_id,
                        comparison.main_api_present,
                        comparison.main_api_reserved,
                        comparison.analytics_free_to_sell,
                        comparison.analytics_reserved,
                        comparison.discrepancy_present,
                        comparison.discrepancy_reserved,
                        comparison.has_significant_discrepancy,
                        date.today()
                    ))
                    
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_error(f"Ошибка сохранения сравнения для {comparison.offer_id}: {e}")
                    continue
            
            self.connection.commit()
            
            if self.sync_logger:
                self.sync_logger.log_info("Результаты сравнения успешно сохранены в БД")
            
        except Exception as e:
            self.connection.rollback()
            error_msg = f"Ошибка сохранения результатов сравнения в БД: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise

    def process_ozon_v4_stocks(self, api_items: List[Dict]) -> List[OzonStockRecord]:
        """
        Обработка данных об остатках из Ozon v4 API.
        
        Args:
            api_items: Список товаров из API ответа
            
        Returns:
            Список обработанных записей об остатках
        """
        stock_records = []
        
        for item in api_items:
            try:
                offer_id = item.get("offer_id", "")
                if not offer_id:
                    if self.sync_logger:
                        self.sync_logger.log_warning("Товар без offer_id пропущен")
                    continue
                
                # Используем product_id из API ответа
                product_id = item.get("product_id", 0)
                if not product_id:
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Товар {offer_id} без product_id пропущен")
                    continue
                
                # Обрабатываем остатки по складам
                stocks = item.get("stocks", [])
                if not stocks:
                    # Если нет остатков, создаем запись с нулевыми значениями
                    stock_record = OzonStockRecord(
                        offer_id=offer_id,
                        product_id=product_id,
                        warehouse_id=0,
                        warehouse_name="Ozon Main",
                        stock_type=StockType.FBO.value,
                        present=0,
                        reserved=0
                    )
                    stock_records.append(stock_record)
                    continue
                
                # Обрабатываем каждый склад
                for stock in stocks:
                    warehouse_id = stock.get("warehouse_id", 0)
                    warehouse_name = self.get_warehouse_name(warehouse_id)
                    stock_type = stock.get("type", "fbo")
                    present = stock.get("present", 0)
                    reserved = stock.get("reserved", 0)
                    
                    # Создаем запись для всех товаров (даже с нулевыми остатками)
                    stock_record = OzonStockRecord(
                        offer_id=offer_id,
                        product_id=product_id,
                        warehouse_id=warehouse_id,
                        warehouse_name=warehouse_name,
                        stock_type=stock_type,
                        present=present,
                        reserved=reserved
                    )
                    stock_records.append(stock_record)
                
            except Exception as e:
                error_msg = f"Ошибка обработки товара {item.get('offer_id', 'unknown')}: {e}"
                if self.sync_logger:
                    self.sync_logger.log_error(error_msg)
                continue
        
        return stock_records

    def convert_to_inventory_records(self, ozon_stocks: List[OzonStockRecord]) -> List[InventoryRecord]:
        """
        Конвертация записей Ozon в универсальный формат InventoryRecord.
        
        Args:
            ozon_stocks: Список записей об остатках с Ozon
            
        Returns:
            Список записей в формате InventoryRecord
        """
        inventory_records = []
        
        for stock in ozon_stocks:
            try:
                inventory_record = InventoryRecord(
                    product_id=stock.product_id,
                    sku=stock.offer_id,
                    source='Ozon',
                    warehouse_name=stock.warehouse_name,
                    stock_type=stock.stock_type.upper(),
                    current_stock=stock.present,
                    reserved_stock=stock.reserved,
                    available_stock=max(0, stock.present - stock.reserved),
                    quantity_present=stock.present,
                    quantity_reserved=stock.reserved,
                    snapshot_date=date.today()
                )
                inventory_records.append(inventory_record)
                
            except Exception as e:
                error_msg = f"Ошибка конвертации записи для товара {stock.offer_id}: {e}"
                if self.sync_logger:
                    self.sync_logger.log_error(error_msg)
                continue
        
        return inventory_records

    def sync_ozon_inventory_v4(self, offer_ids: List[str] = None, 
                              visibility: str = "ALL") -> SyncResult:
        """
        Синхронизация остатков с Ozon через v4 API с пагинацией.
        
        Args:
            offer_ids: Список offer_id для фильтрации (опционально)
            visibility: Фильтр по видимости товаров
            
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        
        # Начинаем сессию логирования
        if self.sync_logger:
            self.sync_logger.start_sync_session(SyncType.INVENTORY, "Ozon_v4")
            self.sync_logger.log_info("Начинаем синхронизацию остатков с Ozon v4 API")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        all_stock_records = []
        
        try:
            # Обновляем информацию о складах
            try:
                if self.sync_logger:
                    self.sync_logger.log_info("Обновляем информацию о складах Ozon")
                
                warehouses = self.get_ozon_warehouses()
                self.save_warehouses_to_db(warehouses)
                
                # Обновляем кэш складов
                self.warehouse_cache.clear()
                for warehouse in warehouses:
                    self.warehouse_cache[warehouse.warehouse_id] = warehouse
                self.warehouse_cache_updated = datetime.now()
                
            except Exception as e:
                if self.sync_logger:
                    self.sync_logger.log_warning(f"Ошибка обновления складов: {e}")
                # Продолжаем выполнение без складов
            
            cursor = None
            page = 1
            
            # Пагинация через cursor
            while True:
                if self.sync_logger:
                    self.sync_logger.log_info(f"Обрабатываем страницу {page}, cursor: {cursor}")
                
                # Получаем данные с API
                api_response = self.get_ozon_stocks_v4(
                    cursor=cursor,
                    offer_ids=offer_ids,
                    visibility=visibility,
                    limit=1000
                )
                
                api_requests += 1
                items = api_response["items"]
                
                if not items:
                    if self.sync_logger:
                        self.sync_logger.log_info("Больше нет товаров для обработки")
                    break
                
                # Обрабатываем полученные данные
                batch_start = time.time()
                stock_records = self.process_ozon_v4_stocks(items)
                all_stock_records.extend(stock_records)
                
                records_processed += len(items)
                batch_time = time.time() - batch_start
                
                if self.sync_logger:
                    self.sync_logger.log_processing_stage(
                        f"Process Page {page}",
                        len(items),
                        len(stock_records),
                        batch_time
                    )
                
                # Проверяем, есть ли еще страницы
                if not api_response["has_next"]:
                    if self.sync_logger:
                        self.sync_logger.log_info("Достигнута последняя страница")
                    break
                
                cursor = api_response["last_id"]
                page += 1
                
                # Задержка между запросами
                time.sleep(config.OZON_REQUEST_DELAY)
            
            # Конвертируем в универсальный формат
            if self.sync_logger:
                self.sync_logger.log_info(f"Конвертируем {len(all_stock_records)} записей в формат БД")
            
            inventory_records = self.convert_to_inventory_records(all_stock_records)
            
            # Получаем аналитические данные для валидации
            try:
                if self.sync_logger:
                    self.sync_logger.log_info("Получаем аналитические данные для валидации")
                
                analytics_stocks = self.get_ozon_analytics_stocks()
                
                if analytics_stocks:
                    # Сравниваем данные между API
                    comparisons = self.compare_stock_data(all_stock_records, analytics_stocks)
                    
                    if comparisons:
                        # Сохраняем результаты сравнения
                        self.save_stock_comparisons(comparisons)
                        
                        # Генерируем алерты при расхождениях
                        alerts = self.generate_discrepancy_alerts(comparisons)
                        
                        if alerts:
                            for alert in alerts:
                                if self.sync_logger:
                                    self.sync_logger.log_warning(f"ALERT: {alert['message']}")
                                    
                                    # Логируем детали для критических алертов
                                    if alert['severity'] == 'HIGH':
                                        for detail in alert['details'][:3]:  # Показываем первые 3
                                            self.sync_logger.log_warning(
                                                f"  - {detail['offer_id']}: основной API={detail['main_api']}, "
                                                f"аналитический API={detail['analytics_api']}, "
                                                f"расхождение={detail['discrepancy']}"
                                            )
                        else:
                            if self.sync_logger:
                                self.sync_logger.log_info("Значительных расхождений между API не обнаружено")
                    
                    if self.sync_logger:
                        self.sync_logger.log_info(f"Выполнено сравнение {len(comparisons)} записей между API")
                else:
                    if self.sync_logger:
                        self.sync_logger.log_warning("Аналитические данные не получены")
                        
            except Exception as e:
                if self.sync_logger:
                    self.sync_logger.log_warning(f"Ошибка получения аналитических данных: {e}")
                # Продолжаем выполнение без аналитики
            
            # Валидация и сохранение данных
            if inventory_records:
                # Валидируем данные
                validation_result = self.validate_inventory_data(inventory_records, 'Ozon')
                valid_records = self.filter_valid_records(inventory_records, validation_result)
                
                if valid_records:
                    # Сохраняем в БД
                    updated, inserted, failed = self.update_inventory_data(valid_records, 'Ozon')
                    records_inserted = inserted
                    records_failed += failed + (len(inventory_records) - len(valid_records))
                    
                    success_msg = (f"Синхронизация Ozon v4 завершена: обработано {records_processed}, "
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
                source='Ozon_v4',
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
            error_msg = f"Критическая ошибка синхронизации Ozon v4: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg, e)
            
            # Проверяем, стоит ли использовать fallback на старые методы
            if self.fallback_to_old_api(e):
                if self.sync_logger:
                    self.sync_logger.log_warning("Пытаемся использовать fallback на старые методы API")
                
                try:
                    # Импортируем старый сервис для fallback
                    from inventory_sync_service_enhanced import EnhancedInventorySyncService
                    
                    fallback_service = EnhancedInventorySyncService()
                    fallback_service.connection = self.connection
                    fallback_service.cursor = self.cursor
                    fallback_service.sync_logger = self.sync_logger
                    
                    # Используем старый метод синхронизации
                    fallback_result = fallback_service.sync_ozon_inventory()
                    
                    if self.sync_logger:
                        self.sync_logger.log_info("Fallback синхронизация выполнена успешно")
                        self.sync_logger.end_sync_session(status=LogSyncStatus.PARTIAL, 
                                                        error_message="Использован fallback метод")
                    
                    # Возвращаем результат fallback с пометкой
                    fallback_result.source = 'Ozon_v4_fallback'
                    fallback_result.error_message = f"Основной v4 API недоступен, использован fallback: {str(e)}"
                    return fallback_result
                    
                except Exception as fallback_error:
                    if self.sync_logger:
                        self.sync_logger.log_error(f"Fallback также не удался: {fallback_error}")
            
            # Если fallback не подходит или не удался
            if self.sync_logger:
                self.sync_logger.end_sync_session(status=LogSyncStatus.FAILED, error_message=str(e))
            
            return SyncResult(
                source='Ozon_v4',
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

    def get_product_id_by_ozon_sku(self, offer_id: str) -> Optional[int]:
        """
        Получение product_id по offer_id товара в Ozon.
        
        Args:
            offer_id: Артикул товара в Ozon
            
        Returns:
            product_id или None если не найден
        """
        try:
            query = """
            SELECT id FROM dim_products 
            WHERE sku_ozon = %s OR sku_internal = %s
            LIMIT 1
            """
            self.cursor.execute(query, (offer_id, offer_id))
            result = self.cursor.fetchone()
            return result['id'] if result else None
        except Exception as e:
            if self.sync_logger:
                self.sync_logger.log_error(f"Ошибка поиска товара по offer_id {offer_id}: {e}")
            return None

    def validate_inventory_data(self, records: List[InventoryRecord], source: str) -> ValidationResult:
        """Валидация данных об остатках."""
        # Конвертируем InventoryRecord в словари для валидатора
        record_dicts = [record.__dict__ for record in records]
        return self.validator.validate_inventory_records(record_dicts, source)

    def filter_valid_records(self, records: List[InventoryRecord], 
                           validation_result: ValidationResult) -> List[InventoryRecord]:
        """Фильтрация валидных записей."""
        return self.validator.filter_valid_records(records, validation_result)

    def update_inventory_data(self, records: List[InventoryRecord], source: str) -> Tuple[int, int, int]:
        """
        Обновление данных об остатках в БД.
        
        Returns:
            Tuple[updated_count, inserted_count, failed_count]
        """
        updated_count = 0
        inserted_count = 0
        failed_count = 0
        
        try:
            for record in records:
                try:
                    # UPSERT запрос для обновления/вставки данных
                    query = """
                    INSERT INTO inventory_data 
                    (product_id, sku, source, warehouse_name, stock_type, 
                     current_stock, reserved_stock, available_stock, 
                     quantity_present, quantity_reserved, snapshot_date, last_sync_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
                    ON DUPLICATE KEY UPDATE
                        current_stock = VALUES(current_stock),
                        reserved_stock = VALUES(reserved_stock),
                        available_stock = VALUES(available_stock),
                        quantity_present = VALUES(quantity_present),
                        quantity_reserved = VALUES(quantity_reserved),
                        snapshot_date = VALUES(snapshot_date),
                        last_sync_at = NOW()
                    """
                    
                    self.cursor.execute(query, (
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
                    
                    if self.cursor.rowcount > 0:
                        if self.cursor.lastrowid:
                            inserted_count += 1
                        else:
                            updated_count += 1
                    
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_error(f"Ошибка сохранения записи для товара {record.sku}: {e}")
                    failed_count += 1
            
            self.connection.commit()
            
        except Exception as e:
            self.connection.rollback()
            if self.sync_logger:
                self.sync_logger.log_error(f"Ошибка транзакции при сохранении данных: {e}")
            failed_count = len(records)
        
        return updated_count, inserted_count, failed_count


def main():
    """Основная функция для тестирования."""
    service = InventorySyncServiceV4()
    
    try:
        service.connect_to_database()
        
        # Тестируем синхронизацию с v4 API
        result = service.sync_ozon_inventory_v4()
        
        print(f"Результат синхронизации:")
        print(f"  Статус: {result.status.value}")
        print(f"  Обработано: {result.records_processed}")
        print(f"  Вставлено: {result.records_inserted}")
        print(f"  Ошибок: {result.records_failed}")
        print(f"  API запросов: {result.api_requests_count}")
        print(f"  Время выполнения: {result.duration_seconds} сек")
        
        if result.error_message:
            print(f"  Ошибка: {result.error_message}")
        
    except Exception as e:
        print(f"❌ Ошибка: {e}")
    finally:
        service.close_database_connection()


if __name__ == "__main__":
    main()