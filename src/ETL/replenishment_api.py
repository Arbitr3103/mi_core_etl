#!/usr/bin/env python3
"""
REST API для системы пополнения склада.
Предоставляет эндпоинты для доступа к рекомендациям, алертам и отчетам.
"""

import sys
import os
import logging
import json
from datetime import datetime, timedelta
from typing import Dict, List, Optional
from flask import Flask, request, jsonify, render_template_string
from flask_cors import CORS

# Добавляем путь к модулям
sys.path.append(os.path.dirname(__file__))

from replenishment_recommender import ReplenishmentRecommender, PriorityLevel
from alert_manager import AlertManager
from reporting_engine import ReportingEngine
from replenishment_orchestrator import ReplenishmentOrchestrator

# Настройка логирования
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Создаем Flask приложение
app = Flask(__name__)
CORS(app)  # Разрешаем CORS для веб-интерфейса

# Глобальные объекты (в продакшене лучше использовать dependency injection)
recommender = None
alert_manager = None
reporting_engine = None
orchestrator = None


def init_components():
    """Инициализация компонентов системы."""
    global recommender, alert_manager, reporting_engine, orchestrator
    
    try:
        recommender = ReplenishmentRecommender()
        alert_manager = AlertManager()
        reporting_engine = ReportingEngine()
        orchestrator = ReplenishmentOrchestrator()
        logger.info("✅ Компоненты API инициализированы")
    except Exception as e:
        logger.error(f"❌ Ошибка инициализации компонентов: {e}")


@app.route('/')
def index():
    """Главная страница с веб-интерфейсом."""
    html_template = """
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
            .btn-danger { background: #dc3545; }
            .metrics { display: flex; flex-wrap: wrap; gap: 20px; }
            .metric { background: #f8f9fa; padding: 15px; border-radius: 5px; min-width: 150px; }
            .metric-value { font-size: 24px; font-weight: bold; color: #007bff; }
            .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .table th { background-color: #f8f9fa; }
            .critical { color: #dc3545; font-weight: bold; }
            .high { color: #fd7e14; font-weight: bold; }
            .medium { color: #ffc107; }
            .loading { display: none; text-align: center; padding: 20px; }
            .error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>📦 Система пополнения склада</h1>
                <p>Управление запасами и рекомендации по пополнению</p>
            </div>
            
            <div class="card">
                <h2>🎛️ Панель управления</h2>
                <button class="btn" onclick="loadRecommendations()">📋 Загрузить рекомендации</button>
                <button class="btn btn-warning" onclick="loadAlerts()">🚨 Показать алерты</button>
                <button class="btn btn-success" onclick="runAnalysis()">🔄 Запустить анализ</button>
                <button class="btn" onclick="loadReport()">📊 Создать отчет</button>
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
            
            async function loadRecommendations() {
                showLoading();
                try {
                    const response = await fetch('/api/recommendations?limit=20');
                    const data = await response.json();
                    
                    if (data.error) {
                        showError('Ошибка загрузки рекомендаций: ' + data.error);
                        return;
                    }
                    
                    let html = '<div class="card"><h2>📋 Рекомендации по пополнению</h2>';
                    
                    if (data.recommendations && data.recommendations.length > 0) {
                        html += '<table class="table"><thead><tr>';
                        html += '<th>SKU</th><th>Товар</th><th>Приоритет</th><th>Остаток</th>';
                        html += '<th>К заказу</th><th>Дней до исчерпания</th><th>Срочность</th></tr></thead><tbody>';
                        
                        data.recommendations.forEach(rec => {
                            const priorityClass = rec.priority_level.toLowerCase();
                            html += `<tr>
                                <td>${rec.sku}</td>
                                <td>${rec.product_name.substring(0, 40)}</td>
                                <td class="${priorityClass}">${rec.priority_level}</td>
                                <td>${rec.current_stock}</td>
                                <td><strong>${rec.recommended_order_quantity}</strong></td>
                                <td>${rec.days_until_stockout || 'Н/Д'}</td>
                                <td>${rec.urgency_score}</td>
                            </tr>`;
                        });
                        
                        html += '</tbody></table>';
                    } else {
                        html += '<p>Нет рекомендаций для отображения</p>';
                    }
                    
                    html += '</div>';
                    document.getElementById('content').innerHTML = html;
                    
                } catch (error) {
                    showError('Ошибка сети: ' + error.message);
                }
                hideLoading();
            }
            
            async function loadAlerts() {
                showLoading();
                try {
                    const response = await fetch('/api/alerts');
                    const data = await response.json();
                    
                    if (data.error) {
                        showError('Ошибка загрузки алертов: ' + data.error);
                        return;
                    }
                    
                    let html = '<div class="card"><h2>🚨 Активные алерты</h2>';
                    
                    if (data.alerts && data.alerts.length > 0) {
                        html += '<table class="table"><thead><tr>';
                        html += '<th>SKU</th><th>Сообщение</th><th>Уровень</th><th>Действие</th></tr></thead><tbody>';
                        
                        data.alerts.forEach(alert => {
                            const levelClass = alert.alert_level.toLowerCase();
                            html += `<tr>
                                <td>${alert.sku}</td>
                                <td>${alert.message}</td>
                                <td class="${levelClass}">${alert.alert_level}</td>
                                <td>${alert.recommended_action}</td>
                            </tr>`;
                        });
                        
                        html += '</tbody></table>';
                    } else {
                        html += '<p>Нет активных алертов</p>';
                    }
                    
                    html += '</div>';
                    document.getElementById('content').innerHTML = html;
                    
                } catch (error) {
                    showError('Ошибка сети: ' + error.message);
                }
                hideLoading();
            }
            
            async function runAnalysis() {
                showLoading();
                try {
                    const response = await fetch('/api/analysis/run', { method: 'POST' });
                    const data = await response.json();
                    
                    if (data.error) {
                        showError('Ошибка запуска анализа: ' + data.error);
                        return;
                    }
                    
                    let html = '<div class="card"><h2>🔄 Результаты анализа</h2>';
                    html += '<div class="metrics">';
                    html += `<div class="metric"><div class="metric-value">${data.products_analyzed || 0}</div><div>Товаров проанализировано</div></div>`;
                    html += `<div class="metric"><div class="metric-value">${data.recommendations_generated || 0}</div><div>Рекомендаций создано</div></div>`;
                    html += `<div class="metric"><div class="metric-value">${data.alerts_created || 0}</div><div>Алертов создано</div></div>`;
                    html += '</div>';
                    html += `<p><strong>Статус:</strong> ${data.status || 'Неизвестно'}</p>`;
                    html += '</div>';
                    
                    document.getElementById('content').innerHTML = html;
                    
                } catch (error) {
                    showError('Ошибка сети: ' + error.message);
                }
                hideLoading();
            }
            
            async function loadReport() {
                showLoading();
                try {
                    const response = await fetch('/api/reports/comprehensive');
                    const data = await response.json();
                    
                    if (data.error) {
                        showError('Ошибка создания отчета: ' + data.error);
                        return;
                    }
                    
                    let html = '<div class="card"><h2>📊 Комплексный отчет</h2>';
                    
                    if (data.inventory_metrics) {
                        html += '<h3>📦 Метрики запасов</h3>';
                        html += '<div class="metrics">';
                        html += `<div class="metric"><div class="metric-value">${data.inventory_metrics.total_products}</div><div>Всего товаров</div></div>`;
                        html += `<div class="metric"><div class="metric-value">${Math.round(data.inventory_metrics.total_inventory_value).toLocaleString()}</div><div>Стоимость запасов (руб)</div></div>`;
                        html += `<div class="metric"><div class="metric-value">${data.inventory_metrics.low_stock_products}</div><div>Низкий остаток</div></div>`;
                        html += `<div class="metric"><div class="metric-value">${data.inventory_metrics.total_recommended_orders}</div><div>Рекомендуемых заказов</div></div>`;
                        html += '</div>';
                    }
                    
                    if (data.sales_metrics) {
                        html += '<h3>📈 Метрики продаж</h3>';
                        html += '<div class="metrics">';
                        html += `<div class="metric"><div class="metric-value">${data.sales_metrics.total_sales_volume_30d}</div><div>Продаж за 30 дней</div></div>`;
                        html += `<div class="metric"><div class="metric-value">${data.sales_metrics.fast_moving_products}</div><div>Быстро движущихся</div></div>`;
                        html += `<div class="metric"><div class="metric-value">${data.sales_metrics.slow_moving_products}</div><div>Медленно движущихся</div></div>`;
                        html += `<div class="metric"><div class="metric-value">${data.sales_metrics.no_sales_products}</div><div>Без продаж</div></div>`;
                        html += '</div>';
                    }
                    
                    html += '</div>';
                    document.getElementById('content').innerHTML = html;
                    
                } catch (error) {
                    showError('Ошибка сети: ' + error.message);
                }
                hideLoading();
            }
            
            // Автоматически загружаем рекомендации при загрузке страницы
            window.onload = function() {
                loadRecommendations();
            };
        </script>
    </body>
    </html>
    """
    return render_template_string(html_template)


# API эндпоинты

@app.route('/api/recommendations')
def get_recommendations():
    """Получить рекомендации по пополнению."""
    try:
        limit = request.args.get('limit', 50, type=int)
        priority_filter = request.args.get('priority', None)
        source = request.args.get('source', None)
        
        if not recommender:
            return jsonify({'error': 'Система не инициализирована'}), 500
        
        # Получаем рекомендации из БД
        if priority_filter:
            recommendations = recommender.get_critical_recommendations(limit)
            # Фильтруем по приоритету если нужно
            if priority_filter != 'ALL':
                recommendations = [r for r in recommendations if r.priority_level.value == priority_filter]
        else:
            recommendations = recommender.get_critical_recommendations(limit)
        
        # Преобразуем в JSON-совместимый формат
        recommendations_data = []
        for rec in recommendations:
            rec_data = {
                'product_id': rec.product_id,
                'sku': rec.sku,
                'product_name': rec.product_name,
                'source': rec.source,
                'current_stock': rec.current_stock,
                'available_stock': rec.available_stock,
                'recommended_order_quantity': rec.recommended_order_quantity,
                'recommended_order_value': rec.recommended_order_value,
                'priority_level': rec.priority_level.value,
                'urgency_score': rec.urgency_score,
                'days_until_stockout': rec.days_until_stockout,
                'daily_sales_rate_7d': rec.daily_sales_rate_7d,
                'sales_trend': rec.sales_trend.value,
                'analysis_date': rec.analysis_date.strftime('%Y-%m-%d %H:%M:%S')
            }
            recommendations_data.append(rec_data)
        
        return jsonify({
            'recommendations': recommendations_data,
            'total_count': len(recommendations_data),
            'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        })
        
    except Exception as e:
        logger.error(f"Ошибка получения рекомендаций: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/recommendations/<int:product_id>')
def get_product_recommendation(product_id):
    """Получить рекомендацию для конкретного товара."""
    try:
        if not recommender:
            return jsonify({'error': 'Система не инициализирована'}), 500
        
        # Получаем все рекомендации и ищем нужную
        recommendations = recommender.get_critical_recommendations(1000)
        product_rec = next((r for r in recommendations if r.product_id == product_id), None)
        
        if not product_rec:
            return jsonify({'error': 'Рекомендация не найдена'}), 404
        
        rec_data = {
            'product_id': product_rec.product_id,
            'sku': product_rec.sku,
            'product_name': product_rec.product_name,
            'source': product_rec.source,
            'current_stock': product_rec.current_stock,
            'reserved_stock': product_rec.reserved_stock,
            'available_stock': product_rec.available_stock,
            'recommended_order_quantity': product_rec.recommended_order_quantity,
            'recommended_order_value': product_rec.recommended_order_value,
            'priority_level': product_rec.priority_level.value,
            'urgency_score': product_rec.urgency_score,
            'days_until_stockout': product_rec.days_until_stockout,
            'daily_sales_rate_7d': product_rec.daily_sales_rate_7d,
            'daily_sales_rate_14d': product_rec.daily_sales_rate_14d,
            'daily_sales_rate_30d': product_rec.daily_sales_rate_30d,
            'sales_trend': product_rec.sales_trend.value,
            'inventory_turnover_days': product_rec.inventory_turnover_days,
            'min_stock_level': product_rec.min_stock_level,
            'reorder_point': product_rec.reorder_point,
            'lead_time_days': product_rec.lead_time_days,
            'analysis_date': product_rec.analysis_date.strftime('%Y-%m-%d %H:%M:%S')
        }
        
        return jsonify(rec_data)
        
    except Exception as e:
        logger.error(f"Ошибка получения рекомендации для товара {product_id}: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/alerts')
def get_alerts():
    """Получить активные алерты."""
    try:
        limit = request.args.get('limit', 50, type=int)
        
        if not alert_manager:
            return jsonify({'error': 'Система не инициализирована'}), 500
        
        # Получаем активные алерты
        alerts = alert_manager.get_active_alerts(limit)
        
        return jsonify({
            'alerts': alerts,
            'total_count': len(alerts),
            'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        })
        
    except Exception as e:
        logger.error(f"Ошибка получения алертов: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/alerts/<int:alert_id>/acknowledge', methods=['POST'])
def acknowledge_alert(alert_id):
    """Подтвердить обработку алерта."""
    try:
        data = request.get_json() or {}
        acknowledged_by = data.get('acknowledged_by', 'API User')
        
        if not alert_manager:
            return jsonify({'error': 'Система не инициализирована'}), 500
        
        success = alert_manager.acknowledge_alert(alert_id, acknowledged_by)
        
        if success:
            return jsonify({'message': 'Алерт подтвержден', 'alert_id': alert_id})
        else:
            return jsonify({'error': 'Ошибка подтверждения алерта'}), 500
        
    except Exception as e:
        logger.error(f"Ошибка подтверждения алерта {alert_id}: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/analysis/run', methods=['POST'])
def run_analysis():
    """Запустить анализ пополнения."""
    try:
        data = request.get_json() or {}
        source = data.get('source', None)
        save_to_db = data.get('save_to_db', True)
        send_alerts = data.get('send_alerts', True)
        
        if not orchestrator:
            return jsonify({'error': 'Система не инициализирована'}), 500
        
        # Запускаем быстрый анализ (для API лучше не делать полный)
        result = orchestrator.run_quick_check()
        
        return jsonify(result)
        
    except Exception as e:
        logger.error(f"Ошибка запуска анализа: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/reports/comprehensive')
def get_comprehensive_report():
    """Получить комплексный отчет."""
    try:
        source = request.args.get('source', None)
        
        if not reporting_engine:
            return jsonify({'error': 'Система не инициализирована'}), 500
        
        report = reporting_engine.create_comprehensive_report(source)
        
        return jsonify(report)
        
    except Exception as e:
        logger.error(f"Ошибка создания отчета: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/reports/export/<format>')
def export_report(format):
    """Экспортировать отчет в указанном формате."""
    try:
        if format not in ['json', 'csv']:
            return jsonify({'error': 'Неподдерживаемый формат'}), 400
        
        source = request.args.get('source', None)
        
        if not reporting_engine:
            return jsonify({'error': 'Система не инициализирована'}), 500
        
        # Создаем отчет
        report = reporting_engine.create_comprehensive_report(source)
        
        if 'error' in report:
            return jsonify(report), 500
        
        # Генерируем имя файла
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        filename = f"replenishment_report_{timestamp}.{format}"
        
        # Экспортируем в файл
        if format == 'json':
            success = reporting_engine.export_report_to_json(report, filename)
        else:  # csv
            success = reporting_engine.export_report_to_csv(report, filename)
        
        if success:
            return jsonify({
                'message': f'Отчет экспортирован в {format.upper()}',
                'filename': filename,
                'generated_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            })
        else:
            return jsonify({'error': 'Ошибка экспорта отчета'}), 500
        
    except Exception as e:
        logger.error(f"Ошибка экспорта отчета: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/health')
def health_check():
    """Проверка состояния API."""
    try:
        status = {
            'status': 'healthy',
            'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'components': {
                'recommender': recommender is not None,
                'alert_manager': alert_manager is not None,
                'reporting_engine': reporting_engine is not None,
                'orchestrator': orchestrator is not None
            }
        }
        
        # Проверяем подключение к БД
        try:
            if recommender and recommender.connection:
                cursor = recommender.connection.cursor()
                cursor.execute("SELECT 1")
                cursor.close()
                status['components']['database'] = True
            else:
                status['components']['database'] = False
        except:
            status['components']['database'] = False
        
        # Определяем общий статус
        all_healthy = all(status['components'].values())
        status['status'] = 'healthy' if all_healthy else 'degraded'
        
        return jsonify(status)
        
    except Exception as e:
        return jsonify({
            'status': 'unhealthy',
            'error': str(e),
            'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        }), 500


@app.errorhandler(404)
def not_found(error):
    """Обработчик 404 ошибки."""
    return jsonify({'error': 'Эндпоинт не найден'}), 404


@app.errorhandler(500)
def internal_error(error):
    """Обработчик 500 ошибки."""
    return jsonify({'error': 'Внутренняя ошибка сервера'}), 500


def main():
    """Запуск API сервера."""
    print("🚀 ЗАПУСК API СИСТЕМЫ ПОПОЛНЕНИЯ СКЛАДА")
    print("=" * 60)
    
    # Инициализируем компоненты
    print("🔧 Инициализация компонентов...")
    init_components()
    
    # Запускаем сервер
    print("🌐 Запуск веб-сервера...")
    print("   URL: http://localhost:5000")
    print("   API документация: http://localhost:5000/api/health")
    print("   Веб-интерфейс: http://localhost:5000")
    print("\n📋 Доступные эндпоинты:")
    print("   GET  /api/recommendations - Получить рекомендации")
    print("   GET  /api/alerts - Получить алерты")
    print("   POST /api/analysis/run - Запустить анализ")
    print("   GET  /api/reports/comprehensive - Комплексный отчет")
    print("   GET  /api/health - Проверка состояния")
    print("\n⏹️  Для остановки нажмите Ctrl+C")
    
    try:
        app.run(host='0.0.0.0', port=5000, debug=False)
    except KeyboardInterrupt:
        print("\n⏹️  Сервер остановлен")
    except Exception as e:
        print(f"\n❌ Ошибка запуска сервера: {e}")


if __name__ == "__main__":
    main()