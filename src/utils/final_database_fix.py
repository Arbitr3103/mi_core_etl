#!/usr/bin/env python3
"""
Финальное исправление структуры базы данных.
"""

import os
import mysql.connector
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

def final_database_fix():
    """Финальное исправление всех проблем с базой данных."""
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
        
        print("🔧 Финальное исправление структуры базы данных...")
        
        # Получаем текущие колонки sync_logs
        cursor.execute("DESCRIBE sync_logs")
        existing_columns = [row[0] for row in cursor.fetchall()]
        print(f"📋 Существующие колонки в sync_logs: {existing_columns}")
        
        # Список всех необходимых колонок
        required_columns = [
            ('source', 'VARCHAR(50) DEFAULT "Ozon"'),
            ('records_inserted', 'INT DEFAULT 0'),
            ('records_failed', 'INT DEFAULT 0'),
            ('duration_seconds', 'INT DEFAULT 0'),
            ('api_requests_count', 'INT DEFAULT 0'),
            ('warning_message', 'TEXT')
        ]
        
        # Добавляем недостающие колонки
        for column_name, column_definition in required_columns:
            if column_name not in existing_columns:
                try:
                    cursor.execute(f"ALTER TABLE sync_logs ADD COLUMN {column_name} {column_definition}")
                    print(f"✅ Колонка {column_name} добавлена")
                except mysql.connector.Error as e:
                    print(f"⚠️ Ошибка добавления колонки {column_name}: {e}")
            else:
                print(f"✅ Колонка {column_name} уже существует")
        
        # Проверяем финальную структуру
        cursor.execute("DESCRIBE sync_logs")
        final_columns = cursor.fetchall()
        print("\n📋 Финальная структура sync_logs:")
        for col in final_columns:
            print(f"  - {col[0]}: {col[1]}")
        
        cursor.close()
        connection.close()
        
        print("\n✅ Финальное исправление завершено!")
        return True
        
    except mysql.connector.Error as e:
        print(f"❌ Ошибка: {e}")
        return False

if __name__ == "__main__":
    final_database_fix()