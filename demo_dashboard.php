<?php
/**
 * Manhattan Dashboard с маржинальностью и рекомендациями
 */

// === КОНФИГУРАЦИЯ БД ===
define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core_db');
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
            case 'summary':
                $sql = "
                    SELECT 
                        COUNT(*) as total_recommendations,
                        SUM(CASE WHEN status = 'urgent' THEN 1 ELSE 0 END) as urgent_count,
                        SUM(CASE WHEN status = 'normal' THEN 1 ELSE 0 END) as normal_count,
                        SUM(CASE WHEN status = 'low_priority' THEN 1 ELSE 0 END) as low_priority_count,
                        SUM(recommended_order_qty) as total_recommended_qty
                    FROM stock_recommendations
                ";
                $stmt = $pdo->query($sql);
                $data = $stmt->fetch() ?: [];
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'margin_summary':
                $sql = "
                    SELECT 
                        SUM(revenue_sum) as total_revenue,
                        SUM(cogs_sum) as total_cogs,
                        SUM(commission_sum + shipping_sum + other_expenses_sum) as total_expenses,
                        SUM(revenue_sum - COALESCE(cogs_sum,0) - commission_sum - shipping_sum - other_expenses_sum) as total_profit,
                        ROUND(
                            (SUM(revenue_sum - COALESCE(cogs_sum,0) - commission_sum - shipping_sum - other_expenses_sum) / SUM(revenue_sum)) * 100, 2
                        ) as margin_percent,
                        COUNT(DISTINCT metric_date) as days_count,
                        MIN(metric_date) as date_from,
                        MAX(metric_date) as date_to
                    FROM metrics_daily 
                    WHERE metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ";
                $stmt = $pdo->query($sql);
                $data = $stmt->fetch() ?: [];
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'list':
                $status = $_GET['status'] ?? null;
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
                $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
                $search = $_GET['search'] ?? null;

                $sql = "
                    SELECT 
                        id, product_id, product_name, current_stock,
                        recommended_order_qty, status, reason, created_at, updated_at
                    FROM stock_recommendations
                    WHERE 1=1
                ";
                $params = [];

                if ($status) {
                    $sql .= " AND status = :status";
                    $params['status'] = $status;
                }
                if ($search) {
                    $sql .= " AND (product_id LIKE :search OR product_name LIKE :search)";
                    $params['search'] = "%" . $search . "%";
                }

                $sql .= " ORDER BY 
                    FIELD(status, 'urgent','normal','low_priority'), 
                    recommended_order_qty DESC, 
                    updated_at DESC 
                    LIMIT :limit OFFSET :offset";

                $stmt = $pdo->prepare($sql);
                foreach ($params as $k => $v) {
                    $stmt->bindValue(":".$k, $v);
                }
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
                $stmt->execute();

                $rows = $stmt->fetchAll();
                echo json_encode([
                    'success' => true,
                    'data' => $rows,
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'count' => count($rows)
                    ]
                ]);
                break;

            case 'turnover_top':
                $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 10;
                $order = strtoupper($_GET['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

                $sql = "
                    SELECT 
                        product_id, sku_ozon, product_name,
                        total_sold_30d, current_stock, days_of_stock
                    FROM v_product_turnover_30d
                    WHERE days_of_stock IS NOT NULL
                    ORDER BY days_of_stock {$order}
                    LIMIT :limit
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'export':
                $status = $_GET['status'] ?? null;
                $sql = "
                    SELECT 
                        id, product_id, product_name, current_stock,
                        recommended_order_qty, status, reason, updated_at
                    FROM stock_recommendations
                    WHERE 1=1
                ";
                $params = [];
                if ($status) {
                    $sql .= " AND status = :status";
                    $params['status'] = $status;
                }
                $sql .= " ORDER BY FIELD(status, 'urgent','normal','low_priority'), recommended_order_qty DESC";

                $stmt = $pdo->prepare($sql);
                foreach ($params as $k => $v) {
                    $stmt->bindValue(":".$k, $v);
                }
                $stmt->execute();
                $rows = $stmt->fetchAll();

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="stock_recommendations.csv"');
                
                $fh = fopen('php://output', 'w');
                fputcsv($fh, ['ID','SKU','Product Name','Current Stock','Recommended Qty','Status','Reason','Updated']);
                foreach ($rows as $r) {
                    fputcsv($fh, [
                        $r['id'], $r['product_id'], $r['product_name'], $r['current_stock'],
                        $r['recommended_order_qty'], $r['status'], $r['reason'], $r['updated_at']
                    ]);
                }
                fclose($fh);
                exit;

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
    <title>Manhattan Dashboard - Рекомендации и маржинальность</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .badge-status { font-size: 0.85rem; }
        .table thead th { white-space: nowrap; }
        .sticky-toolbar { position: sticky; top: 0; background: #fff; z-index: 10; padding: 10px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .demo-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem 0; margin-bottom: 2rem; }
        .demo-header h1 { margin: 0; font-weight: 300; }
        .demo-header p { margin: 0.5rem 0 0 0; opacity: 0.9; }
        .margin-positive { color: #28a745; }
        .margin-negative { color: #dc3545; }
    </style>
</head>
<body>
    <!-- Демо-хедер -->
    <div class="demo-header">
        <div class="container">
            <h1>📊 Manhattan Dashboard</h1>
            <p>Система рекомендаций по пополнению запасов, анализ оборачиваемости и маржинальности</p>
        </div>
    </div>

    <div class="container-fluid" id="demo-app">
        
        <!-- KPI Маржинальности -->
        <div class="row mb-4" id="margin-kpi">
            <div class="col-12">
                <h4 class="mb-3">💰 Маржинальность (30 дней)</h4>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <div class="text-muted small">Выручка</div>
                        <div class="h3 text-success" id="margin-revenue">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <div class="text-muted small">Прибыль</div>
                        <div class="h3 text-primary" id="margin-profit">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <div class="text-muted small">Маржа %</div>
                        <div class="h3 text-info" id="margin-percent">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-secondary">
                    <div class="card-body">
                        <div class="text-muted small">Дней в анализе</div>
                        <div class="h3 text-secondary" id="margin-days">—</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Фильтры -->
        <div class="card mb-3 sticky-toolbar">
            <div class="card-body">
                <form id="filters" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Статус рекомендаций</label>
                        <select class="form-select" name="status">
                            <option value="">Все</option>
                            <option value="urgent">🔴 Критично</option>
                            <option value="normal">🔵 Обычный</option>
                            <option value="low_priority">⚪ Низкий приоритет</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Поиск по SKU/названию</label>
                        <input type="text" class="form-control" name="search" placeholder="Введите SKU или название товара" />
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Показать</label>
                        <select class="form-select" name="limit">
                            <option>25</option>
                            <option selected>50</option>
                            <option>100</option>
                        </select>
                    </div>
                    <div class="col-md-3 text-end">
                        <button type="submit" class="btn btn-primary">🔍 Применить</button>
                        <button type="button" id="exportCsv" class="btn btn-outline-success">📥 Экспорт CSV</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- KPI Рекомендаций -->
        <div class="row mb-4" id="kpi">
            <div class="col-12">
                <h4 class="mb-3">📦 Рекомендации по пополнению</h4>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <div class="text-muted small">Всего рекомендаций</div>
                        <div class="h3 text-primary" id="kpi-total">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <div class="text-muted small">🔴 Критично</div>
                        <div class="h3 text-danger" id="kpi-urgent">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <div class="text-muted small">🔵 Обычный</div>
                        <div class="h3 text-info" id="kpi-normal">—</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-secondary">
                    <div class="card-body">
                        <div class="text-muted small">⚪ Низкий приоритет</div>
                        <div class="h3 text-secondary" id="kpi-low">—</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Таблица рекомендаций -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center bg-light">
                <h5 class="mb-0">📦 Рекомендации по пополнению</h5>
                <small class="text-muted" id="list-count">0 записей</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0" id="reco-table">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 80px;">ID</th>
                                <th>SKU</th>
                                <th>Название товара</th>
                                <th class="text-end">Остаток</th>
                                <th class="text-end">Рекомендуемый заказ</th>
                                <th>Статус</th>
                                <th>Причина</th>
                                <th>Обновлено</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="8" class="text-center py-4 text-muted">⏳ Загрузка данных...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Виджет оборачиваемости -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center bg-light">
                <h5 class="mb-0">📈 Анализ оборачиваемости (30 дней)</h5>
                <div class="d-flex align-items-center gap-2">
                    <small class="text-muted">Меньше дней запаса → выше риск дефицита</small>
                    <select id="turnover-order" class="form-select form-select-sm" style="width:auto;">
                        <option value="ASC" selected>⬆️ Сначала минимальный запас</option>
                        <option value="DESC">⬇️ Сначала максимальный запас</option>
                    </select>
                    <select id="turnover-limit" class="form-select form-select-sm" style="width:auto;">
                        <option>10</option>
                        <option selected>20</option>
                        <option>50</option>
                    </select>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0" id="turnover-table">
                        <thead class="table-dark">
                            <tr>
                                <th>SKU</th>
                                <th>Название товара</th>
                                <th class="text-end">Продажи за 30 дней</th>
                                <th class="text-end">Текущий остаток</th>
                                <th class="text-end">Дней запаса</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="5" class="text-center py-3 text-muted">⏳ Загрузка данных...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Футер -->
        <div class="text-center mt-4 mb-3">
            <small class="text-muted">Manhattan Dashboard © 2024 | Демо-версия для презентации</small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        class DemoDashboard {
            constructor() {
                this.apiBase = window.location.href.split('?')[0];
                this.filtersForm = document.getElementById('filters');
                this.tbody = document.querySelector('#reco-table tbody');
                this.turnoverBody = document.querySelector('#turnover-table tbody');
                this.turnoverLimit = document.getElementById('turnover-limit');
                this.turnoverOrder = document.getElementById('turnover-order');
                this.bind();
                this.loadMarginSummary();
                this.loadSummary();
                this.loadList();
                this.loadTurnover();
            }

            bind() {
                if (this.filtersForm) {
                    this.filtersForm.addEventListener('submit', (e) => {
                        e.preventDefault();
                        this.loadSummary();
                        this.loadList();
                    });
                }

                const exportBtn = document.getElementById('exportCsv');
                if (exportBtn) {
                    exportBtn.addEventListener('click', () => this.exportCSV());
                }

                if (this.turnoverLimit) {
                    this.turnoverLimit.addEventListener('change', () => this.loadTurnover());
                }
                if (this.turnoverOrder) {
                    this.turnoverOrder.addEventListener('change', () => this.loadTurnover());
                }
            }

            getParams() {
                const fd = new FormData(this.filtersForm);
                const params = new URLSearchParams();
                const status = fd.get('status');
                const search = fd.get('search');
                const limit = fd.get('limit') || '50';

                if (status) params.append('status', status);
                if (search) params.append('search', search);
                params.append('limit', limit);

                return params;
            }

            async loadMarginSummary() {
                try {
                    const res = await fetch(`${this.apiBase}?api=margin_summary`);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'API error');

                    const s = data.data || {};
                    document.getElementById('margin-revenue').textContent = this.formatMoney(s.total_revenue || 0);
                    document.getElementById('margin-profit').textContent = this.formatMoney(s.total_profit || 0);
                    document.getElementById('margin-percent').textContent = (s.margin_percent || 0) + '%';
                    document.getElementById('margin-days').textContent = (s.days_count || 0);
                } catch (e) {
                    console.error('Margin summary load error', e);
                }
            }

            async loadSummary() {
                try {
                    const res = await fetch(`${this.apiBase}?api=summary`);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'API error');

                    const s = data.data || {};
                    document.getElementById('kpi-total').textContent = (s.total_recommendations ?? 0).toLocaleString('ru-RU');
                    document.getElementById('kpi-urgent').textContent = (s.urgent_count ?? 0).toLocaleString('ru-RU');
                    document.getElementById('kpi-normal').textContent = (s.normal_count ?? 0).toLocaleString('ru-RU');
                    document.getElementById('kpi-low').textContent = (s.low_priority_count ?? 0).toLocaleString('ru-RU');
                } catch (e) {
                    console.error('Summary load error', e);
                }
            }

            async loadList(offset = 0) {
                try {
                    const params = this.getParams();
                    params.append('offset', String(offset));
                    const res = await fetch(`${this.apiBase}?api=list&${params.toString()}`);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'API error');

                    const rows = data.data || [];
                    document.getElementById('list-count').textContent = `${rows.length} записей`;

                    if (rows.length === 0) {
                        this.tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">📭 Нет данных по заданным фильтрам</td></tr>';
                        return;
                    }

                    this.tbody.innerHTML = rows.map(r => this.renderRow(r)).join('');
                } catch (e) {
                    console.error('List load error', e);
                    this.tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">❌ Ошибка загрузки данных</td></tr>';
                }
            }

            async loadTurnover() {
                try {
                    if (!this.turnoverBody) return;
                    const limit = (this.turnoverLimit && this.turnoverLimit.value) ? this.turnoverLimit.value : '20';
                    const order = (this.turnoverOrder && this.turnoverOrder.value) ? this.turnoverOrder.value : 'ASC';
                    const res = await fetch(`${this.apiBase}?api=turnover_top&limit=${encodeURIComponent(limit)}&order=${encodeURIComponent(order)}`);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'API error');

                    const rows = data.data || [];
                    if (rows.length === 0) {
                        this.turnoverBody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">📭 Нет данных по оборачиваемости</td></tr>';
                        return;
                    }
                    this.turnoverBody.innerHTML = rows.map(r => this.renderTurnoverRow(r)).join('');
                } catch (e) {
                    console.error('Turnover load error', e);
                    this.turnoverBody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-danger">❌ Ошибка загрузки оборачиваемости</td></tr>';
                }
            }

            renderRow(r) {
                const statusBadge = this.getStatusBadge(r.status);
                const updatedAt = r.updated_at ? new Date(r.updated_at).toLocaleString('ru-RU') : '—';

                return `
                    <tr>
                        <td><small class="text-muted">${r.id}</small></td>
                        <td><code class="text-primary">${this.escape(r.product_id)}</code></td>
                        <td>${this.escape(r.product_name || '')}</td>
                        <td class="text-end">${Number(r.current_stock ?? 0).toLocaleString('ru-RU')}</td>
                        <td class="text-end fw-bold text-success">${Number(r.recommended_order_qty ?? 0).toLocaleString('ru-RU')}</td>
                        <td>${statusBadge}</td>
                        <td><small>${this.escape(r.reason || '')}</small></td>
                        <td><small class="text-muted">${updatedAt}</small></td>
                    </tr>
                `;
            }

            renderTurnoverRow(r) {
                const daysClass = r.days_of_stock != null && r.days_of_stock < 7 ? 'text-danger fw-bold' : 
                                 r.days_of_stock != null && r.days_of_stock < 14 ? 'text-warning fw-bold' : '';
                
                return `
                    <tr>
                        <td><code class="text-primary">${this.escape(r.sku_ozon || '')}</code></td>
                        <td>${this.escape(r.product_name || '')}</td>
                        <td class="text-end">${Number(r.total_sold_30d ?? 0).toLocaleString('ru-RU')}</td>
                        <td class="text-end">${Number(r.current_stock ?? 0).toLocaleString('ru-RU')}</td>
                        <td class="text-end ${daysClass}">${r.days_of_stock != null ? Number(r.days_of_stock).toLocaleString('ru-RU') : '—'}</td>
                    </tr>`;
            }

            getStatusBadge(status) {
                switch (status) {
                    case 'urgent':
                        return '<span class="badge bg-danger">🔴 Критично</span>';
                    case 'low_priority':
                        return '<span class="badge bg-secondary">⚪ Низкий</span>';
                    default:
                        return '<span class="badge bg-primary">🔵 Обычный</span>';
                }
            }

            exportCSV() {
                const params = this.getParams();
                const url = `${this.apiBase}?api=export&${params.toString()}`;
                window.open(url, '_blank');
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

        document.addEventListener('DOMContentLoaded', () => new DemoDashboard());
    </script>
</body>
</html>
