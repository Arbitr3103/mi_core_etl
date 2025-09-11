#!/usr/bin/env python3
"""
Отладочный скрипт для проверки различных методов авторизации BaseBuy API.
"""

import os
import requests
from dotenv import load_dotenv

load_dotenv()

def debug_basebuy_auth():
    """Отладка авторизации BaseBuy API."""
    
    api_key = os.getenv('BASEBUY_API_KEY')
    if not api_key:
        print("❌ BASEBUY_API_KEY не найден в .env")
        return
    
    print(f"🔑 Тестируем API ключ: {api_key}")
    print()
    
    # Тестовые URL
    test_urls = [
        "https://api.basebuy.ru/api/auto/v1/mark.getAll.csv",
        "https://basebuy.ru/api/auto/v1/mark.getAll.csv",
        "https://api.basebuy.ru/v1/mark.getAll.csv",
        "https://basebuy.ru/v1/mark.getAll.csv"
    ]
    
    # Варианты авторизации
    auth_methods = [
        {
            'name': 'Query Parameter (api_key)',
            'params': {'api_key': api_key, 'id_type': 1},
            'headers': {}
        },
        {
            'name': 'Authorization Bearer',
            'params': {'id_type': 1},
            'headers': {'Authorization': f'Bearer {api_key}'}
        },
        {
            'name': 'X-API-Key Header',
            'params': {'id_type': 1},
            'headers': {'X-API-Key': api_key}
        },
        {
            'name': 'API-Key Header',
            'params': {'id_type': 1},
            'headers': {'API-Key': api_key}
        },
        {
            'name': 'Authorization Basic',
            'params': {'id_type': 1},
            'headers': {'Authorization': f'Basic {api_key}'}
        }
    ]
    
    for url in test_urls:
        print(f"🌐 Тестируем URL: {url}")
        print("-" * 60)
        
        for method in auth_methods:
            try:
                print(f"   🔐 {method['name']}")
                
                response = requests.get(
                    url,
                    params=method['params'],
                    headers=method['headers'],
                    timeout=10
                )
                
                print(f"      Статус: {response.status_code}")
                
                if response.status_code == 200:
                    content_preview = response.text[:100].replace('\n', ' ')
                    print(f"      ✅ Успех! Размер: {len(response.text)} символов")
                    print(f"      📄 Превью: {content_preview}...")
                    return url, method  # Возвращаем рабочую комбинацию
                elif response.status_code == 401:
                    print(f"      ❌ 401 Unauthorized")
                elif response.status_code == 404:
                    print(f"      ❌ 404 Not Found")
                else:
                    print(f"      ⚠️ Статус {response.status_code}")
                    error_preview = response.text[:100].replace('\n', ' ')
                    print(f"      📄 Ответ: {error_preview}...")
                
            except Exception as e:
                print(f"      💥 Ошибка: {e}")
        
        print()
    
    print("❌ Ни один метод авторизации не сработал")
    
    # Дополнительная диагностика
    print("\n🔍 ДОПОЛНИТЕЛЬНАЯ ДИАГНОСТИКА:")
    print("-" * 40)
    
    # Проверяем доступность базового домена
    try:
        response = requests.get("https://api.basebuy.ru", timeout=5)
        print(f"✅ api.basebuy.ru доступен (статус: {response.status_code})")
    except Exception as e:
        print(f"❌ api.basebuy.ru недоступен: {e}")
    
    # Проверяем альтернативный домен
    try:
        response = requests.get("https://basebuy.ru", timeout=5)
        print(f"✅ basebuy.ru доступен (статус: {response.status_code})")
    except Exception as e:
        print(f"❌ basebuy.ru недоступен: {e}")
    
    # Проверяем формат API ключа
    print(f"\n📋 Анализ API ключа:")
    print(f"   Длина: {len(api_key)} символов")
    print(f"   Формат: {'hex' if all(c in '0123456789abcdef' for c in api_key.lower()) else 'mixed'}")
    print(f"   Начинается с: {api_key[:5]}...")
    print(f"   Заканчивается на: ...{api_key[-5:]}")
    
    return None

if __name__ == "__main__":
    result = debug_basebuy_auth()
    if result:
        url, method = result
        print(f"\n🎉 НАЙДЕН РАБОЧИЙ МЕТОД!")
        print(f"URL: {url}")
        print(f"Авторизация: {method['name']}")
    else:
        print(f"\n💥 РАБОЧИЙ МЕТОД НЕ НАЙДЕН")
        print("Возможные причины:")
        print("1. API ключ недействителен или истек")
        print("2. Превышен лимит запросов (100/день)")
        print("3. Изменился формат API или требуется активация")
        print("4. Проблемы с сетевым подключением")
