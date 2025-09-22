#!/bin/bash

# Скрипт развертывания системы пополнения склада
# Автор: Команда разработки
# Версия: 1.0.0

set -e  # Остановка при ошибке

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функции для вывода
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

# Проверка зависимостей
check_dependencies() {
    print_info "Проверка зависимостей..."
    
    # Проверка Docker
    if ! command -v docker &> /dev/null; then
        print_error "Docker не установлен. Установите Docker и повторите попытку."
        exit 1
    fi
    
    # Проверка Docker Compose
    if ! command -v docker-compose &> /dev/null; then
        print_error "Docker Compose не установлен. Установите Docker Compose и повторите попытку."
        exit 1
    fi
    
    print_success "Все зависимости установлены"
}

# Создание необходимых директорий
create_directories() {
    print_info "Создание директорий..."
    
    mkdir -p logs
    mkdir -p reports
    mkdir -p ssl
    
    print_success "Директории созданы"
}

# Настройка переменных окружения
setup_environment() {
    print_info "Настройка переменных окружения..."
    
    if [ ! -f .env ]; then
        print_info "Создание файла .env..."
        cat > .env << EOF
# База данных
MYSQL_ROOT_PASSWORD=replenishment_root_password_$(date +%s)
MYSQL_PASSWORD=replenishment_password_$(date +%s)
MYSQL_USER=replenishment_user
MYSQL_DATABASE=replenishment_db

# Приложение
APP_ENV=production
DEBUG=false
LOG_LEVEL=INFO

# API
API_HOST=0.0.0.0
API_PORT=8000

# Безопасность
SECRET_KEY=$(openssl rand -hex 32)
EOF
        print_success "Файл .env создан"
    else
        print_warning "Файл .env уже существует"
    fi
}

# Сборка Docker образов
build_images() {
    print_info "Сборка Docker образов..."
    
    docker-compose build --no-cache
    
    print_success "Docker образы собраны"
}

# Запуск сервисов
start_services() {
    print_info "Запуск сервисов..."
    
    # Остановка существующих контейнеров
    docker-compose down --remove-orphans
    
    # Запуск в фоновом режиме
    docker-compose up -d
    
    print_success "Сервисы запущены"
}

# Проверка здоровья сервисов
check_health() {
    print_info "Проверка здоровья сервисов..."
    
    # Ожидание запуска MySQL
    print_info "Ожидание запуска MySQL..."
    timeout=60
    while [ $timeout -gt 0 ]; do
        if docker-compose exec -T mysql mysqladmin ping -h localhost --silent; then
            break
        fi
        sleep 2
        timeout=$((timeout-2))
    done
    
    if [ $timeout -le 0 ]; then
        print_error "MySQL не запустился в течение 60 секунд"
        exit 1
    fi
    
    print_success "MySQL запущен"
    
    # Ожидание запуска приложения
    print_info "Ожидание запуска приложения..."
    timeout=60
    while [ $timeout -gt 0 ]; do
        if curl -f http://localhost:8000/api/health &> /dev/null; then
            break
        fi
        sleep 2
        timeout=$((timeout-2))
    done
    
    if [ $timeout -le 0 ]; then
        print_error "Приложение не запустилось в течение 60 секунд"
        exit 1
    fi
    
    print_success "Приложение запущено"
}

# Инициализация данных
initialize_data() {
    print_info "Инициализация данных..."
    
    # Запуск начального анализа (опционально)
    # docker-compose exec replenishment_app python3 replenishment_orchestrator.py --mode quick
    
    print_success "Данные инициализированы"
}

# Показ информации о развертывании
show_deployment_info() {
    print_success "🎉 Развертывание завершено успешно!"
    echo
    print_info "📋 Информация о развертывании:"
    echo "  🌐 Веб-интерфейс: http://localhost:8000"
    echo "  🔍 API здоровья: http://localhost:8000/api/health"
    echo "  📊 API рекомендаций: http://localhost:8000/api/recommendations"
    echo "  🚨 API алертов: http://localhost:8000/api/alerts"
    echo
    print_info "📁 Директории:"
    echo "  📝 Логи: ./logs/"
    echo "  📊 Отчеты: ./reports/"
    echo
    print_info "🐳 Docker команды:"
    echo "  📊 Статус: docker-compose ps"
    echo "  📝 Логи: docker-compose logs -f"
    echo "  ⏹️  Остановка: docker-compose down"
    echo "  🔄 Перезапуск: docker-compose restart"
    echo
    print_info "🔧 Управление:"
    echo "  📋 Полный анализ: docker-compose exec replenishment_app python3 replenishment_orchestrator.py --mode full"
    echo "  ⚡ Быстрая проверка: docker-compose exec replenishment_app python3 replenishment_orchestrator.py --mode quick"
    echo "  📊 Экспорт отчета: docker-compose exec replenishment_app python3 replenishment_orchestrator.py --mode export --export-file report.csv"
}

# Основная функция
main() {
    echo "🚀 Развертывание системы пополнения склада"
    echo "=" * 50
    
    check_dependencies
    create_directories
    setup_environment
    build_images
    start_services
    check_health
    initialize_data
    show_deployment_info
}

# Обработка аргументов командной строки
case "${1:-deploy}" in
    "deploy")
        main
        ;;
    "stop")
        print_info "Остановка сервисов..."
        docker-compose down
        print_success "Сервисы остановлены"
        ;;
    "restart")
        print_info "Перезапуск сервисов..."
        docker-compose restart
        print_success "Сервисы перезапущены"
        ;;
    "logs")
        docker-compose logs -f
        ;;
    "status")
        docker-compose ps
        ;;
    "clean")
        print_warning "Удаление всех контейнеров и данных..."
        read -p "Вы уверены? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            docker-compose down -v --remove-orphans
            docker system prune -f
            print_success "Очистка завершена"
        else
            print_info "Очистка отменена"
        fi
        ;;
    "help")
        echo "Использование: $0 [команда]"
        echo
        echo "Команды:"
        echo "  deploy   - Развернуть систему (по умолчанию)"
        echo "  stop     - Остановить сервисы"
        echo "  restart  - Перезапустить сервисы"
        echo "  logs     - Показать логи"
        echo "  status   - Показать статус сервисов"
        echo "  clean    - Удалить все контейнеры и данные"
        echo "  help     - Показать эту справку"
        ;;
    *)
        print_error "Неизвестная команда: $1"
        print_info "Используйте '$0 help' для справки"
        exit 1
        ;;
esac