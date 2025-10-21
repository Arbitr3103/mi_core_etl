<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MDM Data Quality Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .header .subtitle {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .metric-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .metric-card .label {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .metric-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .metric-card .percentage {
            font-size: 14px;
            color: #7f8c8d;
        }
        
        .metric-card.success .value { color: #27ae60; }
        .metric-card.warning .value { color: #f39c12; }
        .metric-card.danger .value { color: #e74c3c; }
        
        .quality-score {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }
        
        .quality-score .score {
            font-size: 72px;
            font-weight: bold;
            margin: 20px 0;
        }
        
        .quality-score .score.good { color: #27ae60; }
        .quality-score .score.fair { color: #f39c12; }
        .quality-score .score.poor { color: #e74c3c; }
        
        .quality-score .status {
            font-size: 18px;
            color: #7f8c8d;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .chart-card h3 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        
        .progress-bar {
            background: #ecf0f1;
            height: 30px;
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 15px;
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
        
        .progress-bar .fill.success { background: #27ae60; }
        .progress-bar .fill.warning { background: #f39c12; }
        .progress-bar .fill.danger { background: #e74c3c; }
        
        .alerts-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .alerts-section h3 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        
        .alert-item {
            padding: 15px;
            border-left: 4px solid;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .alert-item.critical { border-color: #e74c3c; }
        .alert-item.warning { border-color: #f39c12; }
        .alert-item.info { border-color: #3498db; }
        
        .alert-item .alert-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .alert-item .alert-level {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }
        
        .alert-item.critical .alert-level { color: #e74c3c; }
        .alert-item.warning .alert-level { color: #f39c12; }
        .alert-item.info .alert-level { color: #3498db; }
        
        .alert-item .alert-time {
            color: #7f8c8d;
            font-size: 12px;
        }
        
        .alert-item .alert-message {
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .alert-item .alert-details {
            color: #7f8c8d;
            font-size: 12px;
        }
        
        .refresh-button {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
        
        .refresh-button:hover {
            background: #2980b9;
        }
        
        .last-update {
            color: #7f8c8d;
            font-size: 12px;
            margin-top: 10px;
        }
        
        .error-types-list {
            list-style: none;
        }
        
        .error-types-list li {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            justify-content: space-between;
        }
        
        .error-types-list li:last-child {
            border-bottom: none;
        }
        
        .badge {
            background: #e74c3c;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 MDM Data Quality Dashboard</h1>
            <p class="subtitle">Real-time monitoring of Master Data Management system health and quality</p>
            <button class="refresh-button" onclick="location.reload()">🔄 Refresh</button>
            <div class="last-update" id="lastUpdate"></div>
        </div>
        
        <div id="dashboard-content">
            <p>Loading...</p>
        </div>
    </div>
    
    <script>
        async function loadDashboard() {
            try {
                const response = await fetch('../api/quality-metrics.php');
                const data = await response.json();
                
                if (data.status === 'success') {
                    renderDashboard(data.data);
                    document.getElementById('lastUpdate').textContent = 
                        'Last updated: ' + new Date().toLocaleString();
                } else {
                    document.getElementById('dashboard-content').innerHTML = 
                        '<p style="color: red;">Error loading dashboard data</p>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('dashboard-content').innerHTML = 
                    '<p style="color: red;">Failed to load dashboard data</p>';
            }
        }
        
        function renderDashboard(data) {
            const metrics = data.metrics;
            const alerts = data.recent_alerts || [];
            
            let html = '';
            
            // Overall Quality Score
            const score = metrics.overall_score;
            const scoreClass = score >= 90 ? 'good' : (score >= 70 ? 'fair' : 'poor');
            const scoreStatus = score >= 90 ? 'Excellent' : (score >= 70 ? 'Fair' : 'Poor');
            
            html += `
                <div class="quality-score">
                    <h2>Overall Quality Score</h2>
                    <div class="score ${scoreClass}">${score}/100</div>
                    <div class="status">${scoreStatus}</div>
                </div>
            `;
            
            // Metrics Grid
            html += '<div class="metrics-grid">';
            
            const syncMetrics = metrics.sync_status;
            html += `
                <div class="metric-card success">
                    <div class="label">Synced Products</div>
                    <div class="value">${syncMetrics.synced}</div>
                    <div class="percentage">${syncMetrics.synced_percentage}% of total</div>
                </div>
                
                <div class="metric-card ${syncMetrics.pending_percentage > 10 ? 'warning' : ''}">
                    <div class="label">Pending Sync</div>
                    <div class="value">${syncMetrics.pending}</div>
                    <div class="percentage">${syncMetrics.pending_percentage}% of total</div>
                </div>
                
                <div class="metric-card ${syncMetrics.failed_percentage > 5 ? 'danger' : ''}">
                    <div class="label">Failed Sync</div>
                    <div class="value">${syncMetrics.failed}</div>
                    <div class="percentage">${syncMetrics.failed_percentage}% of total</div>
                </div>
                
                <div class="metric-card">
                    <div class="label">Total Products</div>
                    <div class="value">${syncMetrics.total}</div>
                    <div class="percentage">In system</div>
                </div>
            `;
            
            html += '</div>';
            
            // Charts Grid
            html += '<div class="charts-grid">';
            
            // Data Quality Chart
            const qualityMetrics = metrics.data_quality;
            html += `
                <div class="chart-card">
                    <h3>Data Quality Metrics</h3>
                    
                    <div style="margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span>Real Product Names</span>
                            <span>${qualityMetrics.real_names_percentage}%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="fill ${qualityMetrics.real_names_percentage >= 80 ? 'success' : 'warning'}" 
                                 style="width: ${qualityMetrics.real_names_percentage}%">
                                ${qualityMetrics.with_real_names} products
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span>Products with Brands</span>
                            <span>${qualityMetrics.brands_percentage}%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="fill ${qualityMetrics.brands_percentage >= 70 ? 'success' : 'warning'}" 
                                 style="width: ${qualityMetrics.brands_percentage}%">
                                ${qualityMetrics.with_brands} products
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="color: #7f8c8d; font-size: 12px;">Average Cache Age</div>
                        <div style="font-size: 24px; font-weight: bold; color: #2c3e50;">
                            ${qualityMetrics.avg_cache_age_days || 0} days
                        </div>
                    </div>
                </div>
            `;
            
            // Error Metrics Chart
            const errorMetrics = metrics.error_metrics;
            html += `
                <div class="chart-card">
                    <h3>Error Metrics</h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 5px; text-align: center;">
                            <div style="color: #7f8c8d; font-size: 12px;">Last 24 Hours</div>
                            <div style="font-size: 32px; font-weight: bold; color: ${errorMetrics.total_errors_24h > 10 ? '#e74c3c' : '#27ae60'};">
                                ${errorMetrics.total_errors_24h}
                            </div>
                        </div>
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 5px; text-align: center;">
                            <div style="color: #7f8c8d; font-size: 12px;">Last 7 Days</div>
                            <div style="font-size: 32px; font-weight: bold; color: #2c3e50;">
                                ${errorMetrics.total_errors_7d}
                            </div>
                        </div>
                    </div>
                    
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <div style="color: #7f8c8d; font-size: 12px; margin-bottom: 5px;">Affected Products</div>
                        <div style="font-size: 24px; font-weight: bold; color: #2c3e50;">
                            ${errorMetrics.affected_products}
                        </div>
                    </div>
                    
                    ${errorMetrics.error_types && errorMetrics.error_types.length > 0 ? `
                        <div style="margin-top: 20px;">
                            <div style="color: #7f8c8d; font-size: 12px; margin-bottom: 10px;">Top Error Types</div>
                            <ul class="error-types-list">
                                ${errorMetrics.error_types.map(et => `
                                    <li>
                                        <span>${et.error_type}</span>
                                        <span class="badge">${et.count}</span>
                                    </li>
                                `).join('')}
                            </ul>
                        </div>
                    ` : ''}
                </div>
            `;
            
            html += '</div>';
            
            // Recent Alerts
            if (alerts.length > 0) {
                html += `
                    <div class="alerts-section">
                        <h3>⚠️ Recent Alerts</h3>
                        ${alerts.map(alert => `
                            <div class="alert-item ${alert.alert_level}">
                                <div class="alert-header">
                                    <span class="alert-level">${alert.alert_level}</span>
                                    <span class="alert-time">${new Date(alert.created_at).toLocaleString()}</span>
                                </div>
                                <div class="alert-message">${alert.message}</div>
                                <div class="alert-details">
                                    Value: ${alert.value} | Threshold: ${alert.threshold_value}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
            }
            
            // Performance Metrics
            const perfMetrics = metrics.performance;
            html += `
                <div class="chart-card">
                    <h3>Performance Metrics</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 5px;">
                            <div style="color: #7f8c8d; font-size: 12px;">Synced Today</div>
                            <div style="font-size: 24px; font-weight: bold; color: #27ae60;">
                                ${perfMetrics.products_synced_today}
                            </div>
                        </div>
                        <div style="padding: 15px; background: #f8f9fa; border-radius: 5px;">
                            <div style="color: #7f8c8d; font-size: 12px;">Last Sync</div>
                            <div style="font-size: 14px; font-weight: bold; color: #2c3e50;">
                                ${perfMetrics.last_sync_time || 'Never'}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('dashboard-content').innerHTML = html;
        }
        
        // Load dashboard on page load
        loadDashboard();
        
        // Auto-refresh every 60 seconds
        setInterval(loadDashboard, 60000);
    </script>
</body>
</html>
