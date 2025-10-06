#!/usr/bin/env python3
"""
Unit тесты для AlertManager - системы уведомлений и алертов.

Тестирует:
- Отправку различных типов уведомлений
- Работу с email и Telegram каналами
- Логирование алертов в базу данных
- Систему cooldown для предотвращения спама
- Форматирование сообщений

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
from unittest.mock import Mock, MagicMock, patch
from datetime import datetime, timedelta
import sys
import os
import json

# Добавляем текущую директорию в путь для импорта
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from alert_manager import (
    AlertManager, Alert, AlertLevel, NotificationType, 
    NotificationConfig
)


class TestAlertManager(unittest.TestCase):
    """Тесты для класса AlertManager."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        # Создаем mock объекты для БД
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        
        # Создаем тестовую конфигурацию
        self.test_config = NotificationConfig(
            email_enabled=True,
            telegram_enabled=True,
            smtp_server='smtp.test.com',
            smtp_port=587,
            email_user='test@example.com',
            email_password='test_password',
            recipients=['admin@example.com', 'dev@example.com'],
            telegram_bot_token='test_bot_token',
            telegram_chat_id='test_chat_id'
        )
        
        # Создаем экземпляр AlertManager
        self.alert_manager = AlertManager(
            db_cursor=self.mock_cursor,
            db_connection=self.mock_connection,
            logger_name="TestAlertManager"
        )
        
        # Подменяем конфигурацию на тестовую
        self.alert_manager.config = self.test_config
        
        # Настраиваем mock для определения типа БД
        self.mock_cursor.lastrowid = 1  # Имитируем SQLite
    
    def test_init(self):
        """Тест инициализации AlertManager."""
        self.assertEqual(self.alert_manager.cursor, self.mock_cursor)
        self.assertEqual(self.alert_manager.connection, self.mock_connection)
        self.assertIsInstance(self.alert_manager.config, NotificationConfig)
        self.assertIsInstance(self.alert_manager.sent_alerts, dict)
        self.assertIsInstance(self.alert_manager.alert_cooldowns, dict)
    
    def test_notification_config_creation(self):
        """Тест создания конфигурации уведомлений."""
        config = NotificationConfig(
            email_enabled=True,
            recipients=['test@example.com']
        )
        
        self.assertTrue(config.email_enabled)
        self.assertFalse(config.telegram_enabled)
        self.assertEqual(config.recipients, ['test@example.com'])
        self.assertEqual(config.smtp_server, 'smtp.gmail.com')  # Значение по умолчанию
    
    def test_alert_creation(self):
        """Тест создания объекта Alert."""
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test Alert",
            message="Test message",
            source="Ozon"
        )
        
        self.assertEqual(alert.level, AlertLevel.ERROR)
        self.assertEqual(alert.type, NotificationType.SYNC_FAILURE)
        self.assertEqual(alert.title, "Test Alert")
        self.assertEqual(alert.message, "Test message")
        self.assertEqual(alert.source, "Ozon")
        self.assertIsInstance(alert.timestamp, datetime)
    
    def test_should_send_alert_no_cooldown(self):
        """Тест проверки отправки алерта без cooldown."""
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test Alert",
            message="Test message",
            source="Ozon"
        )
        
        # Первый алерт должен отправляться
        should_send = self.alert_manager._should_send_alert(alert)
        self.assertTrue(should_send)
    
    def test_should_send_alert_with_cooldown(self):
        """Тест проверки отправки алерта с активным cooldown."""
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test Alert",
            message="Test message",
            source="Ozon"
        )
        
        # Добавляем алерт в историю отправленных
        alert_key = f"{alert.type.value}_{alert.source}"
        self.alert_manager.sent_alerts[alert_key] = datetime.now()
        
        # Алерт не должен отправляться из-за cooldown
        should_send = self.alert_manager._should_send_alert(alert)
        self.assertFalse(should_send)
    
    def test_should_send_alert_cooldown_expired(self):
        """Тест проверки отправки алерта после истечения cooldown."""
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test Alert",
            message="Test message",
            source="Ozon"
        )
        
        # Добавляем алерт в историю с истекшим cooldown
        alert_key = f"{alert.type.value}_{alert.source}"
        cooldown = self.alert_manager.alert_cooldowns[alert.type]
        self.alert_manager.sent_alerts[alert_key] = datetime.now() - cooldown - timedelta(minutes=1)
        
        # Алерт должен отправляться после истечения cooldown
        should_send = self.alert_manager._should_send_alert(alert)
        self.assertTrue(should_send)
    
    @patch('alert_manager.MimeText')
    @patch('alert_manager.MimeMultipart')
    @patch('alert_manager.smtplib.SMTP')
    def test_send_email_alert_success(self, mock_smtp, mock_multipart, mock_mimetext):
        """Тест успешной отправки email алерта."""
        # Настраиваем mock SMTP сервера
        mock_server = Mock()
        mock_smtp.return_value = mock_server
        
        # Настраиваем mock для email модулей
        mock_msg = Mock()
        mock_multipart.return_value = mock_msg
        mock_msg.as_string.return_value = "test email content"
        
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test Email Alert",
            message="Test email message",
            source="Ozon"
        )
        
        # Выполняем отправку
        success = self.alert_manager._send_email_alert(alert)
        
        # Проверяем результат
        self.assertTrue(success)
        mock_smtp.assert_called_once_with('smtp.test.com', 587)
        mock_server.starttls.assert_called_once()
        mock_server.login.assert_called_once_with('test@example.com', 'test_password')
        mock_server.sendmail.assert_called_once()
        mock_server.quit.assert_called_once()
    
    @patch('alert_manager.smtplib.SMTP')
    def test_send_email_alert_failure(self, mock_smtp):
        """Тест неудачной отправки email алерта."""
        # Настраиваем mock для генерации ошибки
        mock_smtp.side_effect = Exception("SMTP connection failed")
        
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test Email Alert",
            message="Test email message"
        )
        
        # Выполняем отправку
        success = self.alert_manager._send_email_alert(alert)
        
        # Проверяем, что отправка не удалась
        self.assertFalse(success)
    
    @patch('alert_manager.requests.post')
    def test_send_telegram_alert_success(self, mock_post):
        """Тест успешной отправки Telegram алерта."""
        # Настраиваем mock ответа
        mock_response = Mock()
        mock_response.raise_for_status.return_value = None
        mock_post.return_value = mock_response
        
        alert = Alert(
            level=AlertLevel.WARNING,
            type=NotificationType.STALE_DATA,
            title="Test Telegram Alert",
            message="Test telegram message",
            source="Wildberries"
        )
        
        # Выполняем отправку
        success = self.alert_manager._send_telegram_alert(alert)
        
        # Проверяем результат
        self.assertTrue(success)
        mock_post.assert_called_once()
        
        # Проверяем параметры запроса
        call_args = mock_post.call_args
        self.assertIn('json', call_args.kwargs)
        payload = call_args.kwargs['json']
        self.assertEqual(payload['chat_id'], 'test_chat_id')
        self.assertIn('Test Telegram Alert', payload['text'])
    
    @patch('alert_manager.requests.post')
    def test_send_telegram_alert_failure(self, mock_post):
        """Тест неудачной отправки Telegram алерта."""
        # Настраиваем mock для генерации ошибки
        mock_post.side_effect = Exception("Telegram API error")
        
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.API_ERROR,
            title="Test Telegram Alert",
            message="Test telegram message"
        )
        
        # Выполняем отправку
        success = self.alert_manager._send_telegram_alert(alert)
        
        # Проверяем, что отправка не удалась
        self.assertFalse(success)
    
    def test_log_alert_to_db_success(self):
        """Тест успешного логирования алерта в БД."""
        alert = Alert(
            level=AlertLevel.CRITICAL,
            type=NotificationType.ANOMALY_DETECTED,
            title="Test DB Alert",
            message="Test database logging",
            source="Ozon",
            details={'test_key': 'test_value'}
        )
        
        # Выполняем логирование
        self.alert_manager._log_alert_to_db(alert, True)
        
        # Проверяем, что были выполнены запросы к БД
        self.assertEqual(self.mock_cursor.execute.call_count, 2)  # CREATE TABLE + INSERT
        self.mock_connection.commit.assert_called_once()
    
    def test_log_alert_to_db_failure(self):
        """Тест логирования алерта при ошибке БД."""
        # Настраиваем mock для генерации ошибки
        self.mock_cursor.execute.side_effect = Exception("Database error")
        
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test DB Alert",
            message="Test database error"
        )
        
        # Выполняем логирование (не должно вызывать исключение)
        self.alert_manager._log_alert_to_db(alert, False)
        
        # Проверяем, что был вызван rollback
        self.mock_connection.rollback.assert_called_once()
    
    @patch.object(AlertManager, '_send_email_alert')
    @patch.object(AlertManager, '_send_telegram_alert')
    @patch.object(AlertManager, '_log_alert_to_db')
    def test_send_alert_success(self, mock_log, mock_telegram, mock_email):
        """Тест успешной отправки алерта через все каналы."""
        # Настраиваем mock методы
        mock_email.return_value = True
        mock_telegram.return_value = True
        
        alert = Alert(
            level=AlertLevel.WARNING,
            type=NotificationType.SYSTEM_HEALTH,
            title="Test Alert",
            message="Test message"
        )
        
        # Выполняем отправку
        success = self.alert_manager.send_alert(alert)
        
        # Проверяем результат
        self.assertTrue(success)
        mock_email.assert_called_once_with(alert)
        mock_telegram.assert_called_once_with(alert)
        mock_log.assert_called_once_with(alert, True)
    
    @patch.object(AlertManager, '_should_send_alert')
    def test_send_alert_cooldown_skip(self, mock_should_send):
        """Тест пропуска отправки алерта из-за cooldown."""
        # Настраиваем mock для пропуска из-за cooldown
        mock_should_send.return_value = False
        
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test Alert",
            message="Test message"
        )
        
        # Выполняем отправку
        success = self.alert_manager.send_alert(alert)
        
        # Проверяем, что алерт был пропущен
        self.assertFalse(success)
        mock_should_send.assert_called_once_with(alert)
    
    def test_send_sync_failure_alert(self):
        """Тест отправки уведомления о сбое синхронизации."""
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку
            success = self.alert_manager.send_sync_failure_alert(
                source="Ozon",
                error_message="API connection timeout",
                failure_count=3
            )
            
            # Проверяем результат
            self.assertTrue(success)
            mock_send.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.ERROR)  # failure_count <= 3
            self.assertEqual(alert.type, NotificationType.SYNC_FAILURE)
            self.assertEqual(alert.source, "Ozon")
            self.assertIn("API connection timeout", alert.message)
    
    def test_send_sync_failure_alert_critical(self):
        """Тест отправки критического уведомления о сбое синхронизации."""
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку с большим количеством сбоев
            success = self.alert_manager.send_sync_failure_alert(
                source="Wildberries",
                error_message="Authentication failed",
                failure_count=5
            )
            
            # Проверяем результат
            self.assertTrue(success)
            
            # Проверяем уровень алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.CRITICAL)  # failure_count > 3
    
    def test_send_stale_data_alert(self):
        """Тест отправки уведомления об устаревших данных."""
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку
            success = self.alert_manager.send_stale_data_alert(
                source="Ozon",
                hours_since_update=8.5
            )
            
            # Проверяем результат
            self.assertTrue(success)
            mock_send.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.WARNING)  # 8.5 < 24
            self.assertEqual(alert.type, NotificationType.STALE_DATA)
            self.assertEqual(alert.source, "Ozon")
            self.assertIn("8.5 часов", alert.message)
    
    def test_send_stale_data_alert_critical(self):
        """Тест отправки критического уведомления об устаревших данных."""
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку с критическим временем
            success = self.alert_manager.send_stale_data_alert(
                source="Wildberries",
                hours_since_update=30.0
            )
            
            # Проверяем уровень алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.CRITICAL)  # 30 > 24
    
    def test_send_anomaly_alert(self):
        """Тест отправки уведомления об аномалии."""
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку
            success = self.alert_manager.send_anomaly_alert(
                anomaly_type="zero_stock_spike",
                source="Ozon",
                description="60% товаров с нулевыми остатками",
                affected_records=150,
                severity="high"
            )
            
            # Проверяем результат
            self.assertTrue(success)
            mock_send.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.ERROR)  # high severity
            self.assertEqual(alert.type, NotificationType.ANOMALY_DETECTED)
            self.assertEqual(alert.source, "Ozon")
            # Alert объект не имеет affected_records, проверяем details
            self.assertIn('affected_records', alert.details)
    
    def test_send_api_error_alert(self):
        """Тест отправки уведомления об ошибке API."""
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку
            success = self.alert_manager.send_api_error_alert(
                source="Ozon",
                endpoint="/v1/products/stocks",
                status_code=429,
                error_message="Rate limit exceeded"
            )
            
            # Проверяем результат
            self.assertTrue(success)
            mock_send.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.ERROR)  # 429 не критичный
            self.assertEqual(alert.type, NotificationType.API_ERROR)
            self.assertEqual(alert.source, "Ozon")
            self.assertIn("429", alert.message)
    
    def test_send_api_error_alert_critical(self):
        """Тест отправки критического уведомления об ошибке API."""
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку с критическим статусом
            success = self.alert_manager.send_api_error_alert(
                source="Wildberries",
                endpoint="/api/v1/supplier/stocks",
                status_code=401,
                error_message="Unauthorized access"
            )
            
            # Проверяем уровень алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.CRITICAL)  # 401 критичный
    
    def test_send_weekly_report(self):
        """Тест отправки еженедельного отчета."""
        report_data = {
            'period': 'week',
            'total_syncs': 50,
            'success_rate': 0.92,
            'anomalies_detected': 3
        }
        
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку
            success = self.alert_manager.send_weekly_report(report_data)
            
            # Проверяем результат
            self.assertTrue(success)
            mock_send.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.INFO)
            self.assertEqual(alert.type, NotificationType.WEEKLY_REPORT)
            self.assertEqual(alert.details, report_data)
    
    def test_send_system_health_alert(self):
        """Тест отправки уведомления о состоянии системы."""
        with patch.object(self.alert_manager, 'send_alert') as mock_send:
            mock_send.return_value = True
            
            # Выполняем отправку
            success = self.alert_manager.send_system_health_alert(
                overall_status="warning",
                sources_status={"Ozon": "healthy", "Wildberries": "warning"},
                anomalies_count=2
            )
            
            # Проверяем результат
            self.assertTrue(success)
            mock_send.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send.call_args[0][0]
            self.assertEqual(alert.level, AlertLevel.WARNING)
            self.assertEqual(alert.type, NotificationType.SYSTEM_HEALTH)
            self.assertIn("warning", alert.message)
    
    def test_format_email_body(self):
        """Тест форматирования тела email сообщения."""
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title="Test Alert",
            message="Test message for email",
            source="Ozon",
            details={'error_code': 500, 'retry_count': 3}
        )
        
        # Форматируем тело сообщения
        body = self.alert_manager._format_email_body(alert)
        
        # Проверяем содержимое
        self.assertIn("АЛЕРТ СИСТЕМЫ СИНХРОНИЗАЦИИ", body)
        self.assertIn("ERROR", body)
        self.assertIn("sync_failure", body)
        self.assertIn("Test message for email", body)
        self.assertIn("Test message for email", body)
        self.assertIn("Ozon", body)
        self.assertIn("error_code: 500", body)
        self.assertIn("retry_count: 3", body)
    
    def test_format_telegram_message(self):
        """Тест форматирования сообщения для Telegram."""
        alert = Alert(
            level=AlertLevel.CRITICAL,
            type=NotificationType.ANOMALY_DETECTED,
            title="Critical Anomaly",
            message="Critical anomaly detected in system",
            source="Wildberries"
        )
        
        # Форматируем сообщение
        message = self.alert_manager._format_telegram_message(alert)
        
        # Проверяем содержимое
        self.assertIn("🚨", message)  # Эмодзи для критического уровня
        self.assertIn("*Critical Anomaly*", message)
        self.assertIn("*Уровень:* CRITICAL", message)
        self.assertIn("*Источник:* Wildberries", message)
        self.assertIn("Critical anomaly detected", message)
    
    def test_format_telegram_message_truncation(self):
        """Тест обрезания длинного сообщения для Telegram."""
        # Создаем очень длинное сообщение
        long_message = "A" * 5000  # 5000 символов
        
        alert = Alert(
            level=AlertLevel.INFO,
            type=NotificationType.WEEKLY_REPORT,
            title="Long Report",
            message=long_message
        )
        
        # Форматируем сообщение
        message = self.alert_manager._format_telegram_message(alert)
        
        # Проверяем, что сообщение обрезано
        self.assertLess(len(message), 4096)  # Лимит Telegram
        self.assertIn("... (сообщение обрезано)", message)
    
    def test_format_weekly_summary(self):
        """Тест форматирования еженедельной сводки."""
        report_data = {
            'health_status': {
                'overall_status': 'healthy',
                'sources': {
                    'Ozon': {'health_status': 'healthy'},
                    'Wildberries': {'health_status': 'warning'}
                }
            },
            'sync_statistics': {
                'Ozon': {
                    'success': {'count': 20},
                    'failed': {'count': 2}
                },
                'Wildberries': {
                    'success': {'count': 18},
                    'failed': {'count': 4}
                }
            },
            'anomalies': [
                {'type': 'stale_data'},
                {'type': 'api_errors'}
            ]
        }
        
        # Форматируем сводку
        summary = self.alert_manager._format_weekly_summary(report_data)
        
        # Проверяем содержимое
        self.assertIn("ЕЖЕНЕДЕЛЬНАЯ СВОДКА", summary)
        self.assertIn("healthy", summary)
        self.assertIn("Ozon: healthy", summary)
        self.assertIn("Wildberries: warning", summary)
        self.assertIn("90.9%", summary)  # Ozon: 20/(20+2)*100
        self.assertIn("81.8%", summary)  # Wildberries: 18/(18+4)*100
        self.assertIn("Обнаружено аномалий: 2", summary)
    
    def test_get_recent_alerts(self):
        """Тест получения последних алертов из БД."""
        # Настраиваем mock данные
        mock_alerts = [
            ('error', 'sync_failure', 'Sync Failed', 'Error message', 'Ozon', 
             '{"error_code": 500}', 1, datetime.now()),
            ('warning', 'stale_data', 'Stale Data', 'Data is old', 'Wildberries',
             '{"hours": 8}', 1, datetime.now() - timedelta(hours=2))
        ]
        self.mock_cursor.fetchall.return_value = mock_alerts
        
        # Выполняем запрос
        alerts = self.alert_manager.get_recent_alerts(24, 'sync_failure')
        
        # Проверяем результат
        self.assertEqual(len(alerts), 2)
        self.mock_cursor.execute.assert_called_once()
    
    def test_get_recent_alerts_no_db(self):
        """Тест получения алертов без подключения к БД."""
        # Создаем AlertManager без БД
        alert_manager_no_db = AlertManager()
        
        # Выполняем запрос
        alerts = alert_manager_no_db.get_recent_alerts(24)
        
        # Проверяем, что возвращается пустой список
        self.assertEqual(len(alerts), 0)
    
    @patch.object(AlertManager, '_send_email_alert')
    @patch.object(AlertManager, '_send_telegram_alert')
    def test_test_notification_channels(self, mock_telegram, mock_email):
        """Тест проверки каналов уведомлений."""
        # Настраиваем mock методы
        mock_email.return_value = True
        mock_telegram.return_value = True
        
        # Выполняем тестирование
        results = self.alert_manager.test_notification_channels()
        
        # Проверяем результаты
        self.assertIn('email', results)
        self.assertIn('telegram', results)
        self.assertTrue(results['email'])
        self.assertTrue(results['telegram'])
        
        # Проверяем, что методы были вызваны
        mock_email.assert_called_once()
        mock_telegram.assert_called_once()
    
    def test_test_notification_channels_disabled(self):
        """Тест проверки отключенных каналов уведомлений."""
        # Отключаем каналы
        self.alert_manager.config.email_enabled = False
        self.alert_manager.config.telegram_enabled = False
        
        # Выполняем тестирование
        results = self.alert_manager.test_notification_channels()
        
        # Проверяем результаты
        self.assertFalse(results['email'])
        self.assertFalse(results['telegram'])
    
    def test_update_sent_alerts(self):
        """Тест обновления истории отправленных алертов."""
        alert = Alert(
            level=AlertLevel.WARNING,
            type=NotificationType.STALE_DATA,
            title="Test Alert",
            message="Test message",
            source="Ozon"
        )
        
        # Обновляем историю
        self.alert_manager._update_sent_alerts(alert)
        
        # Проверяем, что алерт добавлен в историю
        alert_key = f"{alert.type.value}_{alert.source}"
        self.assertIn(alert_key, self.alert_manager.sent_alerts)
        self.assertIsInstance(self.alert_manager.sent_alerts[alert_key], datetime)


class TestAlertManagerIntegration(unittest.TestCase):
    """Интеграционные тесты для AlertManager."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        self.alert_manager = AlertManager(self.mock_cursor, self.mock_connection)
        
        # Настраиваем тестовую конфигурацию
        self.alert_manager.config = NotificationConfig(
            email_enabled=True,
            telegram_enabled=True,
            recipients=['test@example.com'],
            telegram_bot_token='test_token',
            telegram_chat_id='test_chat'
        )
    
    @patch.object(AlertManager, '_send_email_alert')
    @patch.object(AlertManager, '_send_telegram_alert')
    @patch.object(AlertManager, '_log_alert_to_db')
    def test_full_alert_workflow(self, mock_log, mock_telegram, mock_email):
        """Тест полного цикла отправки алерта."""
        # Настраиваем mock методы
        mock_email.return_value = True
        mock_telegram.return_value = True
        
        # Создаем и отправляем алерт
        success = self.alert_manager.send_sync_failure_alert(
            source="Ozon",
            error_message="Connection timeout",
            failure_count=2
        )
        
        # Проверяем, что алерт был обработан полностью
        self.assertTrue(success)
        mock_email.assert_called_once()
        mock_telegram.assert_called_once()
        mock_log.assert_called_once()
        
        # Проверяем, что алерт добавлен в историю
        self.assertGreater(len(self.alert_manager.sent_alerts), 0)
    
    @patch.object(AlertManager, 'send_alert')
    def test_multiple_alert_types_workflow(self, mock_send_alert):
        """Тест отправки различных типов алертов."""
        mock_send_alert.return_value = True
        
        # Отправляем различные типы алертов
        self.alert_manager.send_sync_failure_alert("Ozon", "Error 1", 1)
        self.alert_manager.send_stale_data_alert("Wildberries", 10.0)
        self.alert_manager.send_anomaly_alert("zero_stock", "Ozon", "Description", 50)
        self.alert_manager.send_api_error_alert("Ozon", "/api/test", 500, "Server error")
        
        # Проверяем, что все алерты были отправлены
        self.assertEqual(mock_send_alert.call_count, 4)
        
        # Проверяем типы отправленных алертов
        call_args_list = mock_send_alert.call_args_list
        alert_types = [call[0][0].type for call in call_args_list]
        
        expected_types = [
            NotificationType.SYNC_FAILURE,
            NotificationType.STALE_DATA,
            NotificationType.ANOMALY_DETECTED,
            NotificationType.API_ERROR
        ]
        
        for expected_type in expected_types:
            self.assertIn(expected_type, alert_types)


if __name__ == '__main__':
    # Настройка логирования для тестов
    import logging
    logging.basicConfig(level=logging.WARNING)
    
    # Запуск тестов
    unittest.main(verbosity=2)