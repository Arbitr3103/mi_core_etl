#!/bin/bash

echo "🔍 ПРОВЕРКА СООТВЕТСТВИЯ ДАННЫХ OZON API"
echo "========================================"

echo "1️⃣ Данные из дашборда (БД):"
echo "----------------------------"
cd /var/www/mi_core_api
python3 -c "
import sys
sys.path.append('.')
try:
    from ozon_importer import connect_to_db
    conn = connect_to_db()
    cursor = conn.cursor(dictionary=True)
    
    # Общая статистика
    cursor.execute('''
        SELECT 
            COUNT(*) as total_records,
            COUNT(DISTINCT product_id) as unique_products,
            SUM(quantity_present) as total_present,
            SUM(quantity_reserved) as total_reserved
        FROM inventory 
        WHERE source = \"Ozon\"
    ''')
    stats = cursor.fetchone()
    print(f'📊 Статистика из БД:')
    print(f'   Записей: {stats[\"total_records\"]}')
    print(f'   Товаров: {stats[\"unique_products\"]}')
    print(f'   Доступно: {stats[\"total_present\"]}')
    print(f'   Зарезервировано: {stats[\"total_reserved\"]}')
    
    # Топ товары
    cursor.execute('''
        SELECT product_id, quantity_present, quantity_reserved
        FROM inventory 
        WHERE source = \"Ozon\" AND quantity_present > 0
        ORDER BY quantity_present DESC
        LIMIT 5
    ''')
    top_products = cursor.fetchall()
    print(f'\\n📦 Топ-5 товаров из БД:')
    for product in top_products:
        print(f'   ID {product[\"product_id\"]}: {product[\"quantity_present\"]} доступно, {product[\"quantity_reserved\"]} резерв')
    
    cursor.close()
    conn.close()
except Exception as e:
    print(f'❌ Ошибка БД: {e}')
"

echo ""
echo "2️⃣ Свежие данные из Ozon API:"
echo "------------------------------"
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
    'limit': 10
}

try:
    response = requests.post(url, json=payload, headers=headers)
    response.raise_for_status()
    data = response.json()
    
    print(f'📡 Свежие данные из Ozon API:')
    print(f'   Всего товаров в системе: {data.get(\"total\", 0)}')
    print(f'   Получено в запросе: {len(data.get(\"items\", []))}')
    
    total_present = 0
    total_reserved = 0
    products_with_stock = 0
    
    print(f'\\n📦 Примеры товаров из API:')
    for item in data.get('items', [])[:5]:
        offer_id = item.get('offer_id', 'N/A')
        for stock in item.get('stocks', []):
            present = stock.get('present', 0)
            reserved = stock.get('reserved', 0)
            total_present += present
            total_reserved += reserved
            if present > 0:
                products_with_stock += 1
            print(f'   {offer_id}: {present} доступно, {reserved} резерв')
    
    print(f'\\n📊 Суммарно по выборке из {len(data.get(\"items\", []))} товаров:')
    print(f'   Доступно: {total_present}')
    print(f'   Зарезервировано: {total_reserved}')
    print(f'   Товаров с остатками: {products_with_stock}')
    
except Exception as e:
    print(f'❌ Ошибка API: {e}')
"

echo ""
echo "3️⃣ Запуск импортера для обновления:"
echo "-----------------------------------"
echo "Хотите запустить импортер для получения свежих данных? (y/n)"
read -r answer
if [ "$answer" = "y" ] || [ "$answer" = "Y" ]; then
    echo "🔄 Запуск импортера..."
    python3 importers/stock_importer.py
    echo ""
    echo "✅ Импорт завершен! Проверьте дашборд для обновленных данных."
else
    echo "ℹ️ Импорт пропущен"
fi

echo ""
echo "🌐 Дашборд: https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"
echo "✅ Проверка завершена!"