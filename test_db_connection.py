#!/usr/bin/env python3
"""
Тестовый скрипт для проверки подключения к базе данных.
"""

import os
import mysql.connector
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

def test_connection():
    """Тестирование подключения к БД."""
    print("🔍 Тестируем подключение к базе данных...")
    print(f"DB_HOST: {os.getenv('DB_HOST')}")
    print(f"DB_USER: {os.getenv('DB_USER')}")
    print(f"DB_NAME: {os.getenv('DB_NAME')}")
    print(f"DB_PASSWORD: {'*' * len(os.getenv('DB_PASSWORD', ''))}")
    
    try:
        # Сначала попробуем подключиться без указания базы данных
        connection = mysql.connector.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            user=os.getenv('DB_USER', 'v_admin'),
            password=os.getenv('DB_PASSWORD'),
            charset='utf8mb4',
            collation='utf8mb4_unicode_ci'
        )
        
        cursor = connection.cursor()
        
        # Проверяем, какие базы данных доступны пользователю
        cursor.execute("SHOW DATABASES")
        databases = cursor.fetchall()
        print(f"✅ Подключение успешно! Доступные базы данных:")
        for db in databases:
            print(f"  - {db[0]}")
        
        # Проверяем права пользователя (пропускаем если нет доступа)
        try:
            cursor.execute("SHOW GRANTS FOR CURRENT_USER()")
            grants = cursor.fetchall()
            print(f"\n🔐 Права текущего пользователя:")
            for grant in grants:
                print(f"  - {grant[0]}")
        except mysql.connector.Error as e:
            print(f"\n⚠️ Не удалось получить информацию о правах: {e}")
        
        # Пробуем подключиться к конкретной базе
        try:
            cursor.execute(f"USE {os.getenv('DB_NAME', 'mi_core')}")
            print(f"✅ Успешно подключились к базе {os.getenv('DB_NAME', 'mi_core')}")
            
            # Проверяем таблицы
            cursor.execute("SHOW TABLES")
            tables = cursor.fetchall()
            print(f"\n📋 Таблицы в базе данных:")
            for table in tables:
                print(f"  - {table[0]}")
        except mysql.connector.Error as e:
            print(f"❌ Ошибка доступа к базе {os.getenv('DB_NAME', 'mi_core')}: {e}")
            print("💡 Возможно, нужно создать базу данных или предоставить права")
        
        cursor.close()
        connection.close()
        
        return True
        
    except mysql.connector.Error as e:
        print(f"❌ Ошибка подключения: {e}")
        return False

if __name__ == "__main__":
    test_connection()