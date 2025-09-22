#!/usr/bin/env python3
"""
Генератор рекомендаций по пополнению склада.
Объединяет анализ запасов и скорости продаж для создания рекомендаций по пополнению.
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

from ozon_importer import connect_to_db
from inventory_analyzer import InventoryAnalyzer, InventoryItem, ProductSettings
from sales_velocity_calculator import SalesVelocityCalculator, SalesMetrics, SalesTrend

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class PriorityLevel(Enum):
    """Уровни приоритета пополнения."""
    CRITICAL = "CRITICAL"
    HIGH = "HIGH"
    MEDIUM = "MEDIUM"
    LOW = "LOW"


@dataclass
class ReplenishmentRecommendation:
    """Рекомендация по пополнению товара."""
    # Основная информация
    product_id: int
    sku: str
    product_name: str
    source: str
    
    # Текущее состояние
    current_stock: int
    reserved_stock: int
    available_stock: int
    
    # Анализ продаж
    daily_sales_rate_7d: float
    daily_sales_rate_14d: float
    daily_sales_rate_30d: float
    
    # Прогнозы
    days_until_stockout: Optional[int]
    recommended_order_quantity: int
    recommended_order_value: Optional[float]
    
    # Приоритизация
    priority_level: PriorityLevel
    urgency_score: float
    
    # Дополнительная информация
    last_sale_date: Optional[datetime]
    last_restock_date: Optional[datetime]
    sales_trend: SalesTrend
    inventory_turnover_days: Optional[int]
    days_since_last_sale: Optional[int]
    
    # Настройки товара
    min_stock_level: int
    reorder_point: int
    lead_time_days: int
    
    # Метаданные
    analysis_date: datetime
    confidence_level: float


class ReplenishmentRecommender:
    """Класс для генерации рекомендаций по пополнению склада."""
    
    def __init__(self, connection=None):
        """
        Инициализация генератора рекомендаций.
        
        Args:
            connection: Подключение к базе данных (опционально)
        """
        self.connection = connection or connect_to_db()
        self.inventory_analyzer = InventoryAnalyzer(self.connection)
        self.sales_calculator = SalesVelocityCalculator(self.connection)
        self.settings = self.inventory_analyzer.settings
        
    def generate_recommendations(self, source: Optional[str] = None) -> List[ReplenishmentRecommendation]:
        """
        Генерировать рекомендации по пополнению для всех товаров.
        
        Args:
            source: Источник данных для анализа (опционально)
            
        Returns:
            Список рекомендаций по пополнению
        """
        logger.info(f"🔄 Генерация рекомендаций по пополнению{' для ' + source if source else ''}")
        
        try:
            # Получаем все товары в запасах
            inventory_items = self.inventory_analyzer.get_current_stock(source=source)
            
            if not inventory_items:
                logger.warning("Нет товаров в запасах для анализа")
                return []
            
            recommendations = []
            analysis_date = datetime.now()
            
            logger.info(f"Анализируем {len(inventory_items)} товаров...")
            
            for i, item in enumerate(inventory_items, 1):
                if i % 50 == 0:  # Логируем прогресс каждые 50 товаров
                    logger.info(f"Обработано {i}/{len(inventory_items)} товаров")
                
                try:
                    # Получаем настройки товара
                    settings = self.inventory_analyzer.get_product_settings(item.product_id)
                    
                    # Пропускаем неактивные товары
                    if not settings.is_active:
                        continue
                    
                    # Получаем метрики продаж
                    sales_metrics = self.sales_calculator.get_sales_metrics(item.product_id)
                    
                    # Рассчитываем прогноз исчерпания запасов
                    stockout_prediction = self.sales_calculator.calculate_days_until_stockout(
                        item.product_id, item.current_stock
                    )
                    
                    # Рассчитываем рекомендуемое количество для заказа
                    recommended_qty, order_value = self.calculate_recommended_quantity(
                        item, sales_metrics, settings
                    )
                    
                    # Определяем приоритет и срочность
                    priority, urgency_score = self.calculate_priority_and_urgency(
                        item, sales_metrics, stockout_prediction, settings
                    )
                    
                    # Рассчитываем оборачиваемость
                    turnover_days = self.calculate_inventory_turnover(sales_metrics, item.current_stock)
                    
                    # Рассчитываем дни с последней продажи
                    days_since_last_sale = None
                    if sales_metrics.last_sale_date:
                        days_since_last_sale = (datetime.now().date() - sales_metrics.last_sale_date.date()).days
                    
                    # Создаем рекомендацию
                    recommendation = ReplenishmentRecommendation(
                        product_id=item.product_id,
                        sku=item.sku,
                        product_name=item.product_name,
                        source=item.source,
                        current_stock=item.current_stock,
                        reserved_stock=item.reserved_stock,
                        available_stock=item.available_stock,
                        daily_sales_rate_7d=sales_metrics.daily_sales_rate_7d,
                        daily_sales_rate_14d=sales_metrics.daily_sales_rate_14d,
                        daily_sales_rate_30d=sales_metrics.daily_sales_rate_30d,
                        days_until_stockout=stockout_prediction.days_until_stockout,
                        recommended_order_quantity=recommended_qty,
                        recommended_order_value=order_value,
                        priority_level=priority,
                        urgency_score=urgency_score,
                        last_sale_date=sales_metrics.last_sale_date,
                        last_restock_date=None,  # TODO: Добавить логику определения последнего пополнения
                        sales_trend=sales_metrics.sales_trend,
                        inventory_turnover_days=turnover_days,
                        days_since_last_sale=days_since_last_sale,
                        min_stock_level=settings.min_stock_level,
                        reorder_point=settings.reorder_point,
                        lead_time_days=settings.lead_time_days,
                        analysis_date=analysis_date,
                        confidence_level=stockout_prediction.confidence_level
                    )
                    
                    recommendations.append(recommendation)
                    
                except Exception as e:
                    logger.error(f"Ошибка обработки товара {item.sku} (ID: {item.product_id}): {e}")
                    continue
            
            logger.info(f"✅ Сгенерировано {len(recommendations)} рекомендаций")
            
            # Сортируем по приоритету и срочности
            recommendations = self.prioritize_recommendations(recommendations)
            
            return recommendations
            
        except Exception as e:
            logger.error(f"Ошибка генерации рекомендаций: {e}")
            return []
    
    def calculate_recommended_quantity(self, item: InventoryItem, 
                                     sales_metrics: SalesMetrics, 
                                     settings: ProductSettings) -> Tuple[int, Optional[float]]:
        """
        Рассчитать рекомендуемое количество для заказа.
        
        Args:
            item: Товар в запасах
            sales_metrics: Метрики продаж
            settings: Настройки товара
            
        Returns:
            Кортеж (рекомендуемое количество, стоимость заказа)
        """
        try:
            # Используем наиболее актуальную скорость продаж
            daily_rate = sales_metrics.daily_sales_rate_7d
            if daily_rate == 0:
                daily_rate = sales_metrics.daily_sales_rate_14d
            if daily_rate == 0:
                daily_rate = sales_metrics.daily_sales_rate_30d
            
            # Если нет продаж, используем минимальный заказ
            if daily_rate == 0:
                if settings.min_stock_level > 0:
                    recommended_qty = max(0, settings.min_stock_level - item.available_stock)
                else:
                    recommended_qty = 0
            else:
                # Рассчитываем потребность на время поставки
                lead_time_demand = daily_rate * settings.lead_time_days
                
                # Добавляем страховой запас
                safety_stock = daily_rate * settings.safety_stock_days
                
                # Учитываем тренд продаж
                trend_multiplier = 1.0
                if sales_metrics.sales_trend == SalesTrend.GROWING:
                    trend_multiplier = 1.2  # Увеличиваем на 20% для растущих продаж
                elif sales_metrics.sales_trend == SalesTrend.DECLINING:
                    trend_multiplier = 0.8  # Уменьшаем на 20% для падающих продаж
                
                # Общая потребность
                total_need = (lead_time_demand + safety_stock) * trend_multiplier
                
                # Рекомендуемое количество = потребность - текущий доступный остаток
                recommended_qty = max(0, int(total_need - item.available_stock))
                
                # Ограничиваем максимальным множителем
                max_multiplier = self.settings.get('max_recommended_order_multiplier', 3.0)
                max_qty = int(daily_rate * 30 * max_multiplier)  # Максимум на месяц * множитель
                recommended_qty = min(recommended_qty, max_qty)
            
            # Рассчитываем стоимость заказа
            order_value = None
            if recommended_qty > 0 and item.cost_price:
                order_value = recommended_qty * item.cost_price
            
            return recommended_qty, order_value
            
        except Exception as e:
            logger.error(f"Ошибка расчета рекомендуемого количества для товара {item.sku}: {e}")
            return 0, None
    
    def calculate_priority_and_urgency(self, item: InventoryItem, 
                                     sales_metrics: SalesMetrics,
                                     stockout_prediction,
                                     settings: ProductSettings) -> Tuple[PriorityLevel, float]:
        """
        Рассчитать приоритет и оценку срочности.
        
        Args:
            item: Товар в запасах
            sales_metrics: Метрики продаж
            stockout_prediction: Прогноз исчерпания запасов
            settings: Настройки товара
            
        Returns:
            Кортеж (уровень приоритета, оценка срочности)
        """
        try:
            urgency_score = 0.0
            priority = PriorityLevel.LOW
            
            # Базовая оценка на основе дней до исчерпания
            days_until_stockout = stockout_prediction.days_until_stockout
            
            if days_until_stockout is not None:
                if days_until_stockout <= self.settings.get('critical_stockout_threshold', 3):
                    priority = PriorityLevel.CRITICAL
                    urgency_score += 40
                elif days_until_stockout <= self.settings.get('high_priority_threshold', 7):
                    priority = PriorityLevel.HIGH
                    urgency_score += 30
                elif days_until_stockout <= 14:
                    priority = PriorityLevel.MEDIUM
                    urgency_score += 20
                else:
                    priority = PriorityLevel.LOW
                    urgency_score += 10
                
                # Дополнительная оценка на основе скорости исчерпания
                if days_until_stockout > 0:
                    urgency_score += min(20, 100 / days_until_stockout)
            
            # Учитываем скорость продаж
            daily_rate = max(sales_metrics.daily_sales_rate_7d, 
                           sales_metrics.daily_sales_rate_14d,
                           sales_metrics.daily_sales_rate_30d)
            
            if daily_rate > 0:
                urgency_score += min(15, daily_rate * 2)  # Максимум 15 баллов за скорость
            
            # Учитываем тренд продаж
            if sales_metrics.sales_trend == SalesTrend.GROWING:
                urgency_score += 10
                # Повышаем приоритет для растущих продаж
                if priority == PriorityLevel.LOW:
                    priority = PriorityLevel.MEDIUM
                elif priority == PriorityLevel.MEDIUM:
                    priority = PriorityLevel.HIGH
            elif sales_metrics.sales_trend == SalesTrend.DECLINING:
                urgency_score -= 5
            
            # Учитываем настройки товара
            if settings.reorder_point > 0 and item.available_stock <= settings.reorder_point:
                urgency_score += 15
                if priority == PriorityLevel.LOW:
                    priority = PriorityLevel.MEDIUM
            
            # Учитываем консистентность продаж (более предсказуемые товары важнее)
            urgency_score += sales_metrics.sales_consistency * 10
            
            # Ограничиваем оценку срочности
            urgency_score = min(100.0, max(0.0, urgency_score))
            
            return priority, round(urgency_score, 2)
            
        except Exception as e:
            logger.error(f"Ошибка расчета приоритета для товара {item.sku}: {e}")
            return PriorityLevel.LOW, 0.0
    
    def calculate_inventory_turnover(self, sales_metrics: SalesMetrics, current_stock: int) -> Optional[int]:
        """
        Рассчитать оборачиваемость запасов в днях.
        
        Args:
            sales_metrics: Метрики продаж
            current_stock: Текущий остаток
            
        Returns:
            Оборачиваемость в днях или None
        """
        try:
            # Используем скорость продаж за 30 дней как наиболее стабильную
            daily_rate = sales_metrics.daily_sales_rate_30d
            
            if daily_rate > 0 and current_stock > 0:
                turnover_days = int(current_stock / daily_rate)
                return turnover_days
            
            return None
            
        except Exception as e:
            logger.error(f"Ошибка расчета оборачиваемости: {e}")
            return None
    
    def prioritize_recommendations(self, recommendations: List[ReplenishmentRecommendation]) -> List[ReplenishmentRecommendation]:
        """
        Приоритизировать рекомендации по критичности.
        
        Args:
            recommendations: Список рекомендаций
            
        Returns:
            Отсортированный список рекомендаций
        """
        try:
            # Определяем порядок приоритетов
            priority_order = {
                PriorityLevel.CRITICAL: 4,
                PriorityLevel.HIGH: 3,
                PriorityLevel.MEDIUM: 2,
                PriorityLevel.LOW: 1
            }
            
            # Сортируем по приоритету, затем по срочности, затем по дням до исчерпания
            sorted_recommendations = sorted(
                recommendations,
                key=lambda r: (
                    priority_order.get(r.priority_level, 0),
                    r.urgency_score,
                    -(r.days_until_stockout or 999)  # Отрицательное значение для сортировки по возрастанию
                ),
                reverse=True
            )
            
            logger.info(f"Рекомендации отсортированы по приоритету")
            return sorted_recommendations
            
        except Exception as e:
            logger.error(f"Ошибка приоритизации рекомендаций: {e}")
            return recommendations
    
    def save_recommendations_to_db(self, recommendations: List[ReplenishmentRecommendation]) -> bool:
        """
        Сохранить рекомендации в базу данных.
        
        Args:
            recommendations: Список рекомендаций для сохранения
            
        Returns:
            True если сохранение прошло успешно
        """
        if not recommendations:
            logger.warning("Нет рекомендаций для сохранения")
            return True
        
        try:
            cursor = self.connection.cursor()
            
            # Очищаем старые рекомендации за сегодня
            today = datetime.now().date()
            cursor.execute("""
                DELETE FROM replenishment_recommendations 
                WHERE analysis_date = %s
            """, (today,))
            
            logger.info(f"Удалено {cursor.rowcount} старых рекомендаций за {today}")
            
            # Подготавливаем данные для вставки
            insert_sql = """
                INSERT INTO replenishment_recommendations (
                    product_id, sku, product_name, source,
                    current_stock, reserved_stock, available_stock,
                    daily_sales_rate_7d, daily_sales_rate_14d, daily_sales_rate_30d,
                    days_until_stockout, recommended_order_quantity, recommended_order_value,
                    priority_level, urgency_score,
                    last_sale_date, sales_trend, inventory_turnover_days,
                    min_stock_level, reorder_point, lead_time_days,
                    analysis_date
                ) VALUES (
                    %s, %s, %s, %s,
                    %s, %s, %s,
                    %s, %s, %s,
                    %s, %s, %s,
                    %s, %s,
                    %s, %s, %s,
                    %s, %s, %s,
                    %s
                )
            """
            
            # Подготавливаем данные для batch insert
            batch_data = []
            for rec in recommendations:
                batch_data.append((
                    rec.product_id, rec.sku, rec.product_name, rec.source,
                    rec.current_stock, rec.reserved_stock, rec.available_stock,
                    rec.daily_sales_rate_7d, rec.daily_sales_rate_14d, rec.daily_sales_rate_30d,
                    rec.days_until_stockout, rec.recommended_order_quantity, rec.recommended_order_value,
                    rec.priority_level.value, rec.urgency_score,
                    rec.last_sale_date, rec.sales_trend.value, rec.inventory_turnover_days,
                    rec.min_stock_level, rec.reorder_point, rec.lead_time_days,
                    rec.analysis_date.date()
                ))
            
            # Выполняем batch insert
            cursor.executemany(insert_sql, batch_data)
            
            # Фиксируем изменения
            self.connection.commit()
            cursor.close()
            
            logger.info(f"✅ Сохранено {len(recommendations)} рекомендаций в базу данных")
            return True
            
        except Exception as e:
            logger.error(f"Ошибка сохранения рекомендаций: {e}")
            self.connection.rollback()
            return False
    
    def get_critical_recommendations(self, limit: int = 20) -> List[ReplenishmentRecommendation]:
        """
        Получить критические рекомендации из базы данных.
        
        Args:
            limit: Максимальное количество рекомендаций
            
        Returns:
            Список критических рекомендаций
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            cursor.execute("""
                SELECT * FROM replenishment_recommendations
                WHERE priority_level IN ('CRITICAL', 'HIGH')
                    AND analysis_date = (
                        SELECT MAX(analysis_date) 
                        FROM replenishment_recommendations
                    )
                ORDER BY urgency_score DESC, days_until_stockout ASC
                LIMIT %s
            """, (limit,))
            
            results = cursor.fetchall()
            cursor.close()
            
            # Преобразуем в объекты рекомендаций
            recommendations = []
            for row in results:
                rec = ReplenishmentRecommendation(
                    product_id=row['product_id'],
                    sku=row['sku'],
                    product_name=row['product_name'],
                    source=row['source'],
                    current_stock=row['current_stock'],
                    reserved_stock=row['reserved_stock'],
                    available_stock=row['available_stock'],
                    daily_sales_rate_7d=float(row['daily_sales_rate_7d']),
                    daily_sales_rate_14d=float(row['daily_sales_rate_14d']),
                    daily_sales_rate_30d=float(row['daily_sales_rate_30d']),
                    days_until_stockout=row['days_until_stockout'],
                    recommended_order_quantity=row['recommended_order_quantity'],
                    recommended_order_value=float(row['recommended_order_value']) if row['recommended_order_value'] else None,
                    priority_level=PriorityLevel(row['priority_level']),
                    urgency_score=float(row['urgency_score']),
                    last_sale_date=row['last_sale_date'],
                    last_restock_date=row['last_restock_date'],
                    sales_trend=SalesTrend(row['sales_trend']),
                    inventory_turnover_days=row['inventory_turnover_days'],
                    min_stock_level=row['min_stock_level'],
                    reorder_point=row['reorder_point'],
                    lead_time_days=row['lead_time_days'],
                    analysis_date=row['analysis_date'],
                    confidence_level=0.0  # Не сохраняется в БД
                )
                recommendations.append(rec)
            
            logger.info(f"Получено {len(recommendations)} критических рекомендаций")
            return recommendations
            
        except Exception as e:
            logger.error(f"Ошибка получения критических рекомендаций: {e}")
            return []
    
    def close(self):
        """Закрыть все соединения."""
        if self.inventory_analyzer:
            self.inventory_analyzer.close()
        if self.sales_calculator:
            self.sales_calculator.close()
        if self.connection:
            self.connection.close()


def main():
    """Основная функция для тестирования генератора рекомендаций."""
    logger.info("🎯 Запуск генератора рекомендаций по пополнению")
    
    recommender = None
    try:
        # Создаем генератор рекомендаций
        recommender = ReplenishmentRecommender()
        
        # Генерируем рекомендации
        recommendations = recommender.generate_recommendations()
        
        if recommendations:
            print(f"\n📋 СГЕНЕРИРОВАНО {len(recommendations)} РЕКОМЕНДАЦИЙ:")
            print("=" * 80)
            
            # Показываем критические рекомендации
            critical_recs = [r for r in recommendations if r.priority_level in [PriorityLevel.CRITICAL, PriorityLevel.HIGH]]
            
            if critical_recs:
                print(f"\n🚨 КРИТИЧЕСКИЕ РЕКОМЕНДАЦИИ ({len(critical_recs)}):")
                for i, rec in enumerate(critical_recs[:10], 1):  # Показываем первые 10
                    print(f"\n{i}. {rec.sku} - {rec.product_name[:40]}")
                    print(f"   Приоритет: {rec.priority_level.value} (срочность: {rec.urgency_score})")
                    print(f"   Остаток: {rec.available_stock} шт.")
                    print(f"   Дней до исчерпания: {rec.days_until_stockout or 'Не определено'}")
                    print(f"   Рекомендуемый заказ: {rec.recommended_order_quantity} шт.")
                    if rec.recommended_order_value:
                        print(f"   Стоимость заказа: {rec.recommended_order_value:.2f} руб.")
                    print(f"   Скорость продаж: {rec.daily_sales_rate_7d} шт/день")
                    print(f"   Тренд: {rec.sales_trend.value}")
            
            # Сохраняем рекомендации в БД
            print(f"\n💾 Сохранение рекомендаций в базу данных...")
            if recommender.save_recommendations_to_db(recommendations):
                print("✅ Рекомендации успешно сохранены")
            else:
                print("❌ Ошибка сохранения рекомендаций")
            
            # Статистика по приоритетам
            priority_stats = {}
            for rec in recommendations:
                priority = rec.priority_level.value
                priority_stats[priority] = priority_stats.get(priority, 0) + 1
            
            print(f"\n📊 СТАТИСТИКА ПО ПРИОРИТЕТАМ:")
            for priority, count in priority_stats.items():
                print(f"   {priority}: {count} товаров")
        
        else:
            print("ℹ️  Рекомендации не сгенерированы")
        
        print("\n✅ Генерация рекомендаций завершена!")
        
    except Exception as e:
        logger.error(f"Ошибка в main(): {e}")
        
    finally:
        if recommender:
            recommender.close()


if __name__ == "__main__":
    main()