#!/usr/bin/env python3
"""
Утилита восстановления данных для системы синхронизации остатков.

Реализует принудительную пересинхронизацию, очистку поврежденных данных
и процедуры восстановления после сбоев.

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import logging
import argparse
from datetime import datetime, date, timedelta
from typing import Dict, Any, List, Optional, Tuple
import json

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from importers.ozon_importer import connect_to_db
    from inventory_error_handler import DataRecoveryManager, FallbackManager
    from inventory_sync_service_with_error_handling import RobustInventorySyncService
    from sync_logger import SyncLogger, SyncType, SyncStatus
except ImportError as e:
    print(f"❌ Ошибка импорта: {e}")
    sys.exit(1)

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class InventoryRecoveryUtility:
    """
    Утилита для восстановления данных системы синхронизации остатков.
    
    Предоставляет команды для:
    - Принудительной пересинхронизации
    - Очистки поврежденных данных
    - Восстановления после сбоев
    - Проверки целостности данных
    - Использования fallback механизмов
    """
    
    def __init__(self):
        """Инициализация утилиты."""
        self.connection = None
        self.cursor = None
        self.recovery_manager: Optional[DataRecoveryManager] = None
        self.fallback_manager: Optional[FallbackManager] = None
        self.sync_logger: Optional[SyncLogger] = None
        self.sync_service: Optional[RobustInventorySyncService] = None
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            
            # Инициализируем компоненты
            self.recovery_manager = DataRecoveryManager(self.cursor, self.connection)
            self.fallback_manager = FallbackManager(self.cursor, self.connection)
            self.sync_logger = SyncLogger(self.cursor, self.connection, "RecoveryUtility")
            self.sync_service = RobustInventorySyncService()
            
            logger.info("✅ Успешное подключение к базе данных")
            
        except Exception as e:
            logger.error(f"❌ Ошибка подключения к БД: {e}")
            raise
    
    def close_database_connection(self):
        """Закрытие подключения к базе данных."""
        if self.cursor:
            self.cursor.close()
        if self.connection:
            self.connection.close()
        logger.info("🔌 Подключение к БД закрыто")

    def force_resync(self, source: str, days_back: int = 7, run_sync: bool = True) -> Dict[str, Any]:
        """
        Принудительная пересинхронизация данных.
        
        Args:
            source: Источник данных ('Ozon', 'Wildberries' или 'all')
            days_back: Количество дней для очистки данных
            run_sync: Запускать ли синхронизацию после очистки
            
        Returns:
            Dict[str, Any]: Результат операции
        """
        logger.info(f"🔄 Запуск принудительной пересинхронизации для {source}")
        
        if not self.recovery_manager:
            return {'status': 'error', 'message': 'Recovery manager не инициализирован'}
        
        results = {}
        
        try:
            sources = ['Ozon', 'Wildberries'] if source.lower() == 'all' else [source]
            
            for src in sources:
                logger.info(f"Обработка источника: {src}")
                
                # Выполняем принудительную пересинхронизацию
                resync_result = self.recovery_manager.force_resync(src, days_back)
                results[src] = {'resync': resync_result}
                
                if resync_result['status'] == 'success' and run_sync:
                    logger.info(f"Запуск синхронизации для {src}")
                    
                    # Подключаем sync_service к БД
                    if self.sync_service:
                        self.sync_service.connection = self.connection
                        self.sync_service.cursor = self.cursor
                        self.sync_service.recovery_manager = self.recovery_manager
                        self.sync_service.fallback_manager = self.fallback_manager
                        self.sync_service.sync_logger = self.sync_logger
                        
                        # Запускаем синхронизацию
                        if src == 'Ozon':
                            sync_result = self.sync_service.sync_ozon_inventory_with_recovery()
                        elif src == 'Wildberries':
                            sync_result = self.sync_service.sync_wb_inventory_with_recovery()
                        else:
                            continue
                        
                        results[src]['sync'] = {
                            'status': sync_result.status.value,
                            'records_processed': sync_result.records_processed,
                            'records_inserted': sync_result.records_inserted,
                            'records_failed': sync_result.records_failed,
                            'duration_seconds': sync_result.duration_seconds,
                            'fallback_used': sync_result.fallback_used,
                            'recovery_actions': sync_result.recovery_actions
                        }
                        
                        logger.info(f"Синхронизация {src} завершена: {sync_result.status.value}")
                
                logger.info(f"Обработка {src} завершена")
            
            return {
                'status': 'success',
                'operation': 'force_resync',
                'sources_processed': sources,
                'days_back': days_back,
                'sync_executed': run_sync,
                'results': results,
                'timestamp': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Ошибка принудительной пересинхронизации: {e}")
            return {
                'status': 'error',
                'operation': 'force_resync',
                'error': str(e),
                'timestamp': datetime.now().isoformat()
            }

    def cleanup_corrupted_data(self, source: str, days_back: int = 7) -> Dict[str, Any]:
        """
        Очистка поврежденных данных.
        
        Args:
            source: Источник данных ('Ozon', 'Wildberries' или 'all')
            days_back: Количество дней для анализа
            
        Returns:
            Dict[str, Any]: Результат очистки
        """
        logger.info(f"🧹 Очистка поврежденных данных для {source}")
        
        if not self.recovery_manager:
            return {'status': 'error', 'message': 'Recovery manager не инициализирован'}
        
        try:
            sources = ['Ozon', 'Wildberries'] if source.lower() == 'all' else [source]
            results = {}
            
            for src in sources:
                logger.info(f"Очистка данных для {src}")
                cleanup_result = self.recovery_manager.cleanup_corrupted_data(src, days_back)
                results[src] = cleanup_result
                
                if cleanup_result['status'] == 'success':
                    logger.info(f"Очистка {src} завершена: удалено {cleanup_result['total_deleted']} записей")
                else:
                    logger.error(f"Ошибка очистки {src}: {cleanup_result.get('error', 'Unknown error')}")
            
            return {
                'status': 'success',
                'operation': 'cleanup_corrupted_data',
                'sources_processed': sources,
                'days_back': days_back,
                'results': results,
                'timestamp': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Ошибка очистки данных: {e}")
            return {
                'status': 'error',
                'operation': 'cleanup_corrupted_data',
                'error': str(e),
                'timestamp': datetime.now().isoformat()
            }

    def recover_from_failure(self, source: str, sync_session_id: Optional[int] = None) -> Dict[str, Any]:
        """
        Восстановление после сбоя синхронизации.
        
        Args:
            source: Источник данных ('Ozon', 'Wildberries' или 'all')
            sync_session_id: ID конкретной сессии синхронизации
            
        Returns:
            Dict[str, Any]: Результат восстановления
        """
        logger.info(f"🔧 Восстановление после сбоя для {source}")
        
        if not self.recovery_manager:
            return {'status': 'error', 'message': 'Recovery manager не инициализирован'}
        
        try:
            sources = ['Ozon', 'Wildberries'] if source.lower() == 'all' else [source]
            results = {}
            
            for src in sources:
                logger.info(f"Восстановление для {src}")
                recovery_result = self.recovery_manager.recover_from_failure(src, sync_session_id)
                results[src] = recovery_result
                
                if recovery_result['status'] == 'success':
                    logger.info(f"Восстановление {src} завершено: {recovery_result['message']}")
                else:
                    logger.error(f"Ошибка восстановления {src}: {recovery_result.get('error', 'Unknown error')}")
            
            return {
                'status': 'success',
                'operation': 'recover_from_failure',
                'sources_processed': sources,
                'sync_session_id': sync_session_id,
                'results': results,
                'timestamp': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Ошибка восстановления: {e}")
            return {
                'status': 'error',
                'operation': 'recover_from_failure',
                'error': str(e),
                'timestamp': datetime.now().isoformat()
            }

    def validate_data_integrity(self, source: str) -> Dict[str, Any]:
        """
        Проверка целостности данных.
        
        Args:
            source: Источник данных ('Ozon', 'Wildberries' или 'all')
            
        Returns:
            Dict[str, Any]: Результат проверки
        """
        logger.info(f"🔍 Проверка целостности данных для {source}")
        
        if not self.recovery_manager:
            return {'status': 'error', 'message': 'Recovery manager не инициализирован'}
        
        try:
            sources = ['Ozon', 'Wildberries'] if source.lower() == 'all' else [source]
            results = {}
            overall_score = 0
            
            for src in sources:
                logger.info(f"Проверка целостности для {src}")
                integrity_result = self.recovery_manager.validate_data_integrity(src)
                results[src] = integrity_result
                
                if integrity_result['status'] == 'success':
                    score = integrity_result['integrity_score']
                    overall_score += score
                    logger.info(f"Целостность {src}: {score}% ({integrity_result['total_issues']} проблем)")
                else:
                    logger.error(f"Ошибка проверки {src}: {integrity_result.get('error', 'Unknown error')}")
            
            # Вычисляем общий балл
            if len([r for r in results.values() if r['status'] == 'success']) > 0:
                overall_score = overall_score / len([r for r in results.values() if r['status'] == 'success'])
            
            return {
                'status': 'success',
                'operation': 'validate_data_integrity',
                'sources_processed': sources,
                'overall_integrity_score': round(overall_score, 2),
                'results': results,
                'timestamp': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Ошибка проверки целостности: {e}")
            return {
                'status': 'error',
                'operation': 'validate_data_integrity',
                'error': str(e),
                'timestamp': datetime.now().isoformat()
            }

    def use_fallback_data(self, source: str, max_age_hours: int = 24) -> Dict[str, Any]:
        """
        Использование fallback данных.
        
        Args:
            source: Источник данных ('Ozon', 'Wildberries' или 'all')
            max_age_hours: Максимальный возраст кэшированных данных
            
        Returns:
            Dict[str, Any]: Результат использования fallback
        """
        logger.info(f"💾 Использование fallback данных для {source}")
        
        if not self.fallback_manager:
            return {'status': 'error', 'message': 'Fallback manager не инициализирован'}
        
        try:
            sources = ['Ozon', 'Wildberries'] if source.lower() == 'all' else [source]
            results = {}
            
            for src in sources:
                logger.info(f"Fallback для {src}")
                fallback_result = self.fallback_manager.use_cached_data(src, max_age_hours)
                results[src] = fallback_result
                
                if fallback_result['status'] == 'success':
                    logger.info(f"Fallback {src} завершен: {fallback_result['copied_records']} записей")
                elif fallback_result['status'] == 'no_cache':
                    logger.warning(f"Нет кэшированных данных для {src}")
                else:
                    logger.error(f"Ошибка fallback {src}: {fallback_result.get('error', 'Unknown error')}")
            
            return {
                'status': 'success',
                'operation': 'use_fallback_data',
                'sources_processed': sources,
                'max_age_hours': max_age_hours,
                'results': results,
                'timestamp': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Ошибка использования fallback: {e}")
            return {
                'status': 'error',
                'operation': 'use_fallback_data',
                'error': str(e),
                'timestamp': datetime.now().isoformat()
            }

    def estimate_inventory_from_history(self, source: str, days_back: int = 7) -> Dict[str, Any]:
        """
        Оценка остатков на основе исторических данных.
        
        Args:
            source: Источник данных ('Ozon', 'Wildberries' или 'all')
            days_back: Количество дней для анализа истории
            
        Returns:
            Dict[str, Any]: Результат оценки
        """
        logger.info(f"📊 Оценка остатков из истории для {source}")
        
        if not self.fallback_manager:
            return {'status': 'error', 'message': 'Fallback manager не инициализирован'}
        
        try:
            sources = ['Ozon', 'Wildberries'] if source.lower() == 'all' else [source]
            results = {}
            
            for src in sources:
                logger.info(f"Оценка для {src}")
                estimation_result = self.fallback_manager.estimate_inventory_from_history(src, days_back)
                results[src] = estimation_result
                
                if estimation_result['status'] == 'success':
                    logger.info(f"Оценка {src} завершена: {estimation_result['estimated_records']} записей")
                elif estimation_result['status'] == 'no_data':
                    logger.warning(f"Недостаточно данных для оценки {src}")
                else:
                    logger.error(f"Ошибка оценки {src}: {estimation_result.get('error', 'Unknown error')}")
            
            return {
                'status': 'success',
                'operation': 'estimate_inventory_from_history',
                'sources_processed': sources,
                'days_back': days_back,
                'results': results,
                'timestamp': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Ошибка оценки остатков: {e}")
            return {
                'status': 'error',
                'operation': 'estimate_inventory_from_history',
                'error': str(e),
                'timestamp': datetime.now().isoformat()
            }

    def get_sync_history(self, source: Optional[str] = None, days: int = 7) -> Dict[str, Any]:
        """
        Получение истории синхронизации.
        
        Args:
            source: Источник данных (опционально)
            days: Количество дней для анализа
            
        Returns:
            Dict[str, Any]: История синхронизации
        """
        logger.info(f"📈 Получение истории синхронизации")
        
        try:
            # Базовый запрос
            query = """
                SELECT 
                    id, sync_type, source, status, 
                    records_processed, records_updated, records_inserted, records_failed,
                    started_at, completed_at, 
                    TIMESTAMPDIFF(SECOND, started_at, completed_at) as duration_seconds,
                    api_requests_count, error_message
                FROM sync_logs 
                WHERE sync_type = 'inventory'
                AND started_at >= DATE_SUB(NOW(), INTERVAL %s DAY)
            """
            
            params = [days]
            
            if source and source.lower() != 'all':
                query += " AND source = %s"
                params.append(source)
            
            query += " ORDER BY started_at DESC"
            
            self.cursor.execute(query, params)
            sync_history = self.cursor.fetchall()
            
            # Группируем по источникам
            history_by_source = {}
            total_syncs = 0
            successful_syncs = 0
            
            for sync in sync_history:
                src = sync['source']
                if src not in history_by_source:
                    history_by_source[src] = {
                        'syncs': [],
                        'total_count': 0,
                        'success_count': 0,
                        'partial_count': 0,
                        'failed_count': 0,
                        'total_records_processed': 0,
                        'total_records_inserted': 0,
                        'avg_duration': 0
                    }
                
                # Конвертируем datetime в строки для JSON сериализации
                sync_data = dict(sync)
                if sync_data['started_at']:
                    sync_data['started_at'] = sync_data['started_at'].isoformat()
                if sync_data['completed_at']:
                    sync_data['completed_at'] = sync_data['completed_at'].isoformat()
                
                history_by_source[src]['syncs'].append(sync_data)
                history_by_source[src]['total_count'] += 1
                history_by_source[src]['total_records_processed'] += sync['records_processed'] or 0
                history_by_source[src]['total_records_inserted'] += sync['records_inserted'] or 0
                
                if sync['status'] == 'success':
                    history_by_source[src]['success_count'] += 1
                    successful_syncs += 1
                elif sync['status'] == 'partial':
                    history_by_source[src]['partial_count'] += 1
                elif sync['status'] == 'failed':
                    history_by_source[src]['failed_count'] += 1
                
                total_syncs += 1
            
            # Вычисляем средние значения
            for src_data in history_by_source.values():
                if src_data['total_count'] > 0:
                    durations = [s['duration_seconds'] for s in src_data['syncs'] if s['duration_seconds']]
                    src_data['avg_duration'] = sum(durations) / len(durations) if durations else 0
                    src_data['success_rate'] = (src_data['success_count'] / src_data['total_count']) * 100
            
            return {
                'status': 'success',
                'operation': 'get_sync_history',
                'days_analyzed': days,
                'source_filter': source,
                'total_syncs': total_syncs,
                'successful_syncs': successful_syncs,
                'overall_success_rate': (successful_syncs / total_syncs * 100) if total_syncs > 0 else 0,
                'history_by_source': history_by_source,
                'timestamp': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Ошибка получения истории синхронизации: {e}")
            return {
                'status': 'error',
                'operation': 'get_sync_history',
                'error': str(e),
                'timestamp': datetime.now().isoformat()
            }

    def get_current_inventory_status(self, source: Optional[str] = None) -> Dict[str, Any]:
        """
        Получение текущего статуса остатков.
        
        Args:
            source: Источник данных (опционально)
            
        Returns:
            Dict[str, Any]: Текущий статус остатков
        """
        logger.info(f"📋 Получение текущего статуса остатков")
        
        try:
            # Базовый запрос для статистики
            query = """
                SELECT 
                    source,
                    COUNT(*) as total_records,
                    COUNT(DISTINCT product_id) as unique_products,
                    SUM(quantity_present) as total_present,
                    SUM(quantity_reserved) as total_reserved,
                    SUM(available_stock) as total_available,
                    MAX(last_sync_at) as last_sync,
                    MAX(snapshot_date) as last_snapshot_date,
                    COUNT(DISTINCT warehouse_name) as warehouses_count,
                    COUNT(DISTINCT stock_type) as stock_types_count
                FROM inventory_data 
                WHERE snapshot_date = CURDATE()
            """
            
            params = []
            
            if source and source.lower() != 'all':
                query += " AND source = %s"
                params.append(source)
            
            query += " GROUP BY source ORDER BY source"
            
            self.cursor.execute(query, params)
            current_stats = self.cursor.fetchall()
            
            # Получаем детальную информацию по складам
            warehouse_query = """
                SELECT 
                    source, warehouse_name, stock_type,
                    COUNT(*) as records,
                    COUNT(DISTINCT product_id) as products,
                    SUM(quantity_present) as present,
                    SUM(quantity_reserved) as reserved,
                    SUM(available_stock) as available
                FROM inventory_data 
                WHERE snapshot_date = CURDATE()
            """
            
            if source and source.lower() != 'all':
                warehouse_query += " AND source = %s"
            
            warehouse_query += " GROUP BY source, warehouse_name, stock_type ORDER BY source, warehouse_name, stock_type"
            
            self.cursor.execute(warehouse_query, params)
            warehouse_stats = self.cursor.fetchall()
            
            # Группируем данные по складам
            warehouses_by_source = {}
            for warehouse in warehouse_stats:
                src = warehouse['source']
                if src not in warehouses_by_source:
                    warehouses_by_source[src] = []
                
                warehouses_by_source[src].append({
                    'warehouse_name': warehouse['warehouse_name'],
                    'stock_type': warehouse['stock_type'],
                    'records': warehouse['records'],
                    'products': warehouse['products'],
                    'present': warehouse['present'],
                    'reserved': warehouse['reserved'],
                    'available': warehouse['available']
                })
            
            # Форматируем результат
            status_by_source = {}
            total_records = 0
            total_products = set()
            
            for stat in current_stats:
                src = stat['source']
                
                # Конвертируем datetime в строки
                last_sync = stat['last_sync'].isoformat() if stat['last_sync'] else None
                last_snapshot = stat['last_snapshot_date'].isoformat() if stat['last_snapshot_date'] else None
                
                # Вычисляем возраст данных
                data_age_hours = None
                if stat['last_sync']:
                    data_age_hours = (datetime.now() - stat['last_sync']).total_seconds() / 3600
                
                status_by_source[src] = {
                    'total_records': stat['total_records'],
                    'unique_products': stat['unique_products'],
                    'total_present': stat['total_present'],
                    'total_reserved': stat['total_reserved'],
                    'total_available': stat['total_available'],
                    'last_sync': last_sync,
                    'last_snapshot_date': last_snapshot,
                    'data_age_hours': round(data_age_hours, 1) if data_age_hours else None,
                    'is_fresh': data_age_hours < 6 if data_age_hours else False,
                    'warehouses_count': stat['warehouses_count'],
                    'stock_types_count': stat['stock_types_count'],
                    'warehouses': warehouses_by_source.get(src, [])
                }
                
                total_records += stat['total_records']
                # Примерное количество уникальных товаров (может быть неточным из-за пересечений)
                total_products.add(f"{src}_{stat['unique_products']}")
            
            return {
                'status': 'success',
                'operation': 'get_current_inventory_status',
                'source_filter': source,
                'total_records_all_sources': total_records,
                'check_date': date.today().isoformat(),
                'status_by_source': status_by_source,
                'timestamp': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Ошибка получения статуса остатков: {e}")
            return {
                'status': 'error',
                'operation': 'get_current_inventory_status',
                'error': str(e),
                'timestamp': datetime.now().isoformat()
            }

    def run_health_check(self) -> Dict[str, Any]:
        """
        Комплексная проверка здоровья системы.
        
        Returns:
            Dict[str, Any]: Результат проверки здоровья
        """
        logger.info("🏥 Запуск комплексной проверки здоровья системы")
        
        health_results = {
            'status': 'success',
            'operation': 'health_check',
            'timestamp': datetime.now().isoformat(),
            'checks': {}
        }
        
        try:
            # 1. Проверка целостности данных
            logger.info("Проверка целостности данных...")
            integrity_result = self.validate_data_integrity('all')
            health_results['checks']['data_integrity'] = integrity_result
            
            # 2. Проверка свежести данных
            logger.info("Проверка свежести данных...")
            status_result = self.get_current_inventory_status('all')
            health_results['checks']['data_freshness'] = status_result
            
            # 3. Проверка истории синхронизации
            logger.info("Проверка истории синхронизации...")
            history_result = self.get_sync_history('all', 3)  # Последние 3 дня
            health_results['checks']['sync_history'] = history_result
            
            # 4. Анализ общего состояния
            logger.info("Анализ общего состояния...")
            
            # Проверяем критические проблемы
            critical_issues = []
            warnings = []
            
            # Анализируем целостность данных
            if integrity_result['status'] == 'success':
                overall_integrity = integrity_result.get('overall_integrity_score', 0)
                if overall_integrity < 50:
                    critical_issues.append(f"Низкая целостность данных: {overall_integrity}%")
                elif overall_integrity < 80:
                    warnings.append(f"Умеренная целостность данных: {overall_integrity}%")
            
            # Анализируем свежесть данных
            if status_result['status'] == 'success':
                for source, status in status_result.get('status_by_source', {}).items():
                    if not status.get('is_fresh', False):
                        age = status.get('data_age_hours', 0)
                        if age > 24:
                            critical_issues.append(f"Данные {source} устарели на {age:.1f} часов")
                        elif age > 6:
                            warnings.append(f"Данные {source} устарели на {age:.1f} часов")
            
            # Анализируем историю синхронизации
            if history_result['status'] == 'success':
                success_rate = history_result.get('overall_success_rate', 0)
                if success_rate < 50:
                    critical_issues.append(f"Низкий процент успешных синхронизаций: {success_rate:.1f}%")
                elif success_rate < 80:
                    warnings.append(f"Умеренный процент успешных синхронизаций: {success_rate:.1f}%")
            
            # Определяем общий статус здоровья
            if critical_issues:
                health_status = 'critical'
            elif warnings:
                health_status = 'warning'
            else:
                health_status = 'healthy'
            
            health_results['overall_health'] = {
                'status': health_status,
                'critical_issues': critical_issues,
                'warnings': warnings,
                'recommendations': self._generate_recommendations(critical_issues, warnings)
            }
            
            logger.info(f"Проверка здоровья завершена: статус {health_status}")
            
            return health_results
            
        except Exception as e:
            logger.error(f"Ошибка проверки здоровья: {e}")
            health_results['status'] = 'error'
            health_results['error'] = str(e)
            return health_results

    def _generate_recommendations(self, critical_issues: List[str], warnings: List[str]) -> List[str]:
        """
        Генерация рекомендаций на основе найденных проблем.
        
        Args:
            critical_issues: Критические проблемы
            warnings: Предупреждения
            
        Returns:
            List[str]: Список рекомендаций
        """
        recommendations = []
        
        if critical_issues:
            recommendations.append("🚨 Требуется немедленное вмешательство:")
            for issue in critical_issues:
                if "целостность данных" in issue.lower():
                    recommendations.append("  - Выполните очистку поврежденных данных: cleanup-corrupted-data")
                elif "устарели" in issue.lower():
                    recommendations.append("  - Запустите принудительную пересинхронизацию: force-resync")
                elif "синхронизаций" in issue.lower():
                    recommendations.append("  - Проверьте настройки API и сетевое подключение")
        
        if warnings:
            recommendations.append("⚠️ Рекомендуемые действия:")
            for warning in warnings:
                if "целостность данных" in warning.lower():
                    recommendations.append("  - Рассмотрите возможность очистки данных")
                elif "устарели" in warning.lower():
                    recommendations.append("  - Проверьте работу автоматической синхронизации")
                elif "синхронизаций" in warning.lower():
                    recommendations.append("  - Мониторьте логи синхронизации")
        
        if not critical_issues and not warnings:
            recommendations.append("✅ Система работает нормально")
            recommendations.append("  - Продолжайте регулярный мониторинг")
        
        return recommendations


def main():
    """Главная функция для запуска утилиты из командной строки."""
    parser = argparse.ArgumentParser(description='Утилита восстановления данных системы синхронизации остатков')
    
    subparsers = parser.add_subparsers(dest='command', help='Доступные команды')
    
    # Команда force-resync
    resync_parser = subparsers.add_parser('force-resync', help='Принудительная пересинхронизация')
    resync_parser.add_argument('--source', choices=['Ozon', 'Wildberries', 'all'], default='all',
                              help='Источник данных для пересинхронизации')
    resync_parser.add_argument('--days-back', type=int, default=7,
                              help='Количество дней для очистки данных')
    resync_parser.add_argument('--no-sync', action='store_true',
                              help='Только очистка без запуска синхронизации')
    
    # Команда cleanup-corrupted-data
    cleanup_parser = subparsers.add_parser('cleanup-corrupted-data', help='Очистка поврежденных данных')
    cleanup_parser.add_argument('--source', choices=['Ozon', 'Wildberries', 'all'], default='all',
                               help='Источник данных для очистки')
    cleanup_parser.add_argument('--days-back', type=int, default=7,
                               help='Количество дней для анализа')
    
    # Команда recover-from-failure
    recover_parser = subparsers.add_parser('recover-from-failure', help='Восстановление после сбоя')
    recover_parser.add_argument('--source', choices=['Ozon', 'Wildberries', 'all'], default='all',
                               help='Источник данных для восстановления')
    recover_parser.add_argument('--session-id', type=int,
                               help='ID конкретной сессии синхронизации')
    
    # Команда validate-integrity
    validate_parser = subparsers.add_parser('validate-integrity', help='Проверка целостности данных')
    validate_parser.add_argument('--source', choices=['Ozon', 'Wildberries', 'all'], default='all',
                                help='Источник данных для проверки')
    
    # Команда use-fallback
    fallback_parser = subparsers.add_parser('use-fallback', help='Использование fallback данных')
    fallback_parser.add_argument('--source', choices=['Ozon', 'Wildberries', 'all'], default='all',
                                help='Источник данных')
    fallback_parser.add_argument('--max-age-hours', type=int, default=24,
                                help='Максимальный возраст кэшированных данных в часах')
    
    # Команда estimate-inventory
    estimate_parser = subparsers.add_parser('estimate-inventory', help='Оценка остатков из истории')
    estimate_parser.add_argument('--source', choices=['Ozon', 'Wildberries', 'all'], default='all',
                                help='Источник данных')
    estimate_parser.add_argument('--days-back', type=int, default=7,
                                help='Количество дней для анализа истории')
    
    # Команда sync-history
    history_parser = subparsers.add_parser('sync-history', help='История синхронизации')
    history_parser.add_argument('--source', choices=['Ozon', 'Wildberries', 'all'], default='all',
                               help='Источник данных')
    history_parser.add_argument('--days', type=int, default=7,
                               help='Количество дней для анализа')
    
    # Команда inventory-status
    status_parser = subparsers.add_parser('inventory-status', help='Текущий статус остатков')
    status_parser.add_argument('--source', choices=['Ozon', 'Wildberries', 'all'], default='all',
                              help='Источник данных')
    
    # Команда health-check
    subparsers.add_parser('health-check', help='Комплексная проверка здоровья системы')
    
    # Общие параметры
    parser.add_argument('--output', choices=['json', 'text'], default='text',
                       help='Формат вывода результатов')
    parser.add_argument('--verbose', '-v', action='store_true',
                       help='Подробный вывод')
    
    args = parser.parse_args()
    
    if not args.command:
        parser.print_help()
        return
    
    # Настройка уровня логирования
    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)
    
    # Создаем утилиту и подключаемся к БД
    utility = InventoryRecoveryUtility()
    
    try:
        utility.connect_to_database()
        
        # Выполняем команду
        result = None
        
        if args.command == 'force-resync':
            result = utility.force_resync(args.source, args.days_back, not args.no_sync)
        elif args.command == 'cleanup-corrupted-data':
            result = utility.cleanup_corrupted_data(args.source, args.days_back)
        elif args.command == 'recover-from-failure':
            result = utility.recover_from_failure(args.source, args.session_id)
        elif args.command == 'validate-integrity':
            result = utility.validate_data_integrity(args.source)
        elif args.command == 'use-fallback':
            result = utility.use_fallback_data(args.source, args.max_age_hours)
        elif args.command == 'estimate-inventory':
            result = utility.estimate_inventory_from_history(args.source, args.days_back)
        elif args.command == 'sync-history':
            result = utility.get_sync_history(args.source, args.days)
        elif args.command == 'inventory-status':
            result = utility.get_current_inventory_status(args.source)
        elif args.command == 'health-check':
            result = utility.run_health_check()
        
        # Выводим результат
        if args.output == 'json':
            print(json.dumps(result, indent=2, ensure_ascii=False))
        else:
            # Текстовый вывод
            print(f"\n{'='*60}")
            print(f"РЕЗУЛЬТАТ: {args.command.upper()}")
            print(f"{'='*60}")
            print(f"Статус: {result.get('status', 'unknown')}")
            
            if result.get('status') == 'success':
                print("✅ Операция выполнена успешно")
            else:
                print("❌ Операция завершилась с ошибкой")
                if 'error' in result:
                    print(f"Ошибка: {result['error']}")
            
            # Специфичный вывод для разных команд
            if args.command == 'health-check' and 'overall_health' in result:
                health = result['overall_health']
                print(f"\nОбщее состояние: {health['status'].upper()}")
                
                if health['critical_issues']:
                    print("\n🚨 Критические проблемы:")
                    for issue in health['critical_issues']:
                        print(f"  - {issue}")
                
                if health['warnings']:
                    print("\n⚠️ Предупреждения:")
                    for warning in health['warnings']:
                        print(f"  - {warning}")
                
                if health['recommendations']:
                    print("\n💡 Рекомендации:")
                    for rec in health['recommendations']:
                        print(f"  {rec}")
        
    except Exception as e:
        logger.error(f"Критическая ошибка: {e}")
        if args.output == 'json':
            print(json.dumps({'status': 'error', 'error': str(e)}, indent=2))
        else:
            print(f"❌ Критическая ошибка: {e}")
        sys.exit(1)
    
    finally:
        utility.close_database_connection()


if __name__ == "__main__":
    main()