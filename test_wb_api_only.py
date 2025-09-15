#!/usr/bin/env python3
"""
Тестовый скрипт для проверки API Wildberries БЕЗ подключения к БД.
Проверяет только конфигурацию и доступность API.
"""

import os
import json
import requests
import urllib3
from datetime import datetime, timedelta
from dotenv import load_dotenv

# Отключаем предупреждения SSL для тестирования
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# Настройка логирования
import logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def load_wb_config():
    """Загружает только WB конфигурацию из .env файла."""
    load_dotenv()
    
    config = {
        'WB_API_KEY': os.getenv('WB_API_KEY'),
        'WB_API_URL': os.getenv('WB_API_URL', 'https://statistics-api.wildberries.ru')
    }
    
    if not config['WB_API_KEY']:
        raise ValueError("WB_API_KEY не найден в .env файле")
    
    logger.info("✅ WB конфигурация загружена успешно")
    logger.info(f"WB_API_URL: {config['WB_API_URL']}")
    logger.info(f"WB_API_KEY: {config['WB_API_KEY'][:10]}...")
    
    return config


def test_wb_api_request(endpoint, params=None):
    """Выполняет тестовый запрос к API Wildberries."""
    config = load_wb_config()
    
    url = f"{config['WB_API_URL']}{endpoint}"
    headers = {
        'Authorization': config['WB_API_KEY'],
        'Content-Type': 'application/json'
    }
    
    try:
        logger.info(f"🔄 Тестируем endpoint: {endpoint}")
        response = requests.get(url, headers=headers, params=params or {}, timeout=30, verify=False)
        
        logger.info(f"HTTP Status: {response.status_code}")
        
        if response.status_code == 200:
            try:
                data = response.json()
                logger.info(f"✅ Успешный ответ от {endpoint}")
                return data
            except json.JSONDecodeError:
                logger.info(f"✅ Успешный ответ (не JSON) от {endpoint}")
                return response.text
        else:
            logger.error(f"❌ Ошибка {response.status_code}: {response.text}")
            return None
            
    except requests.exceptions.RequestException as e:
        logger.error(f"❌ Ошибка запроса: {e}")
        return None


def test_warehouses():
    """Тест получения списка складов."""
    logger.info("=== Тест: Получение списка складов ===")
    
    data = test_wb_api_request('/api/v3/warehouses')
    
    if data and isinstance(data, list):
        logger.info(f"📦 Найдено складов: {len(data)}")
        if data:
            logger.info("Пример склада:")
            warehouse = data[0]
            for key, value in list(warehouse.items())[:3]:
                logger.info(f"  {key}: {value}")
        return True
    else:
        logger.warning("⚠️ Не удалось получить список складов")
        return False


def test_sales_api():
    """Тест API продаж (ограниченный запрос)."""
    logger.info("=== Тест: API продаж (последние 2 дня) ===")
    
    # Тестируем за последние 2 дня
    end_date = datetime.now() - timedelta(days=1)
    start_date = end_date - timedelta(days=1)
    
    date_from = f"{start_date.strftime('%Y-%m-%d')}T00:00:00Z"
    
    params = {
        'dateFrom': date_from
    }
    
    logger.info(f"📅 Запрашиваем продажи с: {date_from}")
    
    data = test_wb_api_request('/api/v1/supplier/sales', params)
    
    if data and isinstance(data, list):
        logger.info(f"🛒 Найдено продаж: {len(data)}")
        if data:
            logger.info("Пример продажи:")
            sale = data[0]
            for key, value in list(sale.items())[:5]:
                logger.info(f"  {key}: {value}")
        return True
    else:
        logger.warning("⚠️ Не удалось получить данные о продажах")
        return False


def test_finance_api():
    """Тест API финансовых деталей (ограниченный запрос)."""
    logger.info("=== Тест: API финансовых деталей (последние 2 дня) ===")
    
    # Тестируем за последние 2 дня
    end_date = datetime.now() - timedelta(days=1)
    start_date = end_date - timedelta(days=1)
    
    params = {
        'dateFrom': start_date.strftime('%Y-%m-%d'),
        'dateTo': end_date.strftime('%Y-%m-%d'),
        'rrdid': 0
    }
    
    logger.info(f"📅 Запрашиваем финансы: {params['dateFrom']} - {params['dateTo']}")
    
    data = test_wb_api_request('/api/v5/supplier/reportDetailByPeriod', params)
    
    if data and isinstance(data, list):
        logger.info(f"💰 Найдено финансовых записей: {len(data)}")
        if data:
            logger.info("Пример финансовой записи:")
            finance = data[0]
            for key, value in list(finance.items())[:5]:
                logger.info(f"  {key}: {value}")
        return True
    else:
        logger.warning("⚠️ Не удалось получить финансовые данные")
        return False


def test_api_limits():
    """Тест различных endpoints для проверки доступности."""
    logger.info("=== Тест: Проверка доступных endpoints ===")
    
    endpoints_to_test = [
        '/api/v3/warehouses',
        '/api/v2/stocks',
        '/public/api/v1/info',
    ]
    
    successful_endpoints = 0
    
    for endpoint in endpoints_to_test:
        logger.info(f"🔍 Проверяем: {endpoint}")
        data = test_wb_api_request(endpoint)
        
        if data is not None:
            successful_endpoints += 1
            logger.info(f"✅ {endpoint} - доступен")
        else:
            logger.warning(f"⚠️ {endpoint} - недоступен")
    
    logger.info(f"📊 Доступно endpoints: {successful_endpoints}/{len(endpoints_to_test)}")
    return successful_endpoints > 0


def main():
    """Главная функция тестирования."""
    logger.info("🧪 Тестирование API Wildberries (БЕЗ БД)")
    logger.info("=" * 50)
    
    tests_passed = 0
    tests_total = 0
    
    # Тест конфигурации
    tests_total += 1
    try:
        load_wb_config()
        tests_passed += 1
        logger.info("✅ Конфигурация: OK")
    except Exception as e:
        logger.error(f"❌ Конфигурация: {e}")
    
    logger.info("-" * 30)
    
    # Тест доступности API
    tests_total += 1
    if test_api_limits():
        tests_passed += 1
        logger.info("✅ Доступность API: OK")
    else:
        logger.error("❌ Доступность API: FAIL")
    
    logger.info("-" * 30)
    
    # Тест складов
    tests_total += 1
    if test_warehouses():
        tests_passed += 1
        logger.info("✅ Склады: OK")
    else:
        logger.error("❌ Склады: FAIL")
    
    logger.info("-" * 30)
    
    # Тест продаж
    tests_total += 1
    if test_sales_api():
        tests_passed += 1
        logger.info("✅ Продажи: OK")
    else:
        logger.error("❌ Продажи: FAIL")
    
    logger.info("-" * 30)
    
    # Тест финансов
    tests_total += 1
    if test_finance_api():
        tests_passed += 1
        logger.info("✅ Финансы: OK")
    else:
        logger.error("❌ Финансы: FAIL")
    
    # Итоги
    logger.info("=" * 50)
    logger.info(f"🏁 Результат: {tests_passed}/{tests_total} тестов прошли успешно")
    
    if tests_passed >= 3:  # Минимум конфигурация + API + один endpoint
        logger.info("🎉 API Wildberries работает корректно!")
        logger.info("💡 Можно переходить к тестированию с БД: python test_wb_api.py --test-all")
        return 0
    else:
        logger.error("❌ Есть проблемы с API или конфигурацией")
        logger.error("🔧 Проверьте WB_API_KEY в .env файле")
        return 1


if __name__ == "__main__":
    exit_code = main()
    exit(exit_code)
