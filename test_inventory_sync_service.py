#!/usr/bin/env python3
"""
Unit тесты для InventorySyncService.

Тестирует методы получения данных с API, валидацию данных и запись в БД.

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
from unittest.mock import Mock, patch, MagicMock
import sys
import os
from datetime import datetime, date
from typing import List, Dict, Any

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

from inventory_sync_service import (
    InventorySyncService, 
    InventoryRecord, 
    SyncResult, 
    SyncStatus,
    StockType
)
from inventory_data_validator import ValidationResult, ValidationIssue, ValidationSeverity


class TestInventorySyncService(unittest.TestCase):
    """Тесты для InventorySyncService."""
    
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
        
        # Тестовые данные
        self.sample_inventory_record = InventoryRecord(
            product_id=1,
            sku='TEST-SKU-001',
            source='Ozon',
            warehouse_name='Ozon FBO',
            stock_type='FBO',
            current_stock=10,
            reserved_stock=2,
            available_stock=8,
            quantity_present=10,
            quantity_reserved=2,
            snapshot_date=date.today()
        )


class TestDatabaseMethods(TestInventorySyncService):
    """Тесты методов работы с базой данных."""
    
    def test_get_product_id_by_ozon_sku_success(self):
        """Тест успешного поиска товара по Ozon SKU."""
        # Arrange
        test_sku = 'TEST-SKU-001'
        expected_id = 123
        self.mock_cursor.fetchone.return_value = {'id': expected_id}
        
        # Act
        result = self.service.get_product_id_by_ozon_sku(test_sku)
        
        # Assert
        self.assertEqual(result, expected_id)
        self.mock_cursor.execute.assert_called_once_with(
            "SELECT id FROM dim_products WHERE sku_ozon = %s",
            (test_sku,)
        )
    
    def test_get_product_id_by_ozon_sku_not_found(self):
        """Тест поиска несуществующего товара по Ozon SKU."""
        # Arrange
        test_sku = 'NONEXISTENT-SKU'
        self.mock_cursor.fetchone.return_value = None
        
        # Act
        result = self.service.get_product_id_by_ozon_sku(test_sku)
        
        # Assert
        self.assertIsNone(result)
    
    def test_get_product_id_by_ozon_sku_empty_sku(self):
        """Тест поиска с пустым SKU."""
        # Act
        result = self.service.get_product_id_by_ozon_sku('')
        
        # Assert
        self.assertIsNone(result)
        self.mock_cursor.execute.assert_not_called()
    
    def test_get_product_id_by_wb_sku_success(self):
        """Тест успешного поиска товара по WB SKU."""
        # Arrange
        test_sku = '12345'
        expected_id = 456
        self.mock_cursor.fetchone.return_value = {'id': expected_id}
        
        # Act
        result = self.service.get_product_id_by_wb_sku(test_sku)
        
        # Assert
        self.assertEqual(result, expected_id)
        self.mock_cursor.execute.assert_called_once_with(
            "SELECT id FROM dim_products WHERE sku_wb = %s",
            (test_sku,)
        )
    
    def test_get_product_id_by_barcode_success(self):
        """Тест успешного поиска товара по штрихкоду."""
        # Arrange
        test_barcode = '1234567890123'
        expected_id = 789
        self.mock_cursor.fetchone.return_value = {'id': expected_id}
        
        # Act
        result = self.service.get_product_id_by_barcode(test_barcode)
        
        # Assert
        self.assertEqual(result, expected_id)
        self.mock_cursor.execute.assert_called_once_with(
            "SELECT id FROM dim_products WHERE barcode = %s",
            (test_barcode,)
        )
    
    def test_update_inventory_data_success(self):
        """Тест успешного обновления данных в inventory_data."""
        # Arrange
        test_records = [self.sample_inventory_record]
        source = 'Ozon'
        
        # Act
        updated, inserted, failed = self.service.update_inventory_data(test_records, source)
        
        # Assert
        self.assertEqual(updated, 0)  # Мы используем DELETE + INSERT, не UPDATE
        self.assertEqual(inserted, 1)
        self.assertEqual(failed, 0)
        
        # Проверяем, что был вызван DELETE
        delete_calls = [call for call in self.mock_cursor.execute.call_args_list 
                       if 'DELETE FROM inventory_data' in str(call)]
        self.assertTrue(len(delete_calls) > 0)
        
        # Проверяем, что был вызван INSERT
        insert_calls = [call for call in self.mock_cursor.executemany.call_args_list 
                       if 'INSERT INTO inventory_data' in str(call)]
        self.assertTrue(len(insert_calls) > 0)
    
    def test_update_inventory_data_empty_records(self):
        """Тест обновления с пустым списком записей."""
        # Act
        updated, inserted, failed = self.service.update_inventory_data([], 'Ozon')
        
        # Assert
        self.assertEqual(updated, 0)
        self.assertEqual(inserted, 0)
        self.assertEqual(failed, 0)
    
    def test_log_sync_result_success(self):
        """Тест записи результата синхронизации в sync_logs."""
        # Arrange
        sync_result = SyncResult(
            source='Ozon',
            status=SyncStatus.SUCCESS,
            records_processed=10,
            records_updated=0,
            records_inserted=8,
            records_failed=2,
            started_at=datetime.now(),
            completed_at=datetime.now(),
            api_requests_count=3
        )
        
        # Act
        self.service.log_sync_result(sync_result)
        
        # Assert
        self.mock_cursor.execute.assert_called_once()
        call_args = self.mock_cursor.execute.call_args
        self.assertIn('INSERT INTO sync_logs', call_args[0][0])
        self.assertEqual(call_args[0][1][0], 'Ozon')  # source
        self.assertEqual(call_args[0][1][1], 'success')  # status
    
    def test_get_last_sync_time_success(self):
        """Тест получения времени последней синхронизации."""
        # Arrange
        expected_time = datetime.now()
        self.mock_cursor.fetchone.return_value = {'last_sync': expected_time}
        
        # Act
        result = self.service.get_last_sync_time('Ozon')
        
        # Assert
        self.assertEqual(result, expected_time)
        self.mock_cursor.execute.assert_called_once()
        call_args = self.mock_cursor.execute.call_args
        self.assertIn('SELECT MAX(completed_at)', call_args[0][0])
    
    def test_get_last_sync_time_no_records(self):
        """Тест получения времени синхронизации при отсутствии записей."""
        # Arrange
        self.mock_cursor.fetchone.return_value = {'last_sync': None}
        
        # Act
        result = self.service.get_last_sync_time('Ozon')
        
        # Assert
        self.assertIsNone(result)


class TestAPIDataRetrieval(TestInventorySyncService):
    """Тесты методов получения данных с API."""
    
    @patch('inventory_sync_service.requests.post')
    @patch('inventory_sync_service.config')
    def test_sync_ozon_inventory_success(self, mock_config, mock_post):
        """Тест успешной синхронизации остатков Ozon."""
        # Arrange
        mock_config.OZON_API_BASE_URL = 'https://api-seller.ozon.ru'
        mock_config.OZON_CLIENT_ID = 'test_client_id'
        mock_config.OZON_API_KEY = 'test_api_key'
        mock_config.OZON_REQUEST_DELAY = 0.1
        
        # Мокаем ответ API
        mock_response = Mock()
        mock_response.json.return_value = {
            'result': {
                'items': [
                    {
                        'offer_id': 'TEST-SKU-001',
                        'stocks': [
                            {
                                'warehouse_name': 'Ozon FBO',
                                'type': 'FBO',
                                'present': 10,
                                'reserved': 2
                            }
                        ]
                    }
                ]
            }
        }
        mock_response.raise_for_status.return_value = None
        mock_post.return_value = mock_response
        
        # Мокаем поиск товара в БД
        self.mock_cursor.fetchone.return_value = {'id': 123}
        
        # Мокаем валидацию
        with patch.object(self.service, 'validate_inventory_data') as mock_validate:
            mock_validate.return_value = ValidationResult(
                is_valid=True,
                total_records=1,
                valid_records=1,
                issues=[]
            )
            
            with patch.object(self.service, 'check_data_anomalies') as mock_anomalies:
                mock_anomalies.return_value = {'anomalies': []}
                
                with patch.object(self.service, 'filter_valid_records') as mock_filter:
                    mock_filter.return_value = [self.sample_inventory_record]
                    
                    # Act
                    result = self.service.sync_ozon_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Ozon')
        self.assertEqual(result.status, SyncStatus.SUCCESS)
        self.assertEqual(result.records_processed, 1)
        self.assertIsNotNone(result.completed_at)
        
        # Проверяем, что API был вызван
        mock_post.assert_called()
        call_args = mock_post.call_args
        self.assertEqual(call_args[1]['headers']['Client-Id'], 'test_client_id')
        self.assertEqual(call_args[1]['headers']['Api-Key'], 'test_api_key')
    
    @patch('inventory_sync_service.requests.post')
    @patch('inventory_sync_service.config')
    def test_sync_ozon_inventory_api_error(self, mock_config, mock_post):
        """Тест обработки ошибки API Ozon."""
        # Arrange
        mock_config.OZON_API_BASE_URL = 'https://api-seller.ozon.ru'
        mock_config.OZON_CLIENT_ID = 'test_client_id'
        mock_config.OZON_API_KEY = 'test_api_key'
        
        # Мокаем ошибку API
        mock_post.side_effect = Exception("API Error")
        
        # Act
        result = self.service.sync_ozon_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Ozon')
        self.assertEqual(result.status, SyncStatus.FAILED)
        self.assertIsNotNone(result.error_message)
        self.assertIn("API Error", result.error_message)
    
    @patch('inventory_sync_service.requests.get')
    @patch('inventory_sync_service.config')
    def test_sync_wb_inventory_success(self, mock_config, mock_get):
        """Тест успешной синхронизации остатков Wildberries."""
        # Arrange
        mock_config.WB_SUPPLIERS_API_URL = 'https://suppliers-api.wildberries.ru'
        mock_config.WB_API_TOKEN = 'test_token'
        mock_config.WB_REQUEST_DELAY = 0.1
        
        # Мокаем ответ API
        mock_response = Mock()
        mock_response.json.return_value = [
            {
                'barcode': '1234567890123',
                'nmId': 12345,
                'warehouseName': 'WB Main',
                'quantity': 15,
                'inWayToClient': 2,
                'inWayFromClient': 1,
                'quantityFull': 18
            }
        ]
        mock_response.raise_for_status.return_value = None
        mock_get.return_value = mock_response
        
        # Мокаем поиск товара в БД
        self.mock_cursor.fetchone.return_value = {'id': 456}
        
        # Мокаем валидацию
        with patch.object(self.service, 'validate_inventory_data') as mock_validate:
            mock_validate.return_value = ValidationResult(
                is_valid=True,
                total_records=1,
                valid_records=1,
                issues=[]
            )
            
            with patch.object(self.service, 'check_data_anomalies') as mock_anomalies:
                mock_anomalies.return_value = {'anomalies': []}
                
                with patch.object(self.service, 'filter_valid_records') as mock_filter:
                    mock_filter.return_value = [self.sample_inventory_record]
                    
                    # Act
                    result = self.service.sync_wb_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Wildberries')
        self.assertEqual(result.status, SyncStatus.SUCCESS)
        self.assertEqual(result.records_processed, 1)
        
        # Проверяем, что API был вызван
        mock_get.assert_called()
        call_args = mock_get.call_args
        self.assertEqual(call_args[1]['headers']['Authorization'], 'test_token')
    
    @patch('inventory_sync_service.requests.get')
    @patch('inventory_sync_service.config')
    def test_sync_wb_inventory_empty_response(self, mock_config, mock_get):
        """Тест обработки пустого ответа от WB API."""
        # Arrange
        mock_config.WB_SUPPLIERS_API_URL = 'https://suppliers-api.wildberries.ru'
        mock_config.WB_API_TOKEN = 'test_token'
        
        # Мокаем пустой ответ API
        mock_response = Mock()
        mock_response.json.return_value = []
        mock_response.raise_for_status.return_value = None
        mock_get.return_value = mock_response
        
        # Act
        result = self.service.sync_wb_inventory()
        
        # Assert
        self.assertEqual(result.source, 'Wildberries')
        self.assertEqual(result.records_processed, 0)
        # Статус может быть SUCCESS или PARTIAL в зависимости от логики
        self.assertIn(result.status, [SyncStatus.SUCCESS, SyncStatus.PARTIAL])


class TestDataValidation(TestInventorySyncService):
    """Тесты методов валидации данных."""
    
    def test_validate_inventory_data_success(self):
        """Тест успешной валидации данных."""
        # Arrange
        test_records = [self.sample_inventory_record]
        
        # Мокаем валидатор
        with patch.object(self.service.validator, 'validate_inventory_records') as mock_validate:
            mock_validate.return_value = ValidationResult(
                is_valid=True,
                total_records=1,
                valid_records=1,
                issues=[]
            )
            
            with patch.object(self.service.validator, 'validate_product_existence') as mock_existence:
                mock_existence.return_value = ValidationResult(
                    is_valid=True,
                    total_records=1,
                    valid_records=1,
                    issues=[]
                )
                
                # Act
                result = self.service.validate_inventory_data(test_records, 'Ozon')
        
        # Assert
        self.assertTrue(result.is_valid)
        self.assertEqual(result.total_records, 1)
        self.assertEqual(result.valid_records, 1)
        self.assertEqual(len(result.issues), 0)
    
    def test_validate_inventory_data_with_errors(self):
        """Тест валидации данных с ошибками."""
        # Arrange
        test_records = [self.sample_inventory_record]
        
        # Мокаем валидатор с ошибками
        validation_issues = [
            ValidationIssue(
                severity=ValidationSeverity.ERROR,
                field='product_id',
                message='Product not found',
                record_id='Ozon_0'
            )
        ]
        
        with patch.object(self.service.validator, 'validate_inventory_records') as mock_validate:
            mock_validate.return_value = ValidationResult(
                is_valid=False,
                total_records=1,
                valid_records=0,
                issues=validation_issues
            )
            
            # Act
            result = self.service.validate_inventory_data(test_records, 'Ozon')
        
        # Assert
        self.assertFalse(result.is_valid)
        self.assertEqual(len(result.issues), 1)
        self.assertEqual(result.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_filter_valid_records_all_valid(self):
        """Тест фильтрации когда все записи валидны."""
        # Arrange
        test_records = [self.sample_inventory_record]
        validation_result = ValidationResult(
            is_valid=True,
            total_records=1,
            valid_records=1,
            issues=[]
        )
        
        # Act
        result = self.service.filter_valid_records(test_records, validation_result)
        
        # Assert
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0], self.sample_inventory_record)
    
    def test_filter_valid_records_with_errors(self):
        """Тест фильтрации записей с ошибками."""
        # Arrange
        test_records = [self.sample_inventory_record]
        validation_issues = [
            ValidationIssue(
                severity=ValidationSeverity.ERROR,
                field='product_id',
                message='Product not found',
                record_id='Ozon_0'
            )
        ]
        validation_result = ValidationResult(
            is_valid=False,
            total_records=1,
            valid_records=0,
            issues=validation_issues
        )
        
        # Act
        result = self.service.filter_valid_records(test_records, validation_result)
        
        # Assert
        self.assertEqual(len(result), 0)  # Все записи отфильтрованы из-за ошибок


class TestDataAnomalies(TestInventorySyncService):
    """Тесты проверки аномалий в данных."""
    
    def test_check_data_anomalies_normal_data(self):
        """Тест проверки аномалий в нормальных данных."""
        # Arrange
        test_records = [self.sample_inventory_record]
        
        # Act
        result = self.service.check_data_anomalies(test_records, 'Ozon')
        
        # Assert
        self.assertEqual(result['source'], 'Ozon')
        self.assertEqual(result['total_records'], 1)
        self.assertIsInstance(result['anomalies'], list)
        self.assertIn('statistics', result)
    
    def test_check_data_anomalies_high_stock(self):
        """Тест обнаружения аномально высоких остатков."""
        # Arrange
        high_stock_record = InventoryRecord(
            product_id=1,
            sku='HIGH-STOCK-SKU',
            source='Ozon',
            warehouse_name='Ozon FBO',
            stock_type='FBO',
            current_stock=50000,  # Очень высокий остаток
            reserved_stock=0,
            available_stock=50000,
            quantity_present=50000,
            quantity_reserved=0,
            snapshot_date=date.today()
        )
        
        # Act
        result = self.service.check_data_anomalies([high_stock_record], 'Ozon')
        
        # Assert
        anomaly_types = [anomaly['type'] for anomaly in result['anomalies']]
        self.assertIn('high_stock', anomaly_types)
    
    def test_check_data_anomalies_invalid_reserved(self):
        """Тест обнаружения некорректных зарезервированных количеств."""
        # Arrange
        invalid_record = InventoryRecord(
            product_id=1,
            sku='INVALID-RESERVED-SKU',
            source='Ozon',
            warehouse_name='Ozon FBO',
            stock_type='FBO',
            current_stock=10,
            reserved_stock=15,  # Зарезервировано больше чем доступно
            available_stock=0,
            quantity_present=10,
            quantity_reserved=15,
            snapshot_date=date.today()
        )
        
        # Act
        result = self.service.check_data_anomalies([invalid_record], 'Ozon')
        
        # Assert
        anomaly_types = [anomaly['type'] for anomaly in result['anomalies']]
        self.assertIn('invalid_reserved', anomaly_types)


class TestInventoryRecord(unittest.TestCase):
    """Тесты для класса InventoryRecord."""
    
    def test_inventory_record_creation(self):
        """Тест создания записи об остатках."""
        # Act
        record = InventoryRecord(
            product_id=1,
            sku='TEST-SKU',
            source='Ozon',
            warehouse_name='Test Warehouse',
            stock_type='FBO',
            current_stock=10,
            reserved_stock=2,
            available_stock=8,
            quantity_present=10,
            quantity_reserved=2,
            snapshot_date=date.today()
        )
        
        # Assert
        self.assertEqual(record.product_id, 1)
        self.assertEqual(record.sku, 'TEST-SKU')
        self.assertEqual(record.source, 'Ozon')
        self.assertEqual(record.current_stock, 10)
        self.assertEqual(record.reserved_stock, 2)
        self.assertEqual(record.available_stock, 8)
    
    def test_inventory_record_post_init_validation(self):
        """Тест валидации данных при создании записи."""
        # Act
        record = InventoryRecord(
            product_id=1,
            sku='TEST-SKU',
            source='Ozon',
            warehouse_name='Test Warehouse',
            stock_type='FBO',
            current_stock=-5,  # Отрицательное значение
            reserved_stock=None,  # None значение
            available_stock=0,
            quantity_present=-5,
            quantity_reserved=None,
            snapshot_date=date.today()
        )
        
        # Assert - отрицательные значения должны стать 0
        self.assertEqual(record.current_stock, 0)
        self.assertEqual(record.reserved_stock, 0)
        self.assertEqual(record.quantity_present, 0)
        self.assertEqual(record.quantity_reserved, 0)
    
    def test_inventory_record_available_stock_calculation(self):
        """Тест автоматического расчета доступного количества."""
        # Act
        record = InventoryRecord(
            product_id=1,
            sku='TEST-SKU',
            source='Ozon',
            warehouse_name='Test Warehouse',
            stock_type='FBO',
            current_stock=20,
            reserved_stock=5,
            available_stock=0,  # Не задано, должно рассчитаться автоматически
            quantity_present=20,
            quantity_reserved=5,
            snapshot_date=date.today()
        )
        
        # Assert
        self.assertEqual(record.available_stock, 15)  # 20 - 5 = 15


class TestSyncResult(unittest.TestCase):
    """Тесты для класса SyncResult."""
    
    def test_sync_result_creation(self):
        """Тест создания результата синхронизации."""
        # Arrange
        started_at = datetime.now()
        completed_at = datetime.now()
        
        # Act
        result = SyncResult(
            source='Ozon',
            status=SyncStatus.SUCCESS,
            records_processed=100,
            records_updated=0,
            records_inserted=95,
            records_failed=5,
            started_at=started_at,
            completed_at=completed_at,
            api_requests_count=3
        )
        
        # Assert
        self.assertEqual(result.source, 'Ozon')
        self.assertEqual(result.status, SyncStatus.SUCCESS)
        self.assertEqual(result.records_processed, 100)
        self.assertEqual(result.records_inserted, 95)
        self.assertEqual(result.records_failed, 5)
        self.assertEqual(result.api_requests_count, 3)
    
    def test_sync_result_duration_calculation(self):
        """Тест расчета длительности синхронизации."""
        # Arrange
        started_at = datetime(2025, 1, 6, 10, 0, 0)
        completed_at = datetime(2025, 1, 6, 10, 5, 30)  # +5 минут 30 секунд
        
        # Act
        result = SyncResult(
            source='Ozon',
            status=SyncStatus.SUCCESS,
            records_processed=100,
            records_updated=0,
            records_inserted=100,
            records_failed=0,
            started_at=started_at,
            completed_at=completed_at
        )
        
        # Assert
        self.assertEqual(result.duration_seconds, 330)  # 5*60 + 30 = 330 секунд


if __name__ == '__main__':
    # Настройка логирования для тестов
    import logging
    logging.basicConfig(level=logging.WARNING)
    
    # Запуск тестов
    unittest.main(verbosity=2)