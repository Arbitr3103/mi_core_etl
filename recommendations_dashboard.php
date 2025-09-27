<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Дашборд рекомендаций по пополнению</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .badge-status { font-size: 0.85rem; }
    .badge-urgent { background-color: #dc3545; }
    .badge-normal { background-color: #0d6efd; }
    .badge-low { background-color: #6c757d; }
    .table thead th { white-space: nowrap; }
    .sticky-toolbar { position: sticky; top: 0; background: #fff; z-index: 10; padding: 10px 0; }
  </style>
</head>
<body>
  <div class="container-fluid py-3" id="recommendations-app" data-api="recommendations_api.php">
    <div class="row">
      <div class="col-12">
        <h1 class="mb-3">📦 Рекомендации по пополнению</h1>

        <!-- Фильтры -->
        <div class="card mb-3 sticky-toolbar">
          <div class="card-body">
            <form id="filters" class="row g-2 align-items-end">
              <div class="col-md-3">
                <label class="form-label">Статус</label>
                <select class="form-select" name="status">
                  <option value="">Все</option>
                  <option value="urgent">Критично</option>
                  <option value="normal">Обычный</option>
                  <option value="low_priority">Низкий приоритет</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Поиск по SKU/названию</label>
                <input type="text" class="form-control" name="search" placeholder="Введите SKU или название" />
              </div>
              <div class="col-md-2">
                <label class="form-label">Лимит</label>
                <select class="form-select" name="limit">
                  <option>25</option>
                  <option selected>50</option>
                  <option>100</option>
                </select>
              </div>
              <div class="col-md-3 text-end">
                <button type="submit" class="btn btn-primary">Применить</button>
                <button type="button" id="exportCsv" class="btn btn-outline-secondary">Экспорт CSV</button>
              </div>
            </form>
          </div>
        </div>

        <!-- KPI -->
        <div class="row mb-3" id="kpi">
          <div class="col-md-3">
            <div class="card text-center">
              <div class="card-body">
                <div class="text-muted">Всего рекомендаций</div>
                <div class="h3" id="kpi-total">—</div>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card text-center">
              <div class="card-body">
                <div class="text-muted">Критично</div>
                <div class="h3 text-danger" id="kpi-urgent">—</div>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card text-center">
              <div class="card-body">
                <div class="text-muted">Обычный</div>
                <div class="h3 text-primary" id="kpi-normal">—</div>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card text-center">
              <div class="card-body">
                <div class="text-muted">Низкий приоритет</div>
                <div class="h3 text-secondary" id="kpi-low">—</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Таблица рекомендаций -->
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Рекомендации</h5>
            <small class="text-muted" id="list-count">0 записей</small>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-striped table-hover mb-0" id="reco-table">
                <thead class="table-light">
                  <tr>
                    <th style="width: 80px;">ID</th>
                    <th>SKU</th>
                    <th>Название</th>
                    <th class="text-end">Остаток</th>
                    <th class="text-end">Реком. заказ</th>
                    <th>Статус</th>
                    <th>Причина</th>
                    <th>Обновлено</th>
                  </tr>
                </thead>
                <tbody>
                  <tr><td colspan="8" class="text-center py-4 text-muted">Загрузка...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="recommendations-dashboard.js"></script>
</body>
</html>
