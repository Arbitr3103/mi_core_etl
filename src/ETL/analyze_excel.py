#!/usr/bin/env python3
"""
Скрипт для анализа структуры Excel файла себестоимости
"""

import pandas as pd
import sys

def analyze_excel(file_path):
    """Анализирует структуру Excel файла"""
    print(f"Анализ файла: {file_path}")
    print("=" * 50)
    
    # Читаем файл без заголовков
    df = pd.read_excel(file_path, header=None)
    print(f"Размер файла: {df.shape[0]} строк, {df.shape[1]} колонок")
    print()
    
    # Ищем строки с данными
    print("Первые 30 строк:")
    for i in range(min(30, len(df))):
        row = df.iloc[i]
        non_empty = [str(val) for val in row if pd.notna(val) and str(val).strip()]
        if non_empty:
            print(f"Строка {i}: {non_empty}")
    
    print()
    print("Поиск заголовков с 'баркод':")
    for i in range(min(50, len(df))):
        row = df.iloc[i]
        for j, val in enumerate(row):
            if pd.notna(val) and 'баркод' in str(val).lower():
                print(f"Найден заголовок в строке {i}, колонке {j}: '{val}'")
                # Показываем эту строку целиком
                print(f"Вся строка {i}: {row.tolist()}")
                return i
    
    print("Заголовок 'баркод' не найден")
    return None

if __name__ == "__main__":
    file_path = sys.argv[1] if len(sys.argv) > 1 else "data/cost_price.xlsx"
    analyze_excel(file_path)
