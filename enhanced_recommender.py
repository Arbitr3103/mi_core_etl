#!/usr/bin/env python3
"""
Улучшенный генератор рекомендаций с учетом маржинальности и ROI.
Приоритизирует товары на основе прибыльности и оборачиваемости.
"""

import sys
import os
import logging
from datetime import datetime
from typing import List, Dict, Optional, Tuple
from dataclasses import dataclass

# Добавляем путь к модулям
sys.path.append(os.path.dirname(__file__))

from replenishment_recommender import ReplenishmentRecommender, ReplenishmentRecommendation, PriorityLevel
from margin_analyzer import MarginAnalyzer, MarginAnalysis, MarginCategory

# Настройка логирования
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


@dataclass
class EnhancedRecommendation(ReplenishmentRecommendation):
    """Расширенная рекомендация с учетом маржинальности."""
    # Финансовые показатели
    margin_percentage: float
    margin_category: str
    monthly_profit: float
    roi_forecast: float
    
    # Скорректированные рекомендации
    margin_adjusted_priority: PriorityLevel
    margin_adjusted_quantity: int
    investment_recommendation: float
    
    # Бизнес-инсайты
    profitability_score: float
    investment_priority: int  # 1-5, где 1 - наивысший приоритет


class EnhancedRecommender(ReplenishmentRecommender):
    """Улучшенный генератор рекомендаций с учетом маржинальности."""
    
    def __init__(self, connection=None):
        """Инициализация улучшенного генератора."""
        super().__init__(connection)
        self.margin_analyzer = MarginAnalyzer(connection)
    
    def generate_enhanced_recommendations(self, source: Optional[str] = None,
                                        min_margin_threshold: float = 5.0,
                                        min_roi_threshold: float = 20.0) -> List[EnhancedRecommendation]:
        """
        Генерация улучшенных рекомендаций с учетом маржинальности.
        
        Args:
            source: Источник данных
            min_margin_threshold: Минимальный порог маржинальности (%)
            min_roi_threshold: Минимальный порог ROI (%)
            
        Returns:
            Список улучшенных рекомендаций
        """
        logger.info("🚀 Генерация улучшенных рекомендаций с учетом маржинальности")
        
        try:
            # Получаем базовые рекомендации
            base_recommendations = self.generate_recommendations(source)
            
            if not base_recommendations:
                logger.warning("Нет базовых рекомендаций для улучшения")
                return []
            
            enhanced_recommendations = []
            processed = 0
            
            for base_rec in base_recommendations:
                try:
                    # Получаем товар для анализа маржинальности
                    item = self._get_inventory_item(base_rec.product_id)
                    if not item:
                        continue
                    
                    # Анализируем маржинальность
                    margin_analysis = self.margin_analyzer.analyze_product_margin(item)
                    
                    # Фильтруем по минимальным порогам
                    if (margin_analysis.margin_percentage < min_margin_threshold or 
                        margin_analysis.roi_forecast < min_roi_threshold):
                        logger.debug(f"Товар {base_rec.sku} не прошел фильтры маржинальности")
                        continue
                    
                    # Создаем улучшенную рекомендацию
                    enhanced_rec = self._create_enhanced_recommendation(base_rec, margin_analysis)
                    enhanced_recommendations.append(enhanced_rec)
                    
                    processed += 1
                    if processed % 50 == 0:
                        logger.info(f"Обработано {processed} рекомендаций...")
                    
                except Exception as e:
                    logger.error(f"Ошибка обработки рекомендации {base_rec.sku}: {e}")
                    continue
            
            # Сортируем по приоритету инвестиций и прибыльности
            enhanced_recommendations = self._prioritize_enhanced_recommendations(enhanced_recommendations)
            
            logger.info(f"✅ Создано {len(enhanced_recommendations)} улучшенных рекомендаций")
            return enhanced_recommendations
            
        except Exception as e:
            logger.error(f"Ошибка генерации улучшенных рекомендаций: {e}")
            return []
    
    def _get_inventory_item(self, product_id: int):
        """Получение товара по ID для анализа маржинальности."""
        try:
            items = self.inventory_analyzer.get_current_stock()
            for item in items:
                if item.product_id == product_id:
                    return item
            return None
        except Exception as e:
            logger.error(f"Ошибка получения товара {product_id}: {e}")
            return None
    
    def _create_enhanced_recommendation(self, base_rec: ReplenishmentRecommendation,
                                      margin_analysis: MarginAnalysis) -> EnhancedRecommendation:
        """Создание улучшенной рекомендации."""
        
        # Корректируем приоритет на основе маржинальности
        margin_adjusted_priority = self._adjust_priority_by_margin(
            base_rec.priority_level, margin_analysis.margin_category, margin_analysis.roi_forecast
        )
        
        # Корректируем количество на основе ROI
        margin_adjusted_quantity = self._adjust_quantity_by_roi(
            base_rec.recommended_order_quantity, margin_analysis.roi_forecast, margin_analysis.margin_percentage
        )
        
        # Рассчитываем скорректированную стоимость инвестиций
        investment_recommendation = margin_adjusted_quantity * (base_rec.cost_price or margin_analysis.cost_price)
        
        # Рассчитываем оценку прибыльности (0-100)
        profitability_score = self._calculate_profitability_score(
            margin_analysis.margin_percentage, margin_analysis.roi_forecast, margin_analysis.daily_sales_rate
        )
        
        # Определяем приоритет инвестиций (1-5)
        investment_priority = self._calculate_investment_priority(
            margin_adjusted_priority, profitability_score, margin_analysis.roi_forecast
        )
        
        # Создаем улучшенную рекомендацию
        enhanced_rec = EnhancedRecommendation(
            # Базовые поля из ReplenishmentRecommendation
            product_id=base_rec.product_id,
            sku=base_rec.sku,
            product_name=base_rec.product_name,
            source=base_rec.source,
            current_stock=base_rec.current_stock,
            reserved_stock=base_rec.reserved_stock,
            available_stock=base_rec.available_stock,
            daily_sales_rate_7d=base_rec.daily_sales_rate_7d,
            daily_sales_rate_14d=base_rec.daily_sales_rate_14d,
            daily_sales_rate_30d=base_rec.daily_sales_rate_30d,
            days_until_stockout=base_rec.days_until_stockout,
            recommended_order_quantity=base_rec.recommended_order_quantity,
            recommended_order_value=base_rec.recommended_order_value,
            priority_level=base_rec.priority_level,
            urgency_score=base_rec.urgency_score,
            last_sale_date=base_rec.last_sale_date,
            last_restock_date=base_rec.last_restock_date,
            sales_trend=base_rec.sales_trend,
            inventory_turnover_days=base_rec.inventory_turnover_days,
            days_since_last_sale=base_rec.days_since_last_sale,
            min_stock_level=base_rec.min_stock_level,
            reorder_point=base_rec.reorder_point,
            lead_time_days=base_rec.lead_time_days,
            analysis_date=base_rec.analysis_date,
            confidence_level=base_rec.confidence_level,
            
            # Новые поля для улучшенной рекомендации
            margin_percentage=margin_analysis.margin_percentage,
            margin_category=margin_analysis.margin_category.value,
            monthly_profit=margin_analysis.monthly_profit,
            roi_forecast=margin_analysis.roi_forecast,
            margin_adjusted_priority=margin_adjusted_priority,
            margin_adjusted_quantity=margin_adjusted_quantity,
            investment_recommendation=investment_recommendation,
            profitability_score=profitability_score,
            investment_priority=investment_priority
        )
        
        return enhanced_rec
    
    def _adjust_priority_by_margin(self, base_priority: PriorityLevel, 
                                 margin_category: MarginCategory, roi_forecast: float) -> PriorityLevel:
        """Корректировка приоритета на основе маржинальности."""
        
        # Высокомаржинальные товары с хорошим ROI - повышаем приоритет
        if margin_category == MarginCategory.HIGH_MARGIN and roi_forecast > 50:
            if base_priority == PriorityLevel.MEDIUM:
                return PriorityLevel.HIGH
            elif base_priority == PriorityLevel.LOW:
                return PriorityLevel.MEDIUM
        
        # Низкомаржинальные товары - понижаем приоритет
        elif margin_category == MarginCategory.LOW_MARGIN or roi_forecast < 20:
            if base_priority == PriorityLevel.HIGH:
                return PriorityLevel.MEDIUM
            elif base_priority == PriorityLevel.MEDIUM:
                return PriorityLevel.LOW
        
        # Убыточные товары - минимальный приоритет
        elif margin_category == MarginCategory.NEGATIVE_MARGIN:
            return PriorityLevel.LOW
        
        return base_priority
    
    def _adjust_quantity_by_roi(self, base_quantity: int, roi_forecast: float, 
                              margin_percentage: float) -> int:
        """Корректировка количества на основе ROI."""
        
        if roi_forecast > 100:  # Очень высокий ROI
            multiplier = 1.5
        elif roi_forecast > 50:  # Высокий ROI
            multiplier = 1.3
        elif roi_forecast > 30:  # Хороший ROI
            multiplier = 1.1
        elif roi_forecast > 20:  # Приемлемый ROI
            multiplier = 1.0
        elif roi_forecast > 10:  # Низкий ROI
            multiplier = 0.8
        else:  # Очень низкий ROI
            multiplier = 0.5
        
        # Дополнительная корректировка по марже
        if margin_percentage > 40:
            multiplier *= 1.2
        elif margin_percentage < 10:
            multiplier *= 0.8
        
        adjusted_quantity = int(base_quantity * multiplier)
        return max(1, adjusted_quantity)  # Минимум 1 штука
    
    def _calculate_profitability_score(self, margin_percentage: float, 
                                     roi_forecast: float, daily_sales_rate: float) -> float:
        """Расчет оценки прибыльности (0-100)."""
        
        # Компоненты оценки
        margin_score = min(margin_percentage * 2, 40)  # Максимум 40 баллов за маржу
        roi_score = min(roi_forecast * 0.5, 30)        # Максимум 30 баллов за ROI
        velocity_score = min(daily_sales_rate * 5, 30) # Максимум 30 баллов за скорость продаж
        
        total_score = margin_score + roi_score + velocity_score
        return min(100, max(0, total_score))
    
    def _calculate_investment_priority(self, priority_level: PriorityLevel, 
                                     profitability_score: float, roi_forecast: float) -> int:
        """Расчет приоритета инвестиций (1-5, где 1 - наивысший)."""
        
        # Базовый приоритет по уровню
        base_priority = {
            PriorityLevel.CRITICAL: 1,
            PriorityLevel.HIGH: 2,
            PriorityLevel.MEDIUM: 3,
            PriorityLevel.LOW: 4
        }.get(priority_level, 5)
        
        # Корректировка по прибыльности
        if profitability_score > 80 and roi_forecast > 50:
            base_priority = max(1, base_priority - 1)  # Повышаем приоритет
        elif profitability_score < 40 or roi_forecast < 20:
            base_priority = min(5, base_priority + 1)  # Понижаем приоритет
        
        return base_priority
    
    def _prioritize_enhanced_recommendations(self, recommendations: List[EnhancedRecommendation]) -> List[EnhancedRecommendation]:
        """Приоритизация улучшенных рекомендаций."""
        
        return sorted(recommendations, key=lambda r: (
            r.investment_priority,                    # Сначала по приоритету инвестиций
            -r.profitability_score,                   # Потом по прибыльности (убывание)
            -r.roi_forecast,                          # Потом по ROI (убывание)
            r.days_until_stockout or 999              # Потом по срочности (возрастание)
        ))
    
    def get_investment_summary(self, recommendations: List[EnhancedRecommendation]) -> Dict[str, any]:
        """Получение сводки по инвестиционным рекомендациям."""
        
        if not recommendations:
            return {}
        
        # Группировка по приоритету инвестиций
        by_priority = {}
        total_investment = 0
        total_monthly_profit = 0
        
        for rec in recommendations:
            priority = rec.investment_priority
            if priority not in by_priority:
                by_priority[priority] = {
                    'count': 0,
                    'total_investment': 0,
                    'total_profit': 0,
                    'avg_roi': 0,
                    'avg_margin': 0
                }
            
            by_priority[priority]['count'] += 1
            by_priority[priority]['total_investment'] += rec.investment_recommendation
            by_priority[priority]['total_profit'] += rec.monthly_profit
            
            total_investment += rec.investment_recommendation
            total_monthly_profit += rec.monthly_profit
        
        # Рассчитываем средние значения
        for priority_data in by_priority.values():
            if priority_data['count'] > 0:
                priority_recs = [r for r in recommendations if r.investment_priority == priority]
                priority_data['avg_roi'] = sum(r.roi_forecast for r in priority_recs) / len(priority_recs)
                priority_data['avg_margin'] = sum(r.margin_percentage for r in priority_recs) / len(priority_recs)
        
        # Топ рекомендации
        top_by_profitability = sorted(recommendations, key=lambda x: x.profitability_score, reverse=True)[:10]
        top_by_roi = sorted(recommendations, key=lambda x: x.roi_forecast, reverse=True)[:10]
        
        return {
            'total_recommendations': len(recommendations),
            'total_investment_needed': round(total_investment, 2),
            'total_monthly_profit_forecast': round(total_monthly_profit, 2),
            'portfolio_roi_forecast': round((total_monthly_profit / total_investment * 100) if total_investment > 0 else 0, 2),
            'by_investment_priority': by_priority,
            'top_by_profitability': [
                {
                    'sku': r.sku,
                    'profitability_score': round(r.profitability_score, 1),
                    'investment': round(r.investment_recommendation, 2),
                    'monthly_profit': round(r.monthly_profit, 2)
                } for r in top_by_profitability
            ],
            'top_by_roi': [
                {
                    'sku': r.sku,
                    'roi_forecast': round(r.roi_forecast, 1),
                    'margin_percentage': round(r.margin_percentage, 1),
                    'investment': round(r.investment_recommendation, 2)
                } for r in top_by_roi
            ],
            'analysis_date': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        }
    
    def export_enhanced_recommendations(self, recommendations: List[EnhancedRecommendation], 
                                      filename: str = None) -> bool:
        """Экспорт улучшенных рекомендаций в CSV."""
        
        if not filename:
            filename = f"enhanced_recommendations_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
        
        try:
            import csv
            
            with open(filename, 'w', newline='', encoding='utf-8') as f:
                writer = csv.writer(f)
                
                # Заголовки
                writer.writerow([
                    'SKU', 'Название товара', 'Текущий остаток', 'Базовая рекомендация',
                    'Скорректированная рекомендация', 'Инвестиции (руб)', 'Маржа (%)',
                    'Категория маржи', 'Прогноз ROI (%)', 'Месячная прибыль (руб)',
                    'Оценка прибыльности', 'Приоритет инвестиций', 'Дней до исчерпания'
                ])
                
                # Данные
                for rec in recommendations:
                    writer.writerow([
                        rec.sku,
                        rec.product_name,
                        rec.current_stock,
                        rec.recommended_order_quantity,
                        rec.margin_adjusted_quantity,
                        round(rec.investment_recommendation, 2),
                        round(rec.margin_percentage, 2),
                        rec.margin_category,
                        round(rec.roi_forecast, 2),
                        round(rec.monthly_profit, 2),
                        round(rec.profitability_score, 1),
                        rec.investment_priority,
                        rec.days_until_stockout or 'Н/Д'
                    ])
            
            logger.info(f"✅ Улучшенные рекомендации экспортированы в {filename}")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка экспорта улучшенных рекомендаций: {e}")
            return False
    
    def close(self):
        """Закрытие соединений."""
        super().close()
        if self.margin_analyzer:
            self.margin_analyzer.close()


def main():
    """Основная функция для тестирования улучшенного генератора."""
    logger.info("🎯 Запуск улучшенного генератора рекомендаций")
    
    recommender = None
    try:
        # Создаем улучшенный генератор
        recommender = EnhancedRecommender()
        
        # Генерируем улучшенные рекомендации
        recommendations = recommender.generate_enhanced_recommendations(
            min_margin_threshold=10.0,  # Минимум 10% маржи
            min_roi_threshold=25.0      # Минимум 25% ROI
        )
        
        if recommendations:
            # Получаем сводку по инвестициям
            summary = recommender.get_investment_summary(recommendations)
            
            print("\n🎯 УЛУЧШЕННЫЕ РЕКОМЕНДАЦИИ ПО ПОПОЛНЕНИЮ:")
            print("=" * 70)
            print(f"Всего рекомендаций: {summary['total_recommendations']}")
            print(f"Требуемые инвестиции: {summary['total_investment_needed']:,.2f} руб")
            print(f"Прогноз прибыли: {summary['total_monthly_profit_forecast']:,.2f} руб/мес")
            print(f"Прогноз ROI портфеля: {summary['portfolio_roi_forecast']:.1f}%")
            
            print(f"\n📊 РАСПРЕДЕЛЕНИЕ ПО ПРИОРИТЕТУ ИНВЕСТИЦИЙ:")
            for priority, data in summary['by_investment_priority'].items():
                priority_name = {1: 'Наивысший', 2: 'Высокий', 3: 'Средний', 4: 'Низкий', 5: 'Минимальный'}.get(priority, str(priority))
                print(f"  {priority_name} ({priority}): {data['count']} товаров, "
                      f"инвестиции: {data['total_investment']:,.0f} руб, "
                      f"средний ROI: {data['avg_roi']:.1f}%")
            
            print(f"\n🏆 ТОП-5 ПО ПРИБЫЛЬНОСТИ:")
            for i, item in enumerate(summary['top_by_profitability'][:5], 1):
                print(f"  {i}. {item['sku']}: оценка {item['profitability_score']}, "
                      f"инвестиции {item['investment']:,.0f} руб, "
                      f"прибыль {item['monthly_profit']:,.0f} руб/мес")
            
            print(f"\n💎 ТОП-5 ПО ROI:")
            for i, item in enumerate(summary['top_by_roi'][:5], 1):
                print(f"  {i}. {item['sku']}: ROI {item['roi_forecast']:.1f}%, "
                      f"маржа {item['margin_percentage']:.1f}%, "
                      f"инвестиции {item['investment']:,.0f} руб")
            
            # Экспортируем результаты
            if recommender.export_enhanced_recommendations(recommendations):
                print(f"\n📄 Детальные рекомендации экспортированы в CSV файл")
        
        else:
            print("ℹ️  Нет товаров, соответствующих критериям прибыльности")
        
        print("\n✅ Анализ улучшенных рекомендаций завершен!")
        
    except Exception as e:
        logger.error(f"Ошибка в main(): {e}")
        
    finally:
        if recommender:
            recommender.close()


if __name__ == "__main__":
    main()