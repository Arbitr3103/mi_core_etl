#!/usr/bin/env python3
"""
Скрипт для исправления проблем с базой данных.
"""

import os
import mysql.connector
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

def fix_database_issues():
    """Исправление проблем с базой данных."""
    try:
        connection = mysql.connector.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            user=os.getenv('DB_USER', 'v_admin'),
            password=os.getenv('DB_PASSWORD'),
            database=os.getenv('DB_NAME', 'mi_core'),
            charset='utf8mb4',
            collation='utf8mb4_unicode_ci'
        )
        
        cursor = connection.cursor()
        
        print("🔧 Исправляем проблемы с базой данных...")
        
        # 1. Изменяем тип product_id на BIGINT для больших значений
        print("1. Изменяем product_id на BIGINT...")
        cursor.execute("""
            ALTER TABLE inventory_data 
            MODIFY COLUMN product_id BIGINT NOT NULL
        """)
        print("✅ product_id изменен на BIGINT")
        
        # 2. Добавляем колонку source в sync_logs если её нет
        print("2. Проверяем колонку source в sync_logs...")
        cursor.execute("DESCRIBE sync_logs")
        columns = [row[0] for row in cursor.fetchall()]
        
        if 'source' not in columns:
            cursor.execute("""
                ALTER TABLE sync_logs 
                ADD COLUMN source VARCHAR(50) DEFAULT 'Ozon'
            """)
            print("✅ Колонка source добавлена в sync_logs")
        else:
            print("✅ Колонка source уже существует в sync_logs")
        
        # 3. Проверяем и исправляем другие возможные проблемы
        print("3. Проверяем структуру таблиц...")
        
        # Проверяем inventory_data
        cursor.execute("DESCRIBE inventory_data")
        inventory_columns = cursor.fetchall()
        print("📋 Структура inventory_data:")
        for col in inventory_columns:
            print(f"  - {col[0]}: {col[1]}")
        
        # Проверяем sync_logs
        cursor.execute("DESCRIBE sync_logs")
        sync_columns = cursor.fetchall()
        print("\n📋 Структура sync_logs:")
        for col in sync_columns:
            print(f"  - {col[0]}: {col[1]}")
        
        cursor.close()
        connection.close()
        
        print("\n✅ Все проблемы исправлены!")
        return True
        
    except mysql.connector.Error as e:
        print(f"❌ Ошибка: {e}")
        return False

if __name__ == "__main__":
    fix_database_issues()