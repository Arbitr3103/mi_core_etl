<div class="container-fluid py-3" id="recommendations-app" data-api-base="<?php echo esc_attr( rest_url('manhattan/v1') ); ?>">
  <div class="row">
    <div class="col-12">
      <h1 class="mb-3">📦 Рекомендации по пополнению</h1>

      <div class="card mb-3" style="position: sticky; top: 0; z-index: 10;">
        <div class="card-body">
          <form id="reco-filters" class="row g-2 align-items-end">
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
              <button type="button" id="reco-export" class="btn btn-outline-secondary">Экспорт CSV</button>
            </div>
          </form>
        </div>
      </div>

      <div class="row mb-3" id="reco-kpi">
        <div class="col-md-3">
          <div class="card text-center"><div class="card-body">
            <div class="text-muted">Всего рекомендаций</div>
            <div class="h3" id="kpi-total">—</div>
          </div></div>
        </div>
        <div class="col-md-3">
          <div class="card text-center"><div class="card-body">
            <div class="text-muted">Критично</div>
            <div class="h3 text-danger" id="kpi-urgent">—</div>
          </div></div>
        </div>
        <div class="col-md-3">
          <div class="card text-center"><div class="card-body">
            <div class="text-muted">Обычный</div>
            <div class="h3 text-primary" id="kpi-normal">—</div>
          </div></div>
        </div>
        <div class="col-md-3">
          <div class="card text-center"><div class="card-body">
            <div class="text-muted">Низкий приоритет</div>
            <div class="h3 text-secondary" id="kpi-low">—</div>
          </div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Рекомендации</h5>
          <small class="text-muted" id="reco-count">0 записей</small>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-hover mb-0" id="reco-table">
              <thead class="table-light">
                <tr>
                  <th style="width:80px;">ID</th>
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

      <!-- Виджет: Топ по оборачиваемости (v_product_turnover_30d) -->
      <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Оборачиваемость (30 дней)</h5>
          <div class="d-flex align-items-center gap-2">
            <small class="text-muted">Меньше дней запаса → выше риск OOS</small>
            <select id="turnover-order" class="form-select form-select-sm" style="width:auto;">
              <option value="ASC" selected>Сначала минимальный запас (ASC)</option>
              <option value="DESC">Сначала максимальный запас (DESC)</option>
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
              <thead class="table-light">
                <tr>
                  <th>SKU</th>
                  <th>Товар</th>
                  <th class="text-end">Продажи 30д</th>
                  <th class="text-end">Текущий остаток</th>
                  <th class="text-end">Дней запаса</th>
                </tr>
              </thead>
              <tbody>
                <tr><td colspan="5" class="text-center py-3 text-muted">Загрузка...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
