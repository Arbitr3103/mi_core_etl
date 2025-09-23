#!/usr/bin/env python3
import mysql.connector

# Конфигурация подключения
DB_CONFIG = {
    'host': 'localhost',
    'user': 'replenishment_user',
    'password': 'K9#mP2$vQx!8LbN&wZr4FjD7sHq',
    'database': 'replenishment_db',
    'charset': 'utf8mb4',
    'autocommit': True
}

def test_connection():
    try:
        print('🔌 Тестирование подключения к базе данных...')
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        # Проверяем подключение
        cursor.execute('SELECT DATABASE(), USER()')
        result = cursor.fetchone()
        print(f'✅ Подключение успешно!')
        print(f'   База данных: {result[0]}')
        print(f'   Пользователь: {result[1]}')
        
        # Проверяем таблицы
        cursor.execute('SHOW TABLES')
        tables = cursor.fetchall()
        print(f'📋 Найдено таблиц: {len(tables)}')
        for table in tables:
            print(f'   - {table[0]}')
        
        cursor.close()
        conn.close()
        return True
        
    except Exception as e:
        print(f'❌ Ошибка подключения: {e}')
        return False

if __name__ == '__main__':
    test_connection()