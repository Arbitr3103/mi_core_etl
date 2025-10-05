/**
 * OzonSecurityIntegration - Заглушка для системы безопасности
 */
class OzonSecurityIntegration {
  constructor() {
    this.isEnabled = false;
    console.log("OzonSecurityIntegration initialized (disabled)");
  }

  /**
   * Инициализация (заглушка)
   */
  init() {
    // Система безопасности временно отключена
    return true;
  }

  /**
   * Проверка доступа (заглушка)
   */
  checkAccess() {
    return true;
  }

  /**
   * Логирование действий (заглушка)
   */
  logAction(action, data) {
    console.log("Security log:", action, data);
  }
}

// Экспортируем класс для использования
window.OzonSecurityIntegration = OzonSecurityIntegration;
