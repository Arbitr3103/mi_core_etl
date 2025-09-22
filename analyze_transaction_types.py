#!/usr/bin/env python3
"""
Скрипт для анализа типов транзакций в таблице fact_transactions.
Помогает понять, какие типы транзакций у нас есть и как их классифицировать для расчета маржинальности.
"""

import sys
import os
from collections import Counter

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

def analyze_transaction_types():
    """Анализирует типы транзакций в базе данных."""
    
    connection = None
    cursor = None
    
    try:
        print("🔍 Анализ типов транзакций в fact_transactions")
        print("=" * 60)
        
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # 1. Общая статистика по таблице
        cursor.execute("SELECT COUNT(*) as total_count FROM fact_transactions")
        total_result = cursor.fetchone()
        print(f"Общее количество транзакций: {total_result['total_count']}")
        
        # 2. Анализ типов транзакций
        print("\n📊 Типы транзакций и их количество:")
        print("-" * 60)
        
        cursor.execute("""
            SELECT 
                transaction_type,
                COUNT(*) as count,
                SUM(amount) as total_amount,
                AVG(amount) as avg_amount,
                MIN(amount) as min_amount,
                MAX(amount) as max_amount
            FROM fact_transactions 
            GROUP BY transaction_type 
            ORDER BY count DESC
        """)
        
        transaction_types = cursor.fetchall()
        
        for tx_type in transaction_types:
            print(f"Тип: {tx_type['transaction_type']}")
            print(f"  Количество: {tx_type['count']}")
            print(f"  Общая сумма: {tx_type['total_amount']:.2f}")
            print(f"  Средняя сумма: {tx_type['avg_amount']:.2f}")
            print(f"  Диапазон: {tx_type['min_amount']:.2f} - {tx_type['max_amount']:.2f}")
            print()
        
        # 3. Анализ связи с заказами
        print("🔗 Анализ связи транзакций с заказами:")
        print("-" * 60)
        
        cursor.execute("""
            SELECT 
                COUNT(*) as transactions_with_orders,
                COUNT(DISTINCT order_id) as unique_orders
            FROM fact_transactions 
            WHERE order_id IS NOT NULL AND order_id != ''
        """)
        
        order_link_result = cursor.fetchone()
        print(f"Транзакций с привязкой к заказам: {order_link_result['transactions_with_orders']}")
        print(f"Уникальных заказов в транзакциях: {order_link_result['unique_orders']}")
        
        # 4. Анализ по источникам
        print("\n🏪 Анализ по источникам (маркетплейсам):")
        print("-" * 60)
        
        cursor.execute("""
            SELECT 
                s.name as source_name,
                ft.transaction_type,
                COUNT(*) as count,
                SUM(ft.amount) as total_amount
            FROM fact_transactions ft
            JOIN sources s ON ft.source_id = s.id
            GROUP BY s.name, ft.transaction_type
            ORDER BY s.name, count DESC
        """)
        
        source_analysis = cursor.fetchall()
        current_source = None
        
        for row in source_analysis:
            if current_source != row['source_name']:
                current_source = row['source_name']
                print(f"\n{current_source}:")
            
            print(f"  {row['transaction_type']}: {row['count']} транзакций, сумма: {row['total_amount']:.2f}")
        
        # 5. Анализ временного распределения
        print("\n📅 Анализ по датам (последние 30 дней):")
        print("-" * 60)
        
        cursor.execute("""
            SELECT 
                transaction_date,
                COUNT(*) as daily_count,
                SUM(amount) as daily_amount
            FROM fact_transactions 
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY transaction_date
            ORDER BY transaction_date DESC
            LIMIT 10
        """)
        
        date_analysis = cursor.fetchall()
        
        for row in date_analysis:
            print(f"{row['transaction_date']}: {row['daily_count']} транзакций, сумма: {row['daily_amount']:.2f}")
        
        # 6. Рекомендации по классификации
        print("\n💡 Рекомендации по классификации для расчета маржинальности:")
        print("-" * 60)
        
        print("На основе анализа данных, предлагаем следующую классификацию:")
        print()
        
        # Анализируем типы и даем рекомендации
        commission_keywords = ['комиссия', 'commission', 'fee', 'эквайринг', 'acquiring']
        logistics_keywords = ['логистика', 'доставка', 'delivery', 'shipping', 'fulfillment']
        return_keywords = ['возврат', 'return', 'refund']
        
        for tx_type in transaction_types:
            tx_name = tx_type['transaction_type'].lower()
            
            if any(keyword in tx_name for keyword in commission_keywords):
                category = "💳 КОМИССИИ"
            elif any(keyword in tx_name for keyword in logistics_keywords):
                category = "🚚 ЛОГИСТИКА"
            elif any(keyword in tx_name for keyword in return_keywords):
                category = "↩️  ВОЗВРАТЫ"
            else:
                category = "❓ ТРЕБУЕТ АНАЛИЗА"
            
            print(f"{category}: {tx_type['transaction_type']}")
        
        print("\n✅ Анализ завершен!")
        
    except Exception as e:
        print(f"❌ Ошибка при анализе транзакций: {e}")
        
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()

def check_metrics_daily_schema():
    """Проверяет текущую схему таблицы metrics_daily."""
    
    connection = None
    cursor = None
    
    try:
        print("\n🔍 Проверка схемы таблицы metrics_daily")
        print("=" * 60)
        
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # Получаем структуру таблицы
        cursor.execute("DESCRIBE metrics_daily")
        columns = cursor.fetchall()
        
        print("Текущие колонки в metrics_daily:")
        for col in columns:
            print(f"  {col['Field']}: {col['Type']} {'(NULL)' if col['Null'] == 'YES' else '(NOT NULL)'}")
        
        # Проверяем, есть ли уже колонка margin_percent
        column_names = [col['Field'] for col in columns]
        
        if 'margin_percent' in column_names:
            print("\n✅ Колонка margin_percent уже существует")
        else:
            print("\n⚠️  Колонка margin_percent отсутствует - нужно добавить")
        
        # Проверяем наличие данных
        cursor.execute("SELECT COUNT(*) as count FROM metrics_daily")
        count_result = cursor.fetchone()
        print(f"\nКоличество записей в metrics_daily: {count_result['count']}")
        
        if count_result['count'] > 0:
            # Показываем пример данных
            cursor.execute("""
                SELECT * FROM metrics_daily 
                ORDER BY metric_date DESC 
                LIMIT 3
            """)
            
            sample_data = cursor.fetchall()
            print("\nПример последних записей:")
            for row in sample_data:
                print(f"  Дата: {row['metric_date']}, Выручка: {row['revenue_sum']}, Прибыль: {row.get('profit_sum', 'NULL')}")
        
    except Exception as e:
        print(f"❌ Ошибка при проверке схемы: {e}")
        
    finally:
        if cursor:
            cursor.close()
        if connection:
            connection.close()

if __name__ == "__main__":
    analyze_transaction_types()
    check_metrics_daily_schema()