#!/usr/bin/env python3
"""
Скрипт для просмотра данных в таблице fact_orders.
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

def view_recent_orders(limit=10):
    """Показывает последние загруженные заказы."""
    print(f'=== ПОСЛЕДНИЕ {limit} ЗАКАЗОВ ===')
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # Получаем последние заказы
        cursor.execute("""
            SELECT 
                fo.id,
                fo.order_id,
                fo.sku,
                fo.qty,
                fo.price,
                fo.order_date,
                fo.created_at,
                c.name as client_name,
                s.name as source_name,
                dp.product_name
            FROM fact_orders fo
            LEFT JOIN clients c ON fo.client_id = c.id
            LEFT JOIN sources s ON fo.source_id = s.id
            LEFT JOIN dim_products dp ON fo.product_id = dp.id
            ORDER BY fo.created_at DESC
            LIMIT %s
        """, (limit,))
        
        orders = cursor.fetchall()
        
        if not orders:
            print("Нет данных в fact_orders")
            return
        
        for order in orders:
            print(f"""
Заказ #{order['id']}:
  Номер заказа: {order['order_id']}
  Товар: {order['product_name'] or order['sku']}
  Количество: {order['qty']}
  Цена: {order['price']} руб.
  Дата заказа: {order['order_date']}
  Клиент: {order['client_name']}
  Источник: {order['source_name']}
  Загружено: {order['created_at']}
""")
        
        cursor.close()
        connection.close()
        
    except Exception as e:
        print(f"❌ Ошибка: {e}")

def view_orders_stats():
    """Показывает статистику по заказам."""
    print('=== СТАТИСТИКА ЗАКАЗОВ ===')
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # Общая статистика
        cursor.execute("""
            SELECT 
                COUNT(*) as total_orders,
                COUNT(DISTINCT order_id) as unique_orders,
                SUM(qty) as total_qty,
                SUM(price * qty) as total_amount,
                MIN(order_date) as first_date,
                MAX(order_date) as last_date
            FROM fact_orders
        """)
        
        stats = cursor.fetchone()
        
        print(f"""
Общая статистика:
  Всего записей: {stats['total_orders']}
  Уникальных заказов: {stats['unique_orders']}
  Общее количество товаров: {stats['total_qty']}
  Общая сумма: {stats['total_amount']:.2f} руб.
  Период: с {stats['first_date']} по {stats['last_date']}
""")
        
        # Статистика по дням
        cursor.execute("""
            SELECT 
                order_date,
                COUNT(*) as orders_count,
                SUM(qty) as total_qty,
                SUM(price * qty) as total_amount
            FROM fact_orders 
            GROUP BY order_date 
            ORDER BY order_date DESC
            LIMIT 7
        """)
        
        daily_stats = cursor.fetchall()
        
        print("Статистика по дням (последние 7 дней):")
        for day in daily_stats:
            print(f"  {day['order_date']}: {day['orders_count']} заказов, {day['total_qty']} шт., {day['total_amount']:.2f} руб.")
        
        cursor.close()
        connection.close()
        
    except Exception as e:
        print(f"❌ Ошибка: {e}")

if __name__ == "__main__":
    print("🔍 ПРОСМОТР ДАННЫХ В fact_orders")
    
    # Показываем статистику
    view_orders_stats()
    
    # Показываем последние заказы
    view_recent_orders(5)
