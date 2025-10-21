#!/usr/bin/env python3
"""
Простой тест подключения к интернету и API маркетплейсов
"""

import requests
import sys

def test_internet():
    """Тест подключения к интернету"""
    try:
        response = requests.get('https://httpbin.org/get', timeout=5)
        return response.status_code == 200
    except:
        return False

def test_ozon_simple():
    """Простой тест Ozon API"""
    try:
        # Тестируем доступность Ozon API
        response = requests.get('https://api-seller.ozon.ru', timeout=5)
        return True  # Любой ответ означает, что сервер доступен
    except:
        return False

def test_wb_simple():
    """Простой тест WB API"""
    try:
        # Тестируем доступность WB API
        response = requests.get('https://suppliers-api.wildberries.ru', timeout=5)
        return True  # Любой ответ означает, что сервер доступен
    except:
        return False

def main():
    print("🌐 Тестирование подключения...")
    
    # Тест интернета
    if test_internet():
        print("✅ Интернет: подключение работает")
    else:
        print("❌ Интернет: нет подключения")
        return False
    
    # Тест Ozon
    if test_ozon_simple():
        print("✅ Ozon API: сервер доступен")
    else:
        print("❌ Ozon API: сервер недоступен")
    
    # Тест WB
    if test_wb_simple():
        print("✅ WB API: сервер доступен")
    else:
        print("❌ WB API: сервер недоступен")
    
    return True

if __name__ == "__main__":
    main()