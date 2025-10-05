/**
 * Ozon Settings Manager
 *
 * Handles the Ozon API settings interface including validation,
 * connection testing, and secure credential management.
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonSettingsManager {
  constructor() {
    this.apiEndpoint = "src/api/ozon-settings.php";
    this.init();
  }

  /**
   * Initialize the settings manager
   */
  init() {
    this.bindEvents();
    this.loadCurrentSettings();
    this.checkConnectionStatus();
  }

  /**
   * Bind event listeners
   */
  bindEvents() {
    // Form submission
    const settingsForm = document.getElementById("settingsForm");
    if (settingsForm) {
      settingsForm.addEventListener("submit", (e) => this.handleFormSubmit(e));
    }

    // Test connection button
    const testBtn = document.getElementById("testConnectionBtn");
    if (testBtn) {
      testBtn.addEventListener("click", () => this.testConnection());
    }

    // Real-time validation
    const clientIdInput = document.getElementById("client_id");
    const apiKeyInput = document.getElementById("api_key");

    if (clientIdInput) {
      clientIdInput.addEventListener("input", () => this.validateClientId());
      clientIdInput.addEventListener("blur", () => this.validateClientId());
    }

    if (apiKeyInput) {
      apiKeyInput.addEventListener("input", () => this.validateApiKey());
      apiKeyInput.addEventListener("blur", () => this.validateApiKey());
    }

    // Password visibility toggle
    const toggleBtn = document.getElementById("togglePasswordBtn");
    if (toggleBtn) {
      toggleBtn.addEventListener("click", () =>
        this.togglePasswordVisibility()
      );
    }

    // Delete buttons
    document.addEventListener("click", (e) => {
      if (e.target.classList.contains("delete-settings-btn")) {
        this.deleteSettings(e.target.dataset.settingsId);
      }
    });
  }

  /**
   * Handle form submission
   */
  async handleFormSubmit(event) {
    event.preventDefault();

    const form = event.target;
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    // Show loading state
    this.setFormLoading(true);

    try {
      // Validate before submission
      const validation = await this.validateCredentials(
        data.client_id,
        data.api_key
      );
      if (!validation.success) {
        this.showMessage(validation.message, "error");
        return;
      }

      // Determine if this is an update or new save
      const action = data.settings_id ? "update_settings" : "save_settings";
      const method = data.settings_id ? "PUT" : "POST";

      const response = await this.makeRequest(
        method,
        `${this.apiEndpoint}?action=${action}`,
        data
      );

      if (response.success) {
        this.showMessage(response.message, "success");
        this.loadCurrentSettings();

        // Clear form if it was a new save
        if (!data.settings_id) {
          form.reset();
        }
      } else {
        this.showMessage(response.message, "error");
      }
    } catch (error) {
      console.error("Form submission error:", error);
      this.showMessage("Ошибка сохранения настроек", "error");
    } finally {
      this.setFormLoading(false);
    }
  }

  /**
   * Test API connection
   */
  async testConnection() {
    const clientId = document.getElementById("client_id").value.trim();
    const apiKey = document.getElementById("api_key").value.trim();

    if (!clientId || !apiKey) {
      this.showMessage(
        "Пожалуйста, заполните Client ID и API Key перед тестированием",
        "error"
      );
      return;
    }

    // Show loading state
    this.setTestButtonLoading(true);

    try {
      const response = await this.makeRequest(
        "POST",
        `${this.apiEndpoint}?action=test_connection`,
        {
          client_id: clientId,
          api_key: apiKey,
        }
      );

      if (response.success) {
        this.showConnectionTestResult(response.message, "success", response);
      } else {
        this.showConnectionTestResult(response.message, "error", response);
      }
    } catch (error) {
      console.error("Connection test error:", error);
      this.showConnectionTestResult("Ошибка тестирования подключения", "error");
    } finally {
      this.setTestButtonLoading(false);
    }
  }

  /**
   * Load current settings
   */
  async loadCurrentSettings() {
    try {
      const response = await this.makeRequest(
        "GET",
        `${this.apiEndpoint}?action=get_settings`
      );

      if (response.success) {
        this.renderSettingsList(response.data);
      } else {
        console.error("Failed to load settings:", response.message);
      }
    } catch (error) {
      console.error("Error loading settings:", error);
    }
  }

  /**
   * Check connection status
   */
  async checkConnectionStatus() {
    try {
      const response = await this.makeRequest(
        "GET",
        `${this.apiEndpoint}?action=get_connection_status`
      );

      if (response.success) {
        this.updateConnectionStatus(response.data);
      }
    } catch (error) {
      console.error("Error checking connection status:", error);
    }
  }

  /**
   * Delete settings
   */
  async deleteSettings(settingsId) {
    if (!confirm("Вы уверены, что хотите удалить эти настройки?")) {
      return;
    }

    try {
      const response = await this.makeRequest(
        "DELETE",
        `${this.apiEndpoint}?action=delete_settings&id=${settingsId}`
      );

      if (response.success) {
        this.showMessage(response.message, "success");
        this.loadCurrentSettings();
      } else {
        this.showMessage(response.message, "error");
      }
    } catch (error) {
      console.error("Delete settings error:", error);
      this.showMessage("Ошибка удаления настроек", "error");
    }
  }

  /**
   * Validate Client ID
   */
  validateClientId() {
    const input = document.getElementById("client_id");
    const value = input.value.trim();

    if (!value) {
      this.setFieldError(input, "Client ID не может быть пустым");
      return false;
    }

    if (!this.isNumeric(value)) {
      this.setFieldError(input, "Client ID должен содержать только цифры");
      return false;
    }

    if (value.length < 3 || value.length > 20) {
      this.setFieldError(input, "Client ID должен содержать от 3 до 20 цифр");
      return false;
    }

    this.clearFieldError(input);
    return true;
  }

  /**
   * Validate API Key
   */
  validateApiKey() {
    const input = document.getElementById("api_key");
    const value = input.value.trim();

    if (!value) {
      this.setFieldError(input, "API Key не может быть пустым");
      return false;
    }

    if (!/^[a-zA-Z0-9\-_]+$/.test(value)) {
      this.setFieldError(input, "API Key содержит недопустимые символы");
      return false;
    }

    if (value.length < 10 || value.length > 100) {
      this.setFieldError(
        input,
        "API Key должен содержать от 10 до 100 символов"
      );
      return false;
    }

    this.clearFieldError(input);
    return true;
  }

  /**
   * Validate credentials via API
   */
  async validateCredentials(clientId, apiKey) {
    try {
      const response = await this.makeRequest(
        "POST",
        `${this.apiEndpoint}?action=validate_credentials`,
        {
          client_id: clientId,
          api_key: apiKey,
        }
      );

      return response;
    } catch (error) {
      return {
        success: false,
        message: "Ошибка валидации данных",
      };
    }
  }

  /**
   * Toggle password visibility
   */
  togglePasswordVisibility() {
    const passwordInput = document.getElementById("api_key");
    const toggleIcon = document.getElementById("toggleIcon");

    if (passwordInput.type === "password") {
      passwordInput.type = "text";
      toggleIcon.className = "fas fa-eye-slash";
    } else {
      passwordInput.type = "password";
      toggleIcon.className = "fas fa-eye";
    }
  }

  /**
   * Render settings list
   */
  renderSettingsList(settings) {
    const container = document.getElementById("settingsListContainer");
    if (!container) return;

    if (settings.length === 0) {
      container.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                    <p>Настройки API не найдены</p>
                    <p class="small">Заполните форму для добавления настроек</p>
                </div>
            `;
      return;
    }

    const settingsHtml = settings
      .map(
        (setting) => `
            <div class="border rounded p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <strong>ID: ${setting.id}</strong>
                    <div class="d-flex gap-2">
                        <span class="status-badge ${
                          setting.is_active
                            ? "status-active"
                            : "status-inactive"
                        }">
                            ${setting.is_active ? "Активно" : "Неактивно"}
                        </span>
                        ${
                          setting.has_valid_token
                            ? '<span class="status-badge status-active">Токен действителен</span>'
                            : '<span class="status-badge status-inactive">Токен истек</span>'
                        }
                    </div>
                </div>
                <div class="small text-muted mb-2">
                    <strong>Client ID:</strong> ${this.escapeHtml(
                      setting.client_id
                    )}
                </div>
                <div class="small text-muted mb-2">
                    <strong>Создано:</strong> ${this.formatDate(
                      setting.created_at
                    )}
                </div>
                <div class="small text-muted mb-3">
                    <strong>Обновлено:</strong> ${this.formatDate(
                      setting.updated_at
                    )}
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="ozonSettings.editSettings(${
                      setting.id
                    }, '${setting.client_id}')">
                        <i class="fas fa-edit"></i> Редактировать
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm delete-settings-btn" data-settings-id="${
                      setting.id
                    }">
                        <i class="fas fa-trash"></i> Удалить
                    </button>
                </div>
            </div>
        `
      )
      .join("");

    container.innerHTML = settingsHtml;
  }

  /**
   * Edit settings
   */
  editSettings(settingsId, clientId) {
    document.getElementById("settings_id").value = settingsId;
    document.getElementById("client_id").value = clientId;
    document.getElementById("api_key").value = "";
    document.getElementById("api_key").focus();
  }

  /**
   * Update connection status display
   */
  updateConnectionStatus(statusData) {
    const statusContainer = document.getElementById(
      "connectionStatusContainer"
    );
    if (!statusContainer) return;

    let statusHtml = "";
    let statusClass = "";

    switch (statusData.status) {
      case "connected":
        statusClass = "connection-success";
        statusHtml = `
                    <i class="fas fa-check-circle"></i>
                    Подключение активно (Client ID: ${statusData.client_id})
                    <small class="d-block">Токен действителен до: ${this.formatDate(
                      statusData.token_expiry
                    )}</small>
                `;
        break;
      case "expired":
        statusClass = "connection-error";
        statusHtml = `
                    <i class="fas fa-exclamation-triangle"></i>
                    Токен истек (Client ID: ${statusData.client_id})
                    <small class="d-block">Требуется повторная аутентификация</small>
                `;
        break;
      case "not_configured":
        statusClass = "connection-error";
        statusHtml = `
                    <i class="fas fa-info-circle"></i>
                    API не настроен
                    <small class="d-block">Добавьте настройки подключения</small>
                `;
        break;
    }

    statusContainer.innerHTML = `
            <div class="connection-status ${statusClass}">
                ${statusHtml}
            </div>
        `;
  }

  /**
   * Show connection test result
   */
  showConnectionTestResult(message, type, data = {}) {
    const modal = document.getElementById("connectionTestModal");
    const resultContainer = document.getElementById("connectionTestResult");

    if (!modal || !resultContainer) {
      this.showMessage(message, type);
      return;
    }

    const statusClass =
      type === "success" ? "connection-success" : "connection-error";
    const icon = type === "success" ? "check-circle" : "exclamation-triangle";

    let additionalInfo = "";
    if (type === "success" && data.token_length) {
      additionalInfo = `<small class="d-block mt-2">Длина токена: ${data.token_length} символов</small>`;
    }

    resultContainer.innerHTML = `
            <div class="connection-status ${statusClass}">
                <i class="fas fa-${icon}"></i>
                ${message}
                ${additionalInfo}
            </div>
        `;

    // Show modal
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
  }

  /**
   * Set form loading state
   */
  setFormLoading(loading) {
    const submitBtn = document.querySelector(
      '#settingsForm button[type="submit"]'
    );
    if (submitBtn) {
      if (loading) {
        submitBtn.classList.add("loading");
        submitBtn.disabled = true;
      } else {
        submitBtn.classList.remove("loading");
        submitBtn.disabled = false;
      }
    }
  }

  /**
   * Set test button loading state
   */
  setTestButtonLoading(loading) {
    const testBtn = document.getElementById("testConnectionBtn");
    if (testBtn) {
      if (loading) {
        testBtn.classList.add("loading");
        testBtn.disabled = true;
      } else {
        testBtn.classList.remove("loading");
        testBtn.disabled = false;
      }
    }
  }

  /**
   * Set field error
   */
  setFieldError(input, message) {
    this.clearFieldError(input);

    input.classList.add("is-invalid");

    const errorDiv = document.createElement("div");
    errorDiv.className = "invalid-feedback";
    errorDiv.textContent = message;

    input.parentNode.appendChild(errorDiv);
  }

  /**
   * Clear field error
   */
  clearFieldError(input) {
    input.classList.remove("is-invalid");

    const errorDiv = input.parentNode.querySelector(".invalid-feedback");
    if (errorDiv) {
      errorDiv.remove();
    }
  }

  /**
   * Show message
   */
  showMessage(message, type) {
    // Remove existing alerts
    const existingAlerts = document.querySelectorAll(".alert");
    existingAlerts.forEach((alert) => alert.remove());

    // Create new alert
    const alertClass = type === "success" ? "alert-success" : "alert-danger";
    const icon = type === "success" ? "check-circle" : "exclamation-triangle";

    const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                <i class="fas fa-${icon}"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

    // Insert after header
    const header = document.querySelector(".row.mb-4");
    if (header) {
      header.insertAdjacentHTML("afterend", alertHtml);
    }

    // Auto-hide after 5 seconds
    setTimeout(() => {
      const alert = document.querySelector(".alert");
      if (alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
      }
    }, 5000);
  }

  /**
   * Make HTTP request
   */
  async makeRequest(method, url, data = null) {
    const options = {
      method: method,
      headers: {
        "Content-Type": "application/json",
      },
    };

    if (data && (method === "POST" || method === "PUT")) {
      options.body = JSON.stringify(data);
    }

    const response = await fetch(url, options);

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    return await response.json();
  }

  /**
   * Utility functions
   */
  isNumeric(str) {
    return /^\d+$/.test(str);
  }

  escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString("ru-RU", {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });
  }
}

// Initialize when DOM is loaded
document.addEventListener("DOMContentLoaded", function () {
  window.ozonSettings = new OzonSettingsManager();
});
