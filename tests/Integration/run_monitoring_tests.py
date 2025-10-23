#!/usr/bin/env python3
"""
Простой скрипт для запуска тестов системы мониторинга.

Проверяет основную функциональность без сложных mock'ов.
"""

import unittest
import sys
import os
from datetime import datetime, timedelta, date
from unittest.mock import Mock

# Добавляем текущую директорию в путь для импорта
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from sync_monitor import SyncMonitor, HealthStatus, AnomalyType, Anomaly
from alert_manager import AlertManager, Alert, AlertLevel, NotificationType


class TestMonitoringCore(unittest.TestCase):
    """Основные тесты системы мониторинга."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        
        self.monitor = SyncMonitor(self.mock_cursor, self.mock_connection)
        self.alert_manager = AlertManager()
        
        # Отключаем реальную отправку уведомлений
        self.alert_manager.config.email_enabled = False
        self.alert_manager.config.telegram_enabled = False
    
    def test_sync_monitor_initialization(self):
        """Тест инициализации SyncMonitor."""
        self.assertIsNotNone(self.monitor)
        self.assertIsInstance(self.monitor.thresholds, dict)
        self.assertIn('data_freshness_hours', self.monitor.thresholds)
    
    def test_alert_manager_initialization(self):
        """Тест инициализации AlertManager."""
        self.assertIsNotNone(self.alert_manager)
        self.assertIsInstance(self.alert_manager.alert_cooldowns, dict)
    
    def test_anomaly_creation(self):
        """Тест создания объекта аномалии."""
        anomaly = Anomaly(
            type=AnomalyType.ZERO_STOCK_SPIKE,
            severity='high',
            source='Ozon',
            description='Test anomaly',
            affected_records=100,
            detected_at=datetime.now(),
            details={'test': 'data'}
        )
        
        self.assertEqual(anomaly.type, AnomalyType.ZERO_STOCK_SPIKE)
        self.assertEqual(anomaly.severity, 'high')
        self.assertEqual(anomaly.source, 'Ozon')
        self.assertEqual(anomaly.affected_records, 100)
    
    def test_alert_creation(self):
        """Тест создания объекта алерта."""
        alert = Alert(
            level=AlertLevel.WARNING,
            type=NotificationType.STALE_DATA,
            title='Test Alert',
            message='Test message',
            source='Wildberries'
        )
        
        self.assertEqual(alert.level, AlertLevel.WARNING)
        self.assertEqual(alert.type, NotificationType.STALE_DATA)
        self.assertEqual(alert.title, 'Test Alert')
        self.assertEqual(alert.source, 'Wildberries')
    
    def test_health_status_enum(self):
        """Тест перечисления статусов здоровья."""
        self.assertEqual(HealthStatus.HEALTHY.value, 'healthy')
        self.assertEqual(HealthStatus.WARNING.value, 'warning')
        self.assertEqual(HealthStatus.CRITICAL.value, 'critical')
        self.assertEqual(HealthStatus.UNKNOWN.value, 'unknown')
    
    def test_anomaly_type_enum(self):
        """Тест перечисления типов аномалий."""
        self.assertEqual(AnomalyType.ZERO_STOCK_SPIKE.value, 'zero_stock_spike')
        self.assertEqual(AnomalyType.MASSIVE_STOCK_CHANGE.value, 'massive_stock_change')
        self.assertEqual(AnomalyType.MISSING_PRODUCTS.value, 'missing_products')
        self.assertEqual(AnomalyType.DUPLICATE_RECORDS.value, 'duplicate_records')
        self.assertEqual(AnomalyType.NEGATIVE_STOCK.value, 'negative_stock')
        self.assertEqual(AnomalyType.STALE_DATA.value, 'stale_data')
        self.assertEqual(AnomalyType.API_ERRORS.value, 'api_errors')
    
    def test_alert_level_enum(self):
        """Тест перечисления уровней алертов."""
        self.assertEqual(AlertLevel.INFO.value, 'info')
        self.assertEqual(AlertLevel.WARNING.value, 'warning')
        self.assertEqual(AlertLevel.ERROR.value, 'error')
        self.assertEqual(AlertLevel.CRITICAL.value, 'critical')
    
    def test_notification_type_enum(self):
        """Тест перечисления типов уведомлений."""
        self.assertEqual(NotificationType.SYNC_FAILURE.value, 'sync_failure')
        self.assertEqual(NotificationType.STALE_DATA.value, 'stale_data')
        self.assertEqual(NotificationType.ANOMALY_DETECTED.value, 'anomaly_detected')
        self.assertEqual(NotificationType.SYSTEM_HEALTH.value, 'system_health')
        self.assertEqual(NotificationType.WEEKLY_REPORT.value, 'weekly_report')
        self.assertEqual(NotificationType.API_ERROR.value, 'api_error')
    
    def test_threshold_configuration(self):
        """Тест настройки пороговых значений."""
        # Проверяем значения по умолчанию
        self.assertGreater(self.monitor.thresholds['data_freshness_hours'], 0)
        self.assertGreater(self.monitor.thresholds['success_rate_threshold'], 0)
        self.assertGreater(self.monitor.thresholds['massive_change_threshold'], 0)
        
        # Изменяем пороги
        original_threshold = self.monitor.thresholds['data_freshness_hours']
        self.monitor.thresholds['data_freshness_hours'] = 12
        
        self.assertEqual(self.monitor.thresholds['data_freshness_hours'], 12)
        self.assertNotEqual(self.monitor.thresholds['data_freshness_hours'], original_threshold)
    
    def test_cooldown_configuration(self):
        """Тест настройки cooldown для алертов."""
        # Проверяем наличие cooldown для основных типов
        self.assertIn(NotificationType.SYNC_FAILURE, self.alert_manager.alert_cooldowns)
        self.assertIn(NotificationType.STALE_DATA, self.alert_manager.alert_cooldowns)
        
        # Изменяем cooldown
        original_cooldown = self.alert_manager.alert_cooldowns[NotificationType.SYNC_FAILURE]
        new_cooldown = timedelta(minutes=30)
        self.alert_manager.alert_cooldowns[NotificationType.SYNC_FAILURE] = new_cooldown
        
        self.assertEqual(
            self.alert_manager.alert_cooldowns[NotificationType.SYNC_FAILURE], 
            new_cooldown
        )
        self.assertNotEqual(
            self.alert_manager.alert_cooldowns[NotificationType.SYNC_FAILURE], 
            original_cooldown
        )
    
    def test_alert_should_send_logic(self):
        """Тест логики определения необходимости отправки алерта."""
        alert = Alert(
            level=AlertLevel.ERROR,
            type=NotificationType.SYNC_FAILURE,
            title='Test Alert',
            message='Test message',
            source='Ozon'
        )
        
        # Первый раз должен отправляться
        should_send = self.alert_manager._should_send_alert(alert)
        self.assertTrue(should_send)
        
        # Добавляем в историю
        self.alert_manager._update_sent_alerts(alert)
        
        # Теперь не должен отправляться из-за cooldown
        should_send = self.alert_manager._should_send_alert(alert)
        self.assertFalse(should_send)
    
    def test_email_body_formatting(self):
        """Тест форматирования email сообщения."""
        alert = Alert(
            level=AlertLevel.CRITICAL,
            type=NotificationType.ANOMALY_DETECTED,
            title='Critical Issue',
            message='System has critical issues',
            source='System',
            details={'error_count': 50}
        )
        
        body = self.alert_manager._format_email_body(alert)
        
        # Проверяем основные элементы
        self.assertIn('АЛЕРТ СИСТЕМЫ СИНХРОНИЗАЦИИ', body)
        self.assertIn('CRITICAL', body)
        self.assertIn('anomaly_detected', body)
        self.assertIn('System has critical issues', body)
        self.assertIn('error_count: 50', body)
    
    def test_telegram_message_formatting(self):
        """Тест форматирования Telegram сообщения."""
        alert = Alert(
            level=AlertLevel.WARNING,
            type=NotificationType.STALE_DATA,
            title='Data is Stale',
            message='Data has not been updated for 8 hours',
            source='Ozon'
        )
        
        message = self.alert_manager._format_telegram_message(alert)
        
        # Проверяем основные элементы
        self.assertIn('⚠️', message)  # Эмодзи для WARNING
        self.assertIn('*Data is Stale*', message)
        self.assertIn('*Уровень:* WARNING', message)
        self.assertIn('*Источник:* Ozon', message)
        self.assertIn('Data has not been updated', message)


def run_tests():
    """Запуск тестов с подробным выводом."""
    print("🧪 Запуск тестов системы мониторинга...")
    print("=" * 60)
    
    # Создаем test suite
    loader = unittest.TestLoader()
    suite = loader.loadTestsFromTestCase(TestMonitoringCore)
    
    # Запускаем тесты
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(suite)
    
    print("\n" + "=" * 60)
    print(f"📊 Результаты тестирования:")
    print(f"   ✅ Пройдено: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"   ❌ Провалено: {len(result.failures)}")
    print(f"   🚫 Ошибки: {len(result.errors)}")
    print(f"   📈 Общий результат: {result.testsRun} тестов")
    
    if result.failures:
        print(f"\n❌ Провалившиеся тесты:")
        for test, traceback in result.failures:
            print(f"   - {test}")
    
    if result.errors:
        print(f"\n🚫 Тесты с ошибками:")
        for test, traceback in result.errors:
            print(f"   - {test}")
    
    success_rate = (result.testsRun - len(result.failures) - len(result.errors)) / result.testsRun * 100
    print(f"\n🎯 Процент успешности: {success_rate:.1f}%")
    
    if success_rate >= 90:
        print("🎉 Отличный результат! Система мониторинга работает корректно.")
    elif success_rate >= 70:
        print("👍 Хороший результат! Есть небольшие проблемы для исправления.")
    else:
        print("⚠️ Требуется доработка системы мониторинга.")
    
    return result.wasSuccessful()


if __name__ == '__main__':
    success = run_tests()
    sys.exit(0 if success else 1)