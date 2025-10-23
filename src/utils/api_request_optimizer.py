#!/usr/bin/env python3
"""
Оптимизатор API запросов для синхронизации остатков товаров.

Улучшения:
- Параллельная обработка данных с разных маркетплейсов
- Кэширование неизменяемых данных
- Оптимизация размера батчей для API запросов
- Адаптивное управление rate limits

Автор: ETL System
Дата: 06 января 2025
"""

import asyncio
import aiohttp
import time
import json
import hashlib
from datetime import datetime, timedelta
from typing import Dict, List, Any, Optional, Tuple, Callable
from dataclasses import dataclass, asdict
from enum import Enum
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed
import threading
from collections import defaultdict, deque
import pickle
import os

logger = logging.getLogger(__name__)


class CacheType(Enum):
    """Типы кэшируемых данных."""
    PRODUCT_INFO = "product_info"
    WAREHOUSE_LIST = "warehouse_list"
    CATEGORY_INFO = "category_info"
    RATE_LIMITS = "rate_limits"


@dataclass
class CacheEntry:
    """Запись в кэше."""
    data: Any
    created_at: datetime
    expires_at: datetime
    access_count: int = 0
    last_accessed: Optional[datetime] = None
    
    def is_expired(self) -> bool:
        """Проверка истечения срока действия."""
        return datetime.now() > self.expires_at
    
    def access(self) -> Any:
        """Доступ к данным с обновлением статистики."""
        self.access_count += 1
        self.last_accessed = datetime.now()
        return self.data


@dataclass
class RateLimitInfo:
    """Информация о лимитах API."""
    requests_per_second: float
    requests_per_minute: int
    requests_per_hour: int
    current_requests: int = 0
    reset_time: Optional[datetime] = None
    last_request_time: Optional[datetime] = None


@dataclass
class BatchConfig:
    """Конфигурация батчей для API."""
    initial_size: int
    min_size: int
    max_size: int
    adaptive: bool = True
    success_threshold: float = 0.95
    error_threshold: float = 0.1


class APIRequestOptimizer:
    """Оптимизатор API запросов с кэшированием и параллельной обработкой."""
    
    def __init__(self, cache_dir: str = "cache", max_cache_size: int = 1000):
        """
        Инициализация оптимизатора.
        
        Args:
            cache_dir: Директория для хранения кэша
            max_cache_size: Максимальный размер кэша в записях
        """
        self.cache_dir = cache_dir
        self.max_cache_size = max_cache_size
        self._cache: Dict[str, CacheEntry] = {}
        self._cache_lock = threading.Lock()
        
        # Статистика производительности
        self._stats = {
            'cache_hits': 0,
            'cache_misses': 0,
            'api_requests': 0,
            'parallel_requests': 0,
            'batch_optimizations': 0
        }
        
        # Конфигурации батчей для разных API
        self._batch_configs = {
            'ozon_stocks': BatchConfig(1000, 100, 2000),
            'ozon_products': BatchConfig(500, 50, 1000),
            'wb_stocks': BatchConfig(1000, 100, 2000),
            'wb_products': BatchConfig(500, 50, 1000)
        }
        
        # Информация о rate limits
        self._rate_limits = {
            'ozon': RateLimitInfo(10.0, 600, 7200),  # 10 RPS, 600 RPM, 7200 RPH
            'wb': RateLimitInfo(5.0, 300, 3600)      # 5 RPS, 300 RPM, 3600 RPH
        }
        
        # Очереди запросов для rate limiting
        self._request_queues = {
            'ozon': deque(),
            'wb': deque()
        }
        
        # Создаем директорию кэша
        os.makedirs(cache_dir, exist_ok=True)
        
        # Загружаем кэш из файла
        self._load_cache_from_disk()
    
    def _generate_cache_key(self, cache_type: CacheType, **kwargs) -> str:
        """Генерация ключа кэша."""
        key_data = f"{cache_type.value}:{json.dumps(kwargs, sort_keys=True)}"
        return hashlib.md5(key_data.encode()).hexdigest()
    
    def _load_cache_from_disk(self) -> None:
        """Загрузка кэша с диска."""
        cache_file = os.path.join(self.cache_dir, "api_cache.pkl")
        
        try:
            if os.path.exists(cache_file):
                with open(cache_file, 'rb') as f:
                    disk_cache = pickle.load(f)
                
                # Фильтруем неистекшие записи
                current_time = datetime.now()
                for key, entry in disk_cache.items():
                    if not entry.is_expired():
                        self._cache[key] = entry
                
                logger.info(f"✅ Загружено {len(self._cache)} записей из кэша")
        except Exception as e:
            logger.warning(f"⚠️ Ошибка загрузки кэша: {e}")
    
    def _save_cache_to_disk(self) -> None:
        """Сохранение кэша на диск."""
        cache_file = os.path.join(self.cache_dir, "api_cache.pkl")
        
        try:
            with self._cache_lock:
                # Сохраняем только неистекшие записи
                valid_cache = {
                    key: entry for key, entry in self._cache.items()
                    if not entry.is_expired()
                }
                
                with open(cache_file, 'wb') as f:
                    pickle.dump(valid_cache, f)
                
                logger.debug(f"💾 Сохранено {len(valid_cache)} записей в кэш")
        except Exception as e:
            logger.warning(f"⚠️ Ошибка сохранения кэша: {e}")
    
    def get_cached_data(self, cache_type: CacheType, **kwargs) -> Optional[Any]:
        """
        Получение данных из кэша.
        
        Args:
            cache_type: Тип кэшируемых данных
            **kwargs: Параметры для генерации ключа
            
        Returns:
            Кэшированные данные или None
        """
        cache_key = self._generate_cache_key(cache_type, **kwargs)
        
        with self._cache_lock:
            if cache_key in self._cache:
                entry = self._cache[cache_key]
                
                if not entry.is_expired():
                    self._stats['cache_hits'] += 1
                    return entry.access()
                else:
                    # Удаляем истекшую запись
                    del self._cache[cache_key]
            
            self._stats['cache_misses'] += 1
            return None
    
    def set_cached_data(self, cache_type: CacheType, data: Any, ttl_hours: int = 24, **kwargs) -> None:
        """
        Сохранение данных в кэш.
        
        Args:
            cache_type: Тип кэшируемых данных
            data: Данные для кэширования
            ttl_hours: Время жизни в часах
            **kwargs: Параметры для генерации ключа
        """
        cache_key = self._generate_cache_key(cache_type, **kwargs)
        
        with self._cache_lock:
            # Проверяем размер кэша и очищаем при необходимости
            if len(self._cache) >= self.max_cache_size:
                self._cleanup_cache()
            
            # Создаем новую запись
            entry = CacheEntry(
                data=data,
                created_at=datetime.now(),
                expires_at=datetime.now() + timedelta(hours=ttl_hours)
            )
            
            self._cache[cache_key] = entry
    
    def _cleanup_cache(self) -> None:
        """Очистка кэша от старых записей."""
        current_time = datetime.now()
        
        # Удаляем истекшие записи
        expired_keys = [
            key for key, entry in self._cache.items()
            if entry.is_expired()
        ]
        
        for key in expired_keys:
            del self._cache[key]
        
        # Если все еще превышен лимит, удаляем наименее используемые
        if len(self._cache) >= self.max_cache_size:
            # Сортируем по частоте использования и времени последнего доступа
            sorted_entries = sorted(
                self._cache.items(),
                key=lambda x: (x[1].access_count, x[1].last_accessed or x[1].created_at)
            )
            
            # Удаляем 20% наименее используемых записей
            to_remove = int(len(sorted_entries) * 0.2)
            for key, _ in sorted_entries[:to_remove]:
                del self._cache[key]
        
        logger.debug(f"🧹 Очистка кэша: осталось {len(self._cache)} записей")
    
    def _check_rate_limit(self, marketplace: str) -> float:
        """
        Проверка rate limit и возврат времени ожидания.
        
        Args:
            marketplace: Маркетплейс ('ozon' или 'wb')
            
        Returns:
            Время ожидания в секундах
        """
        if marketplace not in self._rate_limits:
            return 0.0
        
        rate_info = self._rate_limits[marketplace]
        current_time = datetime.now()
        
        # Очищаем старые запросы из очереди
        queue = self._request_queues[marketplace]
        while queue and (current_time - queue[0]).total_seconds() > 60:
            queue.popleft()
        
        # Проверяем лимит запросов в минуту
        if len(queue) >= rate_info.requests_per_minute:
            # Вычисляем время ожидания до освобождения слота
            oldest_request = queue[0]
            wait_time = 60 - (current_time - oldest_request).total_seconds()
            return max(0, wait_time)
        
        # Проверяем лимит запросов в секунду
        recent_requests = [
            req_time for req_time in queue
            if (current_time - req_time).total_seconds() <= 1
        ]
        
        if len(recent_requests) >= rate_info.requests_per_second:
            return 1.0 / rate_info.requests_per_second
        
        return 0.0
    
    def _record_request(self, marketplace: str) -> None:
        """Запись времени выполнения запроса."""
        if marketplace in self._request_queues:
            self._request_queues[marketplace].append(datetime.now())
            self._stats['api_requests'] += 1
    
    async def make_api_request(
        self,
        session: aiohttp.ClientSession,
        method: str,
        url: str,
        marketplace: str,
        cache_type: Optional[CacheType] = None,
        cache_ttl: int = 24,
        **kwargs
    ) -> Optional[Dict[str, Any]]:
        """
        Выполнение API запроса с кэшированием и rate limiting.
        
        Args:
            session: HTTP сессия
            method: HTTP метод
            url: URL запроса
            marketplace: Маркетплейс для rate limiting
            cache_type: Тип кэширования
            cache_ttl: Время жизни кэша в часах
            **kwargs: Дополнительные параметры запроса
            
        Returns:
            Ответ API или None при ошибке
        """
        # Проверяем кэш для GET запросов
        if method.upper() == 'GET' and cache_type:
            cached_data = self.get_cached_data(cache_type, url=url, **kwargs.get('params', {}))
            if cached_data:
                return cached_data
        
        # Проверяем rate limit
        wait_time = self._check_rate_limit(marketplace)
        if wait_time > 0:
            logger.debug(f"⏳ Ожидание rate limit для {marketplace}: {wait_time:.2f} сек")
            await asyncio.sleep(wait_time)
        
        try:
            # Выполняем запрос
            async with session.request(method, url, **kwargs) as response:
                self._record_request(marketplace)
                
                if response.status == 200:
                    data = await response.json()
                    
                    # Кэшируем GET запросы
                    if method.upper() == 'GET' and cache_type:
                        self.set_cached_data(cache_type, data, cache_ttl, url=url, **kwargs.get('params', {}))
                    
                    return data
                
                elif response.status == 429:  # Too Many Requests
                    # Обновляем информацию о rate limits
                    retry_after = int(response.headers.get('Retry-After', 60))
                    logger.warning(f"⚠️ Rate limit для {marketplace}, ожидание {retry_after} сек")
                    await asyncio.sleep(retry_after)
                    return None
                
                else:
                    logger.error(f"❌ API ошибка {response.status}: {await response.text()}")
                    return None
        
        except asyncio.TimeoutError:
            logger.error(f"❌ Timeout при запросе к {url}")
            return None
        except Exception as e:
            logger.error(f"❌ Ошибка API запроса: {e}")
            return None
    
    async def fetch_data_parallel(
        self,
        requests: List[Dict[str, Any]],
        max_concurrent: int = 10
    ) -> List[Optional[Dict[str, Any]]]:
        """
        Параллельное выполнение множественных API запросов.
        
        Args:
            requests: Список запросов с параметрами
            max_concurrent: Максимальное количество одновременных запросов
            
        Returns:
            Список результатов запросов
        """
        semaphore = asyncio.Semaphore(max_concurrent)
        
        async def bounded_request(request_params: Dict[str, Any]) -> Optional[Dict[str, Any]]:
            async with semaphore:
                async with aiohttp.ClientSession() as session:
                    return await self.make_api_request(session, **request_params)
        
        # Выполняем запросы параллельно
        tasks = [bounded_request(req) for req in requests]
        results = await asyncio.gather(*tasks, return_exceptions=True)
        
        # Обрабатываем исключения
        processed_results = []
        for result in results:
            if isinstance(result, Exception):
                logger.error(f"❌ Ошибка параллельного запроса: {result}")
                processed_results.append(None)
            else:
                processed_results.append(result)
        
        self._stats['parallel_requests'] += len(requests)
        return processed_results
    
    def optimize_batch_size(self, api_type: str, success_rate: float, avg_response_time: float) -> int:
        """
        Оптимизация размера батча на основе производительности.
        
        Args:
            api_type: Тип API
            success_rate: Процент успешных запросов
            avg_response_time: Среднее время ответа
            
        Returns:
            Оптимальный размер батча
        """
        if api_type not in self._batch_configs:
            return 1000  # Значение по умолчанию
        
        config = self._batch_configs[api_type]
        
        if not config.adaptive:
            return config.initial_size
        
        current_size = config.initial_size
        
        # Увеличиваем размер при хорошей производительности
        if success_rate >= config.success_threshold and avg_response_time < 2.0:
            new_size = min(int(current_size * 1.2), config.max_size)
        
        # Уменьшаем размер при плохой производительности
        elif success_rate < config.error_threshold or avg_response_time > 10.0:
            new_size = max(int(current_size * 0.8), config.min_size)
        
        # Слегка уменьшаем при средней производительности
        elif success_rate < config.success_threshold:
            new_size = max(int(current_size * 0.9), config.min_size)
        
        else:
            new_size = current_size
        
        # Обновляем конфигурацию
        config.initial_size = new_size
        self._stats['batch_optimizations'] += 1
        
        logger.debug(f"📊 Оптимизация батча {api_type}: {current_size} -> {new_size} "
                    f"(success: {success_rate:.2%}, time: {avg_response_time:.2f}s)")
        
        return new_size
    
    async def fetch_ozon_stocks_optimized(
        self,
        client_id: str,
        api_key: str,
        base_url: str
    ) -> List[Dict[str, Any]]:
        """
        Оптимизированное получение остатков с Ozon API.
        
        Args:
            client_id: Client ID для Ozon API
            api_key: API ключ для Ozon API
            base_url: Базовый URL API
            
        Returns:
            Список товаров с остатками
        """
        all_items = []
        batch_size = self._batch_configs['ozon_stocks'].initial_size
        
        headers = {
            "Client-Id": client_id,
            "Api-Key": api_key,
            "Content-Type": "application/json"
        }
        
        async with aiohttp.ClientSession() as session:
            url = f"{base_url}/v3/product/info/stocks"
            offset = 0
            
            while True:
                start_time = time.time()
                
                payload = {
                    "filter": {},
                    "last_id": "",
                    "limit": batch_size
                }
                
                # Проверяем кэш для этого запроса
                cache_key = f"ozon_stocks_{offset}_{batch_size}"
                cached_data = self.get_cached_data(
                    CacheType.PRODUCT_INFO,
                    endpoint="stocks",
                    offset=offset,
                    limit=batch_size
                )
                
                if cached_data:
                    items = cached_data.get('items', [])
                    logger.debug(f"📦 Получено {len(items)} товаров из кэша (offset: {offset})")
                else:
                    # Выполняем API запрос
                    response_data = await self.make_api_request(
                        session=session,
                        method='POST',
                        url=url,
                        marketplace='ozon',
                        json=payload,
                        headers=headers,
                        timeout=30
                    )
                    
                    if not response_data:
                        logger.error(f"❌ Ошибка получения данных с offset {offset}")
                        break
                    
                    items = response_data.get('result', {}).get('items', [])
                    
                    # Кэшируем результат на 1 час
                    self.set_cached_data(
                        CacheType.PRODUCT_INFO,
                        {'items': items},
                        ttl_hours=1,
                        endpoint="stocks",
                        offset=offset,
                        limit=batch_size
                    )
                    
                    logger.info(f"📦 Получено {len(items)} товаров с Ozon API (offset: {offset})")
                
                if not items:
                    break
                
                all_items.extend(items)
                
                # Оптимизируем размер батча на основе производительности
                response_time = time.time() - start_time
                success_rate = 1.0 if items else 0.0
                
                new_batch_size = self.optimize_batch_size('ozon_stocks', success_rate, response_time)
                if new_batch_size != batch_size:
                    batch_size = new_batch_size
                    logger.info(f"🔧 Размер батча изменен на {batch_size}")
                
                # Если получили меньше лимита, это последняя страница
                if len(items) < batch_size:
                    break
                
                offset += len(items)
        
        logger.info(f"✅ Всего получено {len(all_items)} товаров с Ozon")
        return all_items
    
    async def fetch_wb_stocks_optimized(
        self,
        api_token: str,
        base_url: str
    ) -> List[Dict[str, Any]]:
        """
        Оптимизированное получение остатков с Wildberries API.
        
        Args:
            api_token: API токен для WB
            base_url: Базовый URL API
            
        Returns:
            Список товаров с остатками
        """
        headers = {"Authorization": api_token}
        
        # Проверяем кэш
        cache_key = "wb_stocks_today"
        cached_data = self.get_cached_data(
            CacheType.PRODUCT_INFO,
            endpoint="stocks",
            date=datetime.now().date().isoformat()
        )
        
        if cached_data:
            logger.info(f"📦 Получено {len(cached_data)} товаров WB из кэша")
            return cached_data
        
        async with aiohttp.ClientSession() as session:
            url = f"{base_url}/api/v1/supplier/stocks"
            params = {
                'dateFrom': datetime.now().replace(hour=0, minute=0, second=0, microsecond=0).isoformat()
            }
            
            response_data = await self.make_api_request(
                session=session,
                method='GET',
                url=url,
                marketplace='wb',
                headers=headers,
                params=params,
                timeout=30
            )
            
            if response_data and isinstance(response_data, list):
                # Кэшируем на 30 минут
                self.set_cached_data(
                    CacheType.PRODUCT_INFO,
                    response_data,
                    ttl_hours=0.5,
                    endpoint="stocks",
                    date=datetime.now().date().isoformat()
                )
                
                logger.info(f"📦 Получено {len(response_data)} товаров с WB API")
                return response_data
            
            logger.error("❌ Ошибка получения данных с WB API")
            return []
    
    def get_performance_stats(self) -> Dict[str, Any]:
        """Получение статистики производительности."""
        cache_hit_rate = 0.0
        total_cache_requests = self._stats['cache_hits'] + self._stats['cache_misses']
        
        if total_cache_requests > 0:
            cache_hit_rate = self._stats['cache_hits'] / total_cache_requests
        
        return {
            'cache_stats': {
                'hit_rate': cache_hit_rate,
                'total_entries': len(self._cache),
                'hits': self._stats['cache_hits'],
                'misses': self._stats['cache_misses']
            },
            'api_stats': {
                'total_requests': self._stats['api_requests'],
                'parallel_requests': self._stats['parallel_requests'],
                'batch_optimizations': self._stats['batch_optimizations']
            },
            'batch_configs': {
                api_type: asdict(config) for api_type, config in self._batch_configs.items()
            }
        }
    
    def cleanup(self) -> None:
        """Очистка ресурсов и сохранение кэша."""
        self._save_cache_to_disk()
        logger.info("🧹 Очистка оптимизатора API завершена")


# Пример использования
async def main():
    """Пример использования оптимизатора API."""
    optimizer = APIRequestOptimizer()
    
    try:
        # Получение остатков Ozon
        ozon_stocks = await optimizer.fetch_ozon_stocks_optimized(
            client_id="your_client_id",
            api_key="your_api_key",
            base_url="https://api-seller.ozon.ru"
        )
        
        # Получение остатков WB
        wb_stocks = await optimizer.fetch_wb_stocks_optimized(
            api_token="your_token",
            base_url="https://suppliers-api.wildberries.ru"
        )
        
        # Статистика производительности
        stats = optimizer.get_performance_stats()
        print(f"Статистика: {json.dumps(stats, indent=2, default=str)}")
        
    finally:
        optimizer.cleanup()


if __name__ == "__main__":
    asyncio.run(main())