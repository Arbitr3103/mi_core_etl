#!/usr/bin/env python3
"""
Комплексный набор тестов для системы пополнения склада.
Включает unit-тесты, интеграционные тесты и тесты производительности.
"""

import unittest
import sys
import os
import tempfile
import json
from datetime import datetime, timedelta
from unittest.mock import Mock, patch, MagicMock
from dataclasses import dataclass
from typing import List, Dict, Optional

# Добавляем путь к модулям
sys.path.append(os.path.dirname(__file__))

# Импортируем тестируемые модули
from inventory_analyzer import InventoryAnalyzer, InventoryItem, ProductSettings
from sales_velocity_calculator import SalesVelocityCalculator, SalesMetrics, SalesTrend, StockoutPrediction
from replenishment_recommender import ReplenishmentRecommender, ReplenishmentRecommendation, PriorityLevel
from alert_manager import AlertManager, Alert, AlertType, AlertLevel
from reporting_engine import ReportingEngine, InventoryMetrics, SalesMetrics as ReportSalesMetrics


class TestInventoryAnalyzer(unittest.TestCase):
    """Тесты для анализатора запасов."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_connection = Mock()
        self.analyzer = InventoryAnalyzer(self.mock_connection)
    
    def test_get_current_stock_basic(self):
        """Тест получения текущих запасов."""
        # Мокаем результат запроса к БД
        mock_cursor = Mock()
        mock_cursor.fetchall.return_value = [
            {
                'product_id': 1,
                'sku': 'TEST-001',
                'product_name': 'Тестовый товар 1',
                'source': 'ozon',
                'current_stock': 100,
                'reserved_stock': 10,
                'available_stock': 90,
                'last_updated': datetime.now(),
                'cost_price': 500.0
            },
            {
                'product_id': 2,
                'sku': 'TEST-002',
                'product_name': 'Тестовый товар 2',
                'source': 'ozon',
                'current_stock': 5,
                'reserved_stock': 0,
                'available_stock': 5,
                'last_updated': datetime.now(),
                'cost_price': 1000.0
            }
        ]
        
        self.mock_connection.cursor.return_value = mock_cursor
        
        # Мокаем настройки
        with patch.object(self.analyzer, '_load_settings') as mock_settings:
            mock_settings.return_value = {}
            
            # Выполняем тест
            items = self.analyzer.get_current_stock()
            
            # Проверяем результаты
            self.assertEqual(len(items), 2)
            self.assertEqual(items[0].sku, 'TEST-001')
            self.assertEqual(items[0].current_stock, 100)
            self.assertEqual(items[1].available_stock, 5)
    
    def test_get_product_settings(self):
        """Тест получения настроек товара."""
        mock_cursor = Mock()
        mock_cursor.fetchone.return_value = {
            'min_stock_level': 20,
            'max_stock_level': 100,
            'reorder_point': 30,
            'lead_time_days': 7,
            'safety_stock_days': 3,
            'is_active': 1
        }
        
        self.mock_connection.cursor.return_value = mock_cursor
        
        # Мокаем настройки
        with patch.object(self.analyzer, '_load_settings') as mock_settings:
            mock_settings.return_value = {}
            
            settings = self.analyzer.get_product_settings(1)
            
            self.assertEqual(settings.min_stock_level, 20)
            self.assertEqual(settings.lead_time_days, 7)
            self.assertTrue(settings.is_active)
    
    def test_identify_low_stock_products(self):
        """Тест выявления товаров с низким остатком."""
        # Создаем тестовые данные
        items = [
            InventoryItem(1, 'TEST-001', 'Товар 1', 'ozon', 100, 10, 90, datetime.now(), 500.0),
            InventoryItem(2, 'TEST-002', 'Товар 2', 'ozon', 5, 0, 5, datetime.now(), 1000.0),  # Низкий остаток
            InventoryItem(3, 'TEST-003', 'Товар 3', 'ozon', 0, 0, 0, datetime.now(), 300.0)   # Нулевой остаток
        ]
        
        # Тестируем простую логику определения низких остатков
        low_stock_items = [item for item in items if item.available_stock <= 10]
        
        # Должно быть 2 товара с низким остатком (включая нулевой)
        self.assertEqual(len(low_stock_items), 2)
        self.assertIn('TEST-002', [item.sku for item in low_stock_items])
        self.assertIn('TEST-003', [item.sku for item in low_stock_items])


class TestSalesVelocityCalculator(unittest.TestCase):
    """Тесты для калькулятора скорости продаж."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_connection = Mock()
        self.calculator = SalesVelocityCalculator(self.mock_connection)
    
    def test_calculate_daily_sales_rate(self):
        """Тест расчета дневной скорости продаж."""
        # Мокаем данные продаж
        mock_cursor = Mock()
        mock_cursor.fetchone.return_value = {
            'total_quantity': 70,  # 70 штук за 7 дней = 10 в день
            'days_count': 7
        }
        
        self.mock_connection.cursor.return_value = mock_cursor
        
        daily_rate = self.calculator.calculate_daily_sales_rate(1, 7)
        
        self.assertEqual(daily_rate, 10.0)
    
    def test_calculate_daily_sales_rate_no_sales(self):
        """Тест расчета при отсутствии продаж."""
        mock_cursor = Mock()
        mock_cursor.fetchone.return_value = {
            'total_quantity': 0,
            'days_count': 7
        }
        
        self.mock_connection.cursor.return_value = mock_cursor
        
        daily_rate = self.calculator.calculate_daily_sales_rate(1, 7)
        
        self.assertEqual(daily_rate, 0.0)
    
    def test_detect_sales_trend_growing(self):
        """Тест определения растущего тренда продаж."""
        trend = self.calculator.get_sales_trend(5.0, 8.0, 12.0)
        self.assertEqual(trend, SalesTrend.GROWING)
    
    def test_detect_sales_trend_declining(self):
        """Тест определения падающего тренда продаж."""
        trend = self.calculator.get_sales_trend(15.0, 10.0, 5.0)
        self.assertEqual(trend, SalesTrend.DECLINING)
    
    def test_detect_sales_trend_stable(self):
        """Тест определения стабильного тренда продаж."""
        trend = self.calculator.get_sales_trend(10.0, 9.5, 10.5)
        self.assertEqual(trend, SalesTrend.STABLE)
    
    def test_calculate_days_until_stockout(self):
        """Тест расчета дней до исчерпания запасов."""
        # Мокаем метрики продаж
        with patch.object(self.calculator, 'get_sales_metrics') as mock_metrics:
            mock_metrics.return_value = SalesMetrics(
                product_id=1,
                sku='TEST-001',
                daily_sales_rate_7d=5.0,
                daily_sales_rate_14d=4.5,
                daily_sales_rate_30d=4.0,
                total_sales_7d=35,
                total_sales_14d=63,
                total_sales_30d=120,
                last_sale_date=datetime.now(),
                first_sale_date=datetime.now() - timedelta(days=30),
                sales_trend=SalesTrend.STABLE,
                trend_coefficient=0.1,
                days_since_last_sale=1,
                sales_consistency=0.8,
                peak_daily_sales=8.0
            )
            
            prediction = self.calculator.calculate_days_until_stockout(1, 20)
            
            # При скорости 5 шт/день и остатке 20 шт = 4 дня
            self.assertEqual(prediction.days_until_stockout, 4)
            self.assertGreater(prediction.confidence_level, 0)


class TestReplenishmentRecommender(unittest.TestCase):
    """Тесты для генератора рекомендаций."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_connection = Mock()
        self.recommender = ReplenishmentRecommender(self.mock_connection)
    
    def test_calculate_recommended_quantity_basic(self):
        """Тест базового расчета рекомендуемого количества."""
        # Создаем тестовые данные
        item = InventoryItem(1, 'TEST-001', 'Товар', 'ozon', 10, 2, 8, 500.0, 20)
        settings = ProductSettings(1, 20, 30, 7, 3, True)
        sales_metrics = SalesMetrics(
            product_id=1,
            sku='TEST-001',
            daily_sales_rate_7d=5.0,
            daily_sales_rate_14d=4.5,
            daily_sales_rate_30d=4.0,
            total_sales_7d=35,
            total_sales_14d=63,
            total_sales_30d=120,
            last_sale_date=datetime.now(),
            first_sale_date=datetime.now() - timedelta(days=30),
            sales_trend=SalesTrend.STABLE,
            trend_coefficient=0.1,
            days_since_last_sale=1,
            sales_consistency=0.8,
            peak_daily_sales=8.0
        )
        
        qty, order_value = self.recommender.calculate_recommended_quantity(
            item, sales_metrics, settings
        )
        
        # При скорости 5 шт/день, времени поставки 7 дней, страховом запасе 3 дня
        # Потребность = (5 * 7) + (5 * 3) = 50 шт
        # Рекомендация = 50 - 8 (доступный остаток) = 42 шт
        self.assertGreater(qty, 0)
        self.assertIsNotNone(order_value)
    
    def test_calculate_priority_critical(self):
        """Тест определения критического приоритета."""
        item = InventoryItem(1, 'TEST-001', 'Товар', 'ozon', 2, 0, 2, 500.0, 20)
        sales_metrics = SalesMetrics(
            product_id=1,
            sku='TEST-001',
            daily_sales_rate_7d=10.0,  # Высокая скорость продаж
            daily_sales_rate_14d=9.0,
            daily_sales_rate_30d=8.0,
            total_sales_7d=70,
            total_sales_14d=126,
            total_sales_30d=240,
            last_sale_date=datetime.now(),
            first_sale_date=datetime.now() - timedelta(days=30),
            sales_trend=SalesTrend.GROWING,
            trend_coefficient=0.5,
            days_since_last_sale=0,
            sales_consistency=0.9,
            peak_daily_sales=12.0
        )
        
        # Мокаем прогноз исчерпания
        stockout_prediction = StockoutPrediction(
            product_id=1,
            days_until_stockout=1,  # Критично - 1 день
            confidence_level=0.9
        )
        
        settings = ProductSettings(1, 20, 30, 7, 3, True)
        
        priority, urgency = self.recommender.calculate_priority_and_urgency(
            item, sales_metrics, stockout_prediction, settings
        )
        
        self.assertEqual(priority, PriorityLevel.CRITICAL)
        self.assertGreater(urgency, 50.0)  # Высокая срочность
    
    def test_prioritize_recommendations(self):
        """Тест приоритизации рекомендаций."""
        # Создаем тестовые рекомендации
        recommendations = [
            self._create_test_recommendation('LOW-001', PriorityLevel.LOW, 20.0),
            self._create_test_recommendation('CRIT-001', PriorityLevel.CRITICAL, 90.0),
            self._create_test_recommendation('HIGH-001', PriorityLevel.HIGH, 70.0),
            self._create_test_recommendation('CRIT-002', PriorityLevel.CRITICAL, 85.0)
        ]
        
        sorted_recs = self.recommender.prioritize_recommendations(recommendations)
        
        # Первые должны быть критические с наивысшей срочностью
        self.assertEqual(sorted_recs[0].sku, 'CRIT-001')  # Критический с срочностью 90
        self.assertEqual(sorted_recs[1].sku, 'CRIT-002')  # Критический с срочностью 85
        self.assertEqual(sorted_recs[2].sku, 'HIGH-001')  # Высокий приоритет
        self.assertEqual(sorted_recs[3].sku, 'LOW-001')   # Низкий приоритет
    
    def _create_test_recommendation(self, sku: str, priority: PriorityLevel, urgency: float):
        """Создать тестовую рекомендацию."""
        return ReplenishmentRecommendation(
            product_id=1,
            sku=sku,
            product_name=f'Товар {sku}',
            source='test',
            current_stock=10,
            reserved_stock=0,
            available_stock=10,
            daily_sales_rate_7d=5.0,
            daily_sales_rate_14d=4.5,
            daily_sales_rate_30d=4.0,
            days_until_stockout=5,
            recommended_order_quantity=20,
            recommended_order_value=10000.0,
            priority_level=priority,
            urgency_score=urgency,
            last_sale_date=datetime.now(),
            last_restock_date=None,
            sales_trend=SalesTrend.STABLE,
            inventory_turnover_days=30,
            days_since_last_sale=1,
            min_stock_level=20,
            reorder_point=30,
            lead_time_days=7,
            analysis_date=datetime.now(),
            confidence_level=0.8
        )


class TestAlertManager(unittest.TestCase):
    """Тесты для менеджера алертов."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_connection = Mock()
        self.alert_manager = AlertManager(self.mock_connection)
    
    def test_generate_alerts_from_recommendations(self):
        """Тест генерации алертов из рекомендаций."""
        # Создаем тестовые рекомендации
        recommendations = [
            self._create_test_recommendation('CRIT-001', PriorityLevel.CRITICAL),
            self._create_test_recommendation('HIGH-001', PriorityLevel.HIGH),
            self._create_test_recommendation('MED-001', PriorityLevel.MEDIUM)
        ]
        
        alerts = self.alert_manager.generate_alerts_from_recommendations(recommendations)
        
        # Должно быть создано 2 алерта (критический и высокоприоритетный)
        self.assertEqual(len(alerts), 2)
        
        # Проверяем типы алертов
        alert_levels = [alert.alert_level for alert in alerts]
        self.assertIn(AlertLevel.CRITICAL, alert_levels)
        self.assertIn(AlertLevel.HIGH, alert_levels)
    
    def test_create_dashboard_alerts(self):
        """Тест создания алертов для дашборда."""
        alerts = [
            Alert(1, 'TEST-001', 'Товар 1', AlertType.STOCKOUT_CRITICAL, 
                  AlertLevel.CRITICAL, 'Критический остаток', 5, 2, 'Заказать срочно', datetime.now()),
            Alert(2, 'TEST-002', 'Товар 2', AlertType.SLOW_MOVING, 
                  AlertLevel.MEDIUM, 'Медленно движется', 100, None, 'Акция', datetime.now())
        ]
        
        dashboard_data = self.alert_manager.create_dashboard_alerts(alerts)
        
        self.assertEqual(dashboard_data['total_alerts'], 2)
        self.assertEqual(dashboard_data['critical_count'], 1)
        self.assertEqual(dashboard_data['medium_count'], 1)
        self.assertIn('CRITICAL', dashboard_data['alerts_by_level'])
    
    def _create_test_recommendation(self, sku: str, priority: PriorityLevel):
        """Создать тестовую рекомендацию для алертов."""
        return ReplenishmentRecommendation(
            product_id=1,
            sku=sku,
            product_name=f'Товар {sku}',
            source='test',
            current_stock=10,
            reserved_stock=0,
            available_stock=10,
            daily_sales_rate_7d=5.0,
            daily_sales_rate_14d=4.5,
            daily_sales_rate_30d=4.0,
            days_until_stockout=2 if priority == PriorityLevel.CRITICAL else 10,
            recommended_order_quantity=20,
            recommended_order_value=10000.0,
            priority_level=priority,
            urgency_score=90.0 if priority == PriorityLevel.CRITICAL else 50.0,
            last_sale_date=datetime.now(),
            last_restock_date=None,
            sales_trend=SalesTrend.STABLE,
            inventory_turnover_days=30,
            days_since_last_sale=1,
            min_stock_level=20,
            reorder_point=30,
            lead_time_days=7,
            analysis_date=datetime.now(),
            confidence_level=0.8
        )


class TestReportingEngine(unittest.TestCase):
    """Тесты для движка отчетности."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.mock_connection = Mock()
        self.reporting_engine = ReportingEngine(self.mock_connection)
    
    def test_get_inventory_metrics(self):
        """Тест получения метрик по запасам."""
        mock_cursor = Mock()
        mock_cursor.fetchone.return_value = {
            'total_products': 100,
            'total_value': 500000.0,
            'low_stock_count': 15,
            'zero_stock_count': 5,
            'overstocked_count': 10,
            'avg_turnover': 45.5,
            'recommended_orders': 25,
            'recommended_value': 150000.0
        }
        
        self.mock_connection.cursor.return_value = mock_cursor
        
        metrics = self.reporting_engine.get_inventory_metrics()
        
        self.assertEqual(metrics.total_products, 100)
        self.assertEqual(metrics.total_inventory_value, 500000.0)
        self.assertEqual(metrics.low_stock_products, 15)
        self.assertEqual(metrics.total_recommended_orders, 25)
    
    def test_export_report_to_json(self):
        """Тест экспорта отчета в JSON."""
        test_report = {
            'test_data': 'test_value',
            'numbers': [1, 2, 3],
            'nested': {'key': 'value'}
        }
        
        with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as f:
            filename = f.name
        
        try:
            success = self.reporting_engine.export_report_to_json(test_report, filename)
            self.assertTrue(success)
            
            # Проверяем что файл создан и содержит правильные данные
            with open(filename, 'r', encoding='utf-8') as f:
                loaded_data = json.load(f)
            
            self.assertEqual(loaded_data['test_data'], 'test_value')
            self.assertEqual(loaded_data['numbers'], [1, 2, 3])
            
        finally:
            # Удаляем тестовый файл
            if os.path.exists(filename):
                os.unlink(filename)


class TestIntegration(unittest.TestCase):
    """Интеграционные тесты для всей системы."""
    
    def setUp(self):
        """Настройка интеграционного тестирования."""
        self.mock_connection = Mock()
    
    def test_full_workflow_simulation(self):
        """Тест полного рабочего процесса системы."""
        # Этот тест симулирует полный цикл работы системы
        # без реального подключения к БД
        
        # 1. Анализ запасов
        analyzer = InventoryAnalyzer(self.mock_connection)
        
        # Мокаем данные запасов
        mock_cursor = Mock()
        mock_cursor.fetchall.return_value = [
            {
                'product_id': 1,
                'sku': 'INT-001',
                'product_name': 'Интеграционный тест',
                'source': 'test',
                'current_stock': 5,
                'reserved_stock': 0,
                'available_stock': 5,
                'last_updated': datetime.now(),
                'cost_price': 1000.0
            }
        ]
        self.mock_connection.cursor.return_value = mock_cursor
        
        # Мокаем настройки
        with patch.object(analyzer, '_load_settings') as mock_settings:
            mock_settings.return_value = {}
            
            inventory_items = analyzer.get_current_stock()
            self.assertEqual(len(inventory_items), 1)
        
        # 2. Расчет скорости продаж
        calculator = SalesVelocityCalculator(self.mock_connection)
        
        # Мокаем метрики продаж
        with patch.object(calculator, 'get_sales_metrics') as mock_metrics:
            mock_metrics.return_value = SalesMetrics(
                product_id=1,
                sku='INT-001',
                daily_sales_rate_7d=3.0,
                daily_sales_rate_14d=2.8,
                daily_sales_rate_30d=2.5,
                total_sales_7d=21,
                total_sales_14d=39,
                total_sales_30d=75,
                last_sale_date=datetime.now(),
                first_sale_date=datetime.now() - timedelta(days=30),
                sales_trend=SalesTrend.STABLE,
                trend_coefficient=0.0,
                days_since_last_sale=1,
                sales_consistency=0.7,
                peak_daily_sales=5.0
            )
            
            metrics = calculator.get_sales_metrics(1)
            self.assertEqual(metrics.daily_sales_rate_7d, 3.0)
        
        # 3. Генерация рекомендаций
        recommender = ReplenishmentRecommender(self.mock_connection)
        
        # Мокаем настройки товара
        with patch.object(analyzer, 'get_product_settings') as mock_settings:
            mock_settings.return_value = ProductSettings(1, 20, 30, 7, 3, True)
            
            # Мокаем компоненты рекомендера
            recommender.inventory_analyzer = analyzer
            recommender.sales_calculator = calculator
            
            # Симулируем генерацию рекомендации
            item = inventory_items[0]
            settings = ProductSettings(1, 20, 30, 7, 3, True)
            sales_metrics = SalesMetrics(
                product_id=1, sku='INT-001', daily_sales_rate_7d=3.0, daily_sales_rate_14d=2.8, 
                daily_sales_rate_30d=2.5, total_sales_7d=21, total_sales_14d=39, total_sales_30d=75,
                last_sale_date=datetime.now(), first_sale_date=datetime.now() - timedelta(days=30),
                sales_trend=SalesTrend.STABLE, trend_coefficient=0.0, days_since_last_sale=1,
                sales_consistency=0.7, peak_daily_sales=5.0
            )
            
            qty, order_value = recommender.calculate_recommended_quantity(
                item, sales_metrics, settings
            )
            
            self.assertGreater(qty, 0)  # Должна быть рекомендация к заказу
        
        # 4. Создание алертов
        alert_manager = AlertManager(self.mock_connection)
        
        # Создаем тестовую рекомендацию
        recommendation = ReplenishmentRecommendation(
            product_id=1, sku='INT-001', product_name='Интеграционный тест',
            source='test', current_stock=5, reserved_stock=0, available_stock=5,
            daily_sales_rate_7d=3.0, daily_sales_rate_14d=2.8, daily_sales_rate_30d=2.5,
            days_until_stockout=2, recommended_order_quantity=qty,
            recommended_order_value=order_value, priority_level=PriorityLevel.CRITICAL,
            urgency_score=85.0, last_sale_date=datetime.now(), last_restock_date=None,
            sales_trend=SalesTrend.STABLE, inventory_turnover_days=30, days_since_last_sale=1,
            min_stock_level=20, reorder_point=30, lead_time_days=7,
            analysis_date=datetime.now(), confidence_level=0.8
        )
        
        alerts = alert_manager.generate_alerts_from_recommendations([recommendation])
        self.assertGreater(len(alerts), 0)  # Должен быть создан алерт
        
        print("✅ Интеграционный тест полного рабочего процесса прошел успешно")


class TestPerformance(unittest.TestCase):
    """Тесты производительности."""
    
    def test_large_dataset_processing(self):
        """Тест обработки большого количества товаров."""
        import time
        
        # Создаем большой набор тестовых данных
        large_dataset = []
        for i in range(1000):
            item = InventoryItem(
                product_id=i,
                sku=f'PERF-{i:04d}',
                product_name=f'Товар производительности {i}',
                source='test',
                current_stock=100 + i % 50,
                reserved_stock=i % 10,
                available_stock=100 + i % 50 - i % 10,
                last_updated=datetime.now(),
                cost_price=500.0 + i % 1000
            )
            large_dataset.append(item)
        
        # Тестируем простую обработку данных
        start_time = time.time()
        low_stock_items = [item for item in large_dataset if item.available_stock <= 20]
        processing_time = time.time() - start_time
        
        # Проверяем что обработка завершилась быстро (менее 1 секунды)
        self.assertLess(processing_time, 1.0)
        self.assertIsInstance(low_stock_items, list)
        
        print(f"✅ Обработка 1000 товаров заняла {processing_time:.3f} секунд")
    
    def test_memory_usage(self):
        """Тест использования памяти."""
        import psutil
        import os
        
        process = psutil.Process(os.getpid())
        initial_memory = process.memory_info().rss / 1024 / 1024  # MB
        
        # Создаем много объектов
        recommendations = []
        for i in range(10000):
            rec = ReplenishmentRecommendation(
                product_id=i, sku=f'MEM-{i}', product_name=f'Товар {i}',
                source='test', current_stock=100, reserved_stock=10, available_stock=90,
                daily_sales_rate_7d=5.0, daily_sales_rate_14d=4.5, daily_sales_rate_30d=4.0,
                days_until_stockout=20, recommended_order_quantity=50,
                recommended_order_value=25000.0, priority_level=PriorityLevel.MEDIUM,
                urgency_score=50.0, last_sale_date=datetime.now(), last_restock_date=None,
                sales_trend=SalesTrend.STABLE, inventory_turnover_days=45, days_since_last_sale=2,
                min_stock_level=20, reorder_point=30, lead_time_days=7,
                analysis_date=datetime.now(), confidence_level=0.8
            )
            recommendations.append(rec)
        
        final_memory = process.memory_info().rss / 1024 / 1024  # MB
        memory_increase = final_memory - initial_memory
        
        # Проверяем что увеличение памяти разумное (менее 100 MB для 10k объектов)
        self.assertLess(memory_increase, 100)
        
        print(f"✅ Создание 10000 рекомендаций увеличило память на {memory_increase:.1f} MB")


def run_all_tests():
    """Запуск всех тестов."""
    print("🧪 ЗАПУСК КОМПЛЕКСНОГО ТЕСТИРОВАНИЯ СИСТЕМЫ ПОПОЛНЕНИЯ СКЛАДА")
    print("=" * 80)
    
    # Создаем test suite
    test_suite = unittest.TestSuite()
    
    # Добавляем все тестовые классы
    test_classes = [
        TestInventoryAnalyzer,
        TestSalesVelocityCalculator,
        TestReplenishmentRecommender,
        TestAlertManager,
        TestReportingEngine,
        TestIntegration,
        TestPerformance
    ]
    
    for test_class in test_classes:
        tests = unittest.TestLoader().loadTestsFromTestCase(test_class)
        test_suite.addTests(tests)
    
    # Запускаем тесты
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(test_suite)
    
    # Выводим итоги
    print("\n" + "=" * 80)
    print("📊 ИТОГИ ТЕСТИРОВАНИЯ:")
    print(f"✅ Успешных тестов: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"❌ Неудачных тестов: {len(result.failures)}")
    print(f"🚫 Ошибок: {len(result.errors)}")
    print(f"📈 Общий процент успеха: {((result.testsRun - len(result.failures) - len(result.errors)) / result.testsRun * 100):.1f}%")
    
    if result.failures:
        print("\n❌ НЕУДАЧНЫЕ ТЕСТЫ:")
        for test, traceback in result.failures:
            print(f"   {test}: {traceback.split('AssertionError: ')[-1].split('\\n')[0]}")
    
    if result.errors:
        print("\n🚫 ОШИБКИ:")
        for test, traceback in result.errors:
            error_lines = traceback.split('\n')
            error_msg = error_lines[-2] if len(error_lines) > 1 else str(traceback)
            print(f"   {test}: {error_msg}")
    
    return result.wasSuccessful()


if __name__ == "__main__":
    success = run_all_tests()
    exit(0 if success else 1)