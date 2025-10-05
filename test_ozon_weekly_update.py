#!/usr/bin/env python3
"""
Test Script for Ozon Analytics Weekly Update System
Тестовый скрипт для системы еженедельного обновления Ozon Analytics

Выполняет базовые тесты компонентов системы без реальных API вызовов.

Автор: Manhattan System
Версия: 1.0
"""

import os
import sys
import unittest
import tempfile
import shutil
from datetime import datetime, date, timedelta
from unittest.mock import Mock, patch, MagicMock

# Добавляем текущую директорию в путь для импорта модулей
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    from ozon_weekly_update import OzonWeeklyUpdater
    from ozon_update_monitor import OzonUpdateMonitor
except ImportError as e:
    print(f"Ошибка импорта модулей: {e}")
    print("Убедитесь, что все файлы находятся в одной директории")
    sys.exit(1)


class TestOzonWeeklyUpdater(unittest.TestCase):
    """Тесты для класса OzonWeeklyUpdater"""
    
    def setUp(self):
        """Настройка тестового окружения"""
        # Создаем временную директорию для тестов
        self.test_dir = tempfile.mkdtemp()
        self.original_dir = os.getcwd()
        os.chdir(self.test_dir)
        
        # Создаем тестовый конфигурационный файл
        self.config_content = """
DB_HOST = 'localhost'
DB_USER = 'test_user'
DB_PASSWORD = 'test_password'
DB_NAME = 'test_db'
DB_PORT = 3306

OZON_CLIENT_ID = 'test_client_id'
OZON_API_KEY = 'test_api_key'

SMTP_SERVER = 'localhost'
SMTP_PORT = 587
SMTP_USER = 'test@example.com'
SMTP_PASSWORD = 'test_password'
FROM_EMAIL = 'noreply@example.com'
ADMIN_EMAILS = ['admin@example.com']

LOOKBACK_DAYS = 7
MAX_DATE_RANGE_DAYS = 30
BATCH_SIZE = 10
"""
        
        with open('test_config.py', 'w') as f:
            f.write(self.config_content)
        
        # Создаем директорию для логов
        os.makedirs('logs', exist_ok=True)
    
    def tearDown(self):
        """Очистка после тестов"""
        os.chdir(self.original_dir)
        shutil.rmtree(self.test_dir)
    
    def test_config_loading(self):
        """Тест загрузки конфигурации"""
        updater = OzonWeeklyUpdater('test_config.py')
        
        self.assertEqual(updater.config['database']['host'], 'localhost')
        self.assertEqual(updater.config['database']['user'], 'test_user')
        self.assertEqual(updater.config['ozon_api']['client_id'], 'test_client_id')
        self.assertEqual(updater.config['update']['lookback_days'], 7)
    
    def test_default_config(self):
        """Тест конфигурации по умолчанию"""
        updater = OzonWeeklyUpdater('nonexistent_config.py')
        
        # Проверяем, что загружается конфигурация по умолчанию
        self.assertIsNotNone(updater.config)
        self.assertIn('database', updater.config)
        self.assertIn('ozon_api', updater.config)
    
    def test_logging_setup(self):
        """Тест настройки логирования"""
        updater = OzonWeeklyUpdater('test_config.py')
        
        self.assertIsNotNone(updater.logger)
        self.assertEqual(updater.logger.name, 'ozon_weekly_updater')
    
    def test_calculate_update_periods(self):
        """Тест вычисления периодов обновления"""
        updater = OzonWeeklyUpdater('test_config.py')
        
        # Тест с пустыми датами (первый запуск)
        last_dates = {
            'funnel': None,
            'demographics': None,
            'campaigns': None
        }
        
        periods = updater.calculate_update_periods(last_dates)
        
        self.assertIsInstance(periods, list)
        self.assertGreater(len(periods), 0)
        
        # Проверяем, что периоды корректные
        for period in periods:
            self.assertIsInstance(period, tuple)
            self.assertEqual(len(period), 2)
            self.assertIsInstance(period[0], date)
            self.assertIsInstance(period[1], date)
            self.assertLessEqual(period[0], period[1])
    
    def test_calculate_update_periods_with_existing_data(self):
        """Тест вычисления периодов с существующими данными"""
        updater = OzonWeeklyUpdater('test_config.py')
        
        # Тест с существующими датами
        last_week = date.today() - timedelta(days=7)
        last_dates = {
            'funnel': last_week,
            'demographics': last_week,
            'campaigns': last_week
        }
        
        periods = updater.calculate_update_periods(last_dates)
        
        self.assertIsInstance(periods, list)
        # Должен быть хотя бы один период для обновления
        if periods:
            self.assertGreaterEqual(periods[0][0], last_week)
    
    @patch('mysql.connector.connect')
    def test_database_connection(self, mock_connect):
        """Тест подключения к базе данных"""
        # Настраиваем мок
        mock_connection = Mock()
        mock_connection.is_connected.return_value = True
        mock_connect.return_value = mock_connection
        
        updater = OzonWeeklyUpdater('test_config.py')
        result = updater.connect_database()
        
        self.assertTrue(result)
        self.assertIsNotNone(updater.db_connection)
        mock_connect.assert_called_once()
    
    @patch('mysql.connector.connect')
    def test_database_connection_failure(self, mock_connect):
        """Тест неудачного подключения к базе данных"""
        # Настраиваем мок для ошибки
        mock_connect.side_effect = Exception("Connection failed")
        
        updater = OzonWeeklyUpdater('test_config.py')
        result = updater.connect_database()
        
        self.assertFalse(result)
        self.assertIsNone(updater.db_connection)
    
    @patch('requests.post')
    def test_authenticate_ozon_api(self, mock_post):
        """Тест аутентификации в Ozon API"""
        # Настраиваем мок успешного ответа
        mock_response = Mock()
        mock_response.status_code = 200
        mock_response.json.return_value = {'access_token': 'test_token'}
        mock_post.return_value = mock_response
        
        updater = OzonWeeklyUpdater('test_config.py')
        token = updater.authenticate_ozon_api('test_client', 'test_key')
        
        self.assertEqual(token, 'test_token')
        mock_post.assert_called_once()
    
    @patch('requests.post')
    def test_authenticate_ozon_api_failure(self, mock_post):
        """Тест неудачной аутентификации в Ozon API"""
        # Настраиваем мок неудачного ответа
        mock_response = Mock()
        mock_response.status_code = 401
        mock_post.return_value = mock_response
        
        updater = OzonWeeklyUpdater('test_config.py')
        token = updater.authenticate_ozon_api('test_client', 'test_key')
        
        self.assertIsNone(token)


class TestOzonUpdateMonitor(unittest.TestCase):
    """Тесты для класса OzonUpdateMonitor"""
    
    def setUp(self):
        """Настройка тестового окружения"""
        self.test_dir = tempfile.mkdtemp()
        self.original_dir = os.getcwd()
        os.chdir(self.test_dir)
        
        # Создаем тестовый конфигурационный файл
        config_content = """
DB_HOST = 'localhost'
DB_USER = 'test_user'
DB_PASSWORD = 'test_password'
DB_NAME = 'test_db'
DB_PORT = 3306
LOG_DIR = 'logs'
"""
        
        with open('test_config.py', 'w') as f:
            f.write(config_content)
        
        # Создаем директорию для логов и тестовые логи
        os.makedirs('logs', exist_ok=True)
        self._create_test_logs()
    
    def tearDown(self):
        """Очистка после тестов"""
        os.chdir(self.original_dir)
        shutil.rmtree(self.test_dir)
    
    def _create_test_logs(self):
        """Создание тестовых файлов логов"""
        # Успешный лог
        success_log = """
2024-01-15 02:00:00 - ozon_weekly_updater - INFO - === Начало еженедельного обновления данных Ozon ===
2024-01-15 02:01:00 - ozon_weekly_updater - INFO - Успешное подключение к базе данных
2024-01-15 02:02:00 - ozon_weekly_updater - INFO - Успешная аутентификация в Ozon API
2024-01-15 02:05:00 - ozon_weekly_updater - INFO - Сохранено 150 записей воронки
2024-01-15 02:07:00 - ozon_weekly_updater - INFO - Сохранено 75 демографических записей
2024-01-15 02:10:00 - ozon_weekly_updater - INFO - === Еженедельное обновление данных Ozon завершено успешно ===
"""
        
        with open('logs/ozon_update_20240115_020000.log', 'w') as f:
            f.write(success_log)
        
        # Лог с ошибками
        error_log = """
2024-01-14 02:00:00 - ozon_weekly_updater - INFO - === Начало еженедельного обновления данных Ozon ===
2024-01-14 02:01:00 - ozon_weekly_updater - ERROR - Ошибка подключения к базе данных
2024-01-14 02:01:30 - ozon_weekly_updater - WARNING - Повторная попытка подключения
2024-01-14 02:02:00 - ozon_weekly_updater - ERROR - Критическая ошибка при обновлении
2024-01-14 02:02:30 - ozon_weekly_updater - INFO - === Еженедельное обновление данных Ozon завершено с ошибками ===
"""
        
        with open('logs/ozon_update_20240114_020000.log', 'w') as f:
            f.write(error_log)
    
    def test_config_loading(self):
        """Тест загрузки конфигурации монитора"""
        monitor = OzonUpdateMonitor('test_config.py')
        
        self.assertEqual(monitor.config['database']['host'], 'localhost')
        self.assertEqual(monitor.config['monitoring']['log_dir'], 'logs')
    
    def test_analyze_logs(self):
        """Тест анализа логов"""
        monitor = OzonUpdateMonitor('test_config.py')
        analysis = monitor.analyze_logs(days=7)
        
        self.assertIsInstance(analysis, dict)
        self.assertIn('total_runs', analysis)
        self.assertIn('successful_runs', analysis)
        self.assertIn('failed_runs', analysis)
        self.assertIn('warnings_count', analysis)
        self.assertIn('errors_count', analysis)
        
        # Проверяем, что найдены наши тестовые логи
        self.assertEqual(analysis['total_runs'], 2)
        self.assertEqual(analysis['successful_runs'], 1)
        self.assertEqual(analysis['failed_runs'], 1)
        self.assertGreater(analysis['errors_count'], 0)
        self.assertGreater(analysis['warnings_count'], 0)
    
    def test_generate_report_text(self):
        """Тест генерации текстового отчета"""
        monitor = OzonUpdateMonitor('test_config.py')
        
        with patch.object(monitor, 'get_last_update_status') as mock_status:
            mock_status.return_value = {
                'funnel_data': {'last_update': datetime.now(), 'records_count': 100},
                'demographics': {'last_update': datetime.now(), 'records_count': 50},
                'campaigns': {'last_update': None, 'records_count': 0},
                'overall_status': 'warning'
            }
            
            report = monitor.generate_report(format='text')
            
            self.assertIsInstance(report, str)
            self.assertIn('ОТЧЕТ О СОСТОЯНИИ СИСТЕМЫ', report)
            self.assertIn('ОБЩИЙ СТАТУС', report)
            self.assertIn('АНАЛИЗ ЛОГОВ', report)
    
    def test_generate_report_json(self):
        """Тест генерации JSON отчета"""
        monitor = OzonUpdateMonitor('test_config.py')
        
        with patch.object(monitor, 'get_last_update_status') as mock_status:
            mock_status.return_value = {
                'overall_status': 'healthy'
            }
            
            report = monitor.generate_report(format='json')
            
            self.assertIsInstance(report, str)
            # Проверяем, что это валидный JSON
            import json
            data = json.loads(report)
            self.assertIn('timestamp', data)
            self.assertIn('status', data)
            self.assertIn('log_analysis', data)


class TestSystemIntegration(unittest.TestCase):
    """Интеграционные тесты системы"""
    
    def setUp(self):
        """Настройка тестового окружения"""
        self.test_dir = tempfile.mkdtemp()
        self.original_dir = os.getcwd()
        os.chdir(self.test_dir)
        
        # Создаем минимальную конфигурацию
        config_content = """
DB_HOST = 'localhost'
DB_USER = 'test'
DB_PASSWORD = 'test'
DB_NAME = 'test'
OZON_CLIENT_ID = 'test'
OZON_API_KEY = 'test'
ADMIN_EMAILS = ['test@example.com']
LOOKBACK_DAYS = 7
"""
        
        with open('test_config.py', 'w') as f:
            f.write(config_content)
        
        os.makedirs('logs', exist_ok=True)
    
    def tearDown(self):
        """Очистка после тестов"""
        os.chdir(self.original_dir)
        shutil.rmtree(self.test_dir)
    
    def test_updater_and_monitor_integration(self):
        """Тест интеграции обновлятора и монитора"""
        # Создаем экземпляры
        updater = OzonWeeklyUpdater('test_config.py')
        monitor = OzonUpdateMonitor('test_config.py')
        
        # Проверяем, что оба используют одинаковую конфигурацию
        self.assertEqual(
            updater.config['database']['host'],
            monitor.config['database']['host']
        )
        
        # Проверяем, что логгеры настроены
        self.assertIsNotNone(updater.logger)
        self.assertIsNotNone(monitor.logger)


def run_tests():
    """Запуск всех тестов"""
    print("=" * 60)
    print("ЗАПУСК ТЕСТОВ СИСТЕМЫ OZON ANALYTICS WEEKLY UPDATE")
    print("=" * 60)
    
    # Создаем test suite
    loader = unittest.TestLoader()
    suite = unittest.TestSuite()
    
    # Добавляем тесты
    suite.addTests(loader.loadTestsFromTestCase(TestOzonWeeklyUpdater))
    suite.addTests(loader.loadTestsFromTestCase(TestOzonUpdateMonitor))
    suite.addTests(loader.loadTestsFromTestCase(TestSystemIntegration))
    
    # Запускаем тесты
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(suite)
    
    # Выводим результаты
    print("\n" + "=" * 60)
    print("РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ")
    print("=" * 60)
    print(f"Всего тестов: {result.testsRun}")
    print(f"Успешных: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"Неудачных: {len(result.failures)}")
    print(f"Ошибок: {len(result.errors)}")
    
    if result.failures:
        print("\nНЕУДАЧНЫЕ ТЕСТЫ:")
        for test, traceback in result.failures:
            print(f"  - {test}")
    
    if result.errors:
        print("\nОШИБКИ В ТЕСТАХ:")
        for test, traceback in result.errors:
            print(f"  - {test}")
    
    success = len(result.failures) == 0 and len(result.errors) == 0
    
    if success:
        print("\n✅ ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО!")
    else:
        print("\n❌ ОБНАРУЖЕНЫ ПРОБЛЕМЫ В ТЕСТАХ")
    
    return success


if __name__ == "__main__":
    success = run_tests()
    sys.exit(0 if success else 1)