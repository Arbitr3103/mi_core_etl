#!/usr/bin/env python3
"""
Исправление структуры базы данных в продакшене.
"""

import os
import mysql.connector
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

def fix_production_database():
    """Исправление всех проблем с базой данных в продакшене."""
    try:
        # Подключаемся к базе mi_core
        connection = mysql.connector.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            user=os.getenv('DB_USER', 'v_admin'),
            password=os.getenv('DB_PASSWORD'),
            database=os.getenv('DB_NAME', 'mi_core'),
            charset='utf8mb4',
            collation='utf8mb4_unicode_ci'
        )
        
        cursor = connection.cursor()
        
        print("🔧 Исправляем структуру базы данных в продакшене...")
        
        # 1. Исправляем тип product_id на BIGINT
        print("1. Изменяем product_id на BIGINT...")
        try:
            cursor.execute("ALTER TABLE inventory_data MODIFY COLUMN product_id BIGINT NOT NULL")
            print("✅ product_id изменен на BIGINT")
        except mysql.connector.Error as e:
            print(f"⚠️ Ошибка изменения product_id: {e}")
        
        # 2. Добавляем недостающие колонки в sync_logs
        print("2. Добавляем недостающие колонки в sync_logs...")
        
        # Получаем текущие колонки
        cursor.execute("DESCRIBE sync_logs")
        existing_columns = [row[0] for row in cursor.fetchall()]
        
        # Список необходимых колонок
        required_columns = [
            ('source', 'VARCHAR(50) DEFAULT "Ozon"'),
            ('records_inserted', 'INT DEFAULT 0'),
            ('records_failed', 'INT DEFAULT 0'),
            ('duration_seconds', 'INT DEFAULT 0'),
            ('api_requests_count', 'INT DEFAULT 0'),
            ('warning_message', 'TEXT')
        ]
        
        for column_name, column_definition in required_columns:
            if column_name not in existing_columns:
                try:
                    cursor.execute(f"ALTER TABLE sync_logs ADD COLUMN {column_name} {column_definition}")
                    print(f"✅ Колонка {column_name} добавлена")
                except mysql.connector.Error as e:
                    print(f"⚠️ Ошибка добавления колонки {column_name}: {e}")
            else:
                print(f"✅ Колонка {column_name} уже существует")
        
        # 3. Создаем таблицу ozon_warehouses если её нет
        print("3. Проверяем таблицу ozon_warehouses...")
        try:
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS ozon_warehouses (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    warehouse_id BIGINT NOT NULL UNIQUE,
                    name VARCHAR(255) NOT NULL,
                    is_rfbs BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """)
            print("✅ Таблица ozon_warehouses создана или уже существует")
        except mysql.connector.Error as e:
            print(f"⚠️ Ошибка создания таблицы ozon_warehouses: {e}")
        
        # 4. Проверяем финальную структуру
        print("4. Проверяем финальную структуру...")
        
        cursor.execute("DESCRIBE inventory_data")
        inventory_columns = cursor.fetchall()
        print("\n📋 Структура inventory_data:")
        for col in inventory_columns:
            print(f"  - {col[0]}: {col[1]}")
        
        cursor.execute("DESCRIBE sync_logs")
        sync_columns = cursor.fetchall()
        print("\n📋 Структура sync_logs:")
        for col in sync_columns:
            print(f"  - {col[0]}: {col[1]}")
        
        cursor.close()
        connection.close()
        
        print("\n✅ Исправление продакшен базы данных завершено!")
        return True
        
    except mysql.connector.Error as e:
        print(f"❌ Ошибка: {e}")
        return False

if __name__ == "__main__":
    fix_production_database()