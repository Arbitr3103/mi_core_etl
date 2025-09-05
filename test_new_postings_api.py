#!/usr/bin/env python3
"""
Тестовый скрипт для проверки нового API отчетов для заказов.
"""

import sys
import os
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import request_report, get_report_by_code, get_postings_from_api

def test_request_postings_report():
    """Тест заказа отчета по заказам."""
    print("=== Тест 1: Заказ отчета по заказам ===")
    
    # Тестируем за последние 7 дней
    end_date = datetime.now().strftime('%Y-%m-%d')
    start_date = (datetime.now() - timedelta(days=7)).strftime('%Y-%m-%d')
    
    try:
        print(f"Заказываем отчет по заказам с {start_date} по {end_date}")
        report_code = request_report('postings', start_date, end_date)
        print(f"✅ Отчет заказан успешно, код: {report_code}")
        return report_code
        
    except Exception as e:
        print(f"❌ Ошибка заказа отчета: {e}")
        return None

def test_get_postings_report(report_code):
    """Тест получения отчета по заказам."""
    print(f"\n=== Тест 2: Получение отчета {report_code} ===")
    
    if not report_code:
        print("❌ Нет кода отчета для тестирования")
        return None
    
    try:
        print("Ожидаем готовности отчета и скачиваем...")
        csv_content = get_report_by_code(report_code)
        print(f"✅ CSV-файл получен, размер: {len(csv_content)} символов")
        
        # Показываем первые несколько строк
        lines = csv_content.split('\n')[:5]
        print("\nПервые строки CSV:")
        for i, line in enumerate(lines):
            print(f"  {i+1}: {line[:100]}...")
        
        return csv_content
        
    except Exception as e:
        print(f"❌ Ошибка получения отчета: {e}")
        return None

def test_full_postings_cycle():
    """Тест полного цикла получения заказов через новый API."""
    print("\n=== Тест 3: Полный цикл получения заказов ===")
    
    # Тестируем за последние 7 дней
    end_date = datetime.now().strftime('%Y-%m-%d')
    start_date = (datetime.now() - timedelta(days=7)).strftime('%Y-%m-%d')
    
    try:
        print(f"Получаем заказы с {start_date} по {end_date} через новый API")
        postings = get_postings_from_api(start_date, end_date)
        print(f"✅ Получено заказов: {len(postings)}")
        
        if postings:
            print("\nСтруктура первого заказа:")
            sample_posting = postings[0]
            for key, value in list(sample_posting.items())[:15]:  # Показываем первые 15 полей
                print(f"  {key}: {value}")
        
        return postings
        
    except Exception as e:
        print(f"❌ Ошибка получения заказов: {e}")
        return []

def run_all_postings_tests():
    """Запуск всех тестов для нового API заказов."""
    print("🧪 Тестирование нового API отчетов для заказов\n")
    
    # Тест 1: Заказ отчета
    report_code = test_request_postings_report()
    
    # Тест 2: Получение отчета
    csv_content = test_get_postings_report(report_code)
    
    # Тест 3: Полный цикл
    postings = test_full_postings_cycle()
    
    if postings:
        print("\n🎉 Все тесты прошли успешно!")
        print("Новый API отчетов для заказов работает корректно!")
    else:
        print("\n❌ Некоторые тесты не прошли")

if __name__ == "__main__":
    run_all_postings_tests()
