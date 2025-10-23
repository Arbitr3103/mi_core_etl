#!/usr/bin/env python3
"""
Скрипт для создания таблицы stock_recommendations и наполнения ее данными
"""

import mysql.connector
from mysql.connector import Error
import os
from datetime import datetime

# Конфигурация базы данных
DB_CONFIG = {
    'host': '178.72.129.61',
    'database': 'mi_core_db',
    'user': 'v_admin',  # Используем v_admin для создания таблицы
    'password': os.getenv('MYSQL_ADMIN_PASSWORD', 'your_admin_password_here')
}

def create_table():
    """Создает таблицу stock_recommendations"""
    connection = None
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor()
        
        # SQL для создания таблицы
        create_table_query = """
        CREATE TABLE IF NOT EXISTS stock_recommendations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id VARCHAR(50) NOT NULL,
            product_name VARCHAR(255),
            current_stock INT DEFAULT 0,
            recommended_order_qty INT NOT NULL,
            status ENUM('urgent', 'normal', 'low_priority') DEFAULT 'normal',
            reason TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_product_id (product_id)
        )
        """
        
        cursor.execute(create_table_query)
        print("✅ Таблица stock_recommendations создана успешно")
        
        # Предоставляем права пользователю app_user
        grant_query = """
        GRANT SELECT, INSERT, UPDATE, DELETE ON mi_core_db.stock_recommendations TO 'app_user'@'%'
        """
        cursor.execute(grant_query)
        cursor.execute("FLUSH PRIVILEGES")
        print("✅ Права для app_user предоставлены")
        
        connection.commit()
        
    except Error as e:
        print(f"❌ Ошибка при создании таблицы: {e}")
    finally:
        if connection and connection.is_connected():
            cursor.close()
            connection.close()

if __name__ == "__main__":
    print("🚀 Создание таблицы stock_recommendations...")
    create_table()