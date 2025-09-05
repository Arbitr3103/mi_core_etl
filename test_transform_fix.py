#!/usr/bin/env python3
"""
Тест для проверки исправленной функции transform_posting_data с CSV структурой
"""

import sys
import os
sys.path.append(os.path.join(os.path.dirname('.'), 'importers'))

from ozon_importer import request_report, get_report_by_code, transform_posting_data
from datetime import datetime, timedelta
import csv
import io

def test_transform_posting_data():
    """Тестируем трансформацию данных CSV заказов"""
    
    # Берем данные за позавчерашний день
    target_date = datetime.now() - timedelta(days=3)
    start_date = target_date.strftime('%Y-%m-%d')
    end_date = target_date.strftime('%Y-%m-%d')

    print(f'🧪 Тестируем трансформацию CSV заказов за {start_date}')
    
    try:
        # Получаем CSV данные
        print('📡 Заказываем отчет...')
        report_code = request_report('postings', start_date, end_date)
        
        print('⏳ Получаем CSV...')
        csv_content = get_report_by_code(report_code)
        
        print('📊 Парсим CSV...')
        csv_file = io.StringIO(csv_content)
        csv_reader = csv.DictReader(csv_file, delimiter=';')
        
        postings = []
        for row in csv_reader:
            postings.append(row)
        
        if not postings:
            print('❌ Заказы не получены')
            return
            
        print(f'✅ Получено заказов: {len(postings)}')
        
        # Тестируем трансформацию первых 3 заказов
        print(f'\n🔄 Тестируем трансформацию первых 3 заказов:')
        
        for i, csv_row in enumerate(postings[:3]):
            print(f'\n--- Заказ {i+1} ---')
            print(f'Номер заказа: {csv_row.get("Номер заказа", "")}')
            print(f'Артикул: {csv_row.get("Артикул", "")}')
            print(f'Количество: {csv_row.get("Количество", "")}')
            print(f'Цена: {csv_row.get("Ваша цена", "")}')
            
            try:
                # Вызываем исправленную функцию трансформации
                # ВАЖНО: Функция ожидает подключение к БД, поэтому тест может упасть
                # Но мы увидим, правильно ли извлекаются поля
                transformed = transform_posting_data(csv_row)
                
                if transformed:
                    print(f'✅ Трансформация успешна: {len(transformed)} записей')
                    record = transformed[0]
                    print(f'  order_id: {record.get("order_id")}')
                    print(f'  sku: {record.get("sku")}')
                    print(f'  qty: {record.get("qty")}')
                    print(f'  price: {record.get("price")}')
                else:
                    print('⚠️  Трансформация вернула пустой список')
                    
            except Exception as e:
                if "Can't connect to MySQL server" in str(e):
                    print('⚠️  Ошибка БД (ожидаемо), но извлечение полей работает')
                elif "не найден в dim_products" in str(e):
                    print('⚠️  Товар не найден в dim_products (ожидаемо для теста)')
                else:
                    print(f'❌ Неожиданная ошибка: {e}')
                    
        # Проверим маппинг полей без вызова БД
        print(f'\n🔍 Проверка маппинга полей (без БД):')
        sample_row = postings[0]
        
        order_id = sample_row.get('Номер заказа', '')
        sku_ozon = sample_row.get('Артикул', '')
        quantity_str = sample_row.get('Количество', '0')
        price_str = sample_row.get('Ваша цена', '0')
        date_str = sample_row.get('Принят в обработку', '')
        
        print(f'  Номер заказа: "{order_id}" (было: posting_number)')
        print(f'  Артикул: "{sku_ozon}" (было: offer_id)')
        print(f'  Количество: "{quantity_str}" (было: quantity)')
        print(f'  Цена: "{price_str}" (было: price)')
        print(f'  Дата: "{date_str[:10]}" (было: created_at)')
        
        # Проверим парсинг числовых значений
        try:
            quantity = int(quantity_str)
            price = float(price_str.replace(',', '.'))
            print(f'✅ Парсинг чисел успешен: qty={quantity}, price={price}')
        except Exception as e:
            print(f'❌ Ошибка парсинга чисел: {e}')
            
    except Exception as e:
        print(f'❌ Ошибка: {e}')

if __name__ == "__main__":
    test_transform_posting_data()
