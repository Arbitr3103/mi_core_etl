#!/usr/bin/env python3
"""
Unit тесты для InventoryDataValidator.

Тестирует методы валидации данных об остатках товаров.

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
from unittest.mock import Mock, patch
import sys
import os
from datetime import datetime, date

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

from inventory_data_validator import (
    InventoryDataValidator,
    ValidationResult,
    ValidationIssue,
    ValidationSeverity
)


class TestInventoryDataValidator(unittest.TestCase):
    """Тесты для InventoryDataValidator."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.validator = InventoryDataValidator()
        
        # Тестовые данные - валидная запись
        self.valid_record = {
            'product_id': 1,
            'sku': 'TEST-SKU-001',
            'source': 'Ozon',
            'warehouse_name': 'Ozon FBO',
            'stock_type': 'FBO',
            'current_stock': 10,
            'reserved_stock': 2,
            'available_stock': 8,
            'quantity_present': 10,
            'quantity_reserved': 2,
            'snapshot_date': date.today()
        }


class TestValidationMethods(TestInventoryDataValidator):
    """Тесты основных методов валидации."""
    
    def test_validate_inventory_records_success(self):
        """Тест успешной валидации валидных записей."""
        # Arrange
        records = [self.valid_record]
        
        # Act
        result = self.validator.validate_inventory_records(records, 'Ozon')
        
        # Assert
        self.assertTrue(result.is_valid)
        self.assertEqual(result.total_records, 1)
        self.assertEqual(result.valid_records, 1)
        self.assertEqual(result.error_count, 0)
        self.assertEqual(result.warning_count, 0)
    
    def test_validate_inventory_records_with_errors(self):
        """Тест валидации записей с ошибками."""
        # Arrange
        invalid_record = {
            'product_id': None,  # Ошибка: отсутствует product_id
            'sku': '',  # Ошибка: пустой SKU
            'source': 'InvalidSource',  # Ошибка: неверный источник
            'current_stock': -5,  # Ошибка: отрицательное количество
            'reserved_stock': 15,  # Ошибка: больше текущего
            'available_stock': 5,
            'quantity_present': -5,
            'quantity_reserved': 15,
            'snapshot_date': 'invalid-date'  # Ошибка: неверная дата
        }
        records = [invalid_record]
        
        # Act
        result = self.validator.validate_inventory_records(records, 'Ozon')
        
        # Assert
        self.assertFalse(result.is_valid)
        self.assertEqual(result.total_records, 1)
        self.assertEqual(result.valid_records, 0)
        self.assertGreater(result.error_count, 0)
    
    def test_validate_inventory_records_empty_list(self):
        """Тест валидации пустого списка записей."""
        # Act
        result = self.validator.validate_inventory_records([], 'Ozon')
        
        # Assert
        self.assertTrue(result.is_valid)
        self.assertEqual(result.total_records, 0)
        self.assertEqual(result.valid_records, 0)
        self.assertEqual(result.error_count, 0)


class TestFieldValidation(TestInventoryDataValidator):
    """Тесты валидации отдельных полей."""
    
    def test_validate_required_field_success(self):
        """Тест успешной валидации обязательного поля."""
        # Act
        result = self.validator._validate_required_field(
            {'test_field': 'valid_value'}, 
            'test_field', 
            'record_1'
        )
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_required_field_missing(self):
        """Тест валидации отсутствующего обязательного поля."""
        # Act
        result = self.validator._validate_required_field(
            {}, 
            'missing_field', 
            'record_1'
        )
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_required_field_empty(self):
        """Тест валидации пустого обязательного поля."""
        # Act
        result = self.validator._validate_required_field(
            {'test_field': ''}, 
            'test_field', 
            'record_1'
        )
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
    
    def test_validate_product_id_success(self):
        """Тест успешной валидации product_id."""
        # Act
        result = self.validator._validate_product_id(123, 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_product_id_none(self):
        """Тест валидации None product_id."""
        # Act
        result = self.validator._validate_product_id(None, 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].field, 'product_id')
    
    def test_validate_product_id_negative(self):
        """Тест валидации отрицательного product_id."""
        # Act
        result = self.validator._validate_product_id(-1, 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
    
    def test_validate_product_id_string(self):
        """Тест валидации строкового product_id."""
        # Act
        result = self.validator._validate_product_id('not_a_number', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
    
    def test_validate_sku_success_ozon(self):
        """Тест успешной валидации Ozon SKU."""
        # Act
        result = self.validator._validate_sku('TEST-SKU-001', 'Ozon', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_sku_success_wb(self):
        """Тест успешной валидации WB SKU."""
        # Act
        result = self.validator._validate_sku('12345', 'Wildberries', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_sku_empty(self):
        """Тест валидации пустого SKU."""
        # Act
        result = self.validator._validate_sku('', 'Ozon', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_sku_too_long(self):
        """Тест валидации слишком длинного SKU."""
        # Arrange
        long_sku = 'A' * 300  # Больше 255 символов
        
        # Act
        result = self.validator._validate_sku(long_sku, 'Ozon', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
    
    def test_validate_sku_wb_non_numeric(self):
        """Тест валидации нечислового WB SKU."""
        # Act
        result = self.validator._validate_sku('ABC123', 'Wildberries', 'record_1')
        
        # Assert
        # Должно пройти валидацию, но с предупреждением
        self.assertTrue(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.WARNING)
    
    def test_validate_source_success(self):
        """Тест успешной валидации источника."""
        # Act
        result = self.validator._validate_source('Ozon', 'Ozon', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_source_mismatch(self):
        """Тест валидации несоответствующего источника."""
        # Act
        result = self.validator._validate_source('Wildberries', 'Ozon', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_source_invalid(self):
        """Тест валидации неизвестного источника."""
        # Act
        result = self.validator._validate_source('InvalidSource', 'InvalidSource', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)


class TestQuantityValidation(TestInventoryDataValidator):
    """Тесты валидации количественных показателей."""
    
    def test_validate_quantity_success(self):
        """Тест успешной валидации количества."""
        # Act
        result = self.validator._validate_quantity(10, 'current_stock', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_quantity_zero(self):
        """Тест валидации нулевого количества."""
        # Act
        result = self.validator._validate_quantity(0, 'current_stock', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_quantity_negative(self):
        """Тест валидации отрицательного количества."""
        # Act
        result = self.validator._validate_quantity(-5, 'current_stock', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_quantity_very_large(self):
        """Тест валидации очень большого количества."""
        # Act
        result = self.validator._validate_quantity(2000000, 'current_stock', 'record_1')
        
        # Assert
        self.assertTrue(result)  # Валидация проходит
        self.assertEqual(len(self.validator.issues), 1)  # Но есть предупреждение
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.WARNING)
    
    def test_validate_quantity_string(self):
        """Тест валидации строкового количества."""
        # Act
        result = self.validator._validate_quantity('not_a_number', 'current_stock', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_quantity_none(self):
        """Тест валидации None количества."""
        # Act
        result = self.validator._validate_quantity(None, 'current_stock', 'record_1')
        
        # Assert
        self.assertTrue(result)  # None конвертируется в 0


class TestStockLogicValidation(TestInventoryDataValidator):
    """Тесты валидации логики остатков."""
    
    def test_validate_stock_logic_success(self):
        """Тест успешной валидации логики остатков."""
        # Arrange
        record = {
            'current_stock': 10,
            'reserved_stock': 3,
            'available_stock': 7,
            'quantity_present': 10,
            'quantity_reserved': 3
        }
        
        # Act
        result = self.validator._validate_stock_logic(record, 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_stock_logic_reserved_exceeds_current(self):
        """Тест валидации когда зарезервированное больше текущего."""
        # Arrange
        record = {
            'current_stock': 5,
            'reserved_stock': 10,  # Больше текущего
            'available_stock': 0,
            'quantity_present': 5,
            'quantity_reserved': 10
        }
        
        # Act
        result = self.validator._validate_stock_logic(record, 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_stock_logic_available_mismatch(self):
        """Тест валидации неправильного доступного количества."""
        # Arrange
        record = {
            'current_stock': 10,
            'reserved_stock': 3,
            'available_stock': 5,  # Должно быть 7 (10-3)
            'quantity_present': 10,
            'quantity_reserved': 3
        }
        
        # Act
        result = self.validator._validate_stock_logic(record, 'record_1')
        
        # Assert
        self.assertTrue(result)  # Логика проходит
        self.assertEqual(len(self.validator.issues), 1)  # Но есть предупреждение
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.WARNING)
    
    def test_validate_stock_logic_quantity_mismatch(self):
        """Тест валидации несоответствия quantity_* полей."""
        # Arrange
        record = {
            'current_stock': 10,
            'reserved_stock': 3,
            'available_stock': 7,
            'quantity_present': 12,  # Не соответствует current_stock
            'quantity_reserved': 5   # Не соответствует reserved_stock
        }
        
        # Act
        result = self.validator._validate_stock_logic(record, 'record_1')
        
        # Assert
        self.assertTrue(result)  # Логика проходит
        self.assertEqual(len(self.validator.issues), 2)  # Два предупреждения
        for issue in self.validator.issues:
            self.assertEqual(issue.severity, ValidationSeverity.WARNING)


class TestStockTypeValidation(TestInventoryDataValidator):
    """Тесты валидации типов складов."""
    
    def test_validate_stock_type_ozon_fbo(self):
        """Тест валидации типа склада FBO для Ozon."""
        # Act
        result = self.validator._validate_stock_type('FBO', 'Ozon', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_stock_type_ozon_fbs(self):
        """Тест валидации типа склада FBS для Ozon."""
        # Act
        result = self.validator._validate_stock_type('FBS', 'Ozon', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_stock_type_ozon_realfbs(self):
        """Тест валидации типа склада realFBS для Ozon."""
        # Act
        result = self.validator._validate_stock_type('realFBS', 'Ozon', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_stock_type_wb_fbs(self):
        """Тест валидации типа склада FBS для Wildberries."""
        # Act
        result = self.validator._validate_stock_type('FBS', 'Wildberries', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_stock_type_invalid_for_source(self):
        """Тест валидации неподходящего типа склада для источника."""
        # Act
        result = self.validator._validate_stock_type('InvalidType', 'Ozon', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.WARNING)


class TestWarehouseNameValidation(TestInventoryDataValidator):
    """Тесты валидации названий складов."""
    
    def test_validate_warehouse_name_success(self):
        """Тест успешной валидации названия склада."""
        # Act
        result = self.validator._validate_warehouse_name('Ozon FBO Warehouse', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_warehouse_name_empty(self):
        """Тест валидации пустого названия склада."""
        # Act
        result = self.validator._validate_warehouse_name('', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.WARNING)
    
    def test_validate_warehouse_name_none(self):
        """Тест валидации None названия склада."""
        # Act
        result = self.validator._validate_warehouse_name(None, 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
    
    def test_validate_warehouse_name_not_string(self):
        """Тест валидации не-строкового названия склада."""
        # Act
        result = self.validator._validate_warehouse_name(123, 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_warehouse_name_too_long(self):
        """Тест валидации слишком длинного названия склада."""
        # Arrange
        long_name = 'A' * 300  # Больше 255 символов
        
        # Act
        result = self.validator._validate_warehouse_name(long_name, 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)


class TestSnapshotDateValidation(TestInventoryDataValidator):
    """Тесты валидации даты снимка."""
    
    def test_validate_snapshot_date_success_date(self):
        """Тест успешной валидации даты как объекта date."""
        # Act
        result = self.validator._validate_snapshot_date(date.today(), 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_snapshot_date_success_datetime(self):
        """Тест успешной валидации даты как объекта datetime."""
        # Act
        result = self.validator._validate_snapshot_date(datetime.now(), 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_snapshot_date_success_string(self):
        """Тест успешной валидации даты как строки."""
        # Act
        result = self.validator._validate_snapshot_date('2025-01-06', 'record_1')
        
        # Assert
        self.assertTrue(result)
    
    def test_validate_snapshot_date_none(self):
        """Тест валидации None даты."""
        # Act
        result = self.validator._validate_snapshot_date(None, 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_snapshot_date_invalid_string(self):
        """Тест валидации неправильной строки даты."""
        # Act
        result = self.validator._validate_snapshot_date('invalid-date', 'record_1')
        
        # Assert
        self.assertFalse(result)
        self.assertEqual(len(self.validator.issues), 1)
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.ERROR)
    
    def test_validate_snapshot_date_future(self):
        """Тест валидации даты в будущем."""
        # Arrange
        future_date = date(2030, 1, 1)
        
        # Act
        result = self.validator._validate_snapshot_date(future_date, 'record_1')
        
        # Assert
        self.assertTrue(result)  # Валидация проходит
        self.assertEqual(len(self.validator.issues), 1)  # Но есть предупреждение
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.WARNING)
    
    def test_validate_snapshot_date_too_old(self):
        """Тест валидации слишком старой даты."""
        # Arrange
        old_date = date(2020, 1, 1)
        
        # Act
        result = self.validator._validate_snapshot_date(old_date, 'record_1')
        
        # Assert
        self.assertTrue(result)  # Валидация проходит
        self.assertEqual(len(self.validator.issues), 1)  # Но есть предупреждение
        self.assertEqual(self.validator.issues[0].severity, ValidationSeverity.WARNING)


class TestProductExistenceValidation(TestInventoryDataValidator):
    """Тесты проверки существования товаров в БД."""
    
    def test_validate_product_existence_success(self):
        """Тест успешной проверки существования товаров."""
        # Arrange
        product_ids = [1, 2, 3]
        mock_cursor = Mock()
        mock_cursor.fetchall.return_value = [
            {'id': 1}, {'id': 2}, {'id': 3}
        ]
        
        # Act
        result = self.validator.validate_product_existence(product_ids, mock_cursor)
        
        # Assert
        self.assertTrue(result.is_valid)
        self.assertEqual(result.total_records, 3)
        self.assertEqual(result.valid_records, 3)
        self.assertEqual(len(result.issues), 0)
    
    def test_validate_product_existence_missing_products(self):
        """Тест проверки с отсутствующими товарами."""
        # Arrange
        product_ids = [1, 2, 3, 4]
        mock_cursor = Mock()
        mock_cursor.fetchall.return_value = [
            {'id': 1}, {'id': 3}  # Товары 2 и 4 отсутствуют
        ]
        
        # Act
        result = self.validator.validate_product_existence(product_ids, mock_cursor)
        
        # Assert
        self.assertFalse(result.is_valid)
        self.assertEqual(result.total_records, 4)
        self.assertEqual(result.valid_records, 2)
        self.assertEqual(len(result.issues), 2)  # Два отсутствующих товара
    
    def test_validate_product_existence_empty_list(self):
        """Тест проверки пустого списка товаров."""
        # Arrange
        product_ids = []
        mock_cursor = Mock()
        
        # Act
        result = self.validator.validate_product_existence(product_ids, mock_cursor)
        
        # Assert
        self.assertTrue(result.is_valid)
        self.assertEqual(result.total_records, 0)
        self.assertEqual(result.valid_records, 0)
        self.assertEqual(len(result.issues), 0)
    
    def test_validate_product_existence_database_error(self):
        """Тест обработки ошибки базы данных."""
        # Arrange
        product_ids = [1, 2, 3]
        mock_cursor = Mock()
        mock_cursor.execute.side_effect = Exception("Database error")
        
        # Act
        result = self.validator.validate_product_existence(product_ids, mock_cursor)
        
        # Assert
        self.assertFalse(result.is_valid)
        self.assertEqual(result.total_records, 3)
        self.assertEqual(result.valid_records, 0)
        self.assertEqual(len(result.issues), 1)
        self.assertIn("Database error", result.issues[0].message)


class TestValidationResult(unittest.TestCase):
    """Тесты для класса ValidationResult."""
    
    def test_validation_result_properties(self):
        """Тест свойств ValidationResult."""
        # Arrange
        issues = [
            ValidationIssue(ValidationSeverity.ERROR, 'field1', 'Error message'),
            ValidationIssue(ValidationSeverity.WARNING, 'field2', 'Warning message'),
            ValidationIssue(ValidationSeverity.WARNING, 'field3', 'Another warning'),
        ]
        
        # Act
        result = ValidationResult(
            is_valid=False,
            total_records=10,
            valid_records=7,
            issues=issues
        )
        
        # Assert
        self.assertEqual(result.error_count, 1)
        self.assertEqual(result.warning_count, 2)
        self.assertEqual(result.success_rate, 70.0)
    
    def test_validation_result_success_rate_zero_records(self):
        """Тест расчета success_rate при нулевом количестве записей."""
        # Act
        result = ValidationResult(
            is_valid=True,
            total_records=0,
            valid_records=0,
            issues=[]
        )
        
        # Assert
        self.assertEqual(result.success_rate, 100.0)


class TestValidationIssue(unittest.TestCase):
    """Тесты для класса ValidationIssue."""
    
    def test_validation_issue_str(self):
        """Тест строкового представления ValidationIssue."""
        # Act
        issue = ValidationIssue(
            severity=ValidationSeverity.ERROR,
            field='product_id',
            message='Product not found',
            value=123,
            record_id='record_1'
        )
        
        # Assert
        expected = "[ERROR] product_id: Product not found"
        self.assertEqual(str(issue), expected)


if __name__ == '__main__':
    # Настройка логирования для тестов
    import logging
    logging.basicConfig(level=logging.WARNING)
    
    # Запуск тестов
    unittest.main(verbosity=2)