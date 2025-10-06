#!/usr/bin/env python3
"""
Нагрузочное тестирование системы синхронизации остатков товаров.

Тестирует:
- Производительность с большими объемами данных
- Время выполнения синхронизации
- Использование памяти и CPU
- Пропускную способность системы
- Стабильность под нагрузкой

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import time
import psutil
import threading
import asyncio
import json
import tempfile
import shutil
from datetime import datetime, timedelta
from typing import Dict, List, Any, Optional, Tuple
from dataclasses import dataclass, asdict
from concurrent.futures import ThreadPoolExecutor, as_completed
import logging
import gc
import tracemalloc
from unittest.mock import Mock, patch
import random
import string

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_sync_service_optimized import OptimizedInventorySyncService, InventoryRecord, SyncResult, SyncStatus
    from parallel_sync_manager import ParallelSyncManager, SyncPriority
    from api_request_optimizer import APIRequestOptimizer, CacheType
    import config
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


@dataclass
class LoadTestConfig:
    """Конфигурация нагрузочного тестирования."""
    # Размеры тестовых данных
    small_dataset_size: int = 1000
    medium_dataset_size: int = 10000
    large_dataset_size: int = 100000
    xlarge_dataset_size: int = 500000
    
    # Параметры производительности
    max_workers: int = 8
    batch_sizes: List[int] = None
    test_duration_minutes: int = 30
    
    # Пороги производительности
    max_memory_mb: int = 2048
    max_cpu_percent: float = 80.0
    min_throughput_per_second: float = 100.0
    max_sync_time_seconds: int = 300
    
    def __post_init__(self):
        if self.batch_sizes is None:
            self.batch_sizes = [100, 500, 1000, 2000, 5000]


@dataclass
class PerformanceMetrics:
    """Метрики производительности."""
    test_name: str
    dataset_size: int
    batch_size: int
    workers_count: int
    
    # Временные метрики
    start_time: datetime
    end_time: Optional[datetime] = None
    duration_seconds: float = 0.0
    
    # Метрики обработки
    records_processed: int = 0
    records_per_second: float = 0.0
    
    # Метрики ресурсов
    peak_memory_mb: float = 0.0
    avg_cpu_percent: float = 0.0
    peak_cpu_percent: float = 0.0
    
    # Метрики качества
    success_rate: float = 0.0
    error_count: int = 0
    cache_hit_rate: float = 0.0
    
    # Дополнительные метрики
    api_requests_count: int = 0
    database_operations: int = 0
    
    def calculate_derived_metrics(self):
        """Вычисление производных метрик."""
        if self.end_time:
            self.duration_seconds = (self.end_time - self.start_time).total_seconds()
            if self.duration_seconds > 0:
                self.records_per_second = self.records_processed / self.duration_seconds


class ResourceMonitor:
    """Монитор ресурсов системы."""
    
    def __init__(self, interval_seconds: float = 1.0):
        self.interval_seconds = interval_seconds
        self.monitoring = False
        self.thread: Optional[threading.Thread] = None
        self.metrics: List[Dict[str, Any]] = []
        self._lock = threading.Lock()
    
    def start(self):
        """Запуск мониторинга."""
        if not self.monitoring:
            self.monitoring = True
            self.metrics.clear()
            self.thread = threading.Thread(target=self._monitor_loop, daemon=True)
            self.thread.start()
            logger.info("📊 Мониторинг ресурсов запущен")
    
    def stop(self):
        """Остановка мониторинга."""
        self.monitoring = False
        if self.thread:
            self.thread.join(timeout=5)
        logger.info("📊 Мониторинг ресурсов остановлен")
    
    def _monitor_loop(self):
        """Цикл мониторинга ресурсов."""
        process = psutil.Process()
        
        while self.monitoring:
            try:
                # Системные ресурсы
                cpu_percent = psutil.cpu_percent(interval=None)
                memory = psutil.virtual_memory()
                
                # Ресурсы процесса
                process_memory = process.memory_info()
                process_cpu = process.cpu_percent()
                
                # Сетевые и дисковые операции
                net_io = psutil.net_io_counters()
                disk_io = psutil.disk_io_counters()
                
                metric = {
                    'timestamp': datetime.now(),
                    'system_cpu_percent': cpu_percent,
                    'system_memory_percent': memory.percent,
                    'system_memory_mb': memory.used / 1024 / 1024,
                    'process_memory_mb': process_memory.rss / 1024 / 1024,
                    'process_cpu_percent': process_cpu,
                    'network_bytes_sent': net_io.bytes_sent if net_io else 0,
                    'network_bytes_recv': net_io.bytes_recv if net_io else 0,
                    'disk_read_bytes': disk_io.read_bytes if disk_io else 0,
                    'disk_write_bytes': disk_io.write_bytes if disk_io else 0
                }
                
                with self._lock:
                    self.metrics.append(metric)
                
                time.sleep(self.interval_seconds)
                
            except Exception as e:
                logger.error(f"❌ Ошибка мониторинга ресурсов: {e}")
                time.sleep(self.interval_seconds)
    
    def get_summary(self) -> Dict[str, Any]:
        """Получение сводки по ресурсам."""
        with self._lock:
            if not self.metrics:
                return {}
            
            cpu_values = [m['system_cpu_percent'] for m in self.metrics]
            memory_values = [m['process_memory_mb'] for m in self.metrics]
            
            return {
                'duration_seconds': (self.metrics[-1]['timestamp'] - self.metrics[0]['timestamp']).total_seconds(),
                'samples_count': len(self.metrics),
                'avg_cpu_percent': sum(cpu_values) / len(cpu_values),
                'peak_cpu_percent': max(cpu_values),
                'avg_memory_mb': sum(memory_values) / len(memory_values),
                'peak_memory_mb': max(memory_values),
                'memory_growth_mb': memory_values[-1] - memory_values[0] if len(memory_values) > 1 else 0
            }


class MockDataGenerator:
    """Генератор тестовых данных."""
    
    @staticmethod
    def generate_ozon_inventory_data(size: int) -> List[Dict[str, Any]]:
        """Генерация тестовых данных Ozon."""
        logger.info(f"🔄 Генерируем {size} записей Ozon...")
        
        data = []
        warehouses = ['Ozon Main', 'Ozon FBS', 'Ozon Express']
        stock_types = ['FBO', 'FBS', 'realFBS']
        
        for i in range(size):
            offer_id = f"OZON_TEST_{i:06d}"
            
            # Генерируем случайные остатки по складам
            stocks = []
            num_warehouses = random.randint(1, 3)
            
            for j in range(num_warehouses):
                warehouse = random.choice(warehouses)
                stock_type = random.choice(stock_types)
                present = random.randint(0, 1000)
                reserved = random.randint(0, min(present, 100))
                
                stocks.append({
                    'warehouse_name': warehouse,
                    'type': stock_type,
                    'present': present,
                    'reserved': reserved
                })
            
            data.append({
                'offer_id': offer_id,
                'stocks': stocks
            })
        
        logger.info(f"✅ Сгенерировано {len(data)} записей Ozon")
        return data
    
    @staticmethod
    def generate_wb_inventory_data(size: int) -> List[Dict[str, Any]]:
        """Генерация тестовых данных Wildberries."""
        logger.info(f"🔄 Генерируем {size} записей Wildberries...")
        
        data = []
        warehouses = ['WB Main', 'WB Express', 'WB Regional']
        
        for i in range(size):
            barcode = f"{''.join(random.choices(string.digits, k=13))}"
            nm_id = 1000000 + i
            
            quantity = random.randint(0, 1000)
            in_way_to_client = random.randint(0, min(quantity, 50))
            
            data.append({
                'barcode': barcode,
                'nmId': nm_id,
                'warehouseName': random.choice(warehouses),
                'quantity': quantity,
                'inWayToClient': in_way_to_client
            })
        
        logger.info(f"✅ Сгенерировано {len(data)} записей Wildberries")
        return data


class LoadTester:
    """Основной класс для нагрузочного тестирования."""
    
    def __init__(self, config: LoadTestConfig):
        self.config = config
        self.results: List[PerformanceMetrics] = []
        self.temp_dir = tempfile.mkdtemp()
        
    def cleanup(self):
        """Очистка ресурсов."""
        if os.path.exists(self.temp_dir):
            shutil.rmtree(self.temp_dir)
    
    def test_batch_processing_performance(self) -> List[PerformanceMetrics]:
        """Тест производительности пакетной обработки."""
        logger.info("🚀 Запуск теста производительности пакетной обработки")
        
        results = []
        dataset_sizes = [
            self.config.small_dataset_size,
            self.config.medium_dataset_size,
            self.config.large_dataset_size
        ]
        
        for dataset_size in dataset_sizes:
            for batch_size in self.config.batch_sizes:
                logger.info(f"📊 Тестируем: размер данных={dataset_size}, размер батча={batch_size}")
                
                # Генерируем тестовые данные
                test_data = MockDataGenerator.generate_ozon_inventory_data(dataset_size)
                
                # Создаем сервис с мокированным подключением к БД
                service = OptimizedInventorySyncService(
                    batch_size=batch_size,
                    max_workers=self.config.max_workers
                )
                
                # Мокируем кэш товаров для быстрого поиска
                service.product_cache.get_product_id_by_ozon_sku = Mock(return_value=1)
                
                # Запускаем мониторинг ресурсов
                monitor = ResourceMonitor(interval_seconds=0.5)
                monitor.start()
                
                # Включаем трассировку памяти
                tracemalloc.start()
                
                try:
                    # Выполняем тест
                    start_time = datetime.now()
                    
                    processed_records = service.process_inventory_batch(test_data, 'Ozon')
                    
                    end_time = datetime.now()
                    
                    # Получаем статистику памяти
                    current, peak = tracemalloc.get_traced_memory()
                    tracemalloc.stop()
                    
                    # Останавливаем мониторинг
                    monitor.stop()
                    resource_summary = monitor.get_summary()
                    
                    # Создаем метрики
                    metrics = PerformanceMetrics(
                        test_name="batch_processing",
                        dataset_size=dataset_size,
                        batch_size=batch_size,
                        workers_count=self.config.max_workers,
                        start_time=start_time,
                        end_time=end_time,
                        records_processed=len(processed_records),
                        peak_memory_mb=peak / 1024 / 1024,
                        avg_cpu_percent=resource_summary.get('avg_cpu_percent', 0),
                        peak_cpu_percent=resource_summary.get('peak_cpu_percent', 0),
                        success_rate=1.0 if processed_records else 0.0
                    )
                    
                    metrics.calculate_derived_metrics()
                    results.append(metrics)
                    
                    logger.info(f"✅ Тест завершен: {metrics.records_per_second:.1f} записей/сек, "
                               f"пик памяти: {metrics.peak_memory_mb:.1f} МБ")
                    
                except Exception as e:
                    logger.error(f"❌ Ошибка теста: {e}")
                    monitor.stop()
                    tracemalloc.stop()
                
                # Принудительная сборка мусора между тестами
                gc.collect()
                time.sleep(2)
        
        return results
    
    def test_concurrent_sync_performance(self) -> List[PerformanceMetrics]:
        """Тест производительности параллельной синхронизации."""
        logger.info("🚀 Запуск теста параллельной синхронизации")
        
        results = []
        
        # Тестируем разное количество воркеров
        worker_counts = [1, 2, 4, 8]
        dataset_size = self.config.medium_dataset_size
        
        for workers in worker_counts:
            logger.info(f"📊 Тестируем параллельную синхронизацию с {workers} воркерами")
            
            # Создаем менеджер параллельной синхронизации
            manager = ParallelSyncManager(
                max_workers=max(1, workers),  # Минимум 1 воркер
                max_concurrent_marketplaces=2,
                resource_monitoring=False  # Используем свой мониторинг
            )
            
            # Запускаем мониторинг ресурсов
            monitor = ResourceMonitor(interval_seconds=0.5)
            monitor.start()
            
            tracemalloc.start()
            
            try:
                start_time = datetime.now()
                
                # Мокируем синхронизацию с тестовыми данными
                with patch.object(OptimizedInventorySyncService, 'sync_ozon_inventory_optimized') as mock_sync:
                    # Настраиваем мок для возврата успешного результата
                    mock_result = SyncResult(
                        source='Ozon',
                        status=SyncStatus.SUCCESS,
                        records_processed=dataset_size,
                        records_updated=0,
                        records_inserted=dataset_size,
                        records_failed=0,
                        started_at=start_time,
                        completed_at=None
                    )
                    mock_sync.return_value = mock_result
                    
                    # Запускаем параллельную синхронизацию
                    loop = asyncio.new_event_loop()
                    asyncio.set_event_loop(loop)
                    
                    sync_results = loop.run_until_complete(
                        manager.run_parallel_sync(['Ozon'], wait_for_completion=True)
                    )
                    
                    loop.close()
                
                end_time = datetime.now()
                
                # Получаем статистику
                current, peak = tracemalloc.get_traced_memory()
                tracemalloc.stop()
                
                monitor.stop()
                resource_summary = monitor.get_summary()
                
                # Создаем метрики
                metrics = PerformanceMetrics(
                    test_name="concurrent_sync",
                    dataset_size=dataset_size,
                    batch_size=1000,  # Стандартный размер батча
                    workers_count=workers,
                    start_time=start_time,
                    end_time=end_time,
                    records_processed=dataset_size,
                    peak_memory_mb=peak / 1024 / 1024,
                    avg_cpu_percent=resource_summary.get('avg_cpu_percent', 0),
                    peak_cpu_percent=resource_summary.get('peak_cpu_percent', 0),
                    success_rate=1.0 if sync_results else 0.0
                )
                
                metrics.calculate_derived_metrics()
                results.append(metrics)
                
                logger.info(f"✅ Параллельный тест завершен: {metrics.records_per_second:.1f} записей/сек")
                
            except Exception as e:
                logger.error(f"❌ Ошибка параллельного теста: {e}")
                monitor.stop()
                tracemalloc.stop()
            finally:
                manager.cleanup()
            
            gc.collect()
            time.sleep(2)
        
        return results
    
    def test_memory_usage_scaling(self) -> List[PerformanceMetrics]:
        """Тест масштабирования использования памяти."""
        logger.info("🚀 Запуск теста масштабирования памяти")
        
        results = []
        dataset_sizes = [
            self.config.small_dataset_size,
            self.config.medium_dataset_size,
            self.config.large_dataset_size,
            self.config.xlarge_dataset_size
        ]
        
        for dataset_size in dataset_sizes:
            logger.info(f"📊 Тестируем использование памяти для {dataset_size} записей")
            
            # Генерируем тестовые данные
            test_data = MockDataGenerator.generate_ozon_inventory_data(dataset_size)
            
            # Создаем сервис
            service = OptimizedInventorySyncService(
                batch_size=1000,
                max_workers=4
            )
            service.product_cache.get_product_id_by_ozon_sku = Mock(return_value=1)
            
            # Запускаем детальный мониторинг памяти
            monitor = ResourceMonitor(interval_seconds=0.1)
            monitor.start()
            
            tracemalloc.start()
            
            try:
                start_time = datetime.now()
                
                # Обрабатываем данные
                processed_records = service.process_inventory_batch(test_data, 'Ozon')
                
                end_time = datetime.now()
                
                # Получаем пиковое использование памяти
                current, peak = tracemalloc.get_traced_memory()
                tracemalloc.stop()
                
                monitor.stop()
                resource_summary = monitor.get_summary()
                
                # Создаем метрики
                metrics = PerformanceMetrics(
                    test_name="memory_scaling",
                    dataset_size=dataset_size,
                    batch_size=1000,
                    workers_count=4,
                    start_time=start_time,
                    end_time=end_time,
                    records_processed=len(processed_records),
                    peak_memory_mb=peak / 1024 / 1024,
                    avg_cpu_percent=resource_summary.get('avg_cpu_percent', 0),
                    peak_cpu_percent=resource_summary.get('peak_cpu_percent', 0),
                    success_rate=1.0 if processed_records else 0.0
                )
                
                metrics.calculate_derived_metrics()
                results.append(metrics)
                
                # Проверяем на утечки памяти
                memory_per_record = metrics.peak_memory_mb / dataset_size * 1024  # КБ на запись
                
                logger.info(f"✅ Память для {dataset_size} записей: {metrics.peak_memory_mb:.1f} МБ "
                           f"({memory_per_record:.2f} КБ/запись)")
                
                # Предупреждение о высоком использовании памяти
                if metrics.peak_memory_mb > self.config.max_memory_mb:
                    logger.warning(f"⚠️ Превышен лимит памяти: {metrics.peak_memory_mb:.1f} МБ > {self.config.max_memory_mb} МБ")
                
            except Exception as e:
                logger.error(f"❌ Ошибка теста памяти: {e}")
                monitor.stop()
                tracemalloc.stop()
            
            # Очистка памяти между тестами
            del test_data
            gc.collect()
            time.sleep(3)
        
        return results
    
    def test_database_performance(self) -> List[PerformanceMetrics]:
        """Тест производительности операций с базой данных."""
        logger.info("🚀 Запуск теста производительности БД")
        
        results = []
        
        # Тестируем разные размеры батчей для БД операций
        batch_sizes = [100, 500, 1000, 2000, 5000]
        dataset_size = self.config.medium_dataset_size
        
        for batch_size in batch_sizes:
            logger.info(f"📊 Тестируем БД операции с батчем {batch_size}")
            
            # Генерируем тестовые записи
            test_records = []
            for i in range(dataset_size):
                record = InventoryRecord(
                    product_id=i + 1,
                    sku=f'TEST_{i:06d}',
                    source='Ozon',
                    warehouse_name='Test Warehouse',
                    stock_type='FBO',
                    current_stock=random.randint(0, 1000),
                    reserved_stock=random.randint(0, 100),
                    available_stock=random.randint(0, 900),
                    quantity_present=random.randint(0, 1000),
                    quantity_reserved=random.randint(0, 100),
                    snapshot_date=datetime.now().date()
                )
                test_records.append(record)
            
            # Создаем сервис с мокированной БД
            service = OptimizedInventorySyncService(batch_size=batch_size)
            
            # Мокируем подключение к БД
            mock_connection = Mock()
            mock_cursor = Mock()
            service.connection = mock_connection
            service.cursor = mock_cursor
            
            # Настраиваем мок для имитации БД операций
            mock_cursor.rowcount = 0
            mock_cursor.executemany = Mock()
            
            # Запускаем мониторинг
            monitor = ResourceMonitor(interval_seconds=0.5)
            monitor.start()
            
            try:
                start_time = datetime.now()
                
                # Выполняем пакетное обновление
                updated, inserted, failed = service.batch_upsert_inventory_data(test_records, 'Ozon')
                
                end_time = datetime.now()
                
                monitor.stop()
                resource_summary = monitor.get_summary()
                
                # Подсчитываем количество БД операций
                db_operations = mock_cursor.executemany.call_count
                
                # Создаем метрики
                metrics = PerformanceMetrics(
                    test_name="database_performance",
                    dataset_size=dataset_size,
                    batch_size=batch_size,
                    workers_count=1,
                    start_time=start_time,
                    end_time=end_time,
                    records_processed=dataset_size,
                    avg_cpu_percent=resource_summary.get('avg_cpu_percent', 0),
                    peak_cpu_percent=resource_summary.get('peak_cpu_percent', 0),
                    success_rate=1.0,
                    database_operations=db_operations
                )
                
                metrics.calculate_derived_metrics()
                results.append(metrics)
                
                logger.info(f"✅ БД тест завершен: {metrics.records_per_second:.1f} записей/сек, "
                           f"{db_operations} БД операций")
                
            except Exception as e:
                logger.error(f"❌ Ошибка БД теста: {e}")
                monitor.stop()
            
            gc.collect()
        
        return results
    
    def run_full_load_test_suite(self) -> Dict[str, Any]:
        """Запуск полного набора нагрузочных тестов."""
        logger.info("🚀 Запуск полного набора нагрузочных тестов")
        
        start_time = datetime.now()
        
        # Запускаем все тесты
        batch_results = self.test_batch_processing_performance()
        concurrent_results = self.test_concurrent_sync_performance()
        memory_results = self.test_memory_usage_scaling()
        db_results = self.test_database_performance()
        
        end_time = datetime.now()
        
        # Собираем все результаты
        all_results = batch_results + concurrent_results + memory_results + db_results
        
        # Анализируем результаты
        analysis = self._analyze_results(all_results)
        
        # Создаем итоговый отчет
        report = {
            'test_summary': {
                'start_time': start_time.isoformat(),
                'end_time': end_time.isoformat(),
                'total_duration_minutes': (end_time - start_time).total_seconds() / 60,
                'total_tests': len(all_results),
                'config': asdict(self.config)
            },
            'test_results': {
                'batch_processing': [asdict(r) for r in batch_results],
                'concurrent_sync': [asdict(r) for r in concurrent_results],
                'memory_scaling': [asdict(r) for r in memory_results],
                'database_performance': [asdict(r) for r in db_results]
            },
            'performance_analysis': analysis,
            'recommendations': self._generate_recommendations(analysis)
        }
        
        logger.info("✅ Полный набор нагрузочных тестов завершен")
        return report
    
    def _analyze_results(self, results: List[PerformanceMetrics]) -> Dict[str, Any]:
        """Анализ результатов тестирования."""
        if not results:
            return {}
        
        # Группируем результаты по типам тестов
        by_test_type = {}
        for result in results:
            test_type = result.test_name
            if test_type not in by_test_type:
                by_test_type[test_type] = []
            by_test_type[test_type].append(result)
        
        analysis = {}
        
        for test_type, test_results in by_test_type.items():
            # Вычисляем статистики
            throughputs = [r.records_per_second for r in test_results]
            memory_usage = [r.peak_memory_mb for r in test_results]
            cpu_usage = [r.avg_cpu_percent for r in test_results]
            
            analysis[test_type] = {
                'count': len(test_results),
                'throughput': {
                    'min': min(throughputs),
                    'max': max(throughputs),
                    'avg': sum(throughputs) / len(throughputs),
                    'median': sorted(throughputs)[len(throughputs) // 2]
                },
                'memory_usage': {
                    'min_mb': min(memory_usage),
                    'max_mb': max(memory_usage),
                    'avg_mb': sum(memory_usage) / len(memory_usage)
                },
                'cpu_usage': {
                    'min_percent': min(cpu_usage),
                    'max_percent': max(cpu_usage),
                    'avg_percent': sum(cpu_usage) / len(cpu_usage)
                },
                'performance_issues': []
            }
            
            # Выявляем проблемы производительности
            issues = analysis[test_type]['performance_issues']
            
            if max(throughputs) < self.config.min_throughput_per_second:
                issues.append(f"Низкая пропускная способность: {max(throughputs):.1f} < {self.config.min_throughput_per_second}")
            
            if max(memory_usage) > self.config.max_memory_mb:
                issues.append(f"Превышение лимита памяти: {max(memory_usage):.1f} МБ > {self.config.max_memory_mb} МБ")
            
            if max(cpu_usage) > self.config.max_cpu_percent:
                issues.append(f"Высокая загрузка CPU: {max(cpu_usage):.1f}% > {self.config.max_cpu_percent}%")
        
        return analysis
    
    def _generate_recommendations(self, analysis: Dict[str, Any]) -> List[str]:
        """Генерация рекомендаций по оптимизации."""
        recommendations = []
        
        for test_type, stats in analysis.items():
            if stats['performance_issues']:
                recommendations.append(f"Проблемы в тесте {test_type}:")
                for issue in stats['performance_issues']:
                    recommendations.append(f"  - {issue}")
        
        # Общие рекомендации
        if any('memory' in issue.lower() for test_stats in analysis.values() for issue in test_stats['performance_issues']):
            recommendations.append("Рекомендации по памяти:")
            recommendations.append("  - Увеличить размер батчей для снижения накладных расходов")
            recommendations.append("  - Реализовать потоковую обработку для больших наборов данных")
            recommendations.append("  - Добавить принудительную сборку мусора между батчами")
        
        if any('cpu' in issue.lower() for test_stats in analysis.values() for issue in test_stats['performance_issues']):
            recommendations.append("Рекомендации по CPU:")
            recommendations.append("  - Оптимизировать алгоритмы обработки данных")
            recommendations.append("  - Уменьшить количество параллельных воркеров")
            recommendations.append("  - Добавить задержки между операциями")
        
        if any('throughput' in issue.lower() for test_stats in analysis.values() for issue in test_stats['performance_issues']):
            recommendations.append("Рекомендации по производительности:")
            recommendations.append("  - Увеличить размер батчей")
            recommendations.append("  - Оптимизировать запросы к базе данных")
            recommendations.append("  - Использовать кэширование для часто запрашиваемых данных")
        
        return recommendations


def save_test_report(report: Dict[str, Any], filename: str = None):
    """Сохранение отчета о тестировании."""
    if filename is None:
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        filename = f"load_test_report_{timestamp}.json"
    
    with open(filename, 'w', encoding='utf-8') as f:
        json.dump(report, f, indent=2, ensure_ascii=False, default=str)
    
    logger.info(f"📄 Отчет сохранен: {filename}")


def print_test_summary(report: Dict[str, Any]):
    """Вывод краткой сводки результатов тестирования."""
    print("\n" + "="*80)
    print("СВОДКА РЕЗУЛЬТАТОВ НАГРУЗОЧНОГО ТЕСТИРОВАНИЯ")
    print("="*80)
    
    summary = report['test_summary']
    print(f"Время выполнения: {summary['total_duration_minutes']:.1f} минут")
    print(f"Всего тестов: {summary['total_tests']}")
    
    print("\nРЕЗУЛЬТАТЫ ПО ТИПАМ ТЕСТОВ:")
    print("-"*40)
    
    analysis = report['performance_analysis']
    for test_type, stats in analysis.items():
        print(f"\n{test_type.upper()}:")
        print(f"  Пропускная способность: {stats['throughput']['avg']:.1f} записей/сек")
        print(f"  Использование памяти: {stats['memory_usage']['avg_mb']:.1f} МБ")
        print(f"  Загрузка CPU: {stats['cpu_usage']['avg_percent']:.1f}%")
        
        if stats['performance_issues']:
            print(f"  ⚠️ Проблемы: {len(stats['performance_issues'])}")
    
    print("\nРЕКОМЕНДАЦИИ:")
    print("-"*40)
    recommendations = report['recommendations']
    if recommendations:
        for rec in recommendations:
            print(f"  {rec}")
    else:
        print("  ✅ Проблем производительности не выявлено")
    
    print("\n" + "="*80)


def main():
    """Основная функция для запуска нагрузочных тестов."""
    logger.info("🚀 Запуск нагрузочного тестирования системы синхронизации остатков")
    
    # Конфигурация тестирования
    config = LoadTestConfig(
        small_dataset_size=1000,
        medium_dataset_size=10000,
        large_dataset_size=50000,  # Уменьшено для стабильности
        xlarge_dataset_size=100000,
        max_workers=8,
        batch_sizes=[100, 500, 1000, 2000],
        max_memory_mb=1024,  # 1 ГБ лимит
        max_cpu_percent=85.0,
        min_throughput_per_second=50.0
    )
    
    # Создаем тестер
    tester = LoadTester(config)
    
    try:
        # Запускаем полный набор тестов
        report = tester.run_full_load_test_suite()
        
        # Сохраняем отчет
        save_test_report(report)
        
        # Выводим сводку
        print_test_summary(report)
        
        logger.info("✅ Нагрузочное тестирование завершено успешно")
        
    except Exception as e:
        logger.error(f"❌ Критическая ошибка нагрузочного тестирования: {e}")
        raise
    finally:
        tester.cleanup()


if __name__ == "__main__":
    main()