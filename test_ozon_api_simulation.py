#!/usr/bin/env python3
"""
Симуляция тестирования загрузки данных из API Ozon
Период: 29.09.2025 - 05.10.2025

Имитирует полный процесс:
- API запросы к Ozon
- Обработку данных
- Валидацию бизнес-логики
- Подготовку SQL запросов для загрузки в БД
"""

import json
import time
from datetime import datetime, timedelta
from typing import Dict, List, Any

class OzonAPISimulator:
    def __init__(self):
        self.ozon_config = {
            'client_id': 'test_client_id',
            'api_key': 'test_api_key',
            'base_url': 'https://api-seller.ozon.ru'
        }
        
        self.processed_data = {
            'funnel': [],
            'demographics': [],
            'campaigns': []
        }
        
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
    
    def simulate_ozon_api_call(self, endpoint: str, data: Dict) -> Dict:
        """Имитация вызова API Ozon"""
        self.log(f"🔄 API запрос: {endpoint}", 'INFO')
        self.log(f"   Параметры: {data}", 'INFO')
        
        # Имитируем задержку API
        time.sleep(0.3)
        
        if endpoint == '/v1/analytics/funnel':
            return self.generate_realistic_funnel_data(data)
        elif endpoint == '/v1/analytics/demographics':
            return self.generate_realistic_demographics_data(data)
        elif endpoint == '/v1/analytics/campaigns':
            return self.generate_realistic_campaigns_data(data)
        else:
            return {'error': 'Unknown endpoint'}
    
    def generate_realistic_funnel_data(self, request_data: Dict) -> Dict:
        """Генерация реалистичных данных воронки продаж за неделю"""
        self.log("📊 Генерация данных воронки продаж...", 'INFO')
        
        # Реалистичные данные для разных категорий товаров
        products_data = [
            {
                'product_id': 'ELECTRONICS_SMARTPHONE_001',
                'campaign_id': 'PROMO_ELECTRONICS_Q4',
                'views': 15420,
                'cart_additions': 1850,  # ~12% конверсия
                'orders': 463           # ~25% конверсия из корзины
            },
            {
                'product_id': 'HOME_KITCHEN_APPLIANCE_002',
                'campaign_id': 'PROMO_HOME_AUTUMN',
                'views': 8750,
                'cart_additions': 1225,  # ~14% конверсия
                'orders': 294           # ~24% конверсия из корзины
            },
            {
                'product_id': 'FASHION_WINTER_JACKET_003',
                'campaign_id': 'PROMO_FASHION_WINTER',
                'views': 12300,
                'cart_additions': 1476,  # ~12% конверсия
                'orders': 369           # ~25% конверсия из корзины
            },
            {
                'product_id': 'BOOKS_BESTSELLER_004',
                'campaign_id': 'PROMO_BOOKS_EDUCATION',
                'views': 5680,
                'cart_additions': 852,   # ~15% конверсия
                'orders': 213           # ~25% конверсия из корзины
            },
            {
                'product_id': 'SPORTS_FITNESS_EQUIPMENT_005',
                'campaign_id': 'PROMO_SPORTS_HEALTH',
                'views': 9240,
                'cart_additions': 1109,  # ~12% конверсия
                'orders': 277           # ~25% конверсия из корзины
            }
        ]
        
        return {
            'success': True,
            'data': products_data,
            'metadata': {
                'total_products': len(products_data),
                'date_range': f"{request_data['date_from']} - {request_data['date_to']}",
                'api_version': 'v1'
            }
        }
    
    def generate_realistic_demographics_data(self, request_data: Dict) -> Dict:
        """Генерация реалистичных демографических данных"""
        self.log("👥 Генерация демографических данных...", 'INFO')
        
        demographics_data = [
            # Москва
            {
                'age_group': '25-34',
                'gender': 'male',
                'region': 'Moscow',
                'orders_count': 342,
                'revenue': 171000.00
            },
            {
                'age_group': '25-34',
                'gender': 'female',
                'region': 'Moscow',
                'orders_count': 398,
                'revenue': 199000.00
            },
            {
                'age_group': '35-44',
                'gender': 'male',
                'region': 'Moscow',
                'orders_count': 287,
                'revenue': 143500.00
            },
            {
                'age_group': '35-44',
                'gender': 'female',
                'region': 'Moscow',
                'orders_count': 324,
                'revenue': 162000.00
            },
            
            # Санкт-Петербург
            {
                'age_group': '25-34',
                'gender': 'male',
                'region': 'Saint Petersburg',
                'orders_count': 198,
                'revenue': 99000.00
            },
            {
                'age_group': '25-34',
                'gender': 'female',
                'region': 'Saint Petersburg',
                'orders_count': 234,
                'revenue': 117000.00
            },
            {
                'age_group': '35-44',
                'gender': 'male',
                'region': 'Saint Petersburg',
                'orders_count': 167,
                'revenue': 83500.00
            },
            {
                'age_group': '35-44',
                'gender': 'female',
                'region': 'Saint Petersburg',
                'orders_count': 189,
                'revenue': 94500.00
            },
            
            # Другие регионы
            {
                'age_group': '25-34',
                'gender': 'male',
                'region': 'Novosibirsk',
                'orders_count': 89,
                'revenue': 44500.00
            },
            {
                'age_group': '35-44',
                'gender': 'female',
                'region': 'Yekaterinburg',
                'orders_count': 76,
                'revenue': 38000.00
            },
            {
                'age_group': '45-54',
                'gender': 'male',
                'region': 'Kazan',
                'orders_count': 54,
                'revenue': 27000.00
            },
            {
                'age_group': '18-24',
                'gender': 'female',
                'region': 'Rostov-on-Don',
                'orders_count': 43,
                'revenue': 21500.00
            }
        ]
        
        return {
            'success': True,
            'data': demographics_data,
            'metadata': {
                'total_segments': len(demographics_data),
                'regions_count': len(set(item['region'] for item in demographics_data)),
                'date_range': f"{request_data['date_from']} - {request_data['date_to']}"
            }
        }
    
    def generate_realistic_campaigns_data(self, request_data: Dict) -> Dict:
        """Генерация реалистичных данных рекламных кампаний"""
        self.log("📈 Генерация данных рекламных кампаний...", 'INFO')
        
        campaigns_data = [
            {
                'campaign_id': 'PROMO_ELECTRONICS_Q4',
                'campaign_name': 'Электроника - Осенние скидки',
                'impressions': 245000,
                'clicks': 12250,      # CTR 5%
                'spend': 24500.00,    # CPC 2.00
                'orders': 463,        # Конверсия 3.78%
                'revenue': 92600.00   # ROAS 3.78
            },
            {
                'campaign_id': 'PROMO_HOME_AUTUMN',
                'campaign_name': 'Товары для дома - Уютная осень',
                'impressions': 180000,
                'clicks': 9000,       # CTR 5%
                'spend': 18000.00,    # CPC 2.00
                'orders': 294,        # Конверсия 3.27%
                'revenue': 58800.00   # ROAS 3.27
            },
            {
                'campaign_id': 'PROMO_FASHION_WINTER',
                'campaign_name': 'Мода - Зимняя коллекция',
                'impressions': 320000,
                'clicks': 16000,      # CTR 5%
                'spend': 32000.00,    # CPC 2.00
                'orders': 369,        # Конверсия 2.31%
                'revenue': 73800.00   # ROAS 2.31
            },
            {
                'campaign_id': 'PROMO_BOOKS_EDUCATION',
                'campaign_name': 'Книги - Образование и развитие',
                'impressions': 95000,
                'clicks': 4750,       # CTR 5%
                'spend': 9500.00,     # CPC 2.00
                'orders': 213,        # Конверсия 4.48%
                'revenue': 21300.00   # ROAS 2.24
            },
            {
                'campaign_id': 'PROMO_SPORTS_HEALTH',
                'campaign_name': 'Спорт и здоровье - Активная осень',
                'impressions': 155000,
                'clicks': 7750,       # CTR 5%
                'spend': 15500.00,    # CPC 2.00
                'orders': 277,        # Конверсия 3.57%
                'revenue': 55400.00   # ROAS 3.57
            }
        ]
        
        return {
            'success': True,
            'data': campaigns_data,
            'metadata': {
                'total_campaigns': len(campaigns_data),
                'date_range': f"{request_data['date_from']} - {request_data['date_to']}",
                'total_spend': sum(item['spend'] for item in campaigns_data),
                'total_revenue': sum(item['revenue'] for item in campaigns_data)
            }
        }
    
    def process_funnel_data(self, api_response: Dict, date_from: str, date_to: str) -> List[Dict]:
        """Обработка данных воронки продаж с валидацией"""
        processed_data = []
        
        self.log("🔄 Обработка данных воронки продаж...", 'INFO')
        
        for item in api_response.get('data', []):
            views = max(0, int(item.get('views', 0)))
            cart_additions = max(0, int(item.get('cart_additions', 0)))
            orders = max(0, int(item.get('orders', 0)))
            
            # Проверяем и корректируем логическую корректность
            original_cart = cart_additions
            original_orders = orders
            
            if cart_additions > views and views > 0:
                cart_additions = views
                self.log(f"⚠️  Скорректированы добавления в корзину для {item.get('product_id')}: {original_cart} → {cart_additions}", 'WARNING')
            
            if orders > cart_additions and cart_additions > 0:
                orders = cart_additions
                self.log(f"⚠️  Скорректированы заказы для {item.get('product_id')}: {original_orders} → {orders}", 'WARNING')
            
            # Рассчитываем конверсии
            conv_view_to_cart = round((cart_additions / views) * 100, 2) if views > 0 else 0.0
            conv_cart_to_order = round((orders / cart_additions) * 100, 2) if cart_additions > 0 else 0.0
            conv_overall = round((orders / views) * 100, 2) if views > 0 else 0.0
            
            # Ограничиваем конверсии максимумом 100%
            conv_view_to_cart = min(100.0, conv_view_to_cart)
            conv_cart_to_order = min(100.0, conv_cart_to_order)
            conv_overall = min(100.0, conv_overall)
            
            processed_record = {
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
            }
            
            processed_data.append(processed_record)
            
            # Логируем обработанную запись
            self.log(f"   📦 {item.get('product_id')}: {views:,} → {cart_additions:,} → {orders:,} (конверсия: {conv_overall:.2f}%)", 'INFO')
        
        return processed_data
    
    def process_demographics_data(self, api_response: Dict, date_from: str, date_to: str) -> List[Dict]:
        """Обработка демографических данных"""
        processed_data = []
        
        self.log("🔄 Обработка демографических данных...", 'INFO')
        
        for item in api_response.get('data', []):
            orders_count = max(0, int(item.get('orders_count', 0)))
            revenue = max(0.0, float(item.get('revenue', 0.0)))
            
            processed_record = {
                'date_from': date_from,
                'date_to': date_to,
                'age_group': item.get('age_group'),
                'gender': item.get('gender'),
                'region': item.get('region'),
                'orders_count': orders_count,
                'revenue': revenue,
                'cached_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            }
            
            processed_data.append(processed_record)
            
            # Рассчитываем средний чек
            avg_order_value = revenue / orders_count if orders_count > 0 else 0
            
            self.log(f"   👤 {item.get('age_group')}, {item.get('gender')}, {item.get('region')}: {orders_count:,} заказов, {revenue:,.0f} руб. (средний чек: {avg_order_value:.0f} руб.)", 'INFO')
        
        return processed_data
    
    def process_campaigns_data(self, api_response: Dict, date_from: str, date_to: str) -> List[Dict]:
        """Обработка данных рекламных кампаний"""
        processed_data = []
        
        self.log("🔄 Обработка данных рекламных кампаний...", 'INFO')
        
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
            
            processed_record = {
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
            }
            
            processed_data.append(processed_record)
            
            self.log(f"   📊 {item.get('campaign_name')}: {impressions:,} показов → {clicks:,} кликов → {orders:,} заказов", 'INFO')
            self.log(f"      CTR: {ctr:.2f}%, CPC: {cpc:.2f} руб., ROAS: {roas:.2f}", 'INFO')
        
        return processed_data
    
    def validate_processed_data(self) -> bool:
        """Валидация всех обработанных данных"""
        self.log("🔍 Валидация обработанных данных...", 'INFO')
        
        validation_errors = 0
        
        # Валидация данных воронки
        for record in self.processed_data['funnel']:
            if record['cart_additions'] > record['views']:
                self.log(f"❌ Ошибка воронки: добавления в корзину > просмотров для {record['product_id']}", 'ERROR')
                validation_errors += 1
            
            if record['orders'] > record['cart_additions']:
                self.log(f"❌ Ошибка воронки: заказы > добавлений в корзину для {record['product_id']}", 'ERROR')
                validation_errors += 1
            
            if record['conversion_overall'] > 100:
                self.log(f"❌ Ошибка воронки: общая конверсия > 100% для {record['product_id']}", 'ERROR')
                validation_errors += 1
        
        # Валидация демографических данных
        for record in self.processed_data['demographics']:
            if record['orders_count'] < 0 or record['revenue'] < 0:
                self.log(f"❌ Ошибка демографии: отрицательные значения для {record['age_group']}, {record['gender']}, {record['region']}", 'ERROR')
                validation_errors += 1
        
        # Валидация кампаний
        for record in self.processed_data['campaigns']:
            if record['clicks'] > record['impressions']:
                self.log(f"❌ Ошибка кампании: клики > показов для {record['campaign_id']}", 'ERROR')
                validation_errors += 1
            
            if record['ctr'] > 100:
                self.log(f"❌ Ошибка кампании: CTR > 100% для {record['campaign_id']}", 'ERROR')
                validation_errors += 1
        
        if validation_errors == 0:
            self.log("✅ Все данные прошли валидацию", 'SUCCESS')
            return True
        else:
            self.log(f"❌ Найдено {validation_errors} ошибок валидации", 'ERROR')
            return False
    
    def generate_sql_queries(self):
        """Генерация SQL запросов для загрузки данных в БД"""
        self.log("📝 Генерация SQL запросов для загрузки в БД...", 'INFO')
        
        sql_queries = []
        
        # SQL для воронки продаж
        if self.processed_data['funnel']:
            funnel_sql = """
INSERT INTO ozon_funnel_data 
(date_from, date_to, product_id, campaign_id, views, cart_additions, orders,
 conversion_view_to_cart, conversion_cart_to_order, conversion_overall, cached_at)
VALUES """
            
            values = []
            for record in self.processed_data['funnel']:
                values.append(f"('{record['date_from']}', '{record['date_to']}', '{record['product_id']}', "
                            f"'{record['campaign_id']}', {record['views']}, {record['cart_additions']}, "
                            f"{record['orders']}, {record['conversion_view_to_cart']}, "
                            f"{record['conversion_cart_to_order']}, {record['conversion_overall']}, "
                            f"'{record['cached_at']}')")
            
            funnel_sql += ",\n".join(values)
            funnel_sql += "\nON DUPLICATE KEY UPDATE views = VALUES(views), cart_additions = VALUES(cart_additions), orders = VALUES(orders);"
            sql_queries.append(("Воронка продаж", funnel_sql))
        
        # SQL для демографии
        if self.processed_data['demographics']:
            demo_sql = """
INSERT INTO ozon_demographics 
(date_from, date_to, age_group, gender, region, orders_count, revenue, cached_at)
VALUES """
            
            values = []
            for record in self.processed_data['demographics']:
                values.append(f"('{record['date_from']}', '{record['date_to']}', '{record['age_group']}', "
                            f"'{record['gender']}', '{record['region']}', {record['orders_count']}, "
                            f"{record['revenue']}, '{record['cached_at']}')")
            
            demo_sql += ",\n".join(values)
            demo_sql += "\nON DUPLICATE KEY UPDATE orders_count = VALUES(orders_count), revenue = VALUES(revenue);"
            sql_queries.append(("Демографические данные", demo_sql))
        
        # SQL для кампаний
        if self.processed_data['campaigns']:
            campaigns_sql = """
INSERT INTO ozon_campaigns 
(campaign_id, campaign_name, date_from, date_to, impressions, clicks, spend,
 orders, revenue, ctr, cpc, roas, cached_at)
VALUES """
            
            values = []
            for record in self.processed_data['campaigns']:
                values.append(f"('{record['campaign_id']}', '{record['campaign_name']}', "
                            f"'{record['date_from']}', '{record['date_to']}', {record['impressions']}, "
                            f"{record['clicks']}, {record['spend']}, {record['orders']}, "
                            f"{record['revenue']}, {record['ctr']}, {record['cpc']}, "
                            f"{record['roas']}, '{record['cached_at']}')")
            
            campaigns_sql += ",\n".join(values)
            campaigns_sql += "\nON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks), spend = VALUES(spend);"
            sql_queries.append(("Рекламные кампании", campaigns_sql))
        
        return sql_queries
    
    def print_data_summary(self):
        """Вывод сводки по обработанным данным"""
        self.log("\n📊 СВОДКА ПО ОБРАБОТАННЫМ ДАННЫМ", 'INFO')
        self.log("=" * 50, 'INFO')
        
        # Сводка по воронке
        if self.processed_data['funnel']:
            total_views = sum(r['views'] for r in self.processed_data['funnel'])
            total_cart = sum(r['cart_additions'] for r in self.processed_data['funnel'])
            total_orders = sum(r['orders'] for r in self.processed_data['funnel'])
            avg_conversion = sum(r['conversion_overall'] for r in self.processed_data['funnel']) / len(self.processed_data['funnel'])
            
            self.log(f"🛒 Воронка продаж ({len(self.processed_data['funnel'])} товаров):", 'INFO')
            self.log(f"   - Просмотры: {total_views:,}", 'INFO')
            self.log(f"   - В корзину: {total_cart:,} ({(total_cart/total_views*100):.2f}%)", 'INFO')
            self.log(f"   - Заказы: {total_orders:,} ({(total_orders/total_views*100):.2f}%)", 'INFO')
            self.log(f"   - Средняя конверсия: {avg_conversion:.2f}%", 'INFO')
        
        # Сводка по демографии
        if self.processed_data['demographics']:
            total_demo_orders = sum(r['orders_count'] for r in self.processed_data['demographics'])
            total_revenue = sum(r['revenue'] for r in self.processed_data['demographics'])
            avg_order_value = total_revenue / total_demo_orders if total_demo_orders > 0 else 0
            regions = set(r['region'] for r in self.processed_data['demographics'])
            
            self.log(f"\n👥 Демографические данные ({len(self.processed_data['demographics'])} сегментов):", 'INFO')
            self.log(f"   - Заказы: {total_demo_orders:,}", 'INFO')
            self.log(f"   - Выручка: {total_revenue:,.0f} руб.", 'INFO')
            self.log(f"   - Средний чек: {avg_order_value:.0f} руб.", 'INFO')
            self.log(f"   - Регионы: {len(regions)} ({', '.join(sorted(regions))})", 'INFO')
        
        # Сводка по кампаниям
        if self.processed_data['campaigns']:
            total_impressions = sum(r['impressions'] for r in self.processed_data['campaigns'])
            total_clicks = sum(r['clicks'] for r in self.processed_data['campaigns'])
            total_spend = sum(r['spend'] for r in self.processed_data['campaigns'])
            total_campaign_revenue = sum(r['revenue'] for r in self.processed_data['campaigns'])
            avg_ctr = sum(r['ctr'] for r in self.processed_data['campaigns']) / len(self.processed_data['campaigns'])
            avg_roas = sum(r['roas'] for r in self.processed_data['campaigns']) / len(self.processed_data['campaigns'])
            
            self.log(f"\n📈 Рекламные кампании ({len(self.processed_data['campaigns'])} кампаний):", 'INFO')
            self.log(f"   - Показы: {total_impressions:,}", 'INFO')
            self.log(f"   - Клики: {total_clicks:,} (CTR: {(total_clicks/total_impressions*100):.2f}%)", 'INFO')
            self.log(f"   - Расходы: {total_spend:,.0f} руб.", 'INFO')
            self.log(f"   - Доходы: {total_campaign_revenue:,.0f} руб.", 'INFO')
            self.log(f"   - Средний CTR: {avg_ctr:.2f}%", 'INFO')
            self.log(f"   - Средний ROAS: {avg_roas:.2f}", 'INFO')
    
    def run_simulation(self):
        """Основной метод симуляции"""
        self.log("🚀 СИМУЛЯЦИЯ ЗАГРУЗКИ ДАННЫХ OZON ANALYTICS", 'INFO')
        self.log("Период: 29.09.2025 - 05.10.2025", 'INFO')
        self.log("=" * 60, 'INFO')
        
        date_from = '2025-09-29'
        date_to = '2025-10-05'
        
        # 1. Получение данных из API (имитация)
        self.log("\n1️⃣ Получение данных из API Ozon...", 'INFO')
        
        # Воронка продаж
        self.log("\n📊 Запрос данных воронки продаж:", 'INFO')
        funnel_response = self.simulate_ozon_api_call('/v1/analytics/funnel', {
            'date_from': date_from,
            'date_to': date_to
        })
        
        if funnel_response.get('success'):
            self.processed_data['funnel'] = self.process_funnel_data(funnel_response, date_from, date_to)
        
        # Демографические данные
        self.log("\n👥 Запрос демографических данных:", 'INFO')
        demo_response = self.simulate_ozon_api_call('/v1/analytics/demographics', {
            'date_from': date_from,
            'date_to': date_to
        })
        
        if demo_response.get('success'):
            self.processed_data['demographics'] = self.process_demographics_data(demo_response, date_from, date_to)
        
        # Рекламные кампании
        self.log("\n📈 Запрос данных рекламных кампаний:", 'INFO')
        campaigns_response = self.simulate_ozon_api_call('/v1/analytics/campaigns', {
            'date_from': date_from,
            'date_to': date_to
        })
        
        if campaigns_response.get('success'):
            self.processed_data['campaigns'] = self.process_campaigns_data(campaigns_response, date_from, date_to)
        
        # 2. Валидация данных
        self.log("\n2️⃣ Валидация обработанных данных...", 'INFO')
        validation_success = self.validate_processed_data()
        
        # 3. Генерация SQL запросов
        self.log("\n3️⃣ Генерация SQL запросов...", 'INFO')
        sql_queries = self.generate_sql_queries()
        
        for query_name, query in sql_queries:
            self.log(f"✅ SQL запрос для '{query_name}' готов ({len(query)} символов)", 'SUCCESS')
        
        # 4. Сводка по данным
        self.print_data_summary()
        
        # 5. Сохранение SQL запросов в файл
        self.log("\n4️⃣ Сохранение SQL запросов в файл...", 'INFO')
        with open('ozon_data_insert_queries.sql', 'w', encoding='utf-8') as f:
            f.write("-- SQL запросы для загрузки данных Ozon Analytics\n")
            f.write(f"-- Период: {date_from} - {date_to}\n")
            f.write(f"-- Сгенерировано: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n")
            
            for query_name, query in sql_queries:
                f.write(f"-- {query_name}\n")
                f.write(query)
                f.write("\n\n")
        
        self.log("✅ SQL запросы сохранены в файл 'ozon_data_insert_queries.sql'", 'SUCCESS')
        
        self.log("\n" + "=" * 60, 'INFO')
        if validation_success:
            self.log("🎉 СИМУЛЯЦИЯ ЗАВЕРШЕНА УСПЕШНО!", 'SUCCESS')
            self.log("Данные обработаны, проверены и готовы к загрузке в БД.", 'SUCCESS')
        else:
            self.log("❌ СИМУЛЯЦИЯ ЗАВЕРШЕНА С ОШИБКАМИ!", 'ERROR')
            self.log("Обнаружены проблемы с валидацией данных.", 'ERROR')
        
        return validation_success

if __name__ == "__main__":
    simulator = OzonAPISimulator()
    success = simulator.run_simulation()
    exit(0 if success else 1)