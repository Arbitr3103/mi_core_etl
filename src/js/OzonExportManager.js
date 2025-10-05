/**
 * OzonExportManager - Управление экспортом данных аналитики Ozon
 *
 * Обеспечивает функциональность экспорта данных воронки продаж,
 * демографических данных и кампаний в форматах CSV и JSON.
 * Поддерживает пагинацию для больших объемов данных.
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonExportManager {
  constructor(apiBaseUrl = "/src/api/ozon-analytics.php") {
    this.apiBaseUrl = apiBaseUrl;
    this.activeExports = new Map();
    this.exportHistory = [];

    // Bind methods
    this.exportData = this.exportData.bind(this);
    this.downloadFile = this.downloadFile.bind(this);
    this.showExportModal = this.showExportModal.bind(this);
    this.hideExportModal = this.hideExportModal.bind(this);

    this.initializeUI();
  }

  /**
   * Инициализация пользовательского интерфейса
   */
  initializeUI() {
    // Создаем модальное окно для экспорта если его нет
    if (!document.getElementById("exportModal")) {
      this.createExportModal();
    }

    // Привязываем обработчики событий к кнопкам экспорта
    this.bindExportButtons();
  }

  /**
   * Создание модального окна для экспорта
   */
  createExportModal() {
    const modalHtml = `
            <div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="exportModalLabel">📤 Экспорт данных Ozon</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="exportForm">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="exportDataType" class="form-label">Тип данных:</label>
                                        <select class="form-control" id="exportDataType" required>
                                            <option value="">Выберите тип данных</option>
                                            <option value="funnel">📊 Воронка продаж</option>
                                            <option value="demographics">👥 Демографические данные</option>
                                            <option value="campaigns">📢 Рекламные кампании</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="exportFormat" class="form-label">Формат:</label>
                                        <select class="form-control" id="exportFormat" required>
                                            <option value="csv">📄 CSV (Excel)</option>
                                            <option value="json">📋 JSON</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="exportDateFrom" class="form-label">Дата начала:</label>
                                        <input type="date" class="form-control" id="exportDateFrom" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="exportDateTo" class="form-label">Дата окончания:</label>
                                        <input type="date" class="form-control" id="exportDateTo" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="usePagination">
                                        <label class="form-check-label" for="usePagination">
                                            Использовать пагинацию для больших объемов данных
                                        </label>
                                    </div>
                                </div>
                                
                                <div id="paginationSettings" style="display: none;">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="pageSize" class="form-label">Размер страницы:</label>
                                            <select class="form-control" id="pageSize">
                                                <option value="500">500 записей</option>
                                                <option value="1000" selected>1000 записей</option>
                                                <option value="2000">2000 записей</option>
                                                <option value="5000">5000 записей</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="pageNumber" class="form-label">Номер страницы:</label>
                                            <input type="number" class="form-control" id="pageNumber" value="1" min="1">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <h6>Дополнительные фильтры:</h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <label for="exportProductId" class="form-label">ID товара:</label>
                                            <input type="text" class="form-control" id="exportProductId" placeholder="Опционально">
                                        </div>
                                        <div class="col-md-4">
                                            <label for="exportCampaignId" class="form-label">ID кампании:</label>
                                            <input type="text" class="form-control" id="exportCampaignId" placeholder="Опционально">
                                        </div>
                                        <div class="col-md-4">
                                            <label for="exportRegion" class="form-label">Регион:</label>
                                            <input type="text" class="form-control" id="exportRegion" placeholder="Опционально">
                                        </div>
                                    </div>
                                </div>
                            </form>
                            
                            <div id="exportProgress" style="display: none;">
                                <div class="progress mb-3">
                                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="text-center">
                                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                    <span id="exportStatus">Подготовка экспорта...</span>
                                </div>
                            </div>
                            
                            <div id="exportResult" style="display: none;">
                                <div class="alert alert-success">
                                    <h6>✅ Экспорт завершен успешно!</h6>
                                    <div id="exportDetails"></div>
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-success" id="downloadButton">
                                            📥 Скачать файл
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" id="newExportButton">
                                            🔄 Новый экспорт
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="exportError" style="display: none;">
                                <div class="alert alert-danger">
                                    <h6>❌ Ошибка экспорта</h6>
                                    <div id="errorDetails"></div>
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-outline-danger" id="retryExportButton">
                                            🔄 Повторить
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                            <button type="button" class="btn btn-primary" id="startExportButton">
                                📤 Начать экспорт
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

    document.body.insertAdjacentHTML("beforeend", modalHtml);

    // Привязываем обработчики событий модального окна
    this.bindModalEvents();
  }

  /**
   * Привязка обработчиков событий модального окна
   */
  bindModalEvents() {
    const modal = document.getElementById("exportModal");
    const usePaginationCheckbox = document.getElementById("usePagination");
    const paginationSettings = document.getElementById("paginationSettings");
    const startExportButton = document.getElementById("startExportButton");
    const downloadButton = document.getElementById("downloadButton");
    const newExportButton = document.getElementById("newExportButton");
    const retryExportButton = document.getElementById("retryExportButton");

    // Показать/скрыть настройки пагинации
    usePaginationCheckbox.addEventListener("change", () => {
      paginationSettings.style.display = usePaginationCheckbox.checked
        ? "block"
        : "none";
    });

    // Начать экспорт
    startExportButton.addEventListener("click", () => {
      this.startExport();
    });

    // Скачать файл
    downloadButton.addEventListener("click", () => {
      const downloadUrl = downloadButton.dataset.downloadUrl;
      if (downloadUrl) {
        this.downloadFile(downloadUrl);
      }
    });

    // Новый экспорт
    newExportButton.addEventListener("click", () => {
      this.resetExportModal();
    });

    // Повторить экспорт
    retryExportButton.addEventListener("click", () => {
      this.startExport();
    });

    // Установить значения дат по умолчанию при открытии модального окна
    modal.addEventListener("show.bs.modal", () => {
      this.setDefaultDates();
    });
  }

  /**
   * Привязка обработчиков к кнопкам экспорта
   */
  bindExportButtons() {
    // Основная кнопка экспорта в дашборде
    const exportButton = document.getElementById("exportAnalyticsData");
    if (exportButton) {
      exportButton.addEventListener("click", () => {
        this.showExportModal();
      });
    }

    // Кнопки быстрого экспорта для каждого типа данных
    const quickExportButtons = document.querySelectorAll("[data-quick-export]");
    quickExportButtons.forEach((button) => {
      button.addEventListener("click", (e) => {
        const dataType = e.target.dataset.quickExport;
        this.quickExport(dataType);
      });
    });
  }

  /**
   * Установка дат по умолчанию
   */
  setDefaultDates() {
    const dateFrom = document.getElementById("exportDateFrom");
    const dateTo = document.getElementById("exportDateTo");

    if (!dateFrom.value) {
      dateFrom.value = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000)
        .toISOString()
        .split("T")[0];
    }

    if (!dateTo.value) {
      dateTo.value = new Date().toISOString().split("T")[0];
    }
  }

  /**
   * Показать модальное окно экспорта
   */
  showExportModal(dataType = null) {
    const modal = new bootstrap.Modal(document.getElementById("exportModal"));

    if (dataType) {
      document.getElementById("exportDataType").value = dataType;
    }

    this.resetExportModal();
    modal.show();
  }

  /**
   * Скрыть модальное окно экспорта
   */
  hideExportModal() {
    const modal = bootstrap.Modal.getInstance(
      document.getElementById("exportModal")
    );
    if (modal) {
      modal.hide();
    }
  }

  /**
   * Сброс состояния модального окна
   */
  resetExportModal() {
    document.getElementById("exportProgress").style.display = "none";
    document.getElementById("exportResult").style.display = "none";
    document.getElementById("exportError").style.display = "none";
    document.getElementById("exportForm").style.display = "block";
    document.getElementById("startExportButton").style.display = "inline-block";

    // Сброс прогресс-бара
    const progressBar = document.querySelector("#exportProgress .progress-bar");
    progressBar.style.width = "0%";
  }

  /**
   * Начать процесс экспорта
   */
  async startExport() {
    const form = document.getElementById("exportForm");
    const formData = new FormData(form);

    // Валидация формы
    if (!this.validateExportForm()) {
      return;
    }

    // Получаем параметры экспорта
    const exportParams = this.getExportParams();

    // Показываем прогресс
    this.showExportProgress();

    try {
      const result = await this.exportData(exportParams);
      this.handleExportSuccess(result);
    } catch (error) {
      this.handleExportError(error);
    }
  }

  /**
   * Валидация формы экспорта
   */
  validateExportForm() {
    const dataType = document.getElementById("exportDataType").value;
    const format = document.getElementById("exportFormat").value;
    const dateFrom = document.getElementById("exportDateFrom").value;
    const dateTo = document.getElementById("exportDateTo").value;

    if (!dataType) {
      this.showError("Выберите тип данных для экспорта");
      return false;
    }

    if (!format) {
      this.showError("Выберите формат экспорта");
      return false;
    }

    if (!dateFrom || !dateTo) {
      this.showError("Укажите период для экспорта");
      return false;
    }

    if (new Date(dateFrom) > new Date(dateTo)) {
      this.showError("Начальная дата не может быть больше конечной");
      return false;
    }

    // Проверяем максимальный диапазон (90 дней)
    const daysDiff =
      (new Date(dateTo) - new Date(dateFrom)) / (1000 * 60 * 60 * 24);
    if (daysDiff > 90) {
      this.showError("Максимальный период для экспорта: 90 дней");
      return false;
    }

    return true;
  }

  /**
   * Получение параметров экспорта из формы
   */
  getExportParams() {
    return {
      data_type: document.getElementById("exportDataType").value,
      format: document.getElementById("exportFormat").value,
      date_from: document.getElementById("exportDateFrom").value,
      date_to: document.getElementById("exportDateTo").value,
      use_pagination: document.getElementById("usePagination").checked,
      page: parseInt(document.getElementById("pageNumber").value) || 1,
      page_size: parseInt(document.getElementById("pageSize").value) || 1000,
      product_id: document.getElementById("exportProductId").value || null,
      campaign_id: document.getElementById("exportCampaignId").value || null,
      region: document.getElementById("exportRegion").value || null,
    };
  }

  /**
   * Показать прогресс экспорта
   */
  showExportProgress() {
    document.getElementById("exportForm").style.display = "none";
    document.getElementById("startExportButton").style.display = "none";
    document.getElementById("exportProgress").style.display = "block";

    // Анимация прогресс-бара
    this.animateProgressBar();
  }

  /**
   * Анимация прогресс-бара
   */
  animateProgressBar() {
    const progressBar = document.querySelector("#exportProgress .progress-bar");
    const statusText = document.getElementById("exportStatus");

    let progress = 0;
    const interval = setInterval(() => {
      progress += Math.random() * 15;
      if (progress > 90) {
        progress = 90;
        clearInterval(interval);
      }

      progressBar.style.width = progress + "%";

      if (progress < 30) {
        statusText.textContent = "Получение данных из API...";
      } else if (progress < 60) {
        statusText.textContent = "Обработка данных...";
      } else if (progress < 90) {
        statusText.textContent = "Подготовка файла для скачивания...";
      }
    }, 200);

    // Сохраняем интервал для возможной остановки
    this.progressInterval = interval;
  }

  /**
   * Экспорт данных через API
   */
  async exportData(params) {
    const response = await fetch(this.apiBaseUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        action: "export-data",
        ...params,
      }),
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    // Если это прямая загрузка CSV
    const contentType = response.headers.get("content-type");
    if (contentType && contentType.includes("text/csv")) {
      const blob = await response.blob();
      const filename =
        this.getFilenameFromResponse(response) ||
        `ozon_${params.data_type}_export_${
          new Date().toISOString().split("T")[0]
        }.csv`;

      return {
        type: "direct_download",
        blob: blob,
        filename: filename,
      };
    }

    const result = await response.json();

    if (!result.success) {
      throw new Error(result.message || "Ошибка экспорта данных");
    }

    return result.data;
  }

  /**
   * Получение имени файла из заголовков ответа
   */
  getFilenameFromResponse(response) {
    const contentDisposition = response.headers.get("content-disposition");
    if (contentDisposition) {
      const filenameMatch = contentDisposition.match(/filename="(.+)"/);
      if (filenameMatch) {
        return filenameMatch[1];
      }
    }
    return null;
  }

  /**
   * Обработка успешного экспорта
   */
  handleExportSuccess(result) {
    // Останавливаем анимацию прогресса
    if (this.progressInterval) {
      clearInterval(this.progressInterval);
    }

    const progressBar = document.querySelector("#exportProgress .progress-bar");
    progressBar.style.width = "100%";
    document.getElementById("exportStatus").textContent = "Экспорт завершен!";

    setTimeout(() => {
      document.getElementById("exportProgress").style.display = "none";
      document.getElementById("exportResult").style.display = "block";

      if (result.type === "direct_download") {
        // Прямая загрузка
        this.downloadBlob(result.blob, result.filename);
        document.getElementById("exportDetails").innerHTML = `
                    <p><strong>Файл:</strong> ${result.filename}</p>
                    <p><strong>Размер:</strong> ${this.formatFileSize(
                      result.blob.size
                    )}</p>
                    <p>Файл автоматически загружен в браузер.</p>
                `;
        document.getElementById("downloadButton").style.display = "none";
      } else if (result.download_link) {
        // Временная ссылка для больших файлов
        const downloadButton = document.getElementById("downloadButton");
        downloadButton.dataset.downloadUrl = result.download_link.download_url;

        document.getElementById("exportDetails").innerHTML = `
                    <p><strong>Файл:</strong> ${
                      result.download_link.filename
                    }</p>
                    <p><strong>Размер:</strong> ${this.formatFileSize(
                      result.download_link.file_size
                    )}</p>
                    <p><strong>Действует до:</strong> ${new Date(
                      result.download_link.expires_at
                    ).toLocaleString()}</p>
                    ${
                      result.pagination
                        ? `
                        <p><strong>Страница:</strong> ${result.pagination.current_page} из ${result.pagination.total_pages}</p>
                        <p><strong>Записей:</strong> ${result.pagination.total_records}</p>
                    `
                        : ""
                    }
                `;
      } else {
        // JSON данные
        const jsonStr = JSON.stringify(result, null, 2);
        const blob = new Blob([jsonStr], { type: "application/json" });
        const filename = `ozon_export_${
          new Date().toISOString().split("T")[0]
        }.json`;

        this.downloadBlob(blob, filename);
        document.getElementById("exportDetails").innerHTML = `
                    <p><strong>Формат:</strong> JSON</p>
                    <p><strong>Записей:</strong> ${
                      Array.isArray(result) ? result.length : "N/A"
                    }</p>
                    <p>Файл автоматически загружен в браузер.</p>
                `;
        document.getElementById("downloadButton").style.display = "none";
      }
    }, 1000);
  }

  /**
   * Обработка ошибки экспорта
   */
  handleExportError(error) {
    // Останавливаем анимацию прогресса
    if (this.progressInterval) {
      clearInterval(this.progressInterval);
    }

    document.getElementById("exportProgress").style.display = "none";
    document.getElementById("exportError").style.display = "block";
    document.getElementById("errorDetails").innerHTML = `
            <p><strong>Ошибка:</strong> ${error.message}</p>
            <p>Попробуйте изменить параметры экспорта или повторить попытку позже.</p>
        `;

    console.error("Export error:", error);
  }

  /**
   * Скачивание файла по URL
   */
  downloadFile(url) {
    const link = document.createElement("a");
    link.href = url;
    link.style.display = "none";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  /**
   * Скачивание blob как файла
   */
  downloadBlob(blob, filename) {
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    link.style.display = "none";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  }

  /**
   * Форматирование размера файла
   */
  formatFileSize(bytes) {
    if (bytes === 0) return "0 Bytes";

    const k = 1024;
    const sizes = ["Bytes", "KB", "MB", "GB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
  }

  /**
   * Быстрый экспорт с предустановленными параметрами
   */
  async quickExport(dataType, format = "csv") {
    const params = {
      data_type: dataType,
      format: format,
      date_from: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000)
        .toISOString()
        .split("T")[0],
      date_to: new Date().toISOString().split("T")[0],
      use_pagination: false,
    };

    try {
      // Показываем индикатор загрузки
      this.showQuickExportProgress(dataType);

      const result = await this.exportData(params);

      if (result.type === "direct_download") {
        this.downloadBlob(result.blob, result.filename);
      } else if (result.download_link) {
        this.downloadFile(result.download_link.download_url);
      }

      this.hideQuickExportProgress();
      this.showSuccessMessage(`Экспорт ${dataType} завершен успешно!`);
    } catch (error) {
      this.hideQuickExportProgress();
      this.showError(`Ошибка экспорта ${dataType}: ${error.message}`);
    }
  }

  /**
   * Показать прогресс быстрого экспорта
   */
  showQuickExportProgress(dataType) {
    const button = document.querySelector(`[data-quick-export="${dataType}"]`);
    if (button) {
      button.disabled = true;
      button.innerHTML =
        '<span class="spinner-border spinner-border-sm me-1"></span>Экспорт...';
    }
  }

  /**
   * Скрыть прогресс быстрого экспорта
   */
  hideQuickExportProgress() {
    const buttons = document.querySelectorAll("[data-quick-export]");
    buttons.forEach((button) => {
      button.disabled = false;
      button.innerHTML = button.dataset.originalText || "📤 Экспорт";
    });
  }

  /**
   * Показать сообщение об ошибке
   */
  showError(message) {
    // Можно использовать toast или alert
    if (typeof bootstrap !== "undefined" && bootstrap.Toast) {
      this.showToast(message, "error");
    } else {
      alert("Ошибка: " + message);
    }
  }

  /**
   * Показать сообщение об успехе
   */
  showSuccessMessage(message) {
    if (typeof bootstrap !== "undefined" && bootstrap.Toast) {
      this.showToast(message, "success");
    } else {
      alert(message);
    }
  }

  /**
   * Показать toast уведомление
   */
  showToast(message, type = "info") {
    const toastContainer =
      document.getElementById("toastContainer") || this.createToastContainer();

    const toastId = "toast_" + Date.now();
    const bgClass =
      type === "error"
        ? "bg-danger"
        : type === "success"
        ? "bg-success"
        : "bg-info";

    const toastHtml = `
            <div id="${toastId}" class="toast ${bgClass} text-white" role="alert">
                <div class="toast-header ${bgClass} text-white">
                    <strong class="me-auto">
                        ${
                          type === "error"
                            ? "❌"
                            : type === "success"
                            ? "✅"
                            : "ℹ️"
                        } 
                        Экспорт данных
                    </strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;

    toastContainer.insertAdjacentHTML("beforeend", toastHtml);

    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement);
    toast.show();

    // Удаляем toast после скрытия
    toastElement.addEventListener("hidden.bs.toast", () => {
      toastElement.remove();
    });
  }

  /**
   * Создать контейнер для toast уведомлений
   */
  createToastContainer() {
    const container = document.createElement("div");
    container.id = "toastContainer";
    container.className = "toast-container position-fixed top-0 end-0 p-3";
    container.style.zIndex = "9999";
    document.body.appendChild(container);
    return container;
  }

  /**
   * Получить историю экспортов
   */
  getExportHistory() {
    return this.exportHistory;
  }

  /**
   * Очистить историю экспортов
   */
  clearExportHistory() {
    this.exportHistory = [];
  }
}

// Инициализация при загрузке страницы
document.addEventListener("DOMContentLoaded", function () {
  // Создаем глобальный экземпляр менеджера экспорта
  if (typeof window.ozonExportManager === "undefined") {
    window.ozonExportManager = new OzonExportManager();
  }
});
