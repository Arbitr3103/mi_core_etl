#!/usr/bin/env python3
"""
Тесты для проверки оптимизаций производительности синхронизации остатков.

Проверяет:
- Оптимизацию запросов к базе данных
- Параллельную обработку API запросов
- Кэширование данных
- Пакетную обработку записей

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
import asyncio
import time
import tempfile
import shutil
from datetime import datetime, date
from unittest.mock import Mock, patch, MagicMock
import sys
import os

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from api_request_optimizer import APIRequestOptimizer, CacheType, BatchConfig
    from inventory_sync_service_optimized import OptimizedInventorySyncService, InventoryRecord, ProductCache
    from parallel_sync_manager import ParallelSyncManager, SyncPriority
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)


class TestAPIRequestOptimizer(unittest.TestCase):
    """Тесты для оптимизатора API запросов."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.temp_dir = tempfile.mkdtemp()
        self.optimizer = APIRequestOptimizer(cache_dir=self.temp_dir, max_cache_size=100)
    
    def tearDown(self):
        """Очистка тестового окружения."""
        self.optimizer.cleanup()
        shutil.rmtree(self.temp_dir)
    
    def test_cache_operations(self):
        """Тест операций кэширования."""
        # Тест сохранения и получения данных
        test_data = {"products": [{"id": 1, "name": "Test Product"}]}
        
        # Сохраняем данные в кэш
        self.optimizer.set_cached_data(
            CacheType.PRODUCT_INFO,
            test_data,
            ttl_hours=1,
            endpoint="test",
            param1="value1"
        )
        
        # Получаем данные из кэша
        cached_data = self.optimizer.get_cached_data(
            CacheType.PRODUCT_INFO,
            endpoint="test",
            param1="value1"
        )
        
        self.assertEqual(cached_data, test_data)
        self.assertEqual(self.optimizer._stats['cache_hits'], 1)
        self.assertEqual(self.optimizer._stats['cache_misses'], 0)
    
    def test_cache_miss(self):
        """Тест промаха кэша."""
        # Пытаемся получить несуществующие данные
        cached_data = self.optimizer.get_cached_data(
            CacheType.PRODUCT_INFO,
            endpoint="nonexistent"
        )
        
        self.assertIsNone(cached_data)
        self.assertEqual(self.optimizer._stats['cache_misses'], 1)
    
    def test_batch_size_optimization(self):
        """Тест оптимизации размера батчей."""
        # Тест увеличения размера при хорошей производительности
        new_size = self.optimizer.optimize_batch_size(
            'ozon_stocks',
            success_rate=0.98,
            avg_response_time=1.5
        )
        
        # Размер должен увеличиться
        original_size = 1000
        self.assertGreater(new_size, original_size)
        
        # Тест уменьшения размера при плохой производительности
        new_size = self.optimizer.optimize_batch_size(
            'ozon_stocks',
            success_rate=0.05,
            avg_response_time=15.0
        )
        
        # Размер должен уменьшиться
        self.assertLess(new_size, original_size)
    
    def test_rate_limiting(self):
        """Тест ограничения скорости запросов."""
        # Записываем несколько запросов
        for _ in range(5):
            self.optimizer._record_request('ozon')
        
        # Проверяем, что rate limit срабатывает
        wait_time = self.optimizer._check_rate_limit('ozon')
        self.assertGreaterEqual(wait_time, 0)
    
    def test_performance_stats(self):
        """Тест получения статистики производительности."""
        # Добавляем некоторые данные в статистику
        self.optimizer._stats['cache_hits'] = 10
        self.optimizer._stats['cache_misses'] = 5
        self.optimizer._stats['api_requests'] = 20
        
        stats = self.optimizer.get_performance_stats()
        
        self.assertIn('cache_stats', stats)
        self.assertIn('api_stats', stats)
        self.assertEqual(stats['cache_stats']['hit_rate'], 10/15)  # 10/(10+5)


class TestProductCache(unittest.TestCase):
    """Тесты для кэша товаров."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.cache = ProductCache()
    
    def test_cache_loading(self):
        """Тест загрузки кэша."""
        # Мокаем курсор базы данных
        mock_cursor = Mock()
        mock_cursor.fetchall.return_value = [
            {'id': 1, 'sku_ozon': 'OZON123', 'sku_wb': 'WB456', 'barcode': '1234567890'},
            {'id': 2, 'sku_ozon': 'OZON789', 'sku_wb': None, 'barcode': '0987654321'},
        ]
        
        # Загружаем кэш
        self.cache.load_cache(mock_cursor)
        
        # Проверяем, что данные загружены
        self.assertEqual(self.cache.get_product_id_by_ozon_sku('OZON123'), 1)
        self.assertEqual(self.cache.get_product_id_by_wb_sku('WB456'), 1)
        self.assertEqual(self.cache.get_product_id_by_barcode('1234567890'), 1)
        self.assertEqual(self.cache.get_product_id_by_ozon_sku('OZON789'), 2)
    
    def test_cache_miss(self):
        """Тест промаха кэша."""
        # Мокаем пустой курсор
        mock_cursor = Mock()
        mock_cursor.fetchall.return_value = []
        
        self.cache.load_cache(mock_cursor)
        
        # Проверяем, что несуществующие SKU возвращают None
        self.assertIsNone(self.cache.get_product_id_by_ozon_sku('NONEXISTENT'))
        self.assertIsNone(self.cache.get_product_id_by_wb_sku('NONEXISTENT'))
        self.assertIsNone(self.cache.get_product_id_by_barcode('NONEXISTENT'))


class TestOptimizedInventorySyncService(unittest.TestCase):
    """Тесты для оптимизированного сервиса синхронизации."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.service = OptimizedInventorySyncService(batch_size=100, max_workers=2)
    
    def test_batch_processing(self):
        """Тест пакетной обработки данных."""
        # Создаем тестовые данные
        test_items = [
            {'offer_id': f'TEST{i}', 'stocks': [{'warehouse_name': 'Test', 'type': 'FBO', 'present': i*10, 'reserved': i}]}
            for i in range(1, 11)
        ]
        
        # Мокаем кэш товаров
        self.service.product_cache.get_product_id_by_ozon_sku = Mock(return_value=1)
        
        # Обрабатываем данные
        records = self.service.process_inventory_batch(test_items, 'Ozon')
        
        # Проверяем результаты
        self.assertEqual(len(records), 10)
        self.assertIsInstance(records[0], InventoryRecord)
        self.assertEqual(records[0].source, 'Ozon')
    
    def test_ozon_item_processing(self):
        """Тест обработки элемента Ozon."""
        test_item = {
            'offer_id': 'TEST123',
            'stocks': [
                {'warehouse_name': 'Ozon Main', 'type': 'FBO', 'present': 100, 'reserved': 10},
                {'warehouse_name': 'Ozon FBS', 'type': 'FBS', 'present': 50, 'reserved': 5}
            ]
        }
        
        # Мокаем кэш
        self.service.product_cache.get_product_id_by_ozon_sku = Mock(return_value=1)
        
        records, cache_hits = self.service._process_ozon_item(test_item)
        
        # Проверяем результаты
        self.assertEqual(len(records), 2)
        self.assertEqual(cache_hits, 1)
        self.assertEqual(records[0].warehouse_name, 'Ozon Main')
        self.assertEqual(records[0].quantity_present, 100)
        self.assertEqual(records[0].available_stock, 90)  # 100 - 10
    
    def test_wb_item_processing(self):
        """Тест обработки элемента Wildberries."""
        test_item = {
            'barcode': '1234567890',
            'nmId': 12345,
            'warehouseName': 'WB Main',
            'quantity': 75,
            'inWayToClient': 15
        }
        
        # Мокаем кэш
        self.service.product_cache.get_product_id_by_barcode = Mock(return_value=2)
        
        records, cache_hits = self.service._process_wb_item(test_item)
        
        # Проверяем результаты
        self.assertEqual(len(records), 1)
        self.assertEqual(cache_hits, 1)
        self.assertEqual(records[0].warehouse_name, 'WB Main')
        self.assertEqual(records[0].quantity_present, 75)
        self.assertEqual(records[0].available_stock, 60)  # 75 - 15


class TestParallelSyncManager(unittest.TestCase):
    """Тесты для менеджера параллельной синхронизации."""
    
    def setUp(self):
        """Настройка тестового окружения."""
        self.manager = ParallelSyncManager(
            max_workers=2,
            max_concurrent_marketplaces=2,
            resource_monitoring=False  # Отключаем для тестов
        )
    
    def tearDown(self):
        """Очистка тестового окружения."""
        self.manager.cleanup()
    
    def test_task_addition(self):
        """Тест добавления задач."""
        task_id = self.manager.add_sync_task('Ozon', priority=SyncPriority.HIGH)
        
        self.assertIsNotNone(task_id)
        self.assertIn(task_id, self.manager._active_tasks)
        
        # Проверяем, что задача добавлена в правильную очередь
        high_queue = self.manager._task_queues[SyncPriority.HIGH]
        self.assertFalse(high_queue.empty())
    
    def test_resource_availability_check(self):
        """Тест проверки доступности ресурсов."""
        # Без истории ресурсов должно возвращать True
        self.assertTrue(self.manager._check_resource_availability())
    
    def test_performance_metrics_update(self):
        """Тест обновления метрик производительности."""
        # Добавляем тестовые задачи
        from parallel_sync_manager import SyncTask, SyncResult, SyncStatus
        
        task1 = SyncTask('Ozon', 'inventory_sync', SyncPriority.NORMAL)
        task1.started_at = datetime.now()
        task1.completed_at = datetime.now()
        task1.result = SyncResult(
            source='Ozon',
            status=SyncStatus.SUCCESS,
            records_processed=100,
            records_updated=0,
            records_inserted=100,
            records_failed=0,
            started_at=task1.started_at,
            completed_at=task1.completed_at
        )
        
        self.manager._completed_tasks.append(task1)
        self.manager._update_performance_metrics()
        
        metrics = self.manager._performance_metrics
        self.assertEqual(metrics.total_tasks, 1)
        self.assertEqual(metrics.completed_tasks, 1)
        self.assertEqual(metrics.failed_tasks, 0)


class TestPerformanceIntegration(unittest.TestCase):
    """Интеграционные тесты производительности."""
    
    def test_end_to_end_performance(self):
        """Тест производительности end-to-end."""
        # Создаем временную директорию для кэша
        temp_dir = tempfile.mkdtemp()
        
        try:
            # Инициализируем компоненты
            optimizer = APIRequestOptimizer(cache_dir=temp_dir)
            
            # Тестируем кэширование
            start_time = time.time()
            
            # Сохраняем данные в кэш
            test_data = {"items": [{"id": i, "name": f"Product {i}"} for i in range(1000)]}
            optimizer.set_cached_data(CacheType.PRODUCT_INFO, test_data, endpoint="test")
            
            # Получаем данные из кэша
            cached_data = optimizer.get_cached_data(CacheType.PRODUCT_INFO, endpoint="test")
            
            cache_time = time.time() - start_time
            
            # Проверяем, что кэширование работает быстро
            self.assertLess(cache_time, 0.1)  # Должно быть меньше 100мс
            self.assertEqual(len(cached_data["items"]), 1000)
            
            # Проверяем статистику
            stats = optimizer.get_performance_stats()
            self.assertEqual(stats['cache_stats']['hits'], 1)
            
        finally:
            shutil.rmtree(temp_dir)
    
    def test_batch_processing_performance(self):
        """Тест производительности пакетной обработки."""
        service = OptimizedInventorySyncService(batch_size=500)
        
        # Создаем большой набор тестовых данных
        large_dataset = [
            {'offer_id': f'TEST{i}', 'stocks': [{'warehouse_name': 'Test', 'type': 'FBO', 'present': i, 'reserved': 0}]}
            for i in range(5000)
        ]
        
        # Мокаем кэш для быстрого поиска
        service.product_cache.get_product_id_by_ozon_sku = Mock(return_value=1)
        
        # Измеряем время обработки
        start_time = time.time()
        records = service.process_inventory_batch(large_dataset, 'Ozon')
        processing_time = time.time() - start_time
        
        # Проверяем результаты
        self.assertEqual(len(records), 5000)
        
        # Проверяем, что обработка достаточно быстрая (менее 10 секунд для 5000 записей)
        self.assertLess(processing_time, 10.0)
        
        # Проверяем пропускную способность (записей в секунду)
        throughput = len(records) / processing_time
        self.assertGreater(throughput, 500)  # Минимум 500 записей в секунду


class TestDatabaseOptimizations(unittest.TestCase):
    """Тесты для оптимизаций базы данных."""
    
    def test_batch_upsert_logic(self):
        """Тест логики пакетного UPSERT."""
        # Создаем мок-сервис
        service = OptimizedInventorySyncService(batch_size=100)
        
        # Мокаем подключение к БД
        mock_connection = Mock()
        mock_cursor = Mock()
        service.connection = mock_connection
        service.cursor = mock_cursor
        
        # Создаем тестовые записи
        test_records = [
            InventoryRecord(
                product_id=i,
                sku=f'TEST{i}',
                source='Ozon',
                warehouse_name='Test Warehouse',
                stock_type='FBO',
                current_stock=i*10,
                reserved_stock=i,
                available_stock=i*9,
                quantity_present=i*10,
                quantity_reserved=i,
                snapshot_date=date.today()
            )
            for i in range(1, 251)  # 250 записей
        ]
        
        # Мокаем выполнение запросов
        mock_cursor.rowcount = 0  # Для DELETE
        mock_cursor.executemany = Mock()
        
        # Выполняем пакетное обновление
        updated, inserted, failed = service.batch_upsert_inventory_data(test_records, 'Ozon')
        
        # Проверяем, что данные обрабатываются батчами
        # Должно быть 3 батча: 100 + 100 + 50
        self.assertEqual(mock_cursor.executemany.call_count, 3)
        
        # Проверяем количество вставленных записей
        self.assertEqual(inserted, 250)
        self.assertEqual(failed, 0)


if __name__ == '__main__':
    # Настройка логирования для тестов
    import logging
    logging.basicConfig(level=logging.WARNING)
    
    # Запуск тестов
    unittest.main(verbosity=2)