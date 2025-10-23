#!/usr/bin/env python3
"""
Подключение к базе данных для системы пополнения склада.
Использует конфигурацию из importers/config.py
"""

import sys
import os
import logging
import mysql.connector
from mysql.connector import Error

# Добавляем путь к конфигурации
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

try:
    from config import DB_CONFIG
except ImportError:
    # Fallback конфигурация если файл не найден
    DB_CONFIG = {
        'host': 'localhost',
        'user': 'replenishment_user',
        'password': 'K9#mP2$vQx!8LbN&wZr4FjD7sHq',
        'database': 'replenishment_db',
        'charset': 'utf8mb4',
        'autocommit': True
    }

logger = logging.getLogger(__name__)

def connect_to_replenishment_db():
    """
    Создает подключение к базе данных системы пополнения.
    
    Returns:
        mysql.connector.connection: Объект подключения к БД
        
    Raises:
        mysql.connector.Error: При ошибке подключения
    """
    try:
        logger.info("Подключение к базе данных системы пополнения...")
        
        connection = mysql.connector.connect(**DB_CONFIG)
        
        if connection.is_connected():
            logger.info(f"✅ Успешное подключение к {DB_CONFIG['database']}")
            return connection
        else:
            raise Error("Не удалось установить подключение")
            
    except Error as e:
        logger.error(f"❌ Ошибка подключения к базе данных: {e}")
        raise

def test_connection():
    """Тестирует подключение к базе данных."""
    try:
        conn = connect_to_replenishment_db()
        cursor = conn.cursor()
        
        # Проверяем подключение
        cursor.execute('SELECT DATABASE(), USER(), VERSION()')
        result = cursor.fetchone()
        
        print(f"✅ Подключение успешно!")
        print(f"   База данных: {result[0]}")
        print(f"   Пользователь: {result[1]}")
        print(f"   Версия MySQL: {result[2]}")
        
        # Проверяем таблицы
        cursor.execute('SHOW TABLES')
        tables = cursor.fetchall()
        print(f"📋 Найдено таблиц: {len(tables)}")
        for table in tables:
            print(f"   - {table[0]}")
        
        cursor.close()
        conn.close()
        return True
        
    except Exception as e:
        print(f"❌ Ошибка подключения: {e}")
        return False

if __name__ == '__main__':
    # Настройка логирования для тестирования
    logging.basicConfig(level=logging.INFO)
    test_connection()