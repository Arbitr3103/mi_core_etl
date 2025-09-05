#!/usr/bin/env python3
"""
Тест для проверки исправленной логики извлечения ext_id из CSV заказов
"""

import sys
import os
sys.path.append(os.path.join(os.path.dirname('.'), 'importers'))

from ozon_importer import request_report, get_report_by_code
from datetime import datetime, timedelta
import json
import csv
import io

def test_ext_id_extraction():
    """Тестируем извлечение ext_id из CSV заказов"""
    
    # Берем данные за позавчерашний день
    target_date = datetime.now() - timedelta(days=2)
    start_date = target_date.strftime('%Y-%m-%d')
    end_date = target_date.strftime('%Y-%m-%d')

    print(f'🧪 Тестируем извлечение ext_id из CSV заказов за {start_date}')
    
    try:
        # Получаем заказы напрямую через API отчетов (без сохранения в БД)
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
        
        # Тестируем логику извлечения ext_id
        sample_posting = postings[0]
        
        print('\n📋 Структура первого заказа:')
        for key, value in list(sample_posting.items())[:5]:
            print(f'  {key}: {value}')
        
        # Проверяем извлечение ext_id по новой логике
        ext_id_new = sample_posting.get('Номер заказа', sample_posting.get('posting_number', ''))
        ext_id_old = sample_posting.get('posting_number', sample_posting.get('operation_id', ''))
        
        print(f'\n🔍 Тест извлечения ext_id:')
        print(f'  Старая логика (posting_number): "{ext_id_old}"')
        print(f'  Новая логика (Номер заказа): "{ext_id_new}"')
        
        if ext_id_new:
            print('✅ Новая логика работает корректно - ext_id найден!')
        else:
            print('❌ Новая логика не работает - ext_id пустой!')
            
        # Проверим несколько заказов
        print(f'\n📊 Проверка ext_id для первых 5 заказов:')
        for i, posting in enumerate(postings[:5]):
            ext_id = posting.get('Номер заказа', '')
            print(f'  Заказ {i+1}: "{ext_id}"')
            
        # Тестируем логику save_raw_events без БД
        print(f'\n🧪 Тест логики формирования raw_data:')
        event_type = 'ozon_posting'
        for i, event in enumerate(postings[:3]):
            # Повторяем логику из save_raw_events
            if event_type == 'ozon_posting':
                ext_id = event.get('Номер заказа', event.get('posting_number', ''))
            else:
                ext_id = event.get('posting_number', event.get('operation_id', ''))
            
            raw_data = {
                'ext_id': ext_id,
                'event_type': event_type,
                'payload': json.dumps(event, ensure_ascii=False)[:100] + '...',  # Обрезаем для читаемости
                'ingested_at': datetime.now().isoformat()
            }
            
            print(f'  Заказ {i+1}:')
            print(f'    ext_id: "{raw_data["ext_id"]}"')
            print(f'    event_type: {raw_data["event_type"]}')
            print(f'    payload_preview: {raw_data["payload"]}')
            
    except Exception as e:
        print(f'❌ Ошибка: {e}')

if __name__ == "__main__":
    test_ext_id_extraction()
