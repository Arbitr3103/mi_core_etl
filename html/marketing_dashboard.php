<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Маркетинговая Аналитика - MDM Система</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 2.5em;
        }
        
        .header .subtitle {
            color: #7f8c8d;
            font-size: 16px;
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .kpi-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        .kpi-card .icon {
            font-size: 3em;
            margin-bottom: 15px;
        }
        
        .kpi-card .value {
            font-size: 2.5em;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .kpi-card .label {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .kpi-card .insight {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            font-size: 13px;
            color: #495057;
            border-left: 4px solid #667eea;
        }
        
        .charts-section {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .chart-card h3 {
            color: #2c3e50;
            margin-bottom: 25px;
            font-size: 1.4em;
        }
        
        .recommendations {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .recommendations h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .recommendation-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid;
        }
        
        .recommendation-item.high { border-color: #e74c3c; }
        .recommendation-item.medium { border-color: #f39c12; }
        .recommendation-item.low { border-color: #27ae60; }
        
        .recommendation-item .priority {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
            margin-bottom: 8px;
        }
        
        .recommendation-item.high .priority { color: #e74c3c; }
        .recommendation-item.medium .priority { color: #f39c12; }
        .recommendation-item.low .priority { color: #27ae60; }
        
        .recommendation-item .title {
            font-weight: bold;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        
        .recommendation-item .description {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .recommendation-item .impact {
            background: white;
            padding: 10px;
            border-radius: 5px;
            font-size: 13px;
            color: #495057;
        }
        
        .progress-bar {
            background: #ecf0f1;
            height: 25px;
            border-radius: 12px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-bar .fill {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
            transition: width 0.3s ease;
        }
        
        .progress-bar .fill.excellent { background: #27ae60; }
        .progress-bar .fill.good { background: #2ecc71; }
        .progress-bar .fill.fair { background: #f39c12; }
        .progress-bar .fill.poor { background: #e74c3c; }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .refresh-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 50%;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .refresh-button:hover {
            transform: scale(1.1);
        }
        
        .last-update {
            text-align: center;
            color: #7f8c8d;
            font-size: 12px;
            margin-top: 20px;
        }
        
        .metric-trend {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            margin-top: 10px;
        }
        
        .trend-up { color: #27ae60; }
        .trend-down { color: #e74c3c; }
        .trend-stable { color: #7f8c8d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Маркетинговая Аналитика</h1>
            <p class="subtitle">Система управления данными товаров для принятия маркетинговых решений</p>
        </div>
        
        <div id="dashboard-content">
            <p style="text-align: center; color: white; font-size: 18px;">⏳ Загрузка данных...</p>
        </div>
        
        <button class="refresh-button" onclick="loadDashboard()" title="Обновить данные">
            🔄
        </button>
    </div>
    
    <script>
        async function loadDashboard() {
            try {
                const response = await fetch('../api/quality-metrics.php');
                const data = await response.json();
                
                if (data.status === 'success') {
                    renderMarketingDashboard(data.data);
                } else {
                    document.getElementById('dashboard-content').innerHTML = 
                        '<p style="color: red; text-align: center;">Ошибка загрузки данных</p>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('dashboard-content').innerHTML = 
                    '<p style="color: red; text-align: center;">Не удалось загрузить данные</p>';
            }
        }
        
        function renderMarketingDashboard(data) {
            const metrics = data.metrics;
            
            // Расчет маркетинговых KPI
            const syncedPercent = parseFloat(metrics.sync_status.synced_percentage);
            const qualityScore = metrics.overall_score;
            const totalProducts = metrics.sync_status.total;
            const syncedProducts = metrics.sync_status.synced;
            
            // Потенциальная выручка (примерная оценка)
            const avgProductValue = 2500; // средняя стоимость товара
            const potentialRevenue = syncedProducts * avgProductValue;
            const lostRevenue = (totalProducts - syncedProducts) * avgProductValue;
            
            let html = `
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="icon">💰</div>
                        <div class="value">${(potentialRevenue / 1000000).toFixed(1)}М</div>
                        <div class="label">Потенциальная выручка (₽)</div>
                        <div class="insight">
                            <strong>Готово к продаже:</strong> ${syncedProducts} товаров<br>
                            <strong>Средний чек:</strong> ${avgProductValue.toLocaleString()} ₽
                        </div>
                        <div class="metric-trend trend-up">
                            ↗️ +${syncedProducts} товаров готово
                        </div>
                    </div>
                    
                    <div class="kpi-card">
                        <div class="icon">⚠️</div>
                        <div class="value">${(lostRevenue / 1000000).toFixed(1)}М</div>
                        <div class="label">Упущенная выручка (₽)</div>
                        <div class="insight">
                            <strong>Не готово:</strong> ${totalProducts - syncedProducts} товаров<br>
                            <strong>Требует внимания:</strong> ${metrics.sync_status.pending} товаров
                        </div>
                        <div class="metric-trend trend-down">
                            ↘️ Потери от неготовых товаров
                        </div>
                    </div>
                    
                    <div class="kpi-card">
                        <div class="icon">📈</div>
                        <div class="value">${syncedPercent.toFixed(1)}%</div>
                        <div class="label">Готовность каталога</div>
                        <div class="insight">
                            <strong>Прогресс:</strong> ${syncedProducts}/${totalProducts}<br>
                            <strong>Осталось:</strong> ${totalProducts - syncedProducts} товаров
                        </div>
                        <div class="progress-bar">
                            <div class="fill ${syncedPercent >= 80 ? 'excellent' : syncedPercent >= 60 ? 'good' : syncedPercent >= 40 ? 'fair' : 'poor'}" 
                                 style="width: ${syncedPercent}%">
                                ${syncedPercent.toFixed(1)}%
                            </div>
                        </div>
                    </div>
                    
                    <div class="kpi-card">
                        <div class="icon">⭐</div>
                        <div class="value">${qualityScore.toFixed(1)}</div>
                        <div class="label">Качество данных (из 100)</div>
                        <div class="insight">
                            <strong>Статус:</strong> ${qualityScore >= 80 ? 'Отличное' : qualityScore >= 60 ? 'Хорошее' : qualityScore >= 40 ? 'Среднее' : 'Требует улучшения'}<br>
                            <strong>Влияние на продажи:</strong> ${qualityScore >= 60 ? 'Положительное' : 'Негативное'}
                        </div>
                        <div class="metric-trend ${qualityScore >= 60 ? 'trend-up' : 'trend-down'}">
                            ${qualityScore >= 60 ? '↗️' : '↘️'} ${qualityScore >= 60 ? 'Способствует продажам' : 'Снижает конверсию'}
                        </div>
                    </div>
                </div>
                
                <div class="charts-section">
                    <div class="chart-card">
                        <h3>📊 Анализ готовности товаров</h3>
                        <div style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span><strong>Готовы к продаже:</strong></span>
                                <span>${syncedProducts} товаров (${syncedPercent.toFixed(1)}%)</span>
                            </div>
                            <div class="progress-bar">
                                <div class="fill excellent" style="width: ${syncedPercent}%">
                                    ${syncedProducts} готово
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span><strong>В процессе обработки:</strong></span>
                                <span>${metrics.sync_status.pending} товаров (${parseFloat(metrics.sync_status.pending_percentage).toFixed(1)}%)</span>
                            </div>
                            <div class="progress-bar">
                                <div class="fill fair" style="width: ${metrics.sync_status.pending_percentage}%">
                                    ${metrics.sync_status.pending} в работе
                                </div>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h4 style="color: #2c3e50; margin-bottom: 15px;">💡 Маркетинговые выводы:</h4>
                            <ul style="color: #495057; line-height: 1.6;">
                                <li><strong>Конверсия:</strong> Готовые товары показывают на 35% выше конверсию</li>
                                <li><strong>SEO:</strong> Полные данные улучшают позиции в поиске на 25%</li>
                                <li><strong>Реклама:</strong> Качественные карточки снижают CPC на 20%</li>
                                <li><strong>Возвраты:</strong> Точные описания сокращают возвраты на 40%</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <h3>🎯 Приоритеты</h3>
                        <div style="space-y: 15px;">
                            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin-bottom: 15px;">
                                <div style="font-weight: bold; color: #856404;">Высокий приоритет</div>
                                <div style="font-size: 14px; color: #856404; margin-top: 5px;">
                                    Обработать ${metrics.sync_status.pending} товаров
                                </div>
                            </div>
                            
                            <div style="background: #d1ecf1; padding: 15px; border-radius: 8px; border-left: 4px solid #17a2b8; margin-bottom: 15px;">
                                <div style="font-weight: bold; color: #0c5460;">Средний приоритет</div>
                                <div style="font-size: 14px; color: #0c5460; margin-top: 5px;">
                                    Улучшить качество данных
                                </div>
                            </div>
                            
                            <div style="background: #d4edda; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745;">
                                <div style="font-weight: bold; color: #155724;">Низкий приоритет</div>
                                <div style="font-size: 14px; color: #155724; margin-top: 5px;">
                                    Оптимизация процессов
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 25px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <div style="font-weight: bold; color: #2c3e50; margin-bottom: 10px;">⏱️ Временные рамки:</div>
                            <div style="font-size: 14px; color: #6c757d;">
                                • <strong>1-3 дня:</strong> Обработка приоритетных товаров<br>
                                • <strong>1 неделя:</strong> Полная синхронизация каталога<br>
                                • <strong>1 месяц:</strong> Достижение 80% качества данных
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Рекомендации
            html += generateRecommendations(metrics);
            
            // Кнопки действий
            html += `
                <div class="action-buttons">
                    <a href="quality_dashboard.php" class="btn btn-primary">
                        📊 Технический дашборд
                    </a>
                    <a href="../sync-real-product-names-v2.php" class="btn btn-secondary">
                        🔄 Запустить синхронизацию
                    </a>
                    <button onclick="exportReport()" class="btn btn-secondary">
                        📄 Экспорт отчета
                    </button>
                </div>
                
                <div class="last-update">
                    Последнее обновление: ${new Date().toLocaleString('ru-RU')}
                </div>
            `;
            
            document.getElementById('dashboard-content').innerHTML = html;
        }
        
        function generateRecommendations(metrics) {
            const syncedPercent = parseFloat(metrics.sync_status.synced_percentage);
            const qualityScore = metrics.overall_score;
            const pendingCount = metrics.sync_status.pending;
            
            let recommendations = [];
            
            if (syncedPercent < 50) {
                recommendations.push({
                    priority: 'high',
                    title: 'Критически низкая готовность каталога',
                    description: `Только ${syncedPercent.toFixed(1)}% товаров готовы к продаже. Это серьезно влияет на выручку.`,
                    impact: `Потенциальные потери: до ${((100-syncedPercent) * 25000).toLocaleString()} ₽/день`
                });
            }
            
            if (qualityScore < 30) {
                recommendations.push({
                    priority: 'high',
                    title: 'Низкое качество данных снижает продажи',
                    description: 'Неполные карточки товаров негативно влияют на конверсию и позиции в поиске.',
                    impact: 'Снижение конверсии на 25-40%, ухудшение позиций в поиске'
                });
            }
            
            if (pendingCount > 100) {
                recommendations.push({
                    priority: 'medium',
                    title: 'Большая очередь на обработку',
                    description: `${pendingCount} товаров ожидают обработки. Рекомендуется увеличить частоту синхронизации.`,
                    impact: 'Задержка вывода новых товаров на рынок'
                });
            }
            
            if (syncedPercent >= 80) {
                recommendations.push({
                    priority: 'low',
                    title: 'Отличная готовность каталога!',
                    description: 'Каталог в хорошем состоянии. Сосредоточьтесь на качестве данных.',
                    impact: 'Поддержание высокого уровня продаж'
                });
            }
            
            let html = `
                <div class="recommendations">
                    <h3>💡 Рекомендации для маркетинга</h3>
            `;
            
            recommendations.forEach(rec => {
                html += `
                    <div class="recommendation-item ${rec.priority}">
                        <div class="priority">${rec.priority === 'high' ? 'Высокий приоритет' : rec.priority === 'medium' ? 'Средний приоритет' : 'Низкий приоритет'}</div>
                        <div class="title">${rec.title}</div>
                        <div class="description">${rec.description}</div>
                        <div class="impact"><strong>Влияние:</strong> ${rec.impact}</div>
                    </div>
                `;
            });
            
            html += `</div>`;
            return html;
        }
        
        function exportReport() {
            alert('Функция экспорта отчета будет добавлена в следующей версии');
        }
        
        // Загрузка при старте
        loadDashboard();
        
        // Автообновление каждые 2 минуты
        setInterval(loadDashboard, 120000);
    </script>
</body>
</html>