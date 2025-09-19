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


def aggregate_daily_metrics(connection, date_to_process: str) -> bool:
    """
    Выполняет агрегацию метрик за указанную дату.
    
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
        
        # SQL запрос для агрегации метрик с расчетом маржинальности
        sql_query = """
        INSERT INTO metrics_daily (client_id, metric_date, orders_cnt, revenue_sum, cogs_sum, profit_sum)
        SELECT
            client_id,
            %(date_param)s AS metric_date,
            COUNT(CASE WHEN transaction_type = 'продажа' THEN id END) AS orders_cnt,
            SUM(CASE WHEN transaction_type = 'продажа' THEN (qty * price) ELSE 0 END) AS revenue_sum,
            SUM(CASE WHEN transaction_type = 'продажа' THEN cost_price ELSE 0 END) AS cogs_sum,
            SUM(CASE WHEN transaction_type = 'продажа' THEN (qty * price) ELSE 0 END) - 
            SUM(CASE WHEN transaction_type = 'продажа' THEN COALESCE(cost_price, 0) ELSE 0 END) AS profit_sum
        FROM fact_orders
        WHERE order_date = %(date_param)s
        GROUP BY client_id
        ON DUPLICATE KEY UPDATE
            orders_cnt = VALUES(orders_cnt),
            revenue_sum = VALUES(revenue_sum),
            cogs_sum = VALUES(cogs_sum),
            profit_sum = VALUES(profit_sum);
        """
        
        # Параметры для запроса
        params = {'date_param': date_to_process}
        
        # Выполняем запрос
        cursor.execute(sql_query, params)
        affected_rows = cursor.rowcount
        
        # Фиксируем изменения
        connection.commit()
        
        logger.info(f"Агрегация завершена успешно. Обработано записей: {affected_rows}")
        return True
        
    except Exception as e:
        logger.error(f"Ошибка при агрегации метрик за дату {date_to_process}: {e}")
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
