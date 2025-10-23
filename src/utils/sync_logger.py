#!/usr/bin/env python3
"""
Система логирования синхронизации остатков товаров.

Класс SyncLogger для записи результатов синхронизации в sync_logs,
логирования времени выполнения, количества обработанных записей,
ошибок и предупреждений.

Автор: ETL System
Дата: 06 января 2025
"""

import logging
from datetime import datetime
from typing import Optional, Dict, Any, List
from dataclasses import dataclass
from enum import Enum


class LogLevel(Enum):
    """Уровни логирования."""
    DEBUG = "debug"
    INFO = "info"
    WARNING = "warning"
    ERROR = "error"
    CRITICAL = "critical"


class SyncType(Enum):
    """Типы синхронизации."""
    INVENTORY = "inventory"
    ORDERS = "orders"
    TRANSACTIONS = "transactions"


class SyncStatus(Enum):
    """Статусы синхронизации."""
    SUCCESS = "success"
    PARTIAL = "partial"
    FAILED = "failed"


@dataclass
class SyncLogEntry:
    """Запись лога синхронизации."""
    sync_type: SyncType
    source: str
    status: SyncStatus
    records_processed: int = 0
    records_updated: int = 0
    records_inserted: int = 0
    records_failed: int = 0
    started_at: Optional[datetime] = None
    completed_at: Optional[datetime] = None
    api_requests_count: int = 0
    error_message: Optional[str] = None
    warning_message: Optional[str] = None
    
    @property
    def duration_seconds(self) -> int:
        """Длительность выполнения в секундах."""
        if self.started_at and self.completed_at:
            return int((self.completed_at - self.started_at).total_seconds())
        return 0


@dataclass
class ProcessingStats:
    """Статистика обработки данных."""
    stage_name: str
    records_input: int
    records_output: int
    records_skipped: int
    processing_time_seconds: float
    memory_usage_mb: Optional[float] = None
    error_count: int = 0
    warning_count: int = 0


class SyncLogger:
    """
    Класс для логирования результатов синхронизации.
    
    Обеспечивает:
    - Запись результатов синхронизации в таблицу sync_logs
    - Логирование времени выполнения и количества обработанных записей
    - Запись ошибок и предупреждений
    - Детальное логирование каждого этапа синхронизации
    """
    
    def __init__(self, db_cursor, db_connection, logger_name: str = "SyncLogger"):
        """
        Инициализация логгера синхронизации.
        
        Args:
            db_cursor: Курсор базы данных
            db_connection: Соединение с базой данных
            logger_name: Имя логгера
        """
        self.cursor = db_cursor
        self.connection = db_connection
        self.logger = logging.getLogger(logger_name)
        
        # Текущая сессия синхронизации
        self.current_sync: Optional[SyncLogEntry] = None
        self.processing_stages: List[ProcessingStats] = []
        
        # Счетчики для текущей сессии
        self.session_warnings: List[str] = []
        self.session_errors: List[str] = []
        
    def start_sync_session(self, sync_type: SyncType, source: str) -> SyncLogEntry:
        """
        Начало новой сессии синхронизации.
        
        Args:
            sync_type: Тип синхронизации
            source: Источник данных
            
        Returns:
            SyncLogEntry: Запись о начале синхронизации
        """
        self.current_sync = SyncLogEntry(
            sync_type=sync_type,
            source=source,
            status=SyncStatus.SUCCESS,  # Начинаем с успешного статуса
            started_at=datetime.now()
        )
        
        # Очищаем счетчики предыдущей сессии
        self.processing_stages.clear()
        self.session_warnings.clear()
        self.session_errors.clear()
        
        self.logger.info(f"🚀 Начата синхронизация {sync_type.value} для источника {source}")
        return self.current_sync
    
    def end_sync_session(self, status: Optional[SyncStatus] = None, 
                        error_message: Optional[str] = None) -> Optional[int]:
        """
        Завершение текущей сессии синхронизации.
        
        Args:
            status: Финальный статус синхронизации (если не указан, используется текущий)
            error_message: Сообщение об ошибке
            
        Returns:
            int: ID записи в sync_logs или None при ошибке
        """
        if not self.current_sync:
            self.logger.error("❌ Нет активной сессии синхронизации для завершения")
            return None
        
        # Устанавливаем финальные параметры
        self.current_sync.completed_at = datetime.now()
        
        if status:
            self.current_sync.status = status
        
        if error_message:
            self.current_sync.error_message = error_message
            if self.current_sync.status == SyncStatus.SUCCESS:
                self.current_sync.status = SyncStatus.FAILED
        
        # Объединяем предупреждения в одно сообщение
        if self.session_warnings:
            warning_text = "; ".join(self.session_warnings[:5])  # Ограничиваем количество
            if len(self.session_warnings) > 5:
                warning_text += f" и еще {len(self.session_warnings) - 5} предупреждений"
            self.current_sync.warning_message = warning_text
        
        # Объединяем ошибки в одно сообщение
        if self.session_errors:
            error_text = "; ".join(self.session_errors[:3])  # Ограничиваем количество
            if len(self.session_errors) > 3:
                error_text += f" и еще {len(self.session_errors) - 3} ошибок"
            # Если уже есть error_message из параметра, объединяем
            if self.current_sync.error_message:
                self.current_sync.error_message = f"{self.current_sync.error_message}; {error_text}"
            else:
                self.current_sync.error_message = error_text
        
        # Записываем в базу данных
        sync_log_id = self._write_sync_log_to_db(self.current_sync)
        
        # Записываем статистику этапов
        if self.processing_stages:
            self._write_processing_stages_to_db(sync_log_id)
        
        # Логируем завершение
        duration = self.current_sync.duration_seconds
        self.logger.info(
            f"✅ Синхронизация {self.current_sync.sync_type.value} завершена: "
            f"статус={self.current_sync.status.value}, "
            f"обработано={self.current_sync.records_processed}, "
            f"обновлено={self.current_sync.records_updated}, "
            f"вставлено={self.current_sync.records_inserted}, "
            f"ошибок={self.current_sync.records_failed}, "
            f"длительность={duration}с"
        )
        
        # Очищаем текущую сессию
        self.current_sync = None
        
        return sync_log_id
    
    def log_processing_stage(self, stage_name: str, records_input: int, 
                           records_output: int, processing_time: float,
                           records_skipped: int = 0, error_count: int = 0,
                           warning_count: int = 0, memory_usage_mb: Optional[float] = None):
        """
        Логирование этапа обработки данных.
        
        Args:
            stage_name: Название этапа
            records_input: Количество входных записей
            records_output: Количество выходных записей
            processing_time: Время обработки в секундах
            records_skipped: Количество пропущенных записей
            error_count: Количество ошибок
            warning_count: Количество предупреждений
            memory_usage_mb: Использование памяти в МБ
        """
        stage_stats = ProcessingStats(
            stage_name=stage_name,
            records_input=records_input,
            records_output=records_output,
            records_skipped=records_skipped,
            processing_time_seconds=processing_time,
            memory_usage_mb=memory_usage_mb,
            error_count=error_count,
            warning_count=warning_count
        )
        
        self.processing_stages.append(stage_stats)
        
        # Обновляем счетчики текущей синхронизации
        if self.current_sync:
            self.current_sync.records_processed += records_input
            if records_output > records_input:  # Новые записи
                self.current_sync.records_inserted += (records_output - records_input)
            else:  # Обновленные записи
                self.current_sync.records_updated += records_output
            
            self.current_sync.records_failed += error_count
        
        self.logger.info(
            f"📊 Этап '{stage_name}': вход={records_input}, выход={records_output}, "
            f"пропущено={records_skipped}, время={processing_time:.2f}с, "
            f"ошибок={error_count}, предупреждений={warning_count}"
        )
    
    def log_api_request(self, endpoint: str, response_time: float, 
                       status_code: int, records_received: int = 0,
                       error_message: Optional[str] = None):
        """
        Логирование API запроса.
        
        Args:
            endpoint: URL endpoint
            response_time: Время ответа в секундах
            status_code: HTTP статус код
            records_received: Количество полученных записей
            error_message: Сообщение об ошибке
        """
        if self.current_sync:
            self.current_sync.api_requests_count += 1
        
        if status_code >= 400:
            self.logger.error(
                f"❌ API запрос неудачен: {endpoint}, статус={status_code}, "
                f"время={response_time:.2f}с, ошибка={error_message}"
            )
            if error_message:
                self.log_error(f"API Error {status_code}: {error_message}")
        else:
            self.logger.info(
                f"✅ API запрос успешен: {endpoint}, статус={status_code}, "
                f"время={response_time:.2f}с, записей={records_received}"
            )
    
    def log_error(self, message: str, exception: Optional[Exception] = None):
        """
        Логирование ошибки.
        
        Args:
            message: Сообщение об ошибке
            exception: Объект исключения (опционально)
        """
        if exception:
            full_message = f"{message}: {str(exception)}"
        else:
            full_message = message
        
        self.session_errors.append(full_message)
        self.logger.error(f"❌ {full_message}")
        
        # Обновляем статус текущей синхронизации
        if self.current_sync and self.current_sync.status == SyncStatus.SUCCESS:
            self.current_sync.status = SyncStatus.PARTIAL
    
    def log_warning(self, message: str):
        """
        Логирование предупреждения.
        
        Args:
            message: Сообщение предупреждения
        """
        self.session_warnings.append(message)
        self.logger.warning(f"⚠️ {message}")
    
    def log_info(self, message: str):
        """
        Логирование информационного сообщения.
        
        Args:
            message: Информационное сообщение
        """
        self.logger.info(f"ℹ️ {message}")
    
    def update_sync_counters(self, records_processed: int = 0, records_updated: int = 0,
                           records_inserted: int = 0, records_failed: int = 0):
        """
        Обновление счетчиков текущей синхронизации.
        
        Args:
            records_processed: Количество обработанных записей
            records_updated: Количество обновленных записей
            records_inserted: Количество вставленных записей
            records_failed: Количество неудачных записей
        """
        if not self.current_sync:
            self.logger.warning("⚠️ Нет активной сессии для обновления счетчиков")
            return
        
        self.current_sync.records_processed += records_processed
        self.current_sync.records_updated += records_updated
        self.current_sync.records_inserted += records_inserted
        self.current_sync.records_failed += records_failed
        
        # Если есть ошибки, меняем статус
        if records_failed > 0 and self.current_sync.status == SyncStatus.SUCCESS:
            self.current_sync.status = SyncStatus.PARTIAL
    
    def get_sync_statistics(self) -> Dict[str, Any]:
        """
        Получение статистики текущей синхронизации.
        
        Returns:
            Dict: Статистика синхронизации
        """
        if not self.current_sync:
            return {"error": "Нет активной сессии синхронизации"}
        
        stats = {
            "sync_type": self.current_sync.sync_type.value,
            "source": self.current_sync.source,
            "status": self.current_sync.status.value,
            "started_at": self.current_sync.started_at,
            "duration_seconds": self.current_sync.duration_seconds if self.current_sync.completed_at else None,
            "records": {
                "processed": self.current_sync.records_processed,
                "updated": self.current_sync.records_updated,
                "inserted": self.current_sync.records_inserted,
                "failed": self.current_sync.records_failed
            },
            "api_requests": self.current_sync.api_requests_count,
            "stages_count": len(self.processing_stages),
            "warnings_count": len(self.session_warnings),
            "errors_count": len(self.session_errors)
        }
        
        return stats
    
    def _write_sync_log_to_db(self, sync_entry: SyncLogEntry) -> Optional[int]:
        """
        Запись результата синхронизации в таблицу sync_logs.
        
        Args:
            sync_entry: Запись о синхронизации
            
        Returns:
            int: ID созданной записи или None при ошибке
        """
        try:
            # Определяем тип БД по типу курсора для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            if is_sqlite:
                # SQLite использует ? вместо %s
                insert_query = """
                    INSERT INTO sync_logs 
                    (sync_type, source, status, records_processed, records_updated, 
                     records_inserted, records_failed, started_at, completed_at, 
                     duration_seconds, api_requests_count, error_message, warning_message)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """
            else:
                # MySQL использует %s
                insert_query = """
                    INSERT INTO sync_logs 
                    (sync_type, source, status, records_processed, records_updated, 
                     records_inserted, records_failed, started_at, completed_at, 
                     duration_seconds, api_requests_count, error_message, warning_message)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """
            
            values = (
                sync_entry.sync_type.value,
                sync_entry.source,
                sync_entry.status.value,
                sync_entry.records_processed,
                sync_entry.records_updated,
                sync_entry.records_inserted,
                sync_entry.records_failed,
                sync_entry.started_at,
                sync_entry.completed_at,
                sync_entry.duration_seconds,
                sync_entry.api_requests_count,
                sync_entry.error_message,
                sync_entry.warning_message
            )
            
            self.cursor.execute(insert_query, values)
            self.connection.commit()
            
            sync_log_id = self.cursor.lastrowid
            self.logger.info(f"📝 Результат синхронизации записан в sync_logs (ID: {sync_log_id})")
            
            return sync_log_id
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка записи в sync_logs: {e}")
            try:
                self.connection.rollback()
            except:
                pass
            return None
    
    def _write_processing_stages_to_db(self, sync_log_id: Optional[int]):
        """
        Запись статистики этапов обработки в БД.
        
        Args:
            sync_log_id: ID записи в sync_logs
        """
        if not sync_log_id or not self.processing_stages:
            return
        
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            if is_sqlite:
                # SQLite синтаксис
                create_table_query = """
                    CREATE TABLE IF NOT EXISTS sync_processing_stages (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        sync_log_id INTEGER NOT NULL,
                        stage_name TEXT NOT NULL,
                        records_input INTEGER DEFAULT 0,
                        records_output INTEGER DEFAULT 0,
                        records_skipped INTEGER DEFAULT 0,
                        processing_time_seconds REAL DEFAULT 0,
                        memory_usage_mb REAL,
                        error_count INTEGER DEFAULT 0,
                        warning_count INTEGER DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (sync_log_id) REFERENCES sync_logs(id) ON DELETE CASCADE
                    )
                """
                
                insert_query = """
                    INSERT INTO sync_processing_stages 
                    (sync_log_id, stage_name, records_input, records_output, records_skipped,
                     processing_time_seconds, memory_usage_mb, error_count, warning_count)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                """
            else:
                # MySQL синтаксис
                create_table_query = """
                    CREATE TABLE IF NOT EXISTS sync_processing_stages (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        sync_log_id INT NOT NULL,
                        stage_name VARCHAR(255) NOT NULL,
                        records_input INT DEFAULT 0,
                        records_output INT DEFAULT 0,
                        records_skipped INT DEFAULT 0,
                        processing_time_seconds DECIMAL(10,3) DEFAULT 0,
                        memory_usage_mb DECIMAL(10,2) NULL,
                        error_count INT DEFAULT 0,
                        warning_count INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (sync_log_id) REFERENCES sync_logs(id) ON DELETE CASCADE,
                        INDEX idx_sync_log_stage (sync_log_id, stage_name)
                    )
                """
                
                insert_query = """
                    INSERT INTO sync_processing_stages 
                    (sync_log_id, stage_name, records_input, records_output, records_skipped,
                     processing_time_seconds, memory_usage_mb, error_count, warning_count)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                """
            
            self.cursor.execute(create_table_query)
            
            # Вставляем данные об этапах
            for stage in self.processing_stages:
                values = (
                    sync_log_id,
                    stage.stage_name,
                    stage.records_input,
                    stage.records_output,
                    stage.records_skipped,
                    stage.processing_time_seconds,
                    stage.memory_usage_mb,
                    stage.error_count,
                    stage.warning_count
                )
                
                self.cursor.execute(insert_query, values)
            
            self.connection.commit()
            self.logger.info(f"📊 Записано {len(self.processing_stages)} этапов обработки в БД")
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка записи этапов обработки: {e}")
            try:
                self.connection.rollback()
            except:
                pass
    
    def get_recent_sync_logs(self, source: Optional[str] = None, 
                           limit: int = 10) -> List[Dict[str, Any]]:
        """
        Получение последних записей синхронизации.
        
        Args:
            source: Фильтр по источнику (опционально)
            limit: Максимальное количество записей
            
        Returns:
            List[Dict]: Список записей синхронизации
        """
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            query = """
                SELECT id, sync_type, source, status, records_processed, 
                       records_updated, records_inserted, records_failed,
                       started_at, completed_at, duration_seconds, 
                       api_requests_count, error_message, warning_message
                FROM sync_logs 
                WHERE 1=1
            """
            
            params = []
            
            if source:
                if is_sqlite:
                    query += " AND source = ?"
                else:
                    query += " AND source = %s"
                params.append(source)
            
            if is_sqlite:
                query += " ORDER BY started_at DESC LIMIT ?"
            else:
                query += " ORDER BY started_at DESC LIMIT %s"
            params.append(limit)
            
            self.cursor.execute(query, params)
            results = self.cursor.fetchall()
            
            return results if results else []
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка получения логов синхронизации: {e}")
            return []
    
    def get_sync_health_report(self) -> Dict[str, Any]:
        """
        Получение отчета о состоянии синхронизации.
        
        Returns:
            Dict: Отчет о состоянии системы синхронизации
        """
        try:
            # Определяем тип БД для совместимости
            is_sqlite = hasattr(self.cursor, 'lastrowid') and 'sqlite' in str(type(self.cursor)).lower()
            
            if is_sqlite:
                # SQLite синтаксис
                query_24h = """
                    SELECT source, status, COUNT(*) as count,
                           AVG(duration_seconds) as avg_duration,
                           SUM(records_processed) as total_processed,
                           MAX(completed_at) as last_sync
                    FROM sync_logs 
                    WHERE started_at >= datetime('now', '-24 hours')
                      AND sync_type = 'inventory'
                    GROUP BY source, status
                    ORDER BY source, status
                """
                
                query_errors = """
                    SELECT source, error_message, started_at
                    FROM sync_logs 
                    WHERE status = 'failed' 
                      AND started_at >= datetime('now', '-7 days')
                      AND sync_type = 'inventory'
                    ORDER BY started_at DESC
                    LIMIT 5
                """
            else:
                # MySQL синтаксис
                query_24h = """
                    SELECT source, status, COUNT(*) as count,
                           AVG(duration_seconds) as avg_duration,
                           SUM(records_processed) as total_processed,
                           MAX(completed_at) as last_sync
                    FROM sync_logs 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      AND sync_type = 'inventory'
                    GROUP BY source, status
                    ORDER BY source, status
                """
                
                query_errors = """
                    SELECT source, error_message, started_at
                    FROM sync_logs 
                    WHERE status = 'failed' 
                      AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      AND sync_type = 'inventory'
                    ORDER BY started_at DESC
                    LIMIT 5
                """
            
            self.cursor.execute(query_24h)
            recent_stats = self.cursor.fetchall()
            
            self.cursor.execute(query_errors)
            recent_errors = self.cursor.fetchall()
            
            # Формируем отчет
            report = {
                "generated_at": datetime.now(),
                "period_hours": 24,
                "sources": {},
                "recent_errors": recent_errors,
                "overall_health": "healthy"
            }
            
            # Группируем статистику по источникам
            for stat in recent_stats:
                # Обрабатываем как dict или tuple в зависимости от типа курсора
                if isinstance(stat, dict):
                    source = stat['source']
                    status = stat['status']
                    count = stat['count']
                    avg_duration = stat['avg_duration']
                    total_processed = stat['total_processed']
                    last_sync = stat['last_sync']
                else:
                    # Для tuple (SQLite без row_factory)
                    source, status, count, avg_duration, total_processed, last_sync = stat
                
                if source not in report['sources']:
                    report['sources'][source] = {
                        'success_count': 0,
                        'partial_count': 0,
                        'failed_count': 0,
                        'avg_duration': 0,
                        'total_processed': 0,
                        'last_sync': None,
                        'health_status': 'unknown'
                    }
                
                source_data = report['sources'][source]
                
                if status == 'success':
                    source_data['success_count'] = count
                elif status == 'partial':
                    source_data['partial_count'] = count
                elif status == 'failed':
                    source_data['failed_count'] = count
                
                source_data['avg_duration'] = max(source_data['avg_duration'], avg_duration or 0)
                source_data['total_processed'] += total_processed or 0
                
                if last_sync:
                    if not source_data['last_sync'] or last_sync > source_data['last_sync']:
                        source_data['last_sync'] = last_sync
            
            # Определяем состояние здоровья для каждого источника
            for source, data in report['sources'].items():
                total_syncs = data['success_count'] + data['partial_count'] + data['failed_count']
                
                if total_syncs == 0:
                    data['health_status'] = 'no_data'
                elif data['failed_count'] > total_syncs * 0.5:
                    data['health_status'] = 'critical'
                elif data['failed_count'] > 0 or data['partial_count'] > total_syncs * 0.3:
                    data['health_status'] = 'warning'
                else:
                    data['health_status'] = 'healthy'
            
            # Общее состояние системы
            if any(data['health_status'] == 'critical' for data in report['sources'].values()):
                report['overall_health'] = 'critical'
            elif any(data['health_status'] == 'warning' for data in report['sources'].values()):
                report['overall_health'] = 'warning'
            
            return report
            
        except Exception as e:
            self.logger.error(f"❌ Ошибка генерации отчета о состоянии: {e}")
            return {
                "error": str(e),
                "generated_at": datetime.now()
            }


# Пример использования
if __name__ == "__main__":
    # Демонстрация использования SyncLogger
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
        
        # Создание логгера
        sync_logger = SyncLogger(cursor, connection)
        
        # Пример использования
        sync_entry = sync_logger.start_sync_session(SyncType.INVENTORY, "Ozon")
        
        sync_logger.log_info("Начинаем получение данных с API")
        sync_logger.log_api_request("/api/stocks", 1.5, 200, 150)
        
        sync_logger.log_processing_stage("API Data Fetch", 0, 150, 1.5)
        sync_logger.log_processing_stage("Data Validation", 150, 145, 0.3, records_skipped=5)
        sync_logger.log_processing_stage("Database Update", 145, 145, 2.1)
        
        sync_logger.log_warning("5 записей пропущено из-за отсутствия product_id")
        
        sync_log_id = sync_logger.end_sync_session()
        print(f"Синхронизация завершена, ID лога: {sync_log_id}")
        
        # Получение отчета
        health_report = sync_logger.get_sync_health_report()
        print(f"Отчет о состоянии: {health_report}")
        
    except Exception as e:
        print(f"Ошибка демонстрации: {e}")
    finally:
        if 'cursor' in locals():
            cursor.close()
        if 'connection' in locals():
            connection.close()