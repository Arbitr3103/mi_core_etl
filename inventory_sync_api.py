#!/usr/bin/env python3
"""
REST API для управления синхронизацией остатков товаров.

Предоставляет endpoints для:
- Запуска принудительной синхронизации
- Получения статуса последней синхронизации
- Получения отчетов о синхронизации

Автор: ETL System
Дата: 06 января 2025
"""

import os
import sys
import logging
from datetime import datetime, timedelta
from typing import Dict, List, Optional, Any
from flask import Flask, request, jsonify, render_template_string
from flask_cors import CORS
import threading
import json

# Добавляем путь к модулям
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from inventory_sync_service_with_error_handling import RobustInventorySyncService, SyncResult, SyncStatus
    from sync_logger import SyncLogger, SyncType, SyncStatus as LogSyncStatus
    from sync_monitor import SyncMonitor
    from importers.ozon_importer import connect_to_db
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

# Создаем Flask приложение
app = Flask(__name__)
CORS(app)  # Разрешаем CORS для веб-интерфейса

# Глобальные переменные для отслеживания состояния
sync_in_progress = False
last_sync_results = {}
sync_thread = None


class InventorySyncAPI:
    """Класс для управления API синхронизации остатков."""
    
    def __init__(self):
        """Инициализация API."""
        self.sync_service = None
        self.sync_monitor = None
        self.connection = None
        self.cursor = None
        
    def connect_to_database(self):
        """Подключение к базе данных."""
        try:
            self.connection = connect_to_db()
            self.cursor = self.connection.cursor(dictionary=True)
            self.sync_monitor = SyncMonitor()
            logger.info("✅ Подключение к базе данных установлено")
            return True
        except Exception as e:
            logger.error(f"❌ Ошибка подключения к БД: {e}")
            return False
    
    def get_sync_status(self) -> Dict[str, Any]:
        """Получить статус последней синхронизации."""
        try:
            if not self.cursor:
                self.connect_to_database()
            
            # Получаем последние записи синхронизации для каждого источника
            query = """
            SELECT 
                source,
                status,
                records_processed,
                records_updated,
                started_at,
                completed_at,
                duration_seconds,
                error_message
            FROM sync_logs 
            WHERE sync_type = 'inventory'
            AND (source, started_at) IN (
                SELECT source, MAX(started_at)
                FROM sync_logs 
                WHERE sync_type = 'inventory'
                GROUP BY source
            )
            ORDER BY started_at DESC
            """
            
            self.cursor.execute(query)
            sync_records = self.cursor.fetchall()
            
            # Получаем общую статистику
            stats_query = """
            SELECT 
                COUNT(DISTINCT product_id) as total_products,
                SUM(CASE WHEN source = 'Ozon' THEN 1 ELSE 0 END) as ozon_products,
                SUM(CASE WHEN source = 'Wildberries' THEN 1 ELSE 0 END) as wb_products,
                MAX(last_sync_at) as last_data_update
            FROM inventory_data 
            WHERE current_stock > 0
            """
            
            self.cursor.execute(stats_query)
            stats = self.cursor.fetchone()
            
            return {
                "sync_in_progress": sync_in_progress,
                "last_sync_records": [dict(record) for record in sync_records],
                "inventory_stats": dict(stats) if stats else {},
                "timestamp": datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"❌ Ошибка получения статуса синхронизации: {e}")
            return {
                "error": str(e),
                "timestamp": datetime.now().isoformat()
            }
    
    def get_sync_reports(self, days: int = 7) -> Dict[str, Any]:
        """Получить отчеты о синхронизации за указанный период."""
        try:
            if not self.cursor:
                self.connect_to_database()
            
            start_date = datetime.now() - timedelta(days=days)
            
            # Получаем записи синхронизации за период
            query = """
            SELECT 
                source,
                status,
                records_processed,
                records_updated,
                started_at,
                completed_at,
                duration_seconds,
                error_message
            FROM sync_logs 
            WHERE sync_type = 'inventory'
            AND started_at >= %s
            ORDER BY started_at DESC
            """
            
            self.cursor.execute(query, (start_date,))
            sync_records = self.cursor.fetchall()
            
            # Агрегируем статистику
            stats = {
                "total_syncs": len(sync_records),
                "successful_syncs": len([r for r in sync_records if r['status'] == 'success']),
                "failed_syncs": len([r for r in sync_records if r['status'] == 'failed']),
                "partial_syncs": len([r for r in sync_records if r['status'] == 'partial']),
                "total_records_processed": sum(r['records_processed'] or 0 for r in sync_records),
                "total_records_updated": sum(r['records_updated'] or 0 for r in sync_records),
                "average_duration": sum(r['duration_seconds'] or 0 for r in sync_records) / len(sync_records) if sync_records else 0
            }
            
            # Группируем по источникам
            by_source = {}
            for record in sync_records:
                source = record['source']
                if source not in by_source:
                    by_source[source] = []
                by_source[source].append(dict(record))
            
            return {
                "period_days": days,
                "start_date": start_date.isoformat(),
                "end_date": datetime.now().isoformat(),
                "statistics": stats,
                "records_by_source": by_source,
                "all_records": [dict(record) for record in sync_records]
            }
            
        except Exception as e:
            logger.error(f"❌ Ошибка получения отчетов: {e}")
            return {
                "error": str(e),
                "timestamp": datetime.now().isoformat()
            }


# Создаем экземпляр API
api_instance = InventorySyncAPI()


def run_sync_in_background(sources: List[str] = None):
    """Запуск синхронизации в фоновом режиме."""
    global sync_in_progress, last_sync_results
    
    try:
        sync_in_progress = True
        logger.info("🔄 Запуск фоновой синхронизации")
        
        # Создаем новый экземпляр сервиса для фонового выполнения
        sync_service = RobustInventorySyncService()
        sync_service.connect_to_database()
        
        results = {}
        
        # Определяем источники для синхронизации
        if not sources:
            sources = ['Ozon', 'Wildberries']
        
        # Выполняем синхронизацию для каждого источника
        for source in sources:
            try:
                if source.lower() == 'ozon':
                    result = sync_service.sync_ozon_inventory()
                elif source.lower() == 'wildberries':
                    result = sync_service.sync_wb_inventory()
                else:
                    continue
                
                results[source] = {
                    "status": result.status.value,
                    "records_processed": result.records_processed,
                    "records_updated": result.records_updated,
                    "records_inserted": result.records_inserted,
                    "records_failed": result.records_failed,
                    "duration_seconds": result.duration_seconds,
                    "error_message": result.error_message,
                    "completed_at": result.completed_at.isoformat() if result.completed_at else None
                }
                
                logger.info(f"✅ Синхронизация {source} завершена: {result.status.value}")
                
            except Exception as e:
                logger.error(f"❌ Ошибка синхронизации {source}: {e}")
                results[source] = {
                    "status": "failed",
                    "error_message": str(e),
                    "completed_at": datetime.now().isoformat()
                }
        
        sync_service.close_database_connection()
        last_sync_results = results
        
        logger.info("✅ Фоновая синхронизация завершена")
        
    except Exception as e:
        logger.error(f"❌ Критическая ошибка фоновой синхронизации: {e}")
        last_sync_results = {
            "error": str(e),
            "completed_at": datetime.now().isoformat()
        }
    finally:
        sync_in_progress = False


# API Endpoints

@app.route('/api/sync/status')
def get_sync_status():
    """Получить статус последней синхронизации."""
    try:
        status_data = api_instance.get_sync_status()
        
        # Добавляем информацию о текущем процессе синхронизации
        if sync_in_progress:
            status_data["current_sync"] = {
                "in_progress": True,
                "message": "Синхронизация выполняется в фоновом режиме"
            }
        
        # Добавляем результаты последней принудительной синхронизации
        if last_sync_results:
            status_data["last_forced_sync"] = last_sync_results
        
        return jsonify({
            "success": True,
            "data": status_data
        })
        
    except Exception as e:
        logger.error(f"❌ Ошибка API get_sync_status: {e}")
        return jsonify({
            "success": False,
            "error": str(e)
        }), 500


@app.route('/api/sync/reports')
def get_sync_reports():
    """Получить отчеты о синхронизации."""
    try:
        # Получаем параметр количества дней (по умолчанию 7)
        days = request.args.get('days', 7, type=int)
        
        # Ограничиваем максимальный период
        if days > 90:
            days = 90
        
        reports_data = api_instance.get_sync_reports(days)
        
        return jsonify({
            "success": True,
            "data": reports_data
        })
        
    except Exception as e:
        logger.error(f"❌ Ошибка API get_sync_reports: {e}")
        return jsonify({
            "success": False,
            "error": str(e)
        }), 500


@app.route('/api/sync/trigger', methods=['POST'])
def trigger_sync():
    """Запустить принудительную синхронизацию."""
    global sync_thread
    
    try:
        # Проверяем, не выполняется ли уже синхронизация
        if sync_in_progress:
            return jsonify({
                "success": False,
                "error": "Синхронизация уже выполняется"
            }), 409
        
        # Получаем параметры из запроса
        data = request.get_json() or {}
        sources = data.get('sources', ['Ozon', 'Wildberries'])
        
        # Валидируем источники
        valid_sources = ['Ozon', 'Wildberries']
        sources = [s for s in sources if s in valid_sources]
        
        if not sources:
            return jsonify({
                "success": False,
                "error": "Не указаны валидные источники для синхронизации"
            }), 400
        
        # Запускаем синхронизацию в отдельном потоке
        sync_thread = threading.Thread(
            target=run_sync_in_background,
            args=(sources,),
            daemon=True
        )
        sync_thread.start()
        
        logger.info(f"🚀 Запущена принудительная синхронизация для источников: {sources}")
        
        return jsonify({
            "success": True,
            "message": "Синхронизация запущена в фоновом режиме",
            "sources": sources,
            "started_at": datetime.now().isoformat()
        })
        
    except Exception as e:
        logger.error(f"❌ Ошибка API trigger_sync: {e}")
        return jsonify({
            "success": False,
            "error": str(e)
        }), 500


@app.route('/api/sync/health')
def sync_health_check():
    """Проверка состояния системы синхронизации."""
    try:
        # Проверяем подключение к БД
        db_connected = api_instance.connect_to_database()
        
        # Проверяем актуальность данных
        health_data = {
            "database_connected": db_connected,
            "sync_in_progress": sync_in_progress,
            "api_status": "healthy",
            "timestamp": datetime.now().isoformat()
        }
        
        if db_connected:
            # Проверяем актуальность данных
            try:
                api_instance.cursor.execute("""
                    SELECT 
                        source,
                        MAX(last_sync_at) as last_sync,
                        COUNT(*) as products_count
                    FROM inventory_data 
                    WHERE current_stock > 0
                    GROUP BY source
                """)
                
                data_freshness = api_instance.cursor.fetchall()
                health_data["data_freshness"] = [dict(row) for row in data_freshness]
                
                # Проверяем, есть ли устаревшие данные (старше 12 часов)
                stale_threshold = datetime.now() - timedelta(hours=12)
                stale_data = any(
                    row['last_sync'] and row['last_sync'] < stale_threshold 
                    for row in data_freshness
                )
                
                health_data["data_stale"] = stale_data
                
            except Exception as e:
                health_data["data_check_error"] = str(e)
        
        status_code = 200 if db_connected else 503
        
        return jsonify({
            "success": True,
            "data": health_data
        }), status_code
        
    except Exception as e:
        logger.error(f"❌ Ошибка API sync_health_check: {e}")
        return jsonify({
            "success": False,
            "error": str(e)
        }), 500


@app.route('/api/sync/logs')
def get_sync_logs():
    """Получить логи синхронизации."""
    try:
        # Получаем параметры фильтрации
        source = request.args.get('source')  # Ozon, Wildberries
        status = request.args.get('status')  # success, failed, partial
        limit = request.args.get('limit', 50, type=int)
        
        # Ограничиваем максимальное количество записей
        if limit > 500:
            limit = 500
        
        if not api_instance.cursor:
            api_instance.connect_to_database()
        
        # Строим запрос с фильтрами
        query = """
        SELECT 
            id,
            source,
            status,
            records_processed,
            records_updated,
            started_at,
            completed_at,
            duration_seconds,
            error_message
        FROM sync_logs 
        WHERE sync_type = 'inventory'
        """
        
        params = []
        
        if source:
            query += " AND source = %s"
            params.append(source)
        
        if status:
            query += " AND status = %s"
            params.append(status)
        
        query += " ORDER BY started_at DESC LIMIT %s"
        params.append(limit)
        
        api_instance.cursor.execute(query, params)
        logs = api_instance.cursor.fetchall()
        
        return jsonify({
            "success": True,
            "data": {
                "logs": [dict(log) for log in logs],
                "filters": {
                    "source": source,
                    "status": status,
                    "limit": limit
                },
                "total_returned": len(logs)
            }
        })
        
    except Exception as e:
        logger.error(f"❌ Ошибка API get_sync_logs: {e}")
        return jsonify({
            "success": False,
            "error": str(e)
        }), 500


# Web Interface Routes

@app.route('/')
def dashboard():
    """Главная страница с веб-интерфейсом управления синхронизацией."""
    return render_template_string(DASHBOARD_TEMPLATE)


@app.route('/logs')
def logs_page():
    """Страница с логами синхронизации."""
    return render_template_string(LOGS_TEMPLATE)


# HTML Templates

DASHBOARD_TEMPLATE = """
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление синхронизацией остатков</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .status-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .status-card h3 {
            color: #34495e;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .status-success { background-color: #27ae60; }
        .status-warning { background-color: #f39c12; }
        .status-error { background-color: #e74c3c; }
        .status-info { background-color: #3498db; }
        
        .metric {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .metric:last-child {
            border-bottom: none;
        }
        
        .metric-value {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .controls {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background-color: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
        }
        
        .btn-success {
            background-color: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #229954;
        }
        
        .btn-warning {
            background-color: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #e67e22;
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .sync-options {
            margin-bottom: 20px;
        }
        
        .checkbox-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: none;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .logs-preview {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .log-entry {
            padding: 10px;
            border-left: 4px solid #ecf0f1;
            margin-bottom: 10px;
            background-color: #fafafa;
        }
        
        .log-entry.success {
            border-left-color: #27ae60;
        }
        
        .log-entry.error {
            border-left-color: #e74c3c;
        }
        
        .log-entry.warning {
            border-left-color: #f39c12;
        }
        
        .log-time {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .log-message {
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .checkbox-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Управление синхронизацией остатков</h1>
            <p>Мониторинг и управление синхронизацией данных об остатках товаров с маркетплейсами</p>
        </div>
        
        <div id="alert" class="alert"></div>
        
        <div class="status-grid">
            <div class="status-card">
                <h3><span id="sync-indicator" class="status-indicator status-info"></span>Статус синхронизации</h3>
                <div class="metric">
                    <span>Текущий статус:</span>
                    <span id="sync-status" class="metric-value">Загрузка...</span>
                </div>
                <div class="metric">
                    <span>Последняя синхронизация:</span>
                    <span id="last-sync" class="metric-value">-</span>
                </div>
                <div class="metric">
                    <span>Активных процессов:</span>
                    <span id="active-processes" class="metric-value">0</span>
                </div>
            </div>
            
            <div class="status-card">
                <h3><span class="status-indicator status-success"></span>Статистика остатков</h3>
                <div class="metric">
                    <span>Всего товаров:</span>
                    <span id="total-products" class="metric-value">-</span>
                </div>
                <div class="metric">
                    <span>Товары Ozon:</span>
                    <span id="ozon-products" class="metric-value">-</span>
                </div>
                <div class="metric">
                    <span>Товары Wildberries:</span>
                    <span id="wb-products" class="metric-value">-</span>
                </div>
            </div>
        </div>
        
        <div class="controls">
            <h3>🎛️ Управление синхронизацией</h3>
            
            <div class="sync-options">
                <label><strong>Выберите источники для синхронизации:</strong></label>
                <div class="checkbox-group">
                    <div class="checkbox-item">
                        <input type="checkbox" id="sync-ozon" checked>
                        <label for="sync-ozon">Ozon</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="sync-wb" checked>
                        <label for="sync-wb">Wildberries</label>
                    </div>
                </div>
            </div>
            
            <div class="button-group">
                <button id="trigger-sync" class="btn btn-primary">🚀 Запустить синхронизацию</button>
                <button id="refresh-status" class="btn btn-success">🔄 Обновить статус</button>
                <a href="/logs" class="btn btn-warning">📋 Просмотр логов</a>
            </div>
            
            <div id="loading" class="loading">
                <div class="spinner"></div>
                <p>Выполняется синхронизация...</p>
            </div>
        </div>
        
        <div class="logs-preview">
            <h3>📋 Последние события</h3>
            <div id="recent-logs">
                <p>Загрузка логов...</p>
            </div>
        </div>
    </div>

    <script>
        // Глобальные переменные
        let syncInProgress = false;
        let refreshInterval;
        
        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            loadStatus();
            loadRecentLogs();
            
            // Настраиваем обработчики событий
            document.getElementById('trigger-sync').addEventListener('click', triggerSync);
            document.getElementById('refresh-status').addEventListener('click', loadStatus);
            
            // Автоматическое обновление каждые 30 секунд
            refreshInterval = setInterval(loadStatus, 30000);
        });
        
        // Функция загрузки статуса
        async function loadStatus() {
            try {
                const response = await fetch('/api/sync/status');
                const data = await response.json();
                
                if (data.success) {
                    updateStatusDisplay(data.data);
                } else {
                    showAlert('Ошибка загрузки статуса: ' + data.error, 'error');
                }
            } catch (error) {
                showAlert('Ошибка подключения к API: ' + error.message, 'error');
            }
        }
        
        // Функция обновления отображения статуса
        function updateStatusDisplay(statusData) {
            const syncIndicator = document.getElementById('sync-indicator');
            const syncStatus = document.getElementById('sync-status');
            const lastSync = document.getElementById('last-sync');
            const activeProcesses = document.getElementById('active-processes');
            const totalProducts = document.getElementById('total-products');
            const ozonProducts = document.getElementById('ozon-products');
            const wbProducts = document.getElementById('wb-products');
            
            // Обновляем статус синхронизации
            syncInProgress = statusData.sync_in_progress || false;
            
            if (syncInProgress) {
                syncIndicator.className = 'status-indicator status-warning';
                syncStatus.textContent = 'Выполняется';
                activeProcesses.textContent = '1';
                document.getElementById('trigger-sync').disabled = true;
            } else {
                syncIndicator.className = 'status-indicator status-success';
                syncStatus.textContent = 'Готов';
                activeProcesses.textContent = '0';
                document.getElementById('trigger-sync').disabled = false;
            }
            
            // Обновляем время последней синхронизации
            if (statusData.last_sync_records && statusData.last_sync_records.length > 0) {
                const latestSync = statusData.last_sync_records[0];
                const syncTime = new Date(latestSync.completed_at || latestSync.started_at);
                lastSync.textContent = syncTime.toLocaleString('ru-RU');
            }
            
            // Обновляем статистику остатков
            if (statusData.inventory_stats) {
                const stats = statusData.inventory_stats;
                totalProducts.textContent = stats.total_products || 0;
                ozonProducts.textContent = stats.ozon_products || 0;
                wbProducts.textContent = stats.wb_products || 0;
            }
        }
        
        // Функция запуска синхронизации
        async function triggerSync() {
            if (syncInProgress) {
                showAlert('Синхронизация уже выполняется', 'info');
                return;
            }
            
            // Получаем выбранные источники
            const sources = [];
            if (document.getElementById('sync-ozon').checked) {
                sources.push('Ozon');
            }
            if (document.getElementById('sync-wb').checked) {
                sources.push('Wildberries');
            }
            
            if (sources.length === 0) {
                showAlert('Выберите хотя бы один источник для синхронизации', 'error');
                return;
            }
            
            try {
                // Показываем индикатор загрузки
                document.getElementById('loading').style.display = 'block';
                document.getElementById('trigger-sync').disabled = true;
                
                const response = await fetch('/api/sync/trigger', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ sources: sources })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('Синхронизация запущена успешно для источников: ' + sources.join(', '), 'success');
                    syncInProgress = true;
                    
                    // Увеличиваем частоту обновления во время синхронизации
                    clearInterval(refreshInterval);
                    refreshInterval = setInterval(loadStatus, 5000);
                    
                    // Через 2 минуты возвращаем обычную частоту
                    setTimeout(() => {
                        clearInterval(refreshInterval);
                        refreshInterval = setInterval(loadStatus, 30000);
                    }, 120000);
                    
                } else {
                    showAlert('Ошибка запуска синхронизации: ' + data.error, 'error');
                    document.getElementById('trigger-sync').disabled = false;
                }
                
            } catch (error) {
                showAlert('Ошибка подключения к API: ' + error.message, 'error');
                document.getElementById('trigger-sync').disabled = false;
            } finally {
                document.getElementById('loading').style.display = 'none';
            }
        }
        
        // Функция загрузки последних логов
        async function loadRecentLogs() {
            try {
                const response = await fetch('/api/sync/logs?limit=5');
                const data = await response.json();
                
                if (data.success && data.data.logs) {
                    displayRecentLogs(data.data.logs);
                }
            } catch (error) {
                console.error('Ошибка загрузки логов:', error);
            }
        }
        
        // Функция отображения последних логов
        function displayRecentLogs(logs) {
            const container = document.getElementById('recent-logs');
            
            if (logs.length === 0) {
                container.innerHTML = '<p>Нет доступных логов</p>';
                return;
            }
            
            const logsHtml = logs.map(log => {
                const statusClass = log.status === 'success' ? 'success' : 
                                  log.status === 'failed' ? 'error' : 'warning';
                const time = new Date(log.started_at).toLocaleString('ru-RU');
                
                return `
                    <div class="log-entry ${statusClass}">
                        <div class="log-time">${time} - ${log.source}</div>
                        <div class="log-message">
                            Статус: ${log.status}, 
                            Обработано: ${log.records_processed || 0}, 
                            Обновлено: ${log.records_updated || 0}
                            ${log.error_message ? '<br>Ошибка: ' + log.error_message : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            container.innerHTML = logsHtml;
        }
        
        // Функция показа уведомлений
        function showAlert(message, type) {
            const alert = document.getElementById('alert');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            alert.style.display = 'block';
            
            // Автоматически скрываем через 5 секунд
            setTimeout(() => {
                alert.style.display = 'none';
            }, 5000);
        }
        
        // Очистка интервала при закрытии страницы
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
</body>
</html>
"""

LOGS_TEMPLATE = """
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Логи синхронизации остатков</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: #2c3e50;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            background-color: #3498db;
            color: white;
        }
        
        .btn:hover {
            background-color: #2980b9;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-item label {
            font-weight: 500;
            color: #34495e;
        }
        
        .filter-item select,
        .filter-item input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .logs-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th,
        .logs-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .logs-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .logs-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-failed {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .status-partial {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .error-message {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
        }
        
        .error-message:hover {
            white-space: normal;
            word-break: break-word;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .no-logs {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .logs-table {
                font-size: 12px;
            }
            
            .logs-table th,
            .logs-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Логи синхронизации остатков</h1>
            <a href="/" class="btn">← Вернуться к панели управления</a>
        </div>
        
        <div class="filters">
            <div class="filter-group">
                <div class="filter-item">
                    <label for="source-filter">Источник:</label>
                    <select id="source-filter">
                        <option value="">Все источники</option>
                        <option value="Ozon">Ozon</option>
                        <option value="Wildberries">Wildberries</option>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label for="status-filter">Статус:</label>
                    <select id="status-filter">
                        <option value="">Все статусы</option>
                        <option value="success">Успешно</option>
                        <option value="failed">Ошибка</option>
                        <option value="partial">Частично</option>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label for="limit-filter">Количество записей:</label>
                    <select id="limit-filter">
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label>&nbsp;</label>
                    <button id="apply-filters" class="btn">Применить фильтры</button>
                </div>
            </div>
        </div>
        
        <div class="logs-container">
            <div id="loading" class="loading">
                <div class="spinner"></div>
                <p>Загрузка логов...</p>
            </div>
            
            <div id="logs-content" style="display: none;">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>Время</th>
                            <th>Источник</th>
                            <th>Статус</th>
                            <th>Обработано</th>
                            <th>Обновлено</th>
                            <th>Длительность</th>
                            <th>Ошибка</th>
                        </tr>
                    </thead>
                    <tbody id="logs-tbody">
                    </tbody>
                </table>
            </div>
            
            <div id="no-logs" class="no-logs" style="display: none;">
                <p>Нет логов, соответствующих выбранным фильтрам</p>
            </div>
        </div>
    </div>

    <script>
        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            loadLogs();
            
            // Настраиваем обработчики событий
            document.getElementById('apply-filters').addEventListener('click', loadLogs);
            
            // Автоматическое обновление каждые 60 секунд
            setInterval(loadLogs, 60000);
        });
        
        // Функция загрузки логов
        async function loadLogs() {
            const loading = document.getElementById('loading');
            const logsContent = document.getElementById('logs-content');
            const noLogs = document.getElementById('no-logs');
            
            // Показываем индикатор загрузки
            loading.style.display = 'block';
            logsContent.style.display = 'none';
            noLogs.style.display = 'none';
            
            try {
                // Получаем параметры фильтрации
                const source = document.getElementById('source-filter').value;
                const status = document.getElementById('status-filter').value;
                const limit = document.getElementById('limit-filter').value;
                
                // Формируем URL с параметрами
                const params = new URLSearchParams();
                if (source) params.append('source', source);
                if (status) params.append('status', status);
                if (limit) params.append('limit', limit);
                
                const response = await fetch('/api/sync/logs?' + params.toString());
                const data = await response.json();
                
                if (data.success) {
                    displayLogs(data.data.logs);
                } else {
                    console.error('Ошибка загрузки логов:', data.error);
                    noLogs.innerHTML = '<p>Ошибка загрузки логов: ' + data.error + '</p>';
                    noLogs.style.display = 'block';
                }
            } catch (error) {
                console.error('Ошибка подключения к API:', error);
                noLogs.innerHTML = '<p>Ошибка подключения к API: ' + error.message + '</p>';
                noLogs.style.display = 'block';
            } finally {
                loading.style.display = 'none';
            }
        }
        
        // Функция отображения логов
        function displayLogs(logs) {
            const tbody = document.getElementById('logs-tbody');
            const logsContent = document.getElementById('logs-content');
            const noLogs = document.getElementById('no-logs');
            
            if (logs.length === 0) {
                noLogs.style.display = 'block';
                return;
            }
            
            const logsHtml = logs.map(log => {
                const startTime = new Date(log.started_at).toLocaleString('ru-RU');
                const statusClass = getStatusClass(log.status);
                const statusText = getStatusText(log.status);
                const duration = formatDuration(log.duration_seconds);
                const errorMessage = log.error_message ? 
                    `<span class="error-message" title="${log.error_message}">${log.error_message}</span>` : 
                    '-';
                
                return `
                    <tr>
                        <td>${startTime}</td>
                        <td>${log.source}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                        <td>${log.records_processed || 0}</td>
                        <td>${log.records_updated || 0}</td>
                        <td>${duration}</td>
                        <td>${errorMessage}</td>
                    </tr>
                `;
            }).join('');
            
            tbody.innerHTML = logsHtml;
            logsContent.style.display = 'block';
        }
        
        // Функция получения CSS класса для статуса
        function getStatusClass(status) {
            switch (status) {
                case 'success': return 'status-success';
                case 'failed': return 'status-failed';
                case 'partial': return 'status-partial';
                default: return 'status-partial';
            }
        }
        
        // Функция получения текста для статуса
        function getStatusText(status) {
            switch (status) {
                case 'success': return 'Успешно';
                case 'failed': return 'Ошибка';
                case 'partial': return 'Частично';
                default: return status;
            }
        }
        
        // Функция форматирования длительности
        function formatDuration(seconds) {
            if (!seconds) return '-';
            
            if (seconds < 60) {
                return seconds + ' сек';
            } else if (seconds < 3600) {
                return Math.floor(seconds / 60) + ' мин ' + (seconds % 60) + ' сек';
            } else {
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                return hours + ' ч ' + minutes + ' мин';
            }
        }
    </script>
</body>
</html>
"""


if __name__ == '__main__':
    """Запуск API сервера."""
    print("🚀 Запуск API управления синхронизацией остатков")
    print("📋 Доступные endpoints:")
    print("   GET  /api/sync/status   - Статус синхронизации")
    print("   GET  /api/sync/reports  - Отчеты о синхронизации")
    print("   POST /api/sync/trigger  - Запуск принудительной синхронизации")
    print("   GET  /api/sync/health   - Проверка состояния системы")
    print("   GET  /api/sync/logs     - Логи синхронизации")
    print()
    
    # Подключаемся к БД при запуске
    if api_instance.connect_to_database():
        print("✅ Подключение к базе данных установлено")
    else:
        print("❌ Не удалось подключиться к базе данных")
    
    # Запускаем сервер
    app.run(
        host='0.0.0.0',
        port=5001,
        debug=True,
        threaded=True
    )