#!/usr/bin/env python3
"""
Простой HTTP API сервер для системы пополнения склада.
Использует встроенный http.server без внешних зависимостей.
"""

import sys
import os
import json
import logging
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
import threading

# Добавляем путь к модулям
sys.path.append(os.path.dirname(__file__))

from replenishment_recommender import ReplenishmentRecommender
from alert_manager import AlertManager
from reporting_engine import ReportingEngine
from replenishment_orchestrator import ReplenishmentOrchestrator

# Настройка логирования
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


class ReplenishmentAPIHandler(BaseHTTPRequestHandler):
    """HTTP обработчик для API системы пополнения."""
    
    # Глобальные компоненты (инициализируются при запуске сервера)
    recommender = None
    alert_manager = None
    reporting_engine = None
    orchestrator = None
    
    def do_GET(self):
        """Обработка GET запросов."""
        try:
            parsed_url = urlparse(self.path)
            path = parsed_url.path
            query_params = parse_qs(parsed_url.query)
            
            if path == '/':
                self._serve_web_interface()
            elif path == '/api/health':
                self._handle_health_check()
            elif path == '/api/recommendations':
                self._handle_get_recommendations(query_params)
            elif path == '/api/alerts':
                self._handle_get_alerts(query_params)
            elif path == '/api/reports/comprehensive':
                self._handle_get_comprehensive_report(query_params)
            elif path.startswith('/api/recommendations/'):
                product_id = path.split('/')[-1]
                self._handle_get_product_recommendation(product_id)
            else:
                self._send_error(404, 'Эндпоинт не найден')
                
        except Exception as e:
            logger.error(f"Ошибка обработки GET запроса: {e}")
            self._send_error(500, str(e))
    
    def do_POST(self):
        """Обработка POST запросов."""
        try:
            parsed_url = urlparse(self.path)
            path = parsed_url.path
            
            # Читаем тело запроса
            content_length = int(self.headers.get('Content-Length', 0))
            post_data = self.rfile.read(content_length).decode('utf-8') if content_length > 0 else '{}'
            
            try:
                request_data = json.loads(post_data)
            except json.JSONDecodeError:
                request_data = {}
            
            if path == '/api/analysis/run':
                self._handle_run_analysis(request_data)
            elif path.startswith('/api/alerts/') and path.endswith('/acknowledge'):
                alert_id = path.split('/')[-2]
                self._handle_acknowledge_alert(alert_id, request_data)
            else:
                self._send_error(404, 'Эндпоинт не найден')
                
        except Exception as e:
            logger.error(f"Ошибка обработки POST запроса: {e}")
            self._send_error(500, str(e))
    
    def _serve_web_interface(self):
        """Отдача веб-интерфейса."""
        html_content = """
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Система пополнения склада</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
                .container { max-width: 1200px; margin: 0 auto; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                         color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
                .card { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; 
                       box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .btn { background: #007bff; color: white; padding: 10px 20px; border: none; 
                      border-radius: 5px; cursor: pointer; margin: 5px; }
                .btn:hover { background: #0056b3; }
                .btn-success { background: #28a745; }
                .btn-warning { background: #ffc107; color: #212529; }
                .metrics { display: flex; flex-wrap: wrap; gap: 20px; }
                .metric { background: #f8f9fa; padding: 15px; border-radius: 5px; min-width: 150px; }
                .metric-value { font-size: 24px; font-weight: bold; color: #007bff; }
                .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .table th { background-color: #f8f9fa; }
                .critical { color: #dc3545; font-weight: bold; }
                .high { color: #fd7e14; font-weight: bold; }
                .loading { display: none; text-align: center; padding: 20px; }
                .error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>📦 Система пополнения склада</h1>
                    <p>Простой API сервер для управления запасами</p>
                </div>
                
                <div class="card">
                    <h2>🎛️ Панель управления</h2>
                    <button class="btn" onclick="testAPI()">🔍 Тест API</button>
                    <button class="btn btn-success" onclick="checkHealth()">❤️ Проверка здоровья</button>
                    <button class="btn btn-warning" onclick="runQuickAnalysis()">⚡ Быстрый анализ</button>
                </div>
                
                <div id="loading" class="loading">
                    <h3>⏳ Загрузка данных...</h3>
                </div>
                
                <div id="error" class="error" style="display: none;"></div>
                
                <div id="content"></div>
            </div>
            
            <script>
                function showLoading() {
                    document.getElementById('loading').style.display = 'block';
                    document.getElementById('content').innerHTML = '';
                    document.getElementById('error').style.display = 'none';
                }
                
                function hideLoading() {
                    document.getElementById('loading').style.display = 'none';
                }
                
                function showError(message) {
                    document.getElementById('error').innerHTML = message;
                    document.getElementById('error').style.display = 'block';
                    hideLoading();
                }
                
                async function testAPI() {
                    showLoading();
                    try {
                        const response = await fetch('/api/health');
                        const data = await response.json();
                        
                        let html = '<div class="card"><h2>🔍 Тест API</h2>';
                        html += '<h3>Статус системы</h3>';
                        html += '<div class="metrics">';
                        html += `<div class="metric"><div class="metric-value">${data.status}</div><div>Общий статус</div></div>`;
                        html += '</div>';
                        
                        if (data.components) {
                            html += '<h3>Компоненты</h3><ul>';
                            for (const [component, status] of Object.entries(data.components)) {
                                const statusIcon = status ? '✅' : '❌';
                                html += `<li>${statusIcon} ${component}: ${status ? 'OK' : 'Недоступен'}</li>`;
                            }
                            html += '</ul>';
                        }
                        
                        html += `<p><small>Проверено: ${data.timestamp}</small></p>`;
                        html += '</div>';
                        
                        document.getElementById('content').innerHTML = html;
                        
                    } catch (error) {
                        showError('Ошибка тестирования API: ' + error.message);
                    }
                    hideLoading();
                }
                
                async function checkHealth() {
                    showLoading();
                    try {
                        const response = await fetch('/api/health');
                        const data = await response.json();
                        
                        let html = '<div class="card"><h2>❤️ Проверка здоровья системы</h2>';
                        
                        const statusColor = data.status === 'healthy' ? '#28a745' : 
                                          data.status === 'degraded' ? '#ffc107' : '#dc3545';
                        
                        html += `<div class="metric" style="background-color: ${statusColor}; color: white;">`;
                        html += `<div class="metric-value">${data.status.toUpperCase()}</div>`;
                        html += '<div>Статус системы</div></div>';
                        
                        html += '<h3>Детали компонентов:</h3>';
                        html += '<table class="table"><thead><tr><th>Компонент</th><th>Статус</th></tr></thead><tbody>';
                        
                        if (data.components) {
                            for (const [component, status] of Object.entries(data.components)) {
                                const statusText = status ? 'Работает' : 'Недоступен';
                                const statusClass = status ? 'text-success' : 'text-danger';
                                html += `<tr><td>${component}</td><td class="${statusClass}">${statusText}</td></tr>`;
                            }
                        }
                        
                        html += '</tbody></table>';
                        html += '</div>';
                        
                        document.getElementById('content').innerHTML = html;
                        
                    } catch (error) {
                        showError('Ошибка проверки здоровья: ' + error.message);
                    }
                    hideLoading();
                }
                
                async function runQuickAnalysis() {
                    showLoading();
                    try {
                        const response = await fetch('/api/analysis/run', { 
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({})
                        });
                        const data = await response.json();
                        
                        let html = '<div class="card"><h2>⚡ Результаты быстрого анализа</h2>';
                        
                        if (data.error) {
                            html += `<div class="error">Ошибка: ${data.error}</div>`;
                        } else {
                            html += '<div class="metrics">';
                            html += `<div class="metric"><div class="metric-value">${data.execution_time || 0}</div><div>Время выполнения (сек)</div></div>`;
                            html += `<div class="metric"><div class="metric-value">${data.critical_recommendations || 0}</div><div>Критических товаров</div></div>`;
                            html += `<div class="metric"><div class="metric-value">${data.critical_alerts || 0}</div><div>Алертов создано</div></div>`;
                            html += '</div>';
                            html += `<p><strong>Статус:</strong> ${data.status || 'Неизвестно'}</p>`;
                        }
                        
                        html += '</div>';
                        document.getElementById('content').innerHTML = html;
                        
                    } catch (error) {
                        showError('Ошибка запуска анализа: ' + error.message);
                    }
                    hideLoading();
                }
                
                // Автоматически проверяем здоровье при загрузке
                window.onload = function() {
                    checkHealth();
                };
            </script>
        </body>
        </html>
        """
        
        self._send_response(200, html_content, 'text/html; charset=utf-8')
    
    def _handle_health_check(self):
        """Обработка проверки здоровья системы."""
        try:
            status = {
                'status': 'healthy',
                'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                'components': {
                    'recommender': self.recommender is not None,
                    'alert_manager': self.alert_manager is not None,
                    'reporting_engine': self.reporting_engine is not None,
                    'orchestrator': self.orchestrator is not None
                }
            }
            
            # Проверяем подключение к БД (упрощенно)
            try:
                if self.recommender and hasattr(self.recommender, 'connection'):
                    status['components']['database'] = True
                else:
                    status['components']['database'] = False
            except:
                status['components']['database'] = False
            
            # Определяем общий статус
            all_healthy = all(status['components'].values())
            status['status'] = 'healthy' if all_healthy else 'degraded'
            
            self._send_json_response(200, status)
            
        except Exception as e:
            self._send_json_response(500, {
                'status': 'unhealthy',
                'error': str(e),
                'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            })
    
    def _handle_get_recommendations(self, query_params):
        """Обработка получения рекомендаций."""
        try:
            limit = int(query_params.get('limit', [50])[0])
            
            if not self.recommender:
                self._send_json_response(500, {'error': 'Система не инициализирована'})
                return
            
            # Имитируем получение рекомендаций
            recommendations_data = [
                {
                    'product_id': 1,
                    'sku': 'DEMO-001',
                    'product_name': 'Демо товар 1',
                    'source': 'demo',
                    'current_stock': 5,
                    'available_stock': 3,
                    'recommended_order_quantity': 50,
                    'recommended_order_value': 25000.0,
                    'priority_level': 'CRITICAL',
                    'urgency_score': 95.0,
                    'days_until_stockout': 2,
                    'daily_sales_rate_7d': 2.5,
                    'sales_trend': 'STABLE',
                    'analysis_date': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                },
                {
                    'product_id': 2,
                    'sku': 'DEMO-002',
                    'product_name': 'Демо товар 2',
                    'source': 'demo',
                    'current_stock': 15,
                    'available_stock': 12,
                    'recommended_order_quantity': 30,
                    'recommended_order_value': 15000.0,
                    'priority_level': 'HIGH',
                    'urgency_score': 75.0,
                    'days_until_stockout': 5,
                    'daily_sales_rate_7d': 2.0,
                    'sales_trend': 'GROWING',
                    'analysis_date': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                }
            ]
            
            response = {
                'recommendations': recommendations_data[:limit],
                'total_count': len(recommendations_data),
                'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            }
            
            self._send_json_response(200, response)
            
        except Exception as e:
            self._send_json_response(500, {'error': str(e)})
    
    def _handle_get_alerts(self, query_params):
        """Обработка получения алертов."""
        try:
            limit = int(query_params.get('limit', [50])[0])
            
            # Имитируем получение алертов
            alerts_data = [
                {
                    'id': 1,
                    'sku': 'DEMO-001',
                    'product_name': 'Демо товар 1',
                    'alert_type': 'STOCKOUT_CRITICAL',
                    'alert_level': 'CRITICAL',
                    'message': 'Критический остаток товара DEMO-001',
                    'current_stock': 5,
                    'days_until_stockout': 2,
                    'recommended_action': 'Срочно заказать 50 шт',
                    'status': 'NEW',
                    'created_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                }
            ]
            
            response = {
                'alerts': alerts_data[:limit],
                'total_count': len(alerts_data),
                'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            }
            
            self._send_json_response(200, response)
            
        except Exception as e:
            self._send_json_response(500, {'error': str(e)})
    
    def _handle_get_comprehensive_report(self, query_params):
        """Обработка получения комплексного отчета."""
        try:
            # Имитируем создание отчета
            report = {
                'report_metadata': {
                    'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                    'source_filter': 'Все источники',
                    'report_type': 'Демо отчет'
                },
                'inventory_metrics': {
                    'total_products': 100,
                    'total_inventory_value': 500000.0,
                    'low_stock_products': 15,
                    'zero_stock_products': 5,
                    'overstocked_products': 10,
                    'avg_inventory_turnover_days': 45.5,
                    'total_recommended_orders': 25,
                    'total_recommended_value': 150000.0
                },
                'sales_metrics': {
                    'total_sales_volume_30d': 1500,
                    'total_sales_value_30d': 750000.0,
                    'avg_daily_sales': 50.0,
                    'fast_moving_products': 20,
                    'slow_moving_products': 30,
                    'no_sales_products': 10
                }
            }
            
            self._send_json_response(200, report)
            
        except Exception as e:
            self._send_json_response(500, {'error': str(e)})
    
    def _handle_get_product_recommendation(self, product_id):
        """Обработка получения рекомендации для товара."""
        try:
            # Имитируем получение рекомендации для товара
            if product_id == '1':
                rec_data = {
                    'product_id': 1,
                    'sku': 'DEMO-001',
                    'product_name': 'Демо товар 1',
                    'source': 'demo',
                    'current_stock': 5,
                    'available_stock': 3,
                    'recommended_order_quantity': 50,
                    'priority_level': 'CRITICAL',
                    'urgency_score': 95.0,
                    'analysis_date': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                }
                self._send_json_response(200, rec_data)
            else:
                self._send_json_response(404, {'error': 'Рекомендация не найдена'})
                
        except Exception as e:
            self._send_json_response(500, {'error': str(e)})
    
    def _handle_run_analysis(self, request_data):
        """Обработка запуска анализа."""
        try:
            # Имитируем запуск быстрого анализа
            result = {
                'execution_time': 0.5,
                'critical_recommendations': 5,
                'critical_alerts': 3,
                'status': 'SUCCESS'
            }
            
            self._send_json_response(200, result)
            
        except Exception as e:
            self._send_json_response(500, {'error': str(e)})
    
    def _handle_acknowledge_alert(self, alert_id, request_data):
        """Обработка подтверждения алерта."""
        try:
            acknowledged_by = request_data.get('acknowledged_by', 'API User')
            
            response = {
                'message': 'Алерт подтвержден',
                'alert_id': int(alert_id),
                'acknowledged_by': acknowledged_by,
                'acknowledged_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            }
            
            self._send_json_response(200, response)
            
        except Exception as e:
            self._send_json_response(500, {'error': str(e)})
    
    def _send_response(self, status_code, content, content_type='application/json'):
        """Отправка HTTP ответа."""
        self.send_response(status_code)
        self.send_header('Content-Type', content_type)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()
        self.wfile.write(content.encode('utf-8'))
    
    def _send_json_response(self, status_code, data):
        """Отправка JSON ответа."""
        json_content = json.dumps(data, ensure_ascii=False, indent=2)
        self._send_response(status_code, json_content, 'application/json')
    
    def _send_error(self, status_code, message):
        """Отправка ошибки."""
        error_data = {'error': message, 'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
        self._send_json_response(status_code, error_data)
    
    def do_OPTIONS(self):
        """Обработка OPTIONS запросов для CORS."""
        self.send_response(200)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()
    
    def log_message(self, format, *args):
        """Переопределяем логирование для более красивого вывода."""
        logger.info(f"{self.address_string()} - {format % args}")


def init_components():
    """Инициализация компонентов системы."""
    try:
        ReplenishmentAPIHandler.recommender = ReplenishmentRecommender()
        ReplenishmentAPIHandler.alert_manager = AlertManager()
        ReplenishmentAPIHandler.reporting_engine = ReportingEngine()
        ReplenishmentAPIHandler.orchestrator = ReplenishmentOrchestrator()
        logger.info("✅ Компоненты API инициализированы")
        return True
    except Exception as e:
        logger.error(f"❌ Ошибка инициализации компонентов: {e}")
        logger.info("⚠️  API будет работать в демо-режиме")
        return False


def main():
    """Запуск простого API сервера."""
    print("🚀 ЗАПУСК ПРОСТОГО API СЕРВЕРА СИСТЕМЫ ПОПОЛНЕНИЯ СКЛАДА")
    print("=" * 70)
    
    # Инициализируем компоненты
    print("🔧 Инициализация компонентов...")
    components_ok = init_components()
    
    if not components_ok:
        print("⚠️  Компоненты не инициализированы - работаем в демо-режиме")
    
    # Настройки сервера
    host = '0.0.0.0'
    port = 8000
    
    # Создаем и запускаем сервер
    try:
        server = HTTPServer((host, port), ReplenishmentAPIHandler)
        
        print(f"🌐 Сервер запущен на http://{host}:{port}")
        print(f"   Веб-интерфейс: http://localhost:{port}")
        print(f"   API здоровья: http://localhost:{port}/api/health")
        print(f"   Рекомендации: http://localhost:{port}/api/recommendations")
        print(f"   Алерты: http://localhost:{port}/api/alerts")
        print("\n⏹️  Для остановки нажмите Ctrl+C")
        
        server.serve_forever()
        
    except KeyboardInterrupt:
        print("\n⏹️  Сервер остановлен пользователем")
    except Exception as e:
        print(f"\n❌ Ошибка запуска сервера: {e}")
    finally:
        try:
            server.server_close()
        except:
            pass


if __name__ == "__main__":
    main()