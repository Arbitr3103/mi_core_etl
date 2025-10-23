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
    sku: str = ""  # SKU из поля stocks[].sku в v4 API
    
    def __post_init__(self):
        """Валидация и нормализация данных после создания."""
        self.present = max(0, int(self.present or 0))
        self.reserved = max(0, int(self.reserved or 0))
        self.sku = str(self.sku or "")


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

    def get_ozon_stocks_v3(self, cursor=None, limit=1000, visibility="VISIBLE"):
        """Получение остатков товаров через Ozon v3 API с детализацией по складам."""
        url = "https://api-seller.ozon.ru/v3/product/info/stocks"
        
        headers = {
            "Client-Id": config.OZON_CLIENT_ID,
            "Api-Key": config.OZON_API_KEY,
            "Content-Type": "application/json"
        }
        
        payload = {
            "filter": {
                "visibility": visibility
            },
            "limit": limit
        }
        
        if cursor:
            payload["last_id"] = cursor
            
        if self.sync_logger:
            self.sync_logger.log_info(f"ℹ️ Запрос к Ozon v3 API: cursor={cursor}, limit={limit}, visibility={visibility}")
        
        request_start = time.time()
        response_data = self.make_api_request_with_retry(url, headers, payload)
        request_time = time.time() - request_start
        
        # Логируем использование endpoint
        self.log_endpoint_usage(url, True, request_time)
        
        if not response_data or "result" not in response_data:
            raise Exception("Некорректный ответ от Ozon v3 API")
        
        result = response_data["result"]
        items = result.get("items", [])
        
        if self.sync_logger:
            self.sync_logger.log_info(f"ℹ️ Получено {len(items)} товаров, has_next={result.get('has_next', False)}")
        
        return {
            "items": items,
            "total_items": len(items),
            "has_next": result.get("has_next", False),
            "last_id": result.get("last_id")
        }

    def get_ozon_stocks_v4(self, cursor: str = None, offer_ids: List[str] = None, 
                          visibility: str = "ALL", limit: int = 1000) -> Dict[str, Any]:
        """
        Получение остатков товаров через Ozon v4 API.
        
        Обновленная версия с правильной обработкой структуры данных v4 API:
        - Корректная обработка product_id, offer_id, stocks[]
        - Извлечение SKU из поля stocks[].sku
        - Поддержка всех типов складов: fbo, fbs, realFbs
        
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
        
        # Формируем payload для запроса согласно v4 API документации
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
            
            # Проверяем структуру ответа v4 API (данные в корне, без result)
            if "items" not in data:
                raise ValueError("Неожиданная структура ответа v4 API - отсутствует поле 'items'")
            
            items = data.get("items", [])
            cursor = data.get("cursor", "")
            total = data.get("total", 0)
            has_next = bool(cursor)  # Если есть cursor, значит есть еще данные
            
            # Обрабатываем каждый товар для извлечения правильной структуры данных
            processed_items = []
            for item in items:
                try:
                    # Извлекаем основные поля согласно v4 API
                    product_id = item.get("product_id", 0)
                    offer_id = item.get("offer_id", "")
                    
                    if not product_id or not offer_id:
                        if self.sync_logger:
                            self.sync_logger.log_warning(f"Товар пропущен: product_id={product_id}, offer_id={offer_id}")
                        continue
                    
                    # Обрабатываем массив stocks[] согласно v4 API
                    stocks = item.get("stocks", [])
                    processed_stocks = []
                    
                    for stock in stocks:
                        # Извлекаем SKU из поля stocks[].sku (новая структура v4)
                        sku = stock.get("sku", "")
                        
                        # В реальном API warehouse_id нет, есть warehouse_ids[] (обычно пустой)
                        warehouse_ids = stock.get("warehouse_ids", [])
                        warehouse_id = warehouse_ids[0] if warehouse_ids else 0
                        
                        stock_type = stock.get("type", "fbo")  # fbo, fbs, realFbs
                        
                        # Извлекаем количества
                        present = stock.get("present", 0)
                        reserved = stock.get("reserved", 0)
                        
                        processed_stock = {
                            "sku": sku,
                            "warehouse_id": warehouse_id,
                            "type": stock_type,
                            "present": max(0, int(present or 0)),
                            "reserved": max(0, int(reserved or 0))
                        }
                        processed_stocks.append(processed_stock)
                    
                    processed_item = {
                        "product_id": product_id,
                        "offer_id": offer_id,
                        "stocks": processed_stocks
                    }
                    processed_items.append(processed_item)
                    
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Ошибка обработки товара {item.get('offer_id', 'unknown')}: {e}")
                    continue
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Обработано {len(processed_items)} товаров из {len(items)}, has_next={has_next}")
            
            return {
                "items": processed_items,
                "last_id": cursor,  # Используем cursor как last_id для совместимости
                "has_next": has_next,
                "total_items": len(processed_items),
                "cursor": cursor,
                "total": total
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

    def get_ozon_analytics_stocks(self, date_from: str = None, date_to: str = None, 
                                 limit: int = 1000, offset: int = 0) -> Dict[str, Any]:
        """
        Получение аналитических данных об остатках через Ozon Analytics API.
        
        Обновленная версия с улучшенной обработкой структуры данных:
        - Корректная обработка sku, warehouse_name, promised_amount, free_to_sell_amount, reserved_amount
        - Поддержка пагинации через limit/offset
        - Создание маппинга между основными остатками и аналитическими данными
        - Сохранение детализации по конкретным складам в БД
        
        Args:
            date_from: Дата начала периода (YYYY-MM-DD)
            date_to: Дата окончания периода (YYYY-MM-DD)
            limit: Количество записей в одном запросе (максимум 1000)
            offset: Смещение для пагинации
            
        Returns:
            Dict с результатами API запроса и списком аналитических данных
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
            "limit": min(limit, 1000),  # Максимум 1000 согласно документации
            "offset": offset,
            "metrics": [
                "free_to_sell_amount",
                "promised_amount", 
                "reserved_amount"
            ],
            "dimensions": [
                "sku",
                "warehouse"
            ]
        }
        
        try:
            if self.sync_logger:
                self.sync_logger.log_info(f"Запрос аналитических данных Ozon за период {date_from} - {date_to}, offset={offset}")
            
            request_start = time.time()
            data = self.make_api_request_with_retry(url, headers, payload)
            request_time = time.time() - request_start
            
            # Логируем использование endpoint
            self.log_endpoint_usage(url, True, request_time)
            
            # Проверяем структуру ответа
            if "result" not in data:
                raise ValueError("Неожиданная структура ответа Analytics API")
            
            result = data["result"]
            analytics_data = result.get("rows", [])  # Данные в rows[], а не data[]
            total_count = len(analytics_data)  # Нет totals.count в реальном API
            analytics_stocks = []
            
            # Обновляем кэш складов для корректного маппинга
            self.update_warehouse_cache()
            
            for item in analytics_data:
                try:
                    # В реальном API данные сразу в полях, без dimensions/metrics
                    sku = item.get("sku", "")
                    warehouse_name = item.get("warehouse_name", "")
                    
                    # Ищем warehouse_id по названию в кэше
                    warehouse_id = 0
                    for wh_id, warehouse in self.warehouse_cache.items():
                        if warehouse.warehouse_name == warehouse_name:
                            warehouse_id = wh_id
                            break
                    
                    # Если не найден в кэше, генерируем ID на основе названия
                    if warehouse_id == 0 and warehouse_name:
                        warehouse_id = hash(warehouse_name) % 1000000  # Простой хэш для ID
                    
                    # Извлекаем метрики напрямую из полей
                    free_to_sell_amount = int(item.get("free_to_sell_amount", 0))
                    promised_amount = int(item.get("promised_amount", 0))
                    reserved_amount = int(item.get("reserved_amount", 0))
                    
                    # Создаем запись только если есть sku и хотя бы одна метрика > 0
                    if sku and (free_to_sell_amount > 0 or promised_amount > 0 or reserved_amount > 0):
                        analytics_stock = OzonAnalyticsStock(
                            offer_id=str(sku),  # Используем sku как offer_id
                            warehouse_id=warehouse_id,
                            warehouse_name=warehouse_name or f"Warehouse_{warehouse_id}",
                            free_to_sell_amount=free_to_sell_amount,
                            promised_amount=promised_amount,
                            reserved_amount=reserved_amount
                        )
                        analytics_stocks.append(analytics_stock)
                
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Ошибка обработки аналитических данных: {e}")
                    continue
            
            has_next = len(analytics_data) >= limit and (offset + len(analytics_data)) < total_count
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Получено {len(analytics_stocks)} записей аналитических данных, has_next={has_next}")
            
            return {
                "analytics_stocks": analytics_stocks,
                "total_count": total_count,
                "has_next": has_next,
                "next_offset": offset + len(analytics_data) if has_next else None
            }
            
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

    def get_all_ozon_analytics_stocks(self, date_from: str = None, date_to: str = None) -> List[OzonAnalyticsStock]:
        """
        Получение всех аналитических данных об остатках с пагинацией.
        
        Args:
            date_from: Дата начала периода (YYYY-MM-DD)
            date_to: Дата окончания периода (YYYY-MM-DD)
            
        Returns:
            Полный список аналитических данных об остатках
        """
        all_analytics_stocks = []
        offset = 0
        limit = 1000
        
        try:
            if self.sync_logger:
                self.sync_logger.log_info("Начинаем получение всех аналитических данных с пагинацией")
            
            while True:
                result = self.get_ozon_analytics_stocks(date_from, date_to, limit, offset)
                analytics_stocks = result["analytics_stocks"]
                has_next = result["has_next"]
                
                all_analytics_stocks.extend(analytics_stocks)
                
                if not has_next:
                    break
                
                offset = result["next_offset"]
                
                # Небольшая задержка между запросами для соблюдения rate limits
                time.sleep(0.5)
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Получено всего {len(all_analytics_stocks)} записей аналитических данных")
            
            return all_analytics_stocks
            
        except Exception as e:
            error_msg = f"Ошибка получения всех аналитических данных: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            raise

    def create_stock_mapping(self, main_stocks: List[OzonStockRecord], 
                           analytics_stocks: List[OzonAnalyticsStock]) -> Dict[str, Dict]:
        """
        Создание маппинга между основными остатками и аналитическими данными по складам.
        
        Args:
            main_stocks: Данные из основного API v4
            analytics_stocks: Данные из аналитического API
            
        Returns:
            Словарь с объединенными данными по товарам и складам
        """
        mapping = {}
        
        # Индексируем основные данные
        for stock in main_stocks:
            key = f"{stock.offer_id}_{stock.warehouse_id}"
            mapping[key] = {
                "offer_id": stock.offer_id,
                "product_id": stock.product_id,
                "warehouse_id": stock.warehouse_id,
                "warehouse_name": stock.warehouse_name,
                "stock_type": stock.stock_type,
                "sku": stock.sku,
                # Данные из основного API
                "main_present": stock.present,
                "main_reserved": stock.reserved,
                # Данные из аналитического API (будут добавлены ниже)
                "analytics_free_to_sell": 0,
                "analytics_promised": 0,
                "analytics_reserved": 0,
                "has_analytics_data": False
            }
        
        # Добавляем аналитические данные
        for analytics_stock in analytics_stocks:
            key = f"{analytics_stock.offer_id}_{analytics_stock.warehouse_id}"
            
            if key in mapping:
                # Обновляем существующую запись
                mapping[key].update({
                    "analytics_free_to_sell": analytics_stock.free_to_sell_amount,
                    "analytics_promised": analytics_stock.promised_amount,
                    "analytics_reserved": analytics_stock.reserved_amount,
                    "has_analytics_data": True
                })
            else:
                # Создаем новую запись только с аналитическими данными
                mapping[key] = {
                    "offer_id": analytics_stock.offer_id,
                    "product_id": 0,  # Неизвестно из аналитического API
                    "warehouse_id": analytics_stock.warehouse_id,
                    "warehouse_name": analytics_stock.warehouse_name,
                    "stock_type": "unknown",
                    "sku": "",
                    # Данные из основного API
                    "main_present": 0,
                    "main_reserved": 0,
                    # Данные из аналитического API
                    "analytics_free_to_sell": analytics_stock.free_to_sell_amount,
                    "analytics_promised": analytics_stock.promised_amount,
                    "analytics_reserved": analytics_stock.reserved_amount,
                    "has_analytics_data": True
                }
        
        if self.sync_logger:
            main_only = sum(1 for v in mapping.values() if not v["has_analytics_data"])
            analytics_only = sum(1 for v in mapping.values() if v["main_present"] == 0 and v["has_analytics_data"])
            both_sources = sum(1 for v in mapping.values() if v["main_present"] > 0 and v["has_analytics_data"])
            
            self.sync_logger.log_info(f"Создан маппинг: {len(mapping)} записей, "
                                    f"только основной API: {main_only}, "
                                    f"только аналитический API: {analytics_only}, "
                                    f"оба источника: {both_sources}")
        
        return mapping

    def sync_ozon_inventory_combined(self, offer_ids: List[str] = None, 
                                   visibility: str = "ALL", 
                                   include_analytics: bool = True,
                                   fallback_on_error: bool = True) -> SyncResult:
        """
        Оптимизированная синхронизация остатков с комбинированным использованием API.
        
        Реализует логику:
        1. Получение основных остатков через v4 API
        2. Дополнение данных детализацией по складам через аналитический API
        3. Создание единой структуры данных, объединяющей информацию из обоих источников
        4. Обработка случаев, когда один из API недоступен
        
        Args:
            offer_ids: Список offer_id для фильтрации (опционально)
            visibility: Фильтр по видимости товаров
            include_analytics: Включать ли аналитические данные
            fallback_on_error: Использовать ли fallback при ошибках
            
        Returns:
            SyncResult: Результат синхронизации
        """
        started_at = datetime.now()
        
        # Начинаем сессию логирования
        if self.sync_logger:
            self.sync_logger.start_sync_session(SyncType.INVENTORY, "Ozon_Combined")
            self.sync_logger.log_info("Начинаем комбинированную синхронизацию остатков с Ozon")
        
        records_processed = 0
        records_inserted = 0
        records_failed = 0
        api_requests = 0
        main_api_success = False
        analytics_api_success = False
        
        try:
            # Шаг 1: Получение основных остатков через v4 API
            if self.sync_logger:
                self.sync_logger.log_info("Шаг 1: Получение основных остатков через v4 API")
            
            main_stocks = []
            try:
                cursor = None
                while True:
                    result = self.get_ozon_stocks_v4(cursor, offer_ids, visibility)
                    api_requests += 1
                    
                    items = result.get("items", [])
                    if not items:
                        break
                    
                    # Обрабатываем полученные данные
                    batch_stocks = self.process_ozon_v4_stocks(items)
                    main_stocks.extend(batch_stocks)
                    records_processed += len(items)
                    
                    # Проверяем наличие следующей страницы
                    if not result.get("has_next", False):
                        break
                    
                    cursor = result.get("last_id")
                    if not cursor:
                        break
                
                main_api_success = True
                if self.sync_logger:
                    self.sync_logger.log_info(f"Основной API: получено {len(main_stocks)} записей остатков")
                
            except Exception as e:
                error_msg = f"Ошибка основного API v4: {e}"
                if self.sync_logger:
                    self.sync_logger.log_error(error_msg)
                
                if not fallback_on_error:
                    raise
                
                # Пробуем fallback к v3 API
                if self.sync_logger:
                    self.sync_logger.log_info("Используем fallback к v3 API")
                
                try:
                    cursor = None
                    while True:
                        result = self.get_ozon_stocks_v3(cursor, 1000, visibility)
                        api_requests += 1
                        
                        items = result.get("items", [])
                        if not items:
                            break
                        
                        # Конвертируем v3 данные в формат v4
                        v4_items = self._convert_v3_to_v4_format(items)
                        batch_stocks = self.process_ozon_v4_stocks(v4_items)
                        main_stocks.extend(batch_stocks)
                        records_processed += len(items)
                        
                        if not result.get("has_next", False):
                            break
                        
                        cursor = result.get("last_id")
                        if not cursor:
                            break
                    
                    main_api_success = True
                    if self.sync_logger:
                        self.sync_logger.log_info(f"Fallback API: получено {len(main_stocks)} записей остатков")
                
                except Exception as fallback_error:
                    if self.sync_logger:
                        self.sync_logger.log_error(f"Fallback API также недоступен: {fallback_error}")
                    raise
            
            # Шаг 2: Получение аналитических данных (если включено)
            analytics_stocks = []
            if include_analytics and main_api_success:
                if self.sync_logger:
                    self.sync_logger.log_info("Шаг 2: Получение аналитических данных")
                
                try:
                    analytics_stocks = self.get_all_ozon_analytics_stocks()
                    analytics_api_success = True
                    api_requests += 5  # Примерное количество запросов для аналитики
                    
                    if self.sync_logger:
                        self.sync_logger.log_info(f"Аналитический API: получено {len(analytics_stocks)} записей")
                
                except Exception as e:
                    error_msg = f"Ошибка аналитического API: {e}"
                    if self.sync_logger:
                        self.sync_logger.log_error(error_msg)
                    
                    # Аналитические данные не критичны, продолжаем без них
                    analytics_stocks = []
            
            # Шаг 3: Создание единой структуры данных
            if self.sync_logger:
                self.sync_logger.log_info("Шаг 3: Объединение данных из разных источников")
            
            stock_mapping = self.create_stock_mapping(main_stocks, analytics_stocks)
            
            # Валидация объединенных данных
            if self.validator:
                combined_records = list(stock_mapping.values())
                validation_result = self.validator.validate_combined_stock_data(combined_records, "Ozon")
                
                if self.sync_logger:
                    self.sync_logger.log_info(f"Валидация: {validation_result.valid_records}/{validation_result.total_records} записей валидны")
                
                # Логируем критические ошибки валидации
                for issue in validation_result.issues:
                    if issue.severity.value == "error":
                        if self.sync_logger:
                            self.sync_logger.log_error(f"Валидация: {issue}")
            
            # Шаг 4: Сохранение в БД
            if self.sync_logger:
                self.sync_logger.log_info("Шаг 4: Сохранение объединенных данных в БД")
            
            # Конвертируем в формат InventoryRecord
            inventory_records = self.convert_to_inventory_records(main_stocks)
            
            # Сохраняем основные данные
            if inventory_records:
                saved_count = self.save_inventory_records(inventory_records)
                records_inserted = saved_count
                
                if self.sync_logger:
                    self.sync_logger.log_info(f"Сохранено {saved_count} основных записей остатков")
            
            # Сохраняем детализацию по складам
            if stock_mapping:
                self.save_warehouse_stock_details(stock_mapping)
                
                if self.sync_logger:
                    self.sync_logger.log_info(f"Сохранена детализация по {len(stock_mapping)} складам")
            
            # Сохраняем сравнения (если есть аналитические данные)
            if analytics_api_success and main_stocks and analytics_stocks:
                comparisons = self.compare_stock_data(main_stocks, analytics_stocks)
                if comparisons:
                    self.save_stock_comparisons(comparisons)
                    
                    # Генерируем алерты при значительных расхождениях
                    alerts = self.generate_discrepancy_alerts(comparisons)
                    if alerts and self.sync_logger:
                        for alert in alerts:
                            self.sync_logger.log_warning(f"Алерт: {alert['message']}")
            
            # Завершаем синхронизацию
            completed_at = datetime.now()
            
            # Определяем статус синхронизации
            if main_api_success and records_inserted > 0:
                if analytics_api_success or not include_analytics:
                    status = SyncStatus.SUCCESS
                else:
                    status = SyncStatus.PARTIAL  # Основные данные есть, аналитических нет
            else:
                status = SyncStatus.FAILED
            
            result = SyncResult(
                source="Ozon_Combined",
                status=status,
                records_processed=records_processed,
                records_updated=0,  # Будет вычислено в save_inventory_records
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=completed_at,
                api_requests_count=api_requests
            )
            
            # Логируем результат
            if self.sync_logger:
                self.sync_logger.log_sync_completion(
                    status=LogSyncStatus.SUCCESS if status == SyncStatus.SUCCESS else LogSyncStatus.PARTIAL,
                    stats=ProcessingStats(
                        records_processed=records_processed,
                        records_inserted=records_inserted,
                        records_updated=0,
                        records_failed=records_failed,
                        api_requests=api_requests
                    ),
                    duration_seconds=result.duration_seconds
                )
                
                self.sync_logger.log_info(f"Комбинированная синхронизация завершена: {status.value}")
            
            return result
            
        except Exception as e:
            completed_at = datetime.now()
            error_msg = f"Критическая ошибка комбинированной синхронизации: {e}"
            
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
                self.sync_logger.log_sync_completion(
                    status=LogSyncStatus.FAILED,
                    stats=ProcessingStats(
                        records_processed=records_processed,
                        records_inserted=records_inserted,
                        records_updated=0,
                        records_failed=records_failed,
                        api_requests=api_requests
                    ),
                    duration_seconds=int((completed_at - started_at).total_seconds()),
                    error_message=error_msg
                )
            
            return SyncResult(
                source="Ozon_Combined",
                status=SyncStatus.FAILED,
                records_processed=records_processed,
                records_updated=0,
                records_inserted=records_inserted,
                records_failed=records_failed,
                started_at=started_at,
                completed_at=completed_at,
                error_message=error_msg,
                api_requests_count=api_requests
            )

    def _convert_v3_to_v4_format(self, v3_items: List[Dict]) -> List[Dict]:
        """
        Конвертация данных из v3 API в формат v4 API для совместимости.
        
        Args:
            v3_items: Список товаров из v3 API
            
        Returns:
            Список товаров в формате v4 API
        """
        v4_items = []
        
        for item in v3_items:
            try:
                # Извлекаем основные поля из v3
                offer_id = item.get("offer_id", "")
                product_id = item.get("product_id", 0)
                
                # Конвертируем остатки в формат v4
                stocks = []
                
                # v3 API возвращает остатки в другом формате
                v3_stocks = item.get("stocks", [])
                for stock in v3_stocks:
                    v4_stock = {
                        "sku": offer_id,  # В v3 SKU обычно равен offer_id
                        "warehouse_id": stock.get("warehouse_id", 0),
                        "type": stock.get("type", "fbo"),
                        "present": stock.get("present", 0),
                        "reserved": stock.get("reserved", 0)
                    }
                    stocks.append(v4_stock)
                
                # Если нет детализации по складам, создаем общую запись
                if not stocks:
                    stocks.append({
                        "sku": offer_id,
                        "warehouse_id": 0,
                        "type": "fbo",
                        "present": item.get("present", 0),
                        "reserved": item.get("reserved", 0)
                    })
                
                v4_item = {
                    "offer_id": offer_id,
                    "product_id": product_id,
                    "stocks": stocks
                }
                v4_items.append(v4_item)
                
            except Exception as e:
                if self.sync_logger:
                    self.sync_logger.log_warning(f"Ошибка конвертации v3->v4 для товара {item.get('offer_id', 'unknown')}: {e}")
                continue
        
        return v4_items

    def handle_api_unavailability(self, primary_error: Exception, api_name: str) -> Dict[str, Any]:
        """
        Обработка случаев недоступности API с определением стратегии восстановления.
        
        Args:
            primary_error: Исключение от основного API
            api_name: Название API для логирования
            
        Returns:
            Словарь с информацией о стратегии восстановления
        """
        recovery_strategy = {
            "use_fallback": False,
            "use_cache": False,
            "skip_api": False,
            "retry_later": False,
            "error_type": type(primary_error).__name__,
            "error_message": str(primary_error)
        }
        
        if self.sync_logger:
            self.sync_logger.log_error(f"API {api_name} недоступен: {primary_error}")
        
        # Определяем стратегию на основе типа ошибки
        if isinstance(primary_error, OzonRateLimitError):
            recovery_strategy["retry_later"] = True
            recovery_strategy["retry_delay"] = 300  # 5 минут
            if self.sync_logger:
                self.sync_logger.log_info("Стратегия: повтор через 5 минут из-за rate limit")
        
        elif isinstance(primary_error, OzonAuthenticationError):
            recovery_strategy["skip_api"] = True
            if self.sync_logger:
                self.sync_logger.log_error("Стратегия: пропуск API из-за ошибки аутентификации")
        
        elif isinstance(primary_error, OzonServerError):
            recovery_strategy["use_fallback"] = True
            if self.sync_logger:
                self.sync_logger.log_info("Стратегия: использование fallback API")
        
        elif isinstance(primary_error, (requests.exceptions.ConnectionError, requests.exceptions.Timeout)):
            recovery_strategy["use_fallback"] = True
            recovery_strategy["use_cache"] = True
            if self.sync_logger:
                self.sync_logger.log_info("Стратегия: fallback API + кэш")
        
        else:
            # Неизвестная ошибка - пробуем fallback
            recovery_strategy["use_fallback"] = True
            if self.sync_logger:
                self.sync_logger.log_info("Стратегия: fallback API для неизвестной ошибки")
        
        return recovery_strategy

    def get_cached_stock_data(self, max_age_hours: int = 24) -> List[OzonStockRecord]:
        """
        Получение кэшированных данных об остатках из БД.
        
        Args:
            max_age_hours: Максимальный возраст кэша в часах
            
        Returns:
            Список кэшированных записей об остатках
        """
        try:
            if self.sync_logger:
                self.sync_logger.log_info(f"Получаем кэшированные данные (возраст <= {max_age_hours}ч)")
            
            query = """
            SELECT 
                product_id, sku as offer_id, warehouse_name, stock_type,
                current_stock as present, reserved_stock as reserved,
                0 as warehouse_id
            FROM inventory_data 
            WHERE source = 'Ozon' 
                AND last_sync_at >= DATE_SUB(NOW(), INTERVAL %s HOUR)
                AND current_stock > 0
            ORDER BY last_sync_at DESC
            """
            
            self.cursor.execute(query, (max_age_hours,))
            cached_rows = self.cursor.fetchall()
            
            cached_stocks = []
            for row in cached_rows:
                try:
                    stock_record = OzonStockRecord(
                        offer_id=row["offer_id"],
                        product_id=row["product_id"],
                        warehouse_id=row["warehouse_id"],
                        warehouse_name=row["warehouse_name"],
                        stock_type=row["stock_type"],
                        present=row["present"],
                        reserved=row["reserved"],
                        sku=row["offer_id"]
                    )
                    cached_stocks.append(stock_record)
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Ошибка обработки кэшированной записи: {e}")
                    continue
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Получено {len(cached_stocks)} кэшированных записей")
            
            return cached_stocks
            
        except Exception as e:
            error_msg = f"Ошибка получения кэшированных данных: {e}"
            if self.sync_logger:
                self.sync_logger.log_error(error_msg)
            return []

    def create_unified_data_structure(self, main_stocks: List[OzonStockRecord], 
                                    analytics_stocks: List[OzonAnalyticsStock],
                                    include_metadata: bool = True) -> Dict[str, Any]:
        """
        Создание единой структуры данных, объединяющей информацию из обоих источников.
        
        Args:
            main_stocks: Данные из основного API
            analytics_stocks: Данные из аналитического API
            include_metadata: Включать ли метаданные о источниках
            
        Returns:
            Единая структура данных с метаданными
        """
        unified_structure = {
            "metadata": {
                "main_api_records": len(main_stocks),
                "analytics_api_records": len(analytics_stocks),
                "combined_records": 0,
                "main_only_records": 0,
                "analytics_only_records": 0,
                "timestamp": datetime.now().isoformat(),
                "data_sources": []
            } if include_metadata else {},
            "stock_data": {},
            "warehouse_summary": {},
            "discrepancies": []
        }
        
        # Создаем маппинг данных
        stock_mapping = self.create_stock_mapping(main_stocks, analytics_stocks)
        unified_structure["stock_data"] = stock_mapping
        
        # Обновляем метаданные
        if include_metadata:
            unified_structure["metadata"]["combined_records"] = len(stock_mapping)
            unified_structure["metadata"]["main_only_records"] = sum(
                1 for v in stock_mapping.values() if not v["has_analytics_data"]
            )
            unified_structure["metadata"]["analytics_only_records"] = sum(
                1 for v in stock_mapping.values() if v["main_present"] == 0 and v["has_analytics_data"]
            )
            
            # Информация об источниках данных
            if main_stocks:
                unified_structure["metadata"]["data_sources"].append("main_api_v4")
            if analytics_stocks:
                unified_structure["metadata"]["data_sources"].append("analytics_api_v2")
        
        # Создаем сводку по складам
        warehouse_summary = {}
        for key, stock_data in stock_mapping.items():
            warehouse_id = stock_data["warehouse_id"]
            warehouse_name = stock_data["warehouse_name"]
            
            if warehouse_id not in warehouse_summary:
                warehouse_summary[warehouse_id] = {
                    "warehouse_name": warehouse_name,
                    "total_products": 0,
                    "total_present": 0,
                    "total_reserved": 0,
                    "has_analytics": False
                }
            
            warehouse_summary[warehouse_id]["total_products"] += 1
            warehouse_summary[warehouse_id]["total_present"] += stock_data["main_present"]
            warehouse_summary[warehouse_id]["total_reserved"] += stock_data["main_reserved"]
            
            if stock_data["has_analytics_data"]:
                warehouse_summary[warehouse_id]["has_analytics"] = True
        
        unified_structure["warehouse_summary"] = warehouse_summary
        
        # Выявляем значительные расхождения
        if main_stocks and analytics_stocks:
            comparisons = self.compare_stock_data(main_stocks, analytics_stocks)
            significant_discrepancies = [
                {
                    "offer_id": c.offer_id,
                    "warehouse_id": c.warehouse_id,
                    "main_present": c.main_api_present,
                    "analytics_free_to_sell": c.analytics_free_to_sell,
                    "discrepancy": c.discrepancy_present
                }
                for c in comparisons if c.has_significant_discrepancy
            ]
            unified_structure["discrepancies"] = significant_discrepancies
        
        if self.sync_logger:
            self.sync_logger.log_info(f"Создана единая структура данных: {len(stock_mapping)} записей, "
                                    f"{len(warehouse_summary)} складов, "
                                    f"{len(unified_structure.get('discrepancies', []))} расхождений")
        
        return unified_structure

    def save_warehouse_stock_details(self, stock_mapping: Dict[str, Dict]) -> None:
        """
        Сохранение детализации по конкретным складам в БД.
        
        Args:
            stock_mapping: Маппинг с объединенными данными по товарам и складам
        """
        try:
            if self.sync_logger:
                self.sync_logger.log_info(f"Сохраняем детализацию по {len(stock_mapping)} складам в БД")
            
            # Создаем таблицу для детализации по складам если не существует
            create_table_query = """
            CREATE TABLE IF NOT EXISTS ozon_warehouse_stock_details (
                id INT AUTO_INCREMENT PRIMARY KEY,
                offer_id VARCHAR(255) NOT NULL,
                product_id INT DEFAULT 0,
                warehouse_id INT NOT NULL,
                warehouse_name VARCHAR(255) NOT NULL,
                stock_type VARCHAR(50) DEFAULT 'unknown',
                sku VARCHAR(255) DEFAULT '',
                main_present INT DEFAULT 0,
                main_reserved INT DEFAULT 0,
                analytics_free_to_sell INT DEFAULT 0,
                analytics_promised INT DEFAULT 0,
                analytics_reserved INT DEFAULT 0,
                has_analytics_data BOOLEAN DEFAULT FALSE,
                snapshot_date DATE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_offer_warehouse_date (offer_id, warehouse_id, snapshot_date),
                INDEX idx_offer_id (offer_id),
                INDEX idx_warehouse_id (warehouse_id),
                INDEX idx_snapshot_date (snapshot_date),
                INDEX idx_has_analytics (has_analytics_data)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """
            self.cursor.execute(create_table_query)
            
            # Сохраняем детализацию по складам
            snapshot_date = date.today()
            saved_count = 0
            
            for key, stock_data in stock_mapping.items():
                try:
                    upsert_query = """
                    INSERT INTO ozon_warehouse_stock_details 
                    (offer_id, product_id, warehouse_id, warehouse_name, stock_type, sku,
                     main_present, main_reserved, analytics_free_to_sell, analytics_promised,
                     analytics_reserved, has_analytics_data, snapshot_date)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        product_id = VALUES(product_id),
                        warehouse_name = VALUES(warehouse_name),
                        stock_type = VALUES(stock_type),
                        sku = VALUES(sku),
                        main_present = VALUES(main_present),
                        main_reserved = VALUES(main_reserved),
                        analytics_free_to_sell = VALUES(analytics_free_to_sell),
                        analytics_promised = VALUES(analytics_promised),
                        analytics_reserved = VALUES(analytics_reserved),
                        has_analytics_data = VALUES(has_analytics_data),
                        updated_at = CURRENT_TIMESTAMP
                    """
                    
                    self.cursor.execute(upsert_query, (
                        stock_data["offer_id"],
                        stock_data["product_id"],
                        stock_data["warehouse_id"],
                        stock_data["warehouse_name"],
                        stock_data["stock_type"],
                        stock_data["sku"],
                        stock_data["main_present"],
                        stock_data["main_reserved"],
                        stock_data["analytics_free_to_sell"],
                        stock_data["analytics_promised"],
                        stock_data["analytics_reserved"],
                        stock_data["has_analytics_data"],
                        snapshot_date
                    ))
                    
                    saved_count += 1
                    
                except Exception as e:
                    if self.sync_logger:
                        self.sync_logger.log_error(f"Ошибка сохранения детализации для {stock_data['offer_id']}: {e}")
                    continue
            
            self.connection.commit()
            
            if self.sync_logger:
                self.sync_logger.log_info(f"Детализация по складам успешно сохранена: {saved_count} записей")
            
        except Exception as e:
            self.connection.rollback()
            error_msg = f"Ошибка сохранения детализации по складам в БД: {e}"
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
        
        Обновленная версия для работы с новой структурой v4 API:
        - Корректная обработка product_id, offer_id, stocks[]
        - Извлечение SKU из поля stocks[].sku
        - Поддержка всех типов складов: fbo, fbs, realFbs
        
        Args:
            api_items: Список товаров из API ответа (уже обработанных в get_ozon_stocks_v4)
            
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
                
                # Используем product_id из v4 API ответа
                product_id = item.get("product_id", 0)
                if not product_id:
                    if self.sync_logger:
                        self.sync_logger.log_warning(f"Товар {offer_id} без product_id пропущен")
                    continue
                
                # Обрабатываем остатки по складам из массива stocks[]
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
                
                # Обрабатываем каждый склад из массива stocks[]
                for stock in stocks:
                    # Извлекаем SKU из поля stocks[].sku (новая структура v4)
                    sku = stock.get("sku", "")
                    
                    # В реальном API warehouse_id нет, есть warehouse_ids[] (обычно пустой)
                    warehouse_ids = stock.get("warehouse_ids", [])
                    warehouse_id = warehouse_ids[0] if warehouse_ids else 0
                    warehouse_name = self.get_warehouse_name(warehouse_id)
                    
                    # Поддержка всех типов складов: fbo, fbs, realFbs
                    stock_type = stock.get("type", "fbo")
                    if stock_type not in ["fbo", "fbs", "realFbs"]:
                        stock_type = "fbo"  # Fallback к FBO
                    
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
                        reserved=reserved,
                        sku=str(sku)  # SKU как строка
                    )
                    
                    stock_records.append(stock_record)
                
            except Exception as e:
                error_msg = f"Ошибка обработки товара {item.get('offer_id', 'unknown')}: {e}"
                if self.sync_logger:
                    self.sync_logger.log_error(error_msg)
                continue
        
        if self.sync_logger:
            self.sync_logger.log_info(f"Обработано {len(stock_records)} записей остатков из {len(api_items)} товаров")
        
        return stock_records

    def convert_to_inventory_records(self, ozon_stocks: List[OzonStockRecord]) -> List[InventoryRecord]:
        """
        Конвертация записей Ozon в универсальный формат InventoryRecord.
        
        Обновленная версия для работы с v4 API:
        - Использует SKU из поля stocks[].sku если доступно
        - Fallback к offer_id если SKU отсутствует
        - Поддержка всех типов складов: fbo, fbs, realFbs
        
        Args:
            ozon_stocks: Список записей об остатках с Ozon
            
        Returns:
            Список записей в формате InventoryRecord
        """
        inventory_records = []
        
        for stock in ozon_stocks:
            try:
                # Используем SKU из v4 API если доступно, иначе fallback к offer_id
                sku_value = stock.sku if stock.sku else stock.offer_id
                
                inventory_record = InventoryRecord(
                    product_id=stock.product_id,
                    sku=sku_value,  # Используем SKU из stocks[].sku (v4 API)
                    source='Ozon',
                    warehouse_name=stock.warehouse_name,
                    stock_type=stock.stock_type.upper(),  # FBO, FBS, REALFBS
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
        
        if self.sync_logger:
            self.sync_logger.log_info(f"Конвертировано {len(inventory_records)} записей из {len(ozon_stocks)} остатков")
        
        return inventory_records

    def convert_analytics_to_inventory_records(self, analytics_stocks: List[OzonAnalyticsStock]) -> List[InventoryRecord]:
        """
        Конвертация аналитических данных в записи InventoryRecord для сохранения детализации по складам.
        
        Args:
            analytics_stocks: Список аналитических данных об остатках
            
        Returns:
            Список записей в формате InventoryRecord с детализацией по складам
        """
        inventory_records = []
        
        for analytics_stock in analytics_stocks:
            try:
                # Создаем запись для каждого склада из аналитических данных
                inventory_record = InventoryRecord(
                    product_id=0,  # Неизвестно из аналитического API, будет обновлено при маппинге
                    sku=analytics_stock.offer_id,
                    source="Ozon_Analytics",  # Отдельный источник для аналитических данных
                    warehouse_name=analytics_stock.warehouse_name,
                    stock_type="analytics",  # Специальный тип для аналитических данных
                    current_stock=analytics_stock.promised_amount + analytics_stock.free_to_sell_amount,
                    reserved_stock=analytics_stock.reserved_amount,
                    available_stock=analytics_stock.free_to_sell_amount,
                    quantity_present=analytics_stock.promised_amount + analytics_stock.free_to_sell_amount,
                    quantity_reserved=analytics_stock.reserved_amount,
                    snapshot_date=datetime.now().date()
                )
                
                inventory_records.append(inventory_record)
                
            except Exception as e:
                if self.sync_logger:
                    self.sync_logger.log_error(f"Ошибка конвертации аналитических данных для SKU {analytics_stock.offer_id}: {e}")
                continue
        
        if self.sync_logger:
            self.sync_logger.log_info(f"Конвертировано {len(inventory_records)} аналитических записей из {len(analytics_stocks)} складских данных")
        
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
                
                # Получаем аналитические данные за сегодня
                today = datetime.now().strftime('%Y-%m-%d')
                analytics_result = self.get_ozon_analytics_stocks(
                    date_from=today,
                    date_to=today,
                    limit=1000,
                    offset=0
                )
                analytics_stocks = analytics_result.get("analytics_stocks", [])
                
                if analytics_stocks:
                    # Конвертируем аналитические данные в записи для БД
                    analytics_inventory_records = self.convert_analytics_to_inventory_records(analytics_stocks)
                    
                    if analytics_inventory_records:
                        if self.sync_logger:
                            self.sync_logger.log_info(f"Сохраняем {len(analytics_inventory_records)} записей аналитических данных по складам")
                        
                        # Сохраняем аналитические данные как отдельные записи
                        analytics_updated, analytics_inserted, analytics_failed = self.update_inventory_data(
                            analytics_inventory_records, 'Ozon_Analytics'
                        )
                        
                        if self.sync_logger:
                            self.sync_logger.log_info(f"Аналитические данные: обновлено {analytics_updated}, вставлено {analytics_inserted}, ошибок {analytics_failed}")
                    
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
        if validation_result.is_valid:
            return records
        else:
            # Если есть критические ошибки, возвращаем пустой список
            # В противном случае возвращаем все записи (предупреждения не блокируют)
            if any("ERROR" in issue for issue in validation_result.issues):
                return []
            return records

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