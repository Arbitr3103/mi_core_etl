#!/bin/bash

echo "🔍 АНАЛИЗ СТРУКТУРЫ ДАННЫХ"
echo "========================="

cd /var/www/mi_core_api

echo "1️⃣ Проверяем что сохраняется в БД:"
echo "-----------------------------------"
python3 -c "
import sys
sys.path.append('.')
try:
    from ozon_importer import connect_to_db
    conn = connect_to_db()
    cursor = conn.cursor(dictionary=True)
    
    print('📊 Структура таблицы inventory:')
    cursor.execute('DESCRIBE inventory')
    columns = cursor.fetchall()
    for col in columns:
        print(f'   {col[\"Field\"]}: {col[\"Type\"]}')
    
    print('\\n📦 Примеры данных из inventory:')
    cursor.execute('SELECT * FROM inventory WHERE source=\"Ozon\" LIMIT 5')
    rows = cursor.fetchall()
    for row in rows:
        print(f'   ID: {row[\"product_id\"]}, Склад: {row[\"warehouse_name\"]}, Тип: {row[\"stock_type\"]}, Остаток: {row[\"quantity_present\"]}')
    
    print('\\n🏷️ Структура таблицы dim_products:')
    cursor.execute('DESCRIBE dim_products')
    columns = cursor.fetchall()
    for col in columns:
        print(f'   {col[\"Field\"]}: {col[\"Type\"]}')
    
    print('\\n📋 Примеры товаров из dim_products:')
    cursor.execute('SELECT id, name, sku_ozon FROM dim_products WHERE sku_ozon IS NOT NULL LIMIT 5')
    rows = cursor.fetchall()
    for row in rows:
        print(f'   ID: {row[\"id\"]}, Название: {row[\"name\"]}, SKU: {row[\"sku_ozon\"]}')
    
    cursor.close()
    conn.close()
except Exception as e:
    print(f'❌ Ошибка БД: {e}')
"

echo ""
echo "2️⃣ Проверяем что возвращает Ozon API:"
echo "--------------------------------------"
python3 -c "
import requests
import json
import sys
sys.path.append('.')
import config

url = 'https://api-seller.ozon.ru/v4/product/info/stocks'
headers = {
    'Client-Id': config.OZON_CLIENT_ID,
    'Api-Key': config.OZON_API_KEY,
    'Content-Type': 'application/json'
}

payload = {
    'filter': {
        'visibility': 'ALL'
    },
    'limit': 3
}

try:
    response = requests.post(url, json=payload, headers=headers)
    response.raise_for_status()
    data = response.json()
    
    print('📡 Примеры из Ozon API:')
    for item in data.get('items', [])[:3]:
        offer_id = item.get('offer_id', 'N/A')
        product_id = item.get('product_id', 'N/A')
        print(f'\\n   Product ID: {product_id}')
        print(f'   Offer ID (SKU): {offer_id}')
        
        for stock in item.get('stocks', []):
            stock_type = stock.get('type', 'N/A')
            present = stock.get('present', 0)
            warehouse_ids = stock.get('warehouse_ids', [])
            print(f'   Склад: тип={stock_type}, warehouse_ids={warehouse_ids}, остаток={present}')
    
except Exception as e:
    print(f'❌ Ошибка API: {e}')
"

echo ""
echo "3️⃣ Проверяем как импортер обрабатывает данные:"
echo "-----------------------------------------------"
echo "Смотрим код импортера для warehouse_name..."
grep -A 5 -B 5 "warehouse_name" /var/www/mi_core_api/importers/stock_importer.py

echo ""
echo "4️⃣ Проверяем соответствие offer_id и sku_ozon:"
echo "-----------------------------------------------"
python3 -c "
import sys
sys.path.append('.')
try:
    from ozon_importer import connect_to_db
    conn = connect_to_db()
    cursor = conn.cursor(dictionary=True)
    
    print('🔍 Проверяем соответствие offer_id из API и sku_ozon в БД:')
    cursor.execute('''
        SELECT p.id, p.name, p.sku_ozon, i.warehouse_name, i.quantity_present
        FROM dim_products p
        JOIN inventory i ON p.id = i.product_id
        WHERE i.source = \"Ozon\" AND p.sku_ozon IS NOT NULL
        LIMIT 5
    ''')
    rows = cursor.fetchall()
    for row in rows:
        print(f'   ID: {row[\"id\"]}, SKU: {row[\"sku_ozon\"]}, Название: {row[\"name\"][:30]}..., Склад: {row[\"warehouse_name\"]}')
    
    cursor.close()
    conn.close()
except Exception as e:
    print(f'❌ Ошибка: {e}')
"

echo ""
echo "✅ Анализ завершен!"