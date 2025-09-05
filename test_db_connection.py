#!/usr/bin/env python3
"""
Тестовый скрипт для проверки подключения к базе данных (Тест 1.2).
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

def test_db_connection():
    """Тест подключения к базе данных MySQL."""
    print("=== Тест 1.2: Проверка подключения к базе данных ===")
    
    try:
        # Устанавливаем соединение
        connection = connect_to_db()
        
        # Выполняем простой тестовый запрос
        with connection.cursor(dictionary=True) as cursor:
            cursor.execute("SELECT 1 as test_value")
            result = cursor.fetchone()
            print(f"Результат тестового запроса: {result}")
        
        # Закрываем соединение
        connection.close()
        
        print("✅ Подключение к базе данных работает корректно!")
        
    except Exception as e:
        print(f"❌ Ошибка подключения к базе данных: {e}")
        print("\nПроверьте:")
        print("1. Правильность настроек в .env файле")
        print("2. Запущен ли сервер MySQL")
        print("3. Существует ли база данных mi_core")
        return False
    
    return True

if __name__ == "__main__":
    test_db_connection()
