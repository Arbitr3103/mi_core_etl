#!/usr/bin/env python3
"""
Тестовый скрипт для проверки импорта товаров из Wildberries Content API.

Использование:
    python test_wb_products_import.py
"""

import sys
import os
import logging

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from wb_importer import import_wb_products, connect_to_db, logger

def test_wb_products_import():
    """
    Тестирует импорт товаров из Wildberries Content API.
    """
    logger.info("=== Тестирование импорта товаров WB ===")
    
    try:
        # Проверяем подключение к БД
        logger.info("Проверяем подключение к базе данных...")
        connection = connect_to_db()
        cursor = connection.cursor()
        
        # Проверяем количество товаров до импорта
        cursor.execute("SELECT COUNT(*) as count FROM dim_products")
        count_before = cursor.fetchone()[0]
        logger.info(f"Товаров в dim_products до импорта: {count_before}")
        
        # Проверяем количество товаров WB до импорта
        cursor.execute("SELECT COUNT(*) as count FROM dim_products WHERE sku_wb IS NOT NULL AND sku_wb != ''")
        wb_count_before = cursor.fetchone()[0]
        logger.info(f"Товаров WB в dim_products до импорта: {wb_count_before}")
        
        cursor.close()
        connection.close()
        
        # Запускаем импорт товаров WB
        logger.info("Запускаем импорт товаров WB...")
        import_wb_products()
        
        # Проверяем результат
        connection = connect_to_db()
        cursor = connection.cursor()
        
        # Проверяем количество товаров после импорта
        cursor.execute("SELECT COUNT(*) as count FROM dim_products")
        count_after = cursor.fetchone()[0]
        logger.info(f"Товаров в dim_products после импорта: {count_after}")
        
        # Проверяем количество товаров WB после импорта
        cursor.execute("SELECT COUNT(*) as count FROM dim_products WHERE sku_wb IS NOT NULL AND sku_wb != ''")
        wb_count_after = cursor.fetchone()[0]
        logger.info(f"Товаров WB в dim_products после импорта: {wb_count_after}")
        
        # Показываем примеры импортированных товаров WB
        cursor.execute("""
            SELECT sku_wb, sku_ozon, name, brand, category, barcode 
            FROM dim_products 
            WHERE sku_wb IS NOT NULL AND sku_wb != '' 
            ORDER BY updated_at DESC 
            LIMIT 5
        """)
        
        wb_products = cursor.fetchall()
        if wb_products:
            logger.info("Примеры импортированных товаров WB:")
            for product in wb_products:
                logger.info(f"  WB: {product[0]}, Ozon: {product[1]}, Название: {product[2]}, Бренд: {product[3]}")
        
        cursor.close()
        connection.close()
        
        # Выводим статистику
        new_wb_products = wb_count_after - wb_count_before
        logger.info(f"✅ Импорт завершен успешно!")
        logger.info(f"📊 Статистика:")
        logger.info(f"   - Новых товаров WB: {new_wb_products}")
        logger.info(f"   - Всего товаров в БД: {count_after}")
        logger.info(f"   - Товаров WB в БД: {wb_count_after}")
        
        return True
        
    except Exception as e:
        logger.error(f"❌ Ошибка при тестировании импорта товаров WB: {e}")
        return False


def check_wb_api_connection():
    """
    Проверяет подключение к WB Content API.
    """
    logger.info("=== Проверка подключения к WB Content API ===")
    
    try:
        from wb_importer import make_wb_request
        
        # Тестовый запрос для проверки API
        test_data = {
            "settings": {
                "filter": {
                    "withPhoto": -1
                },
                "cursor": {
                    "limit": 1  # Запрашиваем только 1 товар для теста
                }
            }
        }
        
        response = make_wb_request('/content/v2/get/cards/list', method='POST', data=test_data)
        
        if isinstance(response, dict) and 'data' in response:
            products_count = len(response.get('data', []))
            logger.info(f"✅ Подключение к WB Content API успешно!")
            logger.info(f"📦 Получено товаров в тестовом запросе: {products_count}")
            
            if products_count > 0:
                product = response['data'][0]
                logger.info(f"Пример товара: nmID={product.get('nmID')}, vendorCode={product.get('vendorCode')}")
            
            return True
        else:
            logger.error(f"❌ Неожиданный формат ответа от WB API: {response}")
            return False
            
    except Exception as e:
        logger.error(f"❌ Ошибка подключения к WB Content API: {e}")
        return False


if __name__ == "__main__":
    # Настройка логирования для теста
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(levelname)s - %(message)s'
    )
    
    print("🧪 Тестирование импорта товаров Wildberries")
    print("=" * 50)
    
    # Проверяем подключение к API
    if not check_wb_api_connection():
        print("❌ Тест провален: не удалось подключиться к WB API")
        sys.exit(1)
    
    print()
    
    # Тестируем импорт товаров
    if test_wb_products_import():
        print("✅ Все тесты пройдены успешно!")
        sys.exit(0)
    else:
        print("❌ Тесты провалены!")
        sys.exit(1)
