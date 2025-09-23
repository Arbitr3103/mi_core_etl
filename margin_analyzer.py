#!/usr/bin/env python3
"""
Анализатор маржинальности и улучшенных рекомендаций по пополнению.
Учитывает прибыльность товаров при формировании рекомендаций.
"""

import sys
import os
import logging
from datetime import datetime, timedelta
from typing import List, Dict, Optional, Tuple
from dataclasses import dataclass
from enum import Enum

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from replenishment_db_connector import connect_to_replenishment_db as connect_to_db
from inventory_analyzer import InventoryAnalyzer, InventoryItem
from sales_velocity_calculator import SalesVelocityCalculator
from replenishment_recommender import ReplenishmentRecommender, PriorityLevel

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class MarginCategory(Enum):
    """Категории маржинальности."""
    HIGH_MARGIN = "HIGH_MARGIN"      # > 30%
    MEDIUM_MARGIN = "MEDIUM_MARGIN"  # 15-30%
    LOW_MARGIN = "LOW_MARGIN"        # 5-15%
    NEGATIVE_MARGIN = "NEGATIVE_MARGIN"  # < 5%


@dataclass
class MarginAnalysis:
    """Анализ маржинальности товара."""
    product_id: int
    sku: str
    product_name: str
    
    # Финансовые показатели
    cost_price: float
    selling_price: float
    margin_amount: float
    margin_percentage: float
    margin_category: MarginCategory
    
    # Показатели продаж
    daily_sales_rate: float
    monthly_revenue: float
    monthly_profit: float
    
    # Рекомендации
    priority_adjustment: float  # Корректировка приоритета на основе маржи
    recommended_investment: float  # Рекомендуемые инвестиции в запас
    roi_forecast: float  # Прогнозируемая рентабельность инвестиций


class MarginAnalyzer:
    """Анализатор маржинальности товаров."""
    
    def __init__(self, connection=None):
        """
        Инициализация анализатора маржинальности.
        
        Args:
            connection: Подключение к базе данных (опционально)
        """
        self.connection = connection or connect_to_db()
        self.inventory_analyzer = InventoryAnalyzer(self.connection)
        self.sales_calculator = SalesVelocityCalculator(self.connection)
        
    def analyze_product_margin(self, item: InventoryItem, 
                             selling_price: Optional[float] = None) -> MarginAnalysis:
        """
        Анализ маржинальности конкретного товара.
        
        Args:
            item: Товар для анализа
            selling_price: Цена продажи (если не указана, берется из БД)
            
        Returns:
            Анализ маржинальности товара
        """
        try:
            # Получаем цену продажи
            if not selling_price:
                selling_price = self._get_selling_price(item.product_id)
            
            # Рассчитываем маржу
            cost_price = item.cost_price or 0
            margin_amount = selling_price - cost_price
            margin_percentage = (margin_amount / selling_price * 100) if selling_price > 0 else 0
            
            # Определяем категорию маржинальности
            margin_category = self._categorize_margin(margin_percentage)
            
            # Получаем метрики продаж
            sales_metrics = self.sales_calculator.get_sales_metrics(item.product_id)
            daily_sales_rate = sales_metrics.daily_sales_rate_30d
            
            # Рассчитываем финансовые показатели
            monthly_revenue = daily_sales_rate * 30 * selling_price
            monthly_profit = daily_sales_rate * 30 * margin_amount
            
            # Корректировка приоритета на основе маржи
            priority_adjustment = self._calculate_priority_adjustment(margin_category, margin_percentage)
            
            # Рекомендуемые инвестиции в запас
            recommended_investment = self._calculate_recommended_investment(
                daily_sales_rate, cost_price, margin_percentage
            )
            
            # Прогнозируемая ROI
            roi_forecast = self._calculate_roi_forecast(
                recommended_investment, monthly_profit, daily_sales_rate
            )
            
            return MarginAnalysis(
                product_id=item.product_id,
                sku=item.sku,
                product_name=item.product_name,
                cost_price=cost_price,
                selling_price=selling_price,
                margin_amount=margin_amount,
                margin_percentage=margin_percentage,
                margin_category=margin_category,
                daily_sales_rate=daily_sales_rate,
                monthly_revenue=monthly_revenue,
                monthly_profit=monthly_profit,
                priority_adjustment=priority_adjustment,
                recommended_investment=recommended_investment,
                roi_forecast=roi_forecast
            )
            
        except Exception as e:
            logger.error(f"Ошибка анализа маржинальности товара {item.sku}: {e}")
            # Возвращаем базовый анализ с нулевыми значениями
            return MarginAnalysis(
                product_id=item.product_id,
                sku=item.sku,
                product_name=item.product_name,
                cost_price=item.cost_price or 0,
                selling_price=selling_price or 0,
                margin_amount=0,
                margin_percentage=0,
                margin_category=MarginCategory.LOW_MARGIN,
                daily_sales_rate=0,
                monthly_revenue=0,
                monthly_profit=0,
                priority_adjustment=0,
                recommended_investment=0,
                roi_forecast=0
            )
    
    def _get_selling_price(self, product_id: int) -> float:
        """Получение цены продажи товара из БД."""
        try:
            cursor = self.connection.cursor()
            cursor.execute("""
                SELECT selling_price, current_price, price 
                FROM dim_products 
                WHERE product_id = %s
            """, (product_id,))
            
            result = cursor.fetchone()
            cursor.close()
            
            if result:
                # Пробуем разные поля цены
                for price in result:
                    if price and price > 0:
                        return float(price)
            
            # Если цена не найдена, используем cost_price * 1.3 как приблизительную цену
            cursor = self.connection.cursor()
            cursor.execute("SELECT cost_price FROM dim_products WHERE product_id = %s", (product_id,))
            cost_result = cursor.fetchone()
            cursor.close()
            
            if cost_result and cost_result[0]:
                return float(cost_result[0]) * 1.3  # Предполагаем 30% наценку
            
            return 0.0
            
        except Exception as e:
            logger.error(f"Ошибка получения цены товара {product_id}: {e}")
            return 0.0
    
    def _categorize_margin(self, margin_percentage: float) -> MarginCategory:
        """Категоризация маржинальности."""
        if margin_percentage >= 30:
            return MarginCategory.HIGH_MARGIN
        elif margin_percentage >= 15:
            return MarginCategory.MEDIUM_MARGIN
        elif margin_percentage >= 5:
            return MarginCategory.LOW_MARGIN
        else:
            return MarginCategory.NEGATIVE_MARGIN
    
    def _calculate_priority_adjustment(self, margin_category: MarginCategory, 
                                     margin_percentage: float) -> float:
        """Расчет корректировки приоритета на основе маржинальности."""
        adjustments = {
            MarginCategory.HIGH_MARGIN: 1.5,      # Повышаем приоритет на 50%
            MarginCategory.MEDIUM_MARGIN: 1.2,    # Повышаем на 20%
            MarginCategory.LOW_MARGIN: 1.0,       # Без изменений
            MarginCategory.NEGATIVE_MARGIN: 0.5   # Понижаем на 50%
        }
        
        base_adjustment = adjustments.get(margin_category, 1.0)
        
        # Дополнительная корректировка для очень высокой маржи
        if margin_percentage > 50:
            base_adjustment *= 1.2
        elif margin_percentage < 0:
            base_adjustment *= 0.3
        
        return base_adjustment
    
    def _calculate_recommended_investment(self, daily_sales_rate: float, 
                                        cost_price: float, 
                                        margin_percentage: float) -> float:
        """Расчет рекомендуемых инвестиций в запас."""
        if daily_sales_rate <= 0 or cost_price <= 0:
            return 0.0
        
        # Базовый запас на 30 дней
        base_investment = daily_sales_rate * 30 * cost_price
        
        # Корректировка на основе маржинальности
        if margin_percentage >= 30:
            # Высокомаржинальные товары - увеличиваем инвестиции
            multiplier = 1.5
        elif margin_percentage >= 15:
            # Среднемаржинальные - стандартные инвестиции
            multiplier = 1.0
        elif margin_percentage >= 5:
            # Низкомаржинальные - уменьшаем инвестиции
            multiplier = 0.7
        else:
            # Убыточные - минимальные инвестиции
            multiplier = 0.3
        
        return base_investment * multiplier
    
    def _calculate_roi_forecast(self, investment: float, monthly_profit: float, 
                              daily_sales_rate: float) -> float:
        """Расчет прогнозируемой рентабельности инвестиций."""
        if investment <= 0 or daily_sales_rate <= 0:
            return 0.0
        
        # ROI = (прибыль за период / инвестиции) * 100%
        # Рассчитываем на 3 месяца
        quarterly_profit = monthly_profit * 3
        roi = (quarterly_profit / investment) * 100 if investment > 0 else 0
        
        return roi
    
    def analyze_portfolio_margins(self, source: Optional[str] = None, 
                                limit: Optional[int] = None) -> List[MarginAnalysis]:
        """
        Анализ маржинальности всего портфеля товаров.
        
        Args:
            source: Источник данных (опционально)
            limit: Ограничение количества товаров (опционально)
            
        Returns:
            Список анализов маржинальности
        """
        logger.info("🔍 Анализ маржинальности портфеля товаров")
        
        try:
            # Получаем товары для анализа
            inventory_items = self.inventory_analyzer.get_current_stock(source=source)
            
            if limit:
                inventory_items = inventory_items[:limit]
            
            margin_analyses = []
            processed = 0
            
            for item in inventory_items:
                try:
                    analysis = self.analyze_product_margin(item)
                    margin_analyses.append(analysis)
                    processed += 1
                    
                    if processed % 100 == 0:
                        logger.info(f"Обработано {processed} товаров...")
                        
                except Exception as e:
                    logger.error(f"Ошибка анализа товара {item.sku}: {e}")
                    continue
            
            # Сортируем по ROI (убывание)
            margin_analyses.sort(key=lambda x: x.roi_forecast, reverse=True)
            
            logger.info(f"✅ Анализ завершен: {len(margin_analyses)} товаров")
            return margin_analyses
            
        except Exception as e:
            logger.error(f"Ошибка анализа портфеля: {e}")
            return []
    
    def get_margin_summary(self, analyses: List[MarginAnalysis]) -> Dict[str, any]:
        """Получение сводки по маржинальности."""
        if not analyses:
            return {}
        
        # Группировка по категориям маржинальности
        by_category = {}
        total_investment = 0
        total_profit = 0
        
        for analysis in analyses:
            category = analysis.margin_category.value
            if category not in by_category:
                by_category[category] = {
                    'count': 0,
                    'total_investment': 0,
                    'total_profit': 0,
                    'avg_margin': 0,
                    'avg_roi': 0
                }
            
            by_category[category]['count'] += 1
            by_category[category]['total_investment'] += analysis.recommended_investment
            by_category[category]['total_profit'] += analysis.monthly_profit
            
            total_investment += analysis.recommended_investment
            total_profit += analysis.monthly_profit
        
        # Рассчитываем средние значения
        for category_data in by_category.values():
            if category_data['count'] > 0:
                category_analyses = [a for a in analyses if a.margin_category.value == category]
                category_data['avg_margin'] = sum(a.margin_percentage for a in category_analyses) / len(category_analyses)
                category_data['avg_roi'] = sum(a.roi_forecast for a in category_analyses) / len(category_analyses)
        
        # Топ товары по разным критериям
        top_by_roi = sorted(analyses, key=lambda x: x.roi_forecast, reverse=True)[:10]
        top_by_profit = sorted(analyses, key=lambda x: x.monthly_profit, reverse=True)[:10]
        top_by_margin = sorted(analyses, key=lambda x: x.margin_percentage, reverse=True)[:10]
        
        return {
            'total_products': len(analyses),
            'total_recommended_investment': round(total_investment, 2),
            'total_monthly_profit': round(total_profit, 2),
            'portfolio_roi': round((total_profit / total_investment * 100) if total_investment > 0 else 0, 2),
            'by_category': by_category,
            'top_by_roi': [{'sku': a.sku, 'roi': round(a.roi_forecast, 2)} for a in top_by_roi],
            'top_by_profit': [{'sku': a.sku, 'profit': round(a.monthly_profit, 2)} for a in top_by_profit],
            'top_by_margin': [{'sku': a.sku, 'margin': round(a.margin_percentage, 2)} for a in top_by_margin],
            'analysis_date': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        }
    
    def export_margin_analysis(self, analyses: List[MarginAnalysis], 
                             filename: str = None) -> bool:
        """Экспорт анализа маржинальности в CSV."""
        if not filename:
            filename = f"margin_analysis_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
        
        try:
            import csv
            
            with open(filename, 'w', newline='', encoding='utf-8') as f:
                writer = csv.writer(f)
                
                # Заголовки
                writer.writerow([
                    'SKU', 'Название товара', 'Себестоимость', 'Цена продажи',
                    'Маржа (руб)', 'Маржа (%)', 'Категория маржи',
                    'Продаж в день', 'Выручка в месяц', 'Прибыль в месяц',
                    'Рекомендуемые инвестиции', 'Прогноз ROI (%)'
                ])
                
                # Данные
                for analysis in analyses:
                    writer.writerow([
                        analysis.sku,
                        analysis.product_name,
                        round(analysis.cost_price, 2),
                        round(analysis.selling_price, 2),
                        round(analysis.margin_amount, 2),
                        round(analysis.margin_percentage, 2),
                        analysis.margin_category.value,
                        round(analysis.daily_sales_rate, 2),
                        round(analysis.monthly_revenue, 2),
                        round(analysis.monthly_profit, 2),
                        round(analysis.recommended_investment, 2),
                        round(analysis.roi_forecast, 2)
                    ])
            
            logger.info(f"✅ Анализ маржинальности экспортирован в {filename}")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка экспорта анализа маржинальности: {e}")
            return False
    
    def close(self):
        """Закрытие соединений."""
        if self.inventory_analyzer:
            self.inventory_analyzer.close()
        if self.sales_calculator:
            self.sales_calculator.close()
        if self.connection:
            self.connection.close()


def main():
    """Основная функция для тестирования анализатора маржинальности."""
    logger.info("💰 Запуск анализатора маржинальности")
    
    analyzer = None
    try:
        # Создаем анализатор
        analyzer = MarginAnalyzer()
        
        # Анализируем портфель (ограничиваем для демо)
        analyses = analyzer.analyze_portfolio_margins(limit=100)
        
        if analyses:
            # Получаем сводку
            summary = analyzer.get_margin_summary(analyses)
            
            print("\n💰 АНАЛИЗ МАРЖИНАЛЬНОСТИ ПОРТФЕЛЯ:")
            print("=" * 60)
            print(f"Всего товаров: {summary['total_products']}")
            print(f"Рекомендуемые инвестиции: {summary['total_recommended_investment']:,.2f} руб")
            print(f"Прогнозируемая прибыль: {summary['total_monthly_profit']:,.2f} руб/мес")
            print(f"ROI портфеля: {summary['portfolio_roi']:.2f}%")
            
            print(f"\n📊 РАСПРЕДЕЛЕНИЕ ПО КАТЕГОРИЯМ МАРЖИНАЛЬНОСТИ:")
            for category, data in summary['by_category'].items():
                print(f"  {category}: {data['count']} товаров, "
                      f"средняя маржа: {data['avg_margin']:.1f}%, "
                      f"средний ROI: {data['avg_roi']:.1f}%")
            
            print(f"\n🏆 ТОП-5 ПО ROI:")
            for i, item in enumerate(summary['top_by_roi'][:5], 1):
                print(f"  {i}. {item['sku']}: {item['roi']:.1f}%")
            
            print(f"\n💎 ТОП-5 ПО ПРИБЫЛИ:")
            for i, item in enumerate(summary['top_by_profit'][:5], 1):
                print(f"  {i}. {item['sku']}: {item['profit']:,.2f} руб/мес")
            
            # Экспортируем результаты
            if analyzer.export_margin_analysis(analyses):
                print(f"\n📄 Детальный анализ экспортирован в CSV файл")
        
        else:
            print("ℹ️  Нет данных для анализа маржинальности")
        
        print("\n✅ Анализ маржинальности завершен!")
        
    except Exception as e:
        logger.error(f"Ошибка в main(): {e}")
        
    finally:
        if analyzer:
            analyzer.close()


if __name__ == "__main__":
    main()