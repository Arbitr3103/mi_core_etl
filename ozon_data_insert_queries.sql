-- SQL запросы для загрузки данных Ozon Analytics
-- Период: 2025-09-29 - 2025-10-05
-- Сгенерировано: 2025-10-05 19:29:56

-- Воронка продаж

INSERT INTO ozon_funnel_data 
(date_from, date_to, product_id, campaign_id, views, cart_additions, orders,
 conversion_view_to_cart, conversion_cart_to_order, conversion_overall, cached_at)
VALUES ('2025-09-29', '2025-10-05', 'ELECTRONICS_SMARTPHONE_001', 'PROMO_ELECTRONICS_Q4', 15420, 1850, 463, 12.0, 25.03, 3.0, '2025-10-05 19:29:55'),
('2025-09-29', '2025-10-05', 'HOME_KITCHEN_APPLIANCE_002', 'PROMO_HOME_AUTUMN', 8750, 1225, 294, 14.0, 24.0, 3.36, '2025-10-05 19:29:55'),
('2025-09-29', '2025-10-05', 'FASHION_WINTER_JACKET_003', 'PROMO_FASHION_WINTER', 12300, 1476, 369, 12.0, 25.0, 3.0, '2025-10-05 19:29:55'),
('2025-09-29', '2025-10-05', 'BOOKS_BESTSELLER_004', 'PROMO_BOOKS_EDUCATION', 5680, 852, 213, 15.0, 25.0, 3.75, '2025-10-05 19:29:55'),
('2025-09-29', '2025-10-05', 'SPORTS_FITNESS_EQUIPMENT_005', 'PROMO_SPORTS_HEALTH', 9240, 1109, 277, 12.0, 24.98, 3.0, '2025-10-05 19:29:55')
ON DUPLICATE KEY UPDATE views = VALUES(views), cart_additions = VALUES(cart_additions), orders = VALUES(orders);

-- Демографические данные

INSERT INTO ozon_demographics 
(date_from, date_to, age_group, gender, region, orders_count, revenue, cached_at)
VALUES ('2025-09-29', '2025-10-05', '25-34', 'male', 'Moscow', 342, 171000.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '25-34', 'female', 'Moscow', 398, 199000.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '35-44', 'male', 'Moscow', 287, 143500.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '35-44', 'female', 'Moscow', 324, 162000.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '25-34', 'male', 'Saint Petersburg', 198, 99000.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '25-34', 'female', 'Saint Petersburg', 234, 117000.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '35-44', 'male', 'Saint Petersburg', 167, 83500.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '35-44', 'female', 'Saint Petersburg', 189, 94500.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '25-34', 'male', 'Novosibirsk', 89, 44500.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '35-44', 'female', 'Yekaterinburg', 76, 38000.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '45-54', 'male', 'Kazan', 54, 27000.0, '2025-10-05 19:29:56'),
('2025-09-29', '2025-10-05', '18-24', 'female', 'Rostov-on-Don', 43, 21500.0, '2025-10-05 19:29:56')
ON DUPLICATE KEY UPDATE orders_count = VALUES(orders_count), revenue = VALUES(revenue);

-- Рекламные кампании

INSERT INTO ozon_campaigns 
(campaign_id, campaign_name, date_from, date_to, impressions, clicks, spend,
 orders, revenue, ctr, cpc, roas, cached_at)
VALUES ('PROMO_ELECTRONICS_Q4', 'Электроника - Осенние скидки', '2025-09-29', '2025-10-05', 245000, 12250, 24500.0, 463, 92600.0, 5.0, 2.0, 3.78, '2025-10-05 19:29:56'),
('PROMO_HOME_AUTUMN', 'Товары для дома - Уютная осень', '2025-09-29', '2025-10-05', 180000, 9000, 18000.0, 294, 58800.0, 5.0, 2.0, 3.27, '2025-10-05 19:29:56'),
('PROMO_FASHION_WINTER', 'Мода - Зимняя коллекция', '2025-09-29', '2025-10-05', 320000, 16000, 32000.0, 369, 73800.0, 5.0, 2.0, 2.31, '2025-10-05 19:29:56'),
('PROMO_BOOKS_EDUCATION', 'Книги - Образование и развитие', '2025-09-29', '2025-10-05', 95000, 4750, 9500.0, 213, 21300.0, 5.0, 2.0, 2.24, '2025-10-05 19:29:56'),
('PROMO_SPORTS_HEALTH', 'Спорт и здоровье - Активная осень', '2025-09-29', '2025-10-05', 155000, 7750, 15500.0, 277, 55400.0, 5.0, 2.0, 3.57, '2025-10-05 19:29:56')
ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks), spend = VALUES(spend);

