#!/usr/bin/env python3
"""
Cron Jobs Dashboard
Простой веб-дашборд для мониторинга выполнения cron задач синхронизации

Использование:
  python3 cron_dashboard.py

Автор: Inventory Monitoring System
Версия: 1.0
"""

import os
import sys
import json
import sqlite3
from datetime import datetime, timedelta
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
import threading
import time

# Добавляем путь к корневой директории проекта
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    from importers.ozon_importer import connect_to_db
except ImportError:
    print("❌ Ошибка импорта модулей БД")
    sys.exit(1)


class CronDashboard:
    """Класс для мониторинга cron задач."""
    
    def __init__(self):
        self.script_dir = os.path.dirname(os.path.abspath(__file__))
        self.logs_dir = os.path.join(self.script_dir, 'logs')
        self.db_path = os.path.join(self.logs_dir, 'cron_monitoring.db')
        self.init_monitoring_db()
    
    def init_monitoring_db(self):
        """Инициализация локальной БД для мониторинга."""
        os.makedirs(self.logs_dir, exist_ok=True)
        
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS cron_executions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_name TEXT NOT NULL,
                started_at TIMESTAMP NOT NULL,
                completed_at TIMESTAMP,
                status TEXT NOT NULL,
                exit_code INTEGER,
                log_file TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS job_schedules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_name TEXT NOT NULL UNIQUE,
                cron_expression TEXT NOT NULL,
                description TEXT,
                enabled BOOLEAN DEFAULT 1,
                last_run TIMESTAMP,
                next_run TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Добавляем стандартные задачи
        jobs = [
            ('inventory_sync_all', '0 */6 * * *', 'Синхронизация всех источников каждые 6 часов'),
            ('inventory_sync_ozon', '30 */6 * * *', 'Синхронизация Ozon каждые 6 часов'),
            ('inventory_sync_wb', '0 3,9,15,21 * * *', 'Синхронизация Wildberries 4 раза в день'),
            ('weekly_resync', '0 2 * * 0', 'Еженедельная полная пересинхронизация'),
            ('health_check', '0 * * * *', 'Проверка состояния системы каждый час'),
            ('data_freshness_check', '0 */2 * * *', 'Проверка актуальности данных каждые 2 часа'),
            ('log_monitor', '0 23 * * *', 'Мониторинг размера логов ежедневно'),
            ('weekly_report', '0 8 * * 1', 'Генерация еженедельного отчета')
        ]
        
        for job_name, cron_expr, description in jobs:
            cursor.execute('''
                INSERT OR IGNORE INTO job_schedules (job_name, cron_expression, description)
                VALUES (?, ?, ?)
            ''', (job_name, cron_expr, description))
        
        conn.commit()
        conn.close()
    
    def get_sync_statistics(self):
        """Получение статистики синхронизации из основной БД."""
        try:
            connection = connect_to_db()
            cursor = connection.cursor(dictionary=True)
            
            # Статистика за последние 24 часа
            cursor.execute("""
                SELECT 
                    source,
                    COUNT(*) as total_syncs,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_syncs,
                    MAX(completed_at) as last_sync,
                    AVG(duration_seconds) as avg_duration
                FROM sync_logs 
                WHERE sync_type = 'inventory' 
                AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY source
            """)
            
            sync_stats = cursor.fetchall()
            
            # Текущие остатки
            cursor.execute("""
                SELECT 
                    source,
                    COUNT(DISTINCT product_id) as unique_products,
                    SUM(quantity_present) as total_present,
                    MAX(last_sync_at) as last_data_sync
                FROM inventory_data
                GROUP BY source
            """)
            
            inventory_stats = cursor.fetchall()
            
            cursor.close()
            connection.close()
            
            return {
                'sync_stats': sync_stats,
                'inventory_stats': inventory_stats
            }
            
        except Exception as e:
            return {'error': str(e)}
    
    def get_cron_status(self):
        """Получение статуса cron задач."""
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        
        cursor.execute('''
            SELECT 
                js.job_name,
                js.cron_expression,
                js.description,
                js.enabled,
                js.last_run,
                js.next_run,
                ce.status as last_status,
                ce.exit_code as last_exit_code,
                ce.completed_at as last_completed
            FROM job_schedules js
            LEFT JOIN (
                SELECT DISTINCT job_name, status, exit_code, completed_at,
                       ROW_NUMBER() OVER (PARTITION BY job_name ORDER BY started_at DESC) as rn
                FROM cron_executions
            ) ce ON js.job_name = ce.job_name AND ce.rn = 1
            ORDER BY js.job_name
        ''')
        
        jobs = cursor.fetchall()
        
        conn.close()
        
        return [dict(zip([col[0] for col in cursor.description], job)) for job in jobs]
    
    def get_recent_executions(self, limit=20):
        """Получение последних выполнений cron задач."""
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        
        cursor.execute('''
            SELECT job_name, started_at, completed_at, status, exit_code, log_file
            FROM cron_executions
            ORDER BY started_at DESC
            LIMIT ?
        ''', (limit,))
        
        executions = cursor.fetchall()
        
        conn.close()
        
        return [dict(zip([col[0] for col in cursor.description], execution)) for execution in executions]
    
    def log_execution(self, job_name, status, exit_code=None, log_file=None):
        """Логирование выполнения cron задачи."""
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        
        cursor.execute('''
            INSERT INTO cron_executions (job_name, started_at, completed_at, status, exit_code, log_file)
            VALUES (?, ?, ?, ?, ?, ?)
        ''', (job_name, datetime.now(), datetime.now(), status, exit_code, log_file))
        
        # Обновляем время последнего запуска
        cursor.execute('''
            UPDATE job_schedules 
            SET last_run = ? 
            WHERE job_name = ?
        ''', (datetime.now(), job_name))
        
        conn.commit()
        conn.close()


class DashboardHandler(BaseHTTPRequestHandler):
    """HTTP обработчик для веб-дашборда."""
    
    def __init__(self, *args, dashboard=None, **kwargs):
        self.dashboard = dashboard
        super().__init__(*args, **kwargs)
    
    def do_GET(self):
        """Обработка GET запросов."""
        parsed_path = urlparse(self.path)
        
        if parsed_path.path == '/':
            self.serve_dashboard()
        elif parsed_path.path == '/api/status':
            self.serve_api_status()
        elif parsed_path.path == '/api/executions':
            self.serve_api_executions()
        elif parsed_path.path == '/api/sync_stats':
            self.serve_api_sync_stats()
        else:
            self.send_error(404)
    
    def serve_dashboard(self):
        """Отправка HTML дашборда."""
        html_content = self.generate_dashboard_html()
        
        self.send_response(200)
        self.send_header('Content-type', 'text/html; charset=utf-8')
        self.end_headers()
        self.wfile.write(html_content.encode('utf-8'))
    
    def serve_api_status(self):
        """API для получения статуса cron задач."""
        jobs = self.dashboard.get_cron_status()
        
        self.send_response(200)
        self.send_header('Content-type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(jobs, default=str, ensure_ascii=False).encode('utf-8'))
    
    def serve_api_executions(self):
        """API для получения последних выполнений."""
        executions = self.dashboard.get_recent_executions()
        
        self.send_response(200)
        self.send_header('Content-type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(executions, default=str, ensure_ascii=False).encode('utf-8'))
    
    def serve_api_sync_stats(self):
        """API для получения статистики синхронизации."""
        stats = self.dashboard.get_sync_statistics()
        
        self.send_response(200)
        self.send_header('Content-type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(stats, default=str, ensure_ascii=False).encode('utf-8'))
    
    def generate_dashboard_html(self):
        """Генерация HTML дашборда."""
        return '''
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мониторинг Cron Задач - Синхронизация Остатков</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .status-card { background: white; border-radius: 8px; padding: 15px; border-left: 4px solid #ddd; }
        .status-success { border-left-color: #28a745; }
        .status-warning { border-left-color: #ffc107; }
        .status-error { border-left-color: #dc3545; }
        .status-unknown { border-left-color: #6c757d; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background-color: #f8f9fa; font-weight: bold; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-success { background-color: #d4edda; color: #155724; }
        .badge-warning { background-color: #fff3cd; color: #856404; }
        .badge-error { background-color: #f8d7da; color: #721c24; }
        .badge-unknown { background-color: #e2e3e5; color: #383d41; }
        .refresh-btn { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; }
        .refresh-btn:hover { background: #0056b3; }
        .stats-number { font-size: 24px; font-weight: bold; color: #333; }
        .stats-label { color: #666; font-size: 14px; }
        .loading { text-align: center; padding: 20px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Мониторинг Cron Задач</h1>
            <p>Система синхронизации остатков товаров</p>
            <button class="refresh-btn" onclick="refreshData()">🔄 Обновить</button>
        </div>
        
        <div class="card">
            <h2>📊 Статистика синхронизации (24ч)</h2>
            <div id="sync-stats" class="loading">Загрузка...</div>
        </div>
        
        <div class="card">
            <h2>⚙️ Статус Cron Задач</h2>
            <div id="cron-status" class="loading">Загрузка...</div>
        </div>
        
        <div class="card">
            <h2>📝 Последние выполнения</h2>
            <div id="recent-executions" class="loading">Загрузка...</div>
        </div>
    </div>

    <script>
        function refreshData() {
            loadSyncStats();
            loadCronStatus();
            loadRecentExecutions();
        }
        
        function loadSyncStats() {
            fetch('/api/sync_stats')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('sync-stats');
                    
                    if (data.error) {
                        container.innerHTML = `<div class="badge-error">Ошибка: ${data.error}</div>`;
                        return;
                    }
                    
                    let html = '<div class="status-grid">';
                    
                    if (data.sync_stats) {
                        data.sync_stats.forEach(stat => {
                            const successRate = stat.total_syncs > 0 ? (stat.successful_syncs / stat.total_syncs * 100).toFixed(1) : 0;
                            const statusClass = successRate >= 90 ? 'status-success' : successRate >= 70 ? 'status-warning' : 'status-error';
                            
                            html += `
                                <div class="status-card ${statusClass}">
                                    <h3>${stat.source}</h3>
                                    <div class="stats-number">${successRate}%</div>
                                    <div class="stats-label">Успешность синхронизации</div>
                                    <p>Всего: ${stat.total_syncs} | Успешных: ${stat.successful_syncs}</p>
                                    <p>Среднее время: ${(stat.avg_duration || 0).toFixed(1)}с</p>
                                    <p>Последняя: ${stat.last_sync || 'Нет данных'}</p>
                                </div>
                            `;
                        });
                    }
                    
                    if (data.inventory_stats) {
                        data.inventory_stats.forEach(stat => {
                            html += `
                                <div class="status-card status-success">
                                    <h3>Остатки ${stat.source}</h3>
                                    <div class="stats-number">${stat.unique_products}</div>
                                    <div class="stats-label">Уникальных товаров</div>
                                    <p>Общий остаток: ${stat.total_present || 0}</p>
                                    <p>Последнее обновление: ${stat.last_data_sync || 'Нет данных'}</p>
                                </div>
                            `;
                        });
                    }
                    
                    html += '</div>';
                    container.innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('sync-stats').innerHTML = `<div class="badge-error">Ошибка загрузки: ${error}</div>`;
                });
        }
        
        function loadCronStatus() {
            fetch('/api/status')
                .then(response => response.json())
                .then(jobs => {
                    const container = document.getElementById('cron-status');
                    
                    let html = '<table class="table"><thead><tr><th>Задача</th><th>Расписание</th><th>Статус</th><th>Последний запуск</th><th>Описание</th></tr></thead><tbody>';
                    
                    jobs.forEach(job => {
                        const statusBadge = getStatusBadge(job.last_status, job.last_exit_code);
                        const lastRun = job.last_run ? new Date(job.last_run).toLocaleString('ru-RU') : 'Никогда';
                        
                        html += `
                            <tr>
                                <td><strong>${job.job_name}</strong></td>
                                <td><code>${job.cron_expression}</code></td>
                                <td>${statusBadge}</td>
                                <td>${lastRun}</td>
                                <td>${job.description || ''}</td>
                            </tr>
                        `;
                    });
                    
                    html += '</tbody></table>';
                    container.innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('cron-status').innerHTML = `<div class="badge-error">Ошибка загрузки: ${error}</div>`;
                });
        }
        
        function loadRecentExecutions() {
            fetch('/api/executions')
                .then(response => response.json())
                .then(executions => {
                    const container = document.getElementById('recent-executions');
                    
                    let html = '<table class="table"><thead><tr><th>Задача</th><th>Время запуска</th><th>Время завершения</th><th>Статус</th><th>Код выхода</th></tr></thead><tbody>';
                    
                    executions.forEach(exec => {
                        const statusBadge = getStatusBadge(exec.status, exec.exit_code);
                        const startedAt = new Date(exec.started_at).toLocaleString('ru-RU');
                        const completedAt = exec.completed_at ? new Date(exec.completed_at).toLocaleString('ru-RU') : 'В процессе';
                        
                        html += `
                            <tr>
                                <td>${exec.job_name}</td>
                                <td>${startedAt}</td>
                                <td>${completedAt}</td>
                                <td>${statusBadge}</td>
                                <td>${exec.exit_code !== null ? exec.exit_code : '-'}</td>
                            </tr>
                        `;
                    });
                    
                    html += '</tbody></table>';
                    container.innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('recent-executions').innerHTML = `<div class="badge-error">Ошибка загрузки: ${error}</div>`;
                });
        }
        
        function getStatusBadge(status, exitCode) {
            if (!status) return '<span class="badge badge-unknown">Неизвестно</span>';
            
            if (status === 'success' || exitCode === 0) {
                return '<span class="badge badge-success">Успех</span>';
            } else if (status === 'warning' || exitCode === 1) {
                return '<span class="badge badge-warning">Предупреждение</span>';
            } else if (status === 'failed' || status === 'error' || exitCode > 1) {
                return '<span class="badge badge-error">Ошибка</span>';
            } else {
                return '<span class="badge badge-unknown">Неизвестно</span>';
            }
        }
        
        // Автоматическое обновление каждые 30 секунд
        setInterval(refreshData, 30000);
        
        // Загрузка данных при загрузке страницы
        document.addEventListener('DOMContentLoaded', refreshData);
    </script>
</body>
</html>
        '''


def create_handler_with_dashboard(dashboard):
    """Создание обработчика с привязкой к дашборду."""
    def handler(*args, **kwargs):
        return DashboardHandler(*args, dashboard=dashboard, **kwargs)
    return handler


def main():
    """Главная функция запуска дашборда."""
    dashboard = CronDashboard()
    
    # Настройки сервера
    host = '0.0.0.0'
    port = 8080
    
    # Создание HTTP сервера
    handler = create_handler_with_dashboard(dashboard)
    server = HTTPServer((host, port), handler)
    
    print(f"🚀 Дашборд мониторинга cron задач запущен")
    print(f"📊 Адрес: http://{host}:{port}")
    print(f"🔄 Автоматическое обновление каждые 30 секунд")
    print(f"⏹️  Для остановки нажмите Ctrl+C")
    
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n🛑 Остановка сервера...")
        server.shutdown()
        server.server_close()
        print("✅ Сервер остановлен")


if __name__ == "__main__":
    main()