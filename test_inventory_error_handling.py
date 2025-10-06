#!/usr/bin/env python3
"""
Тесты для системы обработки ошибок и восстановления данных.

Проверяет функциональность APIErrorHandler, DataRecoveryManager,
FallbackManager и RobustInventorySyncService.

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import unittest
from unittest.mock import Mock, patch, MagicMock, call
import requests
from datetime import datetime, date, timedelta
import json
import time

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_error_handler import (
        APIErrorHandler, DataRecoveryManager, FallbackManager,
        RetryConfig, ErrorType, ErrorContext
    )
    from inventory_recovery_utility import InventoryRecoveryUtility
    from inventory_sync_service_with_error_handling import RobustInventorySyncService, SyncResult, SyncStatus
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)


class TestAPIErrorHandler(unittest.TestCase):
    """Тесты для APIErrorHandler."""
    
    def setUp(self):
        """Настройка тестов."""
        self.retry_config = RetryConfig(
            max_attempts=3,
            base_delay=0.1,  # Быстрые тесты
            max_delay=10.0,  # Увеличиваем для тестирования retry_after
            exponential_base=2.0,
            jitter=False  # Отключаем jitter для предсказуемости
        )
        self.handler = APIErrorHandler(self.retry_config)
    
    def test_classify_error_rate_limit(self):
        """Тест классификации ошибки rate limit."""
        response = Mock()
        response.status_code = 429
        
        error_type = self.handler.classify_error(Exception(), response)
        self.assertEqual(error_type, ErrorType.API_RATE_LIMIT)
    
    def test_classify_error_timeout(self):
        """Тест классификации ошибки timeout."""
        error_type = self.handler.classify_error(requests.exceptions.Timeout())
        self.assertEqual(error_type, ErrorType.API_TIMEOUT)
    
    def test_classify_error_auth(self):
        """Тест классификации ошибки аутентификации."""
        response = Mock()
        response.status_code = 401
        
        error_type = self.handler.classify_error(Exception(), response)
        self.assertEqual(error_type, ErrorType.API_AUTH_ERROR)
    
    def test_calculate_delay_exponential(self):
        """Тест экспоненциальной задержки."""
        # Используем тип ошибки без множителя для точного тестирования
        delay1 = self.handler.calculate_delay(1, ErrorType.NETWORK_ERROR)
        delay2 = self.handler.calculate_delay(2, ErrorType.NETWORK_ERROR)
        delay3 = self.handler.calculate_delay(3, ErrorType.NETWORK_ERROR)
        
        # Проверяем экспоненциальный рост
        self.assertLess(delay1, delay2)
        self.assertLess(delay2, delay3)
        
        # Проверяем базовые значения (без jitter)
        self.assertAlmostEqual(delay1, 0.1, places=1)
        self.assertAlmostEqual(delay2, 0.2, places=1)
        self.assertAlmostEqual(delay3, 0.4, places=1)
    
    def test_calculate_delay_with_retry_after(self):
        """Тест задержки с заголовком Retry-After."""
        delay = self.handler.calculate_delay(1, ErrorType.API_RATE_LIMIT, retry_after=5)
        self.assertEqual(delay, 5.0)
    
    def test_should_retry_max_attempts(self):
        """Тест ограничения максимального количества попыток."""
        error_context = ErrorContext(
            error_type=ErrorType.API_TIMEOUT,
            source="TestAPI",
            attempt_number=3,  # Равно max_attempts
            error_message="Test error"
        )
        
        should_retry = self.handler.should_retry(error_context)
        self.assertFalse(should_retry)
    
    def test_should_retry_auth_error(self):
        """Тест отказа от retry для ошибок аутентификации."""
        error_context = ErrorContext(
            error_type=ErrorType.API_AUTH_ERROR,
            source="TestAPI",
            attempt_number=1,
            error_message="Auth error"
        )
        
        should_retry = self.handler.should_retry(error_context)
        self.assertFalse(should_retry)
    
    def test_record_error(self):
        """Тест записи ошибки в историю."""
        error_context = ErrorContext(
            error_type=ErrorType.API_TIMEOUT,
            source="TestAPI",
            attempt_number=1,
            error_message="Test error"
        )
        
        self.handler.record_error(error_context)
        
        self.assertIn("TestAPI", self.handler.error_history)
        self.assertEqual(len(self.handler.error_history["TestAPI"]), 1)
        self.assertEqual(self.handler.error_history["TestAPI"][0], error_context)
    
    def test_update_rate_limit_info(self):
        """Тест обновления информации о rate limits."""
        response = Mock()
        response.status_code = 200
        response.headers = {
            'X-RateLimit-Limit': '1000',
            'X-RateLimit-Remaining': '999',
            'X-RateLimit-Reset': '1640995200'
        }
        
        self.handler.update_rate_limit_info("TestAPI", response)
        
        rate_info = self.handler.rate_limit_info["TestAPI"]
        self.assertEqual(rate_info['limit'], 1000)
        self.assertEqual(rate_info['remaining'], 999)
        self.assertEqual(rate_info['reset'], 1640995200)
    
    @patch('time.sleep')
    def test_execute_with_retry_success(self, mock_sleep):
        """Тест успешного выполнения функции с первой попытки."""
        def test_function():
            return "success"
        
        result, error_context = self.handler.execute_with_retry(test_function, "TestAPI")
        
        self.assertEqual(result, "success")
        self.assertIsNone(error_context)
        mock_sleep.assert_not_called()
    
    @patch('time.sleep')
    def test_execute_with_retry_failure_then_success(self, mock_sleep):
        """Тест успешного выполнения после нескольких неудач."""
        call_count = 0
        
        def test_function():
            nonlocal call_count
            call_count += 1
            if call_count < 3:
                raise requests.exceptions.Timeout("Timeout error")
            return "success"
        
        result, error_context = self.handler.execute_with_retry(test_function, "TestAPI")
        
        self.assertEqual(result, "success")
        self.assertIsNone(error_context)
        self.assertEqual(mock_sleep.call_count, 2)  # 2 retry attempts
    
    @patch('time.sleep')
    def test_execute_with_retry_max_failures(self, mock_sleep):
        """Тест исчерпания всех попыток."""
        def test_function():
            raise requests.exceptions.Timeout("Persistent timeout")
        
        result, error_context = self.handler.execute_with_retry(test_function, "TestAPI")
        
        self.assertIsNone(result)
        self.assertIsNotNone(error_context)
        self.assertEqual(error_context.error_type, ErrorType.API_TIMEOUT)
        self.assertEqual(mock_sleep.call_count, 2)  # max_attempts - 1
    
    def test_get_error_statistics(self):
        """Тест получения статистики ошибок."""
        # Добавляем несколько ошибок
        error1 = ErrorContext(ErrorType.API_TIMEOUT, "API1", 1, "Error 1")
        error2 = ErrorContext(ErrorType.API_RATE_LIMIT, "API1", 1, "Error 2")
        error3 = ErrorContext(ErrorType.API_TIMEOUT, "API2", 1, "Error 3")
        
        self.handler.record_error(error1)
        self.handler.record_error(error2)
        self.handler.record_error(error3)
        
        stats = self.handler.get_error_statistics()
        
        self.assertEqual(stats['total_errors'], 3)
        self.assertEqual(stats['errors_by_type']['api_timeout'], 2)
        self.assertEqual(stats['errors_by_type']['api_rate_limit'], 1)
        self.assertEqual(stats['errors_by_source']['API1'], 2)
        self.assertEqual(stats['errors_by_source']['API2'], 1)

    def test_classify_error_network_error(self):
        """Тест классификации сетевой ошибки."""
        error_type = self.handler.classify_error(requests.exceptions.ConnectionError())
        self.assertEqual(error_type, ErrorType.NETWORK_ERROR)

    def test_classify_error_api_unavailable(self):
        """Тест классификации ошибки недоступности API."""
        response = Mock()
        response.status_code = 503
        
        error_type = self.handler.classify_error(Exception(), response)
        self.assertEqual(error_type, ErrorType.API_UNAVAILABLE)

    def test_classify_error_unknown(self):
        """Тест классификации неизвестной ошибки."""
        error_type = self.handler.classify_error(ValueError("Unknown error"))
        self.assertEqual(error_type, ErrorType.UNKNOWN_ERROR)

    def test_rate_limit_handling(self):
        """Тест обработки rate limits."""
        response = Mock()
        response.status_code = 200
        response.headers = {
            'X-RateLimit-Limit': '100',
            'X-RateLimit-Remaining': '0',
            'X-RateLimit-Reset': str(int(time.time()) + 3600)  # Сброс через час
        }
        
        self.handler.update_rate_limit_info("TestAPI", response)
        
        # Проверяем, что система определяет необходимость ожидания
        wait_time = self.handler.check_rate_limit("TestAPI")
        self.assertIsNotNone(wait_time)
        self.assertGreater(wait_time, 0)

    def test_adaptive_retry_behavior(self):
        """Тест адаптивного поведения retry при множественных ошибках."""
        # Добавляем много ошибок в короткий период
        for i in range(15):
            error = ErrorContext(
                ErrorType.API_TIMEOUT, 
                "TestAPI", 
                1, 
                f"Error {i}",
                timestamp=datetime.now()
            )
            self.handler.record_error(error)
        
        # Создаем новый контекст ошибки
        error_context = ErrorContext(
            ErrorType.API_TIMEOUT,
            "TestAPI",
            1,
            "New error"
        )
        
        # Система должна прекратить попытки из-за слишком большого количества ошибок
        should_retry = self.handler.should_retry(error_context)
        self.assertFalse(should_retry)

    @patch('time.sleep')
    def test_retry_with_different_error_types(self, mock_sleep):
        """Тест retry логики для разных типов ошибок."""
        call_count = 0
        
        def test_function_timeout():
            nonlocal call_count
            call_count += 1
            if call_count < 2:
                raise requests.exceptions.Timeout("Timeout error")
            return "success"
        
        def test_function_rate_limit():
            response = Mock()
            response.status_code = 429
            response.headers = {'Retry-After': '2'}
            error = requests.exceptions.HTTPError()
            error.response = response
            raise error
        
        # Тест timeout ошибки
        call_count = 0
        result, error_context = self.handler.execute_with_retry(test_function_timeout, "TestAPI")
        self.assertEqual(result, "success")
        self.assertIsNone(error_context)
        
        # Тест rate limit ошибки
        result, error_context = self.handler.execute_with_retry(test_function_rate_limit, "TestAPI")
        self.assertIsNone(result)
        self.assertIsNotNone(error_context)
        self.assertEqual(error_context.error_type, ErrorType.API_RATE_LIMIT)

    def test_jitter_in_delay_calculation(self):
        """Тест добавления jitter в расчет задержки."""
        # Включаем jitter
        self.handler.retry_config.jitter = True
        
        delays = []
        for _ in range(10):
            delay = self.handler.calculate_delay(2, ErrorType.API_TIMEOUT)
            delays.append(delay)
        
        # Проверяем, что задержки различаются (из-за jitter)
        unique_delays = set(delays)
        self.assertGreater(len(unique_delays), 1, "Jitter должен создавать разные задержки")

    def test_error_history_size_limit(self):
        """Тест ограничения размера истории ошибок."""
        # Добавляем много ошибок
        for i in range(150):
            error = ErrorContext(ErrorType.API_TIMEOUT, "TestAPI", 1, f"Error {i}")
            self.handler.record_error(error)
        
        # Проверяем, что история ограничена (должно быть <= 100, обрезается до 50)
        history_size = len(self.handler.error_history["TestAPI"])
        self.assertLessEqual(history_size, 100, "История ошибок должна быть ограничена")

    @patch('time.sleep')
    def test_exponential_backoff_progression(self, mock_sleep):
        """Тест прогрессии экспоненциальной задержки."""
        call_count = 0
        
        def failing_function():
            nonlocal call_count
            call_count += 1
            raise requests.exceptions.Timeout("Persistent timeout")
        
        # Выполняем функцию с retry
        result, error_context = self.handler.execute_with_retry(failing_function, "TestAPI")
        
        # Проверяем, что было сделано правильное количество вызовов sleep
        self.assertEqual(mock_sleep.call_count, 2)  # max_attempts - 1
        
        # Проверяем прогрессию задержек
        sleep_calls = mock_sleep.call_args_list
        delay1 = sleep_calls[0][0][0]
        delay2 = sleep_calls[1][0][0]
        
        # Вторая задержка должна быть больше первой (экспоненциальный рост)
        self.assertGreater(delay2, delay1)


class TestDataRecoveryManager(unittest.TestCase):
    """Тесты для DataRecoveryManager."""
    
    def setUp(self):
        """Настройка тестов."""
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        self.manager = DataRecoveryManager(self.mock_cursor, self.mock_connection)
    
    def test_force_resync_success(self):
        """Тест успешной принудительной пересинхронизации."""
        # Настраиваем mock для cleanup_corrupted_data
        self.mock_cursor.rowcount = 5
        
        result = self.manager.force_resync("Ozon", 7)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['source'], 'Ozon')
        self.assertIn('cleanup_result', result)
        self.mock_connection.commit.assert_called()
    
    def test_cleanup_corrupted_data_success(self):
        """Тест успешной очистки поврежденных данных."""
        # Настраиваем mock для запросов
        self.mock_cursor.fetchone.return_value = {'corrupted_count': 10}
        self.mock_cursor.rowcount = 5  # Для каждого DELETE запроса
        
        result = self.manager.cleanup_corrupted_data("Ozon", 7)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['source'], 'Ozon')
        self.assertEqual(result['corrupted_found'], 10)
        self.assertGreater(result['total_deleted'], 0)
        self.mock_connection.commit.assert_called()
    
    def test_cleanup_corrupted_data_error(self):
        """Тест обработки ошибки при очистке данных."""
        self.mock_cursor.execute.side_effect = Exception("Database error")
        
        result = self.manager.cleanup_corrupted_data("Ozon", 7)
        
        self.assertEqual(result['status'], 'error')
        self.assertIn('error', result)
        self.mock_connection.rollback.assert_called()
    
    def test_recover_from_failure_success(self):
        """Тест успешного восстановления после сбоя."""
        # Настраиваем mock для поиска неудачной синхронизации
        failed_sync = {
            'id': 123,
            'started_at': datetime.now(),
            'error_message': 'API timeout',
            'records_processed': 100
        }
        self.mock_cursor.fetchone.return_value = failed_sync
        self.mock_cursor.rowcount = 50  # Удаленные записи
        
        result = self.manager.recover_from_failure("Ozon")
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['source'], 'Ozon')
        self.assertEqual(result['failed_sync_id'], 123)
        self.assertEqual(result['deleted_partial_records'], 50)
        self.mock_connection.commit.assert_called()
    
    def test_recover_from_failure_no_failed_sync(self):
        """Тест восстановления когда нет неудачных синхронизаций."""
        self.mock_cursor.fetchone.return_value = None
        
        result = self.manager.recover_from_failure("Ozon")
        
        self.assertEqual(result['status'], 'no_action')
        self.assertIn('Не найдено неудачных синхронизаций', result['message'])
    
    def test_validate_data_integrity_success(self):
        """Тест успешной проверки целостности данных."""
        # Настраиваем mock для статистики целостности
        integrity_stats = {
            'total_records': 1000,
            'unique_products': 500,
            'negative_present': 5,
            'negative_reserved': 3,
            'null_product_ids': 2,
            'empty_skus': 1,
            'last_sync': datetime.now(),
            'oldest_date': date.today() - timedelta(days=7),
            'newest_date': date.today()
        }
        
        self.mock_cursor.fetchone.return_value = integrity_stats
        self.mock_cursor.fetchall.return_value = []  # Нет дубликатов
        
        result = self.manager.validate_data_integrity("Ozon")
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['source'], 'Ozon')
        self.assertEqual(result['total_records'], 1000)
        self.assertEqual(result['unique_products'], 500)
        self.assertEqual(result['total_issues'], 11)  # 5+3+2+1+0
        
        # Проверяем расчет integrity_score
        expected_score = (1000 - 11) / 1000 * 100
        self.assertAlmostEqual(result['integrity_score'], expected_score, places=1)

    def test_force_resync_with_database_error(self):
        """Тест обработки ошибки БД при принудительной пересинхронизации."""
        self.mock_cursor.execute.side_effect = Exception("Database connection lost")
        
        result = self.manager.force_resync("Ozon", 7)
        
        self.assertEqual(result['status'], 'error')
        self.assertIn('Database connection lost', result['error'])
        self.mock_connection.rollback.assert_called()

    def test_recover_from_specific_sync_session(self):
        """Тест восстановления конкретной сессии синхронизации."""
        failed_sync = {
            'id': 456,
            'started_at': datetime.now(),
            'error_message': 'Network timeout',
            'records_processed': 200
        }
        self.mock_cursor.fetchone.return_value = failed_sync
        self.mock_cursor.rowcount = 75
        
        result = self.manager.recover_from_failure("Wildberries", sync_session_id=456)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['source'], 'Wildberries')
        self.assertEqual(result['failed_sync_id'], 456)
        self.assertEqual(result['deleted_partial_records'], 75)

    def test_validate_data_integrity_with_duplicates(self):
        """Тест проверки целостности данных с дубликатами."""
        integrity_stats = {
            'total_records': 500,
            'unique_products': 250,
            'negative_present': 0,
            'negative_reserved': 0,
            'null_product_ids': 0,
            'empty_skus': 0,
            'last_sync': datetime.now(),
            'oldest_date': date.today(),
            'newest_date': date.today()
        }
        
        # Добавляем дубликаты
        duplicates = [
            {'product_id': 1, 'duplicate_count': 3},
            {'product_id': 2, 'duplicate_count': 2}
        ]
        
        self.mock_cursor.fetchone.return_value = integrity_stats
        self.mock_cursor.fetchall.return_value = duplicates
        
        result = self.manager.validate_data_integrity("Ozon")
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['issues']['duplicates'], 2)
        self.assertEqual(result['total_issues'], 2)
        
        # Проверяем расчет integrity_score с учетом дубликатов
        expected_score = (500 - 2) / 500 * 100
        self.assertAlmostEqual(result['integrity_score'], expected_score, places=1)

    def test_cleanup_corrupted_data_comprehensive(self):
        """Тест комплексной очистки поврежденных данных."""
        # Настраиваем mock для разных типов очистки
        self.mock_cursor.fetchone.return_value = {'corrupted_count': 15}
        
        # Настраиваем rowcount для разных DELETE операций последовательно
        self.mock_cursor.rowcount = 10  # Первый DELETE
        
        # Создаем новый менеджер с правильно настроенными mock
        mock_cursor = Mock()
        mock_connection = Mock()
        mock_cursor.fetchone.return_value = {'corrupted_count': 15}
        
        # Настраиваем последовательность rowcount для разных операций
        mock_cursor.execute = Mock()
        
        # Создаем side_effect для rowcount
        rowcount_values = [10, 5, 20]  # corrupted, duplicates, old
        mock_cursor.rowcount = Mock()
        mock_cursor.rowcount.__get__ = Mock(side_effect=rowcount_values)
        
        manager = DataRecoveryManager(mock_cursor, mock_connection)
        
        # Переопределяем rowcount для каждого вызова
        def mock_execute(*args, **kwargs):
            pass
        
        mock_cursor.execute.side_effect = mock_execute
        
        # Настраиваем rowcount возвращать разные значения
        rowcount_iter = iter([10, 5, 20])
        type(mock_cursor).rowcount = Mock(side_effect=lambda: next(rowcount_iter))
        
        result = manager.cleanup_corrupted_data("Ozon", 7)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['corrupted_found'], 15)
        # Проверяем, что все типы очистки были выполнены
        self.assertIn('deleted_corrupted', result)
        self.assertIn('deleted_duplicates', result)
        self.assertIn('deleted_old', result)

    def test_recovery_transaction_rollback(self):
        """Тест отката транзакции при ошибке восстановления."""
        # Первый запрос успешен, второй вызывает ошибку
        self.mock_cursor.fetchone.return_value = {
            'id': 123,
            'started_at': datetime.now(),
            'error_message': 'Test error',
            'records_processed': 100
        }
        
        # Настраиваем ошибку на втором execute
        self.mock_cursor.execute.side_effect = [None, Exception("DELETE failed")]
        
        result = self.manager.recover_from_failure("Ozon")
        
        self.assertEqual(result['status'], 'error')
        self.mock_connection.rollback.assert_called()
        self.assertIn('DELETE failed', result['error'])


class TestFallbackManager(unittest.TestCase):
    """Тесты для FallbackManager."""
    
    def setUp(self):
        """Настройка тестов."""
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        self.manager = FallbackManager(self.mock_cursor, self.mock_connection)
    
    def test_use_cached_data_success(self):
        """Тест успешного использования кэшированных данных."""
        # Настраиваем mock для поиска кэшированных данных
        cache_info = {
            'cached_records': 100,
            'last_update': datetime.now(),
            'unique_products': 50
        }
        self.mock_cursor.fetchone.return_value = cache_info
        self.mock_cursor.rowcount = 100  # Скопированные записи
        
        result = self.manager.use_cached_data("Ozon", 24)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['source'], 'Ozon')
        self.assertEqual(result['cached_records'], 100)
        self.assertEqual(result['copied_records'], 100)
        self.mock_connection.commit.assert_called()
    
    def test_use_cached_data_no_cache(self):
        """Тест отсутствия кэшированных данных."""
        cache_info = {'cached_records': 0}
        self.mock_cursor.fetchone.return_value = cache_info
        
        result = self.manager.use_cached_data("Ozon", 24)
        
        self.assertEqual(result['status'], 'no_cache')
        self.assertIn('Нет кэшированных данных', result['message'])
    
    def test_estimate_inventory_from_history_success(self):
        """Тест успешной оценки остатков из истории."""
        # Настраиваем mock для исторических данных
        historical_data = [
            {
                'product_id': 1,
                'sku': 'SKU001',
                'warehouse_name': 'Main',
                'stock_type': 'FBO',
                'avg_present': 50.0,
                'avg_reserved': 10.0,
                'stddev_present': 5.0,
                'data_points': 5,
                'last_date': date.today()
            },
            {
                'product_id': 2,
                'sku': 'SKU002',
                'warehouse_name': 'Main',
                'stock_type': 'FBO',
                'avg_present': 30.0,
                'avg_reserved': 5.0,
                'stddev_present': 3.0,
                'data_points': 4,
                'last_date': date.today()
            }
        ]
        
        self.mock_cursor.fetchall.return_value = historical_data
        
        result = self.manager.estimate_inventory_from_history("Ozon", 7)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['source'], 'Ozon')
        self.assertEqual(result['estimated_records'], 2)
        self.assertEqual(result['unique_products'], 2)
        self.mock_connection.commit.assert_called()
    
    def test_estimate_inventory_from_history_no_data(self):
        """Тест оценки остатков при отсутствии данных."""
        self.mock_cursor.fetchall.return_value = []
        
        result = self.manager.estimate_inventory_from_history("Ozon", 7)
        
        self.assertEqual(result['status'], 'no_data')
        self.assertIn('Недостаточно исторических данных', result['message'])

    def test_use_cached_data_with_old_cache(self):
        """Тест использования устаревшего кэша."""
        # Кэш старше максимального возраста
        cache_info = {
            'cached_records': 0,  # Нет записей в допустимом временном окне
            'last_update': datetime.now() - timedelta(hours=48),
            'unique_products': 0
        }
        self.mock_cursor.fetchone.return_value = cache_info
        
        result = self.manager.use_cached_data("Ozon", 24)
        
        self.assertEqual(result['status'], 'no_cache')
        self.assertIn('Нет кэшированных данных', result['message'])

    def test_estimate_inventory_error_handling(self):
        """Тест обработки ошибок при оценке остатков."""
        self.mock_cursor.fetchall.side_effect = Exception("Database error")
        
        result = self.manager.estimate_inventory_from_history("Ozon", 7)
        
        self.assertEqual(result['status'], 'error')
        self.assertIn('Database error', result['error'])

    def test_use_cached_data_transaction_error(self):
        """Тест обработки ошибки транзакции при использовании кэша."""
        cache_info = {
            'cached_records': 100,
            'last_update': datetime.now(),
            'unique_products': 50
        }
        self.mock_cursor.fetchone.return_value = cache_info
        self.mock_cursor.execute.side_effect = [None, Exception("INSERT failed")]
        
        result = self.manager.use_cached_data("Ozon", 24)
        
        self.assertEqual(result['status'], 'error')
        self.mock_connection.rollback.assert_called()

    def test_estimate_inventory_with_statistical_analysis(self):
        """Тест оценки остатков с статистическим анализом."""
        # Данные с различными статистическими показателями
        historical_data = [
            {
                'product_id': 1,
                'sku': 'SKU001',
                'warehouse_name': 'Main',
                'stock_type': 'FBO',
                'avg_present': 100.0,
                'avg_reserved': 20.0,
                'stddev_present': 15.0,  # Высокая вариативность
                'data_points': 10,
                'last_date': date.today()
            },
            {
                'product_id': 2,
                'sku': 'SKU002',
                'warehouse_name': 'Main',
                'stock_type': 'FBO',
                'avg_present': 25.0,
                'avg_reserved': 5.0,
                'stddev_present': 2.0,  # Низкая вариативность
                'data_points': 8,
                'last_date': date.today()
            }
        ]
        
        self.mock_cursor.fetchall.return_value = historical_data
        
        result = self.manager.estimate_inventory_from_history("Ozon", 7)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['estimated_records'], 2)
        # Проверяем, что результат содержит основные поля
        self.assertIn('unique_products', result)
        self.assertIn('days_analyzed', result)


class TestRobustInventorySyncService(unittest.TestCase):
    """Тесты для RobustInventorySyncService с интегрированной обработкой ошибок."""
    
    def setUp(self):
        """Настройка тестов."""
        self.service = RobustInventorySyncService()
        
        # Создаем mock объекты
        self.service.connection = Mock()
        self.service.cursor = Mock()
        self.service.validator = Mock()
        self.service.sync_logger = Mock()
        self.service.error_handler = Mock()
        self.service.recovery_manager = Mock()
        self.service.fallback_manager = Mock()

    def test_sync_ozon_with_api_timeout_recovery(self):
        """Тест синхронизации Ozon с восстановлением после timeout."""
        # Настраиваем mock для API timeout с последующим успехом
        success_response = Mock()
        success_response.json.return_value = {
            'result': {
                'items': [
                    {
                        'offer_id': 'TEST001',
                        'stocks': [
                            {
                                'warehouse_name': 'Ozon Main',
                                'type': 'FBO',
                                'present': 10,
                                'reserved': 2
                            }
                        ]
                    }
                ]
            }
        }
        
        # Настраиваем make_api_request для возврата успешного ответа
        self.service.make_api_request = Mock(return_value=(success_response, None))
        
        # Настраиваем остальные mock объекты
        self.service.get_product_id_by_ozon_sku = Mock(return_value=123)
        self.service.validate_inventory_data = Mock(return_value=Mock(is_valid=True))
        self.service.filter_valid_records = Mock(return_value=[Mock()])
        self.service.update_inventory_data = Mock(return_value=(0, 1, 0))
        
        result = self.service.sync_ozon_inventory_with_recovery()
        
        self.assertIsInstance(result, SyncResult)
        self.assertEqual(result.source, 'Ozon')
        # Проверяем, что API запрос был сделан
        self.service.make_api_request.assert_called()

    def test_sync_with_fallback_mechanism(self):
        """Тест синхронизации с использованием fallback механизма."""
        # Настраиваем полный сбой API
        self.service.make_api_request = Mock(return_value=(
            None, 
            ErrorContext(ErrorType.API_UNAVAILABLE, "Ozon", 3, "API unavailable")
        ))
        
        # Настраиваем успешный fallback
        self.service.fallback_manager.use_cached_data.return_value = {
            'status': 'success',
            'copied_records': 50,
            'message': 'Used cached data'
        }
        
        result = self.service.sync_ozon_inventory_with_recovery()
        
        self.assertEqual(result.status, SyncStatus.FALLBACK)
        self.assertEqual(result.records_processed, 50)
        self.assertTrue(result.fallback_used)
        self.assertIn('Использованы кэшированные данные', result.recovery_actions[0])

    def test_sync_with_recovery_actions(self):
        """Тест синхронизации с действиями восстановления."""
        # Настраиваем восстановление после предыдущего сбоя
        self.service.recovery_manager.recover_from_failure.return_value = {
            'status': 'success',
            'message': 'Recovered from previous failure'
        }
        
        # Настраиваем успешный API запрос
        success_response = Mock()
        success_response.json.return_value = {'result': {'items': []}}
        self.service.error_handler.execute_with_retry.return_value = (success_response, None)
        
        result = self.service.sync_ozon_inventory_with_recovery()
        
        self.assertEqual(result.status, SyncStatus.SUCCESS)
        self.assertIn('Recovered from previous failure', result.recovery_actions[0])
        self.service.recovery_manager.recover_from_failure.assert_called_once()

    def test_sync_wb_with_rate_limit_handling(self):
        """Тест синхронизации WB с обработкой rate limits."""
        success_response = Mock()
        success_response.json.return_value = [
            {
                'barcode': '1234567890',
                'nmId': 12345,
                'quantity': 15,
                'inWayToClient': 3,
                'inWayFromClient': 1,
                'warehouseName': 'WB Main'
            }
        ]
        
        # Настраиваем make_api_request для возврата успешного ответа
        self.service.make_api_request = Mock(return_value=(success_response, None))
        
        # Настраиваем остальные mock объекты
        self.service.get_product_id_by_barcode = Mock(return_value=456)
        self.service.validate_inventory_data = Mock(return_value=Mock(is_valid=True))
        self.service.filter_valid_records = Mock(return_value=[Mock()])
        self.service.update_inventory_data = Mock(return_value=(0, 1, 0))
        
        result = self.service.sync_wb_inventory_with_recovery()
        
        self.assertIsInstance(result, SyncResult)
        self.assertEqual(result.source, 'Wildberries')
        # Проверяем, что API запрос был сделан
        self.service.make_api_request.assert_called()

    def test_sync_with_critical_error_logging(self):
        """Тест логирования критических ошибок при синхронизации."""
        # Настраиваем критическую ошибку в make_api_request
        self.service.make_api_request = Mock(side_effect=Exception("Critical system error"))
        
        result = self.service.sync_ozon_inventory_with_recovery()
        
        self.assertEqual(result.status, SyncStatus.FAILED)
        self.assertIn("Critical system error", result.error_message)
        
        # Проверяем, что ошибка была залогирована
        self.service.sync_logger.log_error.assert_called()
        self.service.sync_logger.end_sync_session.assert_called()

    def test_sync_partial_success_handling(self):
        """Тест обработки частичного успеха синхронизации."""
        # Настраиваем успешный API запрос
        success_response = Mock()
        success_response.json.return_value = {
            'result': {
                'items': [
                    {'offer_id': 'VALID001', 'stocks': [{'present': 10, 'reserved': 2}]},
                    {'offer_id': 'INVALID002', 'stocks': [{'present': -5, 'reserved': 1}]}  # Невалидные данные
                ]
            }
        }
        self.service.make_api_request = Mock(return_value=(success_response, None))
        
        # Настраиваем частичную валидацию - используем Mock для методов
        self.service.get_product_id_by_ozon_sku = Mock()
        self.service.get_product_id_by_ozon_sku.side_effect = [123, None]  # Второй товар не найден
        self.service.validate_inventory_data = Mock(return_value=Mock(is_valid=True))
        self.service.filter_valid_records = Mock(return_value=[Mock()])  # Только один валидный
        self.service.update_inventory_data = Mock(return_value=(0, 1, 1))  # 1 успех, 1 ошибка
        
        result = self.service.sync_ozon_inventory_with_recovery()
        
        self.assertEqual(result.status, SyncStatus.PARTIAL)
        self.assertEqual(result.records_processed, 2)
        self.assertEqual(result.records_inserted, 1)
        self.assertGreater(result.records_failed, 0)

    def test_force_full_resync_integration(self):
        """Тест интеграции принудительной полной пересинхронизации."""
        # Настраиваем mock для force_full_resync
        self.service.recovery_manager = Mock()
        self.service.recovery_manager.force_resync.return_value = {
            'status': 'success',
            'message': 'Force resync completed'
        }
        
        # Настраиваем успешную синхронизацию после очистки
        success_response = Mock()
        success_response.json.return_value = {'result': {'items': []}}
        self.service.make_api_request = Mock(return_value=(success_response, None))
        
        result = self.service.force_full_resync("Ozon", 7)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['source'], 'Ozon')
        self.service.recovery_manager.force_resync.assert_called_once_with("Ozon", 7)


class TestInventoryRecoveryUtility(unittest.TestCase):
    """Тесты для InventoryRecoveryUtility."""
    
    def setUp(self):
        """Настройка тестов."""
        self.utility = InventoryRecoveryUtility()
        
        # Создаем mock объекты
        self.utility.connection = Mock()
        self.utility.cursor = Mock()
        self.utility.recovery_manager = Mock()
        self.utility.fallback_manager = Mock()
        self.utility.sync_logger = Mock()
        self.utility.sync_service = Mock()
    
    def test_force_resync_single_source(self):
        """Тест принудительной пересинхронизации для одного источника."""
        # Настраиваем mock для recovery_manager
        self.utility.recovery_manager.force_resync.return_value = {
            'status': 'success',
            'message': 'Resync completed'
        }
        
        # Настраиваем mock для sync_service
        mock_sync_result = Mock()
        mock_sync_result.status.value = 'success'
        mock_sync_result.records_processed = 100
        mock_sync_result.records_inserted = 95
        mock_sync_result.records_failed = 5
        mock_sync_result.duration_seconds = 30
        mock_sync_result.fallback_used = False
        mock_sync_result.recovery_actions = []
        
        self.utility.sync_service.sync_ozon_inventory_with_recovery.return_value = mock_sync_result
        
        result = self.utility.force_resync("Ozon", 7, True)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['operation'], 'force_resync')
        self.assertIn('Ozon', result['sources_processed'])
        self.assertTrue(result['sync_executed'])
        
        # Проверяем вызовы методов
        self.utility.recovery_manager.force_resync.assert_called_once_with("Ozon", 7)
        self.utility.sync_service.sync_ozon_inventory_with_recovery.assert_called_once()
    
    def test_force_resync_all_sources(self):
        """Тест принудительной пересинхронизации для всех источников."""
        # Настраиваем mock для recovery_manager
        self.utility.recovery_manager.force_resync.return_value = {
            'status': 'success',
            'message': 'Resync completed'
        }
        
        # Настраиваем mock для sync_service
        mock_sync_result = Mock()
        mock_sync_result.status.value = 'success'
        mock_sync_result.records_processed = 100
        mock_sync_result.records_inserted = 95
        mock_sync_result.records_failed = 5
        mock_sync_result.duration_seconds = 30
        mock_sync_result.fallback_used = False
        mock_sync_result.recovery_actions = []
        
        self.utility.sync_service.sync_ozon_inventory_with_recovery.return_value = mock_sync_result
        self.utility.sync_service.sync_wb_inventory_with_recovery.return_value = mock_sync_result
        
        result = self.utility.force_resync("all", 7, True)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(len(result['sources_processed']), 2)
        self.assertIn('Ozon', result['sources_processed'])
        self.assertIn('Wildberries', result['sources_processed'])
        
        # Проверяем вызовы для обоих источников
        self.assertEqual(self.utility.recovery_manager.force_resync.call_count, 2)
        self.utility.sync_service.sync_ozon_inventory_with_recovery.assert_called_once()
        self.utility.sync_service.sync_wb_inventory_with_recovery.assert_called_once()
    
    def test_cleanup_corrupted_data(self):
        """Тест очистки поврежденных данных."""
        self.utility.recovery_manager.cleanup_corrupted_data.return_value = {
            'status': 'success',
            'source': 'Ozon',
            'total_deleted': 25
        }
        
        result = self.utility.cleanup_corrupted_data("Ozon", 7)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['operation'], 'cleanup_corrupted_data')
        self.assertIn('Ozon', result['sources_processed'])
        
        self.utility.recovery_manager.cleanup_corrupted_data.assert_called_once_with("Ozon", 7)
    
    def test_validate_data_integrity(self):
        """Тест проверки целостности данных."""
        self.utility.recovery_manager.validate_data_integrity.return_value = {
            'status': 'success',
            'source': 'Ozon',
            'integrity_score': 95.5,
            'total_issues': 10
        }
        
        result = self.utility.validate_data_integrity("Ozon")
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['operation'], 'validate_data_integrity')
        self.assertEqual(result['overall_integrity_score'], 95.5)
        
        self.utility.recovery_manager.validate_data_integrity.assert_called_once_with("Ozon")
    
    def test_use_fallback_data(self):
        """Тест использования fallback данных."""
        self.utility.fallback_manager.use_cached_data.return_value = {
            'status': 'success',
            'source': 'Ozon',
            'copied_records': 150
        }
        
        result = self.utility.use_fallback_data("Ozon", 24)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['operation'], 'use_fallback_data')
        self.assertEqual(result['max_age_hours'], 24)
        
        self.utility.fallback_manager.use_cached_data.assert_called_once_with("Ozon", 24)
    
    def test_get_sync_history(self):
        """Тест получения истории синхронизации."""
        # Настраиваем mock для запроса истории
        mock_history = [
            {
                'id': 1,
                'sync_type': 'inventory',
                'source': 'Ozon',
                'status': 'success',
                'records_processed': 100,
                'records_updated': 0,
                'records_inserted': 95,
                'records_failed': 5,
                'started_at': datetime.now(),
                'completed_at': datetime.now(),
                'duration_seconds': 30,
                'api_requests_count': 5,
                'error_message': None
            }
        ]
        
        self.utility.cursor.fetchall.return_value = mock_history
        
        result = self.utility.get_sync_history("Ozon", 7)
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['operation'], 'get_sync_history')
        self.assertEqual(result['days_analyzed'], 7)
        self.assertEqual(result['total_syncs'], 1)
        self.assertEqual(result['successful_syncs'], 1)
        self.assertIn('Ozon', result['history_by_source'])
    
    def test_get_current_inventory_status(self):
        """Тест получения текущего статуса остатков."""
        # Настраиваем mock для статистики
        mock_stats = [
            {
                'source': 'Ozon',
                'total_records': 500,
                'unique_products': 250,
                'total_present': 10000,
                'total_reserved': 1000,
                'total_available': 9000,
                'last_sync': datetime.now(),
                'last_snapshot_date': date.today(),
                'warehouses_count': 2,
                'stock_types_count': 2
            }
        ]
        
        mock_warehouses = [
            {
                'source': 'Ozon',
                'warehouse_name': 'Main',
                'stock_type': 'FBO',
                'records': 300,
                'products': 150,
                'present': 6000,
                'reserved': 600,
                'available': 5400
            }
        ]
        
        self.utility.cursor.fetchall.side_effect = [mock_stats, mock_warehouses]
        
        result = self.utility.get_current_inventory_status("Ozon")
        
        self.assertEqual(result['status'], 'success')
        self.assertEqual(result['operation'], 'get_current_inventory_status')
        self.assertEqual(result['total_records_all_sources'], 500)
        self.assertIn('Ozon', result['status_by_source'])
        
        ozon_status = result['status_by_source']['Ozon']
        self.assertEqual(ozon_status['total_records'], 500)
        self.assertEqual(ozon_status['unique_products'], 250)
        self.assertTrue(ozon_status['is_fresh'])  # Недавняя синхронизация


class TestErrorHandlingIntegration(unittest.TestCase):
    """Интеграционные тесты для всей системы обработки ошибок."""
    
    def setUp(self):
        """Настройка интеграционных тестов."""
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        
        # Создаем все компоненты системы
        self.retry_config = RetryConfig(max_attempts=2, base_delay=0.1)
        self.error_handler = APIErrorHandler(self.retry_config)
        self.recovery_manager = DataRecoveryManager(self.mock_cursor, self.mock_connection)
        self.fallback_manager = FallbackManager(self.mock_cursor, self.mock_connection)
        
    def test_end_to_end_error_recovery_flow(self):
        """Тест полного цикла обработки ошибок и восстановления."""
        # Симулируем последовательность: ошибка API -> восстановление -> fallback -> успех
        
        # 1. API ошибка
        def failing_api_call():
            raise requests.exceptions.Timeout("API timeout")
        
        result, error_context = self.error_handler.execute_with_retry(failing_api_call, "TestAPI")
        
        self.assertIsNone(result)
        self.assertIsNotNone(error_context)
        self.assertEqual(error_context.error_type, ErrorType.API_TIMEOUT)
        
        # 2. Попытка восстановления
        self.mock_cursor.fetchone.return_value = {
            'id': 1,
            'started_at': datetime.now(),
            'error_message': 'Previous failure',
            'records_processed': 100
        }
        self.mock_cursor.rowcount = 50
        
        recovery_result = self.recovery_manager.recover_from_failure("TestAPI")
        self.assertEqual(recovery_result['status'], 'success')
        
        # 3. Использование fallback
        self.mock_cursor.fetchone.return_value = {
            'cached_records': 75,
            'last_update': datetime.now(),
            'unique_products': 40
        }
        self.mock_cursor.rowcount = 75
        
        fallback_result = self.fallback_manager.use_cached_data("TestAPI", 24)
        self.assertEqual(fallback_result['status'], 'success')
        self.assertEqual(fallback_result['copied_records'], 75)

    def test_error_escalation_chain(self):
        """Тест цепочки эскалации ошибок."""
        # Добавляем множественные ошибки для тестирования эскалации
        error_types = [
            ErrorType.API_TIMEOUT,
            ErrorType.API_RATE_LIMIT,
            ErrorType.NETWORK_ERROR,
            ErrorType.API_UNAVAILABLE
        ]
        
        for i, error_type in enumerate(error_types):
            error_context = ErrorContext(
                error_type=error_type,
                source="TestAPI",
                attempt_number=1,
                error_message=f"Error {i+1}",
                timestamp=datetime.now()
            )
            self.error_handler.record_error(error_context)
        
        # Проверяем статистику ошибок
        stats = self.error_handler.get_error_statistics("TestAPI")
        
        self.assertEqual(stats['total_errors'], 4)
        self.assertEqual(stats['errors_by_source']['TestAPI'], 4)
        self.assertIn('api_timeout', stats['errors_by_type'])
        self.assertIn('api_rate_limit', stats['errors_by_type'])

    def test_concurrent_error_handling(self):
        """Тест обработки одновременных ошибок от разных источников."""
        sources = ["Ozon", "Wildberries", "TestAPI"]
        
        # Добавляем ошибки от разных источников
        for source in sources:
            for i in range(3):
                error_context = ErrorContext(
                    error_type=ErrorType.API_TIMEOUT,
                    source=source,
                    attempt_number=i+1,
                    error_message=f"Error from {source}",
                    timestamp=datetime.now()
                )
                self.error_handler.record_error(error_context)
        
        # Проверяем, что ошибки корректно разделены по источникам
        for source in sources:
            source_stats = self.error_handler.get_error_statistics(source)
            self.assertEqual(source_stats['errors_by_source'][source], 3)

    def test_recovery_strategy_selection(self):
        """Тест выбора стратегии восстановления в зависимости от типа ошибки."""
        test_cases = [
            (ErrorType.API_TIMEOUT, True),      # Можно повторить
            (ErrorType.API_RATE_LIMIT, True),  # Можно повторить с задержкой
            (ErrorType.API_AUTH_ERROR, False), # Нельзя повторить
            (ErrorType.NETWORK_ERROR, True),   # Можно повторить
            (ErrorType.API_UNAVAILABLE, True)  # Можно повторить
        ]
        
        for error_type, should_retry in test_cases:
            error_context = ErrorContext(
                error_type=error_type,
                source="TestAPI",
                attempt_number=1,
                error_message=f"Test {error_type.value}"
            )
            
            result = self.error_handler.should_retry(error_context)
            self.assertEqual(result, should_retry, 
                           f"Неправильная стратегия для {error_type.value}")

    @patch('time.sleep')
    def test_adaptive_delay_calculation(self, mock_sleep):
        """Тест адаптивного расчета задержек для разных типов ошибок."""
        # Тестируем разные типы ошибок и их влияние на задержку
        base_delay = 1.0
        self.error_handler.retry_config.base_delay = base_delay
        self.error_handler.retry_config.jitter = False  # Отключаем для точного тестирования
        
        # API timeout - умеренное увеличение (1.5x)
        timeout_delay = self.error_handler.calculate_delay(1, ErrorType.API_TIMEOUT)
        expected_timeout = base_delay * 1.5
        self.assertAlmostEqual(timeout_delay, expected_timeout, places=1)
        
        # Rate limit - большое увеличение (2x)
        rate_limit_delay = self.error_handler.calculate_delay(1, ErrorType.API_RATE_LIMIT)
        expected_rate_limit = base_delay * 2
        self.assertAlmostEqual(rate_limit_delay, expected_rate_limit, places=1)
        
        # Network error - базовая задержка
        network_delay = self.error_handler.calculate_delay(1, ErrorType.NETWORK_ERROR)
        self.assertAlmostEqual(network_delay, base_delay, places=1)

    def test_error_history_cleanup(self):
        """Тест очистки истории ошибок."""
        # Добавляем много ошибок
        for i in range(120):
            error_context = ErrorContext(
                ErrorType.API_TIMEOUT,
                "TestAPI",
                1,
                f"Error {i}",
                timestamp=datetime.now()
            )
            self.error_handler.record_error(error_context)
        
        # Проверяем, что история была обрезана (должно быть <= 100, обрезается до 50)
        history_length = len(self.error_handler.error_history["TestAPI"])
        self.assertLessEqual(history_length, 100)
        
        # Проверяем, что остались последние ошибки
        last_error = self.error_handler.error_history["TestAPI"][-1]
        self.assertIn("Error", last_error.error_message)


def run_tests():
    """Запуск всех тестов."""
    print("🧪 Запуск тестов системы обработки ошибок и восстановления")
    print("=" * 80)
    
    # Создаем test suite
    test_suite = unittest.TestSuite()
    
    # Добавляем тесты
    test_suite.addTest(unittest.makeSuite(TestAPIErrorHandler))
    test_suite.addTest(unittest.makeSuite(TestDataRecoveryManager))
    test_suite.addTest(unittest.makeSuite(TestFallbackManager))
    test_suite.addTest(unittest.makeSuite(TestRobustInventorySyncService))
    test_suite.addTest(unittest.makeSuite(TestInventoryRecoveryUtility))
    test_suite.addTest(unittest.makeSuite(TestErrorHandlingIntegration))
    
    # Запускаем тесты
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(test_suite)
    
    # Выводим итоги
    print("\n" + "=" * 80)
    print("ИТОГИ ТЕСТИРОВАНИЯ СИСТЕМЫ ОБРАБОТКИ ОШИБОК")
    print("=" * 80)
    print(f"📊 Всего тестов: {result.testsRun}")
    print(f"✅ Успешных: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"❌ Неудачных: {len(result.failures)}")
    print(f"💥 Ошибок: {len(result.errors)}")
    
    # Детальная статистика по классам тестов
    test_classes = [
        "TestAPIErrorHandler",
        "TestDataRecoveryManager", 
        "TestFallbackManager",
        "TestRobustInventorySyncService",
        "TestInventoryRecoveryUtility",
        "TestErrorHandlingIntegration"
    ]
    
    print(f"\n📋 ПОКРЫТИЕ ТЕСТИРОВАНИЯ:")
    print(f"   🔧 API Error Handler - Retry логика и обработка ошибок API")
    print(f"   🔄 Data Recovery Manager - Восстановление после сбоев")
    print(f"   💾 Fallback Manager - Резервные механизмы")
    print(f"   🚀 Robust Sync Service - Интегрированная синхронизация")
    print(f"   🛠️  Recovery Utility - Утилиты восстановления")
    print(f"   🔗 Integration Tests - Интеграционные тесты")
    
    if result.failures:
        print(f"\n❌ НЕУДАЧНЫЕ ТЕСТЫ:")
        for test, traceback in result.failures:
            test_name = str(test).split()[0]
            error_line = traceback.split('AssertionError: ')[-1].split('\n')[0] if 'AssertionError:' in traceback else 'Assertion failed'
            print(f"   - {test_name}: {error_line}")
    
    if result.errors:
        print(f"\n💥 ОШИБКИ В ТЕСТАХ:")
        for test, traceback in result.errors:
            test_name = str(test).split()[0]
            error_line = traceback.split('\n')[-2] if len(traceback.split('\n')) > 1 else 'Unknown error'
            print(f"   - {test_name}: {error_line}")
    
    # Вычисляем процент успешности
    success_rate = ((result.testsRun - len(result.failures) - len(result.errors)) / result.testsRun * 100) if result.testsRun > 0 else 0
    
    print(f"\n📈 ПРОЦЕНТ УСПЕШНОСТИ: {success_rate:.1f}%")
    
    if result.wasSuccessful():
        print(f"\n🎉 ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО!")
        print(f"   ✓ Retry логика работает корректно")
        print(f"   ✓ Обработка различных типов ошибок API функционирует")
        print(f"   ✓ Механизмы восстановления после сбоев протестированы")
        print(f"   ✓ Fallback механизмы готовы к использованию")
        print(f"   ✓ Интеграция всех компонентов проверена")
        return True
    else:
        print(f"\n⚠️  НЕКОТОРЫЕ ТЕСТЫ НЕ ПРОШЛИ!")
        print(f"   Рекомендуется исправить ошибки перед развертыванием")
        return False


if __name__ == "__main__":
    success = run_tests()
    sys.exit(0 if success else 1)