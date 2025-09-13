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


def aggregate_daily_metrics(date_to_process: str) -> bool:
    """
    Выполняет агрегацию метрик за указанную дату.
    
    Args:
        date_to_process (str): Дата для обработки в формате 'YYYY-MM-DD'
        
    Returns:
        bool: True если агрегация прошла успешно, False в случае ошибки
    """
    logger.info(f"Начинаем агрегацию метрик за дату: {date_to_process}")
    
    connection = None
    cursor = None
    
    try:
        # Устанавливаем соединение с базой данных
        connection = connect_to_db()
        cursor = connection.cursor()
        
        # SQL запрос для агрегации метрик
        sql_query = """
        INSERT INTO metrics_daily (client_id, metric_date, orders_cnt, revenue_sum, cogs_sum)
        SELECT
            client_id,
            %(date_param)s AS metric_date,
            COUNT(CASE WHEN transaction_type = 'продажа' THEN id END) AS orders_cnt,
            SUM(CASE WHEN transaction_type = 'продажа' THEN (qty * price) ELSE 0 END) AS revenue_sum,
            SUM(cost_price) AS cogs_sum
        FROM fact_orders
        WHERE order_date = %(date_param)s
        GROUP BY client_id
        ON DUPLICATE KEY UPDATE
            orders_cnt = VALUES(orders_cnt),
            revenue_sum = VALUES(revenue_sum),
            cogs_sum = VALUES(cogs_sum);
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
        if connection:
            connection.rollback()
        return False
        
    finally:
        # Закрываем соединение
        if cursor:
            cursor.close()
        if connection:
            connection.close()


def get_last_metrics_date() -> Optional[str]:
    """
    Получает последнюю дату из таблицы metrics_daily.
    
    Returns:
        Optional[str]: Последняя дата в формате 'YYYY-MM-DD' или None если таблица пуста
    """
    connection = None
    cursor = None
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor()
        
        cursor.execute("SELECT MAX(metric_date) FROM metrics_daily")
        result = cursor.fetchone()
        
        if result and result[0]:
            return result[0].strftime('%Y-%m-%d')
        return None
        
    except Exception as e:
        logger.error(f"Ошибка при получении последней даты из metrics_daily: {e}")
        return None
        
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()


def get_last_orders_date() -> Optional[str]:
    """
    Получает последнюю дату из таблицы fact_orders.
    
    Returns:
        Optional[str]: Последняя дата в формате 'YYYY-MM-DD' или None если таблица пуста
    """
    connection = None
    cursor = None
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor()
        
        cursor.execute("SELECT MAX(order_date) FROM fact_orders")
        result = cursor.fetchone()
        
        if result and result[0]:
            return result[0].strftime('%Y-%m-%d')
        return None
        
    except Exception as e:
        logger.error(f"Ошибка при получении последней даты из fact_orders: {e}")
        return None
        
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()


def get_dates_to_process() -> List[str]:
    """
    Определяет список дат, которые нужно обработать.
    
    Returns:
        List[str]: Список дат в формате 'YYYY-MM-DD'
    """
    last_metrics_date = get_last_metrics_date()
    last_orders_date = get_last_orders_date()
    
    if not last_orders_date:
        logger.warning("Нет данных в таблице fact_orders")
        return []
    
    # Если в metrics_daily нет данных, начинаем с самой ранней даты в fact_orders
    if not last_metrics_date:
        logger.info("Таблица metrics_daily пуста, начинаем с самой ранней даты")
        # Для начала обработаем только последнюю дату
        return [last_orders_date]
    
    # Определяем следующую дату после последней обработанной
    last_metrics_datetime = datetime.strptime(last_metrics_date, '%Y-%m-%d')
    next_date = last_metrics_datetime + timedelta(days=1)
    last_orders_datetime = datetime.strptime(last_orders_date, '%Y-%m-%d')
    
    dates_to_process = []
    current_date = next_date
    
    while current_date <= last_orders_datetime:
        dates_to_process.append(current_date.strftime('%Y-%m-%d'))
        current_date += timedelta(days=1)
    
    return dates_to_process


def main():
    """
    Основная функция скрипта.
    """
    logger.info("Запуск скрипта агрегации ежедневных метрик")
    
    # Определяем даты для обработки
    dates_to_process = get_dates_to_process()
    
    if not dates_to_process:
        logger.info("Нет дат для обработки")
        return
    
    logger.info(f"Найдено дат для обработки: {len(dates_to_process)}")
    logger.info(f"Даты: {', '.join(dates_to_process)}")
    
    # Обрабатываем каждую дату
    success_count = 0
    for date_str in dates_to_process:
        if aggregate_daily_metrics(date_str):
            success_count += 1
        else:
            logger.error(f"Не удалось обработать дату: {date_str}")
    
    logger.info(f"Обработка завершена. Успешно: {success_count}/{len(dates_to_process)}")


if __name__ == "__main__":
    # Тестируем для одной даты, за которую мы ТОЧНО загрузили данные
    aggregate_daily_metrics('2025-09-03')
