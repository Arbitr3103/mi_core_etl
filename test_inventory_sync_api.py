#!/usr/bin/env python3
"""
Тесты для API управления синхронизацией остатков.

Проверяет функциональность всех endpoints и веб-интерфейса.

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
import requests
import json
import time
from unittest.mock import patch, MagicMock
import sys
import os

# Добавляем путь к модулям
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_sync_api import app, api_instance
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)


class TestInventorySyncAPI(unittest.TestCase):
    """Тесты для API управления синхронизацией остатков."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.app = app.test_client()
        self.app.testing = True
        
        # Мокаем подключение к БД
        self.mock_cursor = MagicMock()
        self.mock_connection = MagicMock()
        api_instance.cursor = self.mock_cursor
        api_instance.connection = self.mock_connection
    
    def test_get_sync_status_success(self):
        """Тест успешного получения статуса синхронизации."""
        # Мокаем данные из БД
        self.mock_cursor.fetchall.return_value = [
            {
                'source': 'Ozon',
                'status': 'success',
                'records_processed': 100,
                'records_updated': 95,
                'started_at': '2025-01-06 10:00:00',
                'completed_at': '2025-01-06 10:05:00',
                'duration_seconds': 300,
                'error_message': None
            }
        ]
        
        self.mock_cursor.fetchone.return_value = {
            'total_products': 150,
            'ozon_products': 100,
            'wb_products': 50,
            'last_data_update': '2025-01-06 10:05:00'
        }
        
        # Выполняем запрос
        response = self.app.get('/api/sync/status')
        
        # Проверяем результат
        self.assertEqual(response.status_code, 200)
        
        data = json.loads(response.data)
        self.assertTrue(data['success'])
        self.assertIn('data', data)
        self.assertIn('last_sync_records', data['data'])
        self.assertIn('inventory_stats', data['data'])
    
    def test_get_sync_reports_success(self):
        """Тест успешного получения отчетов о синхронизации."""
        # Мокаем данные из БД
        self.mock_cursor.fetchall.return_value = [
            {
                'source': 'Ozon',
                'status': 'success',
                'records_processed': 100,
                'records_updated': 95,
                'started_at': '2025-01-06 10:00:00',
                'completed_at': '2025-01-06 10:05:00',
                'duration_seconds': 300,
                'error_message': None
            },
            {
                'source': 'Wildberries',
                'status': 'failed',
                'records_processed': 0,
                'records_updated': 0,
                'started_at': '2025-01-06 09:00:00',
                'completed_at': '2025-01-06 09:01:00',
                'duration_seconds': 60,
                'error_message': 'API timeout'
            }
        ]
        
        # Выполняем запрос
        response = self.app.get('/api/sync/reports?days=7')
        
        # Проверяем результат
        self.assertEqual(response.status_code, 200)
        
        data = json.loads(response.data)
        self.assertTrue(data['success'])
        self.assertIn('data', data)
        self.assertEqual(data['data']['period_days'], 7)
        self.assertIn('statistics', data['data'])
        self.assertIn('records_by_source', data['data'])
    
    @patch('inventory_sync_api.threading.Thread')
    def test_trigger_sync_success(self, mock_thread):
        """Тест успешного запуска принудительной синхронизации."""
        # Мокаем поток
        mock_thread_instance = MagicMock()
        mock_thread.return_value = mock_thread_instance
        
        # Выполняем запрос
        response = self.app.post('/api/sync/trigger', 
                               json={'sources': ['Ozon', 'Wildberries']},
                               content_type='application/json')
        
        # Проверяем результат
        self.assertEqual(response.status_code, 200)
        
        data = json.loads(response.data)
        self.assertTrue(data['success'])
        self.assertIn('message', data)
        self.assertEqual(data['sources'], ['Ozon', 'Wildberries'])
        
        # Проверяем, что поток был запущен
        mock_thread.assert_called_once()
        mock_thread_instance.start.assert_called_once()
    
    def test_trigger_sync_invalid_sources(self):
        """Тест запуска синхронизации с невалидными источниками."""
        response = self.app.post('/api/sync/trigger', 
                               json={'sources': ['InvalidSource']},
                               content_type='application/json')
        
        # Проверяем результат
        self.assertEqual(response.status_code, 400)
        
        data = json.loads(response.data)
        self.assertFalse(data['success'])
        self.assertIn('error', data)
    
    def test_sync_health_check_success(self):
        """Тест проверки состояния системы синхронизации."""
        # Мокаем данные о свежести данных
        self.mock_cursor.fetchall.return_value = [
            {
                'source': 'Ozon',
                'last_sync': '2025-01-06 10:00:00',
                'products_count': 100
            }
        ]
        
        # Выполняем запрос
        response = self.app.get('/api/sync/health')
        
        # Проверяем результат
        self.assertEqual(response.status_code, 200)
        
        data = json.loads(response.data)
        self.assertTrue(data['success'])
        self.assertIn('data', data)
        self.assertIn('database_connected', data['data'])
        self.assertIn('api_status', data['data'])
    
    def test_get_sync_logs_success(self):
        """Тест получения логов синхронизации."""
        # Мокаем данные логов
        self.mock_cursor.fetchall.return_value = [
            {
                'id': 1,
                'source': 'Ozon',
                'status': 'success',
                'records_processed': 100,
                'records_updated': 95,
                'started_at': '2025-01-06 10:00:00',
                'completed_at': '2025-01-06 10:05:00',
                'duration_seconds': 300,
                'error_message': None
            }
        ]
        
        # Выполняем запрос
        response = self.app.get('/api/sync/logs?source=Ozon&limit=10')
        
        # Проверяем результат
        self.assertEqual(response.status_code, 200)
        
        data = json.loads(response.data)
        self.assertTrue(data['success'])
        self.assertIn('data', data)
        self.assertIn('logs', data['data'])
        self.assertIn('filters', data['data'])
    
    def test_dashboard_page_loads(self):
        """Тест загрузки главной страницы дашборда."""
        response = self.app.get('/')
        
        # Проверяем результат
        self.assertEqual(response.status_code, 200)
        self.assertIn(b'<!DOCTYPE html>', response.data)
        self.assertIn('Управление синхронизацией остатков'.encode('utf-8'), response.data)
    
    def test_logs_page_loads(self):
        """Тест загрузки страницы логов."""
        response = self.app.get('/logs')
        
        # Проверяем результат
        self.assertEqual(response.status_code, 200)
        self.assertIn(b'<!DOCTYPE html>', response.data)
        self.assertIn('Логи синхронизации остатков'.encode('utf-8'), response.data)


class TestInventorySyncAPIClass(unittest.TestCase):
    """Тесты для класса InventorySyncAPI."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.api = api_instance
        
        # Мокаем подключение к БД
        self.mock_cursor = MagicMock()
        self.mock_connection = MagicMock()
        self.api.cursor = self.mock_cursor
        self.api.connection = self.mock_connection
    
    def test_get_sync_status_method(self):
        """Тест метода получения статуса синхронизации."""
        # Мокаем данные из БД
        self.mock_cursor.fetchall.return_value = [
            {
                'source': 'Ozon',
                'status': 'success',
                'records_processed': 100,
                'records_updated': 95,
                'started_at': '2025-01-06 10:00:00',
                'completed_at': '2025-01-06 10:05:00',
                'duration_seconds': 300,
                'error_message': None
            }
        ]
        
        self.mock_cursor.fetchone.return_value = {
            'total_products': 150,
            'ozon_products': 100,
            'wb_products': 50,
            'last_data_update': '2025-01-06 10:05:00'
        }
        
        # Выполняем метод
        result = self.api.get_sync_status()
        
        # Проверяем результат
        self.assertIn('last_sync_records', result)
        self.assertIn('inventory_stats', result)
        self.assertIn('timestamp', result)
        self.assertEqual(len(result['last_sync_records']), 1)
    
    def test_get_sync_reports_method(self):
        """Тест метода получения отчетов о синхронизации."""
        # Мокаем данные из БД
        self.mock_cursor.fetchall.return_value = [
            {
                'source': 'Ozon',
                'status': 'success',
                'records_processed': 100,
                'records_updated': 95,
                'started_at': '2025-01-06 10:00:00',
                'completed_at': '2025-01-06 10:05:00',
                'duration_seconds': 300,
                'error_message': None
            }
        ]
        
        # Выполняем метод
        result = self.api.get_sync_reports(7)
        
        # Проверяем результат
        self.assertIn('period_days', result)
        self.assertIn('statistics', result)
        self.assertIn('records_by_source', result)
        self.assertEqual(result['period_days'], 7)
        self.assertEqual(result['statistics']['total_syncs'], 1)
        self.assertEqual(result['statistics']['successful_syncs'], 1)


def run_integration_tests():
    """Запуск интеграционных тестов с реальным API."""
    print("🧪 Запуск интеграционных тестов API...")
    
    # Базовый URL для тестов (предполагаем, что API запущен на localhost:5001)
    base_url = "http://localhost:5001"
    
    try:
        # Тест проверки состояния
        print("📋 Тестирование endpoint /api/sync/health...")
        response = requests.get(f"{base_url}/api/sync/health", timeout=5)
        if response.status_code == 200:
            print("✅ Health check прошел успешно")
        else:
            print(f"❌ Health check неуспешен: {response.status_code}")
        
        # Тест получения статуса
        print("📋 Тестирование endpoint /api/sync/status...")
        response = requests.get(f"{base_url}/api/sync/status", timeout=5)
        if response.status_code == 200:
            print("✅ Получение статуса прошло успешно")
        else:
            print(f"❌ Получение статуса неуспешно: {response.status_code}")
        
        # Тест получения логов
        print("📋 Тестирование endpoint /api/sync/logs...")
        response = requests.get(f"{base_url}/api/sync/logs?limit=5", timeout=5)
        if response.status_code == 200:
            print("✅ Получение логов прошло успешно")
        else:
            print(f"❌ Получение логов неуспешно: {response.status_code}")
        
        # Тест загрузки веб-интерфейса
        print("📋 Тестирование веб-интерфейса...")
        response = requests.get(f"{base_url}/", timeout=5)
        if response.status_code == 200 and "Управление синхронизацией" in response.text:
            print("✅ Веб-интерфейс загружается успешно")
        else:
            print(f"❌ Веб-интерфейс не загружается: {response.status_code}")
        
        print("✅ Интеграционные тесты завершены")
        
    except requests.exceptions.ConnectionError:
        print("❌ Не удалось подключиться к API. Убедитесь, что сервер запущен на localhost:5001")
    except Exception as e:
        print(f"❌ Ошибка при выполнении интеграционных тестов: {e}")


if __name__ == '__main__':
    print("🧪 Запуск тестов API управления синхронизацией остатков")
    print("=" * 60)
    
    # Запускаем unit тесты
    print("📋 Unit тесты:")
    unittest.main(argv=[''], exit=False, verbosity=2)
    
    print("\n" + "=" * 60)
    
    # Запускаем интеграционные тесты
    print("📋 Интеграционные тесты:")
    run_integration_tests()
    
    print("\n✅ Все тесты завершены")