#!/usr/bin/env python3
"""
Тестовые скрипты для проверки новых функций работы с отчетами Ozon.
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import request_products_report, get_report_by_code, get_products_from_api

def test_request_products_report():
    """Тест Этапа 1: Заказ отчета по товарам."""
    print("=== Тест Этапа 1: Заказ отчета по товарам ===")
    
    try:
        report_code = request_products_report()
        print(f"✅ Отчет заказан успешно!")
        print(f"Код отчета: {report_code}")
        return report_code
        
    except Exception as e:
        print(f"❌ Ошибка заказа отчета: {e}")
        return None

def test_get_report_by_code(report_code):
    """Тест Этапа 2: Получение готового отчета."""
    print(f"\n=== Тест Этапа 2: Получение отчета {report_code} ===")
    
    if not report_code:
        print("❌ Нет кода отчета для тестирования")
        return None
    
    try:
        csv_content = get_report_by_code(report_code)
        print(f"✅ Отчет получен успешно!")
        print(f"Размер CSV: {len(csv_content)} символов")
        print("\nПервые 1000 символов CSV:")
        print("-" * 50)
        print(csv_content[:1000])
        print("-" * 50)
        return csv_content
        
    except Exception as e:
        print(f"❌ Ошибка получения отчета: {e}")
        return None

def test_full_products_api():
    """Тест Этапа 3: Полный цикл получения товаров через новый API."""
    print("\n=== Тест Этапа 3: Полный цикл получения товаров ===")
    
    try:
        products = get_products_from_api()
        print(f"✅ Товары получены успешно!")
        print(f"Количество товаров: {len(products)}")
        
        if products:
            print("\nПример первого товара:")
            sample_product = products[0]
            for key, value in sample_product.items():
                print(f"  {key}: {value}")
        
        return products
        
    except Exception as e:
        print(f"❌ Ошибка получения товаров: {e}")
        return None

def run_all_new_tests():
    """Запуск всех тестов для новых функций."""
    print("🧪 Тестирование новых функций работы с отчетами Ozon\n")
    
    # Тест 1: Заказ отчета
    report_code = test_request_products_report()
    
    # Тест 2: Получение отчета (только если первый тест прошел)
    csv_content = None
    if report_code:
        csv_content = test_get_report_by_code(report_code)
    
    # Тест 3: Полный цикл (независимо от предыдущих тестов)
    products = test_full_products_api()
    
    if products:
        print("\n🎉 Все тесты новых функций прошли успешно!")
        return True
    else:
        print("\n❌ Некоторые тесты не прошли")
        return False

if __name__ == "__main__":
    run_all_new_tests()
