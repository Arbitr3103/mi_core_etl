#!/usr/bin/env python3
"""
Скрипт для пересчета маржинальности в существующих записях metrics_daily.

Функционал:
- Пересчитывает cogs_sum и profit_sum для всех записей в metrics_daily
- Использует актуальные данные о себестоимости из dim_products
- Показывает детальную статистику до и после пересчета
"""

import os
import sys
import logging
from datetime import datetime
from typing import List, Dict, Any

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def get_current_metrics_stats(cursor) -> Dict[str, Any]:
    """Получает текущую статистику по metrics_daily."""
    cursor.execute("""
        SELECT 
            COUNT(*) as total_records,
            COUNT(cogs_sum) as records_with_cogs,
            COUNT(profit_sum) as records_with_profit,
            SUM(revenue_sum) as total_revenue,
            SUM(COALESCE(cogs_sum, 0)) as total_cogs,
            SUM(COALESCE(profit_sum, 0)) as total_profit
        FROM metrics_daily
    """)
    
    return cursor.fetchone()


def recalculate_margins_for_date(cursor, metric_date: str) -> bool:
    """Пересчитывает маржинальность для конкретной даты."""
    try:
        # Получаем client_id для данной даты
        cursor.execute("SELECT DISTINCT client_id FROM metrics_daily WHERE metric_date = %s", (metric_date,))
        clients = cursor.fetchall()
        
        for client_row in clients:
            client_id = client_row['client_id'] if isinstance(client_row, dict) else client_row[0]
            
            # Рассчитываем новые значения на основе fact_orders с актуальной себестоимостью
            cursor.execute("""
                SELECT
                    COUNT(CASE WHEN fo.transaction_type = 'продажа' THEN fo.id END) AS orders_cnt,
                    SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) AS revenue_sum,
                    SUM(CASE WHEN fo.transaction_type = 'продажа' THEN COALESCE(dp.cost_price, 0) ELSE 0 END) AS cogs_sum
                FROM fact_orders fo
                LEFT JOIN dim_products dp ON fo.sku = dp.sku_ozon OR fo.sku = dp.sku_wb
                WHERE fo.order_date = %s AND fo.client_id = %s
                GROUP BY fo.client_id
            """, (metric_date, client_id))
            
            result = cursor.fetchone()
            if result:
                if isinstance(result, dict):
                    orders_cnt = result['orders_cnt'] or 0
                    revenue_sum = result['revenue_sum'] or 0
                    cogs_sum = result['cogs_sum'] or 0
                else:
                    orders_cnt = result[0] or 0
                    revenue_sum = result[1] or 0
                    cogs_sum = result[2] or 0
                
                profit_sum = revenue_sum - cogs_sum
                
                # Обновляем запись в metrics_daily
                cursor.execute("""
                    UPDATE metrics_daily 
                    SET 
                        orders_cnt = %s,
                        revenue_sum = %s,
                        cogs_sum = %s,
                        profit_sum = %s
                    WHERE metric_date = %s AND client_id = %s
                """, (orders_cnt, revenue_sum, cogs_sum, profit_sum, metric_date, client_id))
                
                logger.info(f"Обновлена дата {metric_date}, клиент {client_id}: выручка={revenue_sum}, себестоимость={cogs_sum}, прибыль={profit_sum}")
        
        return True
        
    except Exception as e:
        logger.error(f"Ошибка при пересчете для даты {metric_date}: {e}")
        return False


def main():
    """Основная функция пересчета маржинальности."""
    logger.info("🚀 Запуск пересчета маржинальности для metrics_daily")
    
    connection = None
    cursor = None
    
    try:
        # Подключаемся к базе данных
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # Получаем статистику до пересчета
        logger.info("📊 Статистика ДО пересчета:")
        stats_before = get_current_metrics_stats(cursor)
        logger.info(f"Всего записей: {stats_before['total_records']}")
        logger.info(f"Записей с себестоимостью: {stats_before['records_with_cogs']}")
        logger.info(f"Записей с прибылью: {stats_before['records_with_profit']}")
        logger.info(f"Общая выручка: {stats_before['total_revenue']:,.2f} руб.")
        logger.info(f"Общая себестоимость: {stats_before['total_cogs']:,.2f} руб.")
        logger.info(f"Общая прибыль: {stats_before['total_profit']:,.2f} руб.")
        
        # Получаем все даты для пересчета
        cursor.execute("SELECT DISTINCT metric_date FROM metrics_daily ORDER BY metric_date")
        dates = cursor.fetchall()
        
        logger.info(f"🔄 Начинаем пересчет для {len(dates)} дат...")
        
        success_count = 0
        error_count = 0
        
        for date_row in dates:
            metric_date = date_row['metric_date']
            if recalculate_margins_for_date(cursor, metric_date):
                success_count += 1
            else:
                error_count += 1
        
        # Фиксируем изменения
        connection.commit()
        
        # Получаем статистику после пересчета
        logger.info("📊 Статистика ПОСЛЕ пересчета:")
        stats_after = get_current_metrics_stats(cursor)
        logger.info(f"Всего записей: {stats_after['total_records']}")
        logger.info(f"Записей с себестоимостью: {stats_after['records_with_cogs']}")
        logger.info(f"Записей с прибылью: {stats_after['records_with_profit']}")
        logger.info(f"Общая выручка: {stats_after['total_revenue']:,.2f} руб.")
        logger.info(f"Общая себестоимость: {stats_after['total_cogs']:,.2f} руб.")
        logger.info(f"Общая прибыль: {stats_after['total_profit']:,.2f} руб.")
        
        # Рассчитываем изменения
        cogs_change = stats_after['total_cogs'] - stats_before['total_cogs']
        profit_change = stats_after['total_profit'] - stats_before['total_profit']
        
        logger.info("📈 ИЗМЕНЕНИЯ:")
        logger.info(f"Изменение себестоимости: {cogs_change:+,.2f} руб.")
        logger.info(f"Изменение прибыли: {profit_change:+,.2f} руб.")
        
        if stats_after['total_revenue'] > 0:
            margin_percent = (stats_after['total_profit'] / stats_after['total_revenue']) * 100
            logger.info(f"Общая маржинальность: {margin_percent:.1f}%")
        
        logger.info(f"✅ Пересчет завершен: успешно {success_count}, ошибок {error_count}")
        
    except Exception as e:
        logger.error(f"❌ Критическая ошибка: {e}")
        if connection:
            connection.rollback()
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
