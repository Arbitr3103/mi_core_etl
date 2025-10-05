#!/bin/bash
"""
Setup Script for Ozon Analytics Weekly Update System
Скрипт установки системы еженедельного обновления Ozon Analytics

Использование:
  ./setup_ozon_weekly_update.sh

Автор: Manhattan System
Версия: 1.0
"""

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функции для цветного вывода
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_header() {
    echo -e "\n${BLUE}=== $1 ===${NC}"
}

# Получение директории скрипта
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

print_header "УСТАНОВКА СИСТЕМЫ ЕЖЕНЕДЕЛЬНОГО ОБНОВЛЕНИЯ OZON ANALYTICS"

# Проверка операционной системы
print_info "Проверка операционной системы..."
OS=$(uname -s)
print_info "Операционная система: $OS"

# Проверка Python
print_header "ПРОВЕРКА PYTHON"
PYTHON_CMD=""
if command -v python3 &> /dev/null; then
    PYTHON_CMD="python3"
    PYTHON_VERSION=$(python3 --version)
    print_success "Python3 найден: $PYTHON_VERSION"
elif command -v python &> /dev/null; then
    PYTHON_CMD="python"
    PYTHON_VERSION=$(python --version)
    print_warning "Используется Python 2: $PYTHON_VERSION (рекомендуется Python 3)"
else
    print_error "Python не найден в системе!"
    print_info "Установите Python 3.6+ и повторите установку"
    exit 1
fi

# Проверка pip
print_info "Проверка pip..."
PIP_CMD=""
if command -v pip3 &> /dev/null; then
    PIP_CMD="pip3"
elif command -v pip &> /dev/null; then
    PIP_CMD="pip"
else
    print_error "pip не найден!"
    print_info "Установите pip и повторите установку"
    exit 1
fi

print_success "pip найден: $(which $PIP_CMD)"

# Установка зависимостей Python
print_header "УСТАНОВКА ЗАВИСИМОСТЕЙ PYTHON"
REQUIRED_PACKAGES=(
    "mysql-connector-python>=8.0.0"
    "requests>=2.25.0"
)

print_info "Установка необходимых пакетов..."
for package in "${REQUIRED_PACKAGES[@]}"; do
    print_info "Установка $package..."
    if $PIP_CMD install "$package" --user; then
        print_success "$package установлен успешно"
    else
        print_error "Ошибка установки $package"
        exit 1
    fi
done

# Проверка установленных пакетов
print_info "Проверка установленных пакетов..."
for package in "mysql.connector" "requests"; do
    if $PYTHON_CMD -c "import $package" &> /dev/null; then
        print_success "$package доступен"
    else
        print_error "$package не найден после установки"
        exit 1
    fi
done

# Создание директорий
print_header "СОЗДАНИЕ ДИРЕКТОРИЙ"
DIRECTORIES=("logs" "backups" "temp")

for dir in "${DIRECTORIES[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        print_success "Создана директория: $dir"
    else
        print_info "Директория уже существует: $dir"
    fi
done

# Настройка прав доступа
print_header "НАСТРОЙКА ПРАВ ДОСТУПА"
chmod +x run_ozon_weekly_update.sh
print_success "Права на выполнение установлены для run_ozon_weekly_update.sh"

chmod +x setup_ozon_weekly_update.sh
print_success "Права на выполнение установлены для setup_ozon_weekly_update.sh"

# Создание конфигурационного файла
print_header "НАСТРОЙКА КОНФИГУРАЦИИ"
if [ ! -f "config.py" ]; then
    if [ -f "ozon_update_config.py" ]; then
        cp "ozon_update_config.py" "config.py"
        print_success "Создан файл конфигурации config.py"
        print_warning "ВАЖНО: Отредактируйте config.py и укажите ваши настройки!"
    else
        print_error "Шаблон конфигурации ozon_update_config.py не найден"
    fi
else
    print_info "Файл конфигурации config.py уже существует"
fi

# Проверка конфигурации
if [ -f "config.py" ]; then
    print_info "Проверка конфигурации..."
    if $PYTHON_CMD ozon_update_config.py; then
        print_success "Конфигурация проверена"
    else
        print_warning "Обнаружены проблемы в конфигурации - проверьте config.py"
    fi
fi

# Тестовый запуск
print_header "ТЕСТОВЫЙ ЗАПУСК"
print_info "Выполнение тестового запуска системы..."

# Создаем временный конфигурационный файл для теста
cat > test_config.py << 'EOF'
# Тестовая конфигурация
DB_HOST = 'localhost'
DB_USER = 'test'
DB_PASSWORD = 'test'
DB_NAME = 'test'
DB_PORT = 3306

OZON_CLIENT_ID = 'test'
OZON_API_KEY = 'test'

SMTP_SERVER = 'localhost'
SMTP_PORT = 587
SMTP_USER = ''
SMTP_PASSWORD = ''
FROM_EMAIL = 'test@example.com'
ADMIN_EMAILS = ['admin@example.com']

LOOKBACK_DAYS = 7
MAX_DATE_RANGE_DAYS = 30
BATCH_SIZE = 10
EOF

# Проверяем импорт основного модуля
if $PYTHON_CMD -c "
import sys
sys.path.append('.')
try:
    from ozon_weekly_update import OzonWeeklyUpdater
    print('✅ Основной модуль импортируется успешно')
except ImportError as e:
    print(f'❌ Ошибка импорта: {e}')
    sys.exit(1)
except Exception as e:
    print(f'⚠️ Предупреждение при импорте: {e}')
"; then
    print_success "Тестовый запуск прошел успешно"
else
    print_error "Ошибка при тестовом запуске"
fi

# Удаляем тестовый конфигурационный файл
rm -f test_config.py

# Настройка cron задачи
print_header "НАСТРОЙКА CRON ЗАДАЧИ"
CRON_ENTRY="0 2 * * 0 $SCRIPT_DIR/run_ozon_weekly_update.sh"

print_info "Рекомендуемая cron задача для еженедельного запуска:"
echo -e "${GREEN}$CRON_ENTRY${NC}"

read -p "Добавить эту задачу в crontab? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    # Проверяем, есть ли уже такая задача
    if crontab -l 2>/dev/null | grep -q "$SCRIPT_DIR/run_ozon_weekly_update.sh"; then
        print_warning "Задача уже существует в crontab"
    else
        # Добавляем задачу в crontab
        (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
        print_success "Cron задача добавлена успешно"
    fi
else
    print_info "Cron задача не добавлена. Вы можете добавить её позже командой:"
    echo "  crontab -e"
    echo "  Добавьте строку: $CRON_ENTRY"
fi

# Создание документации
print_header "СОЗДАНИЕ ДОКУМЕНТАЦИИ"
cat > OZON_UPDATE_README.md << 'EOF'
# Ozon Analytics Weekly Update System

Система еженедельного обновления аналитических данных Ozon.

## Файлы системы

- `ozon_weekly_update.py` - Основной скрипт обновления
- `run_ozon_weekly_update.sh` - Cron скрипт для запуска
- `config.py` - Файл конфигурации
- `setup_ozon_weekly_update.sh` - Скрипт установки

## Настройка

1. Отредактируйте `config.py` и укажите:
   - Параметры подключения к базе данных
   - Учетные данные Ozon API
   - Настройки email уведомлений

2. Проверьте настройки:
   ```bash
   python3 ozon_update_config.py
   ```

## Запуск

### Ручной запуск
```bash
./run_ozon_weekly_update.sh
```

### Автоматический запуск (cron)
```bash
# Каждое воскресенье в 02:00
0 2 * * 0 /path/to/run_ozon_weekly_update.sh
```

## Логи

Логи сохраняются в директории `logs/`:
- `ozon_update_YYYYMMDD_HHMMSS.log` - Детальные логи обновления
- `cron_ozon_update.log` - Логи cron запусков

## Мониторинг

Система отправляет email уведомления о:
- Успешном завершении обновления
- Ошибках при обновлении
- Предупреждениях (опционально)

## Устранение неполадок

1. Проверьте логи в директории `logs/`
2. Убедитесь, что база данных доступна
3. Проверьте учетные данные Ozon API
4. Проверьте настройки email

## Поддержка

Для получения поддержки обратитесь к администратору системы.
EOF

print_success "Создана документация: OZON_UPDATE_README.md"

# Финальная проверка
print_header "ФИНАЛЬНАЯ ПРОВЕРКА"
ISSUES=0

# Проверка файлов
REQUIRED_FILES=(
    "ozon_weekly_update.py"
    "run_ozon_weekly_update.sh"
    "config.py"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ -f "$file" ]; then
        print_success "✓ $file"
    else
        print_error "✗ $file отсутствует"
        ((ISSUES++))
    fi
done

# Проверка директорий
for dir in "${DIRECTORIES[@]}"; do
    if [ -d "$dir" ]; then
        print_success "✓ Директория $dir"
    else
        print_error "✗ Директория $dir отсутствует"
        ((ISSUES++))
    fi
done

# Проверка прав доступа
if [ -x "run_ozon_weekly_update.sh" ]; then
    print_success "✓ Права на выполнение run_ozon_weekly_update.sh"
else
    print_error "✗ Нет прав на выполнение run_ozon_weekly_update.sh"
    ((ISSUES++))
fi

# Итоговый результат
print_header "РЕЗУЛЬТАТ УСТАНОВКИ"
if [ $ISSUES -eq 0 ]; then
    print_success "🎉 УСТАНОВКА ЗАВЕРШЕНА УСПЕШНО!"
    echo
    print_info "Следующие шаги:"
    echo "1. Отредактируйте config.py и укажите ваши настройки"
    echo "2. Проверьте подключение к базе данных"
    echo "3. Протестируйте систему: ./run_ozon_weekly_update.sh"
    echo "4. Настройте cron для автоматического запуска"
    echo
    print_info "Документация доступна в файле OZON_UPDATE_README.md"
else
    print_error "❌ УСТАНОВКА ЗАВЕРШЕНА С ОШИБКАМИ ($ISSUES проблем)"
    print_info "Исправьте обнаруженные проблемы и повторите установку"
    exit 1
fi