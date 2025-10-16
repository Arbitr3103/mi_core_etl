#!/bin/bash

# ===================================================================
# PRE-DEPLOYMENT READINESS CHECK
# ===================================================================
# Проверяет готовность системы к развертыванию активной фильтрации

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}🔍 Проверка готовности к развертыванию Ozon Active Product Filtering${NC}"
echo "=================================================================="

# Функции для проверок
check_passed() {
    echo -e "${GREEN}✅ $1${NC}"
}

check_failed() {
    echo -e "${RED}❌ $1${NC}"
}

check_warning() {
    echo -e "${YELLOW}⚠️ $1${NC}"
}

# Счетчики
PASSED=0
FAILED=0
WARNINGS=0

# 1. Проверка PHP
echo -e "\n${BLUE}1. Проверка PHP${NC}"
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -n1 | cut -d' ' -f2)
    check_passed "PHP установлен (версия: $PHP_VERSION)"
    ((PASSED++))
else
    check_failed "PHP не найден"
    ((FAILED++))
fi

# 2. Проверка MySQL
echo -e "\n${BLUE}2. Проверка MySQL${NC}"
if command -v mysql &> /dev/null; then
    MYSQL_VERSION=$(mysql --version | cut -d' ' -f3)
    check_passed "MySQL клиент установлен (версия: $MYSQL_VERSION)"
    ((PASSED++))
else
    check_failed "MySQL клиент не найден"
    ((FAILED++))
fi

# 3. Проверка подключения к базе данных
echo -e "\n${BLUE}3. Проверка подключения к базе данных${NC}"
if [[ -f "config.php" ]]; then
    if php -r "
        require_once 'config.php';
        try {
            \$pdo = getDatabaseConnection();
            echo 'OK';
        } catch (Exception \$e) {
            echo 'FAIL: ' . \$e->getMessage();
            exit(1);
        }
    " > /dev/null 2>&1; then
        check_passed "Подключение к базе данных работает"
        ((PASSED++))
    else
        check_failed "Не удается подключиться к базе данных"
        ((FAILED++))
    fi
else
    check_failed "Файл config.php не найден"
    ((FAILED++))
fi

# 4. Проверка файлов миграции
echo -e "\n${BLUE}4. Проверка файлов миграции${NC}"
MIGRATION_FILES=(
    "migrations/add_product_activity_tracking.sql"
    "migrations/rollback_product_activity_tracking.sql"
    "migrations/validate_product_activity_tracking.sql"
)

for file in "${MIGRATION_FILES[@]}"; do
    if [[ -f "$file" ]]; then
        check_passed "Найден файл: $file"
        ((PASSED++))
    else
        check_failed "Отсутствует файл: $file"
        ((FAILED++))
    fi
done

# 5. Проверка скрипта развертывания
echo -e "\n${BLUE}5. Проверка скрипта развертывания${NC}"
if [[ -f "deploy_active_product_filtering.sh" ]]; then
    if [[ -x "deploy_active_product_filtering.sh" ]]; then
        check_passed "Скрипт развертывания готов к выполнению"
        ((PASSED++))
    else
        check_warning "Скрипт развертывания найден, но не исполняемый (запустите: chmod +x deploy_active_product_filtering.sh)"
        ((WARNINGS++))
    fi
else
    check_failed "Скрипт развертывания не найден"
    ((FAILED++))
fi

# 6. Проверка текущего состояния базы данных
echo -e "\n${BLUE}6. Проверка текущего состояния базы данных${NC}"
if php -r "
    require_once 'config.php';
    try {
        \$pdo = getDatabaseConnection();
        \$stmt = \$pdo->query('SELECT COUNT(*) FROM dim_products WHERE sku_ozon IS NOT NULL');
        \$count = \$stmt->fetchColumn();
        echo \$count;
    } catch (Exception \$e) {
        echo 'ERROR';
        exit(1);
    }
" > /dev/null 2>&1; then
    PRODUCT_COUNT=$(php -r "
        require_once 'config.php';
        \$pdo = getDatabaseConnection();
        \$stmt = \$pdo->query('SELECT COUNT(*) FROM dim_products WHERE sku_ozon IS NOT NULL');
        echo \$stmt->fetchColumn();
    ")
    check_passed "В базе данных найдено $PRODUCT_COUNT продуктов с Ozon SKU"
    ((PASSED++))
else
    check_failed "Не удается получить данные о продуктах из базы данных"
    ((FAILED++))
fi

# 7. Проверка наличия поля is_active (не должно существовать до миграции)
echo -e "\n${BLUE}7. Проверка состояния миграции${NC}"
if php -r "
    require_once 'config.php';
    try {
        \$pdo = getDatabaseConnection();
        \$stmt = \$pdo->query('DESCRIBE dim_products');
        \$columns = \$stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('is_active', \$columns)) {
            echo 'EXISTS';
        } else {
            echo 'NOT_EXISTS';
        }
    } catch (Exception \$e) {
        echo 'ERROR';
        exit(1);
    }
" > /dev/null 2>&1; then
    MIGRATION_STATUS=$(php -r "
        require_once 'config.php';
        \$pdo = getDatabaseConnection();
        \$stmt = \$pdo->query('DESCRIBE dim_products');
        \$columns = \$stmt->fetchAll(PDO::FETCH_COLUMN);
        echo in_array('is_active', \$columns) ? 'EXISTS' : 'NOT_EXISTS';
    ")
    
    if [[ "$MIGRATION_STATUS" == "NOT_EXISTS" ]]; then
        check_passed "Миграция еще не применена (поле is_active отсутствует)"
        ((PASSED++))
    else
        check_warning "Миграция уже применена (поле is_active существует)"
        ((WARNINGS++))
    fi
else
    check_failed "Не удается проверить структуру таблицы dim_products"
    ((FAILED++))
fi

# 8. Проверка свободного места на диске
echo -e "\n${BLUE}8. Проверка свободного места на диске${NC}"
AVAILABLE_SPACE=$(df . | tail -1 | awk '{print $4}')
AVAILABLE_MB=$((AVAILABLE_SPACE / 1024))

if [[ $AVAILABLE_MB -gt 1000 ]]; then
    check_passed "Свободного места: ${AVAILABLE_MB}MB (достаточно)"
    ((PASSED++))
elif [[ $AVAILABLE_MB -gt 500 ]]; then
    check_warning "Свободного места: ${AVAILABLE_MB}MB (минимально достаточно)"
    ((WARNINGS++))
else
    check_failed "Свободного места: ${AVAILABLE_MB}MB (недостаточно, нужно минимум 500MB)"
    ((FAILED++))
fi

# 9. Проверка прав доступа
echo -e "\n${BLUE}9. Проверка прав доступа${NC}"
if [[ -w "." ]]; then
    check_passed "Права на запись в текущую директорию есть"
    ((PASSED++))
else
    check_failed "Нет прав на запись в текущую директорию"
    ((FAILED++))
fi

# 10. Проверка наличия директорий для логов и бэкапов
echo -e "\n${BLUE}10. Проверка директорий${NC}"
mkdir -p logs backups
if [[ -d "logs" && -d "backups" ]]; then
    check_passed "Директории logs и backups созданы"
    ((PASSED++))
else
    check_failed "Не удается создать необходимые директории"
    ((FAILED++))
fi

# Итоговый отчет
echo -e "\n${BLUE}=================================================================="
echo -e "ИТОГОВЫЙ ОТЧЕТ ГОТОВНОСТИ К РАЗВЕРТЫВАНИЮ${NC}"
echo "=================================================================="

echo -e "✅ Успешных проверок: ${GREEN}$PASSED${NC}"
echo -e "⚠️ Предупреждений: ${YELLOW}$WARNINGS${NC}"
echo -e "❌ Неудачных проверок: ${RED}$FAILED${NC}"

if [[ $FAILED -eq 0 ]]; then
    echo -e "\n${GREEN}🎉 СИСТЕМА ГОТОВА К РАЗВЕРТЫВАНИЮ!${NC}"
    echo -e "\nСледующие шаги:"
    echo -e "1. Создайте резервную копию: ${BLUE}mysqldump mi_core_db > backup_\$(date +%Y%m%d_%H%M%S).sql${NC}"
    echo -e "2. Запустите dry run: ${BLUE}./deploy_active_product_filtering.sh --dry-run${NC}"
    echo -e "3. Если все ОК, запустите развертывание: ${BLUE}./deploy_active_product_filtering.sh${NC}"
    exit 0
elif [[ $FAILED -le 2 && $WARNINGS -le 3 ]]; then
    echo -e "\n${YELLOW}⚠️ СИСТЕМА ГОТОВА К РАЗВЕРТЫВАНИЮ С ПРЕДУПРЕЖДЕНИЯМИ${NC}"
    echo -e "\nИсправьте предупреждения и повторите проверку"
    exit 1
else
    echo -e "\n${RED}❌ СИСТЕМА НЕ ГОТОВА К РАЗВЕРТЫВАНИЮ${NC}"
    echo -e "\nИсправьте критические ошибки и повторите проверку"
    exit 2
fi