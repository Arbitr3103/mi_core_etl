"""
Конфигурация для системы еженедельного обновления Ozon Analytics
Configuration for Ozon Analytics Weekly Update System

Скопируйте этот файл в config.py и настройте параметры под вашу среду.
Copy this file to config.py and configure parameters for your environment.

Автор: Manhattan System
Версия: 1.0
"""

# =============================================================================
# НАСТРОЙКИ БАЗЫ ДАННЫХ / DATABASE SETTINGS
# =============================================================================

# Основные параметры подключения к MySQL
DB_HOST = 'localhost'
DB_USER = 'root'
DB_PASSWORD = ''  # Укажите пароль базы данных
DB_NAME = 'manhattan'
DB_PORT = 3306

# Настройки пула соединений
DB_POOL_SIZE = 5
DB_POOL_RESET_SESSION = True
DB_AUTOCOMMIT = False

# =============================================================================
# НАСТРОЙКИ OZON API / OZON API SETTINGS
# =============================================================================

# Учетные данные Ozon API (получите в личном кабинете продавца)
OZON_CLIENT_ID = ''  # Ваш Client ID
OZON_API_KEY = ''    # Ваш API Key

# Настройки API запросов
OZON_API_TIMEOUT = 30        # Таймаут запросов в секундах
OZON_RATE_LIMIT_DELAY = 1.0  # Задержка между запросами в секундах
OZON_MAX_RETRIES = 3         # Максимальное количество повторов при ошибках
OZON_RETRY_DELAY = 5         # Задержка между повторами в секундах

# =============================================================================
# НАСТРОЙКИ EMAIL УВЕДОМЛЕНИЙ / EMAIL NOTIFICATION SETTINGS
# =============================================================================

# SMTP сервер для отправки уведомлений
SMTP_SERVER = 'smtp.gmail.com'  # Или ваш SMTP сервер
SMTP_PORT = 587
SMTP_USE_TLS = True

# Учетные данные для SMTP
SMTP_USER = ''        # Email для отправки уведомлений
SMTP_PASSWORD = ''    # Пароль или App Password

# Настройки уведомлений
FROM_EMAIL = 'noreply@zavodprostavok.ru'
ADMIN_EMAILS = [
    'admin@zavodprostavok.ru',
    # 'manager@zavodprostavok.ru',  # Добавьте дополнительные email
]

# Типы уведомлений
SEND_SUCCESS_NOTIFICATIONS = True   # Отправлять уведомления об успехе
SEND_ERROR_NOTIFICATIONS = True     # Отправлять уведомления об ошибках
SEND_WARNING_NOTIFICATIONS = False  # Отправлять уведомления о предупреждениях

# =============================================================================
# НАСТРОЙКИ ОБНОВЛЕНИЯ / UPDATE SETTINGS
# =============================================================================

# Параметры инкрементального обновления
LOOKBACK_DAYS = 14          # Сколько дней назад обновлять при первом запуске
MAX_DATE_RANGE_DAYS = 90    # Максимальный диапазон дат для одного запроса
BATCH_SIZE = 100            # Размер пакета для обработки данных

# Настройки кэширования
CACHE_TIMEOUT_HOURS = 1     # Время жизни кэша в часах
ENABLE_DATA_CACHING = True  # Включить кэширование данных

# Настройки очистки данных
AUTO_CLEANUP_OLD_DATA = True        # Автоматическая очистка старых данных
CLEANUP_DATA_OLDER_THAN_DAYS = 365  # Удалять данные старше N дней

# =============================================================================
# НАСТРОЙКИ ЛОГИРОВАНИЯ / LOGGING SETTINGS
# =============================================================================

# Уровни логирования: DEBUG, INFO, WARNING, ERROR, CRITICAL
LOG_LEVEL = 'INFO'

# Настройки файлов логов
LOG_DIR = 'logs'
LOG_FILE_MAX_SIZE_MB = 10    # Максимальный размер файла лога в МБ
LOG_FILES_BACKUP_COUNT = 5   # Количество резервных файлов логов
LOG_RETENTION_DAYS = 30      # Сколько дней хранить логи

# Форматирование логов
LOG_FORMAT = '%(asctime)s - %(name)s - %(levelname)s - %(message)s'
LOG_DATE_FORMAT = '%Y-%m-%d %H:%M:%S'

# =============================================================================
# НАСТРОЙКИ БЕЗОПАСНОСТИ / SECURITY SETTINGS
# =============================================================================

# Шифрование чувствительных данных
ENCRYPT_API_KEYS = True      # Шифровать API ключи в базе данных
ENCRYPTION_KEY = ''          # Ключ шифрования (сгенерируйте случайный)

# Ограничения доступа
ALLOWED_IP_RANGES = [        # Разрешенные IP диапазоны для API
    '127.0.0.1/32',         # Localhost
    '10.0.0.0/8',           # Внутренняя сеть
    # '192.168.0.0/16',     # Добавьте ваши диапазоны
]

# =============================================================================
# НАСТРОЙКИ МОНИТОРИНГА / MONITORING SETTINGS
# =============================================================================

# Метрики производительности
ENABLE_PERFORMANCE_MONITORING = True
PERFORMANCE_LOG_SLOW_QUERIES = True
SLOW_QUERY_THRESHOLD_SECONDS = 5

# Алерты и уведомления
ALERT_ON_HIGH_ERROR_RATE = True
ERROR_RATE_THRESHOLD_PERCENT = 10    # Процент ошибок для алерта
ALERT_ON_LONG_EXECUTION = True
LONG_EXECUTION_THRESHOLD_MINUTES = 30

# =============================================================================
# ДОПОЛНИТЕЛЬНЫЕ НАСТРОЙКИ / ADDITIONAL SETTINGS
# =============================================================================

# Настройки для разработки и тестирования
DEBUG_MODE = False           # Режим отладки
TEST_MODE = False           # Тестовый режим (не отправляет реальные запросы)
DRY_RUN = False            # Режим "сухого прогона" (не сохраняет данные)

# Настройки интеграции с внешними системами
WEBHOOK_URL = ''            # URL для webhook уведомлений
SLACK_WEBHOOK_URL = ''      # URL для уведомлений в Slack
TELEGRAM_BOT_TOKEN = ''     # Токен Telegram бота
TELEGRAM_CHAT_ID = ''       # ID чата для уведомлений

# Пользовательские настройки
CUSTOM_USER_AGENT = 'Manhattan-Ozon-Analytics/1.0'
REQUEST_HEADERS = {
    'User-Agent': CUSTOM_USER_AGENT,
    'Accept': 'application/json',
    'Accept-Encoding': 'gzip, deflate',
}

# =============================================================================
# ВАЛИДАЦИЯ КОНФИГУРАЦИИ / CONFIGURATION VALIDATION
# =============================================================================

def validate_config():
    """
    Проверка корректности конфигурации
    Validates configuration settings
    """
    errors = []
    warnings = []
    
    # Проверка обязательных параметров
    if not DB_HOST:
        errors.append("DB_HOST не может быть пустым")
    
    if not DB_NAME:
        errors.append("DB_NAME не может быть пустым")
    
    if not OZON_CLIENT_ID:
        warnings.append("OZON_CLIENT_ID не задан - будет использован из базы данных")
    
    if not OZON_API_KEY:
        warnings.append("OZON_API_KEY не задан - будет использован из базы данных")
    
    # Проверка email настроек
    if SEND_SUCCESS_NOTIFICATIONS or SEND_ERROR_NOTIFICATIONS:
        if not SMTP_USER:
            warnings.append("SMTP_USER не задан - email уведомления отключены")
        
        if not ADMIN_EMAILS:
            warnings.append("ADMIN_EMAILS пуст - некому отправлять уведомления")
    
    # Проверка числовых параметров
    if LOOKBACK_DAYS <= 0:
        errors.append("LOOKBACK_DAYS должен быть больше 0")
    
    if MAX_DATE_RANGE_DAYS <= 0:
        errors.append("MAX_DATE_RANGE_DAYS должен быть больше 0")
    
    if BATCH_SIZE <= 0:
        errors.append("BATCH_SIZE должен быть больше 0")
    
    # Проверка логических параметров
    if LOOKBACK_DAYS > MAX_DATE_RANGE_DAYS:
        warnings.append("LOOKBACK_DAYS больше MAX_DATE_RANGE_DAYS - может вызвать проблемы")
    
    return errors, warnings


# Автоматическая валидация при импорте
if __name__ == "__main__":
    errors, warnings = validate_config()
    
    if errors:
        print("❌ ОШИБКИ КОНФИГУРАЦИИ:")
        for error in errors:
            print(f"  - {error}")
    
    if warnings:
        print("⚠️ ПРЕДУПРЕЖДЕНИЯ КОНФИГУРАЦИИ:")
        for warning in warnings:
            print(f"  - {warning}")
    
    if not errors and not warnings:
        print("✅ Конфигурация корректна")
    
    print(f"\nИспользуйте эту конфигурацию, скопировав файл в config.py")