"""
Конфигурационный файл для ETL системы mi_core_etl.

Содержит настройки подключения к API маркетплейсов и другие конфигурации.
Все секретные данные загружаются из .env файла.

Автор: ETL System
Дата: 20 сентября 2025
"""

import os
from dotenv import load_dotenv

# Загружаем переменные из .env файла
load_dotenv()

# ===================================================================
# НАСТРОЙКИ API OZON
# ===================================================================
# Загружаем из .env файла
OZON_CLIENT_ID = os.getenv('OZON_CLIENT_ID')
OZON_API_KEY = os.getenv('OZON_API_KEY')

# Базовый URL для API Ozon (ИСПРАВЛЕНО: используем правильный URL)
OZON_API_BASE_URL = "https://api-seller.ozon.ru"

# ===================================================================
# НАСТРОЙКИ API WILDBERRIES
# ===================================================================
# Загружаем из .env файла (ИСПРАВЛЕНО: используем правильное имя переменной)
WB_API_TOKEN = os.getenv('WB_API_KEY')

# Базовые URL для API Wildberries
WB_SUPPLIERS_API_URL = "https://suppliers-api.wildberries.ru"
WB_CONTENT_API_URL = "https://content-api.wildberries.ru"
WB_STATISTICS_API_URL = "https://statistics-api.wildberries.ru"

# ===================================================================
# НАСТРОЙКИ БАЗЫ ДАННЫХ
# ===================================================================
# Настройки подключения к MySQL (загружаются из .env или используются значения по умолчанию)
DB_HOST = os.getenv('DB_HOST', 'localhost')
DB_USER = os.getenv('DB_USER')
DB_PASSWORD = os.getenv('DB_PASSWORD')
DB_NAME = os.getenv('DB_NAME', 'mi_core_db')
DB_PORT = int(os.getenv('DB_PORT', '3306'))

# ===================================================================
# НАСТРОЙКИ СИСТЕМЫ УПРАВЛЕНИЯ СКЛАДОМ
# ===================================================================
# Настройки для импорта остатков
INVENTORY_UPDATE_INTERVAL_HOURS = 24  # Интервал обновления остатков (в часах)
MOVEMENTS_UPDATE_INTERVAL_HOURS = 2   # Интервал обновления движений (в часах)

# Настройки для анализа оборачиваемости
TURNOVER_ANALYSIS_DAYS = 30          # Период анализа оборачиваемости (в днях)
MIN_STOCK_DAYS = 7                   # Минимальный запас в днях
REORDER_POINT_MULTIPLIER = 1.5       # Множитель для точки заказа

# ===================================================================
# НАСТРОЙКИ ЛОГИРОВАНИЯ
# ===================================================================
LOG_LEVEL = "INFO"
LOG_FORMAT = "%(asctime)s - %(levelname)s - %(message)s"
LOG_DIR = "logs"

# ===================================================================
# НАСТРОЙКИ API ЗАПРОСОВ
# ===================================================================
# Задержки между запросами (в секундах)
OZON_REQUEST_DELAY = 0.1
WB_REQUEST_DELAY = 0.5

# Таймауты для запросов (в секундах)
REQUEST_TIMEOUT = 30

# Количество повторных попыток при ошибках
MAX_RETRIES = 3

# ===================================================================
# НАСТРОЙКИ УВЕДОМЛЕНИЙ
# ===================================================================
# Email настройки (опционально)
EMAIL_ENABLED = False
SMTP_SERVER = "smtp.gmail.com"
SMTP_PORT = 587
EMAIL_USER = "your_email@gmail.com"
EMAIL_PASSWORD = "your_email_password"
NOTIFICATION_RECIPIENTS = ["admin@yourcompany.com"]

# Telegram настройки (опционально)
TELEGRAM_ENABLED = False
TELEGRAM_BOT_TOKEN = "your_telegram_bot_token"
TELEGRAM_CHAT_ID = "your_telegram_chat_id"

# ===================================================================
# ДОПОЛНИТЕЛЬНЫЕ НАСТРОЙКИ
# ===================================================================
# Временная зона
TIMEZONE = "Europe/Moscow"

# Настройки для обработки файлов
TEMP_DIR = "/tmp/mi_core_etl"
ARCHIVE_DIR = "archive"

# Настройки для резервного копирования
BACKUP_ENABLED = True
BACKUP_RETENTION_DAYS = 30

# ===================================================================
# ИНСТРУКЦИИ ПО НАСТРОЙКЕ
# ===================================================================
"""
ИНСТРУКЦИИ ПО НАСТРОЙКЕ .env ФАЙЛА:

1. Создайте файл .env в корне проекта (если его нет)

2. Добавьте следующие переменные в .env файл:

# API ключи Ozon
OZON_CLIENT_ID=your_ozon_client_id_here
OZON_API_KEY=your_ozon_api_key_here

# API ключ Wildberries (JWT токен)
WB_API_KEY=your_wb_api_key_here

# Настройки базы данных (опционально, если отличаются от стандартных)
DB_HOST=localhost
DB_USER=your_db_user
DB_PASSWORD=your_db_password
DB_NAME=mi_core_db
DB_PORT=3306

3. ПОЛУЧЕНИЕ API КЛЮЧЕЙ:

OZON API:
   - Войдите в личный кабинет Ozon Seller
   - Перейдите в раздел "Настройки" -> "API ключи"
   - Создайте новый API ключ с правами на чтение данных
   - Скопируйте Client-Id и Api-Key в .env файл

WILDBERRIES API:
   - Войдите в личный кабинет WB
   - Перейдите в "Настройки" -> "Доступ к API"
   - Создайте новый токен с правами:
     * Контент (чтение)
     * Статистика (чтение)
     * Поставки (чтение)
   - Скопируйте полученный токен в .env файл

4. БЕЗОПАСНОСТЬ:
   - .env файл уже добавлен в .gitignore
   - Не передавайте API ключи третьим лицам
   - Регулярно обновляйте ключи
   - Никогда не коммитьте .env файл в репозиторий

5. ПРОВЕРКА НАСТРОЕК:
   - Запустите: python3 test_inventory_system.py
   - Убедитесь, что все API ключи загружаются корректно
"""

# ===================================================================
# ФУНКЦИИ ПРОВЕРКИ КОНФИГУРАЦИИ
# ===================================================================

def validate_config():
    """
    Проверяет корректность конфигурации.
    Возвращает список ошибок или пустой список если все в порядке.
    """
    errors = []
    warnings = []
    
    # Проверяем обязательные API ключи Ozon
    if not OZON_CLIENT_ID:
        errors.append("OZON_CLIENT_ID не найден в .env файле")
    elif len(OZON_CLIENT_ID) < 3:
        errors.append("OZON_CLIENT_ID слишком короткий")
    
    if not OZON_API_KEY:
        errors.append("OZON_API_KEY не найден в .env файле")
    elif len(OZON_API_KEY) < 30:
        errors.append("OZON_API_KEY слишком короткий (должен быть ~36 символов)")
    
    # Проверяем WB API токен (не критично)
    if not WB_API_TOKEN:
        warnings.append("WB_API_KEY не найден в .env файле (Wildberries API недоступен)")
    elif len(WB_API_TOKEN) < 50:
        warnings.append("WB_API_KEY может быть некорректным (слишком короткий)")
    
    # Проверяем настройки БД
    if not DB_USER:
        errors.append("DB_USER не найден в .env файле")
    
    if not DB_PASSWORD:
        errors.append("DB_PASSWORD не найден в .env файле")
    
    return errors, warnings

def print_config_status():
    """Выводит статус конфигурации."""
    print("📋 СТАТУС КОНФИГУРАЦИИ:")
    print("=" * 40)
    
    # Проверяем API ключи (показываем только длину для безопасности)
    print(f"OZON_CLIENT_ID: {'✅ Загружен' if OZON_CLIENT_ID else '❌ Отсутствует'} ({len(OZON_CLIENT_ID or '')} символов)")
    print(f"OZON_API_KEY: {'✅ Загружен' if OZON_API_KEY else '❌ Отсутствует'} ({len(OZON_API_KEY or '')} символов)")
    print(f"WB_API_TOKEN: {'✅ Загружен' if WB_API_TOKEN else '❌ Отсутствует'} ({len(WB_API_TOKEN or '')} символов)")
    
    print(f"DB_HOST: {DB_HOST}")
    print(f"DB_NAME: {DB_NAME}")
    print(f"DB_USER: {'✅ Загружен' if DB_USER else '❌ Отсутствует'}")
    print(f"DB_PASSWORD: {'✅ Загружен' if DB_PASSWORD else '❌ Отсутствует'}")
    
    errors, warnings = validate_config()
    
    if warnings:
        print("\n⚠️ ПРЕДУПРЕЖДЕНИЯ:")
        for warning in warnings:
            print(f"  - {warning}")
    
    if errors:
        print("\n❌ ОШИБКИ КОНФИГУРАЦИИ:")
        for error in errors:
            print(f"  - {error}")
    else:
        print("\n✅ Конфигурация корректна!")
        
    # Добавляем информацию о базовых URL
    print(f"\n🌐 API ENDPOINTS:")
    print(f"Ozon API: {OZON_API_BASE_URL}")
    print(f"WB Suppliers API: {WB_SUPPLIERS_API_URL}")
    print(f"WB Statistics API: {WB_STATISTICS_API_URL}")

if __name__ == "__main__":
    print_config_status()
