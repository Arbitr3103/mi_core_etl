<?php
/**
 * ZUZ Dashboard - Система аналитики автозапчастей и проставок
 */

// === КОНФИГУРАЦИЯ БД ===
define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core_db'); // Или отдельная БД для ZUZ
define('DB_USER', 'mi_core_user');
define('DB_PASS', 'secure_password_123');

// === ВСТРОЕННЫЙ API ===
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        $action = $_GET['api'] ?? 'summary';

        switch ($action) {
            case 'car_brands':
                // Получаем список брендов автомобилей
                $sql = "SELECT DISTINCT brand_name, COUNT(*) as models_count 
                        FROM car_models 
                        WHERE brand_name IS NOT NULL 
                        GROUP BY brand_name 
                        ORDER BY brand_name";
                $stmt = $pdo->query($sql);
                $data = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'car_models':
                $brand = $_GET['brand'] ?? '';
                $sql = "SELECT model_name, COUNT(*) as specifications_count 
                        FROM car_models cm
                        LEFT JOIN car_specifications cs ON cm.id = cs.model_id
                        WHERE cm.brand_name = :brand
                        GROUP BY cm.id, model_name 
                        ORDER BY model_name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['brand' => $brand]);
                $data = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'parts_summary':
                // Сводка по автозапчастям
                $sql = "
                    SELECT 
                        COUNT(DISTINCT dp.id) as total_parts,
                        COUNT(DISTINCT dp.brand) as brands_count,
                        SUM(CASE WHEN dp.cost_price > 0 THEN 1 ELSE 0 END) as parts_with_cost,
                        AVG(dp.cost_price) as avg_cost_price,
                        MIN(dp.cost_price) as min_cost_price,
                        MAX(dp.cost_price) as max_cost_price
                    FROM dim_products dp
                    WHERE dp.category LIKE '%запчаст%' OR dp.category LIKE '%проставк%'
                ";
                $stmt = $pdo->query($sql);
                $data = $stmt->fetch() ?: [];
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'top_parts':
                $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 10;
                $sql = "
                    SELECT 
                        dp.product_name,
                        dp.brand,
                        dp.cost_price,
                        COUNT(fo.id) as orders_count,
                        SUM(fo.qty) as total_sold,
                        SUM(fo.price * fo.qty) as total_revenue
                    FROM dim_products dp
                    LEFT JOIN fact_orders fo ON dp.id = fo.product_id
                    WHERE (dp.category LIKE '%запчаст%' OR dp.category LIKE '%проставк%')
                    AND fo.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY dp.id
                    ORDER BY total_revenue DESC
                    LIMIT :limit
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $data = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZUZ Dashboard - Автозапчасти и проставки</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .zuz-header { 
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); 
            color: white; 
            padding: 2rem 0; 
            margin-bottom: 2rem; 
        }
        .zuz-header h1 { margin: 0; font-weight: 300; }
        .zuz-header p { margin: 0.5rem 0 0 0; opacity: 0.9; }
        .brand-card { cursor: pointer; transition: all 0.3s; }
        .brand-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .selected { border: 2px solid #3498db !important; }
    </style>
</head>
<body>
    <!-- ZUZ Хедер -->
    <div class="zuz-header">
        <div class="container">
            <h1>🚗 ZUZ Dashboard</h1>
            <p>Система аналитики автозапчастей, проставок и комплектующих</p>
        </div>
    </div>

    <div class="container-fluid" id="zuz-app">
        
        <!-- Выбор марки автомобиля -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">🔍 Выбор марки автомобиля</h5>
                    </div>
                    <div class="card-body">
                        <div class="row" id="brands-container">
                            <div class="col-12 text-center py-3">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Загрузка марок...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Модели выбранной марки -->
        <div class="row mb-4" id="models-section" style="display: none;">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">🚙 Модели марки <span id="selected-brand"></span></h5>
                    </div>
                    <div class="card-body">
                        <div class="row" id="models-container">
                            <!-- Модели будут загружены динамически -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Сводка по автозапчастям -->
        <div class="row mb-4" id="parts-summary">
            <div class="col-12">
                <h4 class="mb-3">📊 Сводка по автозапчастям</h4>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <div class="text-muted small">Всего запчастей</div>
                        <div class="h3 text-primary" id="total-parts">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <div class="text-muted small">Брендов</div>
                        <div class="h3 text-success" id="brands-count">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <div class="text-muted small">С ценами</div>
                        <div class="h3 text-info" id="parts-with-cost">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <div class="text-muted small">Средняя цена</div>
                        <div class="h3 text-warning" id="avg-cost">—</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Топ автозапчастей -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center bg-dark text-white">
                <h5 class="mb-0">🏆 Топ автозапчастей (30 дней)</h5>
                <select id="top-parts-limit" class="form-select form-select-sm text-dark" style="width:auto;">
                    <option>5</option>
                    <option selected>10</option>
                    <option>20</option>
                </select>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0" id="top-parts-table">
                        <thead class="table-dark">
                            <tr>
                                <th>Название</th>
                                <th>Бренд</th>
                                <th class="text-end">Себестоимость</th>
                                <th class="text-end">Заказов</th>
                                <th class="text-end">Продано</th>
                                <th class="text-end">Выручка</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="6" class="text-center py-4 text-muted">⏳ Загрузка данных...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Футер -->
        <div class="text-center mt-4 mb-3">
            <small class="text-muted">
                ZUZ Dashboard © 2024 | Система аналитики автозапчастей | 
                <a href="https://zuz.ru" target="_blank" class="text-decoration-none">🔗 ZUZ.ru</a>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        class ZuzDashboard {
            constructor() {
                this.apiBase = window.location.href.split('?')[0];
                this.selectedBrand = null;
                this.init();
            }

            async init() {
                await this.loadCarBrands();
                await this.loadPartsSummary();
                await this.loadTopParts();
                this.bindEvents();
            }

            bindEvents() {
                // Обработчик для лимита топ запчастей
                const topPartsLimit = document.getElementById('top-parts-limit');
                if (topPartsLimit) {
                    topPartsLimit.addEventListener('change', () => this.loadTopParts());
                }
            }

            async loadCarBrands() {
                try {
                    const res = await fetch(`${this.apiBase}?api=car_brands`);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'API error');

                    const container = document.getElementById('brands-container');
                    if (data.data.length === 0) {
                        container.innerHTML = '<div class="col-12 text-center text-muted">Марки автомобилей не найдены</div>';
                        return;
                    }

                    container.innerHTML = data.data.map(brand => `
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="card brand-card h-100" data-brand="${this.escape(brand.brand_name)}">
                                <div class="card-body text-center">
                                    <h6 class="card-title">${this.escape(brand.brand_name)}</h6>
                                    <small class="text-muted">${brand.models_count} моделей</small>
                                </div>
                            </div>
                        </div>
                    `).join('');

                    // Добавляем обработчики кликов
                    container.querySelectorAll('.brand-card').forEach(card => {
                        card.addEventListener('click', (e) => {
                            const brand = e.currentTarget.dataset.brand;
                            this.selectBrand(brand);
                        });
                    });

                } catch (e) {
                    console.error('Car brands load error', e);
                    document.getElementById('brands-container').innerHTML = 
                        '<div class="col-12 text-center text-danger">Ошибка загрузки марок</div>';
                }
            }

            async selectBrand(brand) {
                this.selectedBrand = brand;
                
                // Обновляем визуальное выделение
                document.querySelectorAll('.brand-card').forEach(card => {
                    card.classList.remove('selected');
                });
                document.querySelector(`[data-brand="${brand}"]`).classList.add('selected');

                // Показываем секцию моделей
                document.getElementById('selected-brand').textContent = brand;
                document.getElementById('models-section').style.display = 'block';

                await this.loadCarModels(brand);
            }

            async loadCarModels(brand) {
                try {
                    const res = await fetch(`${this.apiBase}?api=car_models&brand=${encodeURIComponent(brand)}`);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'API error');

                    const container = document.getElementById('models-container');
                    if (data.data.length === 0) {
                        container.innerHTML = '<div class="col-12 text-center text-muted">Модели не найдены</div>';
                        return;
                    }

                    container.innerHTML = data.data.map(model => `
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">${this.escape(model.model_name)}</h6>
                                    <small class="text-muted">${model.specifications_count} комплектаций</small>
                                </div>
                            </div>
                        </div>
                    `).join('');

                } catch (e) {
                    console.error('Car models load error', e);
                    document.getElementById('models-container').innerHTML = 
                        '<div class="col-12 text-center text-danger">Ошибка загрузки моделей</div>';
                }
            }

            async loadPartsSummary() {
                try {
                    const res = await fetch(`${this.apiBase}?api=parts_summary`);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'API error');

                    const s = data.data || {};
                    document.getElementById('total-parts').textContent = (s.total_parts || 0).toLocaleString('ru-RU');
                    document.getElementById('brands-count').textContent = (s.brands_count || 0).toLocaleString('ru-RU');
                    document.getElementById('parts-with-cost').textContent = (s.parts_with_cost || 0).toLocaleString('ru-RU');
                    document.getElementById('avg-cost').textContent = this.formatMoney(s.avg_cost_price || 0);
                } catch (e) {
                    console.error('Parts summary load error', e);
                }
            }

            async loadTopParts() {
                try {
                    const limit = document.getElementById('top-parts-limit')?.value || '10';
                    const res = await fetch(`${this.apiBase}?api=top_parts&limit=${limit}`);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'API error');

                    const tbody = document.querySelector('#top-parts-table tbody');
                    if (data.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Данные не найдены</td></tr>';
                        return;
                    }

                    tbody.innerHTML = data.data.map(part => `
                        <tr>
                            <td>${this.escape(part.product_name || 'Не указано')}</td>
                            <td>${this.escape(part.brand || 'Не указано')}</td>
                            <td class="text-end">${this.formatMoney(part.cost_price || 0)}</td>
                            <td class="text-end">${(part.orders_count || 0).toLocaleString('ru-RU')}</td>
                            <td class="text-end">${(part.total_sold || 0).toLocaleString('ru-RU')}</td>
                            <td class="text-end">${this.formatMoney(part.total_revenue || 0)}</td>
                        </tr>
                    `).join('');

                } catch (e) {
                    console.error('Top parts load error', e);
                }
            }

            formatMoney(amount) {
                return new Intl.NumberFormat('ru-RU', {
                    style: 'currency',
                    currency: 'RUB',
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0
                }).format(amount || 0);
            }

            escape(s) {
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }
        }

        document.addEventListener('DOMContentLoaded', () => new ZuzDashboard());
    </script>
</body>
</html>
