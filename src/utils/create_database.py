#!/usr/bin/env python3
"""
Скрипт для создания базы данных mi_core.
"""

import os
import mysql.connector
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

def create_database():
    """Создание базы данных mi_core."""
    try:
        # Подключаемся без указания базы данных
        connection = mysql.connector.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            user=os.getenv('DB_USER', 'v_admin'),
            password=os.getenv('DB_PASSWORD'),
            charset='utf8mb4',
            collation='utf8mb4_unicode_ci'
        )
        
        cursor = connection.cursor()
        
        # Создаем базу данных
        db_name = os.getenv('DB_NAME', 'mi_core')
        cursor.execute(f"CREATE DATABASE IF NOT EXISTS {db_name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        print(f"✅ База данных {db_name} создана или уже существует")
        
        # Переключаемся на созданную базу
        cursor.execute(f"USE {db_name}")
        print(f"✅ Переключились на базу {db_name}")
        
        # Создаем необходимые таблицы
        create_tables(cursor)
        
        cursor.close()
        connection.close()
        
        return True
        
    except mysql.connector.Error as e:
        print(f"❌ Ошибка: {e}")
        return False

def create_tables(cursor):
    """Создание необходимых таблиц."""
    
    # Таблица inventory_data
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS inventory_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            sku VARCHAR(255),
            source VARCHAR(50) DEFAULT 'Ozon',
            warehouse_name VARCHAR(255),
            stock_type VARCHAR(50),
            current_stock INT DEFAULT 0,
            reserved_stock INT DEFAULT 0,
            available_stock INT DEFAULT 0,
            quantity_present INT DEFAULT 0,
            quantity_reserved INT DEFAULT 0,
            snapshot_date DATE,
            last_sync_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_inventory (product_id, warehouse_name, stock_type, source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """)
    print("✅ Таблица inventory_data создана")
    
    # Таблица sync_logs
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS sync_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sync_type VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            records_processed INT DEFAULT 0,
            records_updated INT DEFAULT 0,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """)
    print("✅ Таблица sync_logs создана")
    
    # Таблица ozon_warehouses
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
    print("✅ Таблица ozon_warehouses создана")

if __name__ == "__main__":
    print("🚀 Создание базы данных и таблиц...")
    if create_database():
        print("✅ Все готово!")
    else:
        print("❌ Ошибка при создании базы данных")