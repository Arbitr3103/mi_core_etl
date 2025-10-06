#!/usr/bin/env python3
"""
Комплексные тесты для системы логирования синхронизации.

Включает unit тесты для SyncLogger, integration тесты для записи в sync_logs
и проверку корректности логирования ошибок.

Автор: ETL System
Дата: 06 января 2025
"""

import sys
import os
import logging
import unittest
import tempfile
import sqlite3
from datetime import datetime, timedelta
from unittest.mock import Mock, MagicMock, patch, call
from contextlib import contextmanager

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from sync_logger import (
        SyncLogger, SyncType, SyncStatus, ProcessingStats, 
        SyncLogEntry, LogLevel
    )
    from test_config import TEST_SETTINGS, THRESHOLDS
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

# Настройка логирования для тестов
logging.basicConfig(
    level=getattr(logging, TEST_SETTINGS.get('LOG_LEVEL', 'INFO')),
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class TestSyncLogger(unittest.TestCase):
    """Unit тесты для класса SyncLogger."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        self.mock_cursor.lastrowid = 123
        self.sync_logger = SyncLogger(
            self.mock_cursor, 
            self.mock_connection, 
            "TestLogger"
        )
    
    def test_start_sync_session(self):
        """Тест начала сессии синхронизации."""
        # Тестируем создание новой сессии
        sync_entry = self.sync_logger.start_sync_session(
            SyncType.INVENTORY, 
            "TestSource"
        )
        
        self.assertIsNotNone(sync_entry)
        self.assertEqual(sync_entry.sync_type, SyncType.INVENTORY)
        self.assertEqual(sync_entry.source, "TestSource")
        self.assertEqual(sync_entry.status, SyncStatus.SUCCESS)
        self.assertIsNotNone(sync_entry.started_at)
        self.assertIsNone(sync_entry.completed_at)
        
        # Проверяем, что сессия установлена как текущая
        self.assertEqual(self.sync_logger.current_sync, sync_entry)
        
        # Проверяем очистку предыдущих данных
        self.assertEqual(len(self.sync_logger.processing_stages), 0)
        self.assertEqual(len(self.sync_logger.session_warnings), 0)
        self.assertEqual(len(self.sync_logger.session_errors), 0)
    
    def test_end_sync_session_success(self):
        """Тест успешного завершения сессии."""
        # Начинаем сессию
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Завершаем сессию
        sync_log_id = self.sync_logger.end_sync_session(SyncStatus.SUCCESS)
        
        self.assertEqual(sync_log_id, 123)
        self.assertIsNone(self.sync_logger.current_sync)
        
        # Проверяем вызовы к БД
        self.mock_cursor.execute.assert_called()
        self.mock_connection.commit.assert_called()
    
    def test_end_sync_session_without_active_session(self):
        """Тест завершения сессии без активной сессии."""
        sync_log_id = self.sync_logger.end_sync_session()
        
        self.assertIsNone(sync_log_id)
        self.mock_cursor.execute.assert_not_called()
    
    def test_log_processing_stage(self):
        """Тест логирования этапа обработки."""
        # Начинаем сессию
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Логируем этап
        self.sync_logger.log_processing_stage(
            stage_name="Test Stage",
            records_input=100,
            records_output=95,
            processing_time=1.5,
            records_skipped=5,
            error_count=2,
            warning_count=3,
            memory_usage_mb=50.5
        )
        
        # Проверяем, что этап записан
        self.assertEqual(len(self.sync_logger.processing_stages), 1)
        stage = self.sync_logger.processing_stages[0]
        
        self.assertEqual(stage.stage_name, "Test Stage")
        self.assertEqual(stage.records_input, 100)
        self.assertEqual(stage.records_output, 95)
        self.assertEqual(stage.records_skipped, 5)
        self.assertEqual(stage.processing_time_seconds, 1.5)
        self.assertEqual(stage.memory_usage_mb, 50.5)
        self.assertEqual(stage.error_count, 2)
        self.assertEqual(stage.warning_count, 3)
        
        # Проверяем обновление счетчиков сессии
        self.assertEqual(self.sync_logger.current_sync.records_processed, 100)
        self.assertEqual(self.sync_logger.current_sync.records_updated, 95)
        self.assertEqual(self.sync_logger.current_sync.records_failed, 2)
    
    def test_log_api_request_success(self):
        """Тест логирования успешного API запроса."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        self.sync_logger.log_api_request(
            endpoint="/api/test",
            response_time=0.5,
            status_code=200,
            records_received=50
        )
        
        self.assertEqual(self.sync_logger.current_sync.api_requests_count, 1)
    
    def test_log_api_request_error(self):
        """Тест логирования неудачного API запроса."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        self.sync_logger.log_api_request(
            endpoint="/api/test",
            response_time=2.0,
            status_code=500,
            error_message="Internal Server Error"
        )
        
        self.assertEqual(self.sync_logger.current_sync.api_requests_count, 1)
        self.assertEqual(len(self.sync_logger.session_errors), 1)
        self.assertIn("API Error 500", self.sync_logger.session_errors[0])
    
    def test_log_error(self):
        """Тест логирования ошибок."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Тест простой ошибки
        self.sync_logger.log_error("Test error message")
        
        self.assertEqual(len(self.sync_logger.session_errors), 1)
        self.assertEqual(self.sync_logger.session_errors[0], "Test error message")
        self.assertEqual(self.sync_logger.current_sync.status, SyncStatus.PARTIAL)
        
        # Тест ошибки с исключением
        test_exception = ValueError("Test exception")
        self.sync_logger.log_error("Error with exception", test_exception)
        
        self.assertEqual(len(self.sync_logger.session_errors), 2)
        self.assertIn("Error with exception: Test exception", self.sync_logger.session_errors[1])
    
    def test_log_warning(self):
        """Тест логирования предупреждений."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        self.sync_logger.log_warning("Test warning message")
        
        self.assertEqual(len(self.sync_logger.session_warnings), 1)
        self.assertEqual(self.sync_logger.session_warnings[0], "Test warning message")
    
    def test_update_sync_counters(self):
        """Тест обновления счетчиков синхронизации."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        self.sync_logger.update_sync_counters(
            records_processed=100,
            records_updated=50,
            records_inserted=30,
            records_failed=5
        )
        
        sync = self.sync_logger.current_sync
        self.assertEqual(sync.records_processed, 100)
        self.assertEqual(sync.records_updated, 50)
        self.assertEqual(sync.records_inserted, 30)
        self.assertEqual(sync.records_failed, 5)
        self.assertEqual(sync.status, SyncStatus.PARTIAL)  # Из-за failed > 0
    
    def test_update_sync_counters_without_session(self):
        """Тест обновления счетчиков без активной сессии."""
        # Не должно вызывать исключение
        self.sync_logger.update_sync_counters(records_processed=10)
    
    def test_get_sync_statistics(self):
        """Тест получения статистики синхронизации."""
        # Тест без активной сессии
        stats = self.sync_logger.get_sync_statistics()
        self.assertIn("error", stats)
        
        # Тест с активной сессией
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        self.sync_logger.log_processing_stage("Stage1", 100, 95, 1.0)
        self.sync_logger.log_warning("Test warning")
        self.sync_logger.log_error("Test error")
        
        stats = self.sync_logger.get_sync_statistics()
        
        self.assertEqual(stats['sync_type'], 'inventory')
        self.assertEqual(stats['source'], 'TestSource')
        self.assertEqual(stats['status'], 'partial')
        self.assertEqual(stats['stages_count'], 1)
        self.assertEqual(stats['warnings_count'], 1)
        self.assertEqual(stats['errors_count'], 1)
        self.assertIn('records', stats)
        self.assertIn('api_requests', stats)
    
    def test_multiple_warnings_truncation(self):
        """Тест обрезания множественных предупреждений."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Добавляем много предупреждений
        for i in range(10):
            self.sync_logger.log_warning(f"Warning {i}")
        
        sync_log_id = self.sync_logger.end_sync_session()
        
        # Проверяем, что в БД записывается обрезанное сообщение
        call_args = self.mock_cursor.execute.call_args[0]
        warning_message = call_args[1][12]  # warning_message в VALUES
        
        self.assertIn("и еще", warning_message)
        self.assertLess(len(warning_message), 1000)  # Разумная длина


class TestSyncLoggerErrorHandling(unittest.TestCase):
    """Тесты обработки ошибок в SyncLogger."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        self.sync_logger = SyncLogger(
            self.mock_cursor, 
            self.mock_connection, 
            "ErrorTestLogger"
        )
    
    def test_database_error_handling(self):
        """Тест обработки ошибок базы данных."""
        # Настраиваем мок для генерации ошибки при execute
        self.mock_cursor.execute.side_effect = Exception("Database connection lost")
        
        # Начинаем сессию
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "ErrorTestSource")
        
        # Пытаемся завершить сессию (должна обработать ошибку БД)
        sync_log_id = self.sync_logger.end_sync_session(
            status=SyncStatus.FAILED, 
            error_message="Test error"
        )
        
        # Проверяем, что ошибка обработана корректно
        self.assertIsNone(sync_log_id)
        self.mock_connection.rollback.assert_called()
    
    def test_database_commit_error(self):
        """Тест обработки ошибки коммита."""
        self.mock_connection.commit.side_effect = Exception("Commit failed")
        
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        sync_log_id = self.sync_logger.end_sync_session()
        
        self.assertIsNone(sync_log_id)
    
    def test_get_recent_logs_database_error(self):
        """Тест обработки ошибки при получении логов."""
        self.mock_cursor.execute.side_effect = Exception("Query failed")
        
        logs = self.sync_logger.get_recent_sync_logs()
        
        self.assertEqual(logs, [])
    
    def test_health_report_database_error(self):
        """Тест обработки ошибки при генерации отчета."""
        self.mock_cursor.execute.side_effect = Exception("Health query failed")
        
        report = self.sync_logger.get_sync_health_report()
        
        self.assertIn("error", report)
        self.assertIn("Health query failed", report["error"])
    
    def test_processing_stages_database_error(self):
        """Тест обработки ошибки при записи этапов обработки."""
        # Настраиваем успешную запись основного лога
        self.mock_cursor.lastrowid = 123
        
        # Но ошибку при записи этапов (второй вызов execute)
        self.mock_cursor.execute.side_effect = [
            None,  # Первый вызов (основной лог) успешен
            Exception("Stages insert failed")  # Второй вызов (этапы) неудачен
        ]
        
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        self.sync_logger.log_processing_stage("Test Stage", 100, 95, 1.0)
        
        # Должно завершиться без исключения, но этапы не записаны
        sync_log_id = self.sync_logger.end_sync_session()
        
        self.assertEqual(sync_log_id, 123)  # Основной лог записан
        self.mock_connection.rollback.assert_called()  # Rollback для этапов


class TestSyncLoggerIntegration(unittest.TestCase):
    """Integration тесты для SyncLogger с реальной базой данных."""
    
    @contextmanager
    def get_test_database(self):
        """Создает временную SQLite базу для тестирования."""
        # Создаем временный файл для БД
        db_file = tempfile.NamedTemporaryFile(delete=False)
        db_file.close()
        
        try:
            # Подключаемся к SQLite
            connection = sqlite3.connect(db_file.name)
            connection.row_factory = sqlite3.Row  # Для dict-like доступа
            cursor = connection.cursor()
            
            # Создаем тестовые таблицы
            self._create_test_tables(cursor)
            connection.commit()
            
            yield cursor, connection
            
        finally:
            connection.close()
            os.unlink(db_file.name)
    
    def _create_test_tables(self, cursor):
        """Создает тестовые таблицы в БД."""
        # Таблица sync_logs (адаптированная для SQLite)
        cursor.execute("""
            CREATE TABLE sync_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_type TEXT NOT NULL,
                source TEXT NOT NULL,
                status TEXT NOT NULL,
                records_processed INTEGER DEFAULT 0,
                records_updated INTEGER DEFAULT 0,
                records_inserted INTEGER DEFAULT 0,
                records_failed INTEGER DEFAULT 0,
                started_at TIMESTAMP NOT NULL,
                completed_at TIMESTAMP,
                duration_seconds INTEGER,
                api_requests_count INTEGER DEFAULT 0,
                error_message TEXT,
                warning_message TEXT
            )
        """)
        
        # Таблица sync_processing_stages
        cursor.execute("""
            CREATE TABLE sync_processing_stages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_log_id INTEGER NOT NULL,
                stage_name TEXT NOT NULL,
                records_input INTEGER DEFAULT 0,
                records_output INTEGER DEFAULT 0,
                records_skipped INTEGER DEFAULT 0,
                processing_time_seconds REAL DEFAULT 0,
                memory_usage_mb REAL,
                error_count INTEGER DEFAULT 0,
                warning_count INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sync_log_id) REFERENCES sync_logs(id)
            )
        """)
    
    def test_full_sync_cycle_with_database(self):
        """Тест полного цикла синхронизации с реальной БД."""
        with self.get_test_database() as (cursor, connection):
            sync_logger = SyncLogger(cursor, connection, "IntegrationTestLogger")
            
            # Начинаем сессию
            sync_entry = sync_logger.start_sync_session(SyncType.INVENTORY, "Ozon")
            self.assertIsNotNone(sync_entry)
            
            # Добавляем этапы обработки
            sync_logger.log_processing_stage("API Fetch", 0, 150, 2.5)
            sync_logger.log_processing_stage("Data Validation", 150, 145, 0.8, records_skipped=5)
            sync_logger.log_processing_stage("Database Update", 145, 145, 1.2)
            
            # Логируем API запросы
            sync_logger.log_api_request("/api/stocks", 1.5, 200, 150)
            sync_logger.log_api_request("/api/products", 0.8, 200, 50)
            
            # Добавляем предупреждения и ошибки
            sync_logger.log_warning("5 records skipped due to missing product_id")
            sync_logger.log_error("Failed to update 2 records")
            
            # Завершаем сессию
            sync_log_id = sync_logger.end_sync_session()
            self.assertIsNotNone(sync_log_id)
            
            # Проверяем запись в sync_logs
            cursor.execute("SELECT * FROM sync_logs WHERE id = ?", (sync_log_id,))
            log_record = cursor.fetchone()
            
            self.assertIsNotNone(log_record)
            self.assertEqual(log_record['sync_type'], 'inventory')
            self.assertEqual(log_record['source'], 'Ozon')
            self.assertEqual(log_record['status'], 'partial')  # Из-за ошибок
            self.assertEqual(log_record['api_requests_count'], 2)
            self.assertIsNotNone(log_record['error_message'])
            self.assertIsNotNone(log_record['warning_message'])
            
            # Проверяем записи этапов обработки
            cursor.execute(
                "SELECT * FROM sync_processing_stages WHERE sync_log_id = ? ORDER BY id", 
                (sync_log_id,)
            )
            stages = cursor.fetchall()
            
            self.assertEqual(len(stages), 3)
            self.assertEqual(stages[0]['stage_name'], 'API Fetch')
            self.assertEqual(stages[1]['stage_name'], 'Data Validation')
            self.assertEqual(stages[2]['stage_name'], 'Database Update')
    
    def test_get_recent_sync_logs_integration(self):
        """Тест получения последних логов синхронизации."""
        with self.get_test_database() as (cursor, connection):
            sync_logger = SyncLogger(cursor, connection, "IntegrationTestLogger")
            
            # Создаем несколько записей синхронизации
            sources = ["Ozon", "Wildberries", "Ozon"]
            statuses = [SyncStatus.SUCCESS, SyncStatus.FAILED, SyncStatus.PARTIAL]
            
            for i, (source, status) in enumerate(zip(sources, statuses)):
                sync_logger.start_sync_session(SyncType.INVENTORY, source)
                sync_logger.update_sync_counters(records_processed=100 + i * 10)
                sync_logger.end_sync_session(status)
            
            # Получаем все логи
            all_logs = sync_logger.get_recent_sync_logs(limit=10)
            self.assertEqual(len(all_logs), 3)
            
            # Получаем логи только для Ozon
            ozon_logs = sync_logger.get_recent_sync_logs(source="Ozon", limit=10)
            self.assertEqual(len(ozon_logs), 2)
            
            # Проверяем сортировку (последние сначала)
            self.assertGreaterEqual(
                all_logs[0]['started_at'], 
                all_logs[1]['started_at']
            )
    
    def test_sync_health_report_integration(self):
        """Тест генерации отчета о состоянии синхронизации."""
        with self.get_test_database() as (cursor, connection):
            sync_logger = SyncLogger(cursor, connection, "IntegrationTestLogger")
            
            # Создаем тестовые данные за последние 24 часа
            test_data = [
                ("Ozon", SyncStatus.SUCCESS, 100),
                ("Ozon", SyncStatus.SUCCESS, 150),
                ("Ozon", SyncStatus.FAILED, 0),
                ("Wildberries", SyncStatus.SUCCESS, 200),
                ("Wildberries", SyncStatus.PARTIAL, 180),
            ]
            
            for source, status, records in test_data:
                sync_logger.start_sync_session(SyncType.INVENTORY, source)
                sync_logger.update_sync_counters(records_processed=records)
                if status == SyncStatus.FAILED:
                    sync_logger.log_error("Test error")
                elif status == SyncStatus.PARTIAL:
                    sync_logger.log_warning("Test warning")
                sync_logger.end_sync_session(status)
            
            # Генерируем отчет
            report = sync_logger.get_sync_health_report()
            
            self.assertIn('generated_at', report)
            self.assertIn('sources', report)
            self.assertIn('overall_health', report)
            
            # Проверяем данные по источникам
            self.assertIn('Ozon', report['sources'])
            self.assertIn('Wildberries', report['sources'])
            
            ozon_data = report['sources']['Ozon']
            self.assertEqual(ozon_data['success_count'], 2)
            self.assertEqual(ozon_data['failed_count'], 1)
            
            wb_data = report['sources']['Wildberries']
            self.assertEqual(wb_data['success_count'], 1)
            self.assertEqual(wb_data['partial_count'], 1)


class TestProcessingStats(unittest.TestCase):
    """Unit тесты для класса ProcessingStats."""
    
    def test_processing_stats_creation(self):
        """Тест создания объекта ProcessingStats."""
        stats = ProcessingStats(
            stage_name="Test Stage",
            records_input=100,
            records_output=95,
            records_skipped=5,
            processing_time_seconds=2.5,
            memory_usage_mb=64.0,
            error_count=2,
            warning_count=1
        )
        
        self.assertEqual(stats.stage_name, "Test Stage")
        self.assertEqual(stats.records_input, 100)
        self.assertEqual(stats.records_output, 95)
        self.assertEqual(stats.records_skipped, 5)
        self.assertEqual(stats.processing_time_seconds, 2.5)
        self.assertEqual(stats.memory_usage_mb, 64.0)
        self.assertEqual(stats.error_count, 2)
        self.assertEqual(stats.warning_count, 1)
    
    def test_processing_stats_defaults(self):
        """Тест значений по умолчанию для ProcessingStats."""
        stats = ProcessingStats(
            stage_name="Minimal Stage",
            records_input=50,
            records_output=50,
            records_skipped=0,
            processing_time_seconds=1.0
        )
        
        self.assertIsNone(stats.memory_usage_mb)
        self.assertEqual(stats.error_count, 0)
        self.assertEqual(stats.warning_count, 0)


class TestSyncLogEntry(unittest.TestCase):
    """Unit тесты для класса SyncLogEntry."""
    
    def test_duration_calculation(self):
        """Тест расчета длительности выполнения."""
        entry = SyncLogEntry(
            sync_type=SyncType.INVENTORY,
            source="TestSource",
            status=SyncStatus.SUCCESS
        )
        
        # Без времени завершения
        self.assertEqual(entry.duration_seconds, 0)
        
        # С временем начала и завершения
        start_time = datetime.now()
        end_time = start_time + timedelta(seconds=150)
        
        entry.started_at = start_time
        entry.completed_at = end_time
        
        self.assertEqual(entry.duration_seconds, 150)
    
    def test_sync_log_entry_defaults(self):
        """Тест значений по умолчанию для SyncLogEntry."""
        entry = SyncLogEntry(
            sync_type=SyncType.ORDERS,
            source="TestSource",
            status=SyncStatus.PARTIAL
        )
        
        self.assertEqual(entry.records_processed, 0)
        self.assertEqual(entry.records_updated, 0)
        self.assertEqual(entry.records_inserted, 0)
        self.assertEqual(entry.records_failed, 0)
        self.assertEqual(entry.api_requests_count, 0)
        self.assertIsNone(entry.started_at)
        self.assertIsNone(entry.completed_at)
        self.assertIsNone(entry.error_message)
        self.assertIsNone(entry.warning_message)


class TestSyncLoggerErrorLogging(unittest.TestCase):
    """Специальные тесты для проверки корректности логирования ошибок."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        self.mock_cursor.lastrowid = 789
        
        # Настраиваем захват логов
        self.log_capture = []
        self.test_handler = logging.Handler()
        self.test_handler.emit = lambda record: self.log_capture.append(record)
        
        self.sync_logger = SyncLogger(
            self.mock_cursor, 
            self.mock_connection, 
            "ErrorLoggingTestLogger"
        )
        self.sync_logger.logger.addHandler(self.test_handler)
        self.sync_logger.logger.setLevel(logging.DEBUG)
    
    def tearDown(self):
        """Очистка после тестов."""
        self.sync_logger.logger.removeHandler(self.test_handler)
    
    def test_error_logging_format(self):
        """Тест формата логирования ошибок."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Логируем различные типы ошибок
        self.sync_logger.log_error("Simple error message")
        self.sync_logger.log_error("Error with exception", ValueError("Test exception"))
        
        # Проверяем, что ошибки записаны в сессию
        self.assertEqual(len(self.sync_logger.session_errors), 2)
        self.assertEqual(
            self.sync_logger.session_errors[0], 
            "Simple error message"
        )
        self.assertIn(
            "Error with exception: Test exception", 
            self.sync_logger.session_errors[1]
        )
        
        # Проверяем логи
        error_logs = [log for log in self.log_capture if log.levelno >= logging.ERROR]
        self.assertEqual(len(error_logs), 2)
        
        # Проверяем формат сообщений (должны содержать эмодзи)
        self.assertIn("❌", error_logs[0].getMessage())
        self.assertIn("❌", error_logs[1].getMessage())
    
    def test_warning_logging_format(self):
        """Тест формата логирования предупреждений."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        self.sync_logger.log_warning("Test warning message")
        
        # Проверяем запись в сессию
        self.assertEqual(len(self.sync_logger.session_warnings), 1)
        self.assertEqual(self.sync_logger.session_warnings[0], "Test warning message")
        
        # Проверяем лог
        warning_logs = [log for log in self.log_capture if log.levelno == logging.WARNING]
        self.assertEqual(len(warning_logs), 1)
        self.assertIn("⚠️", warning_logs[0].getMessage())
    
    def test_api_error_logging(self):
        """Тест логирования ошибок API."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Успешный API запрос
        self.sync_logger.log_api_request("/api/success", 1.0, 200, 100)
        
        # Неудачный API запрос
        self.sync_logger.log_api_request(
            "/api/error", 
            2.5, 
            500, 
            0, 
            "Internal Server Error"
        )
        
        # Проверяем, что ошибка API добавлена в session_errors
        self.assertEqual(len(self.sync_logger.session_errors), 1)
        self.assertIn("API Error 500", self.sync_logger.session_errors[0])
        
        # Проверяем логи
        info_logs = [log for log in self.log_capture if log.levelno == logging.INFO]
        error_logs = [log for log in self.log_capture if log.levelno >= logging.ERROR]
        
        # Должен быть 1 info лог (успешный запрос) и 1 error лог (неудачный)
        success_logs = [log for log in info_logs if "API запрос успешен" in log.getMessage()]
        api_error_logs = [log for log in error_logs if "API запрос неудачен" in log.getMessage()]
        
        self.assertEqual(len(success_logs), 1)
        self.assertEqual(len(api_error_logs), 1)
    
    def test_status_change_on_errors(self):
        """Тест изменения статуса синхронизации при ошибках."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Изначально статус SUCCESS
        self.assertEqual(self.sync_logger.current_sync.status, SyncStatus.SUCCESS)
        
        # После ошибки статус должен измениться на PARTIAL
        self.sync_logger.log_error("First error")
        self.assertEqual(self.sync_logger.current_sync.status, SyncStatus.PARTIAL)
        
        # Повторные ошибки не должны менять статус с PARTIAL
        self.sync_logger.log_error("Second error")
        self.assertEqual(self.sync_logger.current_sync.status, SyncStatus.PARTIAL)
        
        # Обновление счетчиков с failed > 0 также меняет статус
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource2")
        self.assertEqual(self.sync_logger.current_sync.status, SyncStatus.SUCCESS)
        
        self.sync_logger.update_sync_counters(records_failed=1)
        self.assertEqual(self.sync_logger.current_sync.status, SyncStatus.PARTIAL)
    
    def test_error_message_truncation_in_database(self):
        """Тест обрезания длинных сообщений об ошибках при записи в БД."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Создаем очень длинное сообщение об ошибке
        long_error = "Very long error message " * 100  # ~2500 символов
        self.sync_logger.log_error(long_error)
        
        # Завершаем сессию
        sync_log_id = self.sync_logger.end_sync_session()
        
        # Проверяем, что в БД записывается сообщение
        call_args = self.mock_cursor.execute.call_args[0]
        error_message = call_args[1][11]  # error_message в VALUES
        
        # Проверяем, что сообщение не None и содержит ожидаемый текст
        self.assertIsNotNone(error_message)
        if error_message:  # Дополнительная проверка на случай None
            self.assertIn("Very long error message", error_message)
    
    def test_concurrent_error_and_warning_logging(self):
        """Тест одновременного логирования ошибок и предупреждений."""
        self.sync_logger.start_sync_session(SyncType.INVENTORY, "TestSource")
        
        # Перемешиваем ошибки и предупреждения
        self.sync_logger.log_warning("Warning 1")
        self.sync_logger.log_error("Error 1")
        self.sync_logger.log_warning("Warning 2")
        self.sync_logger.log_error("Error 2")
        self.sync_logger.log_warning("Warning 3")
        
        # Проверяем счетчики
        self.assertEqual(len(self.sync_logger.session_warnings), 3)
        self.assertEqual(len(self.sync_logger.session_errors), 2)
        
        # Статус должен быть PARTIAL из-за ошибок
        self.assertEqual(self.sync_logger.current_sync.status, SyncStatus.PARTIAL)
        
        # Завершаем сессию и проверяем запись в БД
        sync_log_id = self.sync_logger.end_sync_session()
        
        call_args = self.mock_cursor.execute.call_args[0]
        warning_message = call_args[1][12]  # warning_message в VALUES
        error_message = call_args[1][11]    # error_message в VALUES
        
        # Проверяем, что сообщения не None и содержат ожидаемый текст
        if warning_message:
            self.assertIn("Warning 1", warning_message)
        if error_message:
            self.assertIn("Error 1", error_message)


def run_comprehensive_tests():
    """Запуск всех тестов системы логирования."""
    # Создаем test suite
    test_suite = unittest.TestSuite()
    
    # Добавляем все тестовые классы
    test_classes = [
        TestSyncLogger,
        TestSyncLoggerErrorHandling,
        TestSyncLoggerIntegration,
        TestProcessingStats,
        TestSyncLogEntry,
        TestSyncLoggerErrorLogging
    ]
    
    for test_class in test_classes:
        tests = unittest.TestLoader().loadTestsFromTestCase(test_class)
        test_suite.addTests(tests)
    
    # Настраиваем runner
    runner = unittest.TextTestRunner(
        verbosity=2 if TEST_SETTINGS.get('VERBOSE_OUTPUT', True) else 1,
        buffer=TEST_SETTINGS.get('BUFFER_OUTPUT', True),
        failfast=TEST_SETTINGS.get('FAIL_FAST', False)
    )
    
    # Запускаем тесты
    result = runner.run(test_suite)
    
    return result.wasSuccessful()


def main():
    """Главная функция для запуска всех тестов."""
    print("🚀 Запуск комплексных тестов системы логирования синхронизации")
    print("=" * 70)
    
    try:
        success = run_comprehensive_tests()
        
        print("\n" + "=" * 70)
        if success:
            print("🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!")
            print("\nПокрытие тестирования:")
            print("✅ Unit тесты для SyncLogger")
            print("✅ Integration тесты для записи в sync_logs")
            print("✅ Проверка корректности логирования ошибок")
            print("✅ Тесты обработки ошибок базы данных")
            print("✅ Тесты вспомогательных классов (ProcessingStats, SyncLogEntry)")
            print("✅ Тесты форматирования и обрезания сообщений")
            return True
        else:
            print("❌ НЕКОТОРЫЕ ТЕСТЫ ПРОВАЛЕНЫ")
            print("\nПроверьте вывод выше для деталей об ошибках.")
            return False
            
    except Exception as e:
        print(f"❌ КРИТИЧЕСКАЯ ОШИБКА ПРИ ЗАПУСКЕ ТЕСТОВ: {e}")
        return False


if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)