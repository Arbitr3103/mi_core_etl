#!/usr/bin/env python3
"""
Тестовый скрипт для проверки загрузки конфигурации (Тест 1.1).
"""

import sys
import os

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import load_config

def test_config():
    """Тест загрузки конфигурации из .env файла."""
    print("=== Тест 1.1: Проверка загрузки конфигурации ===")
    
    try:
        config = load_config()
        
        print(f"OZON_CLIENT_ID: {config['OZON_CLIENT_ID']}")
        print(f"OZON_API_KEY: {config['OZON_API_KEY']}")
        print(f"DB_HOST: {config['DB_HOST']}")
        print(f"DB_NAME: {config['DB_NAME']}")
        
        print("✅ Конфигурация загружена успешно!")
        
    except Exception as e:
        print(f"❌ Ошибка загрузки конфигурации: {e}")
        return False
    
    return True

if __name__ == "__main__":
    test_config()
