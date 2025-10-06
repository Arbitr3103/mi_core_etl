#!/usr/bin/env python3
"""
Конфигурация для unit тестов системы синхронизации остатков.

Содержит настройки и константы для тестирования.

Автор: ETL System
Дата: 06 января 2025
"""

# Тестовые данные для API
TEST_OZON_CONFIG = {
    'OZON_API_BASE_URL': 'https://api-seller.ozon.ru',
    'OZON_CLIENT_ID': 'test_client_id',
    'OZON_API_KEY': 'test_api_key',
    'OZON_REQUEST_DELAY': 0.01  # Минимальная задержка для тестов
}

TEST_WB_CONFIG = {
    'WB_SUPPLIERS_API_URL': 'https://suppliers-api.wildberries.ru',
    'WB_API_TOKEN': 'test_wb_token',
    'WB_REQUEST_DELAY': 0.01  # Минимальная задержка для тестов
}

# Тестовые данные товаров
SAMPLE_PRODUCTS = [
    {
        'id': 1,
        'sku_ozon': 'OZON-SKU-001',
        'sku_wb': '11111',
        'barcode': '1234567890123',
        'name': 'Тестовый товар 1'
    },
    {
        'id': 2,
        'sku_ozon': 'OZON-SKU-002',
        'sku_wb': '22222',
        'barcode': '9876543210987',
        'name': 'Тестовый товар 2'
    },
    {
        'id': 3,
        'sku_ozon': 'OZON-SKU-003',
        'sku_wb': '33333',
        'barcode': '1111111111111',
        'name': 'Тестовый товар 3'
    }
]

# Тестовые данные остатков Ozon
SAMPLE_OZON_API_RESPONSE = {
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

# Тестовые данные остатков Wildberries
SAMPLE_WB_API_RESPONSE = [
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

# Тестовые данные с ошибками
INVALID_INVENTORY_RECORDS = [
    {
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
]

# Настройки тестирования
TEST_SETTINGS = {
    'VERBOSE_OUTPUT': True,
    'BUFFER_OUTPUT': True,
    'FAIL_FAST': False,  # Продолжать тесты даже при ошибках
    'LOG_LEVEL': 'WARNING'  # Минимальное логирование во время тестов
}

# Ожидаемые результаты валидации
EXPECTED_VALIDATION_ERRORS = [
    'product_id',
    'sku', 
    'source',
    'current_stock',
    'snapshot_date'
]

# Пороговые значения для тестов
THRESHOLDS = {
    'MAX_STOCK_QUANTITY': 1000000,
    'MAX_SKU_LENGTH': 255,
    'MAX_WAREHOUSE_NAME_LENGTH': 255,
    'DATA_FRESHNESS_HOURS': 6,
    'OLD_DATA_DAYS': 30
}