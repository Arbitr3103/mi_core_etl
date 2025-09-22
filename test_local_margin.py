#!/usr/bin/env python3
"""
Локальное тестирование системы расчета маржинальности.
Использует локальную MySQL в Docker.
"""

import sys
import os
import logging
import mysql.connector
from datetime import datetime

# Используем локальную конфигурацию
from config_local import DB_CONFIG

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def connect_to_local_db():
    """Подключение к локальной базе данных."""
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        return connection
    except Exception as e:
        logger.error(f"Ошибка подключения к локальной БД: {e}")
        return None


def test_local_margin_calculation():
    """Тестирует расчет маржинальности на локальных данных."""
    logger.info("🧪 Тестирование расчета маржинальности (локально)")
    logger.info("=" * 60)
    
    connection = connect_to_local_db()
    if not connection:
        logger.error("❌ Не удалось подключиться к локальной БД")
        return False
    
    try:
        cursor = connection.cursor(dictionary=True)
        
        # 1. Проверяем готовность схемы
        logger.info("🔍 Проверка готовности схемы...")
        
        cursor.execute("""
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = 'metrics_daily' 
                AND COLUMN_NAME = 'margin_percent'
        """, (DB_CONFIG['database'],))
        
        has_margin_percent = cursor.fetchone() is not None
        logger.info(f"   Колонка margin_percent: {'✅' if has_margin_percent else '❌'}")
        
        if not has_margin_percent:
            logger.error("❌ Колонка margin_percent отсутствует!")
            return False
        
        # 2. Проверяем тестовые данные
        logger.info("📊 Проверка тестовых данных...")
        
        cursor.execute("SELECT COUNT(*) as count FROM fact_orders WHERE transaction_type = 'sale'")
        orders_count = cursor.fetchone()['count']
        logger.info(f"   Заказов (продажи): {orders_count}")
        
        if orders_count == 0:
            logger.error("❌ Нет заказов в тестовых данных!")
            # Проверим, что вообще есть в таблице
            cursor.execute("SELECT COUNT(*) as count FROM fact_orders")
            total_orders = cursor.fetchone()['count']
            logger.info(f"   Всего заказов: {total_orders}")
            
            if total_orders > 0:
                cursor.execute("SELECT DISTINCT transaction_type FROM fact_orders")
                types = cursor.fetchall()
                logger.info(f"   Типы транзакций: {[t['transaction_type'] for t in types]}")
            
            return False
        
        cursor.execute("SELECT COUNT(*) as count FROM fact_transactions")
        transactions_count = cursor.fetchone()['count']
        logger.info(f"   Транзакций: {transactions_count}")
        
        cursor.execute("SELECT COUNT(*) as count FROM dim_products WHERE cost_price IS NOT NULL")
        products_with_cost = cursor.fetchone()['count']
        logger.info(f"   Товаров с себестоимостью: {products_with_cost}")
        
        # 3. Тестируем расчет для даты 2024-09-20
        test_date = '2024-09-20'
        logger.info(f"🚀 Тестирование расчета для {test_date}...")
        
        # Очищаем старые результаты
        cursor.execute("DELETE FROM metrics_daily WHERE metric_date = %s", (test_date,))
        connection.commit()
        
        # Выполняем расчет маржинальности (упрощенная версия)
        margin_query = """
        INSERT INTO metrics_daily (
            client_id, metric_date, orders_cnt, revenue_sum, returns_sum, 
            cogs_sum, commission_sum, shipping_sum, other_expenses_sum, 
            profit_sum, margin_percent
        )
        SELECT
            fo.client_id,
            %s AS metric_date,
            
            -- Базовые метрики продаж
            COUNT(CASE WHEN fo.transaction_type = 'sale' THEN fo.id END) AS orders_cnt,
            SUM(CASE WHEN fo.transaction_type = 'sale' THEN (fo.qty * fo.price) ELSE 0 END) AS revenue_sum,
            SUM(CASE WHEN fo.transaction_type = 'return' THEN (fo.qty * fo.price) ELSE 0 END) AS returns_sum,
            
            -- Себестоимость проданных товаров (COGS)
            SUM(CASE 
                WHEN fo.transaction_type = 'sale' AND dp.cost_price IS NOT NULL 
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
                SUM(CASE WHEN fo.transaction_type = 'sale' THEN (fo.qty * fo.price) ELSE 0 END) - -- Выручка
                SUM(CASE WHEN fo.transaction_type = 'return' THEN (fo.qty * fo.price) ELSE 0 END) - -- Возвраты
                SUM(CASE 
                    WHEN fo.transaction_type = 'sale' AND dp.cost_price IS NOT NULL 
                    THEN COALESCE(dp.cost_price * fo.qty, 0) 
                    ELSE 0 
                END) - -- Себестоимость
                COALESCE(commission_data.commission_sum, 0) - -- Комиссии
                COALESCE(logistics_data.shipping_sum, 0) - -- Логистика
                COALESCE(other_data.other_expenses_sum, 0) -- Прочие расходы
            ) AS profit_sum,
            
            -- Расчет процента маржинальности
            CASE 
                WHEN SUM(CASE WHEN fo.transaction_type = 'sale' THEN (fo.qty * fo.price) ELSE 0 END) > 0 
                THEN (
                    (
                        SUM(CASE WHEN fo.transaction_type = 'sale' THEN (fo.qty * fo.price) ELSE 0 END) - -- Выручка
                        SUM(CASE WHEN fo.transaction_type = 'return' THEN (fo.qty * fo.price) ELSE 0 END) - -- Возвраты
                        SUM(CASE 
                            WHEN fo.transaction_type = 'sale' AND dp.cost_price IS NOT NULL 
                            THEN COALESCE(dp.cost_price * fo.qty, 0) 
                            ELSE 0 
                        END) - -- Себестоимость
                        COALESCE(commission_data.commission_sum, 0) - -- Комиссии
                        COALESCE(logistics_data.shipping_sum, 0) - -- Логистика
                        COALESCE(other_data.other_expenses_sum, 0) -- Прочие расходы
                    ) / SUM(CASE WHEN fo.transaction_type = 'sale' THEN (fo.qty * fo.price) ELSE 0 END)
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
            WHERE ft.transaction_date = %s
                AND (
                    ft.transaction_type LIKE '%%commission%%' OR
                    ft.transaction_type LIKE '%%fee%%'
                )
            GROUP BY ft.client_id
        ) commission_data ON fo.client_id = commission_data.client_id

        -- Подзапрос для агрегации логистических расходов
        LEFT JOIN (
            SELECT 
                ft.client_id,
                SUM(ABS(ft.amount)) AS shipping_sum
            FROM fact_transactions ft
            WHERE ft.transaction_date = %s
                AND (
                    ft.transaction_type LIKE '%%logistics%%' OR
                    ft.transaction_type LIKE '%%delivery%%' OR
                    ft.transaction_type LIKE '%%shipping%%'
                )
            GROUP BY ft.client_id
        ) logistics_data ON fo.client_id = logistics_data.client_id

        -- Подзапрос для прочих расходов
        LEFT JOIN (
            SELECT 
                ft.client_id,
                SUM(ABS(ft.amount)) AS other_expenses_sum
            FROM fact_transactions ft
            WHERE ft.transaction_date = %s
                AND ft.transaction_type NOT LIKE '%%commission%%'
                AND ft.transaction_type NOT LIKE '%%fee%%'
                AND ft.transaction_type NOT LIKE '%%logistics%%'
                AND ft.transaction_type NOT LIKE '%%delivery%%'
                AND ft.transaction_type NOT LIKE '%%shipping%%'
                AND ft.transaction_type NOT LIKE '%%return%%'
                AND ft.amount < 0 -- Только расходные операции
            GROUP BY ft.client_id
        ) other_data ON fo.client_id = other_data.client_id

        WHERE fo.order_date = %s
        GROUP BY fo.client_id
        """
        
        cursor.execute(margin_query, (test_date, test_date, test_date, test_date, test_date))
        connection.commit()
        
        logger.info("✅ Расчет маржинальности выполнен")
        
        # 4. Проверяем результаты
        logger.info("📊 Результаты расчета:")
        
        cursor.execute("""
            SELECT 
                client_id,
                orders_cnt,
                ROUND(revenue_sum, 2) as revenue_sum,
                ROUND(cogs_sum, 2) as cogs_sum,
                ROUND(commission_sum, 2) as commission_sum,
                ROUND(shipping_sum, 2) as shipping_sum,
                ROUND(profit_sum, 2) as profit_sum,
                ROUND(margin_percent, 2) as margin_percent
            FROM metrics_daily 
            WHERE metric_date = %s
        """, (test_date,))
        
        results = cursor.fetchall()
        
        if not results:
            logger.error("❌ Нет результатов расчета")
            return False
        
        total_revenue = 0
        total_profit = 0
        
        for result in results:
            logger.info(f"   Клиент {result['client_id']}:")
            logger.info(f"     - Заказов: {result['orders_cnt']}")
            logger.info(f"     - Выручка: {result['revenue_sum']} руб")
            logger.info(f"     - Себестоимость: {result['cogs_sum']} руб")
            logger.info(f"     - Комиссии: {result['commission_sum']} руб")
            logger.info(f"     - Логистика: {result['shipping_sum']} руб")
            logger.info(f"     - Прибыль: {result['profit_sum']} руб")
            logger.info(f"     - Маржа: {result['margin_percent']}%")
            
            total_revenue += float(result['revenue_sum'])
            total_profit += float(result['profit_sum'])
        
        overall_margin = (total_profit / total_revenue * 100) if total_revenue > 0 else 0
        
        logger.info("📈 Общие показатели:")
        logger.info(f"   - Общая выручка: {total_revenue:.2f} руб")
        logger.info(f"   - Общая прибыль: {total_profit:.2f} руб")
        logger.info(f"   - Общая маржа: {overall_margin:.2f}%")
        
        # 5. Валидация результатов
        logger.info("🔍 Валидация результатов...")
        
        # Ожидаемые результаты для тестовых данных:
        # Клиент 1: Выручка = 500 + 400 + 1050 = 1950, Себестоимость = 200 + 150 + 600 = 950
        # Комиссии = 50 + 8 + 105 = 163, Логистика = 30 + 25 + 45 = 100
        # Прибыль = 1950 - 950 - 163 - 100 = 737, Маржа = 737/1950 * 100 = 37.79%
        
        expected_revenue = 1950.0  # 2*250 + 1*400 + 3*350
        expected_cogs = 950.0      # 2*100 + 1*150 + 3*200
        expected_commission = 163.0 # 50 + 8 + 105
        expected_shipping = 100.0   # 30 + 25 + 45
        expected_profit = 737.0     # 1950 - 950 - 163 - 100
        expected_margin = 37.79     # 737/1950 * 100
        
        tolerance = 0.1
        
        if (abs(total_revenue - expected_revenue) < tolerance and 
            abs(total_profit - expected_profit) < tolerance and
            abs(overall_margin - expected_margin) < tolerance):
            logger.info("✅ Валидация прошла успешно - результаты соответствуют ожиданиям")
            return True
        else:
            logger.warning("⚠️  Результаты отличаются от ожидаемых:")
            logger.warning(f"   Выручка: получено {total_revenue}, ожидалось {expected_revenue}")
            logger.warning(f"   Прибыль: получено {total_profit}, ожидалось {expected_profit}")
            logger.warning(f"   Маржа: получено {overall_margin:.2f}%, ожидалось {expected_margin}%")
            return False
        
    except Exception as e:
        logger.error(f"❌ Ошибка тестирования: {e}")
        return False
        
    finally:
        cursor.close()
        connection.close()


def main():
    """Основная функция локального тестирования."""
    logger.info("🚀 Локальное тестирование системы расчета маржинальности")
    logger.info("=" * 60)
    
    success = test_local_margin_calculation()
    
    logger.info("=" * 60)
    if success:
        logger.info("🎉 ЛОКАЛЬНОЕ ТЕСТИРОВАНИЕ ПРОШЛО УСПЕШНО!")
        logger.info("✅ Система расчета маржинальности работает корректно")
        logger.info("💡 Можно применять на продакшн-сервере")
    else:
        logger.error("❌ ЛОКАЛЬНОЕ ТЕСТИРОВАНИЕ ЗАВЕРШИЛОСЬ С ОШИБКАМИ")
        logger.error("⚠️  Проверьте логи и исправьте проблемы")
    
    return success


if __name__ == "__main__":
    success = main()
    exit(0 if success else 1)