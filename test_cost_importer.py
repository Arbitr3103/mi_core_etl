"""
Тестовый скрипт для проверки импорта себестоимости.

Создает тестовый Excel файл и проверяет работу cost_importer.py
"""

import os
import pandas as pd
import tempfile
import shutil
from pathlib import Path

def create_test_excel():
    """Создает тестовый Excel файл с себестоимостью."""
    
    # Создаем директории если их нет
    uploads_dir = "/Users/vladimirbragin/CascadeProjects/mi_core_etl/uploads"
    Path(uploads_dir).mkdir(parents=True, exist_ok=True)
    
    # Тестовые данные
    test_data = {
        'barcode': [
            '4607034370237',  # Тестовый штрихкод 1
            '4607034370244',  # Тестовый штрихкод 2
            '4607034370251',  # Тестовый штрихкод 3
            '9999999999999',  # Несуществующий штрихкод
            '1111111111111'   # Еще один несуществующий
        ],
        'cost_price': [
            150.50,
            200.00,
            175.25,
            300.75,
            125.00
        ]
    }
    
    df = pd.DataFrame(test_data)
    
    # Сохраняем как cost_price.xlsx (имя, которое ищет скрипт)
    test_file_path = os.path.join(uploads_dir, "cost_price.xlsx")
    df.to_excel(test_file_path, index=False)
    
    print(f"✅ Создан тестовый файл: {test_file_path}")
    print(f"📊 Записей в файле: {len(df)}")
    print("\nСодержимое файла:")
    print(df.to_string(index=False))
    
    return test_file_path

def main():
    """Основная функция тестирования."""
    print("🧪 Тестирование импорта себестоимости")
    print("=" * 50)
    
    # Создаем тестовый файл
    test_file = create_test_excel()
    
    print(f"\n📁 Тестовый файл создан: {test_file}")
    print("\n🚀 Теперь можно запустить:")
    print("python3 cost_importer.py")
    print("\nИли протестировать через shell скрипт:")
    print("./run_cost_import.sh")

if __name__ == "__main__":
    main()
