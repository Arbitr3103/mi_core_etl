#!/usr/bin/env python3
"""
Модуль валидации данных об остатках товаров.

Содержит классы и методы для проверки корректности данных,
получаемых от API маркетплейсов перед сохранением в БД.

Автор: ETL System
Дата: 06 января 2025
"""

import logging
from typing import List, Dict, Any, Optional, Tuple
from dataclasses import dataclass
from enum import Enum
from datetime import datetime, date

# Настройка логирования
logger = logging.getLogger(__name__)


class ValidationSeverity(Enum):
    """Уровни серьезности ошибок валидации."""
    ERROR = "error"
    WARNING = "warning"
    INFO = "info"


@dataclass
class ValidationIssue:
    """Проблема валидации данных."""
    severity: ValidationSeverity
    field: str
    message: str
    value: Any = None
    record_id: Optional[str] = None
    
    def __str__(self) -> str:
        return f"[{self.severity.value.upper()}] {self.field}: {self.message}"


@dataclass
class ValidationResult:
    """Результат валидации данных."""
    is_valid: bool
    total_records: int
    valid_records: int
    issues: List[ValidationIssue]
    
    @property
    def error_count(self) -> int:
        """Количество критических ошибок."""
        return len([issue for issue in self.issues if issue.severity == ValidationSeverity.ERROR])
    
    @property
    def warning_count(self) -> int:
        """Количество предупреждений."""
        return len([issue for issue in self.issues if issue.severity == ValidationSeverity.WARNING])
    
    @property
    def success_rate(self) -> float:
        """Процент успешно валидированных записей."""
        if self.total_records == 0:
            return 100.0
        return (self.valid_records / self.total_records) * 100.0


class InventoryDataValidator:
    """Валидатор данных об остатках товаров."""
    
    def __init__(self):
        """Инициализация валидатора."""
        self.issues = []
        
    def validate_inventory_records(self, records: List[Dict[str, Any]], source: str) -> ValidationResult:
        """
        Валидация списка записей об остатках.
        
        Args:
            records: Список записей для валидации
            source: Источник данных ('Ozon' или 'Wildberries')
            
        Returns:
            ValidationResult: Результат валидации
        """
        logger.info(f"🔍 Начинаем валидацию {len(records)} записей от {source}")
        
        self.issues = []
        valid_records = 0
        
        for i, record in enumerate(records):
            record_id = f"{source}_{i}"
            
            try:
                if self._validate_single_record(record, source, record_id):
                    valid_records += 1
            except Exception as e:
                self._add_issue(
                    ValidationSeverity.ERROR,
                    "validation_error",
                    f"Критическая ошибка валидации: {e}",
                    record_id=record_id
                )
        
        is_valid = len([issue for issue in self.issues if issue.severity == ValidationSeverity.ERROR]) == 0
        
        result = ValidationResult(
            is_valid=is_valid,
            total_records=len(records),
            valid_records=valid_records,
            issues=self.issues.copy()
        )
        
        logger.info(f"✅ Валидация завершена: {valid_records}/{len(records)} записей валидны, "
                   f"ошибок: {result.error_count}, предупреждений: {result.warning_count}")
        
        return result
    
    def _validate_single_record(self, record: Dict[str, Any], source: str, record_id: str) -> bool:
        """
        Валидация одной записи об остатках.
        
        Args:
            record: Запись для валидации
            source: Источник данных
            record_id: Идентификатор записи для логирования
            
        Returns:
            bool: True если запись валидна
        """
        is_valid = True
        
        # Валидация обязательных полей
        required_fields = ['product_id', 'sku', 'source']
        for field in required_fields:
            if not self._validate_required_field(record, field, record_id):
                is_valid = False
        
        # Валидация product_id
        if not self._validate_product_id(record.get('product_id'), record_id):
            is_valid = False
        
        # Валидация SKU
        if not self._validate_sku(record.get('sku'), source, record_id):
            is_valid = False
        
        # Валидация источника
        if not self._validate_source(record.get('source'), source, record_id):
            is_valid = False
        
        # Валидация количественных показателей
        quantity_fields = ['current_stock', 'reserved_stock', 'available_stock', 
                          'quantity_present', 'quantity_reserved']
        for field in quantity_fields:
            if not self._validate_quantity(record.get(field, 0), field, record_id):
                is_valid = False
        
        # Валидация логики остатков
        if not self._validate_stock_logic(record, record_id):
            is_valid = False
        
        # Валидация типа склада
        if not self._validate_stock_type(record.get('stock_type'), source, record_id):
            is_valid = False
        
        # Валидация названия склада
        if not self._validate_warehouse_name(record.get('warehouse_name'), record_id):
            is_valid = False
        
        # Валидация даты снимка
        if not self._validate_snapshot_date(record.get('snapshot_date'), record_id):
            is_valid = False
        
        return is_valid
    
    def _validate_required_field(self, record: Dict[str, Any], field: str, record_id: str) -> bool:
        """Валидация обязательного поля."""
        value = record.get(field)
        
        if value is None or value == '':
            self._add_issue(
                ValidationSeverity.ERROR,
                field,
                f"Обязательное поле отсутствует или пустое",
                value,
                record_id
            )
            return False
        
        return True
    
    def _validate_product_id(self, product_id: Any, record_id: str) -> bool:
        """Валидация product_id."""
        if product_id is None:
            self._add_issue(
                ValidationSeverity.ERROR,
                "product_id",
                "Product ID не может быть None",
                product_id,
                record_id
            )
            return False
        
        try:
            product_id_int = int(product_id)
            if product_id_int <= 0:
                self._add_issue(
                    ValidationSeverity.ERROR,
                    "product_id",
                    "Product ID должен быть положительным числом",
                    product_id,
                    record_id
                )
                return False
        except (ValueError, TypeError):
            self._add_issue(
                ValidationSeverity.ERROR,
                "product_id",
                "Product ID должен быть числом",
                product_id,
                record_id
            )
            return False
        
        return True
    
    def _validate_sku(self, sku: Any, source: str, record_id: str) -> bool:
        """Валидация SKU."""
        if not sku or not isinstance(sku, str):
            self._add_issue(
                ValidationSeverity.ERROR,
                "sku",
                "SKU должен быть непустой строкой",
                sku,
                record_id
            )
            return False
        
        # Проверка длины SKU
        if len(sku) > 255:
            self._add_issue(
                ValidationSeverity.ERROR,
                "sku",
                "SKU слишком длинный (максимум 255 символов)",
                sku,
                record_id
            )
            return False
        
        # Специфичные проверки для разных источников
        if source == 'Ozon':
            # Ozon SKU обычно содержат буквы, цифры, дефисы
            if not sku.replace('-', '').replace('_', '').isalnum():
                self._add_issue(
                    ValidationSeverity.WARNING,
                    "sku",
                    "Ozon SKU содержит необычные символы",
                    sku,
                    record_id
                )
        elif source == 'Wildberries':
            # WB nmId должен быть числом
            if not sku.isdigit():
                self._add_issue(
                    ValidationSeverity.WARNING,
                    "sku",
                    "Wildberries SKU (nmId) должен быть числом",
                    sku,
                    record_id
                )
        
        return True
    
    def _validate_source(self, source: Any, expected_source: str, record_id: str) -> bool:
        """Валидация источника данных."""
        if source != expected_source:
            self._add_issue(
                ValidationSeverity.ERROR,
                "source",
                f"Источник не соответствует ожидаемому: {source} != {expected_source}",
                source,
                record_id
            )
            return False
        
        valid_sources = ['Ozon', 'Wildberries']
        if source not in valid_sources:
            self._add_issue(
                ValidationSeverity.ERROR,
                "source",
                f"Неизвестный источник: {source}. Допустимые: {valid_sources}",
                source,
                record_id
            )
            return False
        
        return True
    
    def _validate_quantity(self, quantity: Any, field: str, record_id: str) -> bool:
        """Валидация количественного показателя."""
        try:
            quantity_int = int(quantity or 0)
            
            if quantity_int < 0:
                self._add_issue(
                    ValidationSeverity.ERROR,
                    field,
                    "Количество не может быть отрицательным",
                    quantity,
                    record_id
                )
                return False
            
            # Проверка на разумные пределы
            if quantity_int > 1000000:
                self._add_issue(
                    ValidationSeverity.WARNING,
                    field,
                    "Очень большое количество товара (>1M)",
                    quantity,
                    record_id
                )
            
        except (ValueError, TypeError):
            self._add_issue(
                ValidationSeverity.ERROR,
                field,
                "Количество должно быть числом",
                quantity,
                record_id
            )
            return False
        
        return True
    
    def _validate_stock_logic(self, record: Dict[str, Any], record_id: str) -> bool:
        """Валидация логики остатков."""
        is_valid = True
        
        try:
            current_stock = int(record.get('current_stock', 0))
            reserved_stock = int(record.get('reserved_stock', 0))
            available_stock = int(record.get('available_stock', 0))
            quantity_present = int(record.get('quantity_present', 0))
            quantity_reserved = int(record.get('quantity_reserved', 0))
            
            # Проверка: зарезервированное не может быть больше текущего
            if reserved_stock > current_stock:
                self._add_issue(
                    ValidationSeverity.ERROR,
                    "stock_logic",
                    f"Зарезервированное количество ({reserved_stock}) больше текущего ({current_stock})",
                    record_id=record_id
                )
                is_valid = False
            
            # Проверка: доступное должно равняться текущему минус зарезервированное
            expected_available = max(0, current_stock - reserved_stock)
            if available_stock != expected_available:
                self._add_issue(
                    ValidationSeverity.WARNING,
                    "stock_logic",
                    f"Доступное количество ({available_stock}) не соответствует расчетному ({expected_available})",
                    record_id=record_id
                )
            
            # Проверка соответствия quantity_* полей
            if quantity_present != current_stock:
                self._add_issue(
                    ValidationSeverity.WARNING,
                    "stock_logic",
                    f"quantity_present ({quantity_present}) не соответствует current_stock ({current_stock})",
                    record_id=record_id
                )
            
            if quantity_reserved != reserved_stock:
                self._add_issue(
                    ValidationSeverity.WARNING,
                    "stock_logic",
                    f"quantity_reserved ({quantity_reserved}) не соответствует reserved_stock ({reserved_stock})",
                    record_id=record_id
                )
            
        except (ValueError, TypeError) as e:
            self._add_issue(
                ValidationSeverity.ERROR,
                "stock_logic",
                f"Ошибка валидации логики остатков: {e}",
                record_id=record_id
            )
            is_valid = False
        
        return is_valid
    
    def _validate_stock_type(self, stock_type: Any, source: str, record_id: str) -> bool:
        """Валидация типа склада."""
        if source == 'Ozon':
            valid_types = ['FBO', 'FBS', 'realFBS']
        elif source == 'Wildberries':
            valid_types = ['FBS', 'FBO']  # WB в основном FBS
        else:
            valid_types = ['FBO', 'FBS', 'realFBS']
        
        if stock_type not in valid_types:
            self._add_issue(
                ValidationSeverity.WARNING,
                "stock_type",
                f"Неизвестный тип склада для {source}: {stock_type}. Допустимые: {valid_types}",
                stock_type,
                record_id
            )
            return False
        
        return True
    
    def _validate_warehouse_name(self, warehouse_name: Any, record_id: str) -> bool:
        """Валидация названия склада."""
        if not warehouse_name:
            self._add_issue(
                ValidationSeverity.WARNING,
                "warehouse_name",
                "Название склада не указано",
                warehouse_name,
                record_id
            )
            return False
        
        if not isinstance(warehouse_name, str):
            self._add_issue(
                ValidationSeverity.ERROR,
                "warehouse_name",
                "Название склада должно быть строкой",
                warehouse_name,
                record_id
            )
            return False
        
        if len(warehouse_name) > 255:
            self._add_issue(
                ValidationSeverity.ERROR,
                "warehouse_name",
                "Название склада слишком длинное (максимум 255 символов)",
                warehouse_name,
                record_id
            )
            return False
        
        return True
    
    def _validate_snapshot_date(self, snapshot_date: Any, record_id: str) -> bool:
        """Валидация даты снимка."""
        if snapshot_date is None:
            self._add_issue(
                ValidationSeverity.ERROR,
                "snapshot_date",
                "Дата снимка не указана",
                snapshot_date,
                record_id
            )
            return False
        
        try:
            if isinstance(snapshot_date, str):
                # Пытаемся парсить строку как дату
                parsed_date = datetime.strptime(snapshot_date, '%Y-%m-%d').date()
            elif isinstance(snapshot_date, datetime):
                parsed_date = snapshot_date.date()
            elif isinstance(snapshot_date, date):
                parsed_date = snapshot_date
            else:
                raise ValueError(f"Неподдерживаемый тип даты: {type(snapshot_date)}")
            
            # Проверка на разумность даты
            today = date.today()
            if parsed_date > today:
                self._add_issue(
                    ValidationSeverity.WARNING,
                    "snapshot_date",
                    f"Дата снимка в будущем: {parsed_date}",
                    snapshot_date,
                    record_id
                )
            
            # Проверка на слишком старую дату
            days_old = (today - parsed_date).days
            if days_old > 30:
                self._add_issue(
                    ValidationSeverity.WARNING,
                    "snapshot_date",
                    f"Дата снимка слишком старая: {days_old} дней назад",
                    snapshot_date,
                    record_id
                )
            
        except (ValueError, TypeError) as e:
            self._add_issue(
                ValidationSeverity.ERROR,
                "snapshot_date",
                f"Некорректная дата снимка: {e}",
                snapshot_date,
                record_id
            )
            return False
        
        return True
    
    def _add_issue(self, severity: ValidationSeverity, field: str, message: str, 
                   value: Any = None, record_id: Optional[str] = None):
        """Добавление проблемы валидации."""
        issue = ValidationIssue(
            severity=severity,
            field=field,
            message=message,
            value=value,
            record_id=record_id
        )
        self.issues.append(issue)
        
        # Логируем критические ошибки
        if severity == ValidationSeverity.ERROR:
            logger.error(f"❌ {issue}")
        elif severity == ValidationSeverity.WARNING:
            logger.warning(f"⚠️ {issue}")
    
    def validate_product_existence(self, product_ids: List[int], db_cursor) -> ValidationResult:
        """
        Проверка существования товаров в базе данных.
        
        Args:
            product_ids: Список ID товаров для проверки
            db_cursor: Курсор базы данных
            
        Returns:
            ValidationResult: Результат проверки
        """
        logger.info(f"🔍 Проверяем существование {len(product_ids)} товаров в БД")
        
        self.issues = []
        valid_count = 0
        
        try:
            if not product_ids:
                return ValidationResult(
                    is_valid=True,
                    total_records=0,
                    valid_records=0,
                    issues=[]
                )
            
            # Получаем уникальные ID
            unique_ids = list(set(product_ids))
            
            # Проверяем существование товаров в БД
            placeholders = ','.join(['%s'] * len(unique_ids))
            query = f"SELECT id FROM dim_products WHERE id IN ({placeholders})"
            
            db_cursor.execute(query, unique_ids)
            existing_ids = {row['id'] for row in db_cursor.fetchall()}
            
            # Проверяем каждый ID
            for product_id in unique_ids:
                if product_id in existing_ids:
                    valid_count += 1
                else:
                    self._add_issue(
                        ValidationSeverity.ERROR,
                        "product_id",
                        f"Товар с ID {product_id} не найден в БД",
                        product_id
                    )
            
            logger.info(f"✅ Проверка товаров завершена: {valid_count}/{len(unique_ids)} найдено в БД")
            
            return ValidationResult(
                is_valid=len(self.issues) == 0,
                total_records=len(unique_ids),
                valid_records=valid_count,
                issues=self.issues.copy()
            )
            
        except Exception as e:
            logger.error(f"❌ Ошибка проверки существования товаров: {e}")
            self._add_issue(
                ValidationSeverity.ERROR,
                "database",
                f"Ошибка запроса к БД: {e}"
            )
            
            return ValidationResult(
                is_valid=False,
                total_records=len(product_ids),
                valid_records=0,
                issues=self.issues.copy()
            )


def main():
    """Функция для тестирования валидатора."""
    validator = InventoryDataValidator()
    
    # Тестовые данные
    test_records = [
        {
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
        },
        {
            'product_id': None,  # Ошибка: отсутствует product_id
            'sku': '',  # Ошибка: пустой SKU
            'source': 'Ozon',
            'current_stock': -5,  # Ошибка: отрицательное количество
            'reserved_stock': 15,  # Ошибка: больше текущего
            'available_stock': 5,
            'quantity_present': -5,
            'quantity_reserved': 15,
            'snapshot_date': '2025-01-06'
        }
    ]
    
    result = validator.validate_inventory_records(test_records, 'Ozon')
    
    print(f"Результат валидации:")
    print(f"  Всего записей: {result.total_records}")
    print(f"  Валидных: {result.valid_records}")
    print(f"  Процент успеха: {result.success_rate:.1f}%")
    print(f"  Ошибок: {result.error_count}")
    print(f"  Предупреждений: {result.warning_count}")
    
    print("\nПроблемы:")
    for issue in result.issues:
        print(f"  {issue}")


if __name__ == "__main__":
    main()