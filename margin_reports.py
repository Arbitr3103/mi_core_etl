#!/usr/bin/env python3
"""
Скрипт для генерации отчетов по маржинальности.

Функционал:
- Отчет по маржинальности по дням
- Отчет по маржинальности по товарам
- Сводный отчет по периодам
- Анализ топ/худших товаров по марже
"""

import os
import sys
import logging
from datetime import datetime, timedelta
from typing import List, Dict, Any, Optional

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def daily_margin_report(cursor, days: int = 30) -> None:
    """Отчет по маржинальности по дням за последние N дней."""
    print(f"\n📊 ОТЧЕТ ПО МАРЖИНАЛЬНОСТИ ПО ДНЯМ (последние {days} дней)")
    print("=" * 80)
    
    cursor.execute("""
        SELECT 
            metric_date,
            SUM(orders_cnt) as total_orders,
            SUM(revenue_sum) as total_revenue,
            SUM(COALESCE(cogs_sum, 0)) as total_cogs,
            SUM(COALESCE(profit_sum, 0)) as total_profit,
            CASE 
                WHEN SUM(revenue_sum) > 0 THEN 
                    ROUND((SUM(COALESCE(profit_sum, 0)) / SUM(revenue_sum)) * 100, 2)
                ELSE 0 
            END as margin_percent
        FROM metrics_daily 
        WHERE metric_date >= DATE_SUB(CURDATE(), INTERVAL %s DAY)
        GROUP BY metric_date
        ORDER BY metric_date DESC
    """, (days,))
    
    results = cursor.fetchall()
    
    if not results:
        print("Нет данных за указанный период")
        return
    
    print(f"{'Дата':<12} {'Заказы':<8} {'Выручка':<12} {'Себестоимость':<15} {'Прибыль':<12} {'Маржа %':<8}")
    print("-" * 80)
    
    total_revenue = 0
    total_cogs = 0
    total_profit = 0
    
    for row in results:
        date_str = row['metric_date'].strftime('%Y-%m-%d')
        orders = row['total_orders']
        revenue = float(row['total_revenue'])
        cogs = float(row['total_cogs'])
        profit = float(row['total_profit'])
        margin = float(row['margin_percent'])
        
        total_revenue += revenue
        total_cogs += cogs
        total_profit += profit
        
        print(f"{date_str:<12} {orders:<8} {revenue:<12,.0f} {cogs:<15,.0f} {profit:<12,.0f} {margin:<8.1f}%")
    
    # Итоговая строка
    total_margin = (total_profit / total_revenue * 100) if total_revenue > 0 else 0
    print("-" * 80)
    print(f"{'ИТОГО:':<12} {'':<8} {total_revenue:<12,.0f} {total_cogs:<15,.0f} {total_profit:<12,.0f} {total_margin:<8.1f}%")


def product_margin_report(cursor, limit: int = 20) -> None:
    """Отчет по маржинальности по товарам."""
    print(f"\n🏷️ ТОП-{limit} ТОВАРОВ ПО МАРЖИНАЛЬНОСТИ")
    print("=" * 100)
    
    cursor.execute("""
        SELECT 
            dp.sku_ozon,
            dp.product_name,
            dp.cost_price,
            SUM(fo.qty) as total_qty,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) as total_revenue,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as total_cogs,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as total_profit,
            CASE 
                WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) > 0 THEN
                    ROUND(((SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
                           SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END)) / 
                           SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END)) * 100, 2)
                ELSE 0
            END as margin_percent
        FROM fact_orders fo
        LEFT JOIN dim_products dp ON fo.sku = dp.sku_ozon OR fo.sku = dp.sku_wb
        WHERE fo.transaction_type = 'продажа'
        GROUP BY dp.sku_ozon, dp.product_name, dp.cost_price
        HAVING total_revenue > 0
        ORDER BY margin_percent DESC, total_profit DESC
        LIMIT %s
    """, (limit,))
    
    results = cursor.fetchall()
    
    if not results:
        print("Нет данных по товарам")
        return
    
    print(f"{'SKU':<20} {'Название':<30} {'Кол-во':<8} {'Выручка':<10} {'Себест.':<10} {'Прибыль':<10} {'Маржа %':<8}")
    print("-" * 100)
    
    for row in results:
        sku = (row['sku_ozon'] or 'N/A')[:19]
        name = (row['product_name'] or 'Без названия')[:29]
        qty = int(row['total_qty'])
        revenue = float(row['total_revenue'])
        cogs = float(row['total_cogs'])
        profit = float(row['total_profit'])
        margin = float(row['margin_percent'])
        
        print(f"{sku:<20} {name:<30} {qty:<8} {revenue:<10,.0f} {cogs:<10,.0f} {profit:<10,.0f} {margin:<8.1f}%")


def low_margin_products_report(cursor, margin_threshold: float = 10.0, limit: int = 10) -> None:
    """Отчет по товарам с низкой маржинальностью."""
    print(f"\n⚠️ ТОВАРЫ С НИЗКОЙ МАРЖИНАЛЬНОСТЬЮ (< {margin_threshold}%)")
    print("=" * 100)
    
    cursor.execute("""
        SELECT 
            dp.sku_ozon,
            dp.product_name,
            dp.cost_price,
            SUM(fo.qty) as total_qty,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) as total_revenue,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as total_cogs,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END) as total_profit,
            CASE 
                WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) > 0 THEN
                    ROUND(((SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END) - 
                           SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * COALESCE(dp.cost_price, 0) ELSE 0 END)) / 
                           SUM(CASE WHEN fo.transaction_type = 'продажа' THEN fo.qty * fo.price ELSE 0 END)) * 100, 2)
                ELSE 0
            END as margin_percent
        FROM fact_orders fo
        LEFT JOIN dim_products dp ON fo.sku = dp.sku_ozon OR fo.sku = dp.sku_wb
        WHERE fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL
        GROUP BY dp.sku_ozon, dp.product_name, dp.cost_price
        HAVING total_revenue > 1000 AND margin_percent < %s
        ORDER BY margin_percent ASC, total_revenue DESC
        LIMIT %s
    """, (margin_threshold, limit))
    
    results = cursor.fetchall()
    
    if not results:
        print(f"Нет товаров с маржинальностью ниже {margin_threshold}% (с выручкой > 1000 руб.)")
        return
    
    print(f"{'SKU':<20} {'Название':<30} {'Кол-во':<8} {'Выручка':<10} {'Себест.':<10} {'Прибыль':<10} {'Маржа %':<8}")
    print("-" * 100)
    
    for row in results:
        sku = (row['sku_ozon'] or 'N/A')[:19]
        name = (row['product_name'] or 'Без названия')[:29]
        qty = int(row['total_qty'])
        revenue = float(row['total_revenue'])
        cogs = float(row['total_cogs'])
        profit = float(row['total_profit'])
        margin = float(row['margin_percent'])
        
        print(f"{sku:<20} {name:<30} {qty:<8} {revenue:<10,.0f} {cogs:<10,.0f} {profit:<10,.0f} {margin:<8.1f}%")


def summary_report(cursor) -> None:
    """Сводный отчет по маржинальности."""
    print(f"\n📈 СВОДНЫЙ ОТЧЕТ ПО МАРЖИНАЛЬНОСТИ")
    print("=" * 60)
    
    # Общая статистика
    cursor.execute("""
        SELECT 
            SUM(orders_cnt) as total_orders,
            SUM(revenue_sum) as total_revenue,
            SUM(COALESCE(cogs_sum, 0)) as total_cogs,
            SUM(COALESCE(profit_sum, 0)) as total_profit,
            COUNT(*) as total_days
        FROM metrics_daily
    """)
    
    summary = cursor.fetchone()
    
    if summary and summary['total_revenue']:
        total_orders = int(summary['total_orders'])
        total_revenue = float(summary['total_revenue'])
        total_cogs = float(summary['total_cogs'])
        total_profit = float(summary['total_profit'])
        total_days = int(summary['total_days'])
        margin_percent = (total_profit / total_revenue) * 100 if total_revenue > 0 else 0
        
        print(f"Общая выручка: {total_revenue:,.0f} руб.")
        print(f"Общая себестоимость: {total_cogs:,.0f} руб.")
        print(f"Общая прибыль: {total_profit:,.0f} руб.")
        print(f"Общая маржинальность: {margin_percent:.1f}%")
        print(f"Всего заказов: {total_orders:,}")
        print(f"Дней в отчете: {total_days}")
        print(f"Средняя выручка в день: {total_revenue/total_days:,.0f} руб.")
        print(f"Средняя прибыль в день: {total_profit/total_days:,.0f} руб.")
    
    # Статистика по товарам с себестоимостью
    cursor.execute("""
        SELECT 
            COUNT(DISTINCT dp.id) as products_with_cost,
            (SELECT COUNT(*) FROM dim_products) as total_products
        FROM dim_products dp
        WHERE dp.cost_price IS NOT NULL AND dp.cost_price > 0
    """)
    
    product_stats = cursor.fetchone()
    if product_stats:
        products_with_cost = int(product_stats['products_with_cost'])
        total_products = int(product_stats['total_products'])
        coverage_percent = (products_with_cost / total_products) * 100 if total_products > 0 else 0
        
        print(f"\nПокрытие себестоимостью:")
        print(f"Товаров с себестоимостью: {products_with_cost}")
        print(f"Всего товаров: {total_products}")
        print(f"Процент покрытия: {coverage_percent:.1f}%")


def main():
    """Основная функция генерации отчетов."""
    print("📊 СИСТЕМА ОТЧЕТОВ ПО МАРЖИНАЛЬНОСТИ")
    print("=" * 50)
    
    connection = None
    cursor = None
    
    try:
        # Подключаемся к базе данных
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # Генерируем отчеты
        summary_report(cursor)
        daily_margin_report(cursor, days=14)
        product_margin_report(cursor, limit=15)
        low_margin_products_report(cursor, margin_threshold=15.0, limit=10)
        
        print(f"\n✅ Отчеты сгенерированы успешно")
        
    except Exception as e:
        logger.error(f"❌ Ошибка при генерации отчетов: {e}")
        return False
        
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()
    
    return True


if __name__ == "__main__":
    success = main()
    exit(0 if success else 1)
