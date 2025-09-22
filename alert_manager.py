#!/usr/bin/env python3
"""
Система алертов и уведомлений для пополнения склада.
Генерирует и отправляет уведомления о критических остатках и других важных событиях.
"""

import sys
import os
import logging
import smtplib
import json
from datetime import datetime, timedelta
from typing import List, Dict, Optional
from dataclasses import dataclass
from enum import Enum
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db
from replenishment_recommender import ReplenishmentRecommendation, PriorityLevel

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class AlertType(Enum):
    """Типы алертов."""
    STOCKOUT_CRITICAL = "STOCKOUT_CRITICAL"
    STOCKOUT_WARNING = "STOCKOUT_WARNING"
    SLOW_MOVING = "SLOW_MOVING"
    OVERSTOCKED = "OVERSTOCKED"
    NO_SALES = "NO_SALES"


class AlertLevel(Enum):
    """Уровни алертов."""
    CRITICAL = "CRITICAL"
    HIGH = "HIGH"
    MEDIUM = "MEDIUM"
    LOW = "LOW"
    INFO = "INFO"


@dataclass
class Alert:
    """Алерт о состоянии запасов."""
    product_id: int
    sku: str
    product_name: str
    alert_type: AlertType
    alert_level: AlertLevel
    message: str
    current_stock: int
    days_until_stockout: Optional[int]
    recommended_action: str
    created_at: datetime


class AlertManager:
    """Класс для управления алертами и уведомлениями."""
    
    def __init__(self, connection=None):
        """
        Инициализация менеджера алертов.
        
        Args:
            connection: Подключение к базе данных (опционально)
        """
        self.connection = connection or connect_to_db()
        self.settings = self._load_notification_settings()
        
    def _load_notification_settings(self) -> Dict[str, any]:
        """Загружает настройки уведомлений."""
        try:
            cursor = self.connection.cursor(dictionary=True)
            cursor.execute("""
                SELECT setting_key, setting_value, setting_type 
                FROM replenishment_settings 
                WHERE category = 'NOTIFICATIONS' AND is_active = TRUE
            """)
            
            settings = {}
            for row in cursor.fetchall():
                key = row['setting_key']
                value = row['setting_value']
                setting_type = row['setting_type']
                
                if setting_type == 'BOOLEAN':
                    settings[key] = value.lower() in ('true', '1', 'yes')
                elif setting_type == 'JSON':
                    settings[key] = json.loads(value)
                else:
                    settings[key] = value
                    
            cursor.close()
            return settings
            
        except Exception as e:
            logger.error(f"Ошибка загрузки настроек уведомлений: {e}")
            return {
                'enable_email_alerts': False,
                'alert_email_recipients': []
            }
    
    def generate_alerts_from_recommendations(self, recommendations: List[ReplenishmentRecommendation]) -> List[Alert]:
        """
        Генерировать алерты на основе рекомендаций.
        
        Args:
            recommendations: Список рекомендаций по пополнению
            
        Returns:
            Список сгенерированных алертов
        """
        logger.info("🚨 Генерация алертов из рекомендаций")
        
        alerts = []
        
        for rec in recommendations:
            try:
                # Алерты о критических остатках
                if rec.priority_level == PriorityLevel.CRITICAL:
                    alert = Alert(
                        product_id=rec.product_id,
                        sku=rec.sku,
                        product_name=rec.product_name,
                        alert_type=AlertType.STOCKOUT_CRITICAL,
                        alert_level=AlertLevel.CRITICAL,
                        message=f"КРИТИЧЕСКИЙ ОСТАТОК! Товар {rec.sku} закончится через {rec.days_until_stockout or 'неизвестно'} дней. Текущий остаток: {rec.available_stock} шт.",
                        current_stock=rec.current_stock,
                        days_until_stockout=rec.days_until_stockout,
                        recommended_action=f"СРОЧНО заказать {rec.recommended_order_quantity} шт.",
                        created_at=datetime.now()
                    )
                    alerts.append(alert)
                
                elif rec.priority_level == PriorityLevel.HIGH:
                    alert = Alert(
                        product_id=rec.product_id,
                        sku=rec.sku,
                        product_name=rec.product_name,
                        alert_type=AlertType.STOCKOUT_WARNING,
                        alert_level=AlertLevel.HIGH,
                        message=f"Низкий остаток товара {rec.sku}. Остаток: {rec.available_stock} шт., закончится через {rec.days_until_stockout or 'неизвестно'} дней.",
                        current_stock=rec.current_stock,
                        days_until_stockout=rec.days_until_stockout,
                        recommended_action=f"Рекомендуется заказать {rec.recommended_order_quantity} шт.",
                        created_at=datetime.now()
                    )
                    alerts.append(alert)
                
                # Алерты о медленно движущихся товарах
                if rec.days_since_last_sale > 30 and rec.current_stock > 0:
                    alert = Alert(
                        product_id=rec.product_id,
                        sku=rec.sku,
                        product_name=rec.product_name,
                        alert_type=AlertType.SLOW_MOVING,
                        alert_level=AlertLevel.MEDIUM,
                        message=f"Медленно движущийся товар {rec.sku}. Нет продаж {rec.days_since_last_sale} дней, остаток: {rec.current_stock} шт.",
                        current_stock=rec.current_stock,
                        days_until_stockout=None,
                        recommended_action="Рассмотреть снижение цены или маркетинговые акции",
                        created_at=datetime.now()
                    )
                    alerts.append(alert)
                
                # Алерты об избыточных запасах
                if rec.inventory_turnover_days and rec.inventory_turnover_days > 90:
                    alert = Alert(
                        product_id=rec.product_id,
                        sku=rec.sku,
                        product_name=rec.product_name,
                        alert_type=AlertType.OVERSTOCKED,
                        alert_level=AlertLevel.LOW,
                        message=f"Избыточный запас товара {rec.sku}. Оборачиваемость: {rec.inventory_turnover_days} дней.",
                        current_stock=rec.current_stock,
                        days_until_stockout=None,
                        recommended_action="Рассмотреть снижение закупок или распродажу",
                        created_at=datetime.now()
                    )
                    alerts.append(alert)
                
            except Exception as e:
                logger.error(f"Ошибка генерации алерта для товара {rec.sku}: {e}")
                continue
        
        logger.info(f"✅ Сгенерировано {len(alerts)} алертов")
        return alerts
    
    def save_alerts_to_db(self, alerts: List[Alert]) -> bool:
        """
        Сохранить алерты в базу данных.
        
        Args:
            alerts: Список алертов для сохранения
            
        Returns:
            True если сохранение прошло успешно
        """
        if not alerts:
            logger.info("Нет алертов для сохранения")
            return True
        
        try:
            cursor = self.connection.cursor()
            
            insert_sql = """
                INSERT INTO replenishment_alerts (
                    product_id, sku, product_name, alert_type, alert_level,
                    message, current_stock, days_until_stockout, recommended_action
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
            
            batch_data = []
            for alert in alerts:
                batch_data.append((
                    alert.product_id,
                    alert.sku,
                    alert.product_name,
                    alert.alert_type.value,
                    alert.alert_level.value,
                    alert.message,
                    alert.current_stock,
                    alert.days_until_stockout,
                    alert.recommended_action
                ))
            
            cursor.executemany(insert_sql, batch_data)
            self.connection.commit()
            cursor.close()
            
            logger.info(f"✅ Сохранено {len(alerts)} алертов в базу данных")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка сохранения алертов: {e}")
            self.connection.rollback()
            return False
    
    def send_email_alerts(self, alerts: List[Alert]) -> bool:
        """
        Отправить email уведомления.
        
        Args:
            alerts: Список алертов для отправки
            
        Returns:
            True если отправка прошла успешно
        """
        if not self.settings.get('enable_email_alerts', False):
            logger.info("Email уведомления отключены")
            return True
        
        recipients = self.settings.get('alert_email_recipients', [])
        if not recipients:
            logger.warning("Нет получателей для email уведомлений")
            return True
        
        # Фильтруем только критические и высокоприоритетные алерты
        critical_alerts = [a for a in alerts if a.alert_level in [AlertLevel.CRITICAL, AlertLevel.HIGH]]
        
        if not critical_alerts:
            logger.info("Нет критических алертов для отправки")
            return True
        
        try:
            # Формируем email
            subject = f"🚨 Критические остатки на складе - {len(critical_alerts)} товаров"
            
            html_body = self._create_email_template(critical_alerts)
            
            # Отправляем email (заглушка - нужно настроить SMTP)
            logger.info(f"📧 Отправка email уведомления {len(recipients)} получателям")
            logger.info(f"   Тема: {subject}")
            logger.info(f"   Критических алертов: {len(critical_alerts)}")
            
            # TODO: Реализовать реальную отправку email
            # self._send_smtp_email(recipients, subject, html_body)
            
            return True
            
        except Exception as e:
            logger.error(f"Ошибка отправки email уведомлений: {e}")
            return False
    
    def _create_email_template(self, alerts: List[Alert]) -> str:
        """Создать HTML шаблон для email уведомления."""
        html = f"""
        <html>
        <head>
            <style>
                body {{ font-family: Arial, sans-serif; }}
                .critical {{ color: #dc3545; font-weight: bold; }}
                .high {{ color: #fd7e14; font-weight: bold; }}
                .medium {{ color: #ffc107; }}
                table {{ border-collapse: collapse; width: 100%; }}
                th, td {{ border: 1px solid #ddd; padding: 8px; text-align: left; }}
                th {{ background-color: #f2f2f2; }}
            </style>
        </head>
        <body>
            <h2>🚨 Уведомление о критических остатках</h2>
            <p>Дата анализа: {datetime.now().strftime('%d.%m.%Y %H:%M')}</p>
            
            <h3>Критические товары требующие пополнения:</h3>
            <table>
                <tr>
                    <th>SKU</th>
                    <th>Товар</th>
                    <th>Остаток</th>
                    <th>Дней до исчерпания</th>
                    <th>Рекомендуемое действие</th>
                </tr>
        """
        
        for alert in alerts:
            level_class = alert.alert_level.value.lower()
            html += f"""
                <tr>
                    <td>{alert.sku}</td>
                    <td>{alert.product_name[:50]}</td>
                    <td class="{level_class}">{alert.current_stock} шт.</td>
                    <td class="{level_class}">{alert.days_until_stockout or 'Н/Д'}</td>
                    <td>{alert.recommended_action}</td>
                </tr>
            """
        
        html += """
            </table>
            
            <p><strong>Рекомендуется немедленно принять меры по пополнению критических товаров!</strong></p>
            
            <hr>
            <small>Это автоматическое уведомление от системы управления запасами.</small>
        </body>
        </html>
        """
        
        return html
    
    def create_dashboard_alerts(self, alerts: List[Alert]) -> Dict[str, any]:
        """
        Создать алерты для отображения в дашборде.
        
        Args:
            alerts: Список алертов
            
        Returns:
            Структурированные данные для дашборда
        """
        try:
            # Группируем алерты по уровням
            alerts_by_level = {}
            for alert in alerts:
                level = alert.alert_level.value
                if level not in alerts_by_level:
                    alerts_by_level[level] = []
                alerts_by_level[level].append({
                    'sku': alert.sku,
                    'product_name': alert.product_name,
                    'message': alert.message,
                    'current_stock': alert.current_stock,
                    'days_until_stockout': alert.days_until_stockout,
                    'recommended_action': alert.recommended_action,
                    'created_at': alert.created_at.strftime('%Y-%m-%d %H:%M')
                })
            
            # Создаем сводку
            dashboard_data = {
                'total_alerts': len(alerts),
                'critical_count': len([a for a in alerts if a.alert_level == AlertLevel.CRITICAL]),
                'high_count': len([a for a in alerts if a.alert_level == AlertLevel.HIGH]),
                'medium_count': len([a for a in alerts if a.alert_level == AlertLevel.MEDIUM]),
                'alerts_by_level': alerts_by_level,
                'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            }
            
            return dashboard_data
            
        except Exception as e:
            logger.error(f"Ошибка создания алертов для дашборда: {e}")
            return {}
    
    def detect_critical_stock_levels(self) -> List[Alert]:
        """
        Обнаружить товары с критическими остатками.
        
        Returns:
            Список алертов о критических остатках
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Получаем товары с критическими остатками из последнего анализа
            cursor.execute("""
                SELECT 
                    product_id, sku, product_name, current_stock, available_stock,
                    days_until_stockout, recommended_order_quantity, priority_level,
                    daily_sales_rate_7d
                FROM replenishment_recommendations
                WHERE analysis_date = (
                    SELECT MAX(analysis_date) FROM replenishment_recommendations
                )
                AND priority_level IN ('CRITICAL', 'HIGH')
                ORDER BY urgency_score DESC
            """)
            
            results = cursor.fetchall()
            cursor.close()
            
            alerts = []
            
            for row in results:
                if row['priority_level'] == 'CRITICAL':
                    alert_type = AlertType.STOCKOUT_CRITICAL
                    alert_level = AlertLevel.CRITICAL
                    message = f"🚨 КРИТИЧЕСКИЙ ОСТАТОК! Товар {row['sku']} закончится через {row['days_until_stockout'] or 'неизвестно'} дней"
                else:
                    alert_type = AlertType.STOCKOUT_WARNING
                    alert_level = AlertLevel.HIGH
                    message = f"⚠️ Низкий остаток товара {row['sku']}. Остаток: {row['available_stock']} шт."
                
                alert = Alert(
                    product_id=row['product_id'],
                    sku=row['sku'],
                    product_name=row['product_name'],
                    alert_type=alert_type,
                    alert_level=alert_level,
                    message=message,
                    current_stock=row['current_stock'],
                    days_until_stockout=row['days_until_stockout'],
                    recommended_action=f"Заказать {row['recommended_order_quantity']} шт.",
                    created_at=datetime.now()
                )
                alerts.append(alert)
            
            logger.info(f"Обнаружено {len(alerts)} алертов о критических остатках")
            return alerts
            
        except Exception as e:
            logger.error(f"Ошибка обнаружения критических остатков: {e}")
            return []
    
    def detect_slow_moving_inventory(self, days_threshold: int = 30) -> List[Alert]:
        """
        Обнаружить медленно движущиеся товары.
        
        Args:
            days_threshold: Порог в днях без продаж
            
        Returns:
            Список алертов о медленно движущихся товарах
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            cursor.execute("""
                SELECT 
                    rr.product_id, rr.sku, rr.product_name, rr.current_stock,
                    rr.last_sale_date, rr.daily_sales_rate_30d
                FROM replenishment_recommendations rr
                WHERE rr.analysis_date = (
                    SELECT MAX(analysis_date) FROM replenishment_recommendations
                )
                AND (
                    rr.last_sale_date < DATE_SUB(CURDATE(), INTERVAL %s DAY)
                    OR rr.last_sale_date IS NULL
                )
                AND rr.current_stock > 0
                ORDER BY rr.last_sale_date ASC
            """, (days_threshold,))
            
            results = cursor.fetchall()
            cursor.close()
            
            alerts = []
            
            for row in results:
                days_since_sale = 999
                if row['last_sale_date']:
                    days_since_sale = (datetime.now().date() - row['last_sale_date']).days
                
                alert = Alert(
                    product_id=row['product_id'],
                    sku=row['sku'],
                    product_name=row['product_name'],
                    alert_type=AlertType.SLOW_MOVING,
                    alert_level=AlertLevel.MEDIUM,
                    message=f"🐌 Медленно движущийся товар {row['sku']}. Нет продаж {days_since_sale} дней.",
                    current_stock=row['current_stock'],
                    days_until_stockout=None,
                    recommended_action="Рассмотреть маркетинговые акции или снижение цены",
                    created_at=datetime.now()
                )
                alerts.append(alert)
            
            logger.info(f"Обнаружено {len(alerts)} медленно движущихся товаров")
            return alerts
            
        except Exception as e:
            logger.error(f"Ошибка обнаружения медленно движущихся товаров: {e}")
            return []
    
    def process_all_alerts(self) -> Dict[str, any]:
        """
        Обработать все типы алертов.
        
        Returns:
            Сводка по обработанным алертам
        """
        logger.info("🔄 Обработка всех типов алертов")
        
        try:
            # Генерируем разные типы алертов
            critical_alerts = self.detect_critical_stock_levels()
            slow_moving_alerts = self.detect_slow_moving_inventory()
            
            # Объединяем все алерты
            all_alerts = critical_alerts + slow_moving_alerts
            
            # Сохраняем в базу данных
            save_success = self.save_alerts_to_db(all_alerts)
            
            # Отправляем email уведомления
            email_success = self.send_email_alerts(all_alerts)
            
            # Создаем данные для дашборда
            dashboard_data = self.create_dashboard_alerts(all_alerts)
            
            # Формируем сводку
            summary = {
                'total_alerts': len(all_alerts),
                'critical_alerts': len([a for a in all_alerts if a.alert_level == AlertLevel.CRITICAL]),
                'high_alerts': len([a for a in all_alerts if a.alert_level == AlertLevel.HIGH]),
                'medium_alerts': len([a for a in all_alerts if a.alert_level == AlertLevel.MEDIUM]),
                'save_success': save_success,
                'email_success': email_success,
                'dashboard_data': dashboard_data,
                'processed_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            }
            
            logger.info(f"✅ Обработано алертов: {summary['total_alerts']}")
            logger.info(f"   - Критических: {summary['critical_alerts']}")
            logger.info(f"   - Высокоприоритетных: {summary['high_alerts']}")
            logger.info(f"   - Средних: {summary['medium_alerts']}")
            
            return summary
            
        except Exception as e:
            logger.error(f"Ошибка обработки алертов: {e}")
            return {}
    
    def get_active_alerts(self, limit: int = 50) -> List[Dict]:
        """
        Получить активные алерты из базы данных.
        
        Args:
            limit: Максимальное количество алертов
            
        Returns:
            Список активных алертов
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            cursor.execute("""
                SELECT 
                    id, product_id, sku, product_name, alert_type, alert_level,
                    message, current_stock, days_until_stockout, recommended_action,
                    status, created_at
                FROM replenishment_alerts
                WHERE status = 'NEW'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY 
                    CASE alert_level
                        WHEN 'CRITICAL' THEN 4
                        WHEN 'HIGH' THEN 3
                        WHEN 'MEDIUM' THEN 2
                        ELSE 1
                    END DESC,
                    created_at DESC
                LIMIT %s
            """, (limit,))
            
            results = cursor.fetchall()
            cursor.close()
            
            logger.info(f"Получено {len(results)} активных алертов")
            return results
            
        except Exception as e:
            logger.error(f"Ошибка получения активных алертов: {e}")
            return []
    
    def acknowledge_alert(self, alert_id: int, acknowledged_by: str) -> bool:
        """
        Подтвердить обработку алерта.
        
        Args:
            alert_id: ID алерта
            acknowledged_by: Кто подтвердил
            
        Returns:
            True если подтверждение прошло успешно
        """
        try:
            cursor = self.connection.cursor()
            
            cursor.execute("""
                UPDATE replenishment_alerts 
                SET status = 'ACKNOWLEDGED',
                    acknowledged_by = %s,
                    acknowledged_at = NOW()
                WHERE id = %s
            """, (acknowledged_by, alert_id))
            
            self.connection.commit()
            cursor.close()
            
            logger.info(f"✅ Алерт {alert_id} подтвержден пользователем {acknowledged_by}")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка подтверждения алерта {alert_id}: {e}")
            return False
    
    def close(self):
        """Закрыть все соединения."""
        if self.connection:
            self.connection.close()


def main():
    """Основная функция для тестирования системы алертов."""
    logger.info("🚨 Запуск системы алертов пополнения склада")
    
    alert_manager = None
    try:
        # Создаем менеджер алертов
        alert_manager = AlertManager()
        
        # Обрабатываем все алерты
        summary = alert_manager.process_all_alerts()
        
        if summary:
            print(f"\n📊 СВОДКА ПО АЛЕРТАМ:")
            print("=" * 50)
            print(f"Всего алертов: {summary['total_alerts']}")
            print(f"Критических: {summary['critical_alerts']}")
            print(f"Высокоприоритетных: {summary['high_alerts']}")
            print(f"Средних: {summary['medium_alerts']}")
            print(f"Сохранение в БД: {'✅' if summary['save_success'] else '❌'}")
            print(f"Отправка email: {'✅' if summary['email_success'] else '❌'}")
        
        # Получаем активные алерты
        active_alerts = alert_manager.get_active_alerts(limit=10)
        
        if active_alerts:
            print(f"\n🔔 АКТИВНЫЕ АЛЕРТЫ ({len(active_alerts)}):")
            print("=" * 80)
            
            for i, alert in enumerate(active_alerts, 1):
                level_emoji = {
                    'CRITICAL': '🚨',
                    'HIGH': '⚠️',
                    'MEDIUM': '📋',
                    'LOW': 'ℹ️',
                    'INFO': '💡'
                }.get(alert['alert_level'], '📋')
                
                print(f"\n{i}. {level_emoji} {alert['alert_level']} - {alert['sku']}")
                print(f"   {alert['message']}")
                print(f"   Действие: {alert['recommended_action']}")
                print(f"   Создан: {alert['created_at']}")
        
        print("\n✅ Обработка алертов завершена!")
        
    except Exception as e:
        logger.error(f"Ошибка в main(): {e}")
        
    finally:
        if alert_manager:
            alert_manager.close()


if __name__ == "__main__":
    main()