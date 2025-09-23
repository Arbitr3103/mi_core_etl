#!/usr/bin/env python3
"""
Модуль расчета скорости продаж для системы пополнения склада.
Анализирует историю продаж и рассчитывает различные метрики скорости продаж.
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

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class SalesTrend(Enum):
    """Тренды продаж."""
    GROWING = "GROWING"
    STABLE = "STABLE"
    DECLINING = "DECLINING"
    NO_DATA = "NO_DATA"


@dataclass
class SalesMetrics:
    """Метрики продаж товара."""
    product_id: int
    sku: str
    
    # Скорость продаж за разные периоды
    daily_sales_rate_7d: float
    daily_sales_rate_14d: float
    daily_sales_rate_30d: float
    
    # Общая статистика
    total_sales_7d: int
    total_sales_14d: int
    total_sales_30d: int
    
    # Даты
    last_sale_date: Optional[datetime]
    first_sale_date: Optional[datetime]
    
    # Тренд
    sales_trend: SalesTrend
    trend_coefficient: float  # Коэффициент тренда (-1 до 1)
    
    # Дополнительные метрики
    days_since_last_sale: int
    sales_consistency: float  # Консистентность продаж (0-1)
    peak_daily_sales: int  # Максимальные продажи в день


@dataclass
class StockoutPrediction:
    """Прогноз исчерпания запасов."""
    product_id: int
    current_stock: int
    available_stock: int
    daily_sales_rate: float
    days_until_stockout: Optional[int]
    stockout_date: Optional[datetime]
    confidence_level: float  # Уровень уверенности в прогнозе (0-1)


class SalesVelocityCalculator:
    """Класс для расчета скорости продаж товаров."""
    
    def __init__(self, connection=None):
        """
        Инициализация калькулятора скорости продаж.
        
        Args:
            connection: Подключение к базе данных (опционально)
        """
        self.connection = connection or connect_to_db()
        
    def calculate_daily_sales_rate(self, product_id: int, days: int = 7) -> float:
        """
        Рассчитать среднедневную скорость продаж за период.
        
        Args:
            product_id: ID товара
            days: Количество дней для анализа
            
        Returns:
            Среднедневная скорость продаж
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Получаем продажи за указанный период
            end_date = datetime.now().date()
            start_date = end_date - timedelta(days=days)
            
            cursor.execute("""
                SELECT 
                    SUM(qty) as total_quantity,
                    COUNT(DISTINCT DATE(order_date)) as active_days
                FROM fact_orders 
                WHERE product_id = %s 
                    AND order_date >= %s 
                    AND order_date <= %s
                    AND transaction_type = 'продажа'
            """, (product_id, start_date, end_date))
            
            result = cursor.fetchone()
            cursor.close()
            
            if result and result['total_quantity']:
                total_quantity = result['total_quantity']
                # Рассчитываем среднедневную скорость
                daily_rate = total_quantity / days
                return round(daily_rate, 2)
            
            return 0.0
            
        except Exception as e:
            logger.error(f"Ошибка расчета скорости продаж для товара {product_id}: {e}")
            return 0.0
    
    def get_sales_metrics(self, product_id: int) -> SalesMetrics:
        """
        Получить полные метрики продаж для товара.
        
        Args:
            product_id: ID товара
            
        Returns:
            Объект SalesMetrics с метриками продаж
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Получаем SKU товара
            cursor.execute("""
                SELECT COALESCE(sku_ozon, 'UNKNOWN') as sku 
                FROM dim_products 
                WHERE id = %s
            """, (product_id,))
            
            sku_result = cursor.fetchone()
            sku = sku_result['sku'] if sku_result else 'UNKNOWN'
            
            # Рассчитываем скорость продаж за разные периоды
            daily_rate_7d = self.calculate_daily_sales_rate(product_id, 7)
            daily_rate_14d = self.calculate_daily_sales_rate(product_id, 14)
            daily_rate_30d = self.calculate_daily_sales_rate(product_id, 30)
            
            # Получаем общую статистику продаж
            end_date = datetime.now().date()
            
            cursor.execute("""
                SELECT 
                    SUM(CASE WHEN order_date >= %s THEN qty ELSE 0 END) as sales_7d,
                    SUM(CASE WHEN order_date >= %s THEN qty ELSE 0 END) as sales_14d,
                    SUM(CASE WHEN order_date >= %s THEN qty ELSE 0 END) as sales_30d,
                    MAX(order_date) as last_sale_date,
                    MIN(order_date) as first_sale_date,
                    MAX(qty) as peak_daily_sales
                FROM fact_orders 
                WHERE product_id = %s 
                    AND transaction_type = 'продажа'
                    AND order_date >= %s
            """, (
                end_date - timedelta(days=7),
                end_date - timedelta(days=14),
                end_date - timedelta(days=30),
                product_id,
                end_date - timedelta(days=90)  # Берем данные за 90 дней для общей статистики
            ))
            
            stats_result = cursor.fetchone()
            
            # Рассчитываем тренд продаж
            trend, trend_coefficient = self._calculate_sales_trend(product_id)
            
            # Рассчитываем консистентность продаж
            consistency = self._calculate_sales_consistency(product_id, 30)
            
            # Рассчитываем дни с последней продажи
            last_sale_date = stats_result['last_sale_date'] if stats_result else None
            days_since_last_sale = 0
            
            if last_sale_date:
                days_since_last_sale = (datetime.now().date() - last_sale_date).days
            
            cursor.close()
            
            # Создаем объект метрик
            metrics = SalesMetrics(
                product_id=product_id,
                sku=sku,
                daily_sales_rate_7d=daily_rate_7d,
                daily_sales_rate_14d=daily_rate_14d,
                daily_sales_rate_30d=daily_rate_30d,
                total_sales_7d=stats_result['sales_7d'] or 0 if stats_result else 0,
                total_sales_14d=stats_result['sales_14d'] or 0 if stats_result else 0,
                total_sales_30d=stats_result['sales_30d'] or 0 if stats_result else 0,
                last_sale_date=last_sale_date,
                first_sale_date=stats_result['first_sale_date'] if stats_result else None,
                sales_trend=trend,
                trend_coefficient=trend_coefficient,
                days_since_last_sale=days_since_last_sale,
                sales_consistency=consistency,
                peak_daily_sales=stats_result['peak_daily_sales'] or 0 if stats_result else 0
            )
            
            return metrics
            
        except Exception as e:
            logger.error(f"Ошибка получения метрик продаж для товара {product_id}: {e}")
            # Возвращаем пустые метрики
            return SalesMetrics(
                product_id=product_id,
                sku='UNKNOWN',
                daily_sales_rate_7d=0.0,
                daily_sales_rate_14d=0.0,
                daily_sales_rate_30d=0.0,
                total_sales_7d=0,
                total_sales_14d=0,
                total_sales_30d=0,
                last_sale_date=None,
                first_sale_date=None,
                sales_trend=SalesTrend.NO_DATA,
                trend_coefficient=0.0,
                days_since_last_sale=999,
                sales_consistency=0.0,
                peak_daily_sales=0
            )
    
    def _calculate_sales_trend(self, product_id: int) -> Tuple[SalesTrend, float]:
        """
        Рассчитать тренд продаж товара.
        
        Args:
            product_id: ID товара
            
        Returns:
            Кортеж (тренд, коэффициент тренда)
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Получаем продажи по неделям за последний месяц
            cursor.execute("""
                SELECT 
                    WEEK(order_date) as week_num,
                    SUM(qty) as weekly_sales
                FROM fact_orders 
                WHERE product_id = %s 
                    AND order_date >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
                    AND transaction_type = 'продажа'
                GROUP BY WEEK(order_date)
                ORDER BY week_num
            """, (product_id,))
            
            weekly_sales = cursor.fetchall()
            cursor.close()
            
            if len(weekly_sales) < 2:
                return SalesTrend.NO_DATA, 0.0
            
            # Простой расчет тренда: сравниваем первую и последнюю неделю
            first_week_sales = weekly_sales[0]['weekly_sales']
            last_week_sales = weekly_sales[-1]['weekly_sales']
            
            if first_week_sales == 0:
                if last_week_sales > 0:
                    return SalesTrend.GROWING, 1.0
                else:
                    return SalesTrend.NO_DATA, 0.0
            
            # Рассчитываем изменение в процентах
            change_percent = (last_week_sales - first_week_sales) / first_week_sales
            
            # Определяем тренд
            if change_percent > 0.2:  # Рост более 20%
                return SalesTrend.GROWING, min(change_percent, 1.0)
            elif change_percent < -0.2:  # Падение более 20%
                return SalesTrend.DECLINING, max(change_percent, -1.0)
            else:
                return SalesTrend.STABLE, change_percent
                
        except Exception as e:
            logger.error(f"Ошибка расчета тренда для товара {product_id}: {e}")
            return SalesTrend.NO_DATA, 0.0
    
    def _calculate_sales_consistency(self, product_id: int, days: int = 30) -> float:
        """
        Рассчитать консистентность продаж (насколько равномерно продается товар).
        
        Args:
            product_id: ID товара
            days: Период для анализа
            
        Returns:
            Коэффициент консистентности (0-1, где 1 - очень консистентные продажи)
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Получаем ежедневные продажи
            end_date = datetime.now().date()
            start_date = end_date - timedelta(days=days)
            
            cursor.execute("""
                SELECT 
                    DATE(order_date) as sale_date,
                    SUM(qty) as daily_sales
                FROM fact_orders 
                WHERE product_id = %s 
                    AND order_date >= %s 
                    AND order_date <= %s
                    AND transaction_type = 'продажа'
                GROUP BY DATE(order_date)
                ORDER BY sale_date
            """, (product_id, start_date, end_date))
            
            daily_sales = cursor.fetchall()
            cursor.close()
            
            if len(daily_sales) < 3:
                return 0.0
            
            # Рассчитываем стандартное отклонение
            sales_values = [row['daily_sales'] for row in daily_sales]
            mean_sales = sum(sales_values) / len(sales_values)
            
            if mean_sales == 0:
                return 0.0
            
            variance = sum((x - mean_sales) ** 2 for x in sales_values) / len(sales_values)
            std_dev = variance ** 0.5
            
            # Коэффициент вариации (обратный к консистентности)
            cv = std_dev / mean_sales if mean_sales > 0 else 1.0
            
            # Преобразуем в консистентность (0-1)
            consistency = max(0.0, 1.0 - min(cv, 1.0))
            
            return round(consistency, 3)
            
        except Exception as e:
            logger.error(f"Ошибка расчета консистентности для товара {product_id}: {e}")
            return 0.0
    
    def calculate_days_until_stockout(self, product_id: int, current_stock: int) -> StockoutPrediction:
        """
        Рассчитать дни до исчерпания запасов.
        
        Args:
            product_id: ID товара
            current_stock: Текущий остаток
            
        Returns:
            Объект StockoutPrediction с прогнозом
        """
        try:
            # Получаем метрики продаж
            metrics = self.get_sales_metrics(product_id)
            
            # Используем скорость продаж за 7 дней как основную
            daily_rate = metrics.daily_sales_rate_7d
            
            # Если нет продаж за 7 дней, используем 14 дней
            if daily_rate == 0:
                daily_rate = metrics.daily_sales_rate_14d
            
            # Если и за 14 дней нет, используем 30 дней
            if daily_rate == 0:
                daily_rate = metrics.daily_sales_rate_30d
            
            # Рассчитываем доступный остаток (исключаем резерв)
            cursor = self.connection.cursor(dictionary=True)
            cursor.execute("""
                SELECT 
                    SUM(quantity_present - COALESCE(quantity_reserved, 0)) as available_stock
                FROM inventory 
                WHERE product_id = %s
            """, (product_id,))
            
            result = cursor.fetchone()
            available_stock = max(0, result['available_stock'] or 0) if result else current_stock
            cursor.close()
            
            # Рассчитываем дни до исчерпания
            days_until_stockout = None
            stockout_date = None
            confidence_level = 0.0
            
            if daily_rate > 0:
                days_until_stockout = int(available_stock / daily_rate)
                stockout_date = datetime.now() + timedelta(days=days_until_stockout)
                
                # Рассчитываем уровень уверенности на основе консистентности продаж
                confidence_level = metrics.sales_consistency
                
                # Корректируем уверенность на основе количества данных
                if metrics.total_sales_7d > 0:
                    confidence_level = min(confidence_level + 0.3, 1.0)
                if metrics.total_sales_30d > 10:  # Достаточно данных
                    confidence_level = min(confidence_level + 0.2, 1.0)
            
            return StockoutPrediction(
                product_id=product_id,
                current_stock=current_stock,
                available_stock=available_stock,
                daily_sales_rate=daily_rate,
                days_until_stockout=days_until_stockout,
                stockout_date=stockout_date,
                confidence_level=round(confidence_level, 2)
            )
            
        except Exception as e:
            logger.error(f"Ошибка расчета прогноза для товара {product_id}: {e}")
            return StockoutPrediction(
                product_id=product_id,
                current_stock=current_stock,
                available_stock=current_stock,
                daily_sales_rate=0.0,
                days_until_stockout=None,
                stockout_date=None,
                confidence_level=0.0
            )
    
    def get_sales_trend(self, product_id: int, days: int = 30) -> Tuple[SalesTrend, float]:
        """
        Определить тренд продаж товара.
        
        Args:
            product_id: ID товара
            days: Период для анализа
            
        Returns:
            Кортеж (тренд, коэффициент изменения)
        """
        return self._calculate_sales_trend(product_id)
    
    def get_top_selling_products(self, days: int = 7, limit: int = 10) -> List[Dict]:
        """
        Получить топ продаваемых товаров за период.
        
        Args:
            days: Период для анализа
            limit: Количество товаров в топе
            
        Returns:
            Список товаров с метриками продаж
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            end_date = datetime.now().date()
            start_date = end_date - timedelta(days=days)
            
            cursor.execute("""
                SELECT 
                    fo.product_id,
                    dp.sku_ozon as sku,
                    dp.product_name,
                    SUM(fo.qty) as total_sales,
                    COUNT(DISTINCT DATE(fo.order_date)) as active_days,
                    ROUND(SUM(fo.qty) / %s, 2) as daily_rate,
                    SUM(fo.qty * fo.price) as total_revenue
                FROM fact_orders fo
                LEFT JOIN dim_products dp ON fo.product_id = dp.id
                WHERE fo.order_date >= %s 
                    AND fo.order_date <= %s
                    AND fo.transaction_type = 'продажа'
                GROUP BY fo.product_id, dp.sku_ozon, dp.product_name
                HAVING total_sales > 0
                ORDER BY total_sales DESC
                LIMIT %s
            """, (days, start_date, end_date, limit))
            
            results = cursor.fetchall()
            cursor.close()
            
            logger.info(f"Получен топ {len(results)} продаваемых товаров за {days} дней")
            return results
            
        except Exception as e:
            logger.error(f"Ошибка получения топа товаров: {e}")
            return []
    
    def get_slow_moving_products(self, days_threshold: int = 30) -> List[Dict]:
        """
        Получить медленно движущиеся товары.
        
        Args:
            days_threshold: Порог в днях без продаж
            
        Returns:
            Список медленно движущихся товаров
        """
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            cursor.execute("""
                SELECT 
                    dp.id as product_id,
                    dp.sku_ozon as sku,
                    dp.product_name,
                    MAX(fo.order_date) as last_sale_date,
                    DATEDIFF(CURDATE(), MAX(fo.order_date)) as days_since_last_sale,
                    SUM(i.quantity_present) as current_stock
                FROM dim_products dp
                LEFT JOIN fact_orders fo ON dp.id = fo.product_id 
                    AND fo.transaction_type = 'продажа'
                LEFT JOIN inventory i ON dp.id = i.product_id
                WHERE dp.is_active_for_replenishment = TRUE
                GROUP BY dp.id, dp.sku_ozon, dp.product_name
                HAVING (days_since_last_sale > %s OR last_sale_date IS NULL)
                    AND current_stock > 0
                ORDER BY days_since_last_sale DESC, current_stock DESC
            """, (days_threshold,))
            
            results = cursor.fetchall()
            cursor.close()
            
            logger.info(f"Найдено {len(results)} медленно движущихся товаров")
            return results
            
        except Exception as e:
            logger.error(f"Ошибка поиска медленно движущихся товаров: {e}")
            return []
    
    def close(self):
        """Закрыть соединение с базой данных."""
        if self.connection:
            self.connection.close()


def main():
    """Основная функция для тестирования калькулятора скорости продаж."""
    logger.info("📈 Запуск калькулятора скорости продаж")
    
    calculator = None
    try:
        # Создаем калькулятор
        calculator = SalesVelocityCalculator()
        
        # Получаем топ продаваемых товаров
        top_products = calculator.get_top_selling_products(days=7, limit=5)
        
        if top_products:
            print("\n🏆 ТОП-5 ПРОДАВАЕМЫХ ТОВАРОВ (7 дней):")
            print("=" * 60)
            for i, product in enumerate(top_products, 1):
                print(f"{i}. {product['sku']} - {product['product_name'][:30]}")
                print(f"   Продано: {product['total_sales']} шт.")
                print(f"   Скорость: {product['daily_rate']} шт/день")
                print(f"   Выручка: {product['total_revenue']:.2f} руб")
                print()
        
        # Анализируем конкретный товар
        if top_products:
            test_product_id = top_products[0]['product_id']
            
            print(f"📊 ДЕТАЛЬНЫЙ АНАЛИЗ ТОВАРА ID: {test_product_id}")
            print("=" * 50)
            
            # Получаем метрики продаж
            metrics = calculator.get_sales_metrics(test_product_id)
            
            print(f"SKU: {metrics.sku}")
            print(f"Скорость продаж:")
            print(f"  - 7 дней: {metrics.daily_sales_rate_7d} шт/день")
            print(f"  - 14 дней: {metrics.daily_sales_rate_14d} шт/день")
            print(f"  - 30 дней: {metrics.daily_sales_rate_30d} шт/день")
            print(f"Тренд: {metrics.sales_trend.value}")
            print(f"Консистентность: {metrics.sales_consistency:.2f}")
            print(f"Дней с последней продажи: {metrics.days_since_last_sale}")
            
            # Прогноз исчерпания запасов
            current_stock = 100  # Примерный остаток для теста
            prediction = calculator.calculate_days_until_stockout(test_product_id, current_stock)
            
            print(f"\n🔮 ПРОГНОЗ ИСЧЕРПАНИЯ ЗАПАСОВ:")
            print(f"Доступный остаток: {prediction.available_stock} шт.")
            print(f"Скорость продаж: {prediction.daily_sales_rate} шт/день")
            if prediction.days_until_stockout:
                print(f"Дней до исчерпания: {prediction.days_until_stockout}")
                print(f"Дата исчерпания: {prediction.stockout_date.strftime('%Y-%m-%d')}")
            else:
                print("Исчерпание не прогнозируется (нет продаж)")
            print(f"Уверенность прогноза: {prediction.confidence_level:.2f}")
        
        # Медленно движущиеся товары
        slow_products = calculator.get_slow_moving_products(days_threshold=30)
        
        if slow_products:
            print(f"\n🐌 МЕДЛЕННО ДВИЖУЩИЕСЯ ТОВАРЫ ({len(slow_products)} найдено):")
            print("=" * 60)
            for product in slow_products[:5]:  # Показываем первые 5
                days_since = product['days_since_last_sale'] or 999
                print(f"- {product['sku']}: {days_since} дней без продаж, остаток: {product['current_stock']} шт.")
        
        print("\n✅ Анализ скорости продаж завершен успешно!")
        
    except Exception as e:
        logger.error(f"Ошибка в main(): {e}")
        
    finally:
        if calculator:
            calculator.close()


if __name__ == "__main__":
    main()