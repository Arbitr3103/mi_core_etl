#!/usr/bin/env python3
"""
Скрипт для запуска всех тестов проекта mi_core_etl.
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

def run_all_tests():
    """Запуск всех тестов по порядку."""
    print("🚀 Запуск всех тестов проекта mi_core_etl\n")
    
    tests_passed = 0
    total_tests = 0
    
    # Тест 1.1: Конфигурация
    print("=" * 60)
    try:
        from test_config import test_config
        if test_config():
            tests_passed += 1
        total_tests += 1
    except Exception as e:
        print(f"❌ Ошибка в тесте конфигурации: {e}")
        total_tests += 1
    
    # Тест 1.2: Подключение к БД
    print("\n" + "=" * 60)
    try:
        from test_db_connection import test_db_connection
        if test_db_connection():
            tests_passed += 1
        total_tests += 1
    except Exception as e:
        print(f"❌ Ошибка в тесте подключения к БД: {e}")
        total_tests += 1
    
    # Тесты товаров (Этап 2)
    print("\n" + "=" * 60)
    try:
        from test_products import run_all_product_tests
        run_all_product_tests()
        tests_passed += 1
        total_tests += 1
    except Exception as e:
        print(f"❌ Ошибка в тестах товаров: {e}")
        total_tests += 1
    
    # Тесты заказов (Этап 3)
    print("\n" + "=" * 60)
    try:
        from test_orders import run_all_order_tests
        run_all_order_tests()
        tests_passed += 1
        total_tests += 1
    except Exception as e:
        print(f"❌ Ошибка в тестах заказов: {e}")
        total_tests += 1
    
    # Тесты транзакций (Этап 4)
    print("\n" + "=" * 60)
    try:
        from test_transactions import run_all_transaction_tests
        run_all_transaction_tests()
        tests_passed += 1
        total_tests += 1
    except Exception as e:
        print(f"❌ Ошибка в тестах транзакций: {e}")
        total_tests += 1
    
    # Итоговый результат
    print("\n" + "=" * 60)
    print(f"📊 Результаты тестирования: {tests_passed}/{total_tests} тестов прошли успешно")
    
    if tests_passed == total_tests:
        print("🎉 Все тесты прошли успешно! Система готова к работе.")
    else:
        print("⚠️  Некоторые тесты не прошли. Проверьте конфигурацию и исправьте ошибки.")
    
    return tests_passed == total_tests

if __name__ == "__main__":
    success = run_all_tests()
    sys.exit(0 if success else 1)
