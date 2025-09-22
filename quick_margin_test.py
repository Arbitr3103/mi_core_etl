#!/usr/bin/env python3
"""
Быстрый тест системы расчета маржинальности.
Проверяет готовность системы и запускает базовые тесты.
"""

import sys
import os
import logging
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db
from run_aggregation import aggregate_daily_metrics

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def check_database_readiness():
    """Проверяет готовность базы данных к расчету маржинальности."""
    logger.info("🔍 Проверка готовности базы данных...")
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # 1. Проверяем наличие колонки margin_percent
        cursor.execute("""
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'metrics_daily' 
                AND COLUMN_NAME = 'margin_percent'
        """)
        
        has_margin_percent = cursor.fetchone() is not None
        logger.info(f"   Колонка margin_percent: {'✅' if has_margin_percent else '❌'}")
        
        if not has_margin_percent:
            logger.error("❌ Колонка margin_percent отсутствует!")
            logger.error("   Выполните: mysql -u root -p mi_core_db < add_margin_percent_column.sql")
            return False
        
        # 2. Проверяем наличие данных
        cursor.execute("SELECT COUNT(*) as count FROM fact_orders")
        orders_count = cursor.fetchone()['count']
        logger.info(f"   Заказов в fact_orders: {orders_count:,}")
        
        cursor.execute("SELECT COUNT(*) as count FROM fact_transactions")
        transactions_count = cursor.fetchone()['count']
        logger.info(f"   Транзакций в fact_transactions: {transactions_count:,}")
        
        cursor.execute("SELECT COUNT(*) as count FROM dim_products WHERE cost_price IS NOT NULL")
        products_with_cost = cursor.fetchone()['count']
        logger.info(f"   Товаров с себестоимостью: {products_with_cost:,}")
        
        # 3. Находим подходящую дату для тестирования
        cursor.execute("""
            SELECT 
                fo.order_date,
                COUNT(*) as orders_count,
                SUM(fo.qty * fo.price) as revenue
            FROM fact_orders fo
            WHERE fo.transaction_type = 'продажа'
                AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY fo.order_date
            HAVING orders_count >= 5
            ORDER BY fo.order_date DESC
            LIMIT 1
        """)
        
        test_date_info = cursor.fetchone()
        
        if test_date_info:
            test_date = test_date_info['order_date'].strftime('%Y-%m-%d')
            logger.info(f"   Тестовая дата: {test_date} ({test_date_info['orders_count']} заказов)")
            
            cursor.close()
            connection.close()
            return test_date
        else:
            logger.warning("⚠️  Не найдено подходящих дат для тестирования")
            cursor.close()
            connection.close()
            return None
            
    except Exception as e:
        logger.error(f"❌ Ошибка проверки базы данных: {e}")
        return False


def test_margin_calculation(test_date: str):
    """Тестирует расчет маржинальности для конкретной даты."""
    logger.info(f"🧪 Тестирование расчета маржинальности для {test_date}")
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # 1. Проверяем исходные данные
        cursor.execute("""
            SELECT 
                COUNT(*) as orders_count,
                SUM(qty * price) as total_revenue,
                COUNT(DISTINCT client_id) as clients_count
            FROM fact_orders 
            WHERE order_date = %s AND transaction_type = 'продажа'
        """, (test_date,))
        
        source_data = cursor.fetchone()
        logger.info(f"   Исходные данные за {test_date}:")
        logger.info(f"     - Заказов: {source_data['orders_count']}")
        logger.info(f"     - Выручка: {source_data['total_revenue']:.2f} руб")
        logger.info(f"     - Клиентов: {source_data['clients_count']}")
        
        # 2. Проверяем транзакции
        cursor.execute("""
            SELECT 
                COUNT(*) as transactions_count,
                SUM(ABS(amount)) as total_amount
            FROM fact_transactions 
            WHERE transaction_date = %s
        """, (test_date,))
        
        trans_data = cursor.fetchone()
        logger.info(f"     - Транзакций: {trans_data['transactions_count']}")
        logger.info(f"     - Сумма транзакций: {trans_data['total_amount']:.2f} руб")
        
        # 3. Удаляем старые результаты для этой даты
        cursor.execute("DELETE FROM metrics_daily WHERE metric_date = %s", (test_date,))
        connection.commit()
        
        # 4. Запускаем агрегацию
        logger.info("   🚀 Запуск агрегации...")
        success = aggregate_daily_metrics(connection, test_date)
        
        if not success:
            logger.error("❌ Агрегация завершилась с ошибкой")
            return False
        
        # 5. Проверяем результаты
        cursor.execute("""
            SELECT 
                client_id,
                orders_cnt,
                revenue_sum,
                cogs_sum,
                commission_sum,
                shipping_sum,
                profit_sum,
                margin_percent
            FROM metrics_daily 
            WHERE metric_date = %s
        """, (test_date,))
        
        results = cursor.fetchall()
        
        if not results:
            logger.error("❌ Нет результатов агрегации")
            return False
        
        logger.info(f"   📊 Результаты агрегации:")
        total_revenue = 0
        total_profit = 0
        
        for result in results:
            logger.info(f"     Клиент {result['client_id']}:")
            logger.info(f"       - Заказов: {result['orders_cnt']}")
            logger.info(f"       - Выручка: {result['revenue_sum']:.2f} руб")
            logger.info(f"       - Себестоимость: {result['cogs_sum']:.2f} руб")
            logger.info(f"       - Комиссии: {result['commission_sum']:.2f} руб")
            logger.info(f"       - Логистика: {result['shipping_sum']:.2f} руб")
            logger.info(f"       - Прибыль: {result['profit_sum']:.2f} руб")
            logger.info(f"       - Маржа: {result['margin_percent']:.2f}%")
            
            total_revenue += float(result['revenue_sum'])
            total_profit += float(result['profit_sum'])
        
        overall_margin = (total_profit / total_revenue * 100) if total_revenue > 0 else 0
        logger.info(f"   📈 Общие показатели:")
        logger.info(f"     - Общая выручка: {total_revenue:.2f} руб")
        logger.info(f"     - Общая прибыль: {total_profit:.2f} руб")
        logger.info(f"     - Общая маржа: {overall_margin:.2f}%")
        
        cursor.close()
        connection.close()
        
        logger.info("✅ Тест расчета маржинальности прошел успешно!")
        return True
        
    except Exception as e:
        logger.error(f"❌ Ошибка тестирования: {e}")
        return False


def main():
    """Основная функция быстрого теста."""
    logger.info("🚀 Быстрый тест системы расчета маржинальности")
    logger.info("=" * 60)
    
    # 1. Проверка готовности базы данных
    test_date = check_database_readiness()
    
    if not test_date:
        logger.error("❌ База данных не готова к тестированию")
        return False
    
    # 2. Тестирование расчета маржинальности
    success = test_margin_calculation(test_date)
    
    logger.info("=" * 60)
    if success:
        logger.info("🎉 БЫСТРЫЙ ТЕСТ ПРОШЕЛ УСПЕШНО!")
        logger.info("✅ Система расчета маржинальности работает корректно")
        logger.info("💡 Можно запускать полную агрегацию: python3 run_aggregation.py")
    else:
        logger.error("❌ ТЕСТ ЗАВЕРШИЛСЯ С ОШИБКАМИ")
        logger.error("⚠️  Проверьте логи и исправьте проблемы")
    
    return success


if __name__ == "__main__":
    success = main()
    exit(0 if success else 1)