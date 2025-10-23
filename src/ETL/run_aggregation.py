"""
Скрипт ежедневной агрегации метрик для витрины metrics_daily.

Автоматически рассчитывает итоговые дневные метрики и загружает их в таблицу metrics_daily.
Поддерживает как обработку конкретной даты, так и автоматическое определение дат для обработки.
"""

import os
import sys
import logging
from datetime import datetime, timedelta
from typing import List, Optional

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def calculate_margin_percentage(profit_sum: float, revenue_sum: float) -> Optional[float]:
    """
    Рассчитывает процент маржинальности.
    
    Args:
        profit_sum: Чистая прибыль
        revenue_sum: Общая выручка
        
    Returns:
        Процент маржинальности или None если выручка равна 0
    """
    if revenue_sum == 0 or revenue_sum is None:
        return None
    return (profit_sum / revenue_sum) * 100


def aggregate_daily_metrics(connection, date_to_process: str) -> bool:
    """
    Выполняет агрегацию метрик за указанную дату с полным расчетом маржинальности.
    
    Args:
        connection: Подключение к базе данных
        date_to_process (str): Дата для обработки в формате 'YYYY-MM-DD'
        
    Returns:
        bool: True если агрегация прошла успешно, False в случае ошибки
    """
    logger.info(f"Начинаем агрегацию метрик за дату: {date_to_process}")
    
    cursor = None
    
    try:
        cursor = connection.cursor()
        
        # Улучшенный SQL запрос для агрегации метрик с полным расчетом маржинальности
        sql_query = """
        INSERT INTO metrics_daily (
            client_id, metric_date, orders_cnt, revenue_sum, returns_sum, 
            cogs_sum, commission_sum, shipping_sum, other_expenses_sum, 
            profit_sum, margin_percent
        )
        SELECT
            fo.client_id,
            %(date_param)s AS metric_date,
            
            -- Базовые метрики продаж
            COUNT(CASE WHEN fo.transaction_type = 'продажа' THEN fo.id END) AS orders_cnt,
            SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) AS revenue_sum,
            SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) AS returns_sum,
            
            -- Себестоимость проданных товаров (COGS)
            SUM(CASE 
                WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
                THEN COALESCE(dp.cost_price * fo.qty, 0) 
                ELSE 0 
            END) AS cogs_sum,
            
            -- Комиссии маркетплейса и эквайринг
            COALESCE(commission_data.commission_sum, 0) AS commission_sum,
            
            -- Расходы на логистику и доставку
            COALESCE(logistics_data.shipping_sum, 0) AS shipping_sum,
            
            -- Прочие расходы
            COALESCE(other_data.other_expenses_sum, 0) AS other_expenses_sum,
            
            -- Расчет чистой прибыли
            (
                SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) - -- Выручка
                SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) - -- Возвраты
                SUM(CASE 
                    WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
                    THEN COALESCE(dp.cost_price * fo.qty, 0) 
                    ELSE 0 
                END) - -- Себестоимость
                COALESCE(commission_data.commission_sum, 0) - -- Комиссии
                COALESCE(logistics_data.shipping_sum, 0) - -- Логистика
                COALESCE(other_data.other_expenses_sum, 0) -- Прочие расходы
            ) AS profit_sum,
            
            -- Расчет процента маржинальности
            CASE 
                WHEN SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) > 0 
                THEN (
                    (
                        SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) - -- Выручка
                        SUM(CASE WHEN fo.transaction_type = 'возврат' THEN (fo.qty * fo.price) ELSE 0 END) - -- Возвраты
                        SUM(CASE 
                            WHEN fo.transaction_type = 'продажа' AND dp.cost_price IS NOT NULL 
                            THEN COALESCE(dp.cost_price * fo.qty, 0) 
                            ELSE 0 
                        END) - -- Себестоимость
                        COALESCE(commission_data.commission_sum, 0) - -- Комиссии
                        COALESCE(logistics_data.shipping_sum, 0) - -- Логистика
                        COALESCE(other_data.other_expenses_sum, 0) -- Прочие расходы
                    ) / SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END)
                ) * 100
                ELSE NULL 
            END AS margin_percent

        FROM fact_orders fo

        -- JOIN с таблицей товаров для получения себестоимости
        LEFT JOIN dim_products dp ON fo.product_id = dp.id

        -- Подзапрос для агрегации комиссий и эквайринга
        LEFT JOIN (
            SELECT 
                ft.client_id,
                SUM(ABS(ft.amount)) AS commission_sum
            FROM fact_transactions ft
            WHERE ft.transaction_date = %(date_param)s
                AND (
                    ft.transaction_type LIKE '%%комиссия%%' OR
                    ft.transaction_type LIKE '%%эквайринг%%' OR
                    ft.transaction_type LIKE '%%commission%%' OR
                    ft.transaction_type LIKE '%%fee%%' OR
                    ft.transaction_type LIKE '%%OperationMarketplaceServiceItemFulfillment%%'
                )
            GROUP BY ft.client_id
        ) commission_data ON fo.client_id = commission_data.client_id

        -- Подзапрос для агрегации логистических расходов
        LEFT JOIN (
            SELECT 
                ft.client_id,
                SUM(ABS(ft.amount)) AS shipping_sum
            FROM fact_transactions ft
            WHERE ft.transaction_date = %(date_param)s
                AND (
                    ft.transaction_type LIKE '%%логистика%%' OR
                    ft.transaction_type LIKE '%%доставка%%' OR
                    ft.transaction_type LIKE '%%delivery%%' OR
                    ft.transaction_type LIKE '%%shipping%%' OR
                    ft.transaction_type LIKE '%%OperationMarketplaceServiceItemDeliveryToCustomer%%'
                )
            GROUP BY ft.client_id
        ) logistics_data ON fo.client_id = logistics_data.client_id

        -- Подзапрос для прочих расходов
        LEFT JOIN (
            SELECT 
                ft.client_id,
                SUM(ABS(ft.amount)) AS other_expenses_sum
            FROM fact_transactions ft
            WHERE ft.transaction_date = %(date_param)s
                AND ft.transaction_type NOT LIKE '%%комиссия%%'
                AND ft.transaction_type NOT LIKE '%%эквайринг%%'
                AND ft.transaction_type NOT LIKE '%%commission%%'
                AND ft.transaction_type NOT LIKE '%%fee%%'
                AND ft.transaction_type NOT LIKE '%%логистика%%'
                AND ft.transaction_type NOT LIKE '%%доставка%%'
                AND ft.transaction_type NOT LIKE '%%delivery%%'
                AND ft.transaction_type NOT LIKE '%%shipping%%'
                AND ft.transaction_type NOT LIKE '%%возврат%%'
                AND ft.transaction_type NOT LIKE '%%return%%'
                AND ft.transaction_type NOT LIKE '%%OperationMarketplaceServiceItemFulfillment%%'
                AND ft.transaction_type NOT LIKE '%%OperationMarketplaceServiceItemDeliveryToCustomer%%'
                AND ft.transaction_type NOT LIKE '%%OperationMarketplaceServiceItemReturn%%'
                AND ft.amount < 0 -- Только расходные операции
            GROUP BY ft.client_id
        ) other_data ON fo.client_id = other_data.client_id

        WHERE fo.order_date = %(date_param)s
        GROUP BY fo.client_id
        
        ON DUPLICATE KEY UPDATE
            orders_cnt = VALUES(orders_cnt),
            revenue_sum = VALUES(revenue_sum),
            returns_sum = VALUES(returns_sum),
            cogs_sum = VALUES(cogs_sum),
            commission_sum = VALUES(commission_sum),
            shipping_sum = VALUES(shipping_sum),
            other_expenses_sum = VALUES(other_expenses_sum),
            profit_sum = VALUES(profit_sum),
            margin_percent = VALUES(margin_percent);
        """
        
        # Параметры для запроса
        params = {'date_param': date_to_process}
        
        # Выполняем запрос
        cursor.execute(sql_query, params)
        affected_rows = cursor.rowcount
        
        # Фиксируем изменения
        connection.commit()
        
        logger.info(f"Агрегация завершена успешно. Обработано записей: {affected_rows}")
        
        # Логируем детали для отладки
        if affected_rows > 0:
            cursor.execute("""
                SELECT 
                    client_id, 
                    revenue_sum, 
                    profit_sum, 
                    margin_percent,
                    cogs_sum,
                    commission_sum,
                    shipping_sum
                FROM metrics_daily 
                WHERE metric_date = %s
            """, (date_to_process,))
            
            results = cursor.fetchall()
            for result in results:
                logger.info(f"Клиент {result[0]}: Выручка={result[1]:.2f}, Прибыль={result[2]:.2f}, Маржа={result[3]:.2f}%")
        
        return True
        
    except Exception as e:
        logger.error(f"Ошибка при агрегации метрик за дату {date_to_process}: {e}")
        logger.error(f"Детали ошибки: {str(e)}")
        connection.rollback()
        return False
        
    finally:
        # Закрываем только cursor, connection остается открытым
        if cursor:
            cursor.close()


def get_last_aggregated_date(cursor) -> Optional[str]:
    """
    Получает последнюю дату из таблицы metrics_daily.
    
    Args:
        cursor: Курсор базы данных
    
    Returns:
        Optional[str]: Последняя дата в формате 'YYYY-MM-DD' или None если таблица пуста
    """
    try:
        cursor.execute("SELECT MAX(metric_date) FROM metrics_daily")
        result = cursor.fetchone()
        
        if result and result[0]:
            return result[0].strftime('%Y-%m-%d')
        return None
        
    except Exception as e:
        logger.error(f"Ошибка при получении последней даты из metrics_daily: {e}")
        return None


def get_last_order_date(cursor) -> Optional[str]:
    """
    Получает последнюю дату из таблицы fact_orders.
    
    Args:
        cursor: Курсор базы данных
    
    Returns:
        Optional[str]: Последняя дата в формате 'YYYY-MM-DD' или None если таблица пуста
    """
    try:
        cursor.execute("SELECT MAX(order_date) FROM fact_orders")
        result = cursor.fetchone()
        
        if result and result[0]:
            return result[0].strftime('%Y-%m-%d')
        return None
        
    except Exception as e:
        logger.error(f"Ошибка при получении последней даты из fact_orders: {e}")
        return None


def get_dates_to_process(cursor) -> List[str]:
    """
    Определяет список дат, которые нужно обработать.
    
    Args:
        cursor: Курсор базы данных
    
    Returns:
        List[str]: Список дат в формате 'YYYY-MM-DD'
    """
    last_agg_date = get_last_aggregated_date(cursor)
    last_ord_date = get_last_order_date(cursor)
    
    if not last_ord_date:
        logger.warning("Нет данных в таблице fact_orders")
        return []
    
    # Определяем начальную дату для цикла
    if last_agg_date:
        # Начинаем со следующего дня после последней агрегации
        last_agg_datetime = datetime.strptime(last_agg_date, '%Y-%m-%d')
        start_date = last_agg_datetime + timedelta(days=1)
    else:
        # Если в metrics_daily еще ничего нет, начинаем с самой первой даты в заказах
        logger.info("Таблица metrics_daily пуста, ищем самую раннюю дату в fact_orders")
        cursor.execute("SELECT MIN(order_date) FROM fact_orders")
        result = cursor.fetchone()
        if result and result[0]:
            start_date = result[0]
        else:
            logger.warning("Нет данных в fact_orders")
            return []
    
    # Проходим в цикле от начальной даты до последней даты с заказами
    dates_to_process = []
    last_ord_datetime = datetime.strptime(last_ord_date, '%Y-%m-%d')
    
    if isinstance(start_date, str):
        current_date = datetime.strptime(start_date, '%Y-%m-%d')
    else:
        current_date = start_date
    
    while current_date <= last_ord_datetime:
        dates_to_process.append(current_date.strftime('%Y-%m-%d'))
        current_date += timedelta(days=1)
    
    return dates_to_process


def main():
    """
    Основная функция скрипта.
    """
    logger.info("Запуск скрипта агрегации ежедневных метрик")
    
    connection = None
    cursor = None
    
    try:
        # Устанавливаем соединение с базой данных
        connection = connect_to_db()
        cursor = connection.cursor()
        
        # Определяем даты для обработки
        dates_to_process = get_dates_to_process(cursor)
        
        if not dates_to_process:
            logger.info("Нет дат для обработки")
            return
        
        logger.info(f"Найдено дат для обработки: {len(dates_to_process)}")
        logger.info(f"Даты: {', '.join(dates_to_process)}")
        
        # Обрабатываем каждую дату
        success_count = 0
        for date_str in dates_to_process:
            logger.info(f"--- Запускаем агрегацию для {date_str} ---")
            if aggregate_daily_metrics(connection, date_str):
                success_count += 1
            else:
                logger.error(f"Не удалось обработать дату: {date_str}")
        
        logger.info(f"Обработка завершена. Успешно: {success_count}/{len(dates_to_process)}")
        
    except Exception as e:
        logger.error(f"Критическая ошибка в main(): {e}")
        
    finally:
        # Закрываем соединение
        if cursor:
            cursor.close()
        if connection:
            connection.close()


if __name__ == "__main__":
    main()
