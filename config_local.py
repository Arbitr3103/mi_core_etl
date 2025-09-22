"""
Локальная конфигурация для тестирования системы маржинальности.
Использует локальную MySQL в Docker.
"""

import os
from dotenv import load_dotenv

# Загружаем локальную конфигурацию
load_dotenv('.env.local')

# Настройки базы данных (локальная MySQL в Docker)
DB_CONFIG = {
    'host': os.getenv('DB_HOST', '127.0.0.1'),
    'port': int(os.getenv('DB_PORT', 3307)),
    'user': os.getenv('DB_USER', 'root'),
    'password': os.getenv('DB_PASSWORD', 'testpassword'),
    'database': os.getenv('DB_NAME', 'mi_core_db'),
    'charset': 'utf8mb4',
    'autocommit': True
}

# API настройки (тестовые)
OZON_CONFIG = {
    'client_id': os.getenv('OZON_CLIENT_ID', 'test_client_id'),
    'api_key': os.getenv('OZON_API_KEY', 'test_api_key'),
    'base_url': 'https://api-seller.ozon.ru'
}

WILDBERRIES_CONFIG = {
    'api_key': os.getenv('WB_API_KEY', 'test_wb_key'),
    'base_url': 'https://suppliers-api.wildberries.ru'
}

print("✅ Локальная конфигурация загружена")
print(f"   База данных: {DB_CONFIG['host']}:{DB_CONFIG['port']}/{DB_CONFIG['database']}")