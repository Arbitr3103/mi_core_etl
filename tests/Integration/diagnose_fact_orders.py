#!/usr/bin/env python3
"""
Диагностический скрипт для проверки схемы fact_orders на сервере.
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

def check_fact_orders():
    """Проверяет схему таблицы fact_orders."""
    print('=== ДИАГНОСТИКА fact_orders ===')
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # 1. Проверяем существование таблицы
        cursor.execute("SHOW TABLES LIKE 'fact_orders'")
        table_exists = cursor.fetchone()
        
        if not table_exists:
            print("❌ КРИТИЧЕСКАЯ ОШИБКА: Таблица fact_orders не существует!")
            return False
        
        # 2. Показываем структуру таблицы
        cursor.execute("DESCRIBE fact_orders")
        columns = cursor.fetchall()
        print("\nСтруктура таблицы fact_orders:")
        for col in columns:
            print(f"  {col['Field']} - {col['Type']} - Null:{col['Null']} - Key:{col['Key']} - Default:{col['Default']}")
        
        # 3. Проверяем наличие новых колонок
        column_names = [col['Field'] for col in columns]
        required_columns = ['client_id', 'source_id']
        
        print("\nПроверка обязательных колонок:")
        for col in required_columns:
            if col in column_names:
                print(f"  ✅ {col} - присутствует")
            else:
                print(f"  ❌ {col} - ОТСУТСТВУЕТ")
        
        # 4. Показываем foreign key ограничения
        cursor.execute("SHOW CREATE TABLE fact_orders")
        create_table = cursor.fetchone()
        print("\nForeign Key ограничения:")
        create_sql = list(create_table.values())[1]
        
        fk_lines = [line.strip() for line in create_sql.split('\n') if 'FOREIGN KEY' in line or 'REFERENCES' in line]
        if fk_lines:
            for fk in fk_lines:
                print(f"  {fk}")
        else:
            print("  Нет foreign key ограничений")
        
        # 5. Проверяем количество записей
        cursor.execute("SELECT COUNT(*) as total FROM fact_orders")
        total = cursor.fetchone()["total"]
        print(f"\nВсего записей в fact_orders: {total}")
        
        cursor.close()
        connection.close()
        
        print("\n✅ Диагностика fact_orders завершена")
        return True
        
    except Exception as e:
        print(f"❌ Ошибка диагностики: {e}")
        return False

if __name__ == "__main__":
    check_fact_orders()
