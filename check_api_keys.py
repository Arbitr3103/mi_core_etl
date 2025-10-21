#!/usr/bin/env python3
"""
Скрипт проверки API ключей маркетплейсов
Проверяет доступность и корректность API ключей Ozon и WB
"""

import os
import sys
import requests
import json
from datetime import datetime

def load_env_file(env_path='.env'):
    """Загружает переменные из .env файла"""
    env_vars = {}
    try:
        with open(env_path, 'r') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    key, value = line.split('=', 1)
                    env_vars[key.strip()] = value.strip()
    except FileNotFoundError:
        print(f"❌ Файл {env_path} не найден")
    return env_vars

def check_ozon_api(client_id, api_key):
    """Проверяет подключение к Ozon API"""
    if not client_id or not api_key:
        return False, "API ключи не найдены в конфигурации"
    
    headers = {
        'Client-Id': client_id,
        'Api-Key': api_key,
        'Content-Type': 'application/json'
    }
    
    try:
        # Тестовый запрос к Ozon API - получение списка товаров
        response = requests.post(
            'https://api-seller.ozon.ru/v2/product/list',
            headers=headers,
            json={
                'filter': {},
                'limit': 1,
                'last_id': '',
                'sort_dir': 'ASC'
            },
            timeout=10
        )
        
        if response.status_code == 200:
            data = response.json()
            if 'result' in data:
                return True, f"Подключение успешно. Найдено товаров: {len(data.get('result', {}).get('items', []))}"
            else:
                return False, f"Неожиданный формат ответа: {response.text[:200]}"
        else:
            return False, f"HTTP {response.status_code}: {response.text[:200]}"
            
    except requests.exceptions.Timeout:
        return False, "Таймаут подключения к Ozon API"
    except requests.exceptions.RequestException as e:
        return False, f"Ошибка подключения: {str(e)}"
    except Exception as e:
        return False, f"Неожиданная ошибка: {str(e)}"

def check_wb_api(api_key):
    """Проверяет подключение к Wildberries API"""
    if not api_key:
        return False, "API ключ не найден в конфигурации"
    
    headers = {
        'Authorization': api_key,
        'Content-Type': 'application/json'
    }
    
    try:
        # Тестовый запрос к WB API - получение остатков
        response = requests.get(
            'https://suppliers-api.wildberries.ru/api/v3/stocks',
            headers=headers,
            timeout=10
        )
        
        if response.status_code == 200:
            data = response.json()
            if isinstance(data, list):
                return True, f"Подключение успешно. Найдено остатков: {len(data)}"
            else:
                return True, "Подключение успешно"
        elif response.status_code == 401:
            return False, "Неверный API ключ (401 Unauthorized)"
        elif response.status_code == 403:
            return False, "Доступ запрещен (403 Forbidden)"
        else:
            return False, f"HTTP {response.status_code}: {response.text[:200]}"
            
    except requests.exceptions.Timeout:
        return False, "Таймаут подключения к WB API"
    except requests.exceptions.RequestException as e:
        return False, f"Ошибка подключения: {str(e)}"
    except Exception as e:
        return False, f"Неожиданная ошибка: {str(e)}"

def main():
    """Основная функция проверки"""
    print("🔑 Проверка API ключей маркетплейсов")
    print("=" * 50)
    
    # Загружаем переменные окружения
    env_vars = load_env_file()
    
    # Проверяем Ozon API
    print("\n📦 Проверка Ozon API...")
    ozon_client_id = env_vars.get('OZON_CLIENT_ID', os.getenv('OZON_CLIENT_ID'))
    ozon_api_key = env_vars.get('OZON_API_KEY', os.getenv('OZON_API_KEY'))
    
    ozon_success, ozon_message = check_ozon_api(ozon_client_id, ozon_api_key)
    print(f"   {'✅' if ozon_success else '❌'} Ozon: {ozon_message}")
    
    # Проверяем WB API
    print("\n🛒 Проверка Wildberries API...")
    wb_api_key = env_vars.get('WB_API_KEY', os.getenv('WB_API_KEY'))
    
    wb_success, wb_message = check_wb_api(wb_api_key)
    print(f"   {'✅' if wb_success else '❌'} WB: {wb_message}")
    
    # Итоговый результат
    print("\n" + "=" * 50)
    if ozon_success and wb_success:
        print("✅ Все API ключи работают корректно!")
        print("🚀 Можно переходить к загрузке реальных данных")
        return True
    else:
        print("❌ Обнаружены проблемы с API ключами")
        print("🔧 Необходимо исправить конфигурацию перед миграцией")
        
        if not ozon_success:
            print(f"   - Исправить Ozon API: {ozon_message}")
        if not wb_success:
            print(f"   - Исправить WB API: {wb_message}")
        
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)