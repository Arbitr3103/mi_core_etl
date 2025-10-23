#!/usr/bin/env python3
"""
Менеджер параллельной синхронизации остатков с маркетплейсов.

Функции:
- Параллельная обработка данных с разных маркетплейсов
- Координация синхронизации между источниками
- Балансировка нагрузки и управление ресурсами
- Мониторинг производительности в реальном времени

Автор: ETL System
Дата: 06 января 2025
"""

import asyncio
import threading
import time
from datetime import datetime, timedelta
from typing import Dict, List, Any, Optional, Callable, Tuple
from dataclasses import dataclass, field
from enum import Enum
from concurrent.futures import ThreadPoolExecutor, ProcessPoolExecutor, as_completed
import logging
import json
import queue
from collections import defaultdict, deque
import psutil
import sys
import os

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from api_request_optimizer import APIRequestOptimizer
    from inventory_sync_service_optimized import OptimizedInventorySyncService, SyncResult, SyncStatus
    import config
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

logger = logging.getLogger(__name__)


class SyncPriority(Enum):
    """Приоритеты синхронизации."""
    LOW = 1
    NORMAL = 2
    HIGH = 3
    CRITICAL = 4


class ResourceType(Enum):
    """Типы ресурсов системы."""
    CPU = "cpu"
    MEMORY = "memory"
    NETWORK = "network"
    DATABASE = "database"


@dataclass
class SyncTask:
    """Задача синхронизации."""
    marketplace: str
    task_type: str
    priority: SyncPriority
    created_at: datetime = field(default_factory=datetime.now)
    started_at: Optional[datetime] = None
    completed_at: Optional[datetime] = None
    result: Optional[SyncResult] = None
    error: Optional[str] = None
    retry_count: int = 0
    max_retries: int = 3
    
    @property
    def duration(self) -> Optional[timedelta]:
        """Длительность выполнения задачи."""
        if self.started_at and self.completed_at:
            return self.completed_at - self.started_at
        return None
    
    @property
    def is_completed(self) -> bool:
        """Проверка завершения задачи."""
        return self.completed_at is not None
    
    @property
    def is_failed(self) -> bool:
        """Проверка неудачного выполнения."""
        return self.error is not None or (self.result and self.result.status == SyncStatus.FAILED)


@dataclass
class ResourceUsage:
    """Использование ресурсов системы."""
    cpu_percent: float
    memory_percent: float
    memory_mb: float
    network_io: Dict[str, int]
    disk_io: Dict[str, int]
    timestamp: datetime = field(default_factory=datetime.now)


@dataclass
class PerformanceMetrics:
    """Метрики производительности."""
    total_tasks: int = 0
    completed_tasks: int = 0
    failed_tasks: int = 0
    avg_duration: float = 0.0
    throughput_per_minute: float = 0.0
    resource_usage: Optional[ResourceUsage] = None
    cache_hit_rate: float = 0.0
    api_requests_per_minute: float = 0.0


class ParallelSyncManager:
    """Менеджер параллельной синхронизации остатков."""
    
    def __init__(
        self,
        max_workers: int = 4,
        max_concurrent_marketplaces: int = 2,
        resource_monitoring: bool = True
    ):
        """
        Инициализация менеджера.
        
        Args:
            max_workers: Максимальное количество рабочих потоков
            max_concurrent_marketplaces: Максимальное количество одновременно обрабатываемых маркетплейсов
            resource_monitoring: Включить мониторинг ресурсов
        """
        self.max_workers = max_workers
        self.max_concurrent_marketplaces = max_concurrent_marketplaces
        self.resource_monitoring = resource_monitoring
        
        # Очереди задач по приоритетам
        self._task_queues = {
            SyncPriority.CRITICAL: queue.PriorityQueue(),
            SyncPriority.HIGH: queue.PriorityQueue(),
            SyncPriority.NORMAL: queue.PriorityQueue(),
            SyncPriority.LOW: queue.PriorityQueue()
        }
        
        # Активные задачи и результаты
        self._active_tasks: Dict[str, SyncTask] = {}
        self._completed_tasks: List[SyncTask] = []
        self._task_lock = threading.Lock()
        
        # Пулы исполнителей
        self._thread_executor = ThreadPoolExecutor(max_workers=max_workers)
        self._process_executor = ProcessPoolExecutor(max_workers=max(1, max_workers // 2))
        
        # Мониторинг ресурсов
        self._resource_history: deque = deque(maxlen=100)
        self._monitoring_active = False
        self._monitoring_thread: Optional[threading.Thread] = None
        
        # Статистика производительности
        self._performance_metrics = PerformanceMetrics()
        self._start_time = datetime.now()
        
        # Оптимизатор API запросов
        self._api_optimizer = APIRequestOptimizer()
        
        # Конфигурация маркетплейсов
        self._marketplace_configs = {
            'Ozon': {
                'max_concurrent_requests': 10,
                'batch_size': 1000,
                'retry_delay': 5,
                'timeout': 30
            },
            'Wildberries': {
                'max_concurrent_requests': 5,
                'batch_size': 1000,
                'retry_delay': 10,
                'timeout': 60
            }
        }
    
    def start_monitoring(self) -> None:
        """Запуск мониторинга ресурсов."""
        if self.resource_monitoring and not self._monitoring_active:
            self._monitoring_active = True
            self._monitoring_thread = threading.Thread(target=self._monitor_resources, daemon=True)
            self._monitoring_thread.start()
            logger.info("📊 Мониторинг ресурсов запущен")
    
    def stop_monitoring(self) -> None:
        """Остановка мониторинга ресурсов."""
        self._monitoring_active = False
        if self._monitoring_thread:
            self._monitoring_thread.join(timeout=5)
        logger.info("📊 Мониторинг ресурсов остановлен")
    
    def _monitor_resources(self) -> None:
        """Мониторинг использования ресурсов системы."""
        while self._monitoring_active:
            try:
                # Получаем информацию о ресурсах
                cpu_percent = psutil.cpu_percent(interval=1)
                memory = psutil.virtual_memory()
                network_io = psutil.net_io_counters()._asdict()
                disk_io = psutil.disk_io_counters()._asdict()
                
                resource_usage = ResourceUsage(
                    cpu_percent=cpu_percent,
                    memory_percent=memory.percent,
                    memory_mb=memory.used / 1024 / 1024,
                    network_io=network_io,
                    disk_io=disk_io
                )
                
                self._resource_history.append(resource_usage)
                
                # Проверяем критические уровни ресурсов
                if cpu_percent > 90:
                    logger.warning(f"⚠️ Высокая загрузка CPU: {cpu_percent:.1f}%")
                
                if memory.percent > 85:
                    logger.warning(f"⚠️ Высокое использование памяти: {memory.percent:.1f}%")
                
                time.sleep(5)  # Мониторинг каждые 5 секунд
                
            except Exception as e:
                logger.error(f"❌ Ошибка мониторинга ресурсов: {e}")
                time.sleep(10)
    
    def add_sync_task(
        self,
        marketplace: str,
        task_type: str = "inventory_sync",
        priority: SyncPriority = SyncPriority.NORMAL
    ) -> str:
        """
        Добавление задачи синхронизации в очередь.
        
        Args:
            marketplace: Название маркетплейса
            task_type: Тип задачи
            priority: Приоритет выполнения
            
        Returns:
            ID задачи
        """
        task = SyncTask(
            marketplace=marketplace,
            task_type=task_type,
            priority=priority
        )
        
        task_id = f"{marketplace}_{task_type}_{int(time.time())}"
        
        # Добавляем в очередь с приоритетом
        priority_queue = self._task_queues[priority]
        priority_queue.put((priority.value, task_id, task))
        
        with self._task_lock:
            self._active_tasks[task_id] = task
        
        logger.info(f"📝 Добавлена задача {task_id} с приоритетом {priority.name}")
        return task_id
    
    def _get_next_task(self) -> Optional[Tuple[str, SyncTask]]:
        """Получение следующей задачи из очереди по приоритету."""
        # Проверяем очереди в порядке приоритета
        for priority in [SyncPriority.CRITICAL, SyncPriority.HIGH, SyncPriority.NORMAL, SyncPriority.LOW]:
            priority_queue = self._task_queues[priority]
            
            try:
                _, task_id, task = priority_queue.get_nowait()
                return task_id, task
            except queue.Empty:
                continue
        
        return None
    
    def _check_resource_availability(self) -> bool:
        """Проверка доступности ресурсов для новых задач."""
        if not self._resource_history:
            return True
        
        latest_usage = self._resource_history[-1]
        
        # Проверяем критические уровни
        if latest_usage.cpu_percent > 95:
            logger.debug("🚫 CPU перегружен, ожидание...")
            return False
        
        if latest_usage.memory_percent > 90:
            logger.debug("🚫 Память перегружена, ожидание...")
            return False
        
        return True
    
    def _execute_sync_task(self, task_id: str, task: SyncTask) -> SyncResult:
        """
        Выполнение задачи синхронизации.
        
        Args:
            task_id: ID задачи
            task: Объект задачи
            
        Returns:
            Результат синхронизации
        """
        logger.info(f"🚀 Начинаем выполнение задачи {task_id}")
        
        task.started_at = datetime.now()
        
        try:
            # Создаем оптимизированный сервис синхронизации
            sync_service = OptimizedInventorySyncService(
                batch_size=self._marketplace_configs[task.marketplace]['batch_size'],
                max_workers=self.max_workers
            )
            
            # Выполняем синхронизацию в зависимости от маркетплейса
            if task.marketplace == 'Ozon':
                result = sync_service.sync_ozon_inventory_optimized()
            elif task.marketplace == 'Wildberries':
                result = sync_service.sync_wb_inventory()  # Будет реализован аналогично
            else:
                raise ValueError(f"Неподдерживаемый маркетплейс: {task.marketplace}")
            
            task.result = result
            task.completed_at = datetime.now()
            
            logger.info(f"✅ Задача {task_id} завершена успешно")
            return result
            
        except Exception as e:
            error_msg = f"Ошибка выполнения задачи {task_id}: {e}"
            logger.error(f"❌ {error_msg}")
            
            task.error = error_msg
            task.completed_at = datetime.now()
            
            # Создаем результат с ошибкой
            result = SyncResult(
                source=task.marketplace,
                status=SyncStatus.FAILED,
                records_processed=0,
                records_updated=0,
                records_inserted=0,
                records_failed=0,
                started_at=task.started_at,
                completed_at=task.completed_at,
                error_message=error_msg
            )
            
            task.result = result
            return result
    
    def _should_retry_task(self, task: SyncTask) -> bool:
        """Проверка необходимости повторного выполнения задачи."""
        if task.retry_count >= task.max_retries:
            return False
        
        if task.is_failed and task.error:
            # Повторяем только при определенных типах ошибок
            retryable_errors = [
                "timeout",
                "connection",
                "rate limit",
                "temporary"
            ]
            
            error_lower = task.error.lower()
            return any(err in error_lower for err in retryable_errors)
        
        return False
    
    async def run_parallel_sync(
        self,
        marketplaces: List[str],
        wait_for_completion: bool = True
    ) -> Dict[str, SyncResult]:
        """
        Запуск параллельной синхронизации для указанных маркетплейсов.
        
        Args:
            marketplaces: Список маркетплейсов для синхронизации
            wait_for_completion: Ожидать завершения всех задач
            
        Returns:
            Словарь результатов синхронизации
        """
        logger.info(f"🚀 Запуск параллельной синхронизации: {', '.join(marketplaces)}")
        
        # Запускаем мониторинг ресурсов
        self.start_monitoring()
        
        try:
            # Добавляем задачи в очередь
            task_ids = []
            for marketplace in marketplaces:
                task_id = self.add_sync_task(marketplace, priority=SyncPriority.HIGH)
                task_ids.append(task_id)
            
            # Запускаем обработку задач
            futures = []
            active_marketplaces = set()
            
            while task_ids or futures:
                # Проверяем доступность ресурсов
                if not self._check_resource_availability():
                    await asyncio.sleep(5)
                    continue
                
                # Запускаем новые задачи если есть свободные слоты
                while (len(active_marketplaces) < self.max_concurrent_marketplaces and 
                       len(futures) < self.max_workers):
                    
                    next_task = self._get_next_task()
                    if not next_task:
                        break
                    
                    task_id, task = next_task
                    
                    # Проверяем, не обрабатывается ли уже этот маркетплейс
                    if task.marketplace in active_marketplaces:
                        # Возвращаем задачу в очередь
                        priority_queue = self._task_queues[task.priority]
                        priority_queue.put((task.priority.value, task_id, task))
                        break
                    
                    # Запускаем задачу
                    future = self._thread_executor.submit(self._execute_sync_task, task_id, task)
                    futures.append((future, task_id, task))
                    active_marketplaces.add(task.marketplace)
                    
                    logger.info(f"🔄 Запущена задача {task_id} для {task.marketplace}")
                
                # Проверяем завершенные задачи
                completed_futures = []
                for future, task_id, task in futures:
                    if future.done():
                        completed_futures.append((future, task_id, task))
                
                # Обрабатываем завершенные задачи
                for future, task_id, task in completed_futures:
                    futures.remove((future, task_id, task))
                    active_marketplaces.discard(task.marketplace)
                    
                    try:
                        result = future.result()
                        
                        # Проверяем необходимость повтора
                        if self._should_retry_task(task):
                            task.retry_count += 1
                            retry_task_id = self.add_sync_task(
                                task.marketplace,
                                task.task_type,
                                SyncPriority.HIGH
                            )
                            task_ids.append(retry_task_id)
                            logger.info(f"🔄 Повторная попытка {task.retry_count} для {task_id}")
                        else:
                            # Задача завершена окончательно
                            with self._task_lock:
                                self._completed_tasks.append(task)
                                if task_id in self._active_tasks:
                                    del self._active_tasks[task_id]
                            
                            if task_id in task_ids:
                                task_ids.remove(task_id)
                        
                    except Exception as e:
                        logger.error(f"❌ Ошибка получения результата задачи {task_id}: {e}")
                
                # Небольшая пауза между итерациями
                await asyncio.sleep(1)
            
            # Ожидаем завершения всех задач если требуется
            if wait_for_completion:
                while futures:
                    await asyncio.sleep(1)
                    # Повторяем логику обработки завершенных задач
                    completed_futures = [(f, tid, t) for f, tid, t in futures if f.done()]
                    for future, task_id, task in completed_futures:
                        futures.remove((future, task_id, task))
                        try:
                            future.result()
                        except Exception as e:
                            logger.error(f"❌ Ошибка задачи {task_id}: {e}")
            
            # Собираем результаты
            results = {}
            for task in self._completed_tasks:
                if task.result:
                    results[task.marketplace] = task.result
            
            # Обновляем метрики производительности
            self._update_performance_metrics()
            
            logger.info(f"✅ Параллельная синхронизация завершена: {len(results)} результатов")
            return results
            
        finally:
            self.stop_monitoring()
    
    def _update_performance_metrics(self) -> None:
        """Обновление метрик производительности."""
        total_tasks = len(self._completed_tasks)
        completed_tasks = len([t for t in self._completed_tasks if not t.is_failed])
        failed_tasks = len([t for t in self._completed_tasks if t.is_failed])
        
        # Вычисляем среднюю длительность
        durations = [t.duration.total_seconds() for t in self._completed_tasks if t.duration]
        avg_duration = sum(durations) / len(durations) if durations else 0.0
        
        # Вычисляем пропускную способность
        elapsed_time = (datetime.now() - self._start_time).total_seconds() / 60  # в минутах
        throughput = completed_tasks / elapsed_time if elapsed_time > 0 else 0.0
        
        # Получаем статистику API оптимизатора
        api_stats = self._api_optimizer.get_performance_stats()
        
        self._performance_metrics = PerformanceMetrics(
            total_tasks=total_tasks,
            completed_tasks=completed_tasks,
            failed_tasks=failed_tasks,
            avg_duration=avg_duration,
            throughput_per_minute=throughput,
            resource_usage=self._resource_history[-1] if self._resource_history else None,
            cache_hit_rate=api_stats['cache_stats']['hit_rate'],
            api_requests_per_minute=api_stats['api_stats']['total_requests'] / elapsed_time if elapsed_time > 0 else 0.0
        )
    
    def get_performance_report(self) -> Dict[str, Any]:
        """Получение отчета о производительности."""
        self._update_performance_metrics()
        
        return {
            'performance_metrics': {
                'total_tasks': self._performance_metrics.total_tasks,
                'completed_tasks': self._performance_metrics.completed_tasks,
                'failed_tasks': self._performance_metrics.failed_tasks,
                'success_rate': (
                    self._performance_metrics.completed_tasks / self._performance_metrics.total_tasks
                    if self._performance_metrics.total_tasks > 0 else 0.0
                ),
                'avg_duration_seconds': self._performance_metrics.avg_duration,
                'throughput_per_minute': self._performance_metrics.throughput_per_minute,
                'cache_hit_rate': self._performance_metrics.cache_hit_rate,
                'api_requests_per_minute': self._performance_metrics.api_requests_per_minute
            },
            'resource_usage': (
                {
                    'cpu_percent': self._performance_metrics.resource_usage.cpu_percent,
                    'memory_percent': self._performance_metrics.resource_usage.memory_percent,
                    'memory_mb': self._performance_metrics.resource_usage.memory_mb,
                    'timestamp': self._performance_metrics.resource_usage.timestamp.isoformat()
                }
                if self._performance_metrics.resource_usage else None
            ),
            'active_tasks': len(self._active_tasks),
            'queue_sizes': {
                priority.name: queue.qsize() for priority, queue in self._task_queues.items()
            },
            'api_optimizer_stats': self._api_optimizer.get_performance_stats()
        }
    
    def cleanup(self) -> None:
        """Очистка ресурсов менеджера."""
        logger.info("🧹 Очистка ресурсов менеджера...")
        
        self.stop_monitoring()
        
        # Завершаем пулы исполнителей
        self._thread_executor.shutdown(wait=True)
        self._process_executor.shutdown(wait=True)
        
        # Очищаем оптимизатор API
        self._api_optimizer.cleanup()
        
        logger.info("✅ Очистка ресурсов завершена")


# Пример использования
async def main():
    """Пример использования менеджера параллельной синхронизации."""
    manager = ParallelSyncManager(
        max_workers=4,
        max_concurrent_marketplaces=2,
        resource_monitoring=True
    )
    
    try:
        # Запускаем параллельную синхронизацию
        results = await manager.run_parallel_sync(['Ozon', 'Wildberries'])
        
        # Выводим результаты
        for marketplace, result in results.items():
            print(f"\n{marketplace} синхронизация:")
            print(f"  Статус: {result.status.value}")
            print(f"  Обработано: {result.records_processed}")
            print(f"  Вставлено: {result.records_inserted}")
            print(f"  Время: {result.duration_seconds} сек")
        
        # Отчет о производительности
        performance_report = manager.get_performance_report()
        print(f"\nОтчет о производительности:")
        print(json.dumps(performance_report, indent=2, default=str))
        
    finally:
        manager.cleanup()


if __name__ == "__main__":
    asyncio.run(main())