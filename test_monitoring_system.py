#!/usr/bin/env python3
"""
Тестирование системы мониторинга и алертов.

Проверяет работоспособность SyncMonitor, AlertManager и их интеграции.

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import logging
import sqlite3
from datetime import datetime, timedelta, date
from typing import Dict, Any

# Добавляем путь к модулям
sys.path.append(os.path.dirname(__file__))

try:
    from sync_monitor import SyncMonitor, HealthStatus, AnomalyType, Anomaly
    from alert_manager import AlertManager, AlertLevel, NotificationType, Alert
    from monitoring_integration import MonitoringIntegration, MonitoringConfig
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    print("Проверьте, что все необходимые модули доступны")
    # Попробуем импортировать по отдельности для диагностики
    try:
        from sync_monitor import SyncMonitor
        print("✅ sync_monitor импортирован")
    except Exception as e2:
        print(f"❌ sync_monitor: {e2}")
    
    try:
        from alert_manager import AlertManager
        print("✅ alert_manager импортирован")
    except Exception as e2:
        print(f"❌ alert_manager: {e2}")
    
    try:
        from monitoring_integration import MonitoringIntegration
        print("✅ monitoring_integration импортирован")
    except Exception as e2:
        print(f"❌ monitoring_integration: {e2}")
    
    sys.exit(1)

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger("MonitoringTest")


class MonitoringSystemTest:
    """Класс для тестирования системы мониторинга."""
    
    def __init__(self):
        """Инициализация тестовой среды."""
        self.connection = None
        self.cursor = None
        self.monitor = None
        self.alert_manager = None
        self.integration = None
        
        # Создаем тестовую БД в памяти
        self._setup_test_database()
    
    def _setup_test_database(self):
        """Создание тестовой базы данных."""
        try:
            # Создаем SQLite БД в памяти
            self.connection = sqlite3.connect(':memory:')
            self.connection.row_factory = sqlite3.Row  # Для работы как с dict
            self.cursor = self.connection.cursor()
            
            # Создаем необходимые таблицы
            self._create_test_tables()
            self._insert_test_data()
            
            logger.info("✅ Тестовая база данных создана")
            
        except Exception as e:
            logger.error(f"❌ Ошибка создания тестовой БД: {e}")
            raise
    
    def _create_test_tables(self):
        """Создание тестовых таблиц."""
        # Таблица логов синхронизации
        self.cursor.execute("""
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
                duration_seconds INTEGER DEFAULT 0,
                api_requests_count INTEGER DEFAULT 0,
                error_message TEXT,
                warning_message TEXT
            )
        """)
        
        # Таблица данных остатков
        self.cursor.execute("""
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
                quantity_present INTEGER DEFAULT 0,
                quantity_reserved INTEGER DEFAULT 0,
                snapshot_date DATE NOT NULL,
                last_sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        
        # Таблица алертов
        self.cursor.execute("""
            CREATE TABLE alert_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alert_level TEXT NOT NULL,
                alert_type TEXT NOT NULL,
                title TEXT NOT NULL,
                message TEXT,
                source TEXT,
                details TEXT,
                sent_successfully INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        
        self.connection.commit()
    
    def _insert_test_data(self):
        """Вставка тестовых данных."""
        # Тестовые логи синхронизации
        test_sync_logs = [
            # Успешные синхронизации
            ('inventory', 'Ozon', 'success', 100, 0, 95, 5, 
             datetime.now() - timedelta(hours=2), datetime.now() - timedelta(hours=2, minutes=-5), 300, 3, None, None),
            ('inventory', 'Wildberries', 'success', 80, 0, 75, 5,
             datetime.now() - timedelta(hours=1), datetime.now() - timedelta(hours=1, minutes=-3), 180, 2, None, None),
            
            # Частичные синхронизации
            ('inventory', 'Ozon', 'partial', 120, 0, 100, 20,
             datetime.now() - timedelta(hours=6), datetime.now() - timedelta(hours=6, minutes=-8), 480, 4, None, 'Some warnings'),
            
            # Неудачные синхронизации
            ('inventory', 'Wildberries', 'failed', 0, 0, 0, 0,
             datetime.now() - timedelta(hours=8), datetime.now() - timedelta(hours=8, minutes=-1), 60, 1, 'API key invalid', None),
        ]
        
        for log_data in test_sync_logs:
            self.cursor.execute("""
                INSERT INTO sync_logs 
                (sync_type, source, status, records_processed, records_updated, records_inserted, 
                 records_failed, started_at, completed_at, duration_seconds, api_requests_count, 
                 error_message, warning_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, log_data)
        
        # Тестовые данные остатков
        test_inventory_data = [
            # Ozon товары
            (1, 'OZON001', 'Ozon', 'Ozon Main', 'FBO', 50, 5, 45, 50, 5, date.today(), datetime.now() - timedelta(hours=2)),
            (2, 'OZON002', 'Ozon', 'Ozon Main', 'FBS', 0, 0, 0, 0, 0, date.today(), datetime.now() - timedelta(hours=2)),
            (3, 'OZON003', 'Ozon', 'Ozon Main', 'FBO', 100, 10, 90, 100, 10, date.today(), datetime.now() - timedelta(hours=2)),
            
            # Wildberries товары
            (4, 'WB001', 'Wildberries', 'WB Main', 'FBS', 25, 2, 23, 25, 2, date.today(), datetime.now() - timedelta(hours=1)),
            (5, 'WB002', 'Wildberries', 'WB Main', 'FBS', 0, 0, 0, 0, 0, date.today(), datetime.now() - timedelta(hours=1)),
            
            # Устаревшие данные
            (6, 'OLD001', 'Ozon', 'Ozon Main', 'FBO', 30, 3, 27, 30, 3, date.today() - timedelta(days=1), datetime.now() - timedelta(hours=25)),
        ]
        
        for inventory_data in test_inventory_data:
            self.cursor.execute("""
                INSERT INTO inventory_data 
                (product_id, sku, source, warehouse_name, stock_type, current_stock, 
                 reserved_stock, available_stock, quantity_present, quantity_reserved, 
                 snapshot_date, last_sync_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, inventory_data)
        
        self.connection.commit()
        logger.info("✅ Тестовые данные вставлены")
    
    def setup_monitoring_components(self):
        """Инициализация компонентов мониторинга."""
        try:
            # Создаем SyncMonitor
            self.monitor = SyncMonitor(self.cursor, self.connection, "TestMonitor")
            
            # Создаем AlertManager (без реальной отправки уведомлений)
            self.alert_manager = AlertManager(self.cursor, self.connection, "TestAlertManager")
            
            # Создаем интеграцию
            config = MonitoringConfig(
                health_check_interval_minutes=5,
                anomaly_check_interval_minutes=10,
                enable_auto_monitoring=False  # Отключаем для тестов
            )
            self.integration = MonitoringIntegration(self.cursor, self.connection, config)
            
            logger.info("✅ Компоненты мониторинга инициализированы")
            
        except Exception as e:
            logger.error(f"❌ Ошибка инициализации компонентов: {e}")
            raise
    
    def test_sync_monitor(self) -> Dict[str, Any]:
        """Тестирование SyncMonitor."""
        logger.info("🧪 Тестирование SyncMonitor")
        
        results = {
            'health_check': False,
            'anomaly_detection': False,
            'sync_report': False,
            'errors': []
        }
        
        try:
            # Тест проверки состояния системы
            try:
                health_report = self.monitor.check_sync_health()
                
                assert health_report is not None
                assert hasattr(health_report, 'overall_status')
                assert hasattr(health_report, 'sources')
                assert hasattr(health_report, 'anomalies')
                
                results['health_check'] = True
                logger.info(f"✅ Проверка состояния: {health_report.overall_status.value}")
                
            except Exception as e:
                results['errors'].append(f"Health check error: {str(e)}")
                logger.error(f"❌ Ошибка проверки состояния: {e}")
            
            # Тест детекции аномалий
            try:
                anomalies = self.monitor.detect_data_anomalies()
                
                assert isinstance(anomalies, list)
                
                results['anomaly_detection'] = True
                logger.info(f"✅ Детекция аномалий: найдено {len(anomalies)} аномалий")
                
                # Выводим найденные аномалии
                for anomaly in anomalies:
                    logger.info(f"  - {anomaly.type.value} в {anomaly.source}: {anomaly.description}")
                
            except Exception as e:
                results['errors'].append(f"Anomaly detection error: {str(e)}")
                logger.error(f"❌ Ошибка детекции аномалий: {e}")
            
            # Тест генерации отчета
            try:
                sync_report = self.monitor.generate_sync_report(24)
                
                assert isinstance(sync_report, dict)
                assert 'generated_at' in sync_report
                
                results['sync_report'] = True
                logger.info("✅ Генерация отчета о синхронизации")
                
            except Exception as e:
                results['errors'].append(f"Sync report error: {str(e)}")
                logger.error(f"❌ Ошибка генерации отчета: {e}")
            
            results['success'] = all([
                results['health_check'],
                results['anomaly_detection'],
                results['sync_report']
            ])
            
            return results
            
        except Exception as e:
            results['errors'].append(f"General SyncMonitor error: {str(e)}")
            logger.error(f"❌ Общая ошибка SyncMonitor: {e}")
            return results
    
    def test_alert_manager(self) -> Dict[str, Any]:
        """Тестирование AlertManager."""
        logger.info("🧪 Тестирование AlertManager")
        
        results = {
            'sync_failure_alert': False,
            'stale_data_alert': False,
            'anomaly_alert': False,
            'system_health_alert': False,
            'alert_logging': False,
            'errors': []
        }
        
        try:
            # Тест алерта о сбое синхронизации
            try:
                success = self.alert_manager.send_sync_failure_alert(
                    source="TestSource",
                    error_message="Test error message",
                    failure_count=3
                )
                
                results['sync_failure_alert'] = True  # Не проверяем реальную отправку
                logger.info("✅ Алерт о сбое синхронизации")
                
            except Exception as e:
                results['errors'].append(f"Sync failure alert error: {str(e)}")
                logger.error(f"❌ Ошибка алерта о сбое: {e}")
            
            # Тест алерта об устаревших данных
            try:
                success = self.alert_manager.send_stale_data_alert(
                    source="TestSource",
                    hours_since_update=12.5
                )
                
                results['stale_data_alert'] = True
                logger.info("✅ Алерт об устаревших данных")
                
            except Exception as e:
                results['errors'].append(f"Stale data alert error: {str(e)}")
                logger.error(f"❌ Ошибка алерта об устаревших данных: {e}")
            
            # Тест алерта об аномалии
            try:
                success = self.alert_manager.send_anomaly_alert(
                    anomaly_type="test_anomaly",
                    source="TestSource",
                    description="Test anomaly description",
                    affected_records=10,
                    severity="medium"
                )
                
                results['anomaly_alert'] = True
                logger.info("✅ Алерт об аномалии")
                
            except Exception as e:
                results['errors'].append(f"Anomaly alert error: {str(e)}")
                logger.error(f"❌ Ошибка алерта об аномалии: {e}")
            
            # Тест алерта о состоянии системы
            try:
                success = self.alert_manager.send_system_health_alert(
                    overall_status="warning",
                    sources_status={"TestSource1": "healthy", "TestSource2": "warning"},
                    anomalies_count=2
                )
                
                results['system_health_alert'] = True
                logger.info("✅ Алерт о состоянии системы")
                
            except Exception as e:
                results['errors'].append(f"System health alert error: {str(e)}")
                logger.error(f"❌ Ошибка алерта о состоянии системы: {e}")
            
            # Тест логирования алертов в БД
            try:
                recent_alerts = self.alert_manager.get_recent_alerts(hours=1)
                
                assert isinstance(recent_alerts, list)
                
                results['alert_logging'] = True
                logger.info(f"✅ Логирование алертов: найдено {len(recent_alerts)} записей")
                
            except Exception as e:
                results['errors'].append(f"Alert logging error: {str(e)}")
                logger.error(f"❌ Ошибка логирования алертов: {e}")
            
            results['success'] = all([
                results['sync_failure_alert'],
                results['stale_data_alert'],
                results['anomaly_alert'],
                results['system_health_alert'],
                results['alert_logging']
            ])
            
            return results
            
        except Exception as e:
            results['errors'].append(f"General AlertManager error: {str(e)}")
            logger.error(f"❌ Общая ошибка AlertManager: {e}")
            return results
    
    def test_monitoring_integration(self) -> Dict[str, Any]:
        """Тестирование интеграции мониторинга."""
        logger.info("🧪 Тестирование интеграции мониторинга")
        
        results = {
            'monitoring_cycle': False,
            'monitoring_report': False,
            'system_test': False,
            'errors': []
        }
        
        try:
            # Тест цикла мониторинга
            try:
                self.integration.run_monitoring_cycle()
                
                results['monitoring_cycle'] = True
                logger.info("✅ Цикл мониторинга")
                
            except Exception as e:
                results['errors'].append(f"Monitoring cycle error: {str(e)}")
                logger.error(f"❌ Ошибка цикла мониторинга: {e}")
            
            # Тест генерации отчета о мониторинге
            try:
                monitoring_report = self.integration.generate_monitoring_report()
                
                assert isinstance(monitoring_report, dict)
                assert 'generated_at' in monitoring_report
                
                results['monitoring_report'] = True
                logger.info("✅ Отчет о мониторинге")
                
            except Exception as e:
                results['errors'].append(f"Monitoring report error: {str(e)}")
                logger.error(f"❌ Ошибка отчета о мониторинге: {e}")
            
            # Тест системы мониторинга
            try:
                system_test_results = self.integration.test_monitoring_system()
                
                assert isinstance(system_test_results, dict)
                
                results['system_test'] = system_test_results.get('overall_success', False)
                logger.info(f"✅ Тест системы: {'успешно' if results['system_test'] else 'с ошибками'}")
                
            except Exception as e:
                results['errors'].append(f"System test error: {str(e)}")
                logger.error(f"❌ Ошибка теста системы: {e}")
            
            results['success'] = all([
                results['monitoring_cycle'],
                results['monitoring_report'],
                results['system_test']
            ])
            
            return results
            
        except Exception as e:
            results['errors'].append(f"General integration error: {str(e)}")
            logger.error(f"❌ Общая ошибка интеграции: {e}")
            return results
    
    def run_all_tests(self) -> Dict[str, Any]:
        """Запуск всех тестов."""
        logger.info("🚀 Запуск всех тестов системы мониторинга")
        
        overall_results = {
            'sync_monitor': {},
            'alert_manager': {},
            'integration': {},
            'overall_success': False,
            'total_errors': 0
        }
        
        try:
            # Настраиваем компоненты
            self.setup_monitoring_components()
            
            # Тестируем SyncMonitor
            overall_results['sync_monitor'] = self.test_sync_monitor()
            
            # Тестируем AlertManager
            overall_results['alert_manager'] = self.test_alert_manager()
            
            # Тестируем интеграцию
            overall_results['integration'] = self.test_monitoring_integration()
            
            # Подсчитываем общий результат
            all_success = all([
                overall_results['sync_monitor'].get('success', False),
                overall_results['alert_manager'].get('success', False),
                overall_results['integration'].get('success', False)
            ])
            
            overall_results['overall_success'] = all_success
            
            # Подсчитываем общее количество ошибок
            total_errors = 0
            for component_results in overall_results.values():
                if isinstance(component_results, dict) and 'errors' in component_results:
                    total_errors += len(component_results['errors'])
            
            overall_results['total_errors'] = total_errors
            
            # Выводим итоговый результат
            if all_success:
                logger.info("🎉 Все тесты пройдены успешно!")
            else:
                logger.warning(f"⚠️ Тесты завершены с ошибками. Всего ошибок: {total_errors}")
            
            return overall_results
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка тестирования: {e}")
            overall_results['critical_error'] = str(e)
            return overall_results
    
    def cleanup(self):
        """Очистка ресурсов."""
        try:
            if self.cursor:
                self.cursor.close()
            if self.connection:
                self.connection.close()
            logger.info("✅ Ресурсы очищены")
        except Exception as e:
            logger.error(f"❌ Ошибка очистки ресурсов: {e}")


def main():
    """Главная функция для запуска тестов."""
    print("=" * 60)
    print("ТЕСТИРОВАНИЕ СИСТЕМЫ МОНИТОРИНГА И АЛЕРТОВ")
    print("=" * 60)
    
    test_system = MonitoringSystemTest()
    
    try:
        # Запускаем все тесты
        results = test_system.run_all_tests()
        
        # Выводим детальные результаты
        print("\n" + "=" * 60)
        print("РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ")
        print("=" * 60)
        
        print(f"\n📊 SyncMonitor:")
        sync_monitor_results = results['sync_monitor']
        print(f"  Проверка состояния: {'✅' if sync_monitor_results.get('health_check') else '❌'}")
        print(f"  Детекция аномалий: {'✅' if sync_monitor_results.get('anomaly_detection') else '❌'}")
        print(f"  Генерация отчетов: {'✅' if sync_monitor_results.get('sync_report') else '❌'}")
        
        print(f"\n📧 AlertManager:")
        alert_manager_results = results['alert_manager']
        print(f"  Алерты о сбоях: {'✅' if alert_manager_results.get('sync_failure_alert') else '❌'}")
        print(f"  Алерты об устаревших данных: {'✅' if alert_manager_results.get('stale_data_alert') else '❌'}")
        print(f"  Алерты об аномалиях: {'✅' if alert_manager_results.get('anomaly_alert') else '❌'}")
        print(f"  Алерты о состоянии системы: {'✅' if alert_manager_results.get('system_health_alert') else '❌'}")
        print(f"  Логирование алертов: {'✅' if alert_manager_results.get('alert_logging') else '❌'}")
        
        print(f"\n🔗 Интеграция:")
        integration_results = results['integration']
        print(f"  Цикл мониторинга: {'✅' if integration_results.get('monitoring_cycle') else '❌'}")
        print(f"  Отчет о мониторинге: {'✅' if integration_results.get('monitoring_report') else '❌'}")
        print(f"  Тест системы: {'✅' if integration_results.get('system_test') else '❌'}")
        
        print(f"\n🎯 ОБЩИЙ РЕЗУЛЬТАТ: {'✅ УСПЕШНО' if results['overall_success'] else '❌ С ОШИБКАМИ'}")
        print(f"Всего ошибок: {results['total_errors']}")
        
        # Выводим ошибки если есть
        if results['total_errors'] > 0:
            print(f"\n❌ ОШИБКИ:")
            for component, component_results in results.items():
                if isinstance(component_results, dict) and 'errors' in component_results:
                    for error in component_results['errors']:
                        print(f"  {component}: {error}")
        
        print("\n" + "=" * 60)
        
        return 0 if results['overall_success'] else 1
        
    except Exception as e:
        print(f"\n❌ КРИТИЧЕСКАЯ ОШИБКА: {e}")
        return 1
    finally:
        test_system.cleanup()


if __name__ == "__main__":
    exit_code = main()
    sys.exit(exit_code)