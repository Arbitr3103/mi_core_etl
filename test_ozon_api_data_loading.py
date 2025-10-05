#!/usr/bin/env python3
"""
Тест загрузки данных из API Ozon в базу данных
Период: 29.09.2025 - 05.10.2025

Проверяет:
- Подключение к БД
- Имитацию API запросов к Ozon
- Загрузку данных в таблицы
- Валидацию загруженных данных
"""

import mysql.connector
import requests
import json
import os
from datetime import datetime, timedelta
from typing import Dict, List, Any
import time

class OzonAPITester:
    def __init__(self):
        self.db_config = {
            'host': os.getenv('DB_HOST', '127.0.0.1'),
            'database': os.getenv('DB_NAME', 'mi_core_db'),
            'user': os.getenv('DB_USER', 'ingest_user'),
            'password': os.getenv('DB_PASSWORD', 'xK9#mQ7$vN2@pL!rT4wY'),
            'charset': 'utf8mb4'
        }
        
        self.ozon_config = {
            'client_id': os.getenv('OZON_CLIENT_ID', 'test_client_id'),
            'api_key': os.getenv('OZON_API_KEY', 'test_api_key'),
            'base_url': 'https://api-seller.ozon.ru'
        }
        
        self.connection = None
        
    def log(self, message: str, level: str = 'INFO'):
        """Логирование с цветами"""
        colors = {
            'INFO': '\033[0m',      # Default
            'SUCCESS': '\033[32m',  # Green
            'WARNING': '\033[33m',  # Yellow
            'ERROR': '\033[31m'     # Red
        }
        
        color = colors.get(level, colors['INFO'])
        reset = '\033[0m'
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        
        print(f"{color}[{timestamp}] {message}{reset}")
    
    def connect_to_database(self) -> bool:
        """Подключение к базе данных"""
        try:
            self.connection = mysql.connector.connect(**self.db_config)
            self.log("✅ Подключение к БД успешно", 'SUCCESS')
            return True
        except mysql.connector.Error as e:
            self.log(f"❌ Ошибка подключения к БД: {e}", 'ERROR')
            return False
    
    def check_tables_exist(self) -> bool:
        """Проверка существования необходимых таблиц"""
        required_tables = [
            'ozon_api_settings',
            'ozon_funnel_data',
            'ozon_demographics', 
            'ozon_campaigns'
        ]
        
        cursor = self.connection.cursor()
        
        for table in required_tables:
            try:
                cursor.execute(f"SHOW TABLES LIKE '{table}'")
                if cursor.fetchone():
                    self.log(f"✅ Таблица {table} существует", 'SUCCESS')
                else:
                    self.log(f"❌ Таблица {table} не найдена", 'ERROR')
                    return False
            except mysql.connector.Error as e:
                self.log(f"❌ Ошибка проверки таблицы {table}: {e}", 'ERROR')
                return False
        
        cursor.close()
        return True
    
    def simulate_ozon_api_call(self, endpoint: str, data: Dict) -> Dict:
        """Имитация вызова API Ozon (поскольку у нас нет реальных ключей)"""
        self.log(f"🔄 Имитация API вызова: {endpoint}", 'INFO')
        
        # Имитируем задержку API
        time.sleep(0.5)
        
        if endpoint == '/v1/analytics/funnel':
            return self.generate_mock_funnel_data(data)
        elif endpoint == '/v1/analytics/demographics':
            return self.generate_mock_demographics_data(data)
        elif endpoint == '/v1/analytics/campaigns':
            return self.generate_mock_campaigns_data(data)
        else:
            return {'error': 'Unknown endpoint'}
    
    def generate_mock_funnel_data(self, request_data: Dict) -> Dict:
        """Генерация тестовых данных воронки продаж"""
        return {
            'success': True,
            'data': [
                {
                    'product_id': 'OZON_PRODUCT_001',
                    'campaign_id': 'OZON_CAMPAIGN_001',
                    'views': 8500,
                    'cart_additions': 1275,
                    'orders': 383
                },
                {
                    'product_id': 'OZON_PRODUCT_002', 
                    'campaign_id': 'OZON_CAMPAIGN_002',
                    'views': 6200,
                    'cart_additions': 930,
                    'orders': 279
                },
                {
                    'product_id': 'OZON_PRODUCT_003',
                    'campaign_id': 'OZON_CAMPAIGN_001',
                    'views': 4100,
                    'cart_additions': 615,
                    'orders': 185
                }
            ]
        }
    
    def generate_mock_demographics_data(self, request_data: Dict) -> Dict:
        """Генерация тестовых демографических данных"""
        return {
            'success': True,
            'data': [
                {
                    'age_group': '25-34',
                    'gender': 'male',
                    'region': 'Moscow',
                    'orders_count': 245,
                    'revenue': 122500.00
                },
                {
                    'age_group': '35-44',
                    'gender': 'female', 
                    'region': 'Saint Petersburg',
                    'orders_count': 198,
                    'revenue': 99000.00
                },
                {
                    'age_group': '25-34',
                    'gender': 'female',
                    'region': 'Moscow',
                    'orders_count': 312,
                    'revenue': 156000.00
                },
                {
                    'age_group': '45-54',
                    'gender': 'male',
                    'region': 'Novosibirsk',
                    'orders_count': 156,
                    'revenue': 78000.00
                }
            ]
        }
    
    def generate_mock_campaigns_data(self, request_data: Dict) -> Dict:
        """Генерация тестовых данных рекламных кампаний"""
        return {
            'success': True,
            'data': [
                {
                    'campaign_id': 'OZON_CAMPAIGN_001',
                    'campaign_name': 'Продвижение электроники',
                    'impressions': 125000,
                    'clicks': 6250,
                    'spend': 12500.00,
                    'orders': 312,
                    'revenue': 31200.00
                },
                {
                    'campaign_id': 'OZON_CAMPAIGN_002',
                    'campaign_name': 'Товары для дома',
                    'impressions': 98000,
                    'clicks': 4900,
                    'spend': 9800.00,
                    'orders': 245,
                    'revenue': 24500.00
                }
            ]
        }
    
    def process_funnel_data(self, api_response: Dict, date_from: str, date_to: str) -> List[Dict]:
        """Обработка данных воронки продаж"""
        processed_data = []
        
        for item in api_response.get('data', []):
            views = max(0, int(item.get('views', 0)))
            cart_additions = max(0, int(item.get('cart_additions', 0)))
            orders = max(0, int(item.get('orders', 0)))
            
            # Проверяем логическую корректность
            if cart_additions > views and views > 0:
                cart_additions = views
            if orders > cart_additions and cart_additions > 0:
                orders = cart_additions
            
            # Рассчитываем конверсии
            conv_view_to_cart = round((cart_additions / views) * 100, 2) if views > 0 else 0.0
            conv_cart_to_order = round((orders / cart_additions) * 100, 2) if cart_additions > 0 else 0.0
            conv_overall = round((orders / views) * 100, 2) if views > 0 else 0.0
            
            # Ограничиваем конверсии максимумом 100%
            conv_view_to_cart = min(100.0, conv_view_to_cart)
            conv_cart_to_order = min(100.0, conv_cart_to_order)
            conv_overall = min(100.0, conv_overall)
            
            processed_data.append({
                'date_from': date_from,
                'date_to': date_to,
                'product_id': item.get('product_id'),
                'campaign_id': item.get('campaign_id'),
                'views': views,
                'cart_additions': cart_additions,
                'orders': orders,
                'conversion_view_to_cart': conv_view_to_cart,
                'conversion_cart_to_order': conv_cart_to_order,
                'conversion_overall': conv_overall,
                'cached_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            })
        
        return processed_data
    
    def process_demographics_data(self, api_response: Dict, date_from: str, date_to: str) -> List[Dict]:
        """Обработка демографических данных"""
        processed_data = []
        
        for item in api_response.get('data', []):
            processed_data.append({
                'date_from': date_from,
                'date_to': date_to,
                'age_group': item.get('age_group'),
                'gender': item.get('gender'),
                'region': item.get('region'),
                'orders_count': max(0, int(item.get('orders_count', 0))),
                'revenue': max(0.0, float(item.get('revenue', 0.0))),
                'cached_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            })
        
        return processed_data
    
    def process_campaigns_data(self, api_response: Dict, date_from: str, date_to: str) -> List[Dict]:
        """Обработка данных рекламных кампаний"""
        processed_data = []
        
        for item in api_response.get('data', []):
            impressions = int(item.get('impressions', 0))
            clicks = int(item.get('clicks', 0))
            spend = float(item.get('spend', 0.0))
            orders = int(item.get('orders', 0))
            revenue = float(item.get('revenue', 0.0))
            
            # Рассчитываем метрики
            ctr = round((clicks / impressions) * 100, 2) if impressions > 0 else 0.0
            cpc = round(spend / clicks, 2) if clicks > 0 else 0.0
            roas = round(revenue / spend, 2) if spend > 0 else 0.0
            
            processed_data.append({
                'campaign_id': item.get('campaign_id'),
                'campaign_name': item.get('campaign_name'),
                'date_from': date_from,
                'date_to': date_to,
                'impressions': impressions,
                'clicks': clicks,
                'spend': spend,
                'orders': orders,
                'revenue': revenue,
                'ctr': ctr,
                'cpc': cpc,
                'roas': roas,
                'cached_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            })
        
        return processed_data
    
    def save_funnel_data(self, data: List[Dict]) -> bool:
        """Сохранение данных воронки в БД"""
        cursor = self.connection.cursor()
        
        query = """
        INSERT INTO ozon_funnel_data 
        (date_from, date_to, product_id, campaign_id, views, cart_additions, orders,
         conversion_view_to_cart, conversion_cart_to_order, conversion_overall, cached_at)
        VALUES (%(date_from)s, %(date_to)s, %(product_id)s, %(campaign_id)s, %(views)s, 
                %(cart_additions)s, %(orders)s, %(conversion_view_to_cart)s, 
                %(conversion_cart_to_order)s, %(conversion_overall)s, %(cached_at)s)
        ON DUPLICATE KEY UPDATE
        views = VALUES(views),
        cart_additions = VALUES(cart_additions),
        orders = VALUES(orders),
        conversion_view_to_cart = VALUES(conversion_view_to_cart),
        conversion_cart_to_order = VALUES(conversion_cart_to_order),
        conversion_overall = VALUES(conversion_overall),
        cached_at = VALUES(cached_at)
        """
        
        try:
            cursor.executemany(query, data)
            self.connection.commit()
            self.log(f"✅ Сохранено {len(data)} записей воронки продаж", 'SUCCESS')
            cursor.close()
            return True
        except mysql.connector.Error as e:
            self.log(f"❌ Ошибка сохранения данных воронки: {e}", 'ERROR')
            cursor.close()
            return False
    
    def save_demographics_data(self, data: List[Dict]) -> bool:
        """Сохранение демографических данных в БД"""
        cursor = self.connection.cursor()
        
        query = """
        INSERT INTO ozon_demographics 
        (date_from, date_to, age_group, gender, region, orders_count, revenue, cached_at)
        VALUES (%(date_from)s, %(date_to)s, %(age_group)s, %(gender)s, %(region)s,
                %(orders_count)s, %(revenue)s, %(cached_at)s)
        ON DUPLICATE KEY UPDATE
        orders_count = VALUES(orders_count),
        revenue = VALUES(revenue),
        cached_at = VALUES(cached_at)
        """
        
        try:
            cursor.executemany(query, data)
            self.connection.commit()
            self.log(f"✅ Сохранено {len(data)} демографических записей", 'SUCCESS')
            cursor.close()
            return True
        except mysql.connector.Error as e:
            self.log(f"❌ Ошибка сохранения демографических данных: {e}", 'ERROR')
            cursor.close()
            return False
    
    def save_campaigns_data(self, data: List[Dict]) -> bool:
        """Сохранение данных кампаний в БД"""
        cursor = self.connection.cursor()
        
        query = """
        INSERT INTO ozon_campaigns 
        (campaign_id, campaign_name, date_from, date_to, impressions, clicks, spend,
         orders, revenue, ctr, cpc, roas, cached_at)
        VALUES (%(campaign_id)s, %(campaign_name)s, %(date_from)s, %(date_to)s, 
                %(impressions)s, %(clicks)s, %(spend)s, %(orders)s, %(revenue)s,
                %(ctr)s, %(cpc)s, %(roas)s, %(cached_at)s)
        ON DUPLICATE KEY UPDATE
        impressions = VALUES(impressions),
        clicks = VALUES(clicks),
        spend = VALUES(spend),
        orders = VALUES(orders),
        revenue = VALUES(revenue),
        ctr = VALUES(ctr),
        cpc = VALUES(cpc),
        roas = VALUES(roas),
        cached_at = VALUES(cached_at)
        """
        
        try:
            cursor.executemany(query, data)
            self.connection.commit()
            self.log(f"✅ Сохранено {len(data)} записей кампаний", 'SUCCESS')
            cursor.close()
            return True
        except mysql.connector.Error as e:
            self.log(f"❌ Ошибка сохранения данных кампаний: {e}", 'ERROR')
            cursor.close()
            return False
    
    def validate_loaded_data(self, date_from: str, date_to: str) -> bool:
        """Валидация загруженных данных"""
        cursor = self.connection.cursor()
        validation_errors = 0
        
        self.log("🔍 Проверка валидности загруженных данных...", 'INFO')
        
        # Проверка данных воронки
        cursor.execute("""
            SELECT product_id, views, cart_additions, orders, 
                   conversion_view_to_cart, conversion_cart_to_order, conversion_overall
            FROM ozon_funnel_data 
            WHERE date_from >= %s AND date_to <= %s
        """, (date_from, date_to))
        
        funnel_data = cursor.fetchall()
        
        for row in funnel_data:
            product_id, views, cart_additions, orders, conv_vtc, conv_cto, conv_overall = row
            
            if cart_additions > views:
                self.log(f"❌ Ошибка: добавления в корзину ({cart_additions}) > просмотров ({views}) для {product_id}", 'ERROR')
                validation_errors += 1
            
            if orders > cart_additions:
                self.log(f"❌ Ошибка: заказы ({orders}) > добавлений в корзину ({cart_additions}) для {product_id}", 'ERROR')
                validation_errors += 1
            
            if conv_overall > 100:
                self.log(f"❌ Ошибка: общая конверсия ({conv_overall}%) > 100% для {product_id}", 'ERROR')
                validation_errors += 1
        
        # Проверка демографических данных
        cursor.execute("""
            SELECT age_group, gender, region, orders_count, revenue
            FROM ozon_demographics 
            WHERE date_from >= %s AND date_to <= %s
        """, (date_from, date_to))
        
        demo_data = cursor.fetchall()
        
        for row in demo_data:
            age_group, gender, region, orders_count, revenue = row
            
            if orders_count < 0 or revenue < 0:
                self.log(f"❌ Ошибка: отрицательные значения в демографии: {age_group}, {gender}, {region}", 'ERROR')
                validation_errors += 1
        
        # Проверка кампаний
        cursor.execute("""
            SELECT campaign_id, impressions, clicks, ctr, spend, revenue, roas
            FROM ozon_campaigns 
            WHERE date_from >= %s AND date_to <= %s
        """, (date_from, date_to))
        
        campaign_data = cursor.fetchall()
        
        for row in campaign_data:
            campaign_id, impressions, clicks, ctr, spend, revenue, roas = row
            
            if clicks > impressions:
                self.log(f"❌ Ошибка: клики ({clicks}) > показов ({impressions}) для {campaign_id}", 'ERROR')
                validation_errors += 1
            
            if ctr > 100:
                self.log(f"❌ Ошибка: CTR ({ctr}%) > 100% для {campaign_id}", 'ERROR')
                validation_errors += 1
        
        cursor.close()
        
        if validation_errors == 0:
            self.log("✅ Все данные прошли валидацию", 'SUCCESS')
            return True
        else:
            self.log(f"❌ Найдено {validation_errors} ошибок валидации", 'ERROR')
            return False
    
    def get_data_summary(self, date_from: str, date_to: str):
        """Получение сводки по загруженным данным"""
        cursor = self.connection.cursor()
        
        # Сводка по воронке
        cursor.execute("""
            SELECT COUNT(*) as count, 
                   SUM(views) as total_views,
                   SUM(cart_additions) as total_cart,
                   SUM(orders) as total_orders,
                   AVG(conversion_overall) as avg_conversion
            FROM ozon_funnel_data 
            WHERE date_from >= %s AND date_to <= %s
        """, (date_from, date_to))
        
        funnel_summary = cursor.fetchone()
        
        self.log("📊 Сводка по воронке продаж:", 'INFO')
        self.log(f"   - Записей: {funnel_summary[0]}", 'INFO')
        self.log(f"   - Просмотры: {funnel_summary[1]:,}", 'INFO')
        self.log(f"   - В корзину: {funnel_summary[2]:,}", 'INFO')
        self.log(f"   - Заказы: {funnel_summary[3]:,}", 'INFO')
        self.log(f"   - Средняя конверсия: {funnel_summary[4]:.2f}%", 'INFO')
        
        # Сводка по демографии
        cursor.execute("""
            SELECT COUNT(*) as count,
                   SUM(orders_count) as total_orders,
                   SUM(revenue) as total_revenue
            FROM ozon_demographics 
            WHERE date_from >= %s AND date_to <= %s
        """, (date_from, date_to))
        
        demo_summary = cursor.fetchone()
        
        self.log("\n👥 Сводка по демографии:", 'INFO')
        self.log(f"   - Записей: {demo_summary[0]}", 'INFO')
        self.log(f"   - Заказы: {demo_summary[1]:,}", 'INFO')
        self.log(f"   - Выручка: {demo_summary[2]:,.2f} руб.", 'INFO')
        
        # Сводка по кампаниям
        cursor.execute("""
            SELECT COUNT(*) as count,
                   SUM(impressions) as total_impressions,
                   SUM(clicks) as total_clicks,
                   SUM(spend) as total_spend,
                   SUM(revenue) as total_revenue,
                   AVG(ctr) as avg_ctr,
                   AVG(roas) as avg_roas
            FROM ozon_campaigns 
            WHERE date_from >= %s AND date_to <= %s
        """, (date_from, date_to))
        
        campaign_summary = cursor.fetchone()
        
        self.log("\n📈 Сводка по кампаниям:", 'INFO')
        self.log(f"   - Записей: {campaign_summary[0]}", 'INFO')
        self.log(f"   - Показы: {campaign_summary[1]:,}", 'INFO')
        self.log(f"   - Клики: {campaign_summary[2]:,}", 'INFO')
        self.log(f"   - Расходы: {campaign_summary[3]:,.2f} руб.", 'INFO')
        self.log(f"   - Доходы: {campaign_summary[4]:,.2f} руб.", 'INFO')
        self.log(f"   - Средний CTR: {campaign_summary[5]:.2f}%", 'INFO')
        self.log(f"   - Средний ROAS: {campaign_summary[6]:.2f}", 'INFO')
        
        cursor.close()
    
    def run_test(self):
        """Основной метод тестирования"""
        self.log("🚀 ТЕСТ ЗАГРУЗКИ ДАННЫХ OZON ANALYTICS", 'INFO')
        self.log("Период: 29.09.2025 - 05.10.2025", 'INFO')
        self.log("=" * 50, 'INFO')
        
        date_from = '2025-09-29'
        date_to = '2025-10-05'
        
        # 1. Подключение к БД
        self.log("\n1️⃣ Подключение к базе данных...", 'INFO')
        if not self.connect_to_database():
            return False
        
        # 2. Проверка таблиц
        self.log("\n2️⃣ Проверка структуры таблиц...", 'INFO')
        if not self.check_tables_exist():
            return False
        
        # 3. Получение данных из API (имитация)
        self.log("\n3️⃣ Получение данных из API Ozon...", 'INFO')
        
        # Воронка продаж
        funnel_response = self.simulate_ozon_api_call('/v1/analytics/funnel', {
            'date_from': date_from,
            'date_to': date_to
        })
        
        if funnel_response.get('success'):
            funnel_data = self.process_funnel_data(funnel_response, date_from, date_to)
            self.save_funnel_data(funnel_data)
        
        # Демографические данные
        demo_response = self.simulate_ozon_api_call('/v1/analytics/demographics', {
            'date_from': date_from,
            'date_to': date_to
        })
        
        if demo_response.get('success'):
            demo_data = self.process_demographics_data(demo_response, date_from, date_to)
            self.save_demographics_data(demo_data)
        
        # Рекламные кампании
        campaigns_response = self.simulate_ozon_api_call('/v1/analytics/campaigns', {
            'date_from': date_from,
            'date_to': date_to
        })
        
        if campaigns_response.get('success'):
            campaigns_data = self.process_campaigns_data(campaigns_response, date_from, date_to)
            self.save_campaigns_data(campaigns_data)
        
        # 4. Валидация данных
        self.log("\n4️⃣ Валидация загруженных данных...", 'INFO')
        validation_success = self.validate_loaded_data(date_from, date_to)
        
        # 5. Сводка по данным
        self.log("\n5️⃣ Сводка по загруженным данным...", 'INFO')
        self.get_data_summary(date_from, date_to)
        
        # Закрытие соединения
        if self.connection:
            self.connection.close()
        
        self.log("\n" + "=" * 50, 'INFO')
        if validation_success:
            self.log("🎉 ТЕСТ ЗАВЕРШЕН УСПЕШНО!", 'SUCCESS')
            self.log("Данные за период 29.09.2025 - 05.10.2025 загружены и проверены.", 'SUCCESS')
        else:
            self.log("❌ ТЕСТ ЗАВЕРШЕН С ОШИБКАМИ!", 'ERROR')
            self.log("Обнаружены проблемы с валидацией данных.", 'ERROR')
        
        return validation_success

if __name__ == "__main__":
    tester = OzonAPITester()
    success = tester.run_test()
    exit(0 if success else 1)