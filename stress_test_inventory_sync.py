#!/usr/bin/env python3
"""
Стресс-тестирование системы синхронизации остатков товаров.

Тестирует систему в экстремальных условиях:
- Очень большие объемы данных
- Длительная непрерывная работа
- Высокая нагрузка на ресурсы
- Обработка ошибок под нагрузкой
- Восстановление после сбоев

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import time
import psutil
import threading
import asyncio
import random
import signal
from datetime import datetime, timedelta
from typing import Dict, List, Any, Optional, Callable
from dataclasses import dataclass, asdict
from concurrent.futures import ThreadPoolExecutor, ProcessPoolExecutor, as_completed
import logging
import json
import tempfile
import shutil
from contextlib import contextmanager
import tracemalloc
import gc
from unittest.mock import Mock, patch
import queue
import multiprocessing

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_sync_service_optimized import OptimizedInventorySyncService, InventoryRecord, SyncResult, SyncStatus
    from parallel_sync_manager import ParallelSyncManager, SyncPriority
    from api_request_optimizer import APIRequestOptimizer
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
class StressTestConfig:
    """Конфигурация стресс-тестирования."""
    # Параметры нагрузки
    max_dataset_size: int = 1000000  # 1 миллион записей
    concurrent_processes: int = 8
    test_duration_minutes: int = 60
    memory_pressure_mb: int = 4096  # 4 ГБ
    
    # Параметры стабильности
    error_injection_rate: float = 0.05  # 5% ошибок
    network_failure_rate: float = 0.02  # 2% сетевых сбоев
    memory_leak_simulation: bool = True
    
    # Пороги критичности
    max_memory_mb: int = 8192  # 8 ГБ
    max_cpu_percent: float = 95.0
    max_response_time_seconds: int = 600  # 10 минут
    
    # Параметры восстановления
    retry_attempts: int = 5
    recovery_timeout_seconds: int = 300


@dataclass
class StressTestResult:
    """Результат стресс-тестирования."""
    test_name: str
    start_time: datetime
    end_time: Optional[datetime] = None
    
    # Метрики нагрузки
    total_operations: int = 0
    successful_operations: int = 0
    failed_operations: int = 0
    
    # Метрики производительности
    peak_memory_mb: float = 0.0
    avg_cpu_percent: float = 0.0
    peak_cpu_percent: float = 0.0
    
    # Метрики стабильности
    crashes: int = 0
    recoveries: int = 0
    memory_leaks_detected: int = 0
    
    # Метрики качества
    data_corruption_incidents: int = 0
    timeout_incidents: int = 0
    
    @property
    def duration_minutes(self) -> float:
        """Длительность теста в минутах."""
        if self.end_time:
            return (self.end_time - self.start_time).total_seconds() / 60
        return 0.0
    
    @property
    def success_rate(self) -> float:
        """Процент успешных операций."""
        if self.total_operations > 0:
            return self.successful_operations / self.total_operations
        return 0.0
    
    @property
    def operations_per_minute(self) -> float:
        """Операций в минуту."""
        if self.duration_minutes > 0:
            return self.total_operations / self.duration_minutes
        return 0.0


class ErrorInjector:
    """Инжектор ошибок для стресс-тестирования."""
    
    def __init__(self, error_rate: float = 0.05, network_failure_rate: float = 0.02):
        self.error_rate = error_rate
        self.network_failure_rate = network_failure_rate
        self.injected_errors = 0
        self.network_failures = 0
    
    def should_inject_error(self) -> bool:
        """Проверка необходимости инжекции ошибки."""
        return random.random() < self.error_rate
    
    def should_inject_network_failure(self) -> bool:
        """Проверка необходимости инжекции сетевого сбоя."""
        return random.random() < self.network_failure_rate
    
    def inject_processing_error(self):
        """Инжекция ошибки обработки."""
        self.injected_errors += 1
        error_types = [
            "Simulated processing error",
            "Simulated validation error", 
            "Simulated timeout error",
            "Simulated memory error"
        ]
        raise Exception(random.choice(error_types))
    
    def inject_network_failure(self):
        """Инжекция сетевого сбоя."""
        self.network_failures += 1
        failure_types = [
            "Connection timeout",
            "Network unreachable",
            "DNS resolution failed",
            "SSL handshake failed"
        ]
        raise ConnectionError(random.choice(failure_types))


class MemoryLeakSimulator:
    """Симулятор утечек памяти."""
    
    def __init__(self):
        self.leaked_objects = []
        self.leak_size_mb = 0
    
    def create_memory_leak(self, size_mb: float = 1.0):
        """Создание утечки памяти."""
        # Создаем большой объект, который не будет освобожден
        leak_data = bytearray(int(size_mb * 1024 * 1024))
        self.leaked_objects.append(leak_data)
        self.leak_size_mb += size_mb
        logger.debug(f"💧 Создана утечка памяти: {size_mb} МБ (всего: {self.leak_size_mb} МБ)")
    
    def cleanup_leaks(self):
        """Очистка утечек памяти."""
        self.leaked_objects.clear()
        leaked_mb = self.leak_size_mb
        self.leak_size_mb = 0
        logger.info(f"🧹 Очищены утечки памяти: {leaked_mb} МБ")


class StressTester:
    """Основной класс для стресс-тестирования."""
    
    def __init__(self, config: StressTestConfig):
        self.config = config
        self.error_injector = ErrorInjector(
            config.error_injection_rate,
            config.network_failure_rate
        )
        self.memory_leak_simulator = MemoryLeakSimulator()
        self.temp_dir = tempfile.mkdtemp()
        self.stop_flag = threading.Event()
        self.results: List[StressTestResult] = []
        
        # Обработчик сигналов для graceful shutdown
        signal.signal(signal.SIGINT, self._signal_handler)
        signal.signal(signal.SIGTERM, self._signal_handler)
    
    def _signal_handler(self, signum, frame):
        """Обработчик сигналов для остановки тестов."""
        logger.info(f"🛑 Получен сигнал {signum}, останавливаем тесты...")
        self.stop_flag.set()
    
    def cleanup(self):
        """Очистка ресурсов."""
        self.memory_leak_simulator.cleanup_leaks()
        if os.path.exists(self.temp_dir):
            shutil.rmtree(self.temp_dir)
    
    def stress_test_high_volume_processing(self) -> StressTestResult:
        """Стресс-тест обработки больших объемов данных."""
        logger.info("🚀 Запуск стресс-теста обработки больших объемов")
        
        result = StressTestResult(
            test_name="high_volume_processing",
            start_time=datetime.now()
        )
        
        # Запускаем мониторинг ресурсов
        monitor = ResourceMonitor(interval_seconds=1.0)
        monitor.start()
        
        try:
            # Генерируем очень большой набор данных
            logger.info(f"📊 Генерируем {self.config.max_dataset_size} записей...")
            test_data = MockDataGenerator.generate_ozon_inventory_data(self.config.max_dataset_size)
            
            # Создаем сервис с максимальными параметрами
            service = OptimizedInventorySyncService(
                batch_size=5000,
                max_workers=self.config.concurrent_processes
            )
            service.product_cache.get_product_id_by_ozon_sku = lambda x: random.randint(1, 1000)
            
            # Обрабатываем данные с инжекцией ошибок
            processed_count = 0
            failed_count = 0
            
            # Разбиваем на чанки для обработки
            chunk_size = 10000
            chunks = [test_data[i:i + chunk_size] for i in range(0, len(test_data), chunk_size)]
            
            for i, chunk in enumerate(chunks):
                if self.stop_flag.is_set():
                    break
                
                try:
                    # Инжекция ошибок
                    if self.error_injector.should_inject_error():
                        self.error_injector.inject_processing_error()
                    
                    # Симуляция утечки памяти
                    if self.config.memory_leak_simulation and random.random() < 0.1:
                        self.memory_leak_simulator.create_memory_leak(0.5)
                    
                    # Обработка чанка
                    processed_records = service.process_inventory_batch(chunk, 'Ozon')
                    processed_count += len(processed_records)
                    
                    # Логируем прогресс
                    if (i + 1) % 10 == 0:
                        logger.info(f"📈 Обработано чанков: {i + 1}/{len(chunks)}, записей: {processed_count}")
                    
                except Exception as e:
                    logger.error(f"❌ Ошибка обработки чанка {i}: {e}")
                    failed_count += len(chunk)
                
                # Принудительная сборка мусора для контроля памяти
                if i % 5 == 0:
                    gc.collect()
            
            result.total_operations = len(test_data)
            result.successful_operations = processed_count
            result.failed_operations = failed_count
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка стресс-теста: {e}")
            result.crashes += 1
        finally:
            monitor.stop()
            resource_summary = monitor.get_summary()
            
            result.end_time = datetime.now()
            result.peak_memory_mb = resource_summary.get('peak_memory_mb', 0)
            result.avg_cpu_percent = resource_summary.get('avg_cpu_percent', 0)
            result.peak_cpu_percent = resource_summary.get('peak_cpu_percent', 0)
        
        logger.info(f"✅ Стресс-тест больших объемов завершен: "
                   f"обработано {result.successful_operations}/{result.total_operations} записей")
        
        return result
    
    def stress_test_concurrent_operations(self) -> StressTestResult:
        """Стресс-тест параллельных операций."""
        logger.info("🚀 Запуск стресс-теста параллельных операций")
        
        result = StressTestResult(
            test_name="concurrent_operations",
            start_time=datetime.now()
        )
        
        # Запускаем мониторинг ресурсов
        monitor = ResourceMonitor(interval_seconds=0.5)
        monitor.start()
        
        try:
            # Создаем множественные процессы для максимальной нагрузки
            with ProcessPoolExecutor(max_workers=self.config.concurrent_processes) as executor:
                
                # Функция для выполнения в отдельном процессе
                def worker_function(worker_id: int) -> Dict[str, int]:
                    worker_result = {'processed': 0, 'failed': 0, 'errors': 0}
                    
                    try:
                        # Генерируем данные для воркера
                        worker_data = MockDataGenerator.generate_ozon_inventory_data(10000)
                        
                        # Создаем сервис для воркера
                        service = OptimizedInventorySyncService(batch_size=1000, max_workers=2)
                        service.product_cache.get_product_id_by_ozon_sku = lambda x: random.randint(1, 100)
                        
                        # Обрабатываем данные с инжекцией ошибок
                        for i, item in enumerate(worker_data):
                            if i % 1000 == 0 and self.stop_flag.is_set():
                                break
                            
                            try:
                                # Случайная инжекция ошибок
                                if random.random() < self.config.error_injection_rate:
                                    raise Exception(f"Worker {worker_id} simulated error")
                                
                                # Имитация обработки
                                time.sleep(0.001)  # 1мс на запись
                                worker_result['processed'] += 1
                                
                            except Exception:
                                worker_result['failed'] += 1
                                worker_result['errors'] += 1
                        
                    except Exception as e:
                        logger.error(f"❌ Критическая ошибка воркера {worker_id}: {e}")
                        worker_result['errors'] += 1
                    
                    return worker_result
                
                # Запускаем воркеров
                futures = []
                for worker_id in range(self.config.concurrent_processes):
                    future = executor.submit(worker_function, worker_id)
                    futures.append(future)
                
                # Собираем результаты
                total_processed = 0
                total_failed = 0
                total_errors = 0
                
                for future in as_completed(futures):
                    try:
                        worker_result = future.result(timeout=300)  # 5 минут таймаут
                        total_processed += worker_result['processed']
                        total_failed += worker_result['failed']
                        total_errors += worker_result['errors']
                    except Exception as e:
                        logger.error(f"❌ Ошибка получения результата воркера: {e}")
                        result.crashes += 1
                
                result.total_operations = total_processed + total_failed
                result.successful_operations = total_processed
                result.failed_operations = total_failed
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка параллельного стресс-теста: {e}")
            result.crashes += 1
        finally:
            monitor.stop()
            resource_summary = monitor.get_summary()
            
            result.end_time = datetime.now()
            result.peak_memory_mb = resource_summary.get('peak_memory_mb', 0)
            result.avg_cpu_percent = resource_summary.get('avg_cpu_percent', 0)
            result.peak_cpu_percent = resource_summary.get('peak_cpu_percent', 0)
        
        logger.info(f"✅ Стресс-тест параллельных операций завершен: "
                   f"обработано {result.successful_operations} записей, ошибок {result.failed_operations}")
        
        return result
    
    def stress_test_memory_pressure(self) -> StressTestResult:
        """Стресс-тест под давлением памяти."""
        logger.info("🚀 Запуск стресс-теста под давлением памяти")
        
        result = StressTestResult(
            test_name="memory_pressure",
            start_time=datetime.now()
        )
        
        # Запускаем детальный мониторинг памяти
        monitor = ResourceMonitor(interval_seconds=0.1)
        monitor.start()
        
        tracemalloc.start()
        
        try:
            # Создаем давление на память
            memory_hogs = []
            target_memory_mb = self.config.memory_pressure_mb
            
            logger.info(f"📊 Создаем давление на память до {target_memory_mb} МБ...")
            
            # Постепенно увеличиваем использование памяти
            current_memory_mb = 0
            while current_memory_mb < target_memory_mb and not self.stop_flag.is_set():
                # Создаем блок памяти
                chunk_size_mb = min(100, target_memory_mb - current_memory_mb)
                memory_chunk = bytearray(int(chunk_size_mb * 1024 * 1024))
                memory_hogs.append(memory_chunk)
                current_memory_mb += chunk_size_mb
                
                # Тестируем обработку данных под давлением памяти
                try:
                    test_data = MockDataGenerator.generate_ozon_inventory_data(1000)
                    service = OptimizedInventorySyncService(batch_size=100, max_workers=2)
                    service.product_cache.get_product_id_by_ozon_sku = lambda x: 1
                    
                    processed_records = service.process_inventory_batch(test_data, 'Ozon')
                    result.successful_operations += len(processed_records)
                    
                    # Симуляция утечек памяти
                    if self.config.memory_leak_simulation:
                        self.memory_leak_simulator.create_memory_leak(0.1)
                    
                except Exception as e:
                    logger.error(f"❌ Ошибка обработки под давлением памяти: {e}")
                    result.failed_operations += 1000
                
                result.total_operations += 1000
                
                # Проверяем использование памяти
                process = psutil.Process()
                memory_info = process.memory_info()
                current_memory_usage = memory_info.rss / 1024 / 1024
                
                if current_memory_usage > self.config.max_memory_mb:
                    logger.warning(f"⚠️ Превышен лимит памяти: {current_memory_usage:.1f} МБ")
                    result.memory_leaks_detected += 1
                    break
                
                time.sleep(0.1)
            
            # Тестируем восстановление после освобождения памяти
            logger.info("🧹 Освобождаем память и тестируем восстановление...")
            del memory_hogs
            gc.collect()
            
            # Проверяем восстановление производительности
            recovery_start = time.time()
            test_data = MockDataGenerator.generate_ozon_inventory_data(5000)
            service = OptimizedInventorySyncService(batch_size=1000, max_workers=4)
            service.product_cache.get_product_id_by_ozon_sku = lambda x: 1
            
            processed_records = service.process_inventory_batch(test_data, 'Ozon')
            recovery_time = time.time() - recovery_start
            
            if recovery_time < 30:  # Восстановление за 30 секунд считается успешным
                result.recoveries += 1
                logger.info(f"✅ Восстановление после освобождения памяти: {recovery_time:.1f}с")
            else:
                logger.warning(f"⚠️ Медленное восстановление: {recovery_time:.1f}с")
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка стресс-теста памяти: {e}")
            result.crashes += 1
        finally:
            # Получаем пиковое использование памяти
            current, peak = tracemalloc.get_traced_memory()
            tracemalloc.stop()
            
            monitor.stop()
            resource_summary = monitor.get_summary()
            
            result.end_time = datetime.now()
            result.peak_memory_mb = max(peak / 1024 / 1024, resource_summary.get('peak_memory_mb', 0))
            result.avg_cpu_percent = resource_summary.get('avg_cpu_percent', 0)
            result.peak_cpu_percent = resource_summary.get('peak_cpu_percent', 0)
        
        logger.info(f"✅ Стресс-тест памяти завершен: пик {result.peak_memory_mb:.1f} МБ")
        
        return result
    
    def stress_test_long_running_stability(self) -> StressTestResult:
        """Стресс-тест длительной стабильности работы."""
        logger.info(f"🚀 Запуск стресс-теста стабильности ({self.config.test_duration_minutes} минут)")
        
        result = StressTestResult(
            test_name="long_running_stability",
            start_time=datetime.now()
        )
        
        # Запускаем мониторинг ресурсов
        monitor = ResourceMonitor(interval_seconds=5.0)
        monitor.start()
        
        end_time = datetime.now() + timedelta(minutes=self.config.test_duration_minutes)
        
        try:
            iteration = 0
            while datetime.now() < end_time and not self.stop_flag.is_set():
                iteration += 1
                
                try:
                    # Генерируем данные для итерации
                    dataset_size = random.randint(1000, 10000)
                    test_data = MockDataGenerator.generate_ozon_inventory_data(dataset_size)
                    
                    # Создаем сервис с случайными параметрами
                    batch_size = random.choice([100, 500, 1000, 2000])
                    workers = random.choice([1, 2, 4, 8])
                    
                    service = OptimizedInventorySyncService(
                        batch_size=batch_size,
                        max_workers=workers
                    )
                    service.product_cache.get_product_id_by_ozon_sku = lambda x: random.randint(1, 1000)
                    
                    # Инжекция различных типов ошибок
                    if self.error_injector.should_inject_network_failure():
                        self.error_injector.inject_network_failure()
                    
                    if self.error_injector.should_inject_error():
                        self.error_injector.inject_processing_error()
                    
                    # Обработка данных
                    processed_records = service.process_inventory_batch(test_data, 'Ozon')
                    result.successful_operations += len(processed_records)
                    
                    # Симуляция утечек памяти
                    if self.config.memory_leak_simulation and iteration % 10 == 0:
                        self.memory_leak_simulator.create_memory_leak(0.1)
                    
                    # Периодическая очистка памяти
                    if iteration % 50 == 0:
                        gc.collect()
                        logger.info(f"🔄 Итерация {iteration}, обработано {result.successful_operations} записей")
                    
                except ConnectionError as e:
                    logger.warning(f"🌐 Сетевая ошибка на итерации {iteration}: {e}")
                    result.failed_operations += dataset_size
                    result.timeout_incidents += 1
                    
                    # Имитация восстановления соединения
                    time.sleep(random.uniform(1, 5))
                    result.recoveries += 1
                    
                except Exception as e:
                    logger.error(f"❌ Ошибка на итерации {iteration}: {e}")
                    result.failed_operations += dataset_size
                    
                    # Проверка на критические ошибки
                    if "memory" in str(e).lower():
                        result.memory_leaks_detected += 1
                    
                    # Попытка восстановления
                    try:
                        time.sleep(1)
                        gc.collect()
                        result.recoveries += 1
                    except:
                        result.crashes += 1
                
                result.total_operations += dataset_size
                
                # Проверка ресурсов
                process = psutil.Process()
                memory_mb = process.memory_info().rss / 1024 / 1024
                cpu_percent = process.cpu_percent()
                
                if memory_mb > self.config.max_memory_mb:
                    logger.warning(f"⚠️ Превышение лимита памяти: {memory_mb:.1f} МБ")
                    result.memory_leaks_detected += 1
                
                if cpu_percent > self.config.max_cpu_percent:
                    logger.warning(f"⚠️ Высокая загрузка CPU: {cpu_percent:.1f}%")
                
                # Небольшая пауза между итерациями
                time.sleep(random.uniform(0.1, 1.0))
            
        except KeyboardInterrupt:
            logger.info("🛑 Получен сигнал прерывания, завершаем тест...")
        except Exception as e:
            logger.error(f"❌ Критическая ошибка стресс-теста стабильности: {e}")
            result.crashes += 1
        finally:
            monitor.stop()
            resource_summary = monitor.get_summary()
            
            result.end_time = datetime.now()
            result.peak_memory_mb = resource_summary.get('peak_memory_mb', 0)
            result.avg_cpu_percent = resource_summary.get('avg_cpu_percent', 0)
            result.peak_cpu_percent = resource_summary.get('peak_cpu_percent', 0)
        
        logger.info(f"✅ Стресс-тест стабильности завершен: "
                   f"{result.duration_minutes:.1f} минут, "
                   f"успешность {result.success_rate:.2%}")
        
        return result
    
    def run_comprehensive_stress_test(self) -> Dict[str, Any]:
        """Запуск комплексного стресс-тестирования."""
        logger.info("🚀 Запуск комплексного стресс-тестирования")
        
        start_time = datetime.now()
        
        # Запускаем все стресс-тесты
        stress_results = {}
        
        try:
            # Стресс-тест больших объемов
            logger.info("=" * 60)
            logger.info("СТРЕСС-ТЕСТ БОЛЬШИХ ОБЪЕМОВ ДАННЫХ")
            logger.info("=" * 60)
            stress_results['high_volume'] = self.stress_test_high_volume_processing()
            
            if self.stop_flag.is_set():
                return self._create_partial_report(stress_results, start_time)
            
            # Стресс-тест параллельных операций
            logger.info("=" * 60)
            logger.info("СТРЕСС-ТЕСТ ПАРАЛЛЕЛЬНЫХ ОПЕРАЦИЙ")
            logger.info("=" * 60)
            stress_results['concurrent'] = self.stress_test_concurrent_operations()
            
            if self.stop_flag.is_set():
                return self._create_partial_report(stress_results, start_time)
            
            # Стресс-тест памяти
            logger.info("=" * 60)
            logger.info("СТРЕСС-ТЕСТ ДАВЛЕНИЯ ПАМЯТИ")
            logger.info("=" * 60)
            stress_results['memory_pressure'] = self.stress_test_memory_pressure()
            
            if self.stop_flag.is_set():
                return self._create_partial_report(stress_results, start_time)
            
            # Стресс-тест стабильности (сокращенная версия для демо)
            logger.info("=" * 60)
            logger.info("СТРЕСС-ТЕСТ ДЛИТЕЛЬНОЙ СТАБИЛЬНОСТИ")
            logger.info("=" * 60)
            
            # Сокращаем время для демонстрации
            original_duration = self.config.test_duration_minutes
            self.config.test_duration_minutes = min(5, original_duration)  # Максимум 5 минут
            
            stress_results['stability'] = self.stress_test_long_running_stability()
            
            # Восстанавливаем оригинальное время
            self.config.test_duration_minutes = original_duration
            
        except Exception as e:
            logger.error(f"❌ Критическая ошибка стресс-тестирования: {e}")
        
        end_time = datetime.now()
        
        # Анализируем результаты
        analysis = self._analyze_stress_results(stress_results)
        
        # Создаем итоговый отчет
        report = {
            'stress_test_summary': {
                'start_time': start_time.isoformat(),
                'end_time': end_time.isoformat(),
                'total_duration_minutes': (end_time - start_time).total_seconds() / 60,
                'config': asdict(self.config),
                'interrupted': self.stop_flag.is_set()
            },
            'stress_results': {
                test_name: asdict(result) for test_name, result in stress_results.items()
            },
            'stability_analysis': analysis,
            'recommendations': self._generate_stress_recommendations(analysis)
        }
        
        logger.info("✅ Комплексное стресс-тестирование завершено")
        return report
    
    def _create_partial_report(self, results: Dict[str, StressTestResult], start_time: datetime) -> Dict[str, Any]:
        """Создание частичного отчета при прерывании."""
        return {
            'stress_test_summary': {
                'start_time': start_time.isoformat(),
                'end_time': datetime.now().isoformat(),
                'interrupted': True,
                'completed_tests': list(results.keys())
            },
            'stress_results': {
                test_name: asdict(result) for test_name, result in results.items()
            }
        }
    
    def _analyze_stress_results(self, results: Dict[str, StressTestResult]) -> Dict[str, Any]:
        """Анализ результатов стресс-тестирования."""
        analysis = {
            'overall_stability': 'stable',
            'critical_issues': [],
            'performance_degradation': [],
            'resource_issues': [],
            'recovery_capability': 'good'
        }
        
        total_crashes = sum(result.crashes for result in results.values())
        total_operations = sum(result.total_operations for result in results.values())
        total_successful = sum(result.successful_operations for result in results.values())
        
        # Анализ общей стабильности
        if total_crashes > 0:
            analysis['overall_stability'] = 'unstable'
            analysis['critical_issues'].append(f"Обнаружено {total_crashes} критических сбоев")
        
        # Анализ успешности операций
        if total_operations > 0:
            success_rate = total_successful / total_operations
            if success_rate < 0.95:
                analysis['overall_stability'] = 'degraded'
                analysis['performance_degradation'].append(
                    f"Низкая успешность операций: {success_rate:.2%}"
                )
        
        # Анализ использования ресурсов
        for test_name, result in results.items():
            if result.peak_memory_mb > self.config.max_memory_mb:
                analysis['resource_issues'].append(
                    f"Превышение лимита памяти в тесте {test_name}: {result.peak_memory_mb:.1f} МБ"
                )
            
            if result.peak_cpu_percent > self.config.max_cpu_percent:
                analysis['resource_issues'].append(
                    f"Превышение лимита CPU в тесте {test_name}: {result.peak_cpu_percent:.1f}%"
                )
            
            if result.memory_leaks_detected > 0:
                analysis['critical_issues'].append(
                    f"Обнаружены утечки памяти в тесте {test_name}: {result.memory_leaks_detected}"
                )
        
        # Анализ способности к восстановлению
        total_recoveries = sum(result.recoveries for result in results.values())
        total_failures = sum(result.failed_operations for result in results.values())
        
        if total_failures > 0:
            recovery_rate = total_recoveries / total_failures
            if recovery_rate < 0.8:
                analysis['recovery_capability'] = 'poor'
            elif recovery_rate < 0.9:
                analysis['recovery_capability'] = 'fair'
        
        return analysis
    
    def _generate_stress_recommendations(self, analysis: Dict[str, Any]) -> List[str]:
        """Генерация рекомендаций по результатам стресс-тестирования."""
        recommendations = []
        
        if analysis['overall_stability'] != 'stable':
            recommendations.append("КРИТИЧНО: Система показывает нестабильность под нагрузкой")
            recommendations.append("- Необходимо улучшить обработку ошибок")
            recommendations.append("- Добавить более надежные механизмы восстановления")
        
        if analysis['critical_issues']:
            recommendations.append("Критические проблемы:")
            for issue in analysis['critical_issues']:
                recommendations.append(f"  - {issue}")
        
        if analysis['resource_issues']:
            recommendations.append("Проблемы с ресурсами:")
            for issue in analysis['resource_issues']:
                recommendations.append(f"  - {issue}")
            recommendations.append("- Рекомендуется оптимизация использования памяти")
            recommendations.append("- Добавить ограничения на использование CPU")
        
        if analysis['recovery_capability'] != 'good':
            recommendations.append("Проблемы с восстановлением:")
            recommendations.append("- Улучшить механизмы retry")
            recommendations.append("- Добавить circuit breaker паттерн")
            recommendations.append("- Реализовать graceful degradation")
        
        if not recommendations:
            recommendations.append("✅ Система показывает хорошую стабильность под нагрузкой")
            recommendations.append("- Рекомендуется периодическое стресс-тестирование")
            recommendations.append("- Мониторинг производительности в продакшене")
        
        return recommendations


def save_stress_test_report(report: Dict[str, Any], filename: str = None):
    """Сохранение отчета стресс-тестирования."""
    if filename is None:
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        filename = f"stress_test_report_{timestamp}.json"
    
    with open(filename, 'w', encoding='utf-8') as f:
        json.dump(report, f, indent=2, ensure_ascii=False, default=str)
    
    logger.info(f"📄 Отчет стресс-тестирования сохранен: {filename}")


def print_stress_test_summary(report: Dict[str, Any]):
    """Вывод краткой сводки стресс-тестирования."""
    print("\n" + "="*80)
    print("СВОДКА РЕЗУЛЬТАТОВ СТРЕСС-ТЕСТИРОВАНИЯ")
    print("="*80)
    
    summary = report['stress_test_summary']
    print(f"Время выполнения: {summary['total_duration_minutes']:.1f} минут")
    
    if summary.get('interrupted'):
        print("⚠️ ТЕСТИРОВАНИЕ БЫЛО ПРЕРВАНО")
    
    print("\nРЕЗУЛЬТАТЫ СТРЕСС-ТЕСТОВ:")
    print("-"*40)
    
    stress_results = report['stress_results']
    for test_name, result in stress_results.items():
        print(f"\n{test_name.upper().replace('_', ' ')}:")
        print(f"  Операций: {result['total_operations']}")
        print(f"  Успешных: {result['successful_operations']} ({result.get('success_rate', 0):.2%})")
        print(f"  Пик памяти: {result['peak_memory_mb']:.1f} МБ")
        print(f"  Пик CPU: {result['peak_cpu_percent']:.1f}%")
        print(f"  Сбоев: {result['crashes']}")
        print(f"  Восстановлений: {result['recoveries']}")
    
    if 'stability_analysis' in report:
        analysis = report['stability_analysis']
        print(f"\nОБЩАЯ СТАБИЛЬНОСТЬ: {analysis['overall_stability'].upper()}")
        
        if analysis['critical_issues']:
            print("\n❌ КРИТИЧЕСКИЕ ПРОБЛЕМЫ:")
            for issue in analysis['critical_issues']:
                print(f"  - {issue}")
    
    print("\nРЕКОМЕНДАЦИИ:")
    print("-"*40)
    recommendations = report.get('recommendations', [])
    for rec in recommendations:
        print(f"  {rec}")
    
    print("\n" + "="*80)


def main():
    """Основная функция для запуска стресс-тестирования."""
    logger.info("🚀 Запуск стресс-тестирования системы синхронизации остатков")
    
    # Конфигурация стресс-тестирования (облегченная для демо)
    config = StressTestConfig(
        max_dataset_size=50000,  # Уменьшено для стабильности
        concurrent_processes=4,
        test_duration_minutes=2,  # Сокращено для демо
        memory_pressure_mb=1024,  # 1 ГБ
        error_injection_rate=0.05,
        network_failure_rate=0.02,
        memory_leak_simulation=True,
        max_memory_mb=2048,  # 2 ГБ лимит
        max_cpu_percent=90.0
    )
    
    # Создаем стресс-тестер
    tester = StressTester(config)
    
    try:
        # Запускаем комплексное стресс-тестирование
        report = tester.run_comprehensive_stress_test()
        
        # Сохраняем отчет
        save_stress_test_report(report)
        
        # Выводим сводку
        print_stress_test_summary(report)
        
        logger.info("✅ Стресс-тестирование завершено успешно")
        
    except KeyboardInterrupt:
        logger.info("🛑 Стресс-тестирование прервано пользователем")
    except Exception as e:
        logger.error(f"❌ Критическая ошибка стресс-тестирования: {e}")
        raise
    finally:
        tester.cleanup()


if __name__ == "__main__":
    main()