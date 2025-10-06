#!/usr/bin/env python3
"""
Integration тесты для системы синхронизации остатков.

Тестирует полный цикл: API -> валидация -> запись в БД.

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
from unittest.mock import Mock, patch, MagicMock
import sys
import os
from datetime import datetime, date
import json

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

from inventory_sync_service import (
    InventorySyncService, 
    InventoryRecord, 
    SyncResult, 
    SyncStatus
)
from inventory_data_validator import ValidationResult, ValidationSeverity


class TestInventoryIntegration(unittest.TestCase):
    """Integration тесты для системы синхронизации остатков."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.service = InventorySyncService()
        
        # Мокаем подключение к БД
        self.mock_connection = Mock()
        self.mock_cursor = Mock()
        self.mock_cursor.fetchone.return_value = None
        self.mock_cursor.fetchall.return_value = []
        self.mock_cursor.rowcount = 0
        
        self.service.connection = self.mock_connection
        self.service.cursor = self.mock_cursor


class TestOzonIntegrationFlow(TestInventoryIntegration):
    """Тесты полного цикла синхронизации Ozon."""
    
    @patch('inventory_sync_service.requests.post')
    @patch('inventory_sync_service.config')
    def test_ozon_full_sync_flow_success(self, mock_config, mock_post):
        """Тест полного успешного цикла синхронизации Ozon."""
        # Arrange
        mock_config.OZON_API_BASE_URL = 'https://api-seller.ozon.ru'
        mock_config.OZON_CLIENT_ID = 'test_client_id'
        mock_config.OZON_API_KEY = 'test_api_key'
        mock_config.OZON_REQUEST_DELAY = 0.01
        
        # Мокаем успешный ответ API с несколькими товарами
        api_response = {
            'result': {
                'items': [
                    {
                        'offer_id': 'OZON-SKU-001',
                        'stocks': [
                            {
                                'warehouse_name': 'Ozon FBO Moscow',
                                'type': 'FBO',
                                'present': 25,
                                'reserved': 5
                            },
                            {
                                'warehouse_name': 'Ozon FBS Warehouse',
                                'type': 'FBS',
                                'present': 10,
                                'reserved': 2
                            }
                        ]
                    },
                    {
                        'offer_id': 'OZON-SKU-002',
                        'stocks': [
                            {
                                'warehouse_name': 'Ozon FBO Moscow',
                                'type': 'FBO',
                                'present': 50,
                                'reserved': 0
                            }
                        ]
                    }
                ]
            }
        }
        
        mock_response = Mock()
        mock_response.json.return_value = api_response
        mock_response.raise_for_status.return_value = None
        mock_post.return_value = mock_response
        
        # Мокаем поиск товаров в БД
        def mock_product_lookup(sku):
            product_map = {
                'OZON-SKU-001': 101,
                'OZON-SKU-002': 102
            }
            if sku in product_map:
                return {'id': product_map[sku]}
            return None
        
        self.mock_cursor.fetchone.side_effect = lambda: mock_product_lookup(
            self.mock_cursor.execute.call_args[0][1][0]
        )
        
        # Act
        result = self.service.sync_ozon_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Ozon')
        self.assertEqual(result.status, SyncStatus.SUCCESS)
        self.assertEqual(result.records_processed, 2)  # 2 товара обработано
        self.assertGreater(result.records_inserted, 0)  # Записи вставлены
        self.assertEqual(result.records_failed, 0)  # Нет ошибок
        self.assertIsNotNone(result.completed_at)
        
        # Проверяем, что API был вызван
        mock_post.assert_called()
        
        # Проверяем, что данные были записаны в БД
        delete_calls = [call for call in self.mock_cursor.execute.call_args_list 
                       if 'DELETE FROM inventory_data' in str(call)]
        self.assertTrue(len(delete_calls) > 0)
        
        insert_calls = [call for call in self.mock_cursor.executemany.call_args_list 
                       if 'INSERT INTO inventory_data' in str(call)]
        self.assertTrue(len(insert_calls) > 0)
    
    @patch('inventory_sync_service.requests.post')
    @patch('inventory_sync_service.config')
    def test_ozon_sync_with_missing_products(self, mock_config, mock_post):
        """Тест синхронизации Ozon с товарами, отсутствующими в БД."""
        # Arrange
        mock_config.OZON_API_BASE_URL = 'https://api-seller.ozon.ru'
        mock_config.OZON_CLIENT_ID = 'test_client_id'
        mock_config.OZON_API_KEY = 'test_api_key'
        mock_config.OZON_REQUEST_DELAY = 0.01
        
        # API возвращает товары
        api_response = {
            'result': {
                'items': [
                    {
                        'offer_id': 'MISSING-SKU-001',  # Этого товара нет в БД
                        'stocks': [
                            {
                                'warehouse_name': 'Ozon FBO',
                                'type': 'FBO',
                                'present': 10,
                                'reserved': 0
                            }
                        ]
                    },
                    {
                        'offer_id': 'EXISTING-SKU-001',  # Этот товар есть в БД
                        'stocks': [
                            {
                                'warehouse_name': 'Ozon FBO',
                                'type': 'FBO',
                                'present': 20,
                                'reserved': 5
                            }
                        ]
                    }
                ]
            }
        }
        
        mock_response = Mock()
        mock_response.json.return_value = api_response
        mock_response.raise_for_status.return_value = None
        mock_post.return_value = mock_response
        
        # Мокаем поиск товаров: только один найден
        def mock_product_lookup():
            call_args = self.mock_cursor.execute.call_args
            if call_args and len(call_args[0]) > 1:
                sku = call_args[0][1][0]
                if sku == 'EXISTING-SKU-001':
                    return {'id': 201}
            return None
        
        self.mock_cursor.fetchone.side_effect = mock_product_lookup
        
        # Act
        result = self.service.sync_ozon_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Ozon')
        self.assertEqual(result.status, SyncStatus.PARTIAL)  # Частичный успех
        self.assertEqual(result.records_processed, 2)  # 2 товара обработано
        self.assertGreater(result.records_failed, 0)  # Есть ошибки (товар не найден)
    
    @patch('inventory_sync_service.requests.post')
    @patch('inventory_sync_service.config')
    def test_ozon_sync_api_timeout(self, mock_config, mock_post):
        """Тест обработки таймаута API Ozon."""
        # Arrange
        mock_config.OZON_API_BASE_URL = 'https://api-seller.ozon.ru'
        mock_config.OZON_CLIENT_ID = 'test_client_id'
        mock_config.OZON_API_KEY = 'test_api_key'
        
        # Мокаем таймаут
        import requests
        mock_post.side_effect = requests.exceptions.Timeout("Request timeout")
        
        # Act
        result = self.service.sync_ozon_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Ozon')
        self.assertEqual(result.status, SyncStatus.FAILED)
        self.assertIsNotNone(result.error_message)
        self.assertIn("timeout", result.error_message.lower())


class TestWildberriesIntegrationFlow(TestInventoryIntegration):
    """Тесты полного цикла синхронизации Wildberries."""
    
    @patch('inventory_sync_service.requests.get')
    @patch('inventory_sync_service.config')
    def test_wb_full_sync_flow_success(self, mock_config, mock_get):
        """Тест полного успешного цикла синхронизации Wildberries."""
        # Arrange
        mock_config.WB_SUPPLIERS_API_URL = 'https://suppliers-api.wildberries.ru'
        mock_config.WB_API_TOKEN = 'test_wb_token'
        mock_config.WB_REQUEST_DELAY = 0.01
        
        # Мокаем успешный ответ API
        api_response = [
            {
                'barcode': '1234567890123',
                'nmId': 11111,
                'warehouseName': 'WB Подольск',
                'quantity': 30,
                'inWayToClient': 5,
                'inWayFromClient': 2,
                'quantityFull': 37
            },
            {
                'barcode': '9876543210987',
                'nmId': 22222,
                'warehouseName': 'WB Электросталь',
                'quantity': 15,
                'inWayToClient': 0,
                'inWayFromClient': 1,
                'quantityFull': 16
            }
        ]
        
        mock_response = Mock()
        mock_response.json.return_value = api_response
        mock_response.raise_for_status.return_value = None
        mock_get.return_value = mock_response
        
        # Мокаем поиск товаров в БД
        def mock_product_lookup():
            call_args = self.mock_cursor.execute.call_args
            if call_args and len(call_args[0]) > 1:
                identifier = call_args[0][1][0]
                if identifier in ['1234567890123', '11111']:
                    return {'id': 301}
                elif identifier in ['9876543210987', '22222']:
                    return {'id': 302}
            return None
        
        self.mock_cursor.fetchone.side_effect = mock_product_lookup
        
        # Act
        result = self.service.sync_wb_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Wildberries')
        self.assertEqual(result.status, SyncStatus.SUCCESS)
        self.assertEqual(result.records_processed, 2)
        self.assertGreater(result.records_inserted, 0)
        self.assertEqual(result.records_failed, 0)
        
        # Проверяем, что API был вызван
        mock_get.assert_called()
        call_args = mock_get.call_args
        self.assertEqual(call_args[1]['headers']['Authorization'], 'test_wb_token')
    
    @patch('inventory_sync_service.requests.get')
    @patch('inventory_sync_service.config')
    def test_wb_sync_with_validation_errors(self, mock_config, mock_get):
        """Тест синхронизации WB с ошибками валидации."""
        # Arrange
        mock_config.WB_SUPPLIERS_API_URL = 'https://suppliers-api.wildberries.ru'
        mock_config.WB_API_TOKEN = 'test_wb_token'
        mock_config.WB_REQUEST_DELAY = 0.01
        
        # API возвращает данные с проблемами
        api_response = [
            {
                'barcode': '',  # Пустой штрихкод
                'nmId': 33333,
                'warehouseName': 'WB Warehouse',
                'quantity': -10,  # Отрицательное количество
                'inWayToClient': 0,
                'inWayFromClient': 0,
                'quantityFull': -10
            },
            {
                'barcode': '1111111111111',
                'nmId': 44444,
                'warehouseName': 'WB Warehouse',
                'quantity': 25,
                'inWayToClient': 3,
                'inWayFromClient': 1,
                'quantityFull': 29
            }
        ]
        
        mock_response = Mock()
        mock_response.json.return_value = api_response
        mock_response.raise_for_status.return_value = None
        mock_get.return_value = mock_response
        
        # Мокаем поиск товаров
        def mock_product_lookup():
            call_args = self.mock_cursor.execute.call_args
            if call_args and len(call_args[0]) > 1:
                identifier = call_args[0][1][0]
                if identifier in ['1111111111111', '44444']:
                    return {'id': 401}
            return None
        
        self.mock_cursor.fetchone.side_effect = mock_product_lookup
        
        # Act
        result = self.service.sync_wb_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Wildberries')
        # Может быть PARTIAL или SUCCESS в зависимости от того, как обрабатываются ошибки
        self.assertIn(result.status, [SyncStatus.SUCCESS, SyncStatus.PARTIAL])
        self.assertEqual(result.records_processed, 2)


class TestFullSyncIntegration(TestInventoryIntegration):
    """Тесты полной синхронизации всех маркетплейсов."""
    
    @patch('inventory_sync_service.InventorySyncService.sync_wb_inventory')
    @patch('inventory_sync_service.InventorySyncService.sync_ozon_inventory')
    @patch('inventory_sync_service.InventorySyncService.connect_to_database')
    @patch('inventory_sync_service.InventorySyncService.close_database_connection')
    def test_run_full_sync_success(self, mock_close_db, mock_connect_db, 
                                  mock_sync_ozon, mock_sync_wb):
        """Тест успешной полной синхронизации."""
        # Arrange
        ozon_result = SyncResult(
            source='Ozon',
            status=SyncStatus.SUCCESS,
            records_processed=50,
            records_updated=0,
            records_inserted=48,
            records_failed=2,
            started_at=datetime.now(),
            completed_at=datetime.now(),
            api_requests_count=5
        )
        
        wb_result = SyncResult(
            source='Wildberries',
            status=SyncStatus.SUCCESS,
            records_processed=30,
            records_updated=0,
            records_inserted=30,
            records_failed=0,
            started_at=datetime.now(),
            completed_at=datetime.now(),
            api_requests_count=3
        )
        
        mock_sync_ozon.return_value = ozon_result
        mock_sync_wb.return_value = wb_result
        
        # Act
        results = self.service.run_full_sync()
        
        # Assert
        self.assertEqual(len(results), 2)
        self.assertIn('Ozon', results)
        self.assertIn('Wildberries', results)
        
        self.assertEqual(results['Ozon'].status, SyncStatus.SUCCESS)
        self.assertEqual(results['Wildberries'].status, SyncStatus.SUCCESS)
        
        # Проверяем, что методы были вызваны
        mock_connect_db.assert_called_once()
        mock_sync_ozon.assert_called_once()
        mock_sync_wb.assert_called_once()
        mock_close_db.assert_called_once()
    
    @patch('inventory_sync_service.InventorySyncService.sync_wb_inventory')
    @patch('inventory_sync_service.InventorySyncService.sync_ozon_inventory')
    @patch('inventory_sync_service.InventorySyncService.connect_to_database')
    @patch('inventory_sync_service.InventorySyncService.close_database_connection')
    def test_run_full_sync_with_failures(self, mock_close_db, mock_connect_db,
                                        mock_sync_ozon, mock_sync_wb):
        """Тест полной синхронизации с ошибками."""
        # Arrange
        ozon_result = SyncResult(
            source='Ozon',
            status=SyncStatus.FAILED,
            records_processed=0,
            records_updated=0,
            records_inserted=0,
            records_failed=0,
            started_at=datetime.now(),
            completed_at=datetime.now(),
            error_message="API connection failed"
        )
        
        wb_result = SyncResult(
            source='Wildberries',
            status=SyncStatus.PARTIAL,
            records_processed=20,
            records_updated=0,
            records_inserted=15,
            records_failed=5,
            started_at=datetime.now(),
            completed_at=datetime.now()
        )
        
        mock_sync_ozon.return_value = ozon_result
        mock_sync_wb.return_value = wb_result
        
        # Act
        results = self.service.run_full_sync()
        
        # Assert
        self.assertEqual(len(results), 2)
        self.assertEqual(results['Ozon'].status, SyncStatus.FAILED)
        self.assertEqual(results['Wildberries'].status, SyncStatus.PARTIAL)
        
        # Проверяем, что ошибка записана
        self.assertIsNotNone(results['Ozon'].error_message)


class TestDataFreshnessIntegration(TestInventoryIntegration):
    """Тесты проверки свежести данных."""
    
    def test_check_data_freshness_with_fresh_data(self):
        """Тест проверки свежести актуальных данных."""
        # Arrange
        fresh_time = datetime.now()
        self.mock_cursor.fetchall.return_value = [
            {
                'source': 'Ozon',
                'last_update': fresh_time,
                'unique_products': 150,
                'total_present': 5000,
                'total_reserved': 500
            },
            {
                'source': 'Wildberries',
                'last_update': fresh_time,
                'unique_products': 200,
                'total_present': 8000,
                'total_reserved': 800
            }
        ]
        
        # Act
        result = self.service.check_data_freshness()
        
        # Assert
        self.assertIn('check_time', result)
        self.assertIn('sources', result)
        self.assertIn('Ozon', result['sources'])
        self.assertIn('Wildberries', result['sources'])
        
        # Проверяем, что данные считаются свежими
        self.assertTrue(result['sources']['Ozon']['is_fresh'])
        self.assertTrue(result['sources']['Wildberries']['is_fresh'])
    
    def test_check_data_freshness_with_stale_data(self):
        """Тест проверки свежести устаревших данных."""
        # Arrange
        from datetime import timedelta
        stale_time = datetime.now() - timedelta(hours=10)  # 10 часов назад
        
        self.mock_cursor.fetchall.return_value = [
            {
                'source': 'Ozon',
                'last_update': stale_time,
                'unique_products': 150,
                'total_present': 5000,
                'total_reserved': 500
            }
        ]
        
        # Act
        result = self.service.check_data_freshness()
        
        # Assert
        self.assertIn('Ozon', result['sources'])
        self.assertFalse(result['sources']['Ozon']['is_fresh'])
        self.assertGreater(result['sources']['Ozon']['age_hours'], 6)


class TestStatisticsIntegration(TestInventoryIntegration):
    """Тесты получения статистики остатков."""
    
    def test_get_inventory_statistics_success(self):
        """Тест успешного получения статистики остатков."""
        # Arrange
        self.mock_cursor.fetchall.return_value = [
            {
                'source': 'Ozon',
                'warehouse_name': 'Ozon FBO Moscow',
                'stock_type': 'FBO',
                'total_records': 100,
                'unique_products': 95,
                'total_present': 2500,
                'total_reserved': 250,
                'total_available': 2250,
                'last_sync': datetime.now()
            },
            {
                'source': 'Ozon',
                'warehouse_name': 'Ozon FBS Warehouse',
                'stock_type': 'FBS',
                'total_records': 50,
                'unique_products': 48,
                'total_present': 1200,
                'total_reserved': 100,
                'total_available': 1100,
                'last_sync': datetime.now()
            },
            {
                'source': 'Wildberries',
                'warehouse_name': 'WB Подольск',
                'stock_type': 'FBS',
                'total_records': 200,
                'unique_products': 190,
                'total_present': 5000,
                'total_reserved': 400,
                'total_available': 4600,
                'last_sync': datetime.now()
            }
        ]
        
        # Act
        result = self.service.get_inventory_statistics()
        
        # Assert
        self.assertIn('Ozon', result)
        self.assertIn('Wildberries', result)
        
        # Проверяем структуру данных Ozon
        ozon_stats = result['Ozon']
        self.assertIn('warehouses', ozon_stats)
        self.assertIn('totals', ozon_stats)
        
        # Проверяем, что есть данные по складам
        self.assertIn('Ozon FBO Moscow (FBO)', ozon_stats['warehouses'])
        self.assertIn('Ozon FBS Warehouse (FBS)', ozon_stats['warehouses'])
        
        # Проверяем итоговые данные
        self.assertEqual(ozon_stats['totals']['records'], 150)  # 100 + 50
        self.assertEqual(ozon_stats['totals']['present'], 3700)  # 2500 + 1200


if __name__ == '__main__':
    # Настройка логирования для тестов
    import logging
    logging.basicConfig(level=logging.WARNING)
    
    # Запуск тестов
    unittest.main(verbosity=2)