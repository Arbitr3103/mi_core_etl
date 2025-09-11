#!/usr/bin/env python3
"""
Тестовый скрипт для проверки BaseBuy API endpoints.
Проверяет все доступные методы API без подключения к БД.
"""

import os
import sys
from dotenv import load_dotenv

# Загружаем переменные из .env файла
load_dotenv()

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(__file__))

from importers.car_data_updater import CarDataUpdater

def test_basebuy_api():
    """Тестирует все BaseBuy API endpoints."""
    
    print("🧪 ТЕСТИРОВАНИЕ BASEBUY API ENDPOINTS")
    print("=" * 60)
    
    try:
        updater = CarDataUpdater()
        
        # Проверяем наличие API ключа
        if not updater.api_key:
            print("❌ ОШИБКА: BASEBUY_API_KEY не найден в .env файле")
            print("Добавьте в .env файл:")
            print('BASEBUY_API_KEY="bf28ef59a50fcaa49b8ebedce8cc947e"')
            return False
        
        print(f"🔑 API ключ: {updater.api_key[:10]}...")
        print()
        
        # Тестируем получение версии через HTML парсинг
        print("1️⃣ ТЕСТИРОВАНИЕ ПОЛУЧЕНИЯ ВЕРСИИ")
        print("-" * 40)
        
        version = updater.get_latest_version_from_api()
        if version:
            print(f"✅ Версия получена: {version}")
        else:
            print("❌ Не удалось получить версию")
        print()
        
        # Тестируем все API endpoints
        print("2️⃣ ТЕСТИРОВАНИЕ API ENDPOINTS")
        print("-" * 40)
        
        results = updater.test_api_endpoints()
        
        if results['api_key_valid']:
            print("✅ API ключ действителен")
        else:
            print("❌ API ключ недействителен или есть проблемы с доступом")
        
        if results['errors']:
            print("❌ Ошибки:")
            for error in results['errors']:
                print(f"   - {error}")
        
        print()
        print("📊 РЕЗУЛЬТАТЫ ПО СУЩНОСТЯМ:")
        print("-" * 40)
        
        for entity_name, entity_result in results['entities'].items():
            print(f"\n🚗 {entity_name.upper()}:")
            
            if entity_result['error']:
                print(f"   ❌ Ошибка: {entity_result['error']}")
                continue
            
            if entity_result['update_date']:
                print(f"   📅 Дата обновления: {entity_result['update_date']}")
            else:
                print("   ❌ Дата обновления не получена")
            
            if entity_result['csv_available']:
                print(f"   ✅ CSV доступен ({entity_result['csv_size']} символов)")
                if 'csv_preview' in entity_result:
                    print(f"   📄 Превью CSV:")
                    print(f"      {entity_result['csv_preview']}")
            else:
                print("   ❌ CSV недоступен")
        
        print()
        print("3️⃣ ТЕСТИРОВАНИЕ КОНКРЕТНЫХ ENDPOINTS")
        print("-" * 40)
        
        # Тестируем конкретные URL
        test_urls = [
            f"{updater.api_base_url}/mark.getAll.csv?api_key={updater.api_key}&id_type=1",
            f"{updater.api_base_url}/mark.getDateUpdate.timestamp?api_key={updater.api_key}&id_type=1",
            f"{updater.api_base_url}/model.getAll.csv?api_key={updater.api_key}&id_type=1",
            f"{updater.api_base_url}/serie.getAll.csv?api_key={updater.api_key}&id_type=1"
        ]
        
        import requests
        
        for url in test_urls:
            try:
                print(f"🔗 Тестируем: {url.replace(updater.api_key, 'XXX')}")
                
                response = requests.get(url, timeout=10)
                
                if response.status_code == 200:
                    content = response.text[:100] + "..." if len(response.text) > 100 else response.text
                    print(f"   ✅ Статус: {response.status_code}")
                    print(f"   📄 Размер: {len(response.text)} символов")
                    print(f"   📝 Содержимое: {content}")
                else:
                    print(f"   ❌ Статус: {response.status_code}")
                    print(f"   📄 Ответ: {response.text[:200]}")
                
            except Exception as e:
                print(f"   ❌ Ошибка: {e}")
            
            print()
        
        print("4️⃣ РЕКОМЕНДАЦИИ")
        print("-" * 40)
        
        if results['api_key_valid']:
            print("✅ API работает корректно!")
            print("📝 Следующие шаги:")
            print("   1. Запустите на сервере: python3 importers/car_data_updater.py")
            print("   2. Проверьте обновление данных в БД")
            print("   3. Настройте cron job для автоматических обновлений")
        else:
            print("❌ Проблемы с API:")
            print("   1. Проверьте правильность API ключа")
            print("   2. Убедитесь в доступности интернета")
            print("   3. Проверьте лимиты API (100 запросов/день)")
        
        return results['api_key_valid']
        
    except Exception as e:
        print(f"❌ КРИТИЧЕСКАЯ ОШИБКА: {e}")
        return False

if __name__ == "__main__":
    success = test_basebuy_api()
    
    print("\n" + "=" * 60)
    if success:
        print("🎉 ТЕСТИРОВАНИЕ ЗАВЕРШЕНО УСПЕШНО!")
    else:
        print("💥 ТЕСТИРОВАНИЕ ЗАВЕРШИЛОСЬ С ОШИБКАМИ!")
    
    sys.exit(0 if success else 1)
