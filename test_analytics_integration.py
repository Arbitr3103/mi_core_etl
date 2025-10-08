#!/usr/bin/env python3
"""
Тест интеграции аналитического API для получения детализации по складам.
"""

import sys
import os
from datetime import datetime

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(__file__))

from inventory_sync_service_v4 import InventorySyncServiceV4

def test_analytics_integration():
    """Тест интеграции аналитического API."""
    print("🧪 Тестируем интеграцию аналитического API...")
    
    service = InventorySyncServiceV4()
    
    try:
        # Подключаемся к БД
        service.connect_to_database()
        
        # Получаем аналитические данные
        today = datetime.now().strftime('%Y-%m-%d')
        result = service.get_ozon_analytics_stocks(
            date_from=today,
            date_to=today,
            limit=10,
            offset=0
        )
        
        analytics_stocks = result.get("analytics_stocks", [])
        
        print(f"✅ Получено {len(analytics_stocks)} аналитических записей")
        print(f"   Всего доступно: {result.get('total_count', 0)}")
        print(f"   Есть еще данные: {result.get('has_next', False)}")
        
        # Анализируем склады
        warehouses = {}
        for stock in analytics_stocks[:10]:  # Первые 10 записей
            wh_name = stock.warehouse_name
            if wh_name not in warehouses:
                warehouses[wh_name] = []
            warehouses[wh_name].append(stock)
        
        print(f"\n📊 Найдено складов: {len(warehouses)}")
        for wh_name, stocks in warehouses.items():
            print(f"   📦 {wh_name}: {len(stocks)} записей")
            
            # Показываем первую запись для примера
            if stocks:
                stock = stocks[0]
                print(f"      Пример: SKU {stock.offer_id}")
                print(f"      - Свободно к продаже: {stock.free_to_sell_amount}")
                print(f"      - Обещано: {stock.promised_amount}")
                print(f"      - Зарезервировано: {stock.reserved_amount}")
        
        # Тестируем создание маппинга
        print(f"\n🔗 Тестируем создание маппинга...")
        
        # Получаем основные данные
        main_result = service.get_ozon_stocks_v4(limit=5)
        main_stocks = service.process_ozon_v4_stocks(main_result['items'])
        
        # Создаем маппинг
        stock_mapping = service.create_stock_mapping(main_stocks, analytics_stocks)
        
        print(f"✅ Создан маппинг для {len(stock_mapping)} записей")
        
        # Анализируем маппинг
        main_only = sum(1 for v in stock_mapping.values() if not v["has_analytics_data"])
        analytics_only = sum(1 for v in stock_mapping.values() if v["main_present"] == 0 and v["has_analytics_data"])
        both_sources = sum(1 for v in stock_mapping.values() if v["main_present"] > 0 and v["has_analytics_data"])
        
        print(f"   📈 Только основной API: {main_only}")
        print(f"   📊 Только аналитический API: {analytics_only}")
        print(f"   🔄 Оба источника: {both_sources}")
        
        return True
        
    except Exception as e:
        print(f"❌ Ошибка тестирования: {e}")
        return False
    finally:
        service.close_database_connection()

if __name__ == "__main__":
    success = test_analytics_integration()
    sys.exit(0 if success else 1)