#!/usr/bin/env python3
"""
Скрипт для создания тестового файла себестоимости с правильной структурой
"""

import pandas as pd
import os

def create_test_cost_file():
    """Создает тестовый файл себестоимости"""
    
    # Тестовые данные - включаем товар Хлопья_НТВ700 с себестоимостью 103.818
    test_data = [
        {
            'баркод': '4607034648725',  # Штрихкод для Хлопья_НТВ700
            'артикул': 'Хлопья_НТВ700',
            'СС без НДС': 103.818
        },
        {
            'баркод': '1234567890123',
            'артикул': 'TEST_PRODUCT_001',
            'СС без НДС': 50.50
        },
        {
            'баркод': '9876543210987',
            'артикул': 'TEST_PRODUCT_002', 
            'СС без НДС': 75.25
        }
    ]
    
    # Создаем DataFrame
    df = pd.DataFrame(test_data)
    
    # Определяем путь к файлу
    base_dir = os.path.dirname(os.path.abspath(__file__))
    file_path = os.path.join(base_dir, 'data', 'cost_price.xlsx')
    
    # Создаем директорию если не существует
    os.makedirs(os.path.dirname(file_path), exist_ok=True)
    
    # Сохраняем в Excel
    df.to_excel(file_path, index=False, engine='openpyxl')
    
    print(f"✅ Создан тестовый файл: {file_path}")
    print("📊 Содержимое файла:")
    print(df.to_string(index=False))
    
    return file_path

if __name__ == "__main__":
    create_test_cost_file()
