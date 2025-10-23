#!/usr/bin/env python3
"""
Модуль обработки ошибок и восстановления для системы синхронизации остатков.

Реализует стратегии обработки ошибок API, retry логику с экспоненциальной задержкой,
обработку rate limits маркетплейсов и fallback механизмы при недоступности API.

Автор: ETL System
Дата: 06 января 2025
"""

import time
import logging
import random
from datetime import datetime, timedelta
from typing import Dict, Any, Optional, Callable, List, Tuple
from dataclasses import dataclass
from enum import Enum
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

logger = logging.getLogger(__name__)


class ErrorType(Enum):
    """Типы ошибок синхронизации."""
    API_TIMEOUT = "api_timeout"
    API_RATE_LIMIT = "api_rate_limit"
    API_UNAVAILABLE = "api_unavailable"
    API_AUTH_ERROR = "api_auth_error"
    DATA_CORRUPTION = "data_corruption"
    DATABASE_ERROR = "database_error"
    NETWORK_ERROR = "network_error"
    UNKNOWN_ERROR = "unknown_error"


class RecoveryAction(Enum):
    """Действия для восстановления."""
    RETRY = "retry"
    SKIP = "skip"
    FALLBACK = "fallback"
    ABORT = "abort"
    WAIT_AND_RETRY = "wait_and_retry"


@dataclass
class ErrorContext:
    """Контекст ошибки для принятия решений о восстановлении."""
    error_type: ErrorType
    source: str
    attempt_number: int
    error_message: str
    response_code: Optional[int] = None
    retry_after: Optional[int] = None
    timestamp: datetime = None
    
    def __post_init__(self):
        if self.timestamp is None:
            self.timestamp = datetime.now()


@dataclass
class RetryConfig:
    """Конфигурация retry логики."""
    max_attempts: int = 3
    base_delay: float = 1.0
    max_delay: float = 60.0
    exponential_base: float = 2.0
    jitter: bool = True
    backoff_factor: float = 1.0


class APIErrorHandler:
    """
    Обработчик ошибок API с retry логикой и экспоненциальной задержкой.
    
    Поддерживает:
    - Retry с экспоненциальной задержкой
    - Обработку rate limits
    - Fallback механизмы
    - Адаптивные стратегии восстановления
    """
    
    def __init__(self, retry_config: Optional[RetryConfig] = None):
        """
        Инициализация обработчика ошибок.
        
        Args:
            retry_config: Конфигурация retry логики
        """
        self.retry_config = retry_config or RetryConfig()
        self.error_history: Dict[str, List[ErrorContext]] = {}
        self.rate_limit_info: Dict[str, Dict[str, Any]] = {}
        
        # Настройка HTTP сессии с retry
        self.session = requests.Session()
        try:
            # Пытаемся использовать новый API (urllib3 >= 1.26)
            retry_strategy = Retry(
                total=self.retry_config.max_attempts,
                status_forcelist=[429, 500, 502, 503, 504],
                allowed_methods=["HEAD", "GET", "OPTIONS", "POST"],
                backoff_factor=self.retry_config.backoff_factor
            )
        except TypeError:
            # Fallback для старых версий urllib3
            try:
                retry_strategy = Retry(
                    total=self.retry_config.max_attempts,
                    status_forcelist=[429, 500, 502, 503, 504],
                    method_whitelist=["HEAD", "GET", "OPTIONS", "POST"],
                    backoff_factor=self.retry_config.backoff_factor
                )
            except TypeError:
                # Минимальная конфигурация для совместимости
                retry_strategy = Retry(
                    total=self.retry_config.max_attempts,
                    backoff_factor=self.retry_config.backoff_factor
                )
        
        adapter = HTTPAdapter(max_retries=retry_strategy)
        self.session.mount("http://", adapter)
        self.session.mount("https://", adapter)
    
    def classify_error(self, exception: Exception, response: Optional[requests.Response] = None) -> ErrorType:
        """
        Классификация типа ошибки.
        
        Args:
            exception: Исключение
            response: HTTP ответ (если есть)
            
        Returns:
            ErrorType: Тип ошибки
        """
        if response:
            status_code = response.status_code
            
            if status_code == 429:
                return ErrorType.API_RATE_LIMIT
            elif status_code in [401, 403]:
                return ErrorType.API_AUTH_ERROR
            elif status_code in [500, 502, 503, 504]:
                return ErrorType.API_UNAVAILABLE
            elif status_code == 408:
                return ErrorType.API_TIMEOUT
        
        if isinstance(exception, requests.exceptions.Timeout):
            return ErrorType.API_TIMEOUT
        elif isinstance(exception, requests.exceptions.ConnectionError):
            return ErrorType.NETWORK_ERROR
        elif isinstance(exception, requests.exceptions.RequestException):
            return ErrorType.API_UNAVAILABLE
        else:
            return ErrorType.UNKNOWN_ERROR
    
    def calculate_delay(self, attempt: int, error_type: ErrorType, retry_after: Optional[int] = None) -> float:
        """
        Вычисление задержки перед повторной попыткой.
        
        Args:
            attempt: Номер попытки
            error_type: Тип ошибки
            retry_after: Время ожидания из заголовка Retry-After
            
        Returns:
            float: Задержка в секундах
        """
        if retry_after:
            # Используем значение из заголовка Retry-After
            return min(float(retry_after), self.retry_config.max_delay)
        
        # Базовая экспоненциальная задержка
        delay = self.retry_config.base_delay * (self.retry_config.exponential_base ** (attempt - 1))
        
        # Специальные случаи для разных типов ошибок (применяем до jitter)
        if error_type == ErrorType.API_RATE_LIMIT:
            delay *= 2  # Увеличиваем задержку для rate limits
        elif error_type == ErrorType.API_TIMEOUT:
            delay *= 1.5  # Умеренное увеличение для timeouts
        
        # Ограничиваем максимальной задержкой
        delay = min(delay, self.retry_config.max_delay)
        
        # Добавляем jitter для избежания thundering herd
        if self.retry_config.jitter:
            jitter = random.uniform(0.1, 0.3) * delay
            delay += jitter
        
        return delay
    
    def should_retry(self, error_context: ErrorContext) -> bool:
        """
        Определение необходимости повторной попытки.
        
        Args:
            error_context: Контекст ошибки
            
        Returns:
            bool: True если нужно повторить попытку
        """
        # Проверяем максимальное количество попыток
        if error_context.attempt_number >= self.retry_config.max_attempts:
            return False
        
        # Не повторяем для ошибок аутентификации
        if error_context.error_type == ErrorType.API_AUTH_ERROR:
            return False
        
        # Проверяем историю ошибок для адаптивного поведения
        source_history = self.error_history.get(error_context.source, [])
        recent_errors = [
            err for err in source_history 
            if err.timestamp > datetime.now() - timedelta(minutes=30)
        ]
        
        # Если слишком много недавних ошибок, прекращаем попытки
        if len(recent_errors) > 10:
            logger.warning(f"Слишком много ошибок для {error_context.source}, прекращаем попытки")
            return False
        
        return True
    
    def record_error(self, error_context: ErrorContext):
        """
        Запись ошибки в историю.
        
        Args:
            error_context: Контекст ошибки
        """
        if error_context.source not in self.error_history:
            self.error_history[error_context.source] = []
        
        self.error_history[error_context.source].append(error_context)
        
        # Ограничиваем размер истории
        if len(self.error_history[error_context.source]) > 100:
            self.error_history[error_context.source] = self.error_history[error_context.source][-50:]
    
    def update_rate_limit_info(self, source: str, response: requests.Response):
        """
        Обновление информации о rate limits.
        
        Args:
            source: Источник API
            response: HTTP ответ
        """
        if source not in self.rate_limit_info:
            self.rate_limit_info[source] = {}
        
        # Извлекаем информацию о rate limits из заголовков
        headers = response.headers
        
        # Стандартные заголовки rate limiting
        if 'X-RateLimit-Limit' in headers:
            self.rate_limit_info[source]['limit'] = int(headers['X-RateLimit-Limit'])
        if 'X-RateLimit-Remaining' in headers:
            self.rate_limit_info[source]['remaining'] = int(headers['X-RateLimit-Remaining'])
        if 'X-RateLimit-Reset' in headers:
            self.rate_limit_info[source]['reset'] = int(headers['X-RateLimit-Reset'])
        
        # Retry-After для 429 ошибок
        if response.status_code == 429 and 'Retry-After' in headers:
            self.rate_limit_info[source]['retry_after'] = int(headers['Retry-After'])
            self.rate_limit_info[source]['retry_after_timestamp'] = datetime.now()
    
    def check_rate_limit(self, source: str) -> Optional[float]:
        """
        Проверка необходимости ожидания из-за rate limits.
        
        Args:
            source: Источник API
            
        Returns:
            Optional[float]: Время ожидания в секундах или None
        """
        if source not in self.rate_limit_info:
            return None
        
        rate_info = self.rate_limit_info[source]
        
        # Проверяем Retry-After
        if 'retry_after' in rate_info and 'retry_after_timestamp' in rate_info:
            elapsed = (datetime.now() - rate_info['retry_after_timestamp']).total_seconds()
            if elapsed < rate_info['retry_after']:
                return rate_info['retry_after'] - elapsed
        
        # Проверяем оставшиеся запросы
        if 'remaining' in rate_info and rate_info['remaining'] <= 1:
            if 'reset' in rate_info:
                reset_time = datetime.fromtimestamp(rate_info['reset'])
                if reset_time > datetime.now():
                    return (reset_time - datetime.now()).total_seconds()
        
        return None
    
    def execute_with_retry(
        self, 
        func: Callable, 
        source: str, 
        *args, 
        **kwargs
    ) -> Tuple[Any, Optional[ErrorContext]]:
        """
        Выполнение функции с retry логикой.
        
        Args:
            func: Функция для выполнения
            source: Источник API
            *args: Аргументы функции
            **kwargs: Именованные аргументы функции
            
        Returns:
            Tuple[Any, Optional[ErrorContext]]: Результат и контекст ошибки (если была)
        """
        attempt = 1
        last_error_context = None
        
        while attempt <= self.retry_config.max_attempts:
            try:
                # Проверяем rate limits перед запросом
                wait_time = self.check_rate_limit(source)
                if wait_time and wait_time > 0:
                    logger.info(f"Ожидание {wait_time:.1f}с из-за rate limit для {source}")
                    time.sleep(wait_time)
                
                # Выполняем функцию
                result = func(*args, **kwargs)
                
                # Если есть response в результате, обновляем rate limit info
                if hasattr(result, 'status_code'):
                    self.update_rate_limit_info(source, result)
                
                return result, None
                
            except Exception as e:
                # Определяем тип ошибки
                response = getattr(e, 'response', None)
                error_type = self.classify_error(e, response)
                
                # Извлекаем retry_after из заголовков
                retry_after = None
                if response and 'Retry-After' in response.headers:
                    try:
                        retry_after = int(response.headers['Retry-After'])
                    except ValueError:
                        pass
                
                # Создаем контекст ошибки
                error_context = ErrorContext(
                    error_type=error_type,
                    source=source,
                    attempt_number=attempt,
                    error_message=str(e),
                    response_code=response.status_code if response else None,
                    retry_after=retry_after
                )
                
                # Записываем ошибку
                self.record_error(error_context)
                last_error_context = error_context
                
                # Обновляем rate limit info если есть response
                if response:
                    self.update_rate_limit_info(source, response)
                
                # Проверяем необходимость повторной попытки
                if not self.should_retry(error_context):
                    logger.error(f"Прекращаем попытки для {source} после {attempt} попыток: {e}")
                    break
                
                # Вычисляем задержку
                delay = self.calculate_delay(attempt, error_type, retry_after)
                
                logger.warning(
                    f"Ошибка {error_type.value} для {source} (попытка {attempt}/{self.retry_config.max_attempts}): {e}. "
                    f"Повтор через {delay:.1f}с"
                )
                
                time.sleep(delay)
                attempt += 1
        
        return None, last_error_context
    
    def get_error_statistics(self, source: Optional[str] = None) -> Dict[str, Any]:
        """
        Получение статистики ошибок.
        
        Args:
            source: Источник для фильтрации (опционально)
            
        Returns:
            Dict[str, Any]: Статистика ошибок
        """
        stats = {
            'total_errors': 0,
            'errors_by_type': {},
            'errors_by_source': {},
            'recent_errors': 0,
            'rate_limit_info': self.rate_limit_info.copy()
        }
        
        # Фильтруем по источнику если указан
        sources_to_check = [source] if source else self.error_history.keys()
        
        recent_threshold = datetime.now() - timedelta(hours=1)
        
        for src in sources_to_check:
            if src not in self.error_history:
                continue
                
            source_errors = self.error_history[src]
            stats['errors_by_source'][src] = len(source_errors)
            stats['total_errors'] += len(source_errors)
            
            for error in source_errors:
                error_type = error.error_type.value
                if error_type not in stats['errors_by_type']:
                    stats['errors_by_type'][error_type] = 0
                stats['errors_by_type'][error_type] += 1
                
                if error.timestamp > recent_threshold:
                    stats['recent_errors'] += 1
        
        return stats


class DataRecoveryManager:
    """
    Менеджер восстановления данных после сбоев.
    
    Реализует:
    - Принудительную пересинхронизацию
    - Очистку поврежденных данных
    - Процедуры восстановления после сбоев
    """
    
    def __init__(self, cursor, connection):
        """
        Инициализация менеджера восстановления.
        
        Args:
            cursor: Курсор базы данных
            connection: Соединение с базой данных
        """
        self.cursor = cursor
        self.connection = connection
    
    def force_resync(self, source: str, days_back: int = 7) -> Dict[str, Any]:
        """
        Принудительная пересинхронизация данных.
        
        Args:
            source: Источник данных ('Ozon' или 'Wildberries')
            days_back: Количество дней назад для очистки данных
            
        Returns:
            Dict[str, Any]: Результат пересинхронизации
        """
        logger.info(f"Начинаем принудительную пересинхронизацию для {source}")
        
        try:
            # Очищаем данные за указанный период
            cleanup_result = self.cleanup_corrupted_data(source, days_back)
            
            # Очищаем логи синхронизации для пересинхронизации
            self.cursor.execute("""
                DELETE FROM sync_logs 
                WHERE source = %s AND started_at >= DATE_SUB(NOW(), INTERVAL %s DAY)
            """, (source, days_back))
            
            deleted_logs = self.cursor.rowcount
            self.connection.commit()
            
            logger.info(f"Очищено {deleted_logs} записей логов для {source}")
            
            return {
                'status': 'success',
                'source': source,
                'cleanup_result': cleanup_result,
                'deleted_logs': deleted_logs,
                'message': f'Принудительная пересинхронизация для {source} подготовлена'
            }
            
        except Exception as e:
            logger.error(f"Ошибка принудительной пересинхронизации для {source}: {e}")
            self.connection.rollback()
            return {
                'status': 'error',
                'source': source,
                'error': str(e)
            }
    
    def cleanup_corrupted_data(self, source: str, days_back: int = 7) -> Dict[str, Any]:
        """
        Очистка поврежденных данных.
        
        Args:
            source: Источник данных
            days_back: Количество дней назад для очистки
            
        Returns:
            Dict[str, Any]: Результат очистки
        """
        logger.info(f"Очистка поврежденных данных для {source}")
        
        try:
            # Находим записи с некорректными данными
            self.cursor.execute("""
                SELECT COUNT(*) as corrupted_count
                FROM inventory_data 
                WHERE source = %s 
                AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %s DAY)
                AND (
                    quantity_present < 0 
                    OR quantity_reserved < 0 
                    OR current_stock < 0
                    OR product_id IS NULL
                    OR sku IS NULL OR sku = ''
                )
            """, (source, days_back))
            
            corrupted_count = self.cursor.fetchone()['corrupted_count']
            
            # Удаляем поврежденные записи
            self.cursor.execute("""
                DELETE FROM inventory_data 
                WHERE source = %s 
                AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %s DAY)
                AND (
                    quantity_present < 0 
                    OR quantity_reserved < 0 
                    OR current_stock < 0
                    OR product_id IS NULL
                    OR sku IS NULL OR sku = ''
                )
            """, (source, days_back))
            
            deleted_corrupted = self.cursor.rowcount
            
            # Удаляем дублирующиеся записи
            self.cursor.execute("""
                DELETE t1 FROM inventory_data t1
                INNER JOIN inventory_data t2 
                WHERE t1.id > t2.id 
                AND t1.product_id = t2.product_id 
                AND t1.source = t2.source 
                AND t1.snapshot_date = t2.snapshot_date
                AND t1.warehouse_name = t2.warehouse_name
                AND t1.stock_type = t2.stock_type
                AND t1.source = %s
                AND t1.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %s DAY)
            """, (source, days_back))
            
            deleted_duplicates = self.cursor.rowcount
            
            # Удаляем старые данные за указанный период
            self.cursor.execute("""
                DELETE FROM inventory_data 
                WHERE source = %s 
                AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %s DAY)
            """, (source, days_back))
            
            deleted_old = self.cursor.rowcount
            
            self.connection.commit()
            
            result = {
                'status': 'success',
                'source': source,
                'corrupted_found': corrupted_count,
                'deleted_corrupted': deleted_corrupted,
                'deleted_duplicates': deleted_duplicates,
                'deleted_old': deleted_old,
                'total_deleted': deleted_corrupted + deleted_duplicates + deleted_old
            }
            
            logger.info(f"Очистка завершена для {source}: {result}")
            return result
            
        except Exception as e:
            logger.error(f"Ошибка очистки данных для {source}: {e}")
            self.connection.rollback()
            return {
                'status': 'error',
                'source': source,
                'error': str(e)
            }
    
    def recover_from_failure(self, source: str, sync_session_id: Optional[int] = None) -> Dict[str, Any]:
        """
        Восстановление после сбоя синхронизации.
        
        Args:
            source: Источник данных
            sync_session_id: ID сессии синхронизации (опционально)
            
        Returns:
            Dict[str, Any]: Результат восстановления
        """
        logger.info(f"Восстановление после сбоя для {source}")
        
        try:
            # Находим последнюю неудачную синхронизацию
            if sync_session_id:
                condition = "id = %s"
                params = (sync_session_id,)
            else:
                condition = "source = %s AND status = 'failed' ORDER BY started_at DESC LIMIT 1"
                params = (source,)
            
            self.cursor.execute(f"""
                SELECT id, started_at, error_message, records_processed
                FROM sync_logs 
                WHERE {condition}
            """, params)
            
            failed_sync = self.cursor.fetchone()
            
            if not failed_sync:
                return {
                    'status': 'no_action',
                    'message': f'Не найдено неудачных синхронизаций для {source}'
                }
            
            # Очищаем частично загруженные данные
            cleanup_date = failed_sync['started_at'].date()
            
            self.cursor.execute("""
                DELETE FROM inventory_data 
                WHERE source = %s AND snapshot_date = %s
            """, (source, cleanup_date))
            
            deleted_partial = self.cursor.rowcount
            
            # Помечаем синхронизацию как восстановленную
            self.cursor.execute("""
                UPDATE sync_logs 
                SET status = 'recovered', 
                    error_message = CONCAT(IFNULL(error_message, ''), ' [RECOVERED]')
                WHERE id = %s
            """, (failed_sync['id'],))
            
            self.connection.commit()
            
            result = {
                'status': 'success',
                'source': source,
                'failed_sync_id': failed_sync['id'],
                'cleanup_date': cleanup_date.isoformat(),
                'deleted_partial_records': deleted_partial,
                'message': f'Восстановление после сбоя для {source} завершено'
            }
            
            logger.info(f"Восстановление завершено: {result}")
            return result
            
        except Exception as e:
            logger.error(f"Ошибка восстановления для {source}: {e}")
            self.connection.rollback()
            return {
                'status': 'error',
                'source': source,
                'error': str(e)
            }
    
    def validate_data_integrity(self, source: str) -> Dict[str, Any]:
        """
        Проверка целостности данных.
        
        Args:
            source: Источник данных
            
        Returns:
            Dict[str, Any]: Результат проверки целостности
        """
        logger.info(f"Проверка целостности данных для {source}")
        
        try:
            # Проверяем основные метрики целостности
            self.cursor.execute("""
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(DISTINCT product_id) as unique_products,
                    COUNT(CASE WHEN quantity_present < 0 THEN 1 END) as negative_present,
                    COUNT(CASE WHEN quantity_reserved < 0 THEN 1 END) as negative_reserved,
                    COUNT(CASE WHEN product_id IS NULL THEN 1 END) as null_product_ids,
                    COUNT(CASE WHEN sku IS NULL OR sku = '' THEN 1 END) as empty_skus,
                    MAX(last_sync_at) as last_sync,
                    MIN(snapshot_date) as oldest_date,
                    MAX(snapshot_date) as newest_date
                FROM inventory_data 
                WHERE source = %s
            """, (source,))
            
            integrity_stats = self.cursor.fetchone()
            
            # Проверяем дублирующиеся записи
            self.cursor.execute("""
                SELECT 
                    product_id, source, snapshot_date, warehouse_name, stock_type,
                    COUNT(*) as duplicate_count
                FROM inventory_data 
                WHERE source = %s
                GROUP BY product_id, source, snapshot_date, warehouse_name, stock_type
                HAVING COUNT(*) > 1
            """, (source,))
            
            duplicates = self.cursor.fetchall()
            
            # Вычисляем показатели целостности
            total_records = integrity_stats['total_records']
            issues_count = (
                integrity_stats['negative_present'] +
                integrity_stats['negative_reserved'] +
                integrity_stats['null_product_ids'] +
                integrity_stats['empty_skus'] +
                len(duplicates)
            )
            
            integrity_score = max(0, (total_records - issues_count) / max(total_records, 1) * 100)
            
            result = {
                'status': 'success',
                'source': source,
                'integrity_score': round(integrity_score, 2),
                'total_records': total_records,
                'unique_products': integrity_stats['unique_products'],
                'issues': {
                    'negative_present': integrity_stats['negative_present'],
                    'negative_reserved': integrity_stats['negative_reserved'],
                    'null_product_ids': integrity_stats['null_product_ids'],
                    'empty_skus': integrity_stats['empty_skus'],
                    'duplicates': len(duplicates)
                },
                'total_issues': issues_count,
                'last_sync': integrity_stats['last_sync'].isoformat() if integrity_stats['last_sync'] else None,
                'date_range': {
                    'oldest': integrity_stats['oldest_date'].isoformat() if integrity_stats['oldest_date'] else None,
                    'newest': integrity_stats['newest_date'].isoformat() if integrity_stats['newest_date'] else None
                }
            }
            
            logger.info(f"Проверка целостности завершена для {source}: score={integrity_score:.1f}%")
            return result
            
        except Exception as e:
            logger.error(f"Ошибка проверки целостности для {source}: {e}")
            return {
                'status': 'error',
                'source': source,
                'error': str(e)
            }


class FallbackManager:
    """
    Менеджер fallback механизмов при недоступности API.
    
    Реализует альтернативные стратегии получения данных
    когда основные API недоступны.
    """
    
    def __init__(self, cursor, connection):
        """
        Инициализация менеджера fallback.
        
        Args:
            cursor: Курсор базы данных
            connection: Соединение с базой данных
        """
        self.cursor = cursor
        self.connection = connection
    
    def use_cached_data(self, source: str, max_age_hours: int = 24) -> Dict[str, Any]:
        """
        Использование кэшированных данных при недоступности API.
        
        Args:
            source: Источник данных
            max_age_hours: Максимальный возраст кэшированных данных в часах
            
        Returns:
            Dict[str, Any]: Результат использования кэша
        """
        logger.info(f"Использование кэшированных данных для {source}")
        
        try:
            # Находим последние успешные данные
            self.cursor.execute("""
                SELECT 
                    COUNT(*) as cached_records,
                    MAX(last_sync_at) as last_update,
                    COUNT(DISTINCT product_id) as unique_products
                FROM inventory_data 
                WHERE source = %s 
                AND last_sync_at >= DATE_SUB(NOW(), INTERVAL %s HOUR)
            """, (source, max_age_hours))
            
            cache_info = self.cursor.fetchone()
            
            if cache_info['cached_records'] == 0:
                return {
                    'status': 'no_cache',
                    'source': source,
                    'message': f'Нет кэшированных данных для {source} младше {max_age_hours} часов'
                }
            
            # Копируем кэшированные данные как текущие
            self.cursor.execute("""
                INSERT INTO inventory_data 
                (product_id, sku, source, warehouse_name, stock_type, 
                 snapshot_date, current_stock, reserved_stock, available_stock,
                 quantity_present, quantity_reserved, last_sync_at)
                SELECT 
                    product_id, sku, source, warehouse_name, stock_type,
                    CURDATE() as snapshot_date, current_stock, reserved_stock, available_stock,
                    quantity_present, quantity_reserved, NOW() as last_sync_at
                FROM inventory_data 
                WHERE source = %s 
                AND last_sync_at >= DATE_SUB(NOW(), INTERVAL %s HOUR)
                AND snapshot_date = (
                    SELECT MAX(snapshot_date) 
                    FROM inventory_data i2 
                    WHERE i2.source = %s 
                    AND i2.last_sync_at >= DATE_SUB(NOW(), INTERVAL %s HOUR)
                )
            """, (source, max_age_hours, source, max_age_hours))
            
            copied_records = self.cursor.rowcount
            self.connection.commit()
            
            result = {
                'status': 'success',
                'source': source,
                'cached_records': cache_info['cached_records'],
                'copied_records': copied_records,
                'last_update': cache_info['last_update'].isoformat() if cache_info['last_update'] else None,
                'unique_products': cache_info['unique_products'],
                'message': f'Использованы кэшированные данные для {source}'
            }
            
            logger.info(f"Кэшированные данные использованы для {source}: {copied_records} записей")
            return result
            
        except Exception as e:
            logger.error(f"Ошибка использования кэша для {source}: {e}")
            self.connection.rollback()
            return {
                'status': 'error',
                'source': source,
                'error': str(e)
            }
    
    def estimate_inventory_from_history(self, source: str, days_back: int = 7) -> Dict[str, Any]:
        """
        Оценка остатков на основе исторических данных.
        
        Args:
            source: Источник данных
            days_back: Количество дней для анализа истории
            
        Returns:
            Dict[str, Any]: Результат оценки
        """
        logger.info(f"Оценка остатков из истории для {source}")
        
        try:
            # Анализируем тренды по товарам
            self.cursor.execute("""
                SELECT 
                    product_id,
                    sku,
                    warehouse_name,
                    stock_type,
                    AVG(quantity_present) as avg_present,
                    AVG(quantity_reserved) as avg_reserved,
                    STDDEV(quantity_present) as stddev_present,
                    COUNT(*) as data_points,
                    MAX(snapshot_date) as last_date
                FROM inventory_data 
                WHERE source = %s 
                AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %s DAY)
                GROUP BY product_id, sku, warehouse_name, stock_type
                HAVING COUNT(*) >= 3  -- Минимум 3 точки данных для оценки
            """, (source, days_back))
            
            historical_data = self.cursor.fetchall()
            
            if not historical_data:
                return {
                    'status': 'no_data',
                    'source': source,
                    'message': f'Недостаточно исторических данных для {source}'
                }
            
            # Создаем оценочные записи
            estimated_records = []
            for record in historical_data:
                # Простая оценка: среднее значение с учетом стандартного отклонения
                estimated_present = max(0, int(record['avg_present']))
                estimated_reserved = max(0, int(record['avg_reserved']))
                
                estimated_records.append({
                    'product_id': record['product_id'],
                    'sku': record['sku'],
                    'source': source,
                    'warehouse_name': record['warehouse_name'],
                    'stock_type': record['stock_type'],
                    'snapshot_date': datetime.now().date(),
                    'current_stock': estimated_present,
                    'reserved_stock': estimated_reserved,
                    'available_stock': max(0, estimated_present - estimated_reserved),
                    'quantity_present': estimated_present,
                    'quantity_reserved': estimated_reserved
                })
            
            # Вставляем оценочные данные
            if estimated_records:
                insert_query = """
                    INSERT INTO inventory_data 
                    (product_id, sku, source, warehouse_name, stock_type, 
                     snapshot_date, current_stock, reserved_stock, available_stock,
                     quantity_present, quantity_reserved, last_sync_at)
                    VALUES (%(product_id)s, %(sku)s, %(source)s, %(warehouse_name)s, %(stock_type)s,
                           %(snapshot_date)s, %(current_stock)s, %(reserved_stock)s, %(available_stock)s,
                           %(quantity_present)s, %(quantity_reserved)s, NOW())
                """
                
                self.cursor.executemany(insert_query, estimated_records)
                self.connection.commit()
            
            result = {
                'status': 'success',
                'source': source,
                'estimated_records': len(estimated_records),
                'unique_products': len(set(r['product_id'] for r in estimated_records)),
                'days_analyzed': days_back,
                'message': f'Создано {len(estimated_records)} оценочных записей для {source}'
            }
            
            logger.info(f"Оценка остатков завершена для {source}: {len(estimated_records)} записей")
            return result
            
        except Exception as e:
            logger.error(f"Ошибка оценки остатков для {source}: {e}")
            self.connection.rollback()
            return {
                'status': 'error',
                'source': source,
                'error': str(e)
            }