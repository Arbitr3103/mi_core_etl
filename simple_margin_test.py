#!/usr/bin/env python3
"""
Упрощенный тест системы расчета маржинальности.
"""

import mysql.connector
from config_local import DB_CONFIG

def simple_test():
    print("🧪 Упрощенный тест расчета маржинальности")
    print("=" * 50)
    
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor(dictionary=True)
        
        # 1. Проверяем данные
        print("📊 Проверка данных:")
        
        cursor.execute("SELECT COUNT(*) as count FROM fact_orders WHERE transaction_type = 'sale'")
        sales_count = cursor.fetchone()['count']
        print(f"   Продаж: {sales_count}")
        
        cursor.execute("SELECT COUNT(*) as count FROM fact_transactions")
        trans_count = cursor.fetchone()['count']
        print(f"   Транзакций: {trans_count}")
        
        # 2. Простой расчет маржинальности для 2024-09-20
        test_date = '2024-09-20'
        print(f"\n🚀 Расчет маржинальности для {test_date}:")
        
        # Очищаем старые результаты
        cursor.execute("DELETE FROM metrics_daily WHERE metric_date = %s", (test_date,))
        
        # Упрощенный расчет
        simple_query = """
        INSERT INTO metrics_daily (
            client_id, metric_date, orders_cnt, revenue_sum, cogs_sum, 
            commission_sum, shipping_sum, profit_sum, margin_percent
        )
        SELECT
            fo.client_id,
            %s AS metric_date,
            COUNT(*) AS orders_cnt,
            SUM(fo.qty * fo.price) AS revenue_sum,
            SUM(COALESCE(dp.cost_price * fo.qty, 0)) AS cogs_sum,
            COALESCE(comm.commission_sum, 0) AS commission_sum,
            COALESCE(ship.shipping_sum, 0) AS shipping_sum,
            (
                SUM(fo.qty * fo.price) - 
                SUM(COALESCE(dp.cost_price * fo.qty, 0)) - 
                COALESCE(comm.commission_sum, 0) - 
                COALESCE(ship.shipping_sum, 0)
            ) AS profit_sum,
            CASE 
                WHEN SUM(fo.qty * fo.price) > 0 
                THEN (
                    (SUM(fo.qty * fo.price) - 
                     SUM(COALESCE(dp.cost_price * fo.qty, 0)) - 
                     COALESCE(comm.commission_sum, 0) - 
                     COALESCE(ship.shipping_sum, 0)
                    ) * 100.0 / SUM(fo.qty * fo.price)
                )
                ELSE NULL 
            END AS margin_percent
        FROM fact_orders fo
        LEFT JOIN dim_products dp ON fo.product_id = dp.id
        LEFT JOIN (
            SELECT client_id, SUM(ABS(amount)) AS commission_sum
            FROM fact_transactions 
            WHERE transaction_date = %s 
                AND transaction_type IN ('commission', 'fee')
            GROUP BY client_id
        ) comm ON fo.client_id = comm.client_id
        LEFT JOIN (
            SELECT client_id, SUM(ABS(amount)) AS shipping_sum
            FROM fact_transactions 
            WHERE transaction_date = %s 
                AND transaction_type IN ('shipping', 'delivery', 'logistics')
            GROUP BY client_id
        ) ship ON fo.client_id = ship.client_id
        WHERE fo.order_date = %s AND fo.transaction_type = 'sale'
        GROUP BY fo.client_id
        """
        
        cursor.execute(simple_query, (test_date, test_date, test_date, test_date))
        connection.commit()
        
        print("✅ Расчет выполнен")
        
        # 3. Показываем результаты
        cursor.execute("""
            SELECT 
                client_id,
                orders_cnt,
                ROUND(revenue_sum, 2) as revenue,
                ROUND(cogs_sum, 2) as cogs,
                ROUND(commission_sum, 2) as commission,
                ROUND(shipping_sum, 2) as shipping,
                ROUND(profit_sum, 2) as profit,
                ROUND(margin_percent, 2) as margin_pct
            FROM metrics_daily 
            WHERE metric_date = %s
        """, (test_date,))
        
        results = cursor.fetchall()
        
        print("\n📊 Результаты:")
        for result in results:
            print(f"   Клиент {result['client_id']}:")
            print(f"     Заказов: {result['orders_cnt']}")
            print(f"     Выручка: {result['revenue']} руб")
            print(f"     Себестоимость: {result['cogs']} руб")
            print(f"     Комиссии: {result['commission']} руб")
            print(f"     Логистика: {result['shipping']} руб")
            print(f"     Прибыль: {result['profit']} руб")
            print(f"     Маржа: {result['margin_pct']}%")
        
        # 4. Проверяем корректность
        if results:
            total_revenue = sum(float(r['revenue']) for r in results)
            total_profit = sum(float(r['profit']) for r in results)
            overall_margin = (total_profit / total_revenue * 100) if total_revenue > 0 else 0
            
            print(f"\n📈 Итого:")
            print(f"   Общая выручка: {total_revenue:.2f} руб")
            print(f"   Общая прибыль: {total_profit:.2f} руб")
            print(f"   Общая маржа: {overall_margin:.2f}%")
            
            # Ожидаемые значения для тестовых данных 2024-09-20:
            # Выручка: 2*250 + 1*400 + 3*350 = 500 + 400 + 1050 = 1950
            # Себестоимость: 2*100 + 1*150 + 3*200 = 200 + 150 + 600 = 950
            # Комиссии: 50 + 8 + 105 = 163
            # Логистика: 30 + 25 + 45 = 100
            # Прибыль: 1950 - 950 - 163 - 100 = 737
            # Маржа: 737/1950 * 100 = 37.79%
            
            expected_revenue = 1950.0
            expected_profit = 737.0
            expected_margin = 37.79
            
            if (abs(total_revenue - expected_revenue) < 1.0 and 
                abs(total_profit - expected_profit) < 1.0 and
                abs(overall_margin - expected_margin) < 1.0):
                print("\n✅ ТЕСТ ПРОШЕЛ УСПЕШНО!")
                print("   Результаты соответствуют ожиданиям")
                return True
            else:
                print("\n⚠️  Результаты отличаются от ожидаемых:")
                print(f"   Выручка: получено {total_revenue}, ожидалось {expected_revenue}")
                print(f"   Прибыль: получено {total_profit}, ожидалось {expected_profit}")
                print(f"   Маржа: получено {overall_margin:.2f}%, ожидалось {expected_margin}%")
                return False
        else:
            print("\n❌ Нет результатов")
            return False
            
    except Exception as e:
        print(f"\n❌ Ошибка: {e}")
        return False
        
    finally:
        cursor.close()
        connection.close()

if __name__ == "__main__":
    success = simple_test()
    exit(0 if success else 1)