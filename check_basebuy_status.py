#!/usr/bin/env python3
"""
Скрипт для проверки статуса API ключа BaseBuy и диагностики проблем.
Помогает определить причину ошибок 401 Unauthorized.
"""

import os
import sys
import requests
from datetime import datetime
from dotenv import load_dotenv

# Загружаем переменные окружения
load_dotenv()

def check_api_key_status():
    """Проверяет статус API ключа BaseBuy."""
    
    api_key = os.getenv('BASEBUY_API_KEY')
    if not api_key:
        print("❌ BASEBUY_API_KEY не найден в .env файле")
        return False
    
    print("🔍 ДИАГНОСТИКА API КЛЮЧА BASEBUY")
    print("=" * 60)
    print(f"📅 Время проверки: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"🔑 API ключ: {api_key[:8]}...{api_key[-4:]}")
    print()
    
    # Список базовых URL для тестирования
    base_urls = [
        "https://api.basebuy.ru/api/auto/v1",
        "https://basebuy.ru/api/auto/v1",
        "http://api.basebuy.ru/api/auto/v1",
        "http://basebuy.ru/api/auto/v1"
    ]
    
    # Тестовые endpoints
    test_endpoints = [
        "version",
        "mark.getAll.csv",
        "mark.getDateUpdate.timestamp"
    ]
    
    working_urls = []
    
    for base_url in base_urls:
        print(f"🌐 Тестируем базовый URL: {base_url}")
        
        for endpoint in test_endpoints:
            test_url = f"{base_url}/{endpoint}"
            
            # Параметры для тестирования
            params = {'api_key': api_key}
            if 'mark.' in endpoint:
                params['id_type'] = '1'
            
            try:
                response = requests.get(test_url, params=params, timeout=10)
                status = response.status_code
                
                if status == 200:
                    print(f"   ✅ {endpoint}: OK (200)")
                    working_urls.append(test_url)
                elif status == 401:
                    print(f"   ❌ {endpoint}: Unauthorized (401)")
                elif status == 403:
                    print(f"   🚫 {endpoint}: Forbidden (403) - возможно превышен лимит")
                elif status == 404:
                    print(f"   🔍 {endpoint}: Not Found (404)")
                elif status == 429:
                    print(f"   ⏰ {endpoint}: Too Many Requests (429) - превышен лимит")
                else:
                    print(f"   ⚠️ {endpoint}: Статус {status}")
                    
                # Показываем первые символы ответа для диагностики
                content_preview = response.text[:100].replace('\n', ' ')
                if content_preview:
                    print(f"      📄 Ответ: {content_preview}...")
                    
            except requests.exceptions.RequestException as e:
                print(f"   💥 {endpoint}: Ошибка соединения - {e}")
        
        print()
    
    print("📊 РЕЗУЛЬТАТЫ ДИАГНОСТИКИ")
    print("=" * 60)
    
    if working_urls:
        print(f"✅ Найдено {len(working_urls)} рабочих URL:")
        for url in working_urls:
            print(f"   - {url}")
    else:
        print("❌ Ни один URL не работает")
        
        print("\n🔧 ВОЗМОЖНЫЕ ПРИЧИНЫ И РЕШЕНИЯ:")
        print("1. 🔑 API ключ недействителен:")
        print("   - Проверьте правильность ключа на BaseBuy.ru")
        print("   - Возможно ключ истек или требует активации")
        
        print("\n2. 📈 Превышен лимит запросов (100/день):")
        print("   - Подождите до следующего дня")
        print("   - Проверьте логи на количество запросов")
        
        print("\n3. 🌐 Проблемы с сетью:")
        print("   - Проверьте интернет соединение")
        print("   - Возможно блокировка файрволом")
        
        print("\n4. 🔧 Проблемы с сервисом BaseBuy:")
        print("   - Попробуйте позже")
        print("   - Обратитесь в поддержку BaseBuy")
        
        print("\n5. 📝 Альтернативное решение:")
        print("   - Скачайте CSV файлы вручную с BaseBuy.ru")
        print("   - Используйте initial_load.py для загрузки данных")
    
    return len(working_urls) > 0

def check_request_limits():
    """Проверяет возможное превышение лимитов запросов."""
    
    print("\n📊 ПРОВЕРКА ЛИМИТОВ ЗАПРОСОВ")
    print("=" * 60)
    
    # Проверяем логи на количество запросов за сегодня
    log_files = [
        "logs/car_update.log",
        "logs/car_update_latest.log"
    ]
    
    today = datetime.now().strftime('%Y-%m-%d')
    request_count = 0
    
    for log_file in log_files:
        if os.path.exists(log_file):
            try:
                with open(log_file, 'r', encoding='utf-8') as f:
                    content = f.read()
                    # Подсчитываем запросы за сегодня
                    lines = content.split('\n')
                    for line in lines:
                        if today in line and ('requests.get' in line or 'API запрос' in line):
                            request_count += 1
            except Exception as e:
                print(f"⚠️ Ошибка чтения {log_file}: {e}")
    
    print(f"📈 Запросов за сегодня ({today}): {request_count}")
    
    if request_count >= 100:
        print("🚫 ПРЕВЫШЕН ЛИМИТ! BaseBuy разрешает только 100 запросов/день")
        print("⏰ Попробуйте завтра или обратитесь в поддержку для увеличения лимита")
        return False
    elif request_count >= 80:
        print("⚠️ Близко к лимиту! Осталось запросов:", 100 - request_count)
        return True
    else:
        print(f"✅ Лимит в порядке. Осталось запросов: {100 - request_count}")
        return True

def main():
    """Основная функция диагностики."""
    
    print("🔍 ПОЛНАЯ ДИАГНОСТИКА BASEBUY API")
    print("=" * 60)
    
    # Проверяем API ключ
    api_working = check_api_key_status()
    
    # Проверяем лимиты
    limits_ok = check_request_limits()
    
    print("\n🎯 ИТОГОВЫЕ РЕКОМЕНДАЦИИ")
    print("=" * 60)
    
    if api_working:
        print("✅ API работает! Можно использовать автоматическое обновление")
    else:
        if not limits_ok:
            print("🚫 Превышен лимит запросов - ждите завтра")
        else:
            print("❌ Проблемы с API ключом - требуется ручное вмешательство")
            print("📝 Используйте ручное обновление через initial_load.py")

if __name__ == "__main__":
    main()
