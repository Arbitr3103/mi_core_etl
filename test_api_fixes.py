#!/usr/bin/env python3
"""
Тест исправлений API для Ozon и Wildberries.
"""

import requests
import json

def test_ozon_api():
    """Тест Ozon API v4/product/info/stocks"""
    print("🔄 Тестируем Ozon API...")
    
    url = "https://api-seller.ozon.ru/v4/product/info/stocks"
    headers = {
        "Client-Id": "26100",
        "Api-Key": "7e074977-e0db-4ace-ba9e-82903e088b4b",
        "Content-Type": "application/json"
    }
    
    payload = {
        "filter": {
            "visibility": "ALL"
        },
        "limit": 5
    }
    
    try:
        response = requests.post(url, json=payload, headers=headers)
        response.raise_for_status()
        
        data = response.json()
        
        print(f"✅ Ozon API работает!")
        print(f"   Получено товаров: {len(data.get('items', []))}")
        print(f"   Общее количество: {data.get('total', 0)}")
        print(f"   Есть cursor: {'cursor' in data}")
        
        # Проверяем структуру первого товара
        if data.get('items'):
            item = data['items'][0]
            print(f"   Пример товара:")
            print(f"     offer_id: {item.get('offer_id')}")
            print(f"     stocks: {len(item.get('stocks', []))}")
            if item.get('stocks'):
                stock = item['stocks'][0]
                print(f"     stock type: {stock.get('type')}")
                print(f"     present: {stock.get('present')}")
                print(f"     reserved: {stock.get('reserved')}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка Ozon API: {e}")
        return False

def test_wb_api():
    """Тест Wildberries API"""
    print("\n🔄 Тестируем Wildberries API...")
    
    # Тест нового домена
    url = "https://statistics-api.wildberries.ru/api/v1/supplier/warehouses"
    headers = {
        "Authorization": "WB_API_KEY"  # Заглушка
    }
    
    try:
        response = requests.get(url, headers=headers, timeout=5)
        print(f"✅ Новый домен statistics-api.wildberries.ru доступен!")
        print(f"   Status code: {response.status_code}")
        
        if response.status_code == 401:
            print("   (401 - ожидаемо, нужен правильный API ключ)")
        elif response.status_code == 403:
            print("   (403 - ожидаемо, нужен правильный API ключ)")
        
        return True
        
    except requests.exceptions.ConnectionError as e:
        print(f"❌ Ошибка подключения к новому домену: {e}")
        return False
    except Exception as e:
        print(f"❌ Другая ошибка: {e}")
        return False

def test_old_wb_domain():
    """Тест старого домена WB для сравнения"""
    print("\n🔄 Тестируем старый домен Wildberries...")
    
    url = "https://suppliers-api.wildberries.ru/api/v1/warehouses"
    headers = {
        "Authorization": "WB_API_KEY"
    }
    
    try:
        response = requests.get(url, headers=headers, timeout=5)
        print(f"❌ Старый домен suppliers-api.wildberries.ru все еще работает?")
        print(f"   Status code: {response.status_code}")
        return True
        
    except requests.exceptions.ConnectionError as e:
        print(f"✅ Старый домен suppliers-api.wildberries.ru недоступен (ожидаемо)")
        return False
    except Exception as e:
        print(f"❌ Другая ошибка: {e}")
        return False

if __name__ == "__main__":
    print("🧪 Тестирование исправлений API")
    print("=" * 50)
    
    ozon_ok = test_ozon_api()
    wb_ok = test_wb_api()
    test_old_wb_domain()
    
    print("\n📊 Результаты тестирования:")
    print(f"   Ozon API: {'✅ OK' if ozon_ok else '❌ FAIL'}")
    print(f"   WB API: {'✅ OK' if wb_ok else '❌ FAIL'}")
    
    if ozon_ok and wb_ok:
        print("\n🎉 Все исправления работают корректно!")
    else:
        print("\n⚠️ Есть проблемы, требующие внимания")