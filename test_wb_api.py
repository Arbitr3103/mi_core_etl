#!/usr/bin/env python3
"""
Тестовый скрипт для проверки работы с API Wildberries.

Использование:
    python test_wb_api.py --test-config     # Проверка конфигурации
    python test_wb_api.py --test-sales      # Тест загрузки продаж
    python test_wb_api.py --test-finance    # Тест загрузки финансовых деталей
    python test_wb_api.py --test-db         # Тест подключения к БД
    python test_wb_api.py --test-all        # Все тесты
"""

import sys
import os
import argparse
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from wb_importer import (
    load_config, connect_to_db, get_client_id_by_name, get_source_id_by_code,
    make_wb_request, get_sales_from_api, get_financial_details_api,
    import_sales, import_financial_details, logger
)


def test_config():
    """Тест загрузки конфигурации."""
    logger.info("=== Тест загрузки конфигурации ===")
    
    try:
        config = load_config()
        logger.info("✅ Конфигурация загружена успешно")
        logger.info(f"DB_HOST: {config['DB_HOST']}")
        logger.info(f"DB_NAME: {config['DB_NAME']}")
        logger.info(f"WB_API_URL: {config['WB_API_URL']}")
        logger.info(f"WB_API_KEY: {config['WB_API_KEY'][:10]}...")
        return True
    except Exception as e:
        logger.error(f"❌ Ошибка загрузки конфигурации: {e}")
        return False


def test_db_connection():
    """Тест подключения к базе данных."""
    logger.info("=== Тест подключения к БД ===")
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor()
        cursor.execute("SELECT VERSION()")
        version = cursor.fetchone()
        cursor.close()
        connection.close()
        
        logger.info(f"✅ Подключение к БД успешно. MySQL версия: {version[0]}")
        return True
    except Exception as e:
        logger.error(f"❌ Ошибка подключения к БД: {e}")
        return False


def test_client_source_ids():
    """Тест получения client_id и source_id."""
    logger.info("=== Тест получения client_id и source_id ===")
    
    try:
        client_id = get_client_id_by_name('ТД Манхэттен')
        source_id = get_source_id_by_code('WB')
        
        if client_id and source_id:
            logger.info(f"✅ client_id: {client_id}, source_id: {source_id}")
            return True
        else:
            logger.error("❌ Не удалось получить client_id или source_id")
            logger.error("Убедитесь, что выполнены скрипты setup_wb_data.sql")
            return False
    except Exception as e:
        logger.error(f"❌ Ошибка получения ID: {e}")
        return False


def test_wb_api_connection():
    """Тест подключения к API Wildberries."""
    logger.info("=== Тест подключения к API Wildberries ===")
    
    try:
        # Пробуем сделать простой запрос к API
        # Используем endpoint для получения информации о складах
        response = make_wb_request('/api/v3/warehouses')
        
        if isinstance(response, list):
            logger.info(f"✅ API Wildberries доступен. Получено складов: {len(response)}")
            return True
        else:
            logger.warning(f"⚠️ Неожиданный формат ответа API: {type(response)}")
            return False
    except Exception as e:
        logger.error(f"❌ Ошибка подключения к API Wildberries: {e}")
        logger.error("Проверьте корректность WB_API_KEY в .env файле")
        return False


def test_sales_api():
    """Тест загрузки продаж (ограниченный тест)."""
    logger.info("=== Тест загрузки продаж (последние 2 дня) ===")
    
    try:
        # Тестируем за последние 2 дня
        end_date = datetime.now() - timedelta(days=1)
        start_date = end_date - timedelta(days=1)
        
        start_date_str = start_date.strftime('%Y-%m-%d')
        end_date_str = end_date.strftime('%Y-%m-%d')
        
        logger.info(f"Период тестирования: {start_date_str} - {end_date_str}")
        
        # Ограничиваем тест - не сохраняем в БД, только проверяем API
        sales = get_sales_from_api(start_date_str, end_date_str)
        
        logger.info(f"✅ Загружено продаж: {len(sales)}")
        
        if sales:
            sample_sale = sales[0]
            logger.info("Пример продажи:")
            for key, value in list(sample_sale.items())[:5]:
                logger.info(f"  {key}: {value}")
        
        return True
    except Exception as e:
        logger.error(f"❌ Ошибка тестирования продаж: {e}")
        return False


def test_finance_api():
    """Тест загрузки финансовых деталей (ограниченный тест)."""
    logger.info("=== Тест загрузки финансовых деталей (последние 2 дня) ===")
    
    try:
        # Тестируем за последние 2 дня
        end_date = datetime.now() - timedelta(days=1)
        start_date = end_date - timedelta(days=1)
        
        start_date_str = start_date.strftime('%Y-%m-%d')
        end_date_str = end_date.strftime('%Y-%m-%d')
        
        logger.info(f"Период тестирования: {start_date_str} - {end_date_str}")
        
        # Ограничиваем тест - не сохраняем в БД, только проверяем API
        financial_details = get_financial_details_api(start_date_str, end_date_str)
        
        logger.info(f"✅ Загружено финансовых записей: {len(financial_details)}")
        
        if financial_details:
            sample_detail = financial_details[0]
            logger.info("Пример финансовой записи:")
            for key, value in list(sample_detail.items())[:5]:
                logger.info(f"  {key}: {value}")
        
        return True
    except Exception as e:
        logger.error(f"❌ Ошибка тестирования финансовых деталей: {e}")
        return False


def test_full_integration():
    """Полный тест интеграции (с записью в БД)."""
    logger.info("=== Полный тест интеграции ===")
    
    try:
        # Тестируем за вчерашний день
        yesterday = datetime.now() - timedelta(days=1)
        test_date = yesterday.strftime('%Y-%m-%d')
        
        logger.info(f"Тестируем полную интеграцию за {test_date}")
        
        # Тест импорта продаж
        logger.info("Тестируем импорт продаж...")
        import_sales(test_date, test_date)
        
        # Тест импорта финансовых деталей
        logger.info("Тестируем импорт финансовых деталей...")
        import_financial_details(test_date, test_date)
        
        logger.info("✅ Полный тест интеграции завершен успешно")
        return True
    except Exception as e:
        logger.error(f"❌ Ошибка полного теста интеграции: {e}")
        return False


def main():
    """Главная функция."""
    parser = argparse.ArgumentParser(description='Тестирование интеграции с Wildberries API')
    
    parser.add_argument('--test-config', action='store_true', help='Тест конфигурации')
    parser.add_argument('--test-db', action='store_true', help='Тест подключения к БД')
    parser.add_argument('--test-api', action='store_true', help='Тест подключения к API')
    parser.add_argument('--test-sales', action='store_true', help='Тест загрузки продаж')
    parser.add_argument('--test-finance', action='store_true', help='Тест загрузки финансовых деталей')
    parser.add_argument('--test-integration', action='store_true', help='Полный тест интеграции')
    parser.add_argument('--test-all', action='store_true', help='Все тесты')
    
    args = parser.parse_args()
    
    logger.info("🧪 Запуск тестирования Wildberries API")
    
    tests_passed = 0
    tests_total = 0
    
    if args.test_all or args.test_config:
        tests_total += 1
        if test_config():
            tests_passed += 1
    
    if args.test_all or args.test_db:
        tests_total += 1
        if test_db_connection():
            tests_passed += 1
        
        # Дополнительно тестируем получение ID
        tests_total += 1
        if test_client_source_ids():
            tests_passed += 1
    
    if args.test_all or args.test_api:
        tests_total += 1
        if test_wb_api_connection():
            tests_passed += 1
    
    if args.test_all or args.test_sales:
        tests_total += 1
        if test_sales_api():
            tests_passed += 1
    
    if args.test_all or args.test_finance:
        tests_total += 1
        if test_finance_api():
            tests_passed += 1
    
    if args.test_integration:
        tests_total += 1
        if test_full_integration():
            tests_passed += 1
    
    # Если никакие тесты не указаны, запускаем базовые
    if not any([args.test_config, args.test_db, args.test_api, args.test_sales, 
                args.test_finance, args.test_integration, args.test_all]):
        logger.info("Запуск базовых тестов...")
        tests_total = 3
        
        if test_config():
            tests_passed += 1
        if test_db_connection():
            tests_passed += 1
        if test_client_source_ids():
            tests_passed += 1
    
    # Итоги
    logger.info(f"🏁 Тестирование завершено: {tests_passed}/{tests_total} тестов прошли успешно")
    
    if tests_passed == tests_total:
        logger.info("🎉 Все тесты прошли успешно!")
        return 0
    else:
        logger.error("❌ Некоторые тесты не прошли. Проверьте конфигурацию.")
        return 1


if __name__ == "__main__":
    exit_code = main()
    sys.exit(exit_code)
