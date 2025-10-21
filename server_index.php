<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборды управления - Завод Проставок</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2d3748;
            position: relative;
        }
        
        .logo-container {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 999999;
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 1);
            padding: 8px 15px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .logo-container:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        
        .logo-image {
            height: 40px;
            width: auto;
            margin-right: 10px;
        }
        
        .logo-text {
            font-weight: bold;
            font-size: 1.2rem;
            color: #2d3748;
        }
        
        .logo-container a {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 50px;
        }
        
        .header h1 {
            font-size: 3rem;
            margin-bottom: 15px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        .header p {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .dashboards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #4299e1, #3182ce);
        }
        
        .dashboard-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }
        
        .dashboard-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            display: block;
        }
        
        .dashboard-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: #2d3748;
        }
        
        .dashboard-description {
            color: #718096;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .dashboard-features {
            list-style: none;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .dashboard-features li {
            padding: 5px 0;
            color: #4a5568;
            position: relative;
            padding-left: 20px;
        }
        
        .dashboard-features li::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #48bb78;
            font-weight: bold;
        }
        
        .dashboard-link {
            display: inline-block;
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
        }
        
        .dashboard-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(66, 153, 225, 0.6);
        }
        
        .new-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 10px;
        }
        
        .status-stable {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .status-new {
            background: #bee3f8;
            color: #2a4365;
        }
    </style>
</head>
<body>
    <div class="logo-container">
        <a href="/">
            <img src="/images/market_mi_logo.jpeg" alt="Market-MIRu" class="logo-image">
            <span class="logo-text">Market-MIRu</span>
        </a>
    </div>
    <div class="container">
        <div class="header">
            <h1>🎯 Дашборды управления</h1>
            <p>Выберите нужный дашборд для работы с системой</p>
        </div>
        
        <div class="dashboards">
            <!-- Классический дашборд -->
            <div class="dashboard-card">
                <span class="dashboard-icon">📊</span>
                <h3 class="dashboard-title">Классический дашборд</h3>
                <p class="dashboard-description">
                    Основной дашборд для мониторинга остатков товаров и статистики маркетплейсов
                </p>
                <ul class="dashboard-features">
                    <li>Просмотр остатков товаров</li>
                    <li>Статистика по маркетплейсам</li>
                    <li>Анализ продаж</li>
                    <li>Отчеты по складам</li>
                </ul>
                <a href="dashboard_marketplace_enhanced.php?tab=warehouse_products" class="dashboard-link">
                    Открыть дашборд
                </a>
                <div class="status status-stable">Стабильная версия</div>
            </div>
            
            <!-- v4 API дашборд -->
            <div class="dashboard-card">
                <div class="new-badge">NEW</div>
                <span class="dashboard-icon">🚀</span>
                <h3 class="dashboard-title">Ozon v4 API</h3>
                <p class="dashboard-description">
                    Новый дашборд для управления синхронизацией остатков через улучшенный v4 API
                </p>
                <ul class="dashboard-features">
                    <li>Синхронизация через v4 API</li>
                    <li>Улучшенная обработка ошибок</li>
                    <li>Аналитика и валидация данных</li>
                    <li>Мониторинг в реальном времени</li>
                    <li>Детальное логирование</li>
                </ul>
                <a href="dashboard_inventory_v4.php" class="dashboard-link">
                    Открыть v4 дашборд
                </a>
                <div class="status status-new">Новая версия</div>
            </div>
        </div>
    </div>
</body>
</html>