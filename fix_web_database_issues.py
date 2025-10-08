#!/usr/bin/env python3
"""
Исправление проблем с базой данных для веб-интерфейса.
"""

import os
import mysql.connector
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

def fix_web_database_issues():
    """Исправление всех проблем с базой данных для веб-интерфейса."""
    
    # Сначала попробуем подключиться к mi_core_db (старая база)
    databases_to_check = ['mi_core_db', 'mi_core']
    
    for db_name in databases_to_check:
        print(f"\n🔍 Проверяем базу данных: {db_name}")
        
        try:
            connection = mysql.connector.connect(
                host=os.getenv('DB_HOST', 'localhost'),
                user=os.getenv('DB_USER', 'v_admin'),
                password=os.getenv('DB_PASSWORD'),
                database=db_name,
                charset='utf8mb4',
                collation='utf8mb4_unicode_ci'
            )
            
            cursor = connection.cursor()
            
            print(f"✅ Подключение к {db_name} успешно!")
            
            # Проверяем структуру inventory_data
            try:
                cursor.execute("DESCRIBE inventory_data")
                columns = cursor.fetchall()
                
                # Ищем product_id и проверяем его тип
                product_id_type = None
                for col in columns:
                    if col[0] == 'product_id':
                        product_id_type = col[1]
                        break
                
                print(f"📋 Тип product_id: {product_id_type}")
                
                # Если product_id не BIGINT, исправляем
                if product_id_type and 'bigint' not in product_id_type.lower():
                    print("🔧 Изменяем product_id на BIGINT...")
                    cursor.execute("ALTER TABLE inventory_data MODIFY COLUMN product_id BIGINT NOT NULL")
                    print("✅ product_id изменен на BIGINT")
                else:
                    print("✅ product_id уже BIGINT")
                    
            except mysql.connector.Error as e:
                print(f"⚠️ Ошибка проверки inventory_data: {e}")
            
            # Проверяем и исправляем sync_logs
            try:
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
                            print(f"✅ Колонка {column_name} добавлена в sync_logs")
                        except mysql.connector.Error as e:
                            print(f"⚠️ Ошибка добавления колонки {column_name}: {e}")
                    else:
                        print(f"✅ Колонка {column_name} уже существует в sync_logs")
                        
            except mysql.connector.Error as e:
                print(f"⚠️ Ошибка проверки sync_logs: {e}")
            
            # Создаем таблицу ozon_warehouses если её нет
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
            
            cursor.close()
            connection.close()
            
            print(f"✅ База данных {db_name} исправлена!")
            
        except mysql.connector.Error as e:
            print(f"❌ Ошибка подключения к {db_name}: {e}")
            continue

if __name__ == "__main__":
    print("🔧 Исправляем проблемы с базой данных для веб-интерфейса...")
    fix_web_database_issues()
    print("\n✅ Исправление завершено!")