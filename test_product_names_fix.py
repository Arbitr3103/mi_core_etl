#!/usr/bin/env python3
"""
Тесты для проверки исправлений отображения названий товаров
"""

import unittest
import sys
import os
import mysql.connector
from unittest.mock import Mock, patch, MagicMock
import requests

# Добавляем путь к модулям
sys.path.append(os.path.dirname(__file__))

from product_name_resolver import ProductNameResolver
from inventory_sync_service_with_names import InventorySyncServiceWithNames

class TestProductNameResolver(unittest.TestCase):
    """Тесты для ProductNameResolver"""
    
    def setUp(self):
        """Настройка тестов"""
        self.resolver = ProductNameResolver()
        # Мокаем подключение к БД для тестов
        self.resolver.db_connection = Mock()
    
    def tearDown(self):
        """Очистка после тестов"""
        if hasattr(self.resolver, 'close'):
            self.resolver.close()
    
    @patch('requests.Session.post')
    def test_get_product_name_by_sku_success(self, mock_post):
        """Тест успешного получения названия по SKU"""
        # Мокаем ответ API
        mock_response = Mock()
        mock_response.raise_for_status.return_value = None
        mock_response.json.return_value = {
            'result': {
                'items': [{
                    'id': 12345,
                    'name': 'Тестовый товар',
                    'offer_id': '257202054'
                }]
            }
        }
        mock_post.return_value = mock_response
        
        # Мокаем методы БД
        self.resolver._get_name_from_db = Mock(return_value=None)
        self.resolver._save_name_to_db = Mock()
        
        # Тестируем
        result = self.resolver.get_product_name_by_sku('257202054')
        
        self.assertEqual(result, 'Тестовый товар')
        self.resolver._save_name_to_db.assert_called_once()
    
    @patch('requests.Session.post')
    def test_get_product_name_by_sku_not_found(self, mock_post):
        """Тест случая, когда товар не найден"""
        # Мокаем пустой ответ API
        mock_response = Mock()
        mock_response.raise_for_status.return_value = None
        mock_response.json.return_value = {
            'result': {
                'items': []
            }
        }
        mock_post.return_value = mock_response
        
        # Мокаем методы БД
        self.resolver._get_name_from_db = Mock(return_value=None)
        
        # Тестируем
        result = self.resolver.get_product_name_by_sku('999999999')
        
        self.assertIsNone(result)
    
    def test_batch_resolve_names(self):
        """Тест пакетного получения названий"""
        # Мокаем методы
        self.resolver._get_name_from_db = Mock(side_effect=lambda sku: {
            '257202054': 'Товар 1',
            '161875896': None,
            '161875313': None
        }.get(sku))
        
        self.resolver._batch_api_request = Mock(return_value={
            '161875896': 'Товар 2',
            '161875313': 'Товар 3'
        })
        
        # Тестируем
        skus = ['257202054', '161875896', '161875313']
        result = self.resolver.batch_resolve_names(skus)
        
        expected = {
            '257202054': 'Товар 1',
            '161875896': 'Товар 2', 
            '161875313': 'Товар 3'
        }
        
        self.assertEqual(result, expected)
    
    def test_resolve_names_for_analytics_data(self):
        """Тест обогащения аналитических данных"""
        # Подготавливаем тестовые данные
        analytics_records = [
            {'sku': '257202054', 'warehouse_name': 'Склад 1', 'present': 10},
            {'sku': '161875896', 'warehouse_name': 'Склад 2', 'present': 5},
            {'sku': 'TEXT_SKU', 'warehouse_name': 'Склад 3', 'present': 3}
        ]
        
        # Мокаем получение названий
        self.resolver.batch_resolve_names = Mock(return_value={
            '257202054': 'Товар артикул 257202054',
            '161875896': 'Товар артикул 161875896'
            # TEXT_SKU не найден
        })
        
        # Тестируем
        result = self.resolver.resolve_names_for_analytics_data(analytics_records)
        
        # Проверяем результат
        self.assertEqual(len(result), 3)
        self.assertEqual(result[0]['product_name'], 'Товар артикул 257202054')
        self.assertEqual(result[1]['product_name'], 'Товар артикул 161875896')
        self.assertEqual(result[2]['product_name'], 'TEXT_SKU')  # Fallback


class TestInventorySyncServiceWithNames(unittest.TestCase):
    """Тесты для InventorySyncServiceWithNames"""
    
    def setUp(self):
        """Настройка тестов"""
        # Мокаем все внешние зависимости
        with patch('inventory_sync_service_with_names.connect_to_db'), \
             patch('inventory_sync_service_with_names.InventoryDataValidator'), \
             patch('inventory_sync_service_with_names.SyncLogger'), \
             patch('inventory_sync_service_with_names.ProductNameResolver'):
            
            self.service = InventorySyncServiceWithNames()
            self.service.connection = Mock()
            self.service.validator = Mock()
            self.service.sync_logger = Mock()
            self.service.name_resolver = Mock()
    
    def test_enrich_analytics_with_names(self):
        """Тест обогащения аналитических данных названиями"""
        # Подготавливаем тестовые данные
        analytics_stocks = [
            {'sku': '257202054', 'present': 10, 'reserved': 2},
            {'sku': '161875896', 'present': 5, 'reserved': 1}
        ]
        
        # Мокаем сервис получения названий
        self.service.name_resolver.batch_resolve_names.return_value = {
            '257202054': 'Тестовый товар 1',
            '161875896': 'Тестовый товар 2'
        }
        
        # Тестируем
        result = self.service._enrich_analytics_with_names(analytics_stocks)
        
        # Проверяем результат
        self.assertEqual(len(result), 2)
        self.assertEqual(result[0]['product_name'], 'Тестовый товар 1')
        self.assertEqual(result[1]['product_name'], 'Тестовый товар 2')
        self.assertEqual(result[0]['sku'], '257202054')
        self.assertEqual(result[1]['sku'], '161875896')
    
    def test_merge_ozon_data(self):
        """Тест объединения данных из разных API"""
        # Подготавливаем тестовые данные
        main_stocks = [
            {
                'product_id': 12345,
                'sku': 'MAIN_SKU',
                'warehouse_name': 'Main Warehouse',
                'present': 100,
                'reserved': 10,
                'stock_type': 'FBO'
            }
        ]
        
        analytics_stocks = [
            {
                'sku': '257202054',
                'warehouse_name': 'Analytics Warehouse',
                'present': 50,
                'reserved': 5,
                'product_name': 'Аналитический товар'
            }
        ]
        
        # Тестируем
        result = self.service._merge_ozon_data(main_stocks, analytics_stocks)
        
        # Проверяем результат
        self.assertEqual(len(result), 2)
        
        # Проверяем основной товар
        main_record = result[0]
        self.assertEqual(main_record.product_id, 12345)
        self.assertEqual(main_record.sku, 'MAIN_SKU')
        self.assertEqual(main_record.source, 'Ozon')
        self.assertEqual(main_record.current_stock, 100)
        
        # Проверяем аналитический товар
        analytics_record = result[1]
        self.assertEqual(analytics_record.product_id, 0)
        self.assertEqual(analytics_record.sku, '257202054')
        self.assertEqual(analytics_record.source, 'Ozon_Analytics')
        self.assertEqual(analytics_record.current_stock, 50)
        self.assertEqual(analytics_record.product_name, 'Аналитический товар')


class TestDatabaseIntegration(unittest.TestCase):
    """Интеграционные тесты с БД"""
    
    def setUp(self):
        """Настройка тестов"""
        try:
            from config_local import DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
            self.connection = mysql.connector.connect(
                host=DB_HOST,
                database=DB_NAME,
                user=DB_USER,
                password=DB_PASSWORD,
                charset='utf8mb4'
            )
        except Exception as e:
            self.skipTest(f"Нет подключения к БД: {e}")
    
    def tearDown(self):
        """Очистка после тестов"""
        if hasattr(self, 'connection') and self.connection:
            self.connection.close()
    
    def test_improved_join_logic(self):
        """Тест улучшенной логики JOIN"""
        cursor = self.connection.cursor()
        
        # Тестируем новую логику JOIN
        query = """
            SELECT 
                i.product_id,
                i.sku,
                i.source,
                p.product_name,
                COALESCE(p.product_name, 
                    CASE 
                        WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                        ELSE i.sku
                    END
                ) as display_name
            FROM inventory_data i
            LEFT JOIN product_names p ON (
                (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                (i.product_id = 0 AND i.sku = p.sku)
            )
            WHERE i.current_stock > 0
            LIMIT 10
        """
        
        cursor.execute(query)
        results = cursor.fetchall()
        
        # Проверяем, что запрос выполняется без ошибок
        self.assertIsNotNone(results)
        
        # Проверяем, что у всех записей есть display_name
        for row in results:
            product_id, sku, source, product_name, display_name = row
            self.assertIsNotNone(display_name)
            self.assertNotEqual(display_name, '')
            
            # Если это числовой SKU без названия, должен быть fallback
            if product_name is None and sku.isdigit():
                self.assertTrue(display_name.startswith('Товар артикул'))
        
        cursor.close()
    
    def test_product_names_coverage(self):
        """Тест покрытия товаров названиями"""
        cursor = self.connection.cursor()
        
        # Проверяем статистику покрытия названиями
        query = """
            SELECT 
                COUNT(*) as total_items,
                COUNT(p.product_name) as with_names,
                COUNT(*) - COUNT(p.product_name) as without_names,
                ROUND((COUNT(p.product_name) / COUNT(*)) * 100, 2) as coverage_percent
            FROM inventory_data i
            LEFT JOIN product_names p ON (
                (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                (i.product_id = 0 AND i.sku = p.sku)
            )
            WHERE i.current_stock > 0
        """
        
        cursor.execute(query)
        stats = cursor.fetchone()
        
        total_items, with_names, without_names, coverage_percent = stats
        
        print(f"\n=== СТАТИСТИКА ПОКРЫТИЯ НАЗВАНИЯМИ ===")
        print(f"Всего товаров с остатками: {total_items}")
        print(f"С названиями: {with_names}")
        print(f"Без названий: {without_names}")
        print(f"Покрытие: {coverage_percent}%")
        
        # Проверяем, что есть товары с остатками
        self.assertGreater(total_items, 0)
        
        cursor.close()


def run_performance_test():
    """Тест производительности новых запросов"""
    import time
    
    try:
        from config_local import DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
        connection = mysql.connector.connect(
            host=DB_HOST,
            database=DB_NAME,
            user=DB_USER,
            password=DB_PASSWORD,
            charset='utf8mb4'
        )
        
        cursor = connection.cursor()
        
        # Тестируем производительность нового JOIN
        start_time = time.time()
        
        query = """
            SELECT 
                i.product_id,
                i.sku,
                COALESCE(p.product_name, 
                    CASE 
                        WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                        ELSE i.sku
                    END
                ) as display_name,
                i.current_stock
            FROM inventory_data i
            LEFT JOIN product_names p ON (
                (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                (i.product_id = 0 AND i.sku = p.sku)
            )
            WHERE i.current_stock > 0
            ORDER BY i.current_stock DESC
        """
        
        cursor.execute(query)
        results = cursor.fetchall()
        
        end_time = time.time()
        execution_time = end_time - start_time
        
        print(f"\n=== ТЕСТ ПРОИЗВОДИТЕЛЬНОСТИ ===")
        print(f"Запрос выполнен за: {execution_time:.3f} секунд")
        print(f"Обработано записей: {len(results)}")
        print(f"Скорость: {len(results)/execution_time:.1f} записей/сек")
        
        cursor.close()
        connection.close()
        
    except Exception as e:
        print(f"Ошибка теста производительности: {e}")


if __name__ == '__main__':
    # Запускаем unit тесты
    print("Запуск unit тестов...")
    unittest.main(argv=[''], exit=False, verbosity=2)
    
    # Запускаем тест производительности
    print("\nЗапуск теста производительности...")
    run_performance_test()