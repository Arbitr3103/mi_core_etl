#!/usr/bin/env python3
"""
Система мониторинга и алертов для синхронизации остатков товаров.

Класс SyncMonitor для проверки актуальности данных, детекции аномалий
в данных остатков и генерации отчетов о синхронизации.

Автор: ETL System
Дата: 06 января 2025
"""

import logging
import smtplib
from datetime import datetime, timedelta, date
from typing import List, Dict, Any, Optional, Tuple
from dataclasses import dataclass
from enum import Enum
try:
    from email.mime.text import MimeText
    from email.mime.multipart import MimeMultipart
except ImportError:
    # Email модули недоступны
    MimeText = None
    MimeMultipart = None
import statistics
import json


class HealthStatus(Enum):
    """Статусы состояния системы."""
    HEALTHY = "healthy"
    WARNING = "warning"
    CRITICAL = "critical"
    UNKNOWN = "unknown"


class AnomalyType(Enum):
    """Типы аномалий в данных."""
    ZERO_STOCK_SPIKE = "zero_stock_spike"
    MASSIVE_STOCK_CHANGE = "massive_stock_change"
    MISSING_PRODUCTS = "missing_products"
    DUPLICATE_RECORDS = "duplicate_records"
    NEGATIVE_STOCK = "negative_stock"
    STALE_DATA = "stale_data"
    API_ERRORS = "api_errors"


@dataclass
class Anomaly:
    """Модель аномалии в данных."""
    type: AnomalyType
    severity: str  # 'low', 'medium', 'high', 'critical'
    source: str
    description: str
    affected_records: int
    detected_at: datetime
    details: Dict[str, Any]


@dataclass
class HealthReport:
    """Отчет о состоянии системы синхронизации."""
    overall_status: HealthStatus
    generated_at: datetime
    sources: Dict[str, Dict[str, Any]]
    anomalies: List[Anomaly]
    recommendations: List[str]
    metrics: Dict[str, Any]


@dataclass
class SyncMetrics:
    """Метрики синхронизации."""
    source: str
    last_sync_time: Optional[datetime]
    success_rate_24h: float
    avg_duration_seconds: float
    total_records_processed: int
    error_count_24h: int
    data_freshness_hours: float


class SyncMonitor:
    """
    Класс для мониторинга состояния системы синхронизации остатков.
    
    Обеспечивает:
    - Проверку актуальности данных
    - Детекцию аномалий в данных остатков
    - Генерацию отчетов о синхронизации
    - Отправку уведомлений при критических проблемах
    """
    
    def __init__(self, db_cursor, db_connection, logger_name: str = "SyncMonitor"):
        """
        Инициализация монитора синхронизации.
        
        Args:
            db_cursor: Курсор базы данных
            db_connection: Соединение с базой данных
            logger_name: Имя логгера
        """
        self.cursor = db_cursor
        self.connection = db_connection
        self.logger = logging.getLogger(logger_name)
        
        # Пороговые значения для детекции аномалий
        self.thresholds = {
            'data_freshness_hours': 6,  # Данные считаются устаревшими через 6 часов
            'success_rate_threshold': 0.8,  # Минимальный процент успешных синхронизаций
            'massive_change_threshold': 0.5,  # Изменение остатков более чем на 50%
            'zero_stock_threshold': 0.3,  # Более 30% товаров с нулевыми остатками
            'max_error_count_24h': 10,  # Максимальное количество ошибок за 24 часа
        }
    
    def check_sync_health(self) -> HealthReport:
        """
        Проверка общего состояния системы синхронизации.
        
        Returns:
            HealthReport: Отчет о состоянии системы
        """
        self.logger.info("🔍 Начинаем проверку состояния системы синхронизации")
        
        try:
            # Получаем метрики по источникам
            sources_metrics = self._get_sources_metrics()
            
            # Детектируем аномалии
            anomalies = self._detect_all_anomalies()
            
            # Определяем общий статус системы
            overall_status = self._calculate_overall_health(sources_metrics, anomalies)
            
            # Генерируем рекомендации
            recommendations = self._generate_recommendations(sources_metrics, anomalies)
            
            # Собираем общие метрики
            overall_metrics = self._calculate_overall_metrics(sources_metrics)
            
            report = HealthReport(
                overall_status=overall_status,
                generated_at=datetime.now(),
                sources=sources_metrics,
                anomalies=anomalies,
                recommendations=recommendations,
                metrics=overall_metrics
            )
            
            self.logger.info(f"✅ Проверка состояния завершена. Статус: {overall_status.value}")
            return report
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка проверки состояния системы: {e}")
            return HealthReport(
                overall_status=HealthStatus.UNKNOWN,
                generated_at=datetime.now(),
                sources={},
                anomalies=[],
                recommendations=[f"Ошибка мониторинга: {str(e)}"],
                metrics={}
            )
    
    def detect_data_anomalies(self, source: Optional[str] = None) -> List[Anomaly]:
        """
        Детекция аномалий в данных остатков.
        
        Args:
            source: Источник данных для проверки (опционально)
            
        Returns:
            List[Anomaly]: Список обнаруженных аномалий
        """
        self.logger.info(f"🔍 Детекция аномалий для источника: {source or 'все'}")
        
        anomalies = []
        
        try:
            # Проверяем различные типы аномалий
            anomalies.extend(self._detect_zero_stock_anomalies(source))
            anomalies.extend(self._detect_massive_stock_changes(source))
            anomalies.extend(self._detect_missing_products(source))
            anomalies.extend(self._detect_duplicate_records(source))
            anomalies.extend(self._detect_negative_stock(source))
            anomalies.extend(self._detect_stale_data(source))
            anomalies.extend(self._detect_api_errors(source))
            
            self.logger.info(f"🔍 Обнаружено {len(anomalies)} аномалий")
            return anomalies
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка детекции аномалий: {e}")
            return []
    
    def generate_sync_report(self, period_hours: int = 24) -> Dict[str, Any]:
        """
        Генерация отчета о синхронизации за указанный период.
        
        Args:
            period_hours: Период для отчета в часах
            
        Returns:
            Dict: Детальный отчет о синхронизации
        """
        self.logger.info(f"📊 Генерация отчета о синхронизации за {period_hours} часов")
        
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            # Получаем статистику синхронизации
            if is_sqlite:
                sync_stats_query = """
                    SELECT source, status, COUNT(*) as count,
                           AVG(duration_seconds) as avg_duration,
                           SUM(records_processed) as total_processed,
                           SUM(records_inserted) as total_inserted,
                           SUM(records_failed) as total_failed,
                           MAX(completed_at) as last_sync
                    FROM sync_logs 
                    WHERE started_at >= datetime('now', '-{} hours')
                      AND sync_type = 'inventory'
                    GROUP BY source, status
                    ORDER BY source, status
                """.format(period_hours)
            else:
                sync_stats_query = """
                    SELECT source, status, COUNT(*) as count,
                           AVG(duration_seconds) as avg_duration,
                           SUM(records_processed) as total_processed,
                           SUM(records_inserted) as total_inserted,
                           SUM(records_failed) as total_failed,
                           MAX(completed_at) as last_sync
                    FROM sync_logs 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL %s HOUR)
                      AND sync_type = 'inventory'
                    GROUP BY source, status
                    ORDER BY source, status
                """
            
            if is_sqlite:
                self.cursor.execute(sync_stats_query)
            else:
                self.cursor.execute(sync_stats_query, (period_hours,))
            
            sync_stats = self.cursor.fetchall()
            
            # Получаем информацию об остатках
            inventory_stats_query = """
                SELECT source, COUNT(*) as total_products,
                       SUM(CASE WHEN current_stock > 0 THEN 1 ELSE 0 END) as products_with_stock,
                       SUM(current_stock) as total_stock,
                       AVG(current_stock) as avg_stock,
                       MAX(last_sync_at) as last_update
                FROM inventory_data 
                WHERE snapshot_date >= %s
                GROUP BY source
            """
            
            if is_sqlite:
                inventory_stats_query = inventory_stats_query.replace('%s', '?')
                self.cursor.execute(inventory_stats_query, (date.today() - timedelta(days=1),))
            else:
                self.cursor.execute(inventory_stats_query, (date.today() - timedelta(days=1),))
            
            inventory_stats = self.cursor.fetchall()
            
            # Формируем отчет
            report = {
                "generated_at": datetime.now(),
                "period_hours": period_hours,
                "sync_statistics": self._format_sync_statistics(sync_stats),
                "inventory_statistics": self._format_inventory_statistics(inventory_stats),
                "anomalies": [anomaly.__dict__ for anomaly in self.detect_data_anomalies()],
                "health_status": self.check_sync_health().__dict__
            }
            
            self.logger.info("📊 Отчет о синхронизации сгенерирован")
            return report
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка генерации отчета: {e}")
            return {
                "error": str(e),
                "generated_at": datetime.now()
            }
    
    def _get_sources_metrics(self) -> Dict[str, Dict[str, Any]]:
        """Получение метрик по источникам данных."""
        sources_metrics = {}
        
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            # Получаем список источников
            sources = ['Ozon', 'Wildberries']
            
            for source in sources:
                metrics = self._calculate_source_metrics(source)
                sources_metrics[source] = {
                    'last_sync_time': metrics.last_sync_time,
                    'success_rate_24h': metrics.success_rate_24h,
                    'avg_duration_seconds': metrics.avg_duration_seconds,
                    'total_records_processed': metrics.total_records_processed,
                    'error_count_24h': metrics.error_count_24h,
                    'data_freshness_hours': metrics.data_freshness_hours,
                    'health_status': self._determine_source_health(metrics)
                }
            
            return sources_metrics
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка получения метрик источников: {e}")
            return {}
    
    def _calculate_source_metrics(self, source: str) -> SyncMetrics:
        """Расчет метрик для конкретного источника."""
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            # Получаем статистику синхронизации за 24 часа
            if is_sqlite:
                sync_query = """
                    SELECT status, COUNT(*) as count,
                           AVG(duration_seconds) as avg_duration,
                           SUM(records_processed) as total_processed,
                           MAX(completed_at) as last_sync
                    FROM sync_logs 
                    WHERE source = ? 
                      AND started_at >= datetime('now', '-24 hours')
                      AND sync_type = 'inventory'
                    GROUP BY status
                """
                self.cursor.execute(sync_query, (source,))
            else:
                sync_query = """
                    SELECT status, COUNT(*) as count,
                           AVG(duration_seconds) as avg_duration,
                           SUM(records_processed) as total_processed,
                           MAX(completed_at) as last_sync
                    FROM sync_logs 
                    WHERE source = %s 
                      AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      AND sync_type = 'inventory'
                    GROUP BY status
                """
                self.cursor.execute(sync_query, (source,))
            
            sync_results = self.cursor.fetchall()
            
            # Обрабатываем результаты
            total_syncs = 0
            success_count = 0
            avg_duration = 0
            total_processed = 0
            last_sync_time = None
            error_count = 0
            
            for result in sync_results:
                if isinstance(result, dict):
                    status = result['status']
                    count = result['count']
                    duration = result['avg_duration'] or 0
                    processed = result['total_processed'] or 0
                    last_sync = result['last_sync']
                else:
                    status, count, duration, processed, last_sync = result
                
                total_syncs += count
                total_processed += processed or 0
                
                if status == 'success':
                    success_count += count
                elif status == 'failed':
                    error_count += count
                
                if duration and duration > avg_duration:
                    avg_duration = duration
                
                if last_sync and (not last_sync_time or last_sync > last_sync_time):
                    last_sync_time = last_sync
            
            # Рассчитываем метрики
            success_rate = success_count / total_syncs if total_syncs > 0 else 0
            
            # Рассчитываем свежесть данных
            data_freshness_hours = 999  # По умолчанию очень старые данные
            if last_sync_time:
                if isinstance(last_sync_time, str):
                    last_sync_time = datetime.fromisoformat(last_sync_time.replace('Z', '+00:00'))
                data_freshness_hours = (datetime.now() - last_sync_time).total_seconds() / 3600
            
            return SyncMetrics(
                source=source,
                last_sync_time=last_sync_time,
                success_rate_24h=success_rate,
                avg_duration_seconds=avg_duration,
                total_records_processed=total_processed,
                error_count_24h=error_count,
                data_freshness_hours=data_freshness_hours
            )
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка расчета метрик для {source}: {e}")
            return SyncMetrics(
                source=source,
                last_sync_time=None,
                success_rate_24h=0,
                avg_duration_seconds=0,
                total_records_processed=0,
                error_count_24h=999,
                data_freshness_hours=999
            )
    
    def _determine_source_health(self, metrics: SyncMetrics) -> HealthStatus:
        """Определение состояния здоровья источника."""
        # Критические проблемы
        if (metrics.data_freshness_hours > 24 or 
            metrics.success_rate_24h < 0.5 or 
            metrics.error_count_24h > 20):
            return HealthStatus.CRITICAL
        
        # Предупреждения
        if (metrics.data_freshness_hours > self.thresholds['data_freshness_hours'] or
            metrics.success_rate_24h < self.thresholds['success_rate_threshold'] or
            metrics.error_count_24h > self.thresholds['max_error_count_24h']):
            return HealthStatus.WARNING
        
        # Здоровое состояние
        if metrics.last_sync_time and metrics.success_rate_24h > 0.8:
            return HealthStatus.HEALTHY
        
        return HealthStatus.UNKNOWN
    
    def _detect_all_anomalies(self) -> List[Anomaly]:
        """Детекция всех типов аномалий."""
        anomalies = []
        
        for source in ['Ozon', 'Wildberries']:
            anomalies.extend(self.detect_data_anomalies(source))
        
        return anomalies
    
    def _detect_zero_stock_anomalies(self, source: Optional[str] = None) -> List[Anomaly]:
        """Детекция аномалий с нулевыми остатками."""
        anomalies = []
        
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            query = """
                SELECT source, 
                       COUNT(*) as total_products,
                       SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) as zero_stock_count
                FROM inventory_data 
                WHERE snapshot_date = %s
            """
            
            params = [date.today()]
            
            if source:
                query += " AND source = %s"
                params.append(source)
            
            query += " GROUP BY source"
            
            if is_sqlite:
                query = query.replace('%s', '?')
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            for result in results:
                if isinstance(result, dict):
                    src = result['source']
                    total = result['total_products']
                    zero_count = result['zero_stock_count']
                else:
                    src, total, zero_count = result
                
                if total > 0:
                    zero_ratio = zero_count / total
                    
                    if zero_ratio > self.thresholds['zero_stock_threshold']:
                        severity = 'critical' if zero_ratio > 0.7 else 'high' if zero_ratio > 0.5 else 'medium'
                        
                        anomaly = Anomaly(
                            type=AnomalyType.ZERO_STOCK_SPIKE,
                            severity=severity,
                            source=src,
                            description=f"Высокий процент товаров с нулевыми остатками: {zero_ratio:.1%}",
                            affected_records=zero_count,
                            detected_at=datetime.now(),
                            details={
                                'total_products': total,
                                'zero_stock_count': zero_count,
                                'zero_stock_ratio': zero_ratio,
                                'threshold': self.thresholds['zero_stock_threshold']
                            }
                        )
                        anomalies.append(anomaly)
            
            return anomalies
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка детекции аномалий нулевых остатков: {e}")
            return []
    
    def _detect_massive_stock_changes(self, source: Optional[str] = None) -> List[Anomaly]:
        """Детекция массовых изменений остатков."""
        anomalies = []
        
        try:
            # Сравниваем остатки сегодня и вчера
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            query = """
                SELECT today.source, today.product_id, today.sku,
                       today.current_stock as today_stock,
                       yesterday.current_stock as yesterday_stock
                FROM inventory_data today
                LEFT JOIN inventory_data yesterday 
                    ON today.product_id = yesterday.product_id 
                    AND today.source = yesterday.source
                    AND yesterday.snapshot_date = %s
                WHERE today.snapshot_date = %s
            """
            
            params = [date.today() - timedelta(days=1), date.today()]
            
            if source:
                query += " AND today.source = %s"
                params.append(source)
            
            if is_sqlite:
                query = query.replace('%s', '?')
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            massive_changes = []
            
            for result in results:
                if isinstance(result, dict):
                    src = result['source']
                    product_id = result['product_id']
                    sku = result['sku']
                    today_stock = result['today_stock'] or 0
                    yesterday_stock = result['yesterday_stock'] or 0
                else:
                    src, product_id, sku, today_stock, yesterday_stock = result
                    today_stock = today_stock or 0
                    yesterday_stock = yesterday_stock or 0
                
                if yesterday_stock > 0:
                    change_ratio = abs(today_stock - yesterday_stock) / yesterday_stock
                    
                    if change_ratio > self.thresholds['massive_change_threshold']:
                        massive_changes.append({
                            'source': src,
                            'product_id': product_id,
                            'sku': sku,
                            'change_ratio': change_ratio,
                            'today_stock': today_stock,
                            'yesterday_stock': yesterday_stock
                        })
            
            if massive_changes:
                # Группируем по источникам
                by_source = {}
                for change in massive_changes:
                    src = change['source']
                    if src not in by_source:
                        by_source[src] = []
                    by_source[src].append(change)
                
                for src, changes in by_source.items():
                    if len(changes) > 5:  # Если много товаров с массовыми изменениями
                        severity = 'critical' if len(changes) > 50 else 'high' if len(changes) > 20 else 'medium'
                        
                        anomaly = Anomaly(
                            type=AnomalyType.MASSIVE_STOCK_CHANGE,
                            severity=severity,
                            source=src,
                            description=f"Массовые изменения остатков у {len(changes)} товаров",
                            affected_records=len(changes),
                            detected_at=datetime.now(),
                            details={
                                'affected_products': len(changes),
                                'threshold': self.thresholds['massive_change_threshold'],
                                'sample_changes': changes[:5]  # Первые 5 для примера
                            }
                        )
                        anomalies.append(anomaly)
            
            return anomalies
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка детекции массовых изменений: {e}")
            return []
    
    def _detect_missing_products(self, source: Optional[str] = None) -> List[Anomaly]:
        """Детекция отсутствующих товаров."""
        anomalies = []
        
        try:
            # Сравниваем количество товаров сегодня и вчера
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            query = """
                SELECT source,
                       COUNT(DISTINCT CASE WHEN snapshot_date = %s THEN product_id END) as today_count,
                       COUNT(DISTINCT CASE WHEN snapshot_date = %s THEN product_id END) as yesterday_count
                FROM inventory_data 
                WHERE snapshot_date IN (%s, %s)
            """
            
            today = date.today()
            yesterday = today - timedelta(days=1)
            params = [today, yesterday, today, yesterday]
            
            if source:
                query += " AND source = %s"
                params.append(source)
            
            query += " GROUP BY source"
            
            if is_sqlite:
                query = query.replace('%s', '?')
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            for result in results:
                if isinstance(result, dict):
                    src = result['source']
                    today_count = result['today_count'] or 0
                    yesterday_count = result['yesterday_count'] or 0
                else:
                    src, today_count, yesterday_count = result
                    today_count = today_count or 0
                    yesterday_count = yesterday_count or 0
                
                if yesterday_count > 0:
                    missing_ratio = (yesterday_count - today_count) / yesterday_count
                    
                    if missing_ratio > 0.1:  # Более 10% товаров исчезло
                        severity = 'critical' if missing_ratio > 0.5 else 'high' if missing_ratio > 0.3 else 'medium'
                        
                        anomaly = Anomaly(
                            type=AnomalyType.MISSING_PRODUCTS,
                            severity=severity,
                            source=src,
                            description=f"Отсутствует {yesterday_count - today_count} товаров ({missing_ratio:.1%})",
                            affected_records=yesterday_count - today_count,
                            detected_at=datetime.now(),
                            details={
                                'today_count': today_count,
                                'yesterday_count': yesterday_count,
                                'missing_count': yesterday_count - today_count,
                                'missing_ratio': missing_ratio
                            }
                        )
                        anomalies.append(anomaly)
            
            return anomalies
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка детекции отсутствующих товаров: {e}")
            return []
    
    def _detect_duplicate_records(self, source: Optional[str] = None) -> List[Anomaly]:
        """Детекция дублирующихся записей."""
        anomalies = []
        
        try:
            # Ищем дубликаты по product_id, source, snapshot_date
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            query = """
                SELECT source, product_id, snapshot_date, COUNT(*) as duplicate_count
                FROM inventory_data 
                WHERE snapshot_date = %s
            """
            
            params = [date.today()]
            
            if source:
                query += " AND source = %s"
                params.append(source)
            
            query += """
                GROUP BY source, product_id, snapshot_date
                HAVING COUNT(*) > 1
            """
            
            if is_sqlite:
                query = query.replace('%s', '?')
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            if results:
                # Группируем по источникам
                by_source = {}
                total_duplicates = 0
                
                for result in results:
                    if isinstance(result, dict):
                        src = result['source']
                        duplicate_count = result['duplicate_count']
                    else:
                        src, product_id, snapshot_date, duplicate_count = result
                    
                    if src not in by_source:
                        by_source[src] = 0
                    by_source[src] += duplicate_count - 1  # Количество лишних записей
                    total_duplicates += duplicate_count - 1
                
                for src, dup_count in by_source.items():
                    if dup_count > 0:
                        severity = 'high' if dup_count > 100 else 'medium' if dup_count > 10 else 'low'
                        
                        anomaly = Anomaly(
                            type=AnomalyType.DUPLICATE_RECORDS,
                            severity=severity,
                            source=src,
                            description=f"Обнаружено {dup_count} дублирующихся записей",
                            affected_records=dup_count,
                            detected_at=datetime.now(),
                            details={
                                'duplicate_count': dup_count,
                                'total_duplicates': total_duplicates
                            }
                        )
                        anomalies.append(anomaly)
            
            return anomalies
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка детекции дубликатов: {e}")
            return []
    
    def _detect_negative_stock(self, source: Optional[str] = None) -> List[Anomaly]:
        """Детекция отрицательных остатков."""
        anomalies = []
        
        try:
            # Ищем записи с отрицательными остатками
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            query = """
                SELECT source, COUNT(*) as negative_count
                FROM inventory_data 
                WHERE snapshot_date = %s
                  AND (current_stock < 0 OR available_stock < 0 OR quantity_present < 0)
            """
            
            params = [date.today()]
            
            if source:
                query += " AND source = %s"
                params.append(source)
            
            query += " GROUP BY source"
            
            if is_sqlite:
                query = query.replace('%s', '?')
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            for result in results:
                if isinstance(result, dict):
                    src = result['source']
                    negative_count = result['negative_count']
                else:
                    src, negative_count = result
                
                if negative_count > 0:
                    severity = 'critical' if negative_count > 50 else 'high' if negative_count > 10 else 'medium'
                    
                    anomaly = Anomaly(
                        type=AnomalyType.NEGATIVE_STOCK,
                        severity=severity,
                        source=src,
                        description=f"Обнаружено {negative_count} записей с отрицательными остатками",
                        affected_records=negative_count,
                        detected_at=datetime.now(),
                        details={
                            'negative_count': negative_count
                        }
                    )
                    anomalies.append(anomaly)
            
            return anomalies
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка детекции отрицательных остатков: {e}")
            return []
    
    def _detect_stale_data(self, source: Optional[str] = None) -> List[Anomaly]:
        """Детекция устаревших данных."""
        anomalies = []
        
        try:
            # Проверяем время последнего обновления данных
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            query = """
                SELECT source, MAX(last_sync_at) as last_update
                FROM inventory_data 
                WHERE 1=1
            """
            
            params = []
            
            if source:
                query += " AND source = %s"
                params.append(source)
            
            query += " GROUP BY source"
            
            if is_sqlite:
                query = query.replace('%s', '?')
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            for result in results:
                if isinstance(result, dict):
                    src = result['source']
                    last_update = result['last_update']
                else:
                    src, last_update = result
                
                if last_update:
                    if isinstance(last_update, str):
                        last_update = datetime.fromisoformat(last_update.replace('Z', '+00:00'))
                    
                    hours_since_update = (datetime.now() - last_update).total_seconds() / 3600
                    
                    if hours_since_update > self.thresholds['data_freshness_hours']:
                        severity = 'critical' if hours_since_update > 24 else 'high' if hours_since_update > 12 else 'medium'
                        
                        anomaly = Anomaly(
                            type=AnomalyType.STALE_DATA,
                            severity=severity,
                            source=src,
                            description=f"Данные не обновлялись {hours_since_update:.1f} часов",
                            affected_records=0,
                            detected_at=datetime.now(),
                            details={
                                'last_update': last_update,
                                'hours_since_update': hours_since_update,
                                'threshold_hours': self.thresholds['data_freshness_hours']
                            }
                        )
                        anomalies.append(anomaly)
            
            return anomalies
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка детекции устаревших данных: {e}")
            return []
    
    def _detect_api_errors(self, source: Optional[str] = None) -> List[Anomaly]:
        """Детекция ошибок API."""
        anomalies = []
        
        try:
            # Проверяем ошибки в логах синхронизации за последние 24 часа
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            if is_sqlite:
                query = """
                    SELECT source, COUNT(*) as error_count,
                           GROUP_CONCAT(DISTINCT error_message) as error_messages
                    FROM sync_logs 
                    WHERE status = 'failed'
                      AND started_at >= datetime('now', '-24 hours')
                      AND sync_type = 'inventory'
                """
            else:
                query = """
                    SELECT source, COUNT(*) as error_count,
                           GROUP_CONCAT(DISTINCT error_message SEPARATOR '; ') as error_messages
                    FROM sync_logs 
                    WHERE status = 'failed'
                      AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      AND sync_type = 'inventory'
                """
            
            params = []
            
            if source:
                query += " AND source = %s"
                params.append(source)
            
            query += " GROUP BY source"
            
            if is_sqlite:
                query = query.replace('%s', '?')
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            for result in results:
                if isinstance(result, dict):
                    src = result['source']
                    error_count = result['error_count']
                    error_messages = result['error_messages']
                else:
                    src, error_count, error_messages = result
                
                if error_count > self.thresholds['max_error_count_24h']:
                    severity = 'critical' if error_count > 50 else 'high' if error_count > 20 else 'medium'
                    
                    anomaly = Anomaly(
                        type=AnomalyType.API_ERRORS,
                        severity=severity,
                        source=src,
                        description=f"Высокое количество ошибок API: {error_count} за 24 часа",
                        affected_records=error_count,
                        detected_at=datetime.now(),
                        details={
                            'error_count_24h': error_count,
                            'threshold': self.thresholds['max_error_count_24h'],
                            'sample_errors': error_messages[:500] if error_messages else None  # Ограничиваем длину
                        }
                    )
                    anomalies.append(anomaly)
            
            return anomalies
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка детекции ошибок API: {e}")
            return []
    
    def _calculate_overall_health(self, sources_metrics: Dict[str, Dict[str, Any]], 
                                anomalies: List[Anomaly]) -> HealthStatus:
        """Расчет общего состояния системы."""
        # Проверяем критические аномалии
        critical_anomalies = [a for a in anomalies if a.severity == 'critical']
        if critical_anomalies:
            return HealthStatus.CRITICAL
        
        # Проверяем состояние источников
        critical_sources = [s for s in sources_metrics.values() 
                          if s.get('health_status') == HealthStatus.CRITICAL]
        if critical_sources:
            return HealthStatus.CRITICAL
        
        # Проверяем предупреждения
        warning_anomalies = [a for a in anomalies if a.severity in ['high', 'medium']]
        warning_sources = [s for s in sources_metrics.values() 
                         if s.get('health_status') == HealthStatus.WARNING]
        
        if warning_anomalies or warning_sources:
            return HealthStatus.WARNING
        
        # Проверяем здоровые источники
        healthy_sources = [s for s in sources_metrics.values() 
                         if s.get('health_status') == HealthStatus.HEALTHY]
        
        if len(healthy_sources) == len(sources_metrics):
            return HealthStatus.HEALTHY
        
        return HealthStatus.UNKNOWN
    
    def _generate_recommendations(self, sources_metrics: Dict[str, Dict[str, Any]], 
                                anomalies: List[Anomaly]) -> List[str]:
        """Генерация рекомендаций по улучшению системы."""
        recommendations = []
        
        # Рекомендации по аномалиям
        for anomaly in anomalies:
            if anomaly.type == AnomalyType.STALE_DATA:
                recommendations.append(f"Проверить работу планировщика синхронизации для {anomaly.source}")
            elif anomaly.type == AnomalyType.API_ERRORS:
                recommendations.append(f"Проверить API ключи и лимиты для {anomaly.source}")
            elif anomaly.type == AnomalyType.ZERO_STOCK_SPIKE:
                recommendations.append(f"Проверить корректность данных об остатках в {anomaly.source}")
            elif anomaly.type == AnomalyType.DUPLICATE_RECORDS:
                recommendations.append(f"Очистить дублирующиеся записи в {anomaly.source}")
            elif anomaly.type == AnomalyType.NEGATIVE_STOCK:
                recommendations.append(f"Исправить записи с отрицательными остатками в {anomaly.source}")
        
        # Рекомендации по метрикам источников
        for source, metrics in sources_metrics.items():
            if metrics.get('success_rate_24h', 0) < 0.8:
                recommendations.append(f"Улучшить стабильность синхронизации для {source}")
            
            if metrics.get('data_freshness_hours', 0) > 6:
                recommendations.append(f"Увеличить частоту синхронизации для {source}")
            
            if metrics.get('error_count_24h', 0) > 10:
                recommendations.append(f"Исследовать причины ошибок синхронизации для {source}")
        
        # Общие рекомендации
        if not recommendations:
            recommendations.append("Система работает стабильно. Продолжайте мониторинг.")
        
        return recommendations[:10]  # Ограничиваем количество рекомендаций
    
    def _calculate_overall_metrics(self, sources_metrics: Dict[str, Dict[str, Any]]) -> Dict[str, Any]:
        """Расчет общих метрик системы."""
        if not sources_metrics:
            return {}
        
        # Собираем метрики
        success_rates = [m.get('success_rate_24h', 0) for m in sources_metrics.values()]
        durations = [m.get('avg_duration_seconds', 0) for m in sources_metrics.values() if m.get('avg_duration_seconds', 0) > 0]
        total_processed = sum(m.get('total_records_processed', 0) for m in sources_metrics.values())
        total_errors = sum(m.get('error_count_24h', 0) for m in sources_metrics.values())
        
        return {
            'avg_success_rate': statistics.mean(success_rates) if success_rates else 0,
            'avg_duration_seconds': statistics.mean(durations) if durations else 0,
            'total_records_processed_24h': total_processed,
            'total_errors_24h': total_errors,
            'active_sources': len(sources_metrics),
            'healthy_sources': len([m for m in sources_metrics.values() 
                                  if m.get('health_status') == HealthStatus.HEALTHY])
        }
    
    def _format_sync_statistics(self, sync_stats) -> Dict[str, Any]:
        """Форматирование статистики синхронизации."""
        formatted = {}
        
        for stat in sync_stats:
            if isinstance(stat, dict):
                source = stat['source']
                status = stat['status']
                count = stat['count']
                avg_duration = stat['avg_duration']
                total_processed = stat['total_processed']
                total_inserted = stat['total_inserted']
                total_failed = stat['total_failed']
                last_sync = stat['last_sync']
            else:
                source, status, count, avg_duration, total_processed, total_inserted, total_failed, last_sync = stat
            
            if source not in formatted:
                formatted[source] = {}
            
            formatted[source][status] = {
                'count': count,
                'avg_duration': avg_duration,
                'total_processed': total_processed,
                'total_inserted': total_inserted,
                'total_failed': total_failed,
                'last_sync': last_sync
            }
        
        return formatted
    
    def _format_inventory_statistics(self, inventory_stats) -> Dict[str, Any]:
        """Форматирование статистики остатков."""
        formatted = {}
        
        for stat in inventory_stats:
            if isinstance(stat, dict):
                source = stat['source']
                total_products = stat['total_products']
                products_with_stock = stat['products_with_stock']
                total_stock = stat['total_stock']
                avg_stock = stat['avg_stock']
                last_update = stat['last_update']
            else:
                source, total_products, products_with_stock, total_stock, avg_stock, last_update = stat
            
            formatted[source] = {
                'total_products': total_products,
                'products_with_stock': products_with_stock,
                'products_without_stock': total_products - products_with_stock,
                'total_stock': total_stock,
                'avg_stock': avg_stock,
                'last_update': last_update
            }
        
        return formatted


# Пример использования
if __name__ == "__main__":
    # Демонстрация использования SyncMonitor
    import mysql.connector
    
    # Подключение к БД (пример)
    try:
        connection = mysql.connector.connect(
            host='localhost',
            database='test_db',
            user='test_user',
            password='test_password'
        )
        cursor = connection.cursor(dictionary=True)
        
        # Создание монитора
        monitor = SyncMonitor(cursor, connection)
        
        # Проверка состояния системы
        health_report = monitor.check_sync_health()
        print(f"Общее состояние системы: {health_report.overall_status.value}")
        print(f"Обнаружено аномалий: {len(health_report.anomalies)}")
        
        # Детекция аномалий для конкретного источника
        ozon_anomalies = monitor.detect_data_anomalies("Ozon")
        print(f"Аномалии Ozon: {len(ozon_anomalies)}")
        
        # Генерация отчета
        sync_report = monitor.generate_sync_report(24)
        print(f"Отчет сгенерирован: {sync_report.get('generated_at')}")
        
    except Exception as e:
        print(f"Ошибка демонстрации: {e}")
    finally:
        if 'cursor' in locals():
            cursor.close()
        if 'connection' in locals():
            connection.close()