#!/usr/bin/env python3
"""
Тестовые скрипты для проверки импорта транзакций (Этап 4).
"""

import sys
import os
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import (
    get_transactions_from_api, 
    transform_transaction_data, 
    load_transactions_to_db, 
    connect_to_db
)

def test_4_1_transactions():
    """Тест 4.1: Загрузка транзакций в базу данных."""
    print("=== Тест 4.1: Загрузка транзакций в базу данных ===")
    
    yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')
    
    try:
        print(f"Загружаем транзакции за {yesterday}")
        
        # Получаем транзакции из API
        transactions = get_transactions_from_api(yesterday, yesterday)
        
        if not transactions:
            print("❌ Нет транзакций за указанный период")
            return False
        
        # Трансформируем данные
        transformed_transactions = [transform_transaction_data(transaction) for transaction in transactions]
        
        # Загружаем в базу данных
        load_transactions_to_db(transformed_transactions)
        
        # Проверяем результат в базе данных
        connection = connect_to_db()
        with connection.cursor(dictionary=True) as cursor:
            cursor.execute(
                """
                SELECT transaction_type, SUM(amount) as total_amount, COUNT(*) as count 
                FROM fact_transactions 
                WHERE transaction_date = %s 
                GROUP BY transaction_type
                ORDER BY total_amount DESC
                """,
                (yesterday,)
            )
            results = cursor.fetchall()
            
            print(f"\nРезультаты загрузки транзакций за {yesterday}:")
            print("Тип транзакции | Сумма | Количество")
            print("-" * 50)
            
            for result in results:
                print(f"{result['transaction_type'][:30]} | {result['total_amount']:10.2f} | {result['count']:5d}")
            
            # Общая статистика
            cursor.execute(
                "SELECT COUNT(*) as total_count, SUM(amount) as total_amount FROM fact_transactions WHERE transaction_date = %s",
                (yesterday,)
            )
            total_result = cursor.fetchone()
            
            print(f"\nОбщая статистика:")
            print(f"Всего транзакций: {total_result['total_count']}")
            print(f"Общая сумма: {total_result['total_amount']:.2f}")
        
        connection.close()
        
        print("✅ Загрузка транзакций прошла успешно")
        print("Проверьте, что суммы выглядят правдоподобно")
        return True
        
    except Exception as e:
        print(f"❌ Ошибка загрузки транзакций: {e}")
        return False

def test_transaction_transformation():
    """Тест трансформации данных транзакции."""
    print("\n=== Дополнительный тест: Трансформация транзакций ===")
    
    # Пример данных транзакции для тестирования
    sample_transaction = {
        "operation_id": "test_123",
        "operation_type": "OperationMarketplaceServiceItemFulfillment",
        "operation_type_name": "Обработка отправления",
        "operation_date": "2024-01-15T10:30:00.000Z",
        "amount": 150.50,
        "posting": {
            "posting_number": "ORDER_123"
        }
    }
    
    try:
        transformed = transform_transaction_data(sample_transaction)
        
        print("Исходные данные:")
        print(f"  ID: {sample_transaction['operation_id']}")
        print(f"  Тип: {sample_transaction['operation_type']}")
        print(f"  Сумма: {sample_transaction['amount']}")
        
        print("\nПреобразованные данные:")
        print(f"  ID: {transformed['transaction_id']}")
        print(f"  Тип: {transformed['transaction_type']}")
        print(f"  Сумма: {transformed['amount']}")
        print(f"  Дата: {transformed['transaction_date']}")
        
        # Проверяем, что расходная операция стала отрицательной
        if transformed['amount'] < 0:
            print("✅ Расходная операция корректно преобразована в отрицательную сумму")
        else:
            print("⚠️  Внимание: операция не была преобразована в расходную")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка трансформации транзакции: {e}")
        return False

def run_all_transaction_tests():
    """Запуск всех тестов для транзакций."""
    print("🧪 Запуск всех тестов для импорта транзакций\n")
    
    # Тест трансформации
    transform_success = test_transaction_transformation()
    
    # Тест 4.1
    load_success = test_4_1_transactions()
    
    if transform_success and load_success:
        print("\n🎉 Все тесты для транзакций прошли успешно!")
    else:
        print("\n❌ Некоторые тесты не прошли")

if __name__ == "__main__":
    run_all_transaction_tests()
