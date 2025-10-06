#!/usr/bin/env python3
"""
Бенчмарк производительности системы синхронизации остатков.

Измеряет:
- Время выполнения различных операций
- Пропускную способность системы
- Использование ресурсов
- Эффективность кэширования
- Производительность базы данных

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import time
import psutil
import threading
import statistics
from datetime import datetime, timedelta
from typing import Dict, List, Any, Optional, Callable
from dataclasses import dataclass, asdict
from concurrent.futures import ThreadPoolExecutor
import logging
import json
import tempfile
import shutil
from contextlib import contextmanager
import tracemalloc
import gc

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_sync_service_optimized import OptimizedInventorySyncService, InventoryRecord
    from api_request_optimizer import APIRequestOptimizer, CacheType
    from parallel_sync_manager import ParallelSyncManager
    from load_test_inventory_sync import MockDataGenerator, ResourceMonitor
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
class BenchmarkResult:
    """Результат бенчмарка."""
    operation_name: str
    dataset_size: int
    iterations: int
    
    # Временные метрики
    total_time_seconds: float
    avg_time_seconds: float
    min_time_seconds: float
    max_time_seconds: float
    median_time_seconds: float
    
    # Метрики производительности
    throughput_per_second: float
    operations_per_second: float
    
    # Метрики ресурсов
    peak_memory_mb: float
    avg_cpu_percent: float
    
    # Дополнительные метрики
    cache_hit_rate: float = 0.0
    error_rate: float = 0.0
    
    def to_dict(self) -> Dict[str, Any]:
        """Преобразование в словарь."""
        return asdict(self)


class PerformanceBenchmark:
    """Класс для проведения бенчмарков производительности."""
    
    def __init__(self):
        self.temp_dir = tempfile.mkdtemp()
        self.results: List[BenchmarkResult] = []
    
    def cleanup(self):
        """Очистка ресурсов."""
        if os.path.exists(self.temp_dir):
            shutil.rmtree(self.temp_dir)
    
    @contextmanager
    def measure_performance(self, operation_name: str):
        """Контекстный менеджер для измерения производительности."""
        # Запускаем мониторинг ресурсов
        monitor = ResourceMonitor(interval_seconds=0.1)
        monitor.start()
        
        # Включаем трассировку памяти
        tracemalloc.start()
        
        start_time = time.perf_counter()
        
        try:
            yield
        finally:
            end_time = time.perf_counter()
            
            # Получаем статистику памяти
            current, peak = tracemalloc.get_traced_memory()
            tracemalloc.stop()
            
            # Останавливаем мониторинг
            monitor.stop()
            resource_summary = monitor.get_summary()
            
            # Сохраняем результаты
            self._last_measurement = {
                'duration': end_time - start_time,
                'peak_memory_mb': peak / 1024 / 1024,
                'avg_cpu_percent': resource_summary.get('avg_cpu_percent', 0),
                'resource_summary': resource_summary
            }
    
    def benchmark_data_processing(self, dataset_sizes: List[int], iterations: int = 5) -> List[BenchmarkResult]:
        """Бенчмарк обработки данных."""
        logger.info("🚀 Запуск бенчмарка обработки данных")
        
        results = []
        
        for dataset_size in dataset_sizes:
            logger.info(f"📊 Бенчмарк обработки {dataset_size} записей")
            
            times = []
            memory_peaks = []
            cpu_averages = []
            
            for iteration in range(iterations):
                # Генерируем тестовые данные
                test_data = MockDataGenerator.generate_ozon_inventory_data(dataset_size)
                
                # Создаем сервис
                service = OptimizedInventorySyncService(batch_size=1000, max_workers=4)
                service.product_cache.get_product_id_by_ozon_sku = lambda x: 1  # Мок кэша
                
                with self.measure_performance(f"data_processing_{dataset_size}"):
                    processed_records = service.process_inventory_batch(test_data, 'Ozon')
                
                # Сохраняем метрики
                measurement = self._last_measurement
                times.append(measurement['duration'])
                memory_peaks.append(measurement['peak_memory_mb'])
                cpu_averages.append(measurement['avg_cpu_percent'])
                
                # Очистка памяти между итерациями
                del test_data, processed_records
                gc.collect()
            
            # Создаем результат бенчмарка
            result = BenchmarkResult(
                operation_name="data_processing",
                dataset_size=dataset_size,
                iterations=iterations,
                total_time_seconds=sum(times),
                avg_time_seconds=statistics.mean(times),
                min_time_seconds=min(times),
                max_time_seconds=max(times),
                median_time_seconds=statistics.median(times),
                throughput_per_second=dataset_size / statistics.mean(times),
                operations_per_second=1 / statistics.mean(times),
                peak_memory_mb=max(memory_peaks),
                avg_cpu_percent=statistics.mean(cpu_averages)
            )
            
            results.append(result)
            
            logger.info(f"✅ Обработка {dataset_size} записей: {result.throughput_per_second:.1f} записей/сек, "
                       f"память: {result.peak_memory_mb:.1f} МБ")
        
        return results
    
    def benchmark_cache_performance(self, cache_sizes: List[int], iterations: int = 10) -> List[BenchmarkResult]:
        """Бенчмарк производительности кэширования."""
        logger.info("🚀 Запуск бенчмарка кэширования")
        
        results = []
        
        for cache_size in cache_sizes:
            logger.info(f"📊 Бенчмарк кэша размером {cache_size}")
            
            # Создаем оптимизатор API с кэшем
            optimizer = APIRequestOptimizer(cache_dir=self.temp_dir, max_cache_size=cache_size)
            
            times = []
            hit_rates = []
            
            for iteration in range(iterations):
                # Генерируем тестовые данные для кэширования
                test_data = {
                    "products": [
                        {"id": i, "name": f"Product {i}", "price": i * 10}
                        for i in range(cache_size)
                    ]
                }
                
                with self.measure_performance(f"cache_operations_{cache_size}"):
                    # Записываем данные в кэш
                    for i in range(cache_size):
                        optimizer.set_cached_data(
                            CacheType.PRODUCT_INFO,
                            {"product": test_data["products"][i]},
                            endpoint=f"product_{i}"
                        )
                    
                    # Читаем данные из кэша
                    cache_hits = 0
                    for i in range(cache_size):
                        cached_data = optimizer.get_cached_data(
                            CacheType.PRODUCT_INFO,
                            endpoint=f"product_{i}"
                        )
                        if cached_data:
                            cache_hits += 1
                
                # Сохраняем метрики
                measurement = self._last_measurement
                times.append(measurement['duration'])
                hit_rates.append(cache_hits / cache_size)
            
            # Создаем результат бенчмарка
            result = BenchmarkResult(
                operation_name="cache_operations",
                dataset_size=cache_size,
                iterations=iterations,
                total_time_seconds=sum(times),
                avg_time_seconds=statistics.mean(times),
                min_time_seconds=min(times),
                max_time_seconds=max(times),
                median_time_seconds=statistics.median(times),
                throughput_per_second=cache_size * 2 / statistics.mean(times),  # read + write
                operations_per_second=1 / statistics.mean(times),
                peak_memory_mb=self._last_measurement['peak_memory_mb'],
                avg_cpu_percent=statistics.mean([self._last_measurement['avg_cpu_percent']]),
                cache_hit_rate=statistics.mean(hit_rates)
            )
            
            results.append(result)
            
            logger.info(f"✅ Кэш {cache_size} записей: {result.throughput_per_second:.1f} операций/сек, "
                       f"hit rate: {result.cache_hit_rate:.2%}")
            
            # Очистка кэша
            optimizer.cleanup()
        
        return results
    
    def benchmark_batch_sizes(self, dataset_size: int, batch_sizes: List[int], iterations: int = 3) -> List[BenchmarkResult]:
        """Бенчмарк различных размеров батчей."""
        logger.info("🚀 Запуск бенчмарка размеров батчей")
        
        results = []
        
        # Генерируем тестовые данные один раз
        test_data = MockDataGenerator.generate_ozon_inventory_data(dataset_size)
        
        for batch_size in batch_sizes:
            logger.info(f"📊 Бенчмарк батча размером {batch_size}")
            
            times = []
            memory_peaks = []
            cpu_averages = []
            
            for iteration in range(iterations):
                # Создаем сервис с определенным размером батча
                service = OptimizedInventorySyncService(batch_size=batch_size, max_workers=4)
                service.product_cache.get_product_id_by_ozon_sku = lambda x: 1
                
                with self.measure_performance(f"batch_processing_{batch_size}"):
                    processed_records = service.process_inventory_batch(test_data, 'Ozon')
                
                # Сохраняем метрики
                measurement = self._last_measurement
                times.append(measurement['duration'])
                memory_peaks.append(measurement['peak_memory_mb'])
                cpu_averages.append(measurement['avg_cpu_percent'])
                
                del processed_records
                gc.collect()
            
            # Создаем результат бенчмарка
            result = BenchmarkResult(
                operation_name="batch_processing",
                dataset_size=batch_size,  # Используем batch_size как dataset_size для этого теста
                iterations=iterations,
                total_time_seconds=sum(times),
                avg_time_seconds=statistics.mean(times),
                min_time_seconds=min(times),
                max_time_seconds=max(times),
                median_time_seconds=statistics.median(times),
                throughput_per_second=dataset_size / statistics.mean(times),
                operations_per_second=1 / statistics.mean(times),
                peak_memory_mb=max(memory_peaks),
                avg_cpu_percent=statistics.mean(cpu_averages)
            )
            
            results.append(result)
            
            logger.info(f"✅ Батч {batch_size}: {result.throughput_per_second:.1f} записей/сек, "
                       f"время: {result.avg_time_seconds:.2f}с")
        
        return results
    
    def benchmark_parallel_workers(self, dataset_size: int, worker_counts: List[int], iterations: int = 3) -> List[BenchmarkResult]:
        """Бенчмарк различного количества параллельных воркеров."""
        logger.info("🚀 Запуск бенчмарка параллельных воркеров")
        
        results = []
        
        # Генерируем тестовые данные один раз
        test_data = MockDataGenerator.generate_ozon_inventory_data(dataset_size)
        
        for workers in worker_counts:
            logger.info(f"📊 Бенчмарк с {workers} воркерами")
            
            times = []
            memory_peaks = []
            cpu_averages = []
            
            for iteration in range(iterations):
                # Создаем сервис с определенным количеством воркеров
                service = OptimizedInventorySyncService(batch_size=1000, max_workers=workers)
                service.product_cache.get_product_id_by_ozon_sku = lambda x: 1
                
                with self.measure_performance(f"parallel_workers_{workers}"):
                    processed_records = service.process_inventory_batch(test_data, 'Ozon')
                
                # Сохраняем метрики
                measurement = self._last_measurement
                times.append(measurement['duration'])
                memory_peaks.append(measurement['peak_memory_mb'])
                cpu_averages.append(measurement['avg_cpu_percent'])
                
                del processed_records
                gc.collect()
            
            # Создаем результат бенчмарка
            result = BenchmarkResult(
                operation_name="parallel_workers",
                dataset_size=workers,  # Используем workers как dataset_size для этого теста
                iterations=iterations,
                total_time_seconds=sum(times),
                avg_time_seconds=statistics.mean(times),
                min_time_seconds=min(times),
                max_time_seconds=max(times),
                median_time_seconds=statistics.median(times),
                throughput_per_second=dataset_size / statistics.mean(times),
                operations_per_second=1 / statistics.mean(times),
                peak_memory_mb=max(memory_peaks),
                avg_cpu_percent=statistics.mean(cpu_averages)
            )
            
            results.append(result)
            
            logger.info(f"✅ {workers} воркеров: {result.throughput_per_second:.1f} записей/сек, "
                       f"CPU: {result.avg_cpu_percent:.1f}%")
        
        return results
    
    def benchmark_memory_efficiency(self, dataset_sizes: List[int]) -> List[BenchmarkResult]:
        """Бенчмарк эффективности использования памяти."""
        logger.info("🚀 Запуск бенчмарка эффективности памяти")
        
        results = []
        
        for dataset_size in dataset_sizes:
            logger.info(f"📊 Бенчмарк памяти для {dataset_size} записей")
            
            # Измеряем базовое использование памяти
            gc.collect()
            process = psutil.Process()
            baseline_memory = process.memory_info().rss / 1024 / 1024
            
            # Генерируем данные и измеряем пиковое использование
            with self.measure_performance(f"memory_efficiency_{dataset_size}"):
                test_data = MockDataGenerator.generate_ozon_inventory_data(dataset_size)
                
                service = OptimizedInventorySyncService(batch_size=1000, max_workers=4)
                service.product_cache.get_product_id_by_ozon_sku = lambda x: 1
                
                processed_records = service.process_inventory_batch(test_data, 'Ozon')
                
                # Принудительная сборка мусора для точного измерения
                del test_data, processed_records
                gc.collect()
            
            measurement = self._last_measurement
            memory_overhead = measurement['peak_memory_mb'] - baseline_memory
            memory_per_record = memory_overhead / dataset_size * 1024  # КБ на запись
            
            # Создаем результат бенчмарка
            result = BenchmarkResult(
                operation_name="memory_efficiency",
                dataset_size=dataset_size,
                iterations=1,
                total_time_seconds=measurement['duration'],
                avg_time_seconds=measurement['duration'],
                min_time_seconds=measurement['duration'],
                max_time_seconds=measurement['duration'],
                median_time_seconds=measurement['duration'],
                throughput_per_second=dataset_size / measurement['duration'],
                operations_per_second=1 / measurement['duration'],
                peak_memory_mb=measurement['peak_memory_mb'],
                avg_cpu_percent=measurement['avg_cpu_percent']
            )
            
            results.append(result)
            
            logger.info(f"✅ Память для {dataset_size} записей: {memory_overhead:.1f} МБ "
                       f"({memory_per_record:.2f} КБ/запись)")
        
        return results
    
    def run_comprehensive_benchmark(self) -> Dict[str, Any]:
        """Запуск комплексного бенчмарка."""
        logger.info("🚀 Запуск комплексного бенчмарка производительности")
        
        start_time = datetime.now()
        
        # Конфигурация бенчмарков
        dataset_sizes = [1000, 5000, 10000, 25000]
        cache_sizes = [100, 500, 1000, 5000]
        batch_sizes = [100, 500, 1000, 2000, 5000]
        worker_counts = [1, 2, 4, 8]
        
        # Запускаем все бенчмарки
        benchmark_results = {}
        
        try:
            # Бенчмарк обработки данных
            logger.info("=" * 50)
            logger.info("БЕНЧМАРК ОБРАБОТКИ ДАННЫХ")
            logger.info("=" * 50)
            benchmark_results['data_processing'] = self.benchmark_data_processing(dataset_sizes)
            
            # Бенчмарк кэширования
            logger.info("=" * 50)
            logger.info("БЕНЧМАРК КЭШИРОВАНИЯ")
            logger.info("=" * 50)
            benchmark_results['cache_performance'] = self.benchmark_cache_performance(cache_sizes)
            
            # Бенчмарк размеров батчей
            logger.info("=" * 50)
            logger.info("БЕНЧМАРК РАЗМЕРОВ БАТЧЕЙ")
            logger.info("=" * 50)
            benchmark_results['batch_sizes'] = self.benchmark_batch_sizes(10000, batch_sizes)
            
            # Бенчмарк параллельных воркеров
            logger.info("=" * 50)
            logger.info("БЕНЧМАРК ПАРАЛЛЕЛЬНЫХ ВОРКЕРОВ")
            logger.info("=" * 50)
            benchmark_results['parallel_workers'] = self.benchmark_parallel_workers(10000, worker_counts)
            
            # Бенчмарк эффективности памяти
            logger.info("=" * 50)
            logger.info("БЕНЧМАРК ЭФФЕКТИВНОСТИ ПАМЯТИ")
            logger.info("=" * 50)
            benchmark_results['memory_efficiency'] = self.benchmark_memory_efficiency(dataset_sizes)
            
        except Exception as e:
            logger.error(f"❌ Ошибка выполнения бенчмарка: {e}")
            raise
        
        end_time = datetime.now()
        
        # Анализируем результаты
        analysis = self._analyze_benchmark_results(benchmark_results)
        
        # Создаем итоговый отчет
        report = {
            'benchmark_summary': {
                'start_time': start_time.isoformat(),
                'end_time': end_time.isoformat(),
                'total_duration_minutes': (end_time - start_time).total_seconds() / 60,
                'system_info': {
                    'cpu_count': psutil.cpu_count(),
                    'memory_gb': psutil.virtual_memory().total / 1024 / 1024 / 1024,
                    'platform': sys.platform
                }
            },
            'benchmark_results': {
                category: [result.to_dict() for result in results]
                for category, results in benchmark_results.items()
            },
            'performance_analysis': analysis,
            'optimal_configurations': self._find_optimal_configurations(benchmark_results)
        }
        
        logger.info("✅ Комплексный бенчмарк завершен")
        return report
    
    def _analyze_benchmark_results(self, results: Dict[str, List[BenchmarkResult]]) -> Dict[str, Any]:
        """Анализ результатов бенчмарка."""
        analysis = {}
        
        for category, benchmark_results in results.items():
            if not benchmark_results:
                continue
            
            throughputs = [r.throughput_per_second for r in benchmark_results]
            memory_usage = [r.peak_memory_mb for r in benchmark_results]
            
            analysis[category] = {
                'best_throughput': max(throughputs),
                'worst_throughput': min(throughputs),
                'avg_throughput': statistics.mean(throughputs),
                'throughput_variance': statistics.variance(throughputs) if len(throughputs) > 1 else 0,
                'memory_efficiency': {
                    'min_memory_mb': min(memory_usage),
                    'max_memory_mb': max(memory_usage),
                    'avg_memory_mb': statistics.mean(memory_usage)
                },
                'scalability_factor': self._calculate_scalability_factor(benchmark_results)
            }
        
        return analysis
    
    def _calculate_scalability_factor(self, results: List[BenchmarkResult]) -> float:
        """Вычисление коэффициента масштабируемости."""
        if len(results) < 2:
            return 1.0
        
        # Сортируем по размеру датасета
        sorted_results = sorted(results, key=lambda r: r.dataset_size)
        
        # Вычисляем отношение пропускной способности к размеру данных
        ratios = []
        for i in range(1, len(sorted_results)):
            prev_result = sorted_results[i-1]
            curr_result = sorted_results[i]
            
            size_ratio = curr_result.dataset_size / prev_result.dataset_size
            throughput_ratio = curr_result.throughput_per_second / prev_result.throughput_per_second
            
            # Идеальная масштабируемость = 1.0 (пропускная способность растет пропорционально размеру)
            scalability = throughput_ratio / size_ratio
            ratios.append(scalability)
        
        return statistics.mean(ratios) if ratios else 1.0
    
    def _find_optimal_configurations(self, results: Dict[str, List[BenchmarkResult]]) -> Dict[str, Any]:
        """Поиск оптимальных конфигураций."""
        optimal = {}
        
        # Оптимальный размер батча
        if 'batch_sizes' in results:
            batch_results = results['batch_sizes']
            best_batch = max(batch_results, key=lambda r: r.throughput_per_second)
            optimal['batch_size'] = {
                'size': best_batch.dataset_size,
                'throughput': best_batch.throughput_per_second,
                'memory_mb': best_batch.peak_memory_mb
            }
        
        # Оптимальное количество воркеров
        if 'parallel_workers' in results:
            worker_results = results['parallel_workers']
            # Ищем баланс между производительностью и использованием ресурсов
            best_worker = max(worker_results, 
                            key=lambda r: r.throughput_per_second / (r.avg_cpu_percent / 100 + 0.1))
            optimal['worker_count'] = {
                'count': best_worker.dataset_size,
                'throughput': best_worker.throughput_per_second,
                'cpu_percent': best_worker.avg_cpu_percent
            }
        
        # Эффективность кэширования
        if 'cache_performance' in results:
            cache_results = results['cache_performance']
            best_cache = max(cache_results, key=lambda r: r.cache_hit_rate * r.throughput_per_second)
            optimal['cache_size'] = {
                'size': best_cache.dataset_size,
                'hit_rate': best_cache.cache_hit_rate,
                'throughput': best_cache.throughput_per_second
            }
        
        return optimal


def save_benchmark_report(report: Dict[str, Any], filename: str = None):
    """Сохранение отчета бенчмарка."""
    if filename is None:
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        filename = f"performance_benchmark_{timestamp}.json"
    
    with open(filename, 'w', encoding='utf-8') as f:
        json.dump(report, f, indent=2, ensure_ascii=False, default=str)
    
    logger.info(f"📄 Отчет бенчмарка сохранен: {filename}")


def print_benchmark_summary(report: Dict[str, Any]):
    """Вывод краткой сводки бенчмарка."""
    print("\n" + "="*80)
    print("СВОДКА РЕЗУЛЬТАТОВ БЕНЧМАРКА ПРОИЗВОДИТЕЛЬНОСТИ")
    print("="*80)
    
    summary = report['benchmark_summary']
    print(f"Время выполнения: {summary['total_duration_minutes']:.1f} минут")
    print(f"Система: {summary['system_info']['cpu_count']} CPU, {summary['system_info']['memory_gb']:.1f} ГБ RAM")
    
    print("\nРЕЗУЛЬТАТЫ БЕНЧМАРКОВ:")
    print("-"*40)
    
    analysis = report['performance_analysis']
    for category, stats in analysis.items():
        print(f"\n{category.upper().replace('_', ' ')}:")
        print(f"  Лучшая производительность: {stats['best_throughput']:.1f} записей/сек")
        print(f"  Средняя производительность: {stats['avg_throughput']:.1f} записей/сек")
        print(f"  Использование памяти: {stats['memory_efficiency']['avg_memory_mb']:.1f} МБ")
        print(f"  Коэффициент масштабируемости: {stats['scalability_factor']:.2f}")
    
    print("\nОПТИМАЛЬНЫЕ КОНФИГУРАЦИИ:")
    print("-"*40)
    optimal = report['optimal_configurations']
    
    if 'batch_size' in optimal:
        batch = optimal['batch_size']
        print(f"Размер батча: {batch['size']} ({batch['throughput']:.1f} записей/сек)")
    
    if 'worker_count' in optimal:
        workers = optimal['worker_count']
        print(f"Количество воркеров: {workers['count']} ({workers['throughput']:.1f} записей/сек)")
    
    if 'cache_size' in optimal:
        cache = optimal['cache_size']
        print(f"Размер кэша: {cache['size']} (hit rate: {cache['hit_rate']:.2%})")
    
    print("\n" + "="*80)


def main():
    """Основная функция для запуска бенчмарка."""
    logger.info("🚀 Запуск бенчмарка производительности системы синхронизации")
    
    benchmark = PerformanceBenchmark()
    
    try:
        # Запускаем комплексный бенчмарк
        report = benchmark.run_comprehensive_benchmark()
        
        # Сохраняем отчет
        save_benchmark_report(report)
        
        # Выводим сводку
        print_benchmark_summary(report)
        
        logger.info("✅ Бенчмарк производительности завершен успешно")
        
    except Exception as e:
        logger.error(f"❌ Критическая ошибка бенчмарка: {e}")
        raise
    finally:
        benchmark.cleanup()


if __name__ == "__main__":
    main()