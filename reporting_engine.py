#!/usr/bin/env python3
"""
Модуль отчетности и аналитики для системы пополнения склада.
Создает различные отчеты и аналитические данные по запасам и продажам.
"""

import sys
import os
import logging
import json
import csv
from datetime import datetime, timedelta
from typing import Dict, List, Optional, Tuple
from dataclasses import dataclass
# import matplotlib.pyplot as plt  # Опционально для графиков
# import pandas as pd  # Опционально для анализа данных

# Простой шаблонизатор вместо jinja2
class SimpleTemplate:
    def __init__(self, template_str):
        self.template = template_str
    
    def render(self, **kwargs):
        result = self.template
        for key, value in kwargs.items():
            if isinstance(value, dict):
                for subkey, subvalue in value.items():
                    placeholder = f"{{{{ {key}.{subkey} }}}}"
                    result = result.replace(placeholder, str(subvalue))
            else:
                placeholder = f"{{{{ {key} }}}}"
                result = result.replace(placeholder, str(value))
        return result

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db
from replenishment_recommender import PriorityLevel

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


@dataclass
class InventoryMetrics:
    """Метрики по запасам."""
    total_products: int
    total_inventory_value: float
    low_stock_products: int
    zero_stock_products: int
    overstocked_products: int
    avg_inventory_turnover: float
    total_recommended_orders: int
    total_recommended_value: float


@dataclass
class SalesMetrics:
    """Метрики по продажам."""
    total_sales_volume: int
    total_sales_value: float
    avg_daily_sales: float
    fast_moving_products: int
    slow_moving_products: int
    no_sales_products: int
    sales_growth_rate: float


class ReportingEngine:
    """Класс для создания отчетов и аналитики."""
    
    def __init__(self, connection=None):
        """
        Инициализация движка отчетности.
        
        Args:
            connection: Подключение к базе данных (опционально)
        """
        self.connection = connection or connect_to_db()
        
    def get_inventory_metrics(self, source: Optional[str] = None, 
                            date_from: Optional[datetime] = None) -> InventoryMetrics:
        """
        Получить метрики по запасам.
        
        Args:
            source: Источник данных (опционально)
            date_from: Дата начала анализа (опционально)
            
        Returns:
            Объект с метриками по запасам
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Базовый запрос для получения данных о запасах
            base_query = """
                SELECT 
                    COUNT(*) as total_products,
                    SUM(CASE WHEN current_stock * COALESCE(cost_price, 0) THEN current_stock * cost_price ELSE 0 END) as total_value,
                    SUM(CASE WHEN available_stock <= min_stock_level THEN 1 ELSE 0 END) as low_stock_count,
                    SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) as zero_stock_count,
                    SUM(CASE WHEN inventory_turnover_days > 90 THEN 1 ELSE 0 END) as overstocked_count,
                    AVG(CASE WHEN inventory_turnover_days > 0 THEN inventory_turnover_days ELSE NULL END) as avg_turnover,
                    SUM(CASE WHEN recommended_order_quantity > 0 THEN 1 ELSE 0 END) as recommended_orders,
                    SUM(COALESCE(recommended_order_value, 0)) as recommended_value
                FROM replenishment_recommendations rr
                LEFT JOIN dim_products dp ON rr.product_id = dp.product_id
                WHERE rr.analysis_date = (
                    SELECT MAX(analysis_date) FROM replenishment_recommendations
                )
            """
            
            # Добавляем фильтр по источнику если указан
            if source:
                base_query += " AND rr.source = %s"
                cursor.execute(base_query, (source,))
            else:
                cursor.execute(base_query)
            
            result = cursor.fetchone()
            cursor.close()
            
            if result:
                metrics = InventoryMetrics(
                    total_products=result['total_products'] or 0,
                    total_inventory_value=float(result['total_value'] or 0),
                    low_stock_products=result['low_stock_count'] or 0,
                    zero_stock_products=result['zero_stock_count'] or 0,
                    overstocked_products=result['overstocked_count'] or 0,
                    avg_inventory_turnover=float(result['avg_turnover'] or 0),
                    total_recommended_orders=result['recommended_orders'] or 0,
                    total_recommended_value=float(result['recommended_value'] or 0)
                )
                
                logger.info(f"✅ Получены метрики по запасам: {metrics.total_products} товаров")
                return metrics
            else:
                logger.warning("⚠️  Нет данных для расчета метрик по запасам")
                return InventoryMetrics(0, 0.0, 0, 0, 0, 0.0, 0, 0.0)
                
        except Exception as e:
            logger.error(f"Ошибка получения метрик по запасам: {e}")
            return InventoryMetrics(0, 0.0, 0, 0, 0, 0.0, 0, 0.0)
    
    def get_sales_metrics(self, source: Optional[str] = None,
                         days_back: int = 30) -> SalesMetrics:
        """
        Получить метрики по продажам.
        
        Args:
            source: Источник данных (опционально)
            days_back: Количество дней для анализа
            
        Returns:
            Объект с метриками по продажам
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Получаем данные о продажах из рекомендаций
            query = """
                SELECT 
                    COUNT(*) as total_products,
                    SUM(daily_sales_rate_30d * 30) as total_volume_30d,
                    AVG(daily_sales_rate_30d) as avg_daily_sales,
                    SUM(CASE WHEN daily_sales_rate_7d > 5 THEN 1 ELSE 0 END) as fast_moving,
                    SUM(CASE WHEN daily_sales_rate_30d > 0 AND daily_sales_rate_30d < 1 THEN 1 ELSE 0 END) as slow_moving,
                    SUM(CASE WHEN daily_sales_rate_30d = 0 THEN 1 ELSE 0 END) as no_sales
                FROM replenishment_recommendations
                WHERE analysis_date = (
                    SELECT MAX(analysis_date) FROM replenishment_recommendations
                )
            """
            
            if source:
                query += " AND source = %s"
                cursor.execute(query, (source,))
            else:
                cursor.execute(query)
            
            result = cursor.fetchone()
            
            # Рассчитываем примерную стоимость продаж (упрощенно)
            total_sales_value = 0.0
            if result and result['total_volume_30d']:
                # Используем среднюю цену товаров для оценки
                cursor.execute("""
                    SELECT AVG(COALESCE(cost_price, 0) * 1.3) as avg_selling_price
                    FROM dim_products 
                    WHERE cost_price > 0
                """)
                price_result = cursor.fetchone()
                if price_result and price_result['avg_selling_price']:
                    total_sales_value = float(result['total_volume_30d']) * float(price_result['avg_selling_price'])
            
            cursor.close()
            
            if result:
                metrics = SalesMetrics(
                    total_sales_volume=int(result['total_volume_30d'] or 0),
                    total_sales_value=total_sales_value,
                    avg_daily_sales=float(result['avg_daily_sales'] or 0),
                    fast_moving_products=result['fast_moving'] or 0,
                    slow_moving_products=result['slow_moving'] or 0,
                    no_sales_products=result['no_sales'] or 0,
                    sales_growth_rate=0.0  # TODO: Рассчитать рост продаж
                )
                
                logger.info(f"✅ Получены метрики по продажам: {metrics.total_sales_volume} шт за 30 дней")
                return metrics
            else:
                logger.warning("⚠️  Нет данных для расчета метрик по продажам")
                return SalesMetrics(0, 0.0, 0.0, 0, 0, 0, 0.0)
                
        except Exception as e:
            logger.error(f"Ошибка получения метрик по продажам: {e}")
            return SalesMetrics(0, 0.0, 0.0, 0, 0, 0, 0.0)
    
    def get_top_recommendations(self, limit: int = 50, 
                              priority_filter: Optional[str] = None) -> List[Dict]:
        """
        Получить топ рекомендаций по пополнению.
        
        Args:
            limit: Максимальное количество рекомендаций
            priority_filter: Фильтр по приоритету ('CRITICAL', 'HIGH', etc.)
            
        Returns:
            Список рекомендаций
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            query = """
                SELECT 
                    sku, product_name, source, current_stock, available_stock,
                    recommended_order_quantity, recommended_order_value,
                    priority_level, urgency_score, days_until_stockout,
                    daily_sales_rate_7d, sales_trend, inventory_turnover_days
                FROM replenishment_recommendations
                WHERE analysis_date = (
                    SELECT MAX(analysis_date) FROM replenishment_recommendations
                )
            """
            
            params = []
            if priority_filter:
                query += " AND priority_level = %s"
                params.append(priority_filter)
            
            query += " ORDER BY urgency_score DESC, days_until_stockout ASC LIMIT %s"
            params.append(limit)
            
            cursor.execute(query, params)
            results = cursor.fetchall()
            cursor.close()
            
            logger.info(f"✅ Получено {len(results)} топ рекомендаций")
            return results
            
        except Exception as e:
            logger.error(f"Ошибка получения топ рекомендаций: {e}")
            return []
    
    def get_slow_moving_products(self, days_threshold: int = 30, 
                               limit: int = 100) -> List[Dict]:
        """
        Получить медленно движущиеся товары.
        
        Args:
            days_threshold: Порог в днях без продаж
            limit: Максимальное количество товаров
            
        Returns:
            Список медленно движущихся товаров
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            cursor.execute("""
                SELECT 
                    sku, product_name, source, current_stock,
                    last_sale_date, daily_sales_rate_30d,
                    inventory_turnover_days,
                    current_stock * COALESCE(dp.cost_price, 0) as inventory_value
                FROM replenishment_recommendations rr
                LEFT JOIN dim_products dp ON rr.product_id = dp.product_id
                WHERE rr.analysis_date = (
                    SELECT MAX(analysis_date) FROM replenishment_recommendations
                )
                AND (
                    rr.last_sale_date < DATE_SUB(CURDATE(), INTERVAL %s DAY)
                    OR rr.last_sale_date IS NULL
                )
                AND rr.current_stock > 0
                ORDER BY rr.last_sale_date ASC, inventory_value DESC
                LIMIT %s
            """, (days_threshold, limit))
            
            results = cursor.fetchall()
            cursor.close()
            
            # Добавляем расчет дней без продаж
            for result in results:
                if result['last_sale_date']:
                    days_since_sale = (datetime.now().date() - result['last_sale_date']).days
                    result['days_since_last_sale'] = days_since_sale
                else:
                    result['days_since_last_sale'] = 999
            
            logger.info(f"✅ Найдено {len(results)} медленно движущихся товаров")
            return results
            
        except Exception as e:
            logger.error(f"Ошибка получения медленно движущихся товаров: {e}")
            return []
    
    def get_overstocked_products(self, turnover_threshold: int = 90,
                               limit: int = 100) -> List[Dict]:
        """
        Получить товары с избыточными запасами.
        
        Args:
            turnover_threshold: Порог оборачиваемости в днях
            limit: Максимальное количество товаров
            
        Returns:
            Список товаров с избыточными запасами
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            cursor.execute("""
                SELECT 
                    sku, product_name, source, current_stock,
                    inventory_turnover_days, daily_sales_rate_30d,
                    current_stock * COALESCE(dp.cost_price, 0) as inventory_value,
                    CASE 
                        WHEN daily_sales_rate_30d > 0 
                        THEN current_stock - (daily_sales_rate_30d * 30)
                        ELSE current_stock
                    END as excess_stock
                FROM replenishment_recommendations rr
                LEFT JOIN dim_products dp ON rr.product_id = dp.product_id
                WHERE rr.analysis_date = (
                    SELECT MAX(analysis_date) FROM replenishment_recommendations
                )
                AND rr.inventory_turnover_days > %s
                AND rr.current_stock > 0
                ORDER BY inventory_value DESC
                LIMIT %s
            """, (turnover_threshold, limit))
            
            results = cursor.fetchall()
            cursor.close()
            
            logger.info(f"✅ Найдено {len(results)} товаров с избыточными запасами")
            return results
            
        except Exception as e:
            logger.error(f"Ошибка получения товаров с избыточными запасами: {e}")
            return []
    
    def create_comprehensive_report(self, source: Optional[str] = None) -> Dict[str, any]:
        """
        Создать комплексный отчет по запасам и продажам.
        
        Args:
            source: Источник данных (опционально)
            
        Returns:
            Словарь с данными отчета
        """
        logger.info("📊 Создание комплексного отчета")
        
        try:
            # Получаем все метрики
            inventory_metrics = self.get_inventory_metrics(source)
            sales_metrics = self.get_sales_metrics(source)
            
            # Получаем топ данные
            critical_recommendations = self.get_top_recommendations(20, 'CRITICAL')
            high_priority_recommendations = self.get_top_recommendations(30, 'HIGH')
            slow_moving_products = self.get_slow_moving_products(30, 50)
            overstocked_products = self.get_overstocked_products(90, 50)
            
            # Формируем отчет
            report = {
                'report_metadata': {
                    'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                    'source_filter': source or 'Все источники',
                    'report_type': 'Комплексный анализ запасов и продаж'
                },
                'inventory_metrics': {
                    'total_products': inventory_metrics.total_products,
                    'total_inventory_value': round(inventory_metrics.total_inventory_value, 2),
                    'low_stock_products': inventory_metrics.low_stock_products,
                    'zero_stock_products': inventory_metrics.zero_stock_products,
                    'overstocked_products': inventory_metrics.overstocked_products,
                    'avg_inventory_turnover_days': round(inventory_metrics.avg_inventory_turnover, 1),
                    'total_recommended_orders': inventory_metrics.total_recommended_orders,
                    'total_recommended_value': round(inventory_metrics.total_recommended_value, 2)
                },
                'sales_metrics': {
                    'total_sales_volume_30d': sales_metrics.total_sales_volume,
                    'total_sales_value_30d': round(sales_metrics.total_sales_value, 2),
                    'avg_daily_sales': round(sales_metrics.avg_daily_sales, 2),
                    'fast_moving_products': sales_metrics.fast_moving_products,
                    'slow_moving_products': sales_metrics.slow_moving_products,
                    'no_sales_products': sales_metrics.no_sales_products
                },
                'critical_recommendations': critical_recommendations,
                'high_priority_recommendations': high_priority_recommendations,
                'slow_moving_products': slow_moving_products,
                'overstocked_products': overstocked_products,
                'summary_insights': self._generate_insights(
                    inventory_metrics, sales_metrics, 
                    len(critical_recommendations), len(slow_moving_products)
                )
            }
            
            logger.info("✅ Комплексный отчет создан успешно")
            return report
            
        except Exception as e:
            logger.error(f"Ошибка создания комплексного отчета: {e}")
            return {'error': str(e)}
    
    def _generate_insights(self, inventory_metrics: InventoryMetrics,
                          sales_metrics: SalesMetrics,
                          critical_count: int, slow_moving_count: int) -> List[str]:
        """Генерация инсайтов на основе метрик."""
        insights = []
        
        # Анализ запасов
        if inventory_metrics.zero_stock_products > 0:
            insights.append(f"🚫 {inventory_metrics.zero_stock_products} товаров полностью закончились на складе")
        
        if inventory_metrics.low_stock_products > inventory_metrics.total_products * 0.1:
            insights.append(f"⚠️ {inventory_metrics.low_stock_products} товаров имеют низкий остаток (>10% от общего количества)")
        
        if inventory_metrics.avg_inventory_turnover > 60:
            insights.append(f"🐌 Средняя оборачиваемость запасов составляет {inventory_metrics.avg_inventory_turnover:.1f} дней (рекомендуется <60)")
        
        # Анализ продаж
        if sales_metrics.no_sales_products > sales_metrics.fast_moving_products:
            insights.append(f"📉 Товаров без продаж ({sales_metrics.no_sales_products}) больше чем быстро движущихся ({sales_metrics.fast_moving_products})")
        
        # Анализ рекомендаций
        if critical_count > 0:
            insights.append(f"🚨 {critical_count} товаров требуют критического пополнения")
        
        if slow_moving_count > 20:
            insights.append(f"🐌 {slow_moving_count} товаров медленно движутся - рассмотрите маркетинговые акции")
        
        # Финансовые инсайты
        if inventory_metrics.total_recommended_value > inventory_metrics.total_inventory_value * 0.3:
            insights.append(f"💰 Рекомендуемые закупки составляют {inventory_metrics.total_recommended_value/inventory_metrics.total_inventory_value*100:.1f}% от текущей стоимости запасов")
        
        return insights
    
    def export_report_to_json(self, report: Dict, filename: str) -> bool:
        """Экспорт отчета в JSON файл."""
        try:
            with open(filename, 'w', encoding='utf-8') as f:
                json.dump(report, f, ensure_ascii=False, indent=2, default=str)
            
            logger.info(f"✅ Отчет экспортирован в JSON: {filename}")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка экспорта в JSON: {e}")
            return False
    
    def export_report_to_csv(self, report: Dict, filename: str) -> bool:
        """Экспорт отчета в CSV файл."""
        try:
            with open(filename, 'w', newline='', encoding='utf-8') as f:
                writer = csv.writer(f)
                
                # Заголовок
                writer.writerow(['Отчет по запасам и продажам'])
                writer.writerow(['Дата создания', report['report_metadata']['generated_at']])
                writer.writerow([])
                
                # Метрики запасов
                writer.writerow(['МЕТРИКИ ЗАПАСОВ'])
                inv_metrics = report['inventory_metrics']
                for key, value in inv_metrics.items():
                    writer.writerow([key.replace('_', ' ').title(), value])
                writer.writerow([])
                
                # Метрики продаж
                writer.writerow(['МЕТРИКИ ПРОДАЖ'])
                sales_metrics = report['sales_metrics']
                for key, value in sales_metrics.items():
                    writer.writerow([key.replace('_', ' ').title(), value])
                writer.writerow([])
                
                # Критические рекомендации
                writer.writerow(['КРИТИЧЕСКИЕ РЕКОМЕНДАЦИИ'])
                writer.writerow(['SKU', 'Название', 'Остаток', 'К заказу', 'Срочность', 'Дней до исчерпания'])
                
                for rec in report['critical_recommendations']:
                    writer.writerow([
                        rec['sku'],
                        rec['product_name'][:50],
                        rec['current_stock'],
                        rec['recommended_order_quantity'],
                        rec['urgency_score'],
                        rec['days_until_stockout'] or 'Н/Д'
                    ])
            
            logger.info(f"✅ Отчет экспортирован в CSV: {filename}")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка экспорта в CSV: {e}")
            return False
    
    def create_html_report(self, report: Dict, filename: str) -> bool:
        """Создание HTML отчета."""
        try:
            html_template = """
            <!DOCTYPE html>
            <html lang="ru">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Отчет по пополнению склада</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; }
                    .metrics { display: flex; flex-wrap: wrap; gap: 20px; margin: 20px 0; }
                    .metric-card { background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px; min-width: 200px; }
                    .metric-value { font-size: 24px; font-weight: bold; color: #007bff; }
                    .table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    .table th { background-color: #f8f9fa; }
                    .critical { color: #dc3545; font-weight: bold; }
                    .high { color: #fd7e14; font-weight: bold; }
                    .insights { background-color: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>📊 Отчет по пополнению склада</h1>
                    <p><strong>Дата создания:</strong> {{ report.report_metadata.generated_at }}</p>
                    <p><strong>Источник:</strong> {{ report.report_metadata.source_filter }}</p>
                </div>
                
                <h2>📦 Метрики запасов</h2>
                <div class="metrics">
                    <div class="metric-card">
                        <div class="metric-value">{{ report.inventory_metrics.total_products }}</div>
                        <div>Всего товаров</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">{{ "{:,.0f}".format(report.inventory_metrics.total_inventory_value) }}</div>
                        <div>Стоимость запасов (руб)</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">{{ report.inventory_metrics.low_stock_products }}</div>
                        <div>Товаров с низким остатком</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">{{ report.inventory_metrics.total_recommended_orders }}</div>
                        <div>Рекомендуемых заказов</div>
                    </div>
                </div>
                
                <h2>📈 Метрики продаж</h2>
                <div class="metrics">
                    <div class="metric-card">
                        <div class="metric-value">{{ report.sales_metrics.total_sales_volume_30d }}</div>
                        <div>Продаж за 30 дней (шт)</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">{{ report.sales_metrics.fast_moving_products }}</div>
                        <div>Быстро движущихся товаров</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">{{ report.sales_metrics.slow_moving_products }}</div>
                        <div>Медленно движущихся товаров</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">{{ report.sales_metrics.no_sales_products }}</div>
                        <div>Товаров без продаж</div>
                    </div>
                </div>
                
                <div class="insights">
                    <h3>💡 Ключевые инсайты</h3>
                    <p>Инсайты будут добавлены в полную версию отчета</p>
                </div>
                
                <h2>🚨 Критические рекомендации</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Название товара</th>
                            <th>Остаток</th>
                            <th>К заказу</th>
                            <th>Срочность</th>
                            <th>Дней до исчерпания</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="6">Данные будут загружены из базы данных</td></tr>
                    </tbody>
                </table>
                
                <footer style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #666;">
                    <p>Отчет сгенерирован автоматически системой управления запасами</p>
                </footer>
            </body>
            </html>
            """
            
            template = SimpleTemplate(html_template)
            html_content = template.render(report=report)
            
            with open(filename, 'w', encoding='utf-8') as f:
                f.write(html_content)
            
            logger.info(f"✅ HTML отчет создан: {filename}")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка создания HTML отчета: {e}")
            return False
    
    def close(self):
        """Закрыть все соединения."""
        if self.connection:
            self.connection.close()


def main():
    """Основная функция для тестирования движка отчетности."""
    logger.info("📊 Запуск движка отчетности")
    
    reporting_engine = None
    try:
        # Создаем движок отчетности
        reporting_engine = ReportingEngine()
        
        # Создаем комплексный отчет
        report = reporting_engine.create_comprehensive_report()
        
        if 'error' not in report:
            print("\n📊 КОМПЛЕКСНЫЙ ОТЧЕТ ПО ЗАПАСАМ:")
            print("=" * 60)
            
            # Показываем основные метрики
            inv_metrics = report['inventory_metrics']
            sales_metrics = report['sales_metrics']
            
            print(f"📦 ЗАПАСЫ:")
            print(f"   Всего товаров: {inv_metrics['total_products']}")
            print(f"   Стоимость запасов: {inv_metrics['total_inventory_value']:,.2f} руб")
            print(f"   Товаров с низким остатком: {inv_metrics['low_stock_products']}")
            print(f"   Рекомендуемых заказов: {inv_metrics['total_recommended_orders']}")
            
            print(f"\n📈 ПРОДАЖИ:")
            print(f"   Продаж за 30 дней: {sales_metrics['total_sales_volume_30d']} шт")
            print(f"   Быстро движущихся: {sales_metrics['fast_moving_products']}")
            print(f"   Медленно движущихся: {sales_metrics['slow_moving_products']}")
            print(f"   Без продаж: {sales_metrics['no_sales_products']}")
            
            # Показываем инсайты
            if report['summary_insights']:
                print(f"\n💡 КЛЮЧЕВЫЕ ИНСАЙТЫ:")
                for insight in report['summary_insights']:
                    print(f"   {insight}")
            
            # Экспортируем отчеты
            timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
            
            # JSON отчет
            json_filename = f"comprehensive_report_{timestamp}.json"
            if reporting_engine.export_report_to_json(report, json_filename):
                print(f"\n✅ JSON отчет: {json_filename}")
            
            # CSV отчет
            csv_filename = f"comprehensive_report_{timestamp}.csv"
            if reporting_engine.export_report_to_csv(report, csv_filename):
                print(f"✅ CSV отчет: {csv_filename}")
            
            # HTML отчет
            html_filename = f"comprehensive_report_{timestamp}.html"
            if reporting_engine.create_html_report(report, html_filename):
                print(f"✅ HTML отчет: {html_filename}")
        
        else:
            print(f"❌ Ошибка создания отчета: {report['error']}")
        
        print("\n✅ Работа движка отчетности завершена!")
        
    except Exception as e:
        logger.error(f"Ошибка в main(): {e}")
        
    finally:
        if reporting_engine:
            reporting_engine.close()


if __name__ == "__main__":
    main()