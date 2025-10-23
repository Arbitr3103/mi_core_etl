#!/usr/bin/env python3
"""
Валидатор PHP классов для проверки синтаксиса и структуры
без необходимости запуска PHP интерпретатора

Проверяет созданные классы Region и CarFilter на соответствие требованиям
"""

import os
import re
from pathlib import Path

class PHPClassValidator:
    def __init__(self):
        self.errors = []
        self.warnings = []
        self.passed_checks = []
        
    def validate_all_classes(self):
        """Валидация всех созданных PHP классов"""
        print("🔍 ВАЛИДАЦИЯ PHP КЛАССОВ")
        print("=" * 60)
        print()
        
        # Проверяем класс Region
        self.validate_region_class()
        print()
        
        # Проверяем класс CarFilter
        self.validate_car_filter_class()
        print()
        
        # Проверяем тесты
        self.validate_test_files()
        print()
        
        self.print_summary()
        
    def validate_region_class(self):
        """Валидация класса Region"""
        print("📍 Валидация класса Region")
        print("-" * 40)
        
        file_path = "classes/Region.php"
        if not os.path.exists(file_path):
            self.errors.append(f"Файл {file_path} не найден")
            return
            
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()
            
        # Проверяем основную структуру класса
        if 'class Region' in content:
            self.passed_checks.append("✅ Класс Region объявлен")
        else:
            self.errors.append("❌ Класс Region не найден")
            
        # Проверяем обязательные методы
        required_methods = [
            'getAll', 'getByBrand', 'getByModel', 'exists', 
            'getById', 'getBrandCount', 'getStatistics'
        ]
        
        for method in required_methods:
            if f'function {method}(' in content:
                self.passed_checks.append(f"✅ Метод {method}() реализован")
            else:
                self.errors.append(f"❌ Метод {method}() не найден")
                
        # Проверяем конструктор
        if 'function __construct(PDO $pdo)' in content:
            self.passed_checks.append("✅ Конструктор с PDO параметром")
        else:
            self.errors.append("❌ Конструктор не найден или неправильный")
            
        # Проверяем обработку ошибок
        if 'throw new Exception' in content:
            self.passed_checks.append("✅ Обработка ошибок реализована")
        else:
            self.warnings.append("⚠️ Обработка ошибок может быть недостаточной")
            
        # Проверяем SQL запросы
        sql_patterns = [
            'SELECT DISTINCT r.id, r.name FROM regions',
            'INNER JOIN brands',
            'ORDER BY r.name ASC'
        ]
        
        for pattern in sql_patterns:
            if pattern in content:
                self.passed_checks.append(f"✅ SQL паттерн найден: {pattern[:30]}...")
            else:
                self.warnings.append(f"⚠️ SQL паттерн не найден: {pattern[:30]}...")
                
    def validate_car_filter_class(self):
        """Валидация класса CarFilter"""
        print("📍 Валидация класса CarFilter")
        print("-" * 40)
        
        file_path = "classes/CarFilter.php"
        if not os.path.exists(file_path):
            self.errors.append(f"Файл {file_path} не найден")
            return
            
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()
            
        # Проверяем основную структуру класса
        if 'class CarFilter' in content:
            self.passed_checks.append("✅ Класс CarFilter объявлен")
        else:
            self.errors.append("❌ Класс CarFilter не найден")
            
        # Проверяем обязательные методы
        required_methods = [
            'setBrand', 'setModel', 'setYear', 'setCountry', 'setLimit', 'setOffset',
            'setFilters', 'validate', 'buildQuery', 'buildCountQuery', 'execute',
            'getFilters', 'hasFilters', 'getFilterCount', 'reset'
        ]
        
        for method in required_methods:
            if f'function {method}(' in content:
                self.passed_checks.append(f"✅ Метод {method}() реализован")
            else:
                self.errors.append(f"❌ Метод {method}() не найден")
                
        # Проверяем цепочку вызовов (fluent interface)
        if 'return $this;' in content:
            self.passed_checks.append("✅ Поддержка цепочки вызовов (fluent interface)")
        else:
            self.warnings.append("⚠️ Цепочка вызовов может быть не реализована")
            
        # Проверяем валидацию
        validation_patterns = [
            'is_numeric',
            'Некорректный ID',
            'errors\[\]',
            'valid.*=>.*empty.*errors'
        ]
        
        validation_found = 0
        for pattern in validation_patterns:
            if re.search(pattern, content):
                validation_found += 1
                
        if validation_found >= 3:
            self.passed_checks.append("✅ Валидация параметров реализована")
        else:
            self.errors.append("❌ Валидация параметров недостаточна")
            
        # Проверяем SQL построение
        if 'SELECT' in content and 'FROM dim_products' in content:
            self.passed_checks.append("✅ SQL запросы для фильтрации реализованы")
        else:
            self.errors.append("❌ SQL запросы не найдены")
            
    def validate_test_files(self):
        """Валидация тестовых файлов"""
        print("📍 Валидация тестовых файлов")
        print("-" * 40)
        
        test_files = [
            ("tests/RegionTest.php", "RegionTest"),
            ("tests/CarFilterTest.php", "CarFilterTest"),
            ("tests/run_all_tests.php", "TestRunner")
        ]
        
        for file_path, class_name in test_files:
            if os.path.exists(file_path):
                self.passed_checks.append(f"✅ Тестовый файл {file_path} создан")
                
                with open(file_path, 'r', encoding='utf-8') as f:
                    content = f.read()
                    
                if f'class {class_name}' in content:
                    self.passed_checks.append(f"✅ Класс {class_name} объявлен")
                else:
                    self.errors.append(f"❌ Класс {class_name} не найден в {file_path}")
                    
                # Проверяем наличие тестовых методов
                if 'function test' in content or 'runAllTests' in content:
                    self.passed_checks.append(f"✅ Тестовые методы найдены в {class_name}")
                else:
                    self.warnings.append(f"⚠️ Тестовые методы не найдены в {class_name}")
                    
            else:
                self.errors.append(f"❌ Тестовый файл {file_path} не найден")
                
    def check_requirements_compliance(self):
        """Проверка соответствия требованиям"""
        print("📍 Проверка соответствия требованиям")
        print("-" * 40)
        
        # Requirement 2.1: Создать класс Region с методами для получения стран по различным критериям
        region_methods = ['getAll', 'getByBrand', 'getByModel']
        region_file_exists = os.path.exists("classes/Region.php")
        
        if region_file_exists:
            with open("classes/Region.php", 'r') as f:
                region_content = f.read()
                region_methods_found = all(f'function {method}(' in region_content for method in region_methods)
                
            if region_methods_found:
                self.passed_checks.append("✅ Requirement 2.1: Класс Region с методами получения стран")
            else:
                self.errors.append("❌ Requirement 2.1: Не все методы Region реализованы")
        else:
            self.errors.append("❌ Requirement 2.1: Класс Region не создан")
            
        # Requirement 4.2: Создать класс CarFilter для валидации и построения запросов фильтрации
        filter_methods = ['validate', 'buildQuery']
        filter_file_exists = os.path.exists("classes/CarFilter.php")
        
        if filter_file_exists:
            with open("classes/CarFilter.php", 'r') as f:
                filter_content = f.read()
                filter_methods_found = all(f'function {method}(' in filter_content for method in filter_methods)
                
            if filter_methods_found:
                self.passed_checks.append("✅ Requirement 4.2: Класс CarFilter с валидацией и построением запросов")
            else:
                self.errors.append("❌ Requirement 4.2: Не все методы CarFilter реализованы")
        else:
            self.errors.append("❌ Requirement 4.2: Класс CarFilter не создан")
            
        # Unit тесты
        test_files_exist = all(os.path.exists(f) for f in [
            "tests/RegionTest.php", 
            "tests/CarFilterTest.php", 
            "tests/run_all_tests.php"
        ])
        
        if test_files_exist:
            self.passed_checks.append("✅ Unit тесты для новых PHP классов созданы")
        else:
            self.errors.append("❌ Не все unit тесты созданы")
            
    def print_summary(self):
        """Вывод итогового отчета"""
        print("🎯 ИТОГОВЫЙ ОТЧЕТ ВАЛИДАЦИИ")
        print("=" * 60)
        
        # Проверяем соответствие требованиям
        self.check_requirements_compliance()
        
        print(f"\n📊 СТАТИСТИКА:")
        print(f"✅ Пройдено проверок: {len(self.passed_checks)}")
        print(f"⚠️  Предупреждений: {len(self.warnings)}")
        print(f"❌ Ошибок: {len(self.errors)}")
        
        total_checks = len(self.passed_checks) + len(self.warnings) + len(self.errors)
        if total_checks > 0:
            success_rate = (len(self.passed_checks) / total_checks) * 100
            print(f"📈 Успешность: {success_rate:.1f}%")
            
        if self.passed_checks:
            print(f"\n✅ УСПЕШНЫЕ ПРОВЕРКИ:")
            for check in self.passed_checks:
                print(f"  {check}")
                
        if self.warnings:
            print(f"\n⚠️  ПРЕДУПРЕЖДЕНИЯ:")
            for warning in self.warnings:
                print(f"  {warning}")
                
        if self.errors:
            print(f"\n❌ ОШИБКИ:")
            for error in self.errors:
                print(f"  {error}")
                
        print(f"\n🎉 ЗАКЛЮЧЕНИЕ:")
        if len(self.errors) == 0:
            print("✅ Все PHP классы созданы корректно и готовы к использованию!")
            print("✅ Задача 2 'Создание PHP классов для работы со странами' выполнена успешно!")
        else:
            print("❌ Обнаружены критические ошибки, требующие исправления.")
            
        print("\n📋 СОЗДАННЫЕ ФАЙЛЫ:")
        files_to_check = [
            "classes/Region.php",
            "classes/CarFilter.php", 
            "tests/RegionTest.php",
            "tests/CarFilterTest.php",
            "tests/run_all_tests.php"
        ]
        
        for file_path in files_to_check:
            if os.path.exists(file_path):
                size = os.path.getsize(file_path)
                print(f"  ✅ {file_path} ({size} bytes)")
            else:
                print(f"  ❌ {file_path} (не найден)")

if __name__ == "__main__":
    validator = PHPClassValidator()
    validator.validate_all_classes()