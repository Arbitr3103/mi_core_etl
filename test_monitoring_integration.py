#!/usr/bin/env python3
"""
Интеграционные тесты для системы мониторинга и алертов.

Тестирует:
- Интеграцию между SyncMonitor и AlertManager
- Автоматическую отправку уведомлений при обнаружении аномалий
- Корректность детекции аномалий и генерации алертов
- Полный цикл мониторинга и уведомлений

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
from unittest.mock import Mock, MagicMock, patch
from datetime import datetime, timedelta, date
import sys
import os

# Добавляем текущую директорию в путь для импорта
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from sync_monitor import SyncMonitor, HealthStatus, AnomalyType, Anomaly
from alert_manager import AlertManager, Alert, AlertLevel, NotificationType


class TestMonitoringIntegration(unittest.TestCase):
    """Интеграционные тесты для системы мониторинга и алертов."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        # Создаем mock объекты для БД
        self.mock_cursor = Mock()
        self.mock_connection = Mock()
        
        # Создаем экземпляры SyncMonitor и AlertManager
        self.monitor = SyncMonitor(
            db_cursor=self.mock_cursor,
            db_connection=self.mock_connection,
            logger_name="TestSyncMonitor"
        )
        
        self.alert_manager = AlertManager(
            db_cursor=self.mock_cursor,
            db_connection=self.mock_connection,
            logger_name="TestAlertManager"
        )
        
        # Настраиваем mock для определения типа БД
        self.mock_cursor.lastrowid = 1  # Имитируем SQLite
        
        # Отключаем реальную отправку уведомлений в тестах
        self.alert_manager.config.email_enabled = False
        self.alert_manager.config.telegram_enabled = False
    
    def test_anomaly_detection_triggers_alerts(self):
        """Тест автоматической отправки алертов при обнаружении аномалий."""
        # Настраиваем mock данные для детекции аномалий
        self.mock_cursor.fetchall.side_effect = [
            # Zero stock anomaly - 70% товаров с нулевыми остатками
            [('Ozon', 100, 70)],
            # Massive changes - пустой результат
            [],
            # Missing products - пустой результат
            [],
            # Duplicates - пустой результат
            [],
            # Negative stock - 15 записей с отрицательными остатками
            [('Ozon', 15)],
            # Stale data - данные устарели на 10 часов
            [('Ozon', datetime.now() - timedelta(hours=10))],
            # API errors - пустой результат
            []
        ]
        
        # Детектируем аномалии
        anomalies = self.monitor.detect_data_anomalies('Ozon')
        
        # Проверяем, что аномалии обнаружены
        self.assertGreater(len(anomalies), 0)
        
        # Отправляем алерты для каждой аномалии
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            for anomaly in anomalies:
                success = self.alert_manager.send_anomaly_alert(
                    anomaly_type=anomaly.type.value,
                    source=anomaly.source,
                    description=anomaly.description,
                    affected_records=anomaly.affected_records,
                    severity=anomaly.severity
                )
                self.assertTrue(success)
            
            # Проверяем, что алерты были отправлены
            self.assertEqual(mock_send_alert.call_count, len(anomalies))
    
    def test_health_check_triggers_system_alerts(self):
        """Тест отправки системных алертов на основе проверки здоровья."""
        # Настраиваем mock данные для нездоровой системы
        self.mock_cursor.fetchall.side_effect = [
            # Метрики Ozon - много ошибок
            [('failed', 15, 0, 0, datetime.now())],
            # Метрики Wildberries - устаревшие данные
            [('success', 5, 30.0, 500, datetime.now() - timedelta(hours=30))],
            # Данные для детекции аномалий (несколько вызовов)
            [], [], [], [], [], [], [],  # Ozon
            [], [], [], [], [], [], [],  # Wildberries
        ]
        
        # Выполняем проверку здоровья
        health_report = self.monitor.check_sync_health()
        
        # Проверяем, что система не здорова
        self.assertNotEqual(health_report.overall_status, HealthStatus.HEALTHY)
        
        # Отправляем системный алерт
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            sources_status = {}
            for source, data in health_report.sources.items():
                sources_status[source] = data.get('health_status', HealthStatus.UNKNOWN).value
            
            success = self.alert_manager.send_system_health_alert(
                overall_status=health_report.overall_status.value,
                sources_status=sources_status,
                anomalies_count=len(health_report.anomalies)
            )
            
            self.assertTrue(success)
            mock_send_alert.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send_alert.call_args[0][0]
            self.assertEqual(alert.type, NotificationType.SYSTEM_HEALTH)
    
    def test_sync_failure_detection_and_alerting(self):
        """Тест детекции сбоев синхронизации и отправки алертов."""
        # Настраиваем mock данные для сбоев синхронизации
        self.mock_cursor.fetchall.side_effect = [
            # Метрики с высоким процентом ошибок
            [('failed', 20, 0, 0, datetime.now())],
            # API errors - много ошибок
            [('Ozon', 25, 'Rate limit exceeded; Authentication failed')]
        ]
        
        # Рассчитываем метрики источника
        metrics = self.monitor._calculate_source_metrics('Ozon')
        
        # Проверяем, что обнаружены проблемы
        self.assertEqual(metrics.success_rate_24h, 0)  # Все синхронизации неудачные
        self.assertEqual(metrics.error_count_24h, 20)
        
        # Детектируем ошибки API
        api_anomalies = self.monitor._detect_api_errors('Ozon')
        self.assertGreater(len(api_anomalies), 0)
        
        # Отправляем алерты о сбоях
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            # Алерт о сбое синхронизации
            self.alert_manager.send_sync_failure_alert(
                source='Ozon',
                error_message='Multiple sync failures detected',
                failure_count=5
            )
            
            # Алерт об ошибках API
            for anomaly in api_anomalies:
                self.alert_manager.send_anomaly_alert(
                    anomaly_type=anomaly.type.value,
                    source=anomaly.source,
                    description=anomaly.description,
                    affected_records=anomaly.affected_records,
                    severity=anomaly.severity
                )
            
            # Проверяем количество отправленных алертов
            expected_alerts = 1 + len(api_anomalies)
            self.assertEqual(mock_send_alert.call_count, expected_alerts)
    
    def test_stale_data_detection_and_alerting(self):
        """Тест детекции устаревших данных и отправки алертов."""
        # Настраиваем mock данные для устаревших данных
        old_time = datetime.now() - timedelta(hours=15)
        self.mock_cursor.fetchall.return_value = [
            ('Ozon', old_time)
        ]
        
        # Детектируем устаревшие данные
        stale_anomalies = self.monitor._detect_stale_data('Ozon')
        
        # Проверяем, что аномалия обнаружена
        self.assertEqual(len(stale_anomalies), 1)
        anomaly = stale_anomalies[0]
        self.assertEqual(anomaly.type, AnomalyType.STALE_DATA)
        self.assertEqual(anomaly.severity, 'high')  # 15 часов > 12
        
        # Отправляем алерт об устаревших данных
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            self.alert_manager.send_stale_data_alert(
                source='Ozon',
                hours_since_update=15.0
            )
            
            mock_send_alert.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send_alert.call_args[0][0]
            self.assertEqual(alert.type, NotificationType.STALE_DATA)
            self.assertEqual(alert.level, AlertLevel.WARNING)  # 15 < 24
    
    def test_weekly_report_generation_and_sending(self):
        """Тест генерации и отправки еженедельного отчета."""
        # Настраиваем mock данные для отчета
        self.mock_cursor.fetchall.side_effect = [
            # Статистика синхронизации
            [
                ('Ozon', 'success', 40, 25.5, 4000, 3800, 200, datetime.now()),
                ('Ozon', 'failed', 8, 0, 0, 0, 0, datetime.now()),
                ('Wildberries', 'success', 35, 30.0, 3500, 3300, 200, datetime.now()),
                ('Wildberries', 'failed', 5, 0, 0, 0, 0, datetime.now())
            ],
            # Статистика остатков
            [
                ('Ozon', 500, 450, 25000, 50.0, datetime.now()),
                ('Wildberries', 400, 380, 20000, 52.6, datetime.now())
            ]
        ]
        
        # Генерируем отчет
        report = self.monitor.generate_sync_report(168)  # 7 дней = 168 часов
        
        # Проверяем структуру отчета
        self.assertIn('sync_statistics', report)
        self.assertIn('inventory_statistics', report)
        self.assertIn('health_status', report)
        
        # Отправляем еженедельный отчет
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            self.alert_manager.send_weekly_report(report)
            
            mock_send_alert.assert_called_once()
            
            # Проверяем параметры алерта
            alert = mock_send_alert.call_args[0][0]
            self.assertEqual(alert.type, NotificationType.WEEKLY_REPORT)
            self.assertEqual(alert.level, AlertLevel.INFO)
            self.assertEqual(alert.details, report)
    
    def test_multiple_anomalies_batch_alerting(self):
        """Тест пакетной отправки алертов при множественных аномалиях."""
        # Создаем несколько аномалий разных типов
        anomalies = [
            Anomaly(
                type=AnomalyType.ZERO_STOCK_SPIKE,
                severity='high',
                source='Ozon',
                description='60% товаров с нулевыми остатками',
                affected_records=150,
                detected_at=datetime.now(),
                details={'zero_ratio': 0.6}
            ),
            Anomaly(
                type=AnomalyType.MASSIVE_STOCK_CHANGE,
                severity='medium',
                source='Ozon',
                description='Массовые изменения у 25 товаров',
                affected_records=25,
                detected_at=datetime.now(),
                details={'change_threshold': 0.5}
            ),
            Anomaly(
                type=AnomalyType.DUPLICATE_RECORDS,
                severity='low',
                source='Wildberries',
                description='Обнаружено 8 дублирующихся записей',
                affected_records=8,
                detected_at=datetime.now(),
                details={'duplicate_count': 8}
            )
        ]
        
        # Отправляем алерты для всех аномалий
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            sent_count = 0
            for anomaly in anomalies:
                success = self.alert_manager.send_anomaly_alert(
                    anomaly_type=anomaly.type.value,
                    source=anomaly.source,
                    description=anomaly.description,
                    affected_records=anomaly.affected_records,
                    severity=anomaly.severity
                )
                if success:
                    sent_count += 1
            
            # Проверяем, что все алерты отправлены
            self.assertEqual(sent_count, len(anomalies))
            self.assertEqual(mock_send_alert.call_count, len(anomalies))
    
    def test_cooldown_prevents_spam_alerts(self):
        """Тест предотвращения спама алертов через cooldown."""
        # Настраиваем короткий cooldown для тестирования
        self.alert_manager.alert_cooldowns[NotificationType.SYNC_FAILURE] = timedelta(seconds=5)
        
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            # Отправляем первый алерт
            success1 = self.alert_manager.send_sync_failure_alert(
                source='Ozon',
                error_message='First error',
                failure_count=1
            )
            
            # Отправляем второй алерт сразу (должен быть заблокирован cooldown)
            success2 = self.alert_manager.send_sync_failure_alert(
                source='Ozon',
                error_message='Second error',
                failure_count=2
            )
            
            # Проверяем результаты
            self.assertTrue(success1)  # Первый алерт отправлен
            self.assertFalse(success2)  # Второй алерт заблокирован
    
    def test_error_handling_in_monitoring_pipeline(self):
        """Тест обработки ошибок в pipeline мониторинга."""
        # Настраиваем mock для генерации ошибки в мониторе
        self.mock_cursor.execute.side_effect = Exception("Database connection lost")
        
        # Выполняем проверку здоровья (должна обработать ошибку)
        health_report = self.monitor.check_sync_health()
        
        # Проверяем, что система корректно обработала ошибку
        self.assertIn(health_report.overall_status, [HealthStatus.UNKNOWN, HealthStatus.CRITICAL])
        self.assertTrue(any("Ошибка мониторинга" in rec for rec in health_report.recommendations))
        
        # Пытаемся отправить алерт об ошибке мониторинга
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            # Отправляем алерт о проблемах с мониторингом
            success = self.alert_manager.send_system_health_alert(
                overall_status=health_report.overall_status.value,
                sources_status={},
                anomalies_count=0
            )
            
            self.assertTrue(success)
            mock_send_alert.assert_called_once()
    
    def test_performance_monitoring_integration(self):
        """Тест интеграции мониторинга производительности."""
        # Настраиваем mock данные с проблемами производительности
        self.mock_cursor.fetchall.side_effect = [
            # Медленные синхронизации
            [('success', 10, 120.0, 1000, datetime.now())],  # 2 минуты на синхронизацию
            # Большое количество обработанных записей
            []
        ]
        
        # Рассчитываем метрики
        metrics = self.monitor._calculate_source_metrics('Ozon')
        
        # Проверяем метрики производительности
        self.assertEqual(metrics.avg_duration_seconds, 120.0)
        self.assertEqual(metrics.total_records_processed, 1000)
        
        # Если синхронизация слишком медленная, отправляем алерт
        if metrics.avg_duration_seconds > 60:  # Более минуты
            with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
                mock_send_alert.return_value = True
                
                # Создаем кастомный алерт о производительности
                alert = Alert(
                    level=AlertLevel.WARNING,
                    type=NotificationType.SYSTEM_HEALTH,
                    title="Медленная синхронизация",
                    message=f"Синхронизация Ozon занимает {metrics.avg_duration_seconds} секунд",
                    source="Ozon",
                    details={'avg_duration': metrics.avg_duration_seconds}
                )
                
                success = self.alert_manager.send_alert(alert)
                self.assertTrue(success)
                mock_send_alert.assert_called_once()
    
    def test_comprehensive_monitoring_workflow(self):
        """Тест полного цикла мониторинга и алертинга."""
        # Настраиваем комплексные mock данные
        self.mock_cursor.fetchall.side_effect = [
            # Метрики Ozon - проблемы
            [('failed', 5, 0, 0, datetime.now())],
            # Метрики Wildberries - норма
            [('success', 10, 30.0, 1000, datetime.now())],
            # Аномалии для Ozon
            [('Ozon', 100, 80)],  # zero stock
            [],  # massive changes
            [('Ozon', 50, 100)],  # missing products
            [],  # duplicates
            [('Ozon', 5)],  # negative stock
            [('Ozon', datetime.now() - timedelta(hours=8))],  # stale data
            [('Ozon', 15, 'API errors')],  # api errors
            # Аномалии для Wildberries (все пустые)
            [], [], [], [], [], [], [],
        ]
        
        # Выполняем полную проверку системы
        health_report = self.monitor.check_sync_health()
        
        # Проверяем, что обнаружены проблемы
        self.assertNotEqual(health_report.overall_status, HealthStatus.HEALTHY)
        self.assertGreater(len(health_report.anomalies), 0)
        
        # Отправляем алерты на основе результатов мониторинга
        with patch.object(self.alert_manager, 'send_alert') as mock_send_alert:
            mock_send_alert.return_value = True
            
            alerts_sent = 0
            
            # Системный алерт
            sources_status = {source: data.get('health_status', HealthStatus.UNKNOWN).value 
                            for source, data in health_report.sources.items()}
            
            self.alert_manager.send_system_health_alert(
                overall_status=health_report.overall_status.value,
                sources_status=sources_status,
                anomalies_count=len(health_report.anomalies)
            )
            alerts_sent += 1
            
            # Алерты по аномалиям
            for anomaly in health_report.anomalies:
                self.alert_manager.send_anomaly_alert(
                    anomaly_type=anomaly.type.value,
                    source=anomaly.source,
                    description=anomaly.description,
                    affected_records=anomaly.affected_records,
                    severity=anomaly.severity
                )
                alerts_sent += 1
            
            # Проверяем, что все алерты отправлены
            self.assertEqual(mock_send_alert.call_count, alerts_sent)
            self.assertGreater(alerts_sent, 1)  # Минимум системный + аномалии


class TestMonitoringConfiguration(unittest.TestCase):
    """Тесты конфигурации системы мониторинга."""
    
    def test_threshold_configuration(self):
        """Тест настройки пороговых значений."""
        monitor = SyncMonitor(Mock(), Mock())
        
        # Проверяем значения по умолчанию
        self.assertEqual(monitor.thresholds['data_freshness_hours'], 6)
        self.assertEqual(monitor.thresholds['success_rate_threshold'], 0.8)
        self.assertEqual(monitor.thresholds['massive_change_threshold'], 0.5)
        self.assertEqual(monitor.thresholds['zero_stock_threshold'], 0.3)
        self.assertEqual(monitor.thresholds['max_error_count_24h'], 10)
        
        # Изменяем пороги
        monitor.thresholds['data_freshness_hours'] = 12
        monitor.thresholds['success_rate_threshold'] = 0.9
        
        # Проверяем, что изменения применились
        self.assertEqual(monitor.thresholds['data_freshness_hours'], 12)
        self.assertEqual(monitor.thresholds['success_rate_threshold'], 0.9)
    
    def test_alert_cooldown_configuration(self):
        """Тест настройки cooldown для алертов."""
        alert_manager = AlertManager()
        
        # Проверяем значения по умолчанию
        self.assertIn(NotificationType.SYNC_FAILURE, alert_manager.alert_cooldowns)
        self.assertIn(NotificationType.STALE_DATA, alert_manager.alert_cooldowns)
        
        # Изменяем cooldown
        original_cooldown = alert_manager.alert_cooldowns[NotificationType.SYNC_FAILURE]
        alert_manager.alert_cooldowns[NotificationType.SYNC_FAILURE] = timedelta(minutes=30)
        
        # Проверяем изменение
        new_cooldown = alert_manager.alert_cooldowns[NotificationType.SYNC_FAILURE]
        self.assertNotEqual(original_cooldown, new_cooldown)
        self.assertEqual(new_cooldown, timedelta(minutes=30))


if __name__ == '__main__':
    # Настройка логирования для тестов
    import logging
    logging.basicConfig(level=logging.WARNING)
    
    # Запуск тестов
    unittest.main(verbosity=2)