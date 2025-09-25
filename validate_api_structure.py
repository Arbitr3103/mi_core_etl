#!/usr/bin/env python3
"""
Скрипт для валидации структуры API фильтра по странам
"""

import os
import re

def validate_php_file(filepath):
    """Проверяет базовую структуру PHP файла"""
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()
        
        errors = []
        warnings = []
        
        # Проверяем наличие открывающего тега PHP
        if not content.strip().startswith('<?php'):
            errors.append("Файл должен начинаться с <?php")
        
        # Проверяем наличие основных элементов для API файлов
        if 'api/' in filepath:
            # Проверяем CORS заголовки
            if 'Access-Control-Allow-Origin' not in content:
                warnings.append("Отсутствуют CORS заголовки")
            
            # Проверяем Content-Type
            if 'Content-Type: application/json' not in content:
                warnings.append("Не установлен JSON Content-Type")
            
            # Проверяем обработку OPTIONS
            if 'OPTIONS' not in content:
                warnings.append("Отсутствует обработка OPTIONS запросов")
        
        # Проверяем наличие обработки ошибок
        if 'try' not in content or 'catch' not in content:
            warnings.append("Отсутствует обработка исключений")
        
        return errors, warnings
        
    except Exception as e:
        return [f"Ошибка чтения файла: {e}"], []

def main():
    print("🔍 ВАЛИДАЦИЯ СТРУКТУРЫ API ФИЛЬТРА ПО СТРАНАМ")
    print("=" * 60)
    
    # Список файлов для проверки
    files_to_check = [
        'CountryFilterAPI.php',
        'api/countries.php',
        'api/countries-by-brand.php',
        'api/countries-by-model.php',
        'api/products-filter.php',
        'test_country_filter_api.php'
    ]
    
    total_errors = 0
    total_warnings = 0
    
    for filepath in files_to_check:
        print(f"\n📄 Проверка файла: {filepath}")
        print("-" * 50)
        
        if not os.path.exists(filepath):
            print(f"❌ Файл не найден: {filepath}")
            total_errors += 1
            continue
        
        errors, warnings = validate_php_file(filepath)
        
        if not errors and not warnings:
            print("✅ Файл корректен")
        else:
            if errors:
                print("❌ Ошибки:")
                for error in errors:
                    print(f"   - {error}")
                total_errors += len(errors)
            
            if warnings:
                print("⚠️  Предупреждения:")
                for warning in warnings:
                    print(f"   - {warning}")
                total_warnings += len(warnings)
    
    # Проверяем структуру директорий
    print(f"\n📁 Проверка структуры директорий")
    print("-" * 50)
    
    if os.path.exists('api/'):
        print("✅ Директория api/ создана")
        
        api_files = ['countries.php', 'countries-by-brand.php', 'countries-by-model.php', 'products-filter.php']
        for api_file in api_files:
            if os.path.exists(f'api/{api_file}'):
                print(f"✅ API endpoint: api/{api_file}")
            else:
                print(f"❌ Отсутствует API endpoint: api/{api_file}")
                total_errors += 1
    else:
        print("❌ Директория api/ не создана")
        total_errors += 1
    
    # Проверяем документацию
    print(f"\n📚 Проверка документации")
    print("-" * 50)
    
    if os.path.exists('COUNTRY_FILTER_API_GUIDE.md'):
        print("✅ Руководство по API создано")
    else:
        print("❌ Отсутствует руководство по API")
        total_warnings += 1
    
    # Итоговый отчет
    print(f"\n📊 ИТОГОВЫЙ ОТЧЕТ")
    print("=" * 60)
    
    print(f"Проверено файлов: {len(files_to_check)}")
    print(f"Ошибок: {total_errors}")
    print(f"Предупреждений: {total_warnings}")
    
    if total_errors == 0:
        print("\n🎉 ВСЕ ОСНОВНЫЕ КОМПОНЕНТЫ СОЗДАНЫ УСПЕШНО!")
        print("\n✅ Созданные компоненты:")
        print("   - CountryFilterAPI.php - основной класс API")
        print("   - api/countries.php - получение всех стран")
        print("   - api/countries-by-brand.php - страны для марки")
        print("   - api/countries-by-model.php - страны для модели")
        print("   - api/products-filter.php - фильтрация товаров")
        print("   - test_country_filter_api.php - тестовый скрипт")
        print("   - COUNTRY_FILTER_API_GUIDE.md - документация")
        
        print("\n📋 Следующие шаги:")
        print("   1. Настройте подключение к базе данных в .env файле")
        print("   2. Убедитесь, что веб-сервер может выполнять PHP файлы")
        print("   3. Протестируйте API endpoints через браузер или curl")
        print("   4. Интегрируйте с frontend компонентами")
        
    else:
        print(f"\n⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ: {total_errors} ошибок, {total_warnings} предупреждений")
        print("Исправьте ошибки перед использованием API")

if __name__ == "__main__":
    main()