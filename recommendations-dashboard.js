class RecommendationsDashboard {
  constructor(rootId = "recommendations-app") {
    this.root = document.getElementById(rootId);
    this.apiUrl = this.root?.dataset?.api || "recommendations_api.php";
    this.tbody = document.querySelector("#reco-table tbody");
    this.filtersForm = document.getElementById("filters");

    this.bind();
    this.loadSummary();
    this.loadList();
  }

  bind() {
    if (this.filtersForm) {
      this.filtersForm.addEventListener("submit", (e) => {
        e.preventDefault();
        this.loadSummary();
        this.loadList();
      });
    }

    const exportBtn = document.getElementById("exportCsv");
    if (exportBtn) {
      exportBtn.addEventListener("click", () => this.exportCSV());
    }
  }

  getParams() {
    const fd = new FormData(this.filtersForm);
    const params = new URLSearchParams();
    const status = fd.get("status");
    const search = fd.get("search");
    const limit = fd.get("limit") || "50";

    if (status) params.append("status", status);
    if (search) params.append("search", search);
    params.append("limit", limit);

    return params;
  }

  async loadSummary() {
    try {
      const params = this.getParams();
      params.append("action", "summary");
      const res = await fetch(`${this.apiUrl}?${params.toString()}`);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || "API error");

      const s = data.data || {};
      document.getElementById("kpi-total").textContent = s.total_recommendations ?? 0;
      document.getElementById("kpi-urgent").textContent = s.urgent_count ?? 0;
      document.getElementById("kpi-normal").textContent = s.normal_count ?? 0;
      document.getElementById("kpi-low").textContent = s.low_priority_count ?? 0;
    } catch (e) {
      console.error("Summary load error", e);
    }
  }

  async loadList(offset = 0) {
    try {
      const params = this.getParams();
      params.append("action", "list");
      params.append("offset", String(offset));
      const res = await fetch(`${this.apiUrl}?${params.toString()}`);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || "API error");

      const rows = data.data || [];
      document.getElementById("list-count").textContent = `${rows.length} записей`;

      if (rows.length === 0) {
        this.tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">Нет данных</td></tr>';
        return;
      }

      this.tbody.innerHTML = rows
        .map((r) => this.renderRow(r))
        .join("");
    } catch (e) {
      console.error("List load error", e);
      this.tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">Ошибка загрузки</td></tr>';
    }
  }

  renderRow(r) {
    const statusBadge = this.getStatusBadge(r.status);
    const updatedAt = r.updated_at ? new Date(r.updated_at).toLocaleString("ru-RU") : "—";

    return `
      <tr>
        <td>${r.id}</td>
        <td><code>${this.escape(r.product_id)}</code></td>
        <td>${this.escape(r.product_name || "")}</td>
        <td class="text-end">${Number(r.current_stock ?? 0).toLocaleString("ru-RU")}</td>
        <td class="text-end fw-bold">${Number(r.recommended_order_qty ?? 0).toLocaleString("ru-RU")}</td>
        <td>${statusBadge}</td>
        <td>${this.escape(r.reason || "")}</td>
        <td>${updatedAt}</td>
      </tr>
    `;
  }

  getStatusBadge(status) {
    switch (status) {
      case "urgent":
        return '<span class="badge badge-status badge-urgent">Критично</span>';
      case "low_priority":
        return '<span class="badge badge-status badge-low">Низкий</span>';
      default:
        return '<span class="badge badge-status badge-normal">Обычный</span>';
    }
  }

  exportCSV() {
    const params = this.getParams();
    params.append("action", "export");
    const url = `${this.apiUrl}?${params.toString()}`;
    window.open(url, "_blank");
  }

  escape(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }
}

window.addEventListener("DOMContentLoaded", () => new RecommendationsDashboard());
