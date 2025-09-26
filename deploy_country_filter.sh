#!/bin/bash

# Deployment script for Country Filter System
# Быстрое развертывание системы фильтрации по стране изготовления на сервере
#
# Usage: ./deploy_country_filter.sh [environment]
# Example: ./deploy_country_filter.sh production

set -e

# Цвета для вывода
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Функции для вывода
print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_header() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}================================${NC}"
}

# Параметры
ENVIRONMENT=${1:-production}
BACKUP_DIR="backup_$(date +%Y%m%d_%H%M%S)"

print_header "🚗 РАЗВЕРТЫВАНИЕ СИСТЕМЫ ФИЛЬТРАЦИИ ПО СТРАНАМ"

echo "Окружение: $ENVIRONMENT"
echo "Дата: $(date)"
echo ""

# Проверка окружения
print_info "Проверка окружения сервера..."

# Проверка PHP
if ! command -v php &> /dev/null; then
    print_error "PHP не найден! Установите PHP 7.4+ для работы системы"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;")
print_success "PHP найден: $PHP_VERSION"

# Проверка веб-сервера
if command -v nginx &> /dev/null; then
    print_success "Nginx найден"
    WEB_SERVER="nginx"
elif command -v apache2 &> /dev/null; then
    print_success "Apache найден"
    WEB_SERVER="apache"
else
    print_warning "Веб-сервер не обнаружен - убедитесь что он настроен"
fi

# Проверка MySQL
if command -v mysql &> /dev/null; then
    print_success "MySQL найден"
else
    print_warning "MySQL не найден - убедитесь что база данных доступна"
fi

echo ""

# Создание бэкапа (если есть существующие файлы)
print_info "Создание бэкапа существующих файлов..."

if [ -d "src" ] || [ -f "src/CountryFilterAPI.php" ]; then
    mkdir -p "$BACKUP_DIR"
    
    # Бэкап основных файлов
    [ -d "src" ] && cp -r "src" "$BACKUP_DIR/"
    
    print_success "Бэкап создан в директории: $BACKUP_DIR"
else
    print_info "Существующих файлов не найдено - чистая установка"
fi

echo ""

# Проверка файлов системы
print_info "Проверка файлов системы фильтрации..."

REQUIRED_FILES=(
    "src/CountryFilterAPI.php"
    "src/api/countries.php"
    "src/api/countries-by-brand.php"
    "src/api/countries-by-model.php"
    "src/api/products-filter.php"
    "src/js/CountryFilter.js"
    "src/css/country-filter.css"
)

MISSING_FILES=()

for file in "${REQUIRED_FILES[@]}"; do
    if [ -f "$file" ]; then
        print_success "Найден: $file"
    else
        print_error "Отсутствует: $file"
        MISSING_FILES+=("$file")
    fi
done

if [ ${#MISSING_FILES[@]} -gt 0 ]; then
    print_error "Отсутствуют критически важные файлы!"
    echo "Убедитесь что все файлы загружены из репозитория"
    exit 1
fi

echo ""

# Настройка прав доступа
print_info "Настройка прав доступа к файлам..."

# Права на PHP файлы в src/
find src/ -name "*.php" -exec chmod 644 {} \; 2>/dev/null || true
print_success "Права на PHP файлы установлены (644)"

# Права на JavaScript и CSS в src/
find src/ -name "*.js" -exec chmod 644 {} \; 2>/dev/null || true
find src/ -name "*.css" -exec chmod 644 {} \; 2>/dev/null || true
print_success "Права на статические файлы установлены (644)"

# Права на директории в src/
find src/ -type d -exec chmod 755 {} \; 2>/dev/null || true
print_success "Права на директории установлены (755)"

# Права на исполняемые скрипты
chmod +x apply_country_filter_optimization.sh
chmod +x tests/run_complete_tests.sh
chmod +x tests/final_comprehensive_test.sh
print_success "Права на исполняемые файлы установлены"

echo ""

# Проверка конфигурации базы данных
print_info "Проверка конфигурации базы данных..."

if [ -f "src/config/database.php" ]; then
    print_success "Найден файл конфигурации БД"
elif [ -f "src/CountryFilterAPI.php" ]; then
    print_warning "Проверьте настройки БД в src/CountryFilterAPI.php"
    echo "Убедитесь что указаны правильные параметры подключения:"
    echo "- host, database, username, password"
else
    print_error "Не найдена конфигурация базы данных!"
fi

echo ""

# Создание индексов базы данных (если файл существует)
if [ -f "create_country_filter_indexes.sql" ]; then
    print_info "Найден файл создания индексов БД"
    print_warning "Выполните вручную: mysql -u username -p database < create_country_filter_indexes.sql"
    print_warning "Это улучшит производительность системы"
fi

echo ""

# Тестирование API endpoints
print_info "Тестирование API endpoints..."

if [ -f "src/test_country_filter_api.php" ]; then
    print_info "Запуск базовых тестов API..."
    
    if cd src && php test_country_filter_api.php > /tmp/api_test.log 2>&1; then
        if grep -q "SUCCESS\|✅" /tmp/api_test.log; then
            print_success "API тесты пройдены успешно"
        else
            print_warning "API тесты завершились с предупреждениями"
            echo "Проверьте лог: /tmp/api_test.log"
        fi
    else
        print_error "API тесты провалены"
        echo "Проверьте лог: /tmp/api_test.log"
        echo "Возможные причины:"
        echo "- Неправильные настройки БД"
        echo "- Отсутствующие таблицы"
        echo "- Проблемы с правами доступа"
    fi
else
    print_warning "Файл тестов API не найден - пропускаем тестирование"
fi

echo ""

# Настройка веб-сервера
print_info "Рекомендации по настройке веб-сервера..."

if [ "$WEB_SERVER" = "nginx" ]; then
    echo "Для Nginx добавьте в конфигурацию сайта:"
    echo ""
    echo "location /api/ {"
    echo "    try_files \$uri \$uri/ /api/index.php;"
    echo "    location ~ \.php$ {"
    echo "        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;"
    echo "        fastcgi_index index.php;"
    echo "        include fastcgi_params;"
    echo "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;"
    echo "    }"
    echo "}"
    echo ""
elif [ "$WEB_SERVER" = "apache" ]; then
    echo "Для Apache убедитесь что включен mod_rewrite:"
    echo "a2enmod rewrite"
    echo ""
    echo "И добавьте в .htaccess или конфигурацию:"
    echo "RewriteEngine On"
    echo "RewriteCond %{REQUEST_FILENAME} !-f"
    echo "RewriteCond %{REQUEST_FILENAME} !-d"
    echo "RewriteRule ^api/(.*)$ api/index.php [QSA,L]"
fi

echo ""

# Проверка производительности
if [ -f "src/test_country_filter_performance.php" ]; then
    print_info "Запуск тестов производительности..."
    
    if cd src && php test_country_filter_performance.php > /tmp/performance_test.log 2>&1; then
        print_success "Тесты производительности завершены"
        echo "Результаты в: /tmp/performance_test.log"
    else
        print_warning "Проблемы с тестами производительности"
    fi
fi

echo ""

# Копирование в веб-директорию (если запущено с правами root)
if [ "$EUID" -eq 0 ] || [ "$(whoami)" = "root" ]; then
    print_info "Копирование файлов в веб-директорию..."
    
    if [ -d "src" ]; then
        # Создаем бэкап существующих файлов в /var/www/html
        if [ -d "/var/www/html/src" ]; then
            mv /var/www/html/src /var/www/html/src_backup_$(date +%Y%m%d_%H%M%S)
            print_info "Создан бэкап существующих файлов в /var/www/html"
        fi
        
        # Копируем новые файлы
        cp -r src/ /var/www/html/
        chown -R www-data:www-data /var/www/html/src/
        chmod -R 755 /var/www/html/src/
        
        print_success "Файлы скопированы в /var/www/html/src/"
        print_success "Права доступа настроены для www-data"
    else
        print_error "Директория src/ не найдена!"
    fi
else
    print_warning "Для копирования в /var/www/html требуются права root"
    print_info "Выполните вручную:"
    echo "  sudo cp -r src/ /var/www/html/"
    echo "  sudo chown -R www-data:www-data /var/www/html/src/"
    echo "  sudo chmod -R 755 /var/www/html/src/"
fi

echo ""

# Финальные проверки
print_header "🎯 ФИНАЛЬНЫЕ ПРОВЕРКИ"

print_info "Проверка готовности к работе..."

# Проверка доступности демо страниц
DEMO_PAGES=(
    "src/demo/country-filter-demo.html"
    "src/demo/mobile-country-filter-demo.html"
)

for page in "${DEMO_PAGES[@]}"; do
    if [ -f "$page" ]; then
        print_success "Демо страница: $page"
    else
        print_warning "Отсутствует демо: $page"
    fi
done

echo ""

# Итоговый статус
print_header "🚀 СТАТУС РАЗВЕРТЫВАНИЯ"

if [ ${#MISSING_FILES[@]} -eq 0 ]; then
    print_success "Все файлы системы на месте"
    print_success "Права доступа настроены"
    print_success "Система готова к работе!"
    
    echo ""
    echo "📋 СЛЕДУЮЩИЕ ШАГИ:"
    echo "1. Проверьте настройки базы данных"
    echo "2. Выполните SQL скрипт создания индексов (если нужно)"
    echo "3. Настройте веб-сервер согласно рекомендациям"
    echo "4. Протестируйте работу через демо страницы"
    echo "5. Интегрируйте в основное приложение"
    
    echo ""
    echo "🔗 ПОЛЕЗНЫЕ ССЫЛКИ:"
    echo "- Демо: /demo/country-filter-demo.html"
    echo "- Мобильная версия: /demo/mobile-country-filter-demo.html"
    echo "- API документация: COUNTRY_FILTER_API_GUIDE.md"
    echo "- Руководство по производительности: COUNTRY_FILTER_PERFORMANCE_GUIDE.md"
    
    echo ""
    print_success "🎉 РАЗВЕРТЫВАНИЕ ЗАВЕРШЕНО УСПЕШНО!"
    
else
    print_error "Развертывание завершено с ошибками"
    echo "Исправьте проблемы и запустите скрипт повторно"
    exit 1
fi

echo ""
echo "Время завершения: $(date)"
echo "Бэкап (если создан): $BACKUP_DIR"