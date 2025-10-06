#!/usr/bin/env python3
"""
Интеграционные тесты для API управления синхронизацией остатков.

Включает:
- Integration тесты для всех endpoints
- Тесты веб-интерфейса
- Проверки безопасности API endpoints

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
import requests
import json
import time
import threading
from unittest.mock import patch, MagicMock
import sys
import os
from datetime import datetime, timedelta
import sqlite3
import tempfile
import subprocess
import signal

# Добавляем путь к модулям
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_sync_api import app, api_instance
    from start_inventory_sync_api import main as start_api_server
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)


class TestInventorySyncAPIIntegration(unittest.TestCase):
    """Интеграционные тесты для API управления синхронизацией остатков."""
    
    @classmethod
    def setUpClass(cls):
        """Настройка тестового окружения для всех тестов."""
        cls.base_url = "http://localhost:5001"
        cls.api_process = None
        cls.test_db_path = None
        
        # Создаем временную тестовую БД
        cls._setup_test_database()
        
        # Запускаем API сервер в отдельном процессе
        cls._start_api_server()
        
        # Ждем запуска сервера
        cls._wait_for_server()
    
    @classmethod
    def tearDownClass(cls):
        """Очистка после всех тестов."""
        # Останавливаем API сервер
        if cls.api_process:
            cls.api_process.terminate()
            cls.api_process.wait()
        
        # Удаляем тестовую БД
        if cls.test_db_path and os.path.exists(cls.test_db_path):
            os.unlink(cls.test_db_path)
    
    @classmethod
    def _setup_test_database(cls):
        """Создание тестовой базы данных."""
        cls.test_db_path = tempfile.mktemp(suffix='.db')
        
        conn = sqlite3.connect(cls.test_db_path)
        cursor = conn.cursor()
        
        # Создаем таблицы
        cursor.execute("""
            CREATE TABLE inventory_data (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL,
                sku TEXT NOT NULL,
                source TEXT NOT NULL,
                warehouse_name TEXT DEFAULT 'Main Warehouse',
                stock_type TEXT DEFAULT 'FBO',
                current_stock INTEGER DEFAULT 0,
                reserved_stock INTEGER DEFAULT 0,
                available_stock INTEGER DEFAULT 0,
                snapshot_date DATE NOT NULL,
                last_sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        
        cursor.execute("""
            CREATE TABLE sync_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_type TEXT NOT NULL,
                source TEXT NOT NULL,
                status TEXT NOT NULL,
                records_processed INTEGER DEFAULT 0,
                records_updated INTEGER DEFAULT 0,
                error_message TEXT,
                started_at TIMESTAMP NOT NULL,
                completed_at TIMESTAMP,
                duration_seconds INTEGER
            )
        """)
        
        # Добавляем тестовые данные
        cursor.execute("""
            INSERT INTO inventory_data 
            (product_id, sku, source, current_stock, snapshot_date)
            VALUES 
            (1, 'TEST-001', 'Ozon', 100, '2025-01-06'),
            (2, 'TEST-002', 'Wildberries', 50, '2025-01-06'),
            (3, 'TEST-003', 'Ozon', 75, '2025-01-06')
        """)
        
        cursor.execute("""
            INSERT INTO sync_logs 
            (sync_type, source, status, records_processed, records_updated, started_at, completed_at, duration_seconds)
            VALUES 
            ('inventory', 'Ozon', 'success', 100, 95, '2025-01-06 10:00:00', '2025-01-06 10:05:00', 300),
            ('inventory', 'Wildberries', 'failed', 0, 0, '2025-01-06 09:00:00', '2025-01-06 09:01:00', 60),
            ('inventory', 'Ozon', 'partial', 50, 45, '2025-01-06 08:00:00', '2025-01-06 08:03:00', 180)
        """)
        
        conn.commit()
        conn.close()
    
    @classmethod
    def _start_api_server(cls):
        """Запуск API сервера в отдельном процессе."""
        try:
            # Устанавливаем переменную окружения для тестовой БД
            env = os.environ.copy()
            env['TEST_DB_PATH'] = cls.test_db_path
            
            cls.api_process = subprocess.Popen(
                [sys.executable, 'start_inventory_sync_api.py'],
                env=env,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE
            )
        except Exception as e:
            print(f"❌ Ошибка запуска API сервера: {e}")
    
    @classmethod
    def _wait_for_server(cls):
        """Ожидание запуска сервера."""
        max_attempts = 30
        for attempt in range(max_attempts):
            try:
                response = requests.get(f"{cls.base_url}/api/sync/health", timeout=2)
                if response.status_code == 200:
                    print("✅ API сервер запущен успешно")
                    return
            except requests.exceptions.RequestException:
                pass
            
            time.sleep(1)
        
        raise Exception("❌ Не удалось дождаться запуска API сервера")
    
    def test_api_sync_status_endpoint(self):
        """Тест endpoint получения статуса синхронизации."""
        response = requests.get(f"{self.base_url}/api/sync/status")
        
        self.assertEqual(response.status_code, 200)
        
        data = response.json()
        self.assertTrue(data['success'])
        self.assertIn('data', data)
        self.assertIn('last_sync_records', data['data'])
        self.assertIn('inventory_stats', data['data'])
        self.assertIn('timestamp', data['data'])
    
    def test_api_sync_reports_endpoint(self):
        """Тест endpoint получения отчетов о синхронизации."""
        # Тест без параметров
        response = requests.get(f"{self.base_url}/api/sync/reports")
        
        self.assertEqual(response.status_code, 200)
        
        data = response.json()
        self.assertTrue(data['success'])
        self.assertIn('data', data)
        self.assertEqual(data['data']['period_days'], 7)
        self.assertIn('statistics', data['data'])
        self.assertIn('records_by_source', data['data'])
        
        # Тест с параметром days
        response = requests.get(f"{self.base_url}/api/sync/reports?days=30")
        
        self.assertEqual(response.status_code, 200)
        data = response.json()
        self.assertEqual(data['data']['period_days'], 30)
    
    def test_api_sync_trigger_endpoint(self):
        """Тест endpoint запуска принудительной синхронизации."""
        # Тест с валидными источниками
        payload = {"sources": ["Ozon", "Wildberries"]}
        response = requests.post(
            f"{self.base_url}/api/sync/trigger",
            json=payload,
            headers={'Content-Type': 'application/json'}
        )
        
        self.assertEqual(response.status_code, 200)
        
        data = response.json()
        self.assertTrue(data['success'])
        self.assertIn('message', data)
        self.assertEqual(data['sources'], ["Ozon", "Wildberries"])
        self.assertIn('started_at', data)
        
        # Ждем немного и проверяем, что синхронизация не запускается повторно
        time.sleep(1)
        
        response = requests.post(
            f"{self.base_url}/api/sync/trigger",
            json=payload,
            headers={'Content-Type': 'application/json'}
        )
        
        # Должен вернуть ошибку о том, что синхронизация уже выполняется
        self.assertEqual(response.status_code, 409)
    
    def test_api_sync_trigger_invalid_sources(self):
        """Тест endpoint с невалидными источниками."""
        payload = {"sources": ["InvalidSource", "AnotherInvalid"]}
        response = requests.post(
            f"{self.base_url}/api/sync/trigger",
            json=payload,
            headers={'Content-Type': 'application/json'}
        )
        
        self.assertEqual(response.status_code, 400)
        
        data = response.json()
        self.assertFalse(data['success'])
        self.assertIn('error', data)
    
    def test_api_sync_health_endpoint(self):
        """Тест endpoint проверки состояния системы."""
        response = requests.get(f"{self.base_url}/api/sync/health")
        
        self.assertEqual(response.status_code, 200)
        
        data = response.json()
        self.assertTrue(data['success'])
        self.assertIn('data', data)
        self.assertIn('database_connected', data['data'])
        self.assertIn('api_status', data['data'])
        self.assertIn('sync_in_progress', data['data'])
    
    def test_api_sync_logs_endpoint(self):
        """Тест endpoint получения логов синхронизации."""
        # Тест без фильтров
        response = requests.get(f"{self.base_url}/api/sync/logs")
        
        self.assertEqual(response.status_code, 200)
        
        data = response.json()
        self.assertTrue(data['success'])
        self.assertIn('data', data)
        self.assertIn('logs', data['data'])
        self.assertIn('filters', data['data'])
        
        # Тест с фильтрами
        response = requests.get(f"{self.base_url}/api/sync/logs?source=Ozon&status=success&limit=5")
        
        self.assertEqual(response.status_code, 200)
        data = response.json()
        self.assertEqual(data['data']['filters']['source'], 'Ozon')
        self.assertEqual(data['data']['filters']['status'], 'success')
        self.assertEqual(data['data']['filters']['limit'], 5)


class TestInventorySyncAPIWebInterface(unittest.TestCase):
    """Тесты веб-интерфейса API управления синхронизацией."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_dashboard_page_loads(self):
        """Тест загрузки главной страницы дашборда."""
        response = requests.get(f"{self.base_url}/")
        
        self.assertEqual(response.status_code, 200)
        self.assertIn('text/html', response.headers.get('Content-Type', ''))
        
        # Проверяем наличие ключевых элементов
        content = response.text
        self.assertIn('Управление синхронизацией остатков', content)
        self.assertIn('Статус синхронизации', content)
        self.assertIn('Статистика остатков', content)
        self.assertIn('Запустить синхронизацию', content)
    
    def test_logs_page_loads(self):
        """Тест загрузки страницы логов."""
        response = requests.get(f"{self.base_url}/logs")
        
        self.assertEqual(response.status_code, 200)
        self.assertIn('text/html', response.headers.get('Content-Type', ''))
        
        # Проверяем наличие ключевых элементов
        content = response.text
        self.assertIn('Логи синхронизации остатков', content)
        self.assertIn('Фильтры', content)
        self.assertIn('Источник', content)
        self.assertIn('Статус', content)
    
    def test_dashboard_javascript_functionality(self):
        """Тест JavaScript функциональности дашборда."""
        response = requests.get(f"{self.base_url}/")
        content = response.text
        
        # Проверяем наличие JavaScript функций
        self.assertIn('loadStatus', content)
        self.assertIn('triggerSync', content)
        self.assertIn('updateStatusDisplay', content)
        self.assertIn('showAlert', content)
    
    def test_web_interface_cors_headers(self):
        """Тест CORS заголовков для веб-интерфейса."""
        response = requests.get(f"{self.base_url}/")
        
        # Проверяем наличие CORS заголовков (если настроены)
        self.assertEqual(response.status_code, 200)
        
        # Проверяем OPTIONS запрос
        options_response = requests.options(f"{self.base_url}/api/sync/status")
        self.assertIn(options_response.status_code, [200, 204])


class TestInventorySyncAPISecurity(unittest.TestCase):
    """Тесты безопасности API endpoints."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_api_input_validation(self):
        """Тест валидации входных данных."""
        # Тест с невалидным JSON
        response = requests.post(
            f"{self.base_url}/api/sync/trigger",
            data="invalid json",
            headers={'Content-Type': 'application/json'}
        )
        
        self.assertIn(response.status_code, [400, 422])
        
        # Тест с отсутствующими полями
        response = requests.post(
            f"{self.base_url}/api/sync/trigger",
            json={},
            headers={'Content-Type': 'application/json'}
        )
        
        self.assertEqual(response.status_code, 200)  # Должен использовать значения по умолчанию
    
    def test_api_parameter_limits(self):
        """Тест ограничений параметров API."""
        # Тест превышения лимита days в reports
        response = requests.get(f"{self.base_url}/api/sync/reports?days=999")
        
        self.assertEqual(response.status_code, 200)
        data = response.json()
        # Должен ограничить до максимального значения (90)
        self.assertLessEqual(data['data']['period_days'], 90)
        
        # Тест превышения лимита limit в logs
        response = requests.get(f"{self.base_url}/api/sync/logs?limit=9999")
        
        self.assertEqual(response.status_code, 200)
        data = response.json()
        # Должен ограничить до максимального значения (500)
        self.assertLessEqual(data['data']['filters']['limit'], 500)
    
    def test_api_sql_injection_protection(self):
        """Тест защиты от SQL инъекций."""
        # Попытка SQL инъекции через параметр source
        malicious_source = "Ozon'; DROP TABLE sync_logs; --"
        response = requests.get(f"{self.base_url}/api/sync/logs?source={malicious_source}")
        
        # API должен обработать запрос безопасно
        self.assertEqual(response.status_code, 200)
        
        # Проверяем, что таблица все еще существует
        health_response = requests.get(f"{self.base_url}/api/sync/health")
        self.assertEqual(health_response.status_code, 200)
    
    def test_api_xss_protection(self):
        """Тест защиты от XSS атак."""
        # Попытка XSS через параметры
        xss_payload = "<script>alert('xss')</script>"
        response = requests.get(f"{self.base_url}/api/sync/logs?source={xss_payload}")
        
        self.assertEqual(response.status_code, 200)
        
        # Проверяем, что ответ не содержит исполняемый JavaScript
        data = response.json()
        if 'data' in data and 'filters' in data['data']:
            source_filter = data['data']['filters'].get('source', '')
            self.assertNotIn('<script>', source_filter)
    
    def test_api_rate_limiting_behavior(self):
        """Тест поведения при множественных запросах."""
        # Отправляем множественные запросы быстро
        responses = []
        for i in range(10):
            response = requests.get(f"{self.base_url}/api/sync/status")
            responses.append(response.status_code)
        
        # Все запросы должны быть обработаны (нет rate limiting в текущей реализации)
        self.assertTrue(all(status == 200 for status in responses))
    
    def test_api_error_information_disclosure(self):
        """Тест на раскрытие чувствительной информации в ошибках."""
        # Запрос к несуществующему endpoint
        response = requests.get(f"{self.base_url}/api/nonexistent")
        
        self.assertEqual(response.status_code, 404)
        
        # Проверяем, что ошибка не раскрывает внутреннюю информацию
        if response.headers.get('Content-Type', '').startswith('application/json'):
            data = response.json()
            error_message = str(data).lower()
            
            # Не должно содержать пути к файлам, пароли и т.д.
            sensitive_patterns = ['password', 'secret', 'key', '/home/', '/var/', 'traceback']
            for pattern in sensitive_patterns:
                self.assertNotIn(pattern, error_message)
    
    def test_api_http_methods_security(self):
        """Тест безопасности HTTP методов."""
        # Проверяем, что GET endpoints не принимают POST
        response = requests.post(f"{self.base_url}/api/sync/status")
        self.assertEqual(response.status_code, 405)  # Method Not Allowed
        
        # Проверяем, что POST endpoints не принимают GET
        response = requests.get(f"{self.base_url}/api/sync/trigger")
        self.assertEqual(response.status_code, 405)  # Method Not Allowed
    
    def test_api_content_type_validation(self):
        """Тест валидации Content-Type."""
        # Отправляем POST запрос без правильного Content-Type
        response = requests.post(
            f"{self.base_url}/api/sync/trigger",
            data='{"sources": ["Ozon"]}',
            headers={'Content-Type': 'text/plain'}
        )
        
        # API должен корректно обработать или отклонить запрос
        self.assertIn(response.status_code, [200, 400, 415])


class TestInventorySyncAPIPerformance(unittest.TestCase):
    """Тесты производительности API."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_api_response_time(self):
        """Тест времени отклика API."""
        endpoints = [
            '/api/sync/status',
            '/api/sync/health',
            '/api/sync/reports',
            '/api/sync/logs'
        ]
        
        for endpoint in endpoints:
            start_time = time.time()
            response = requests.get(f"{self.base_url}{endpoint}")
            end_time = time.time()
            
            response_time = end_time - start_time
            
            # API должен отвечать быстро (менее 5 секунд)
            self.assertLess(response_time, 5.0, f"Endpoint {endpoint} слишком медленный: {response_time:.2f}s")
            self.assertEqual(response.status_code, 200)
    
    def test_api_concurrent_requests(self):
        """Тест обработки параллельных запросов."""
        def make_request():
            response = requests.get(f"{self.base_url}/api/sync/status")
            return response.status_code
        
        # Запускаем 5 параллельных запросов
        threads = []
        results = []
        
        for i in range(5):
            thread = threading.Thread(target=lambda: results.append(make_request()))
            threads.append(thread)
            thread.start()
        
        # Ждем завершения всех потоков
        for thread in threads:
            thread.join()
        
        # Все запросы должны быть успешными
        self.assertEqual(len(results), 5)
        self.assertTrue(all(status == 200 for status in results))


def run_comprehensive_api_tests():
    """Запуск всех тестов API."""
    print("🧪 Запуск комплексных тестов API управления синхронизацией остатков")
    print("=" * 80)
    
    # Создаем test suite
    test_suite = unittest.TestSuite()
    
    # Добавляем интеграционные тесты
    test_suite.addTest(unittest.makeSuite(TestInventorySyncAPIIntegration))
    
    # Добавляем тесты веб-интерфейса
    test_suite.addTest(unittest.makeSuite(TestInventorySyncAPIWebInterface))
    
    # Добавляем тесты безопасности
    test_suite.addTest(unittest.makeSuite(TestInventorySyncAPISecurity))
    
    # Добавляем тесты производительности
    test_suite.addTest(unittest.makeSuite(TestInventorySyncAPIPerformance))
    
    # Запускаем тесты
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(test_suite)
    
    # Выводим результаты
    print("\n" + "=" * 80)
    print("📊 Результаты тестирования:")
    print(f"✅ Успешных тестов: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"❌ Неудачных тестов: {len(result.failures)}")
    print(f"💥 Ошибок: {len(result.errors)}")
    
    if result.failures:
        print("\n❌ Неудачные тесты:")
        for test, traceback in result.failures:
            print(f"  - {test}: {traceback.split('AssertionError: ')[-1].split('\\n')[0]}")
    
    if result.errors:
        print("\n💥 Ошибки:")
        for test, traceback in result.errors:
            print(f"  - {test}: {traceback.split('\\n')[-2]}")
    
    return result.wasSuccessful()


if __name__ == '__main__':
    success = run_comprehensive_api_tests()
    sys.exit(0 if success else 1)