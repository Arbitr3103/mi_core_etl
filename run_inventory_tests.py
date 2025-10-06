#!/usr/bin/env python3
"""
Скрипт для запуска всех unit тестов системы синхронизации остатков.

Запускает тесты для:
- InventorySyncService (методы API, валидация, запись в БД)
- InventoryDataValidator (валидация данных)
- Integration тесты (полный цикл синхронизации)

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
import sys
import os
from io import StringIO

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

# Импортируем тестовые модули
from test_inventory_sync_service import *
from test_inventory_data_validator import *
from test_inventory_integration import *


class TestResult:
    """Класс для сбора результатов тестирования."""
    
    def __init__(self):
        self.total_tests = 0
        self.passed_tests = 0
        self.failed_tests = 0
        self.error_tests = 0
        self.skipped_tests = 0
        self.failures = []
        self.errors = []
    
    def add_result(self, result):
        """Добавление результата тестирования."""
        self.total_tests += result.testsRun
        self.failed_tests += len(result.failures)
        self.error_tests += len(result.errors)
        self.skipped_tests += len(result.skipped)
        self.passed_tests = self.total_tests - self.failed_tests - self.error_tests - self.skipped_tests
        
        self.failures.extend(result.failures)
        self.errors.extend(result.errors)
    
    def print_summary(self):
        """Вывод сводки результатов."""
        print("\n" + "="*80)
        print("СВОДКА РЕЗУЛЬТАТОВ ТЕСТИРОВАНИЯ")
        print("="*80)
        print(f"Всего тестов:     {self.total_tests}")
        print(f"Пройдено:         {self.passed_tests}")
        print(f"Провалено:        {self.failed_tests}")
        print(f"Ошибок:           {self.error_tests}")
        print(f"Пропущено:        {self.skipped_tests}")
        print("-"*80)
        
        if self.passed_tests == self.total_tests:
            print("✅ ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!")
        else:
            print("❌ ЕСТЬ ПРОВАЛЕННЫЕ ТЕСТЫ")
            
            if self.failures:
                print(f"\nПроваленные тесты ({len(self.failures)}):")
                for i, (test, traceback) in enumerate(self.failures[:5], 1):
                    print(f"  {i}. {test}")
                if len(self.failures) > 5:
                    print(f"  ... и еще {len(self.failures) - 5} тестов")
            
            if self.errors:
                print(f"\nТесты с ошибками ({len(self.errors)}):")
                for i, (test, traceback) in enumerate(self.errors[:5], 1):
                    print(f"  {i}. {test}")
                if len(self.errors) > 5:
                    print(f"  ... и еще {len(self.errors) - 5} тестов")
        
        print("="*80)
        
        # Возвращаем код выхода
        return 0 if self.passed_tests == self.total_tests else 1


def run_test_suite(test_class, suite_name):
    """
    Запуск набора тестов для конкретного класса.
    
    Args:
        test_class: Класс с тестами
        suite_name: Название набора тестов
        
    Returns:
        unittest.TestResult: Результат выполнения тестов
    """
    print(f"\n{'='*60}")
    print(f"ЗАПУСК ТЕСТОВ: {suite_name}")
    print(f"{'='*60}")
    
    # Создаем test suite
    loader = unittest.TestLoader()
    suite = loader.loadTestsFromTestCase(test_class)
    
    # Запускаем тесты
    stream = StringIO()
    runner = unittest.TextTestRunner(
        stream=stream,
        verbosity=2,
        buffer=True
    )
    
    result = runner.run(suite)
    
    # Выводим результаты
    output = stream.getvalue()
    print(output)
    
    # Краткая сводка по этому набору
    total = result.testsRun
    failed = len(result.failures)
    errors = len(result.errors)
    skipped = len(result.skipped)
    passed = total - failed - errors - skipped
    
    print(f"\nРезультат {suite_name}:")
    print(f"  Всего: {total}, Пройдено: {passed}, Провалено: {failed}, Ошибок: {errors}, Пропущено: {skipped}")
    
    if failed > 0 or errors > 0:
        print("  ❌ ЕСТЬ ПРОБЛЕМЫ")
    else:
        print("  ✅ ВСЕ ТЕСТЫ ПРОЙДЕНЫ")
    
    return result


def main():
    """Главная функция запуска всех тестов."""
    print("🧪 ЗАПУСК UNIT ТЕСТОВ СИСТЕМЫ СИНХРОНИЗАЦИИ ОСТАТКОВ")
    print("📅 Дата:", "06 января 2025")
    
    # Настройка логирования для тестов
    import logging
    logging.basicConfig(level=logging.CRITICAL)  # Отключаем логи во время тестов
    
    # Собираем результаты
    overall_result = TestResult()
    
    # Список тестовых наборов
    test_suites = [
        # Тесты методов работы с БД
        (TestDatabaseMethods, "Методы работы с базой данных"),
        
        # Тесты получения данных с API
        (TestAPIDataRetrieval, "Получение данных с API маркетплейсов"),
        
        # Тесты валидации данных
        (TestDataValidation, "Валидация данных остатков"),
        
        # Тесты проверки аномалий
        (TestDataAnomalies, "Проверка аномалий в данных"),
        
        # Тесты моделей данных
        (TestInventoryRecord, "Модель записи остатков"),
        (TestSyncResult, "Модель результата синхронизации"),
        
        # Тесты валидатора данных
        (TestValidationMethods, "Основные методы валидации"),
        (TestFieldValidation, "Валидация отдельных полей"),
        (TestQuantityValidation, "Валидация количественных показателей"),
        (TestStockLogicValidation, "Валидация логики остатков"),
        (TestStockTypeValidation, "Валидация типов складов"),
        (TestWarehouseNameValidation, "Валидация названий складов"),
        (TestSnapshotDateValidation, "Валидация даты снимка"),
        (TestProductExistenceValidation, "Проверка существования товаров"),
        
        # Тесты моделей валидации
        (TestValidationResult, "Модель результата валидации"),
        (TestValidationIssue, "Модель проблемы валидации"),
        
        # Integration тесты
        (TestOzonIntegrationFlow, "Интеграция с Ozon API"),
        (TestWildberriesIntegrationFlow, "Интеграция с Wildberries API"),
        (TestFullSyncIntegration, "Полная синхронизация"),
        (TestDataFreshnessIntegration, "Проверка свежести данных"),
        (TestStatisticsIntegration, "Получение статистики остатков"),
    ]
    
    # Запускаем каждый набор тестов
    for test_class, suite_name in test_suites:
        try:
            result = run_test_suite(test_class, suite_name)
            overall_result.add_result(result)
        except Exception as e:
            print(f"❌ КРИТИЧЕСКАЯ ОШИБКА при запуске {suite_name}: {e}")
            overall_result.error_tests += 1
    
    # Выводим общую сводку
    exit_code = overall_result.print_summary()
    
    # Дополнительная информация
    if exit_code == 0:
        print("\n🎉 Все тесты пройдены! Система готова к развертыванию.")
        print("\n📋 Что протестировано:")
        print("  ✅ Методы получения данных с API Ozon и Wildberries")
        print("  ✅ Валидация данных об остатках")
        print("  ✅ Запись данных в базу данных")
        print("  ✅ Обработка ошибок и исключений")
        print("  ✅ Проверка аномалий в данных")
        print("  ✅ Полный цикл синхронизации")
        print("  ✅ Мониторинг и статистика")
    else:
        print("\n⚠️  Обнаружены проблемы в тестах.")
        print("   Рекомендуется исправить ошибки перед развертыванием.")
        print("\n🔧 Для отладки:")
        print("   - Запустите конкретный тест: python -m unittest test_inventory_sync_service.TestClass.test_method")
        print("   - Включите подробные логи: logging.basicConfig(level=logging.DEBUG)")
        print("   - Проверьте подключение к тестовой БД")
    
    return exit_code


if __name__ == '__main__':
    exit_code = main()
    sys.exit(exit_code)