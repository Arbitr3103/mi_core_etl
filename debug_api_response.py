#!/usr/bin/env python3
"""
Скрипт для отладки ответов Ozon API.
"""

import os
import sys
import json
import requests
from datetime import datetime

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    import config
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

def debug_v4_api():
    """Отладка v4 API ответа."""
    print("🔍 Отладка v4 API ответа...")
    
    url = f"{config.OZON_API_BASE_URL}/v4/product/info/stocks"
    headers = {
        "Client-Id": config.OZON_CLIENT_ID,
        "Api-Key": config.OZON_API_KEY,
        "Content-Type": "application/json"
    }
    
    payload = {
        "limit": 5,
        "filter": {
            "visibility": "ALL"
        }
    }
    
    try:
        response = requests.post(url, json=payload, headers=headers, timeout=30)
        
        print(f"Статус код: {response.status_code}")
        print(f"Заголовки ответа: {dict(response.headers)}")
        
        if response.status_code == 200:
            data = response.json()
            print(f"Структура ответа:")
            print(json.dumps(data, indent=2, ensure_ascii=False))
        else:
            print(f"Ошибка: {response.text}")
            
    except Exception as e:
        print(f"❌ Ошибка запроса: {e}")

def debug_v3_api():
    """Отладка v3 API ответа."""
    print("\n🔍 Отладка v3 API ответа...")
    
    url = f"{config.OZON_API_BASE_URL}/v3/product/info/stocks"
    headers = {
        "Client-Id": config.OZON_CLIENT_ID,
        "Api-Key": config.OZON_API_KEY,
        "Content-Type": "application/json"
    }
    
    payload = {
        "filter": {
            "visibility": "ALL"
        },
        "limit": 5
    }
    
    try:
        response = requests.post(url, json=payload, headers=headers, timeout=30)
        
        print(f"Статус код: {response.status_code}")
        print(f"Заголовки ответа: {dict(response.headers)}")
        
        if response.status_code == 200:
            data = response.json()
            print(f"Структура ответа:")
            print(json.dumps(data, indent=2, ensure_ascii=False))
        else:
            print(f"Ошибка: {response.text}")
            
    except Exception as e:
        print(f"❌ Ошибка запроса: {e}")

def debug_analytics_api():
    """Отладка Analytics API ответа."""
    print("\n🔍 Отладка Analytics API ответа...")
    
    url = f"{config.OZON_API_BASE_URL}/v2/analytics/stock_on_warehouses"
    headers = {
        "Client-Id": config.OZON_CLIENT_ID,
        "Api-Key": config.OZON_API_KEY,
        "Content-Type": "application/json"
    }
    
    from datetime import date
    today = date.today().isoformat()
    
    payload = {
        "date_from": today,
        "date_to": today,
        "limit": 5,
        "offset": 0,
        "metrics": [
            "free_to_sell_amount",
            "promised_amount", 
            "reserved_amount"
        ],
        "dimensions": [
            "sku",
            "warehouse"
        ]
    }
    
    try:
        response = requests.post(url, json=payload, headers=headers, timeout=30)
        
        print(f"Статус код: {response.status_code}")
        print(f"Заголовки ответа: {dict(response.headers)}")
        
        if response.status_code == 200:
            data = response.json()
            print(f"Структура ответа:")
            print(json.dumps(data, indent=2, ensure_ascii=False))
        else:
            print(f"Ошибка: {response.text}")
            
    except Exception as e:
        print(f"❌ Ошибка запроса: {e}")

def debug_warehouse_api():
    """Отладка Warehouse API ответа."""
    print("\n🔍 Отладка Warehouse API ответа...")
    
    url = f"{config.OZON_API_BASE_URL}/v1/warehouse/list"
    headers = {
        "Client-Id": config.OZON_CLIENT_ID,
        "Api-Key": config.OZON_API_KEY,
        "Content-Type": "application/json"
    }
    
    try:
        response = requests.post(url, json={}, headers=headers, timeout=30)
        
        print(f"Статус код: {response.status_code}")
        print(f"Заголовки ответа: {dict(response.headers)}")
        
        if response.status_code == 200:
            data = response.json()
            print(f"Структура ответа:")
            print(json.dumps(data, indent=2, ensure_ascii=False))
        else:
            print(f"Ошибка: {response.text}")
            
    except Exception as e:
        print(f"❌ Ошибка запроса: {e}")

if __name__ == "__main__":
    print("🚀 Отладка ответов Ozon API")
    print("=" * 50)
    
    debug_v4_api()
    debug_v3_api()
    debug_analytics_api()
    debug_warehouse_api()