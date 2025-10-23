#!/usr/bin/env python3
"""
Интеграционный модуль для мониторинга и уведомлений системы синхронизации.

Объединяет SyncMonitor и AlertManager для автоматического мониторинга
состояния системы и отправки уведомлений при обнаружении проблем.

Автор: ETL System
Дата: 06 января 2025
"""

import logging
import time
try:
    import schedule
except ImportError:
    schedule = None
from datetime import datetime, timedelta, date
from typing import Dict, Any, List, Optional
from dataclasses import dataclass

# Импортируем наши модули
try:
    from sync_monitor import SyncMonitor, HealthStatus, AnomalyType
    from alert_manager import AlertManager, AlertLevel, NotificationType, Alert
    from sync_logger import SyncLogger
except ImportError as e:
    print(f"❌ Ошибка импорта модулей: {e}")
    print("Убедитесь, что файлы sync_monitor.py и alert_manager.py находятся в той же директории")
    exit(1)


@dataclass
class MonitoringConfig:
    """Конфигурация мониторинга."""
    health_check_interval_minutes: int = 30
    anomaly_check_interval_minutes: int = 60
    weekly_report_day: str = "monday"  # день недели для еженедельного отчета
    weekly_report_time: str = "09:00"  # время отправки еженедельного отчета
    stale_data_threshold_hours: int = 6
    critical_error_threshold: int = 5
    enable_auto_monitoring: bool = True


class MonitoringIntegration:
    """
    Интеграционный класс для автоматического мониторинга и уведомлений.
    
    Обеспечивает:
    - Автоматическую проверку состояния системы
    - Детекцию аномалий и отправку уведомлений
    - Еженедельные отчеты о состоянии системы
    - Мониторинг устаревших данных
    """
    
    def __init__(self, db_cursor, db_connection, config: Optional[MonitoringConfig] = None):
        """
        Инициализация интеграционного модуля.
        
        Args:
            db_cursor: Курсор базы данных
            db_connection: Соединение с базой данных
            config: Конфигурация мониторинга
        """
        self.cursor = db_cursor
        self.connection = db_connection
        self.config = config or MonitoringConfig()
        
        # Инициализируем компоненты
        self.monitor = SyncMonitor(db_cursor, db_connection, "MonitoringIntegration")
        self.alert_manager = AlertManager(db_cursor, db_connection, "MonitoringIntegration")
        
        self.logger = logging.getLogger("MonitoringIntegration")
        
        # Счетчики для отслеживания состояния
        self.last_health_check = None
        self.consecutive_errors = {}
        self.last_weekly_report = None
        
        # Настраиваем расписание если включен автоматический мониторинг
        if self.config.enable_auto_monitoring:
            self._setup_monitoring_schedule()
    
    def _setup_monitoring_schedule(self):
        """Настройка расписания автоматического мониторинга."""
        try:
            if not schedule:
                self.logger.warning("⚠️ Модуль schedule недоступен, автоматическое расписание отключено")
                return
            
            # Проверка состояния системы
            schedule.every(self.config.health_check_interval_minutes).minutes.do(
                self._scheduled_health_check
            )
            
            # Детекция аномалий
            schedule.every(self.config.anomaly_check_interval_minutes).minutes.do(
                self._scheduled_anomaly_check
            )
            
            # Еженедельный отчет
            getattr(schedule.every(), self.config.weekly_report_day).at(
                self.config.weekly_report_time
            ).do(self._scheduled_weekly_report)
            
            self.logger.info("📅 Расписание мониторинга настроено")
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка настройки расписания: {e}")
    
    def run_monitoring_cycle(self):
        """Запуск одного цикла мониторинга."""
        self.logger.info("🔍 Запуск цикла мониторинга")
        
        try:
            # Проверяем состояние системы
            health_report = self.monitor.check_sync_health()
            self._process_health_report(health_report)
            
            # Детектируем аномалии
            anomalies = self.monitor.detect_data_anomalies()
            self._process_anomalies(anomalies)
            
            # Проверяем устаревшие данные
            self._check_stale_data()
            
            self.logger.info("✅ Цикл мониторинга завершен")
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка цикла мониторинга: {e}")
            
            # Отправляем алерт о проблеме с мониторингом
            self.alert_manager.send_alert(
                Alert(
                    level=AlertLevel.ERROR,
                    type=NotificationType.SYSTEM_HEALTH,
                    title="Ошибка системы мониторинга",
                    message=f"Произошла ошибка при выполнении цикла мониторинга: {str(e)}",
                    details={'error': str(e), 'timestamp': datetime.now().isoformat()}
                )
            )
    
    def _process_health_report(self, health_report):
        """Обработка отчета о состоянии системы."""
        try:
            # Отправляем уведомление если статус критический или изменился
            if (health_report.overall_status == HealthStatus.CRITICAL or
                self._health_status_changed(health_report)):
                
                sources_status = {}
                for source, data in health_report.sources.items():
                    sources_status[source] = data.get('health_status', HealthStatus.UNKNOWN).value
                
                self.alert_manager.send_system_health_alert(
                    overall_status=health_report.overall_status.value,
                    sources_status=sources_status,
                    anomalies_count=len(health_report.anomalies)
                )
            
            # Обновляем последнюю проверку
            self.last_health_check = health_report
            
            # Логируем состояние
            self.logger.info(
                f"🏥 Состояние системы: {health_report.overall_status.value}, "
                f"аномалий: {len(health_report.anomalies)}"
            )
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка обработки отчета о состоянии: {e}")
    
    def _process_anomalies(self, anomalies):
        """Обработка обнаруженных аномалий."""
        try:
            for anomaly in anomalies:
                # Отправляем уведомление об аномалии
                self.alert_manager.send_anomaly_alert(
                    anomaly_type=anomaly.type.value,
                    source=anomaly.source,
                    description=anomaly.description,
                    affected_records=anomaly.affected_records,
                    severity=anomaly.severity
                )
                
                self.logger.warning(
                    f"⚠️ Аномалия {anomaly.type.value} в {anomaly.source}: "
                    f"{anomaly.description} (затронуто: {anomaly.affected_records})"
                )
            
            if not anomalies:
                self.logger.info("✅ Аномалий не обнаружено")
                
        except Exception as e:
            self.logger.error(f"❌ Ошибка обработки аномалий: {e}")
    
    def _check_stale_data(self):
        """Проверка устаревших данных."""
        try:
            sources = ['Ozon', 'Wildberries']
            
            for source in sources:
                # Получаем время последнего обновления
                last_update = self._get_last_sync_time(source)
                
                if last_update:
                    hours_since_update = (datetime.now() - last_update).total_seconds() / 3600
                    
                    if hours_since_update > self.config.stale_data_threshold_hours:
                        self.alert_manager.send_stale_data_alert(
                            source=source,
                            hours_since_update=hours_since_update
                        )
                        
                        self.logger.warning(
                            f"⏰ Устаревшие данные {source}: "
                            f"{hours_since_update:.1f} часов с последнего обновления"
                        )
                else:
                    # Если нет данных об обновлениях
                    self.alert_manager.send_stale_data_alert(
                        source=source,
                        hours_since_update=999  # Очень большое значение
                    )
                    
                    self.logger.warning(f"⏰ Нет данных об обновлениях для {source}")
                    
        except Exception as e:
            self.logger.error(f"❌ Ошибка проверки устаревших данных: {e}")
    
    def _get_last_sync_time(self, source: str) -> Optional[datetime]:
        """Получение времени последней синхронизации для источника."""
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            if is_sqlite:
                query = """
                    SELECT MAX(completed_at) as last_sync
                    FROM sync_logs 
                    WHERE source = ? 
                      AND status = 'success'
                      AND sync_type = 'inventory'
                """
                self.cursor.execute(query, (source,))
            else:
                query = """
                    SELECT MAX(completed_at) as last_sync
                    FROM sync_logs 
                    WHERE source = %s 
                      AND status = 'success'
                      AND sync_type = 'inventory'
                """
                self.cursor.execute(query, (source,))
            
            result = self.cursor.fetchone()
            
            if result:
                last_sync = result['last_sync'] if isinstance(result, dict) else result[0]
                
                if last_sync:
                    if isinstance(last_sync, str):
                        return datetime.fromisoformat(last_sync.replace('Z', '+00:00'))
                    return last_sync
            
            return None
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка получения времени последней синхронизации: {e}")
            return None
    
    def _health_status_changed(self, current_report) -> bool:
        """Проверка изменения статуса здоровья системы."""
        if not self.last_health_check:
            return True  # Первая проверка
        
        # Сравниваем общий статус
        if current_report.overall_status != self.last_health_check.overall_status:
            return True
        
        # Сравниваем статус источников
        for source in current_report.sources:
            current_status = current_report.sources[source].get('health_status')
            last_status = self.last_health_check.sources.get(source, {}).get('health_status')
            
            if current_status != last_status:
                return True
        
        return False
    
    def _scheduled_health_check(self):
        """Запланированная проверка состояния системы."""
        self.logger.info("⏰ Запланированная проверка состояния системы")
        
        try:
            health_report = self.monitor.check_sync_health()
            self._process_health_report(health_report)
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка запланированной проверки состояния: {e}")
    
    def _scheduled_anomaly_check(self):
        """Запланированная проверка аномалий."""
        self.logger.info("⏰ Запланированная проверка аномалий")
        
        try:
            anomalies = self.monitor.detect_data_anomalies()
            self._process_anomalies(anomalies)
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка запланированной проверки аномалий: {e}")
    
    def _scheduled_weekly_report(self):
        """Запланированная отправка еженедельного отчета."""
        self.logger.info("⏰ Генерация еженедельного отчета")
        
        try:
            # Генерируем отчет за неделю
            report_data = self.monitor.generate_sync_report(period_hours=168)  # 7 дней
            
            # Отправляем еженедельный отчет
            self.alert_manager.send_weekly_report(report_data)
            
            self.last_weekly_report = datetime.now()
            self.logger.info("📊 Еженедельный отчет отправлен")
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка генерации еженедельного отчета: {e}")
    
    def start_monitoring_daemon(self):
        """Запуск демона мониторинга."""
        self.logger.info("🚀 Запуск демона мониторинга")
        
        if not self.config.enable_auto_monitoring:
            self.logger.warning("⚠️ Автоматический мониторинг отключен в конфигурации")
            return
        
        try:
            while True:
                # Выполняем запланированные задачи
                if schedule:
                    schedule.run_pending()
                
                # Спим 1 минуту
                time.sleep(60)
                
        except KeyboardInterrupt:
            self.logger.info("🛑 Демон мониторинга остановлен пользователем")
        except Exception as e:
            self.logger.error(f"❌ Критическая ошибка демона мониторинга: {e}")
            
            # Отправляем критический алерт
            self.alert_manager.send_alert(
                Alert(
                    level=AlertLevel.CRITICAL,
                    type=NotificationType.SYSTEM_HEALTH,
                    title="Критическая ошибка демона мониторинга",
                    message=f"Демон мониторинга остановлен из-за критической ошибки: {str(e)}",
                    details={'error': str(e), 'timestamp': datetime.now().isoformat()}
                )
            )
    
    def generate_monitoring_report(self) -> Dict[str, Any]:
        """Генерация отчета о работе системы мониторинга."""
        try:
            # Получаем статистику алертов
            recent_alerts = self.alert_manager.get_recent_alerts(hours=24)
            
            # Группируем алерты по типам
            alerts_by_type = {}
            for alert in recent_alerts:
                alert_type = alert.get('alert_type', 'unknown')
                if alert_type not in alerts_by_type:
                    alerts_by_type[alert_type] = 0
                alerts_by_type[alert_type] += 1
            
            # Получаем текущее состояние системы
            health_report = self.monitor.check_sync_health()
            
            # Формируем отчет
            report = {
                "generated_at": datetime.now(),
                "monitoring_config": {
                    "health_check_interval_minutes": self.config.health_check_interval_minutes,
                    "anomaly_check_interval_minutes": self.config.anomaly_check_interval_minutes,
                    "stale_data_threshold_hours": self.config.stale_data_threshold_hours,
                    "auto_monitoring_enabled": self.config.enable_auto_monitoring
                },
                "current_system_health": {
                    "overall_status": health_report.overall_status.value,
                    "sources_count": len(health_report.sources),
                    "anomalies_count": len(health_report.anomalies),
                    "last_check_time": health_report.generated_at
                },
                "alerts_24h": {
                    "total_alerts": len(recent_alerts),
                    "by_type": alerts_by_type,
                    "critical_alerts": len([a for a in recent_alerts if a.get('alert_level') == 'critical']),
                    "error_alerts": len([a for a in recent_alerts if a.get('alert_level') == 'error'])
                },
                "monitoring_status": {
                    "last_health_check": self.last_health_check.generated_at if self.last_health_check else None,
                    "last_weekly_report": self.last_weekly_report,
                    "consecutive_errors": dict(self.consecutive_errors)
                }
            }
            
            return report
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка генерации отчета мониторинга: {e}")
            return {
                "error": str(e),
                "generated_at": datetime.now()
            }
    
    def test_monitoring_system(self) -> Dict[str, Any]:
        """Тестирование системы мониторинга."""
        self.logger.info("🧪 Тестирование системы мониторинга")
        
        results = {
            "monitor_test": False,
            "alert_manager_test": False,
            "integration_test": False,
            "notification_channels": {},
            "errors": []
        }
        
        try:
            # Тестируем SyncMonitor
            try:
                health_report = self.monitor.check_sync_health()
                results["monitor_test"] = True
                self.logger.info("✅ SyncMonitor работает корректно")
            except Exception as e:
                results["errors"].append(f"SyncMonitor error: {str(e)}")
                self.logger.error(f"❌ Ошибка SyncMonitor: {e}")
            
            # Тестируем AlertManager
            try:
                notification_results = self.alert_manager.test_notification_channels()
                results["alert_manager_test"] = True
                results["notification_channels"] = notification_results
                self.logger.info("✅ AlertManager работает корректно")
            except Exception as e:
                results["errors"].append(f"AlertManager error: {str(e)}")
                self.logger.error(f"❌ Ошибка AlertManager: {e}")
            
            # Тестируем интеграцию
            try:
                self.run_monitoring_cycle()
                results["integration_test"] = True
                self.logger.info("✅ Интеграция работает корректно")
            except Exception as e:
                results["errors"].append(f"Integration error: {str(e)}")
                self.logger.error(f"❌ Ошибка интеграции: {e}")
            
            # Общий результат
            results["overall_success"] = (
                results["monitor_test"] and 
                results["alert_manager_test"] and 
                results["integration_test"]
            )
            
            return results
            
        except Exception as e:
            results["errors"].append(f"Test system error: {str(e)}")
            self.logger.error(f"❌ Критическая ошибка тестирования: {e}")
            return results


# Пример использования
if __name__ == "__main__":
    # Демонстрация использования MonitoringIntegration
    import mysql.connector
    
    # Настройка логирования
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    
    try:
        # Подключение к БД
        connection = None
        cursor = None
        
        try:
            connection = mysql.connector.connect(
                host='localhost',
                database='test_db',
                user='test_user',
                password='test_password'
            )
            cursor = connection.cursor(dictionary=True)
            print("✅ Подключение к БД установлено")
        except:
            print("⚠️ Подключение к БД недоступно, работаем в демо режиме")
        
        # Создание конфигурации мониторинга
        config = MonitoringConfig(
            health_check_interval_minutes=5,  # Для демо - каждые 5 минут
            anomaly_check_interval_minutes=10,  # Для демо - каждые 10 минут
            enable_auto_monitoring=False  # Отключаем автоматический режим для демо
        )
        
        # Создание интеграционного модуля
        monitoring = MonitoringIntegration(cursor, connection, config)
        
        # Тестирование системы
        print("\n🧪 Тестирование системы мониторинга...")
        test_results = monitoring.test_monitoring_system()
        
        print(f"Общий результат: {'✅ Успешно' if test_results['overall_success'] else '❌ Ошибки'}")
        
        if test_results['errors']:
            print("Ошибки:")
            for error in test_results['errors']:
                print(f"  - {error}")
        
        # Запуск одного цикла мониторинга
        print("\n🔍 Запуск цикла мониторинга...")
        monitoring.run_monitoring_cycle()
        
        # Генерация отчета о мониторинге
        print("\n📊 Генерация отчета о мониторинге...")
        monitoring_report = monitoring.generate_monitoring_report()
        
        print(f"Статус системы: {monitoring_report['current_system_health']['overall_status']}")
        print(f"Алертов за 24ч: {monitoring_report['alerts_24h']['total_alerts']}")
        
        print("\n✅ Демонстрация завершена")
        
        # Для запуска демона раскомментируйте следующую строку:
        # monitoring.start_monitoring_daemon()
        
    except Exception as e:
        print(f"❌ Ошибка демонстрации: {e}")
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()