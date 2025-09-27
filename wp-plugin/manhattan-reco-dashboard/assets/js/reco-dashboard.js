(function(){
  // Простой клиент дашборда рекомендаций для WP REST API
  class WPRecoDashboard {
    constructor(){
      this.root = document.getElementById('recommendations-app');
      if(!this.root){ return; }
      this.apiBase = (window.ManhattanReco && ManhattanReco.apiBase) ? ManhattanReco.apiBase : this.root.dataset.apiBase;
      this.filtersForm = document.getElementById('reco-filters');
      this.tbody = document.querySelector('#reco-table tbody');
      this.turnoverBody = document.querySelector('#turnover-table tbody');
      this.turnoverLimit = document.getElementById('turnover-limit');
      this.turnoverOrder = document.getElementById('turnover-order');
      this.bind();
      this.loadSummary();
      this.loadList();
      this.loadTurnover();
    }

    // Привязка обработчиков событий
    bind(){
      if(this.filtersForm){
        this.filtersForm.addEventListener('submit', (e)=>{
          e.preventDefault();
          this.loadSummary();
          this.loadList();
        });
      }
      const exportBtn = document.getElementById('reco-export');
      if(exportBtn){
        exportBtn.addEventListener('click', ()=> this.exportCSV());
      }

      if(this.turnoverLimit){
        this.turnoverLimit.addEventListener('change', ()=> this.loadTurnover());
      }
      if(this.turnoverOrder){
        this.turnoverOrder.addEventListener('change', ()=> this.loadTurnover());
      }
    }

    // Сбор параметров фильтрации
    getParams(){
      const fd = new FormData(this.filtersForm);
      const params = new URLSearchParams();
      const status = fd.get('status');
      const search = fd.get('search');
      const limit  = fd.get('limit') || '50';
      if(status) params.append('status', status);
      if(search) params.append('search', search);
      params.append('limit', limit);
      return params;
    }

    // Загрузка KPI сводки
    async loadSummary(){
      try{
        const url = `${this.apiBase.replace(/\/$/, '')}/reco/summary`;
        const res = await fetch(url, { headers: { 'X-WP-Nonce': (window.ManhattanReco && ManhattanReco.nonce) || '' }});
        const data = await res.json();
        if(!data || data.success === false){ throw new Error(data && data.message || 'API error'); }
        const s = data.data || {};
        document.getElementById('kpi-total').textContent  = s.total_recommendations ?? 0;
        document.getElementById('kpi-urgent').textContent = s.urgent_count ?? 0;
        document.getElementById('kpi-normal').textContent = s.normal_count ?? 0;
        document.getElementById('kpi-low').textContent    = s.low_priority_count ?? 0;
      }catch(e){ console.error('Summary load error', e); }
    }

    // Загрузка списка рекомендаций
    async loadList(offset=0){
      try{
        const params = this.getParams();
        params.append('offset', String(offset));
        const url = `${this.apiBase.replace(/\/$/, '')}/reco/list?${params.toString()}`;
        const res = await fetch(url, { headers: { 'X-WP-Nonce': (window.ManhattanReco && ManhattanReco.nonce) || '' }});
        const data = await res.json();
        if(!data || data.success === false){ throw new Error(data && data.message || 'API error'); }
        const rows = data.data || [];
        document.getElementById('reco-count').textContent = `${rows.length} записей`;
        if(rows.length === 0){
          this.tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">Нет данных</td></tr>';
          return;
        }
        this.tbody.innerHTML = rows.map(r => this.renderRow(r)).join('');
      }catch(e){
        console.error('List load error', e);
        this.tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">Ошибка загрузки</td></tr>';
      }
    }

    // Экспорт CSV
    exportCSV(){
      const params = this.getParams();
      const url = `${this.apiBase.replace(/\/$/, '')}/reco/export?${params.toString()}`;
      window.open(url, '_blank');
    }

    // Загрузка топа по оборачиваемости
    async loadTurnover(){
      try{
        if(!this.turnoverBody) return;
        const limit = (this.turnoverLimit && this.turnoverLimit.value) ? this.turnoverLimit.value : '20';
        const order = (this.turnoverOrder && this.turnoverOrder.value) ? this.turnoverOrder.value : 'ASC';
        const url = `${this.apiBase.replace(/\/$/, '')}/turnover/top?limit=${encodeURIComponent(limit)}&order=${encodeURIComponent(order)}`;
        const res = await fetch(url, { headers: { 'X-WP-Nonce': (window.ManhattanReco && ManhattanReco.nonce) || '' }});
        const data = await res.json();
        if(!data || data.success === false){ throw new Error(data && data.message || 'API error'); }
        const rows = data.data || [];
        if(rows.length === 0){
          this.turnoverBody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Нет данных</td></tr>';
          return;
        }
        this.turnoverBody.innerHTML = rows.map(r => this.renderTurnoverRow(r)).join('');
      }catch(e){
        console.error('Turnover load error', e);
        this.turnoverBody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-danger">Ошибка загрузки</td></tr>';
      }
    }

    // Рендер строки оборачиваемости
    renderTurnoverRow(r){
      return `
        <tr>
          <td><code>${this.escape(r.sku_ozon || '')}</code></td>
          <td>${this.escape(r.product_name || '')}</td>
          <td class="text-end">${Number(r.total_sold_30d ?? 0).toLocaleString('ru-RU')}</td>
          <td class="text-end">${Number(r.current_stock ?? 0).toLocaleString('ru-RU')}</td>
          <td class="text-end">${r.days_of_stock != null ? Number(r.days_of_stock).toLocaleString('ru-RU') : '—'}</td>
        </tr>`;
    }

    // Рендер строки таблицы
    renderRow(r){
      const statusBadge = this.badge(r.status);
      const upd = r.updated_at ? new Date(r.updated_at).toLocaleString('ru-RU') : '—';
      return `
        <tr>
          <td>${r.id}</td>
          <td><code>${this.escape(r.product_id)}</code></td>
          <td>${this.escape(r.product_name || '')}</td>
          <td class="text-end">${Number(r.current_stock ?? 0).toLocaleString('ru-RU')}</td>
          <td class="text-end fw-bold">${Number(r.recommended_order_qty ?? 0).toLocaleString('ru-RU')}</td>
          <td>${statusBadge}</td>
          <td>${this.escape(r.reason || '')}</td>
          <td>${upd}</td>
        </tr>`;
    }

    // Бейдж статуса
    badge(status){
      switch(status){
        case 'urgent': return '<span class="badge bg-danger">Критично</span>';
        case 'low_priority': return '<span class="badge bg-secondary">Низкий</span>';
        default: return '<span class="badge bg-primary">Обычный</span>';
      }
    }

    // Экранирование текста
    escape(s){
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
  }

  document.addEventListener('DOMContentLoaded', ()=> new WPRecoDashboard());
})();
