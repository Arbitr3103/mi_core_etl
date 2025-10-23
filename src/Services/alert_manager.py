#!/usr/bin/env python3
"""
Система уведомлений и алертов для мониторинга синхронизации остатков.

Класс AlertManager для отправки email уведомлений при критических ошибках,
уведомлений о длительном отсутствии синхронизации и еженедельных отчетов
о состоянии системы.

Автор: ETL System
Дата: 06 января 2025
"""

import os
import logging
import smtplib
import json
from datetime import datetime, timedelta, date
from typing import List, Dict, Any, Optional
from dataclasses import dataclass
from enum import Enum
try:
    from email.mime.text import MimeText
    from email.mime.multipart import MimeMultipart
    from email.mime.base import MimeBase
    from email import encoders
except ImportError:
    # Fallback для случаев когда email модули недоступны
    MimeText = None
    MimeMultipart = None
    MimeBase = None
    encoders = None
import requests

# Импортируем конфигурацию
try:
    import config
except ImportError:
    # Если config не найден, используем значения по умолчанию
    class Config:
        EMAIL_ENABLED = False
        SMTP_SERVER = "smtp.gmail.com"
        SMTP_PORT = 587
        EMAIL_USER = ""
        EMAIL_PASSWORD = ""
        NOTIFICATION_RECIPIENTS = []
        TELEGRAM_ENABLED = False
        TELEGRAM_BOT_TOKEN = ""
        TELEGRAM_CHAT_ID = ""
    
    config = Config()


class AlertLevel(Enum):
    """Уровни алертов."""
    INFO = "info"
    WARNING = "warning"
    ERROR = "error"
    CRITICAL = "critical"


class NotificationType(Enum):
    """Типы уведомлений."""
    SYNC_FAILURE = "sync_failure"
    STALE_DATA = "stale_data"
    ANOMALY_DETECTED = "anomaly_detected"
    SYSTEM_HEALTH = "system_health"
    WEEKLY_REPORT = "weekly_report"
    API_ERROR = "api_error"


@dataclass
class Alert:
    """Модель алерта."""
    level: AlertLevel
    type: NotificationType
    title: str
    message: str
    source: Optional[str] = None
    details: Optional[Dict[str, Any]] = None
    timestamp: Optional[datetime] = None
    
    def __post_init__(self):
        if self.timestamp is None:
            self.timestamp = datetime.now()


@dataclass
class NotificationConfig:
    """Конфигурация уведомлений."""
    email_enabled: bool = False
    telegram_enabled: bool = False
    smtp_server: str = "smtp.gmail.com"
    smtp_port: int = 587
    email_user: str = ""
    email_password: str = ""
    recipients: List[str] = None
    telegram_bot_token: str = ""
    telegram_chat_id: str = ""
    
    def __post_init__(self):
        if self.recipients is None:
            self.recipients = []


class AlertManager:
    """
    Класс для управления уведомлениями и алертами системы синхронизации.
    
    Обеспечивает:
    - Отправку email уведомлений при критических ошибках
    - Уведомления о длительном отсутствии синхронизации
    - Еженедельные отчеты о состоянии системы
    - Интеграцию с Telegram для мгновенных уведомлений
    """
    
    def __init__(self, db_cursor=None, db_connection=None, logger_name: str = "AlertManager"):
        """
        Инициализация менеджера алертов.
        
        Args:
            db_cursor: Курсор базы данных (опционально)
            db_connection: Соединение с базой данных (опционально)
            logger_name: Имя логгера
        """
        self.cursor = db_cursor
        self.connection = db_connection
        self.logger = logging.getLogger(logger_name)
        
        # Загружаем конфигурацию из config.py или переменных окружения
        self.config = self._load_notification_config()
        
        # История отправленных уведомлений (для предотвращения спама)
        self.sent_alerts = {}
        
        # Настройки частоты уведомлений
        self.alert_cooldowns = {
            NotificationType.SYNC_FAILURE: timedelta(hours=1),
            NotificationType.STALE_DATA: timedelta(hours=6),
            NotificationType.ANOMALY_DETECTED: timedelta(hours=2),
            NotificationType.API_ERROR: timedelta(minutes=30),
            NotificationType.SYSTEM_HEALTH: timedelta(hours=12),
        }
    
    def _load_notification_config(self) -> NotificationConfig:
        """Загрузка конфигурации уведомлений."""
        try:
            return NotificationConfig(
                email_enabled=getattr(config, 'EMAIL_ENABLED', False),
                telegram_enabled=getattr(config, 'TELEGRAM_ENABLED', False),
                smtp_server=getattr(config, 'SMTP_SERVER', 'smtp.gmail.com'),
                smtp_port=getattr(config, 'SMTP_PORT', 587),
                email_user=getattr(config, 'EMAIL_USER', ''),
                email_password=getattr(config, 'EMAIL_PASSWORD', ''),
                recipients=getattr(config, 'NOTIFICATION_RECIPIENTS', []),
                telegram_bot_token=getattr(config, 'TELEGRAM_BOT_TOKEN', ''),
                telegram_chat_id=getattr(config, 'TELEGRAM_CHAT_ID', '')
            )
        except Exception as e:
            self.logger.warning(f"⚠️ Ошибка загрузки конфигурации уведомлений: {e}")
            return NotificationConfig()
    
    def send_alert(self, alert: Alert) -> bool:
        """
        Отправка алерта через доступные каналы.
        
        Args:
            alert: Объект алерта для отправки
            
        Returns:
            bool: True если алерт был отправлен успешно
        """
        # Проверяем cooldown для предотвращения спама
        if not self._should_send_alert(alert):
            self.logger.debug(f"🔇 Алерт {alert.type.value} пропущен из-за cooldown")
            return False
        
        success = False
        
        try:
            # Отправляем через email
            if self.config.email_enabled and self.config.recipients:
                email_success = self._send_email_alert(alert)
                success = success or email_success
            
            # Отправляем через Telegram
            if self.config.telegram_enabled and self.config.telegram_bot_token:
                telegram_success = self._send_telegram_alert(alert)
                success = success or telegram_success
            
            # Логируем в базу данных
            if self.cursor and self.connection:
                self._log_alert_to_db(alert, success)
            
            # Обновляем историю отправленных алертов
            if success:
                self._update_sent_alerts(alert)
                self.logger.info(f"📧 Алерт {alert.type.value} отправлен успешно")
            else:
                self.logger.warning(f"⚠️ Не удалось отправить алерт {alert.type.value}")
            
            return success
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка отправки алерта: {e}")
            return False
    
    def send_sync_failure_alert(self, source: str, error_message: str, 
                              failure_count: int = 1) -> bool:
        """
        Отправка уведомления о сбое синхронизации.
        
        Args:
            source: Источник данных (Ozon, Wildberries)
            error_message: Сообщение об ошибке
            failure_count: Количество последовательных сбоев
            
        Returns:
            bool: True если уведомление отправлено
        """
        level = AlertLevel.CRITICAL if failure_count > 3 else AlertLevel.ERROR
        
        alert = Alert(
            level=level,
            type=NotificationType.SYNC_FAILURE,
            title=f"Сбой синхронизации {source}",
            message=f"Синхронизация с {source} завершилась ошибкой.\n\n"
                   f"Количество последовательных сбоев: {failure_count}\n"
                   f"Ошибка: {error_message}\n\n"
                   f"Требуется проверка настроек API и состояния системы.",
            source=source,
            details={
                'error_message': error_message,
                'failure_count': failure_count,
                'timestamp': datetime.now().isoformat()
            }
        )
        
        return self.send_alert(alert)
    
    def send_stale_data_alert(self, source: str, hours_since_update: float) -> bool:
        """
        Отправка уведомления об устаревших данных.
        
        Args:
            source: Источник данных
            hours_since_update: Количество часов с последнего обновления
            
        Returns:
            bool: True если уведомление отправлено
        """
        level = AlertLevel.CRITICAL if hours_since_update > 24 else AlertLevel.WARNING
        
        alert = Alert(
            level=level,
            type=NotificationType.STALE_DATA,
            title=f"Устаревшие данные {source}",
            message=f"Данные от {source} не обновлялись {hours_since_update:.1f} часов.\n\n"
                   f"Последнее обновление: {datetime.now() - timedelta(hours=hours_since_update)}\n\n"
                   f"Рекомендуется проверить работу планировщика синхронизации.",
            source=source,
            details={
                'hours_since_update': hours_since_update,
                'last_update': (datetime.now() - timedelta(hours=hours_since_update)).isoformat()
            }
        )
        
        return self.send_alert(alert)
    
    def send_anomaly_alert(self, anomaly_type: str, source: str, 
                          description: str, affected_records: int,
                          severity: str = "medium") -> bool:
        """
        Отправка уведомления об обнаруженной аномалии.
        
        Args:
            anomaly_type: Тип аномалии
            source: Источник данных
            description: Описание аномалии
            affected_records: Количество затронутых записей
            severity: Уровень серьезности (low, medium, high, critical)
            
        Returns:
            bool: True если уведомление отправлено
        """
        # Определяем уровень алерта по серьезности
        level_mapping = {
            'low': AlertLevel.INFO,
            'medium': AlertLevel.WARNING,
            'high': AlertLevel.ERROR,
            'critical': AlertLevel.CRITICAL
        }
        level = level_mapping.get(severity, AlertLevel.WARNING)
        
        alert = Alert(
            level=level,
            type=NotificationType.ANOMALY_DETECTED,
            title=f"Аномалия в данных {source}",
            message=f"Обнаружена аномалия в данных от {source}.\n\n"
                   f"Тип аномалии: {anomaly_type}\n"
                   f"Описание: {description}\n"
                   f"Затронуто записей: {affected_records}\n"
                   f"Уровень серьезности: {severity}\n\n"
                   f"Рекомендуется проверить качество данных и настройки импорта.",
            source=source,
            details={
                'anomaly_type': anomaly_type,
                'affected_records': affected_records,
                'severity': severity,
                'description': description
            }
        )
        
        return self.send_alert(alert)
    
    def send_api_error_alert(self, source: str, endpoint: str, 
                           status_code: int, error_message: str) -> bool:
        """
        Отправка уведомления об ошибке API.
        
        Args:
            source: Источник данных
            endpoint: API endpoint
            status_code: HTTP статус код
            error_message: Сообщение об ошибке
            
        Returns:
            bool: True если уведомление отправлено
        """
        level = AlertLevel.CRITICAL if status_code in [401, 403] else AlertLevel.ERROR
        
        alert = Alert(
            level=level,
            type=NotificationType.API_ERROR,
            title=f"Ошибка API {source}",
            message=f"Ошибка при обращении к API {source}.\n\n"
                   f"Endpoint: {endpoint}\n"
                   f"HTTP статус: {status_code}\n"
                   f"Ошибка: {error_message}\n\n"
                   f"Возможные причины:\n"
                   f"- Неверные API ключи (401, 403)\n"
                   f"- Превышение лимитов (429)\n"
                   f"- Технические проблемы на стороне {source} (5xx)",
            source=source,
            details={
                'endpoint': endpoint,
                'status_code': status_code,
                'error_message': error_message
            }
        )
        
        return self.send_alert(alert)
    
    def send_weekly_report(self, report_data: Dict[str, Any]) -> bool:
        """
        Отправка еженедельного отчета о состоянии системы.
        
        Args:
            report_data: Данные отчета
            
        Returns:
            bool: True если отчет отправлен
        """
        # Формируем сводку отчета
        summary = self._format_weekly_summary(report_data)
        
        alert = Alert(
            level=AlertLevel.INFO,
            type=NotificationType.WEEKLY_REPORT,
            title="Еженедельный отчет о синхронизации",
            message=f"Еженедельный отчет о состоянии системы синхронизации остатков.\n\n{summary}",
            details=report_data
        )
        
        return self.send_alert(alert)
    
    def send_system_health_alert(self, overall_status: str, 
                               sources_status: Dict[str, str],
                               anomalies_count: int) -> bool:
        """
        Отправка уведомления о состоянии системы.
        
        Args:
            overall_status: Общий статус системы
            sources_status: Статус по источникам
            anomalies_count: Количество обнаруженных аномалий
            
        Returns:
            bool: True если уведомление отправлено
        """
        # Определяем уровень алерта по статусу
        level_mapping = {
            'healthy': AlertLevel.INFO,
            'warning': AlertLevel.WARNING,
            'critical': AlertLevel.CRITICAL,
            'unknown': AlertLevel.ERROR
        }
        level = level_mapping.get(overall_status, AlertLevel.WARNING)
        
        # Формируем сообщение
        status_text = "\n".join([f"- {source}: {status}" for source, status in sources_status.items()])
        
        alert = Alert(
            level=level,
            type=NotificationType.SYSTEM_HEALTH,
            title=f"Состояние системы: {overall_status}",
            message=f"Текущее состояние системы синхронизации: {overall_status}\n\n"
                   f"Статус по источникам:\n{status_text}\n\n"
                   f"Обнаружено аномалий: {anomalies_count}\n\n"
                   f"Время проверки: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
            details={
                'overall_status': overall_status,
                'sources_status': sources_status,
                'anomalies_count': anomalies_count
            }
        )
        
        return self.send_alert(alert)
    
    def _should_send_alert(self, alert: Alert) -> bool:
        """Проверка необходимости отправки алерта (cooldown)."""
        alert_key = f"{alert.type.value}_{alert.source or 'global'}"
        
        if alert_key in self.sent_alerts:
            last_sent = self.sent_alerts[alert_key]
            cooldown = self.alert_cooldowns.get(alert.type, timedelta(hours=1))
            
            if datetime.now() - last_sent < cooldown:
                return False
        
        return True
    
    def _update_sent_alerts(self, alert: Alert):
        """Обновление истории отправленных алертов."""
        alert_key = f"{alert.type.value}_{alert.source or 'global'}"
        self.sent_alerts[alert_key] = datetime.now()
    
    def _send_email_alert(self, alert: Alert) -> bool:
        """Отправка алерта по email."""
        try:
            # Проверяем доступность email модулей
            if not all([MimeText, MimeMultipart, MimeBase, encoders]):
                self.logger.warning("⚠️ Email модули недоступны, пропускаем отправку")
                return False
            
            # Создаем сообщение
            msg = MimeMultipart()
            msg['From'] = self.config.email_user
            msg['To'] = ', '.join(self.config.recipients)
            msg['Subject'] = f"[{alert.level.value.upper()}] {alert.title}"
            
            # Формируем тело сообщения
            body = self._format_email_body(alert)
            msg.attach(MimeText(body, 'plain', 'utf-8'))
            
            # Добавляем детали как JSON вложение для детальных отчетов
            if alert.type == NotificationType.WEEKLY_REPORT and alert.details:
                json_attachment = MimeBase('application', 'json')
                json_attachment.set_payload(json.dumps(alert.details, indent=2, default=str))
                encoders.encode_base64(json_attachment)
                json_attachment.add_header(
                    'Content-Disposition',
                    f'attachment; filename="weekly_report_{date.today()}.json"'
                )
                msg.attach(json_attachment)
            
            # Отправляем email
            server = smtplib.SMTP(self.config.smtp_server, self.config.smtp_port)
            server.starttls()
            server.login(self.config.email_user, self.config.email_password)
            
            text = msg.as_string()
            server.sendmail(self.config.email_user, self.config.recipients, text)
            server.quit()
            
            self.logger.info(f"📧 Email алерт отправлен: {alert.title}")
            return True
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка отправки email: {e}")
            return False
    
    def _send_telegram_alert(self, alert: Alert) -> bool:
        """Отправка алерта в Telegram."""
        try:
            # Формируем сообщение для Telegram
            message = self._format_telegram_message(alert)
            
            # Отправляем через Telegram Bot API
            url = f"https://api.telegram.org/bot{self.config.telegram_bot_token}/sendMessage"
            
            payload = {
                'chat_id': self.config.telegram_chat_id,
                'text': message,
                'parse_mode': 'Markdown'
            }
            
            response = requests.post(url, json=payload, timeout=10)
            response.raise_for_status()
            
            self.logger.info(f"📱 Telegram алерт отправлен: {alert.title}")
            return True
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка отправки Telegram: {e}")
            return False
    
    def _format_email_body(self, alert: Alert) -> str:
        """Форматирование тела email сообщения."""
        body = f"""
АЛЕРТ СИСТЕМЫ СИНХРОНИЗАЦИИ
{'=' * 50}

Уровень: {alert.level.value.upper()}
Тип: {alert.type.value}
Время: {alert.timestamp.strftime('%Y-%m-%d %H:%M:%S')}
Источник: {alert.source or 'Система'}

ОПИСАНИЕ:
{alert.message}
"""
        
        if alert.details:
            body += f"\n\nДЕТАЛИ:\n"
            for key, value in alert.details.items():
                body += f"{key}: {value}\n"
        
        body += f"""
{'=' * 50}
Это автоматическое уведомление системы мониторинга.
Для получения дополнительной информации обратитесь к логам системы.
"""
        
        return body
    
    def _format_telegram_message(self, alert: Alert) -> str:
        """Форматирование сообщения для Telegram."""
        # Эмодзи для разных уровней
        emoji_map = {
            AlertLevel.INFO: "ℹ️",
            AlertLevel.WARNING: "⚠️",
            AlertLevel.ERROR: "❌",
            AlertLevel.CRITICAL: "🚨"
        }
        
        emoji = emoji_map.get(alert.level, "📢")
        
        message = f"{emoji} *{alert.title}*\n\n"
        message += f"*Уровень:* {alert.level.value.upper()}\n"
        message += f"*Время:* {alert.timestamp.strftime('%H:%M:%S')}\n"
        
        if alert.source:
            message += f"*Источник:* {alert.source}\n"
        
        message += f"\n{alert.message}"
        
        # Ограничиваем длину сообщения для Telegram (4096 символов)
        if len(message) > 4000:
            message = message[:3950] + "\n\n... (сообщение обрезано)"
        
        return message
    
    def _format_weekly_summary(self, report_data: Dict[str, Any]) -> str:
        """Форматирование еженедельной сводки."""
        try:
            summary = "ЕЖЕНЕДЕЛЬНАЯ СВОДКА\n"
            summary += "=" * 30 + "\n\n"
            
            # Общая статистика
            if 'health_status' in report_data:
                health = report_data['health_status']
                summary += f"Общее состояние: {health.get('overall_status', 'unknown')}\n"
                
                if 'sources' in health:
                    summary += "\nСтатус источников:\n"
                    for source, data in health['sources'].items():
                        status = data.get('health_status', 'unknown')
                        summary += f"- {source}: {status}\n"
            
            # Статистика синхронизации
            if 'sync_statistics' in report_data:
                sync_stats = report_data['sync_statistics']
                summary += f"\nСтатистика синхронизации:\n"
                for source, stats in sync_stats.items():
                    success_count = stats.get('success', {}).get('count', 0)
                    failed_count = stats.get('failed', {}).get('count', 0)
                    total = success_count + failed_count
                    success_rate = (success_count / total * 100) if total > 0 else 0
                    summary += f"- {source}: {success_rate:.1f}% успешных ({success_count}/{total})\n"
            
            # Аномалии
            if 'anomalies' in report_data:
                anomalies = report_data['anomalies']
                if anomalies:
                    summary += f"\nОбнаружено аномалий: {len(anomalies)}\n"
                    # Группируем по типам
                    anomaly_types = {}
                    for anomaly in anomalies:
                        atype = anomaly.get('type', 'unknown')
                        anomaly_types[atype] = anomaly_types.get(atype, 0) + 1
                    
                    for atype, count in anomaly_types.items():
                        summary += f"- {atype}: {count}\n"
                else:
                    summary += "\nАномалий не обнаружено ✅\n"
            
            summary += f"\nОтчет сгенерирован: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}"
            
            return summary
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка форматирования сводки: {e}")
            return "Ошибка генерации сводки отчета"
    
    def _log_alert_to_db(self, alert: Alert, success: bool):
        """Логирование алерта в базу данных."""
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            # Создаем таблицу для алертов если не существует
            if is_sqlite:
                create_table_query = """
                    CREATE TABLE IF NOT EXISTS alert_logs (
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
                """
            else:
                create_table_query = """
                    CREATE TABLE IF NOT EXISTS alert_logs (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        alert_level VARCHAR(50) NOT NULL,
                        alert_type VARCHAR(100) NOT NULL,
                        title VARCHAR(255) NOT NULL,
                        message TEXT,
                        source VARCHAR(100),
                        details JSON,
                        sent_successfully BOOLEAN DEFAULT FALSE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_alert_type_time (alert_type, created_at),
                        INDEX idx_source_level (source, alert_level)
                    )
                """
            
            self.cursor.execute(create_table_query)
            
            # Вставляем запись об алерте
            if is_sqlite:
                insert_query = """
                    INSERT INTO alert_logs 
                    (alert_level, alert_type, title, message, source, details, sent_successfully)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                """
                details_json = json.dumps(alert.details) if alert.details else None
            else:
                insert_query = """
                    INSERT INTO alert_logs 
                    (alert_level, alert_type, title, message, source, details, sent_successfully)
                    VALUES (%s, %s, %s, %s, %s, %s, %s)
                """
                details_json = alert.details
            
            values = (
                alert.level.value,
                alert.type.value,
                alert.title,
                alert.message,
                alert.source,
                details_json,
                1 if success else 0
            )
            
            self.cursor.execute(insert_query, values)
            self.connection.commit()
            
            self.logger.debug(f"📝 Алерт записан в БД: {alert.title}")
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка записи алерта в БД: {e}")
            try:
                self.connection.rollback()
            except:
                pass
    
    def get_recent_alerts(self, hours: int = 24, alert_type: Optional[str] = None) -> List[Dict[str, Any]]:
        """
        Получение последних алертов из базы данных.
        
        Args:
            hours: Количество часов для выборки
            alert_type: Фильтр по типу алерта (опционально)
            
        Returns:
            List[Dict]: Список алертов
        """
        try:
            if not self.cursor:
                return []
            
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            if is_sqlite:
                query = """
                    SELECT alert_level, alert_type, title, message, source, 
                           details, sent_successfully, created_at
                    FROM alert_logs 
                    WHERE created_at >= datetime('now', '-{} hours')
                """.format(hours)
            else:
                query = """
                    SELECT alert_level, alert_type, title, message, source, 
                           details, sent_successfully, created_at
                    FROM alert_logs 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL %s HOUR)
                """
            
            params = [] if is_sqlite else [hours]
            
            if alert_type:
                if is_sqlite:
                    query += " AND alert_type = ?"
                else:
                    query += " AND alert_type = %s"
                params.append(alert_type)
            
            query += " ORDER BY created_at DESC LIMIT 100"
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            return results if results else []
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка получения алертов: {e}")
            return []
    
    def test_notification_channels(self) -> Dict[str, bool]:
        """
        Тестирование каналов уведомлений.
        
        Returns:
            Dict[str, bool]: Результаты тестирования каналов
        """
        results = {}
        
        # Тестовый алерт
        test_alert = Alert(
            level=AlertLevel.INFO,
            type=NotificationType.SYSTEM_HEALTH,
            title="Тест системы уведомлений",
            message="Это тестовое сообщение для проверки работы системы уведомлений.",
            details={'test': True}
        )
        
        # Тестируем email
        if self.config.email_enabled and self.config.recipients:
            try:
                results['email'] = self._send_email_alert(test_alert)
            except Exception as e:
                self.logger.error(f"❌ Ошибка тестирования email: {e}")
                results['email'] = False
        else:
            results['email'] = False
            self.logger.info("📧 Email уведомления отключены или не настроены")
        
        # Тестируем Telegram
        if self.config.telegram_enabled and self.config.telegram_bot_token:
            try:
                results['telegram'] = self._send_telegram_alert(test_alert)
            except Exception as e:
                self.logger.error(f"❌ Ошибка тестирования Telegram: {e}")
                results['telegram'] = False
        else:
            results['telegram'] = False
            self.logger.info("📱 Telegram уведомления отключены или не настроены")
        
        return results


# Пример использования
if __name__ == "__main__":
    # Демонстрация использования AlertManager
    import mysql.connector
    
    # Настройка логирования
    logging.basicConfig(level=logging.INFO)
    
    try:
        # Подключение к БД (опционально)
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
        except:
            print("⚠️ Подключение к БД недоступно, работаем без логирования в БД")
        
        # Создание менеджера алертов
        alert_manager = AlertManager(cursor, connection)
        
        # Тестирование каналов уведомлений
        print("🧪 Тестирование каналов уведомлений...")
        test_results = alert_manager.test_notification_channels()
        
        for channel, success in test_results.items():
            status = "✅ Работает" if success else "❌ Не работает"
            print(f"{channel}: {status}")
        
        # Примеры отправки различных типов алертов
        print("\n📧 Примеры алертов:")
        
        # Алерт о сбое синхронизации
        alert_manager.send_sync_failure_alert(
            source="Ozon",
            error_message="API ключ недействителен",
            failure_count=3
        )
        
        # Алерт об устаревших данных
        alert_manager.send_stale_data_alert(
            source="Wildberries",
            hours_since_update=8.5
        )
        
        # Алерт об аномалии
        alert_manager.send_anomaly_alert(
            anomaly_type="zero_stock_spike",
            source="Ozon",
            description="Более 50% товаров имеют нулевые остатки",
            affected_records=150,
            severity="high"
        )
        
        # Алерт о состоянии системы
        alert_manager.send_system_health_alert(
            overall_status="warning",
            sources_status={"Ozon": "healthy", "Wildberries": "warning"},
            anomalies_count=2
        )
        
        print("✅ Демонстрация завершена")
        
    except Exception as e:
        print(f"❌ Ошибка демонстрации: {e}")
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()