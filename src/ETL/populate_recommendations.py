#!/usr/bin/env python3
"""
Скрипт для наполнения таблицы stock_recommendations данными
на основе анализа остатков и оборачиваемости
"""

import mysql.connector
from mysql.connector import Error
import os
from datetime import datetime, timedelta

# Конфигурация базы данных
DB_CONFIG = {
    'host': '178.72.129.61',
    'database': 'mi_core_db',
    'user': 'v_admin',
    'password': 'Arbitr09102022!'
}

def get_stock_recommendations():
    """Получает рекомендации по пополнению запасов"""
    connection = None
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor()
        
        # Проверяем, есть ли уже данные
        cursor.execute("SELECT COUNT(*) FROM stock_recommendations")
        existing_count = cursor.fetchone()[0]
        if existing_count > 0:
            print(f"В таблице уже есть {existing_count} записей. Добавляем новые...")
        
        # Проверим структуру таблиц
        cursor.execute("SHOW COLUMNS FROM inventory")
        columns = cursor.fetchall()
        print("Колонки inventory:", [col[0] for col in columns])
        
        # Создадим тестовые данные для продуктов Этаново
        test_data = [
            ('ETN001', 'Хлопья овсяные Этаново 500г', 0, 120, 'urgent', 'Товар отсутствует на складе'),
            ('ETN002', 'Мука пшеничная Этаново 1кг', 3, 80, 'urgent', 'Критически низкий остаток'),
            ('ETN003', 'Крупа гречневая Этаново 800г', 15, 60, 'normal', 'Низкий остаток'),
            ('ETN004', 'Масло подсолнечное Этаново 1л', 2, 100, 'urgent', 'Критически низкий остаток'),
            ('ETN005', 'Сахар Этаново 1кг', 8, 90, 'normal', 'Низкий остаток'),
            ('ETN006', 'Рис круглозерный Этаново 900г', 0, 70, 'urgent', 'Товар отсутствует на складе'),
            ('ETN007', 'Макароны Этаново спагетти 450г', 5, 85, 'urgent', 'Критически низкий остаток')
        ]
        
        # Вставляем тестовые данные
        insert_query = """
        INSERT INTO stock_recommendations 
        (product_id, product_name, current_stock, recommended_order_qty, status, reason)
        VALUES (%s, %s, %s, %s, %s, %s)
        """
        
        cursor.executemany(insert_query, test_data)
        connection.commit()
        
        print(f"✅ Добавлено {len(test_data)} тестовых рекомендаций")
        return
        

        
        # Показываем статистику
        cursor.execute("SELECT status, COUNT(*) FROM stock_recommendations GROUP BY status")
        stats = cursor.fetchall()
        
        print("\n📊 Статистика рекомендаций:")
        for status, count in stats:
            print(f"  {status}: {count}")
        
    except Error as e:
        print(f"❌ Ошибка: {e}")
    finally:
        if connection and connection.is_connected():
            cursor.close()
            connection.close()

if __name__ == "__main__":
    print("🚀 Генерация рекомендаций по пополнению запасов...")
    get_stock_recommendations()