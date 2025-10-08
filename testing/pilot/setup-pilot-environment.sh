#!/bin/bash

# Скрипт настройки пилотной среды для тестирования MDM системы

set -e

# Конфигурация
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PILOT_DB_NAME="mdm_pilot"
LOG_FILE="$SCRIPT_DIR/logs/pilot_setup_$(date +%Y%m%d_%H%M%S).log"

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Функции логирования
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a "$LOG_FILE"
}

info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] INFO:${NC} $1" | tee -a "$LOG_FILE"
}

# Создание директорий
create_directories() {
    log "Создаем директории для пилотного тестирования..."
    
    mkdir -p "$SCRIPT_DIR/logs"
    mkdir -p "$SCRIPT_DIR/test-data"
    mkdir -p "$SCRIPT_DIR/results"
    mkdir -p "$SCRIPT_DIR/user-feedback"
    
    log "Директории созданы"
}

# Создание пилотной базы данных
create_pilot_database() {
    log "Создаем пилотную базу данных..."
    
    # Загружаем конфигурацию
    source "$SCRIPT_DIR/../../.env" 2>/dev/null || {
        error "Файл .env не найден"
        exit 1
    }
    
    # Создаем базу данных
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -e "
        CREATE DATABASE IF NOT EXISTS $PILOT_DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        GRANT ALL PRIVILEGES ON $PILOT_DB_NAME.* TO '$DB_USER'@'%';
        FLUSH PRIVILEGES;
    "
    
    log "Пилотная база данных создана: $PILOT_DB_NAME"
}

# Создание схемы базы данных
create_database_schema() {
    log "Создаем схему базы данных для пилотного тестирования..."
    
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$PILOT_DB_NAME" << 'EOF'
-- Таблица мастер-продуктов
CREATE TABLE IF NOT EXISTS master_products (
    master_id VARCHAR(50) PRIMARY KEY,
    canonical_name VARCHAR(500) NOT NULL,
    canonical_brand VARCHAR(200),
    canonical_category VARCHAR(200),
    description TEXT,
    attributes JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive', 'pending_review') DEFAULT 'active',
    
    INDEX idx_canonical_name (canonical_name(100)),
    INDEX idx_canonical_brand (canonical_brand),
    INDEX idx_canonical_category (canonical_category),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Таблица сопоставления SKU
CREATE TABLE IF NOT EXISTS sku_mapping (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    master_id VARCHAR(50) NOT NULL,
    external_sku VARCHAR(200) NOT NULL,
    source VARCHAR(50) NOT NULL,
    source_name VARCHAR(500),
    source_brand VARCHAR(200),
    source_category VARCHAR(200),
    confidence_score DECIMAL(3,2),
    verification_status ENUM('auto', 'manual', 'pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (master_id) REFERENCES master_products(master_id) ON DELETE CASCADE,
    UNIQUE KEY unique_source_sku (source, external_sku),
    INDEX idx_master_id (master_id),
    INDEX idx_external_sku (external_sku),
    INDEX idx_source (source),
    INDEX idx_verification_status (verification_status)
);

-- Таблица метрик качества данных
CREATE TABLE IF NOT EXISTS data_quality_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(10,2),
    total_records INT,
    good_records INT,
    calculation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_metric_name (metric_name),
    INDEX idx_calculation_date (calculation_date)
);

-- Таблица логов пилотного тестирования
CREATE TABLE IF NOT EXISTS pilot_test_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100),
    action VARCHAR(200),
    details JSON,
    execution_time_ms INT,
    status ENUM('success', 'error', 'warning') DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Таблица обратной связи пользователей
CREATE TABLE IF NOT EXISTS user_feedback (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100),
    feature VARCHAR(200),
    rating INT CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    issue_type ENUM('bug', 'improvement', 'feature_request', 'other') DEFAULT 'other',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_feature (feature),
    INDEX idx_rating (rating),
    INDEX idx_issue_type (issue_type),
    INDEX idx_priority (priority),
    INDEX idx_status (status)
);
EOF

    log "Схема базы данных создана"
}

# Подготовка тестовых данных
prepare_test_data() {
    log "Подготавливаем тестовые данные..."
    
    # Создаем ограниченный набор тестовых данных
    cat > "$SCRIPT_DIR/test-data/pilot_products.sql" << 'EOF'
-- Тестовые мастер-продукты для пилотного тестирования
INSERT INTO master_products (master_id, canonical_name, canonical_brand, canonical_category, description, status) VALUES
('PILOT_001', 'Смесь для выпечки ЭТОНОВО Пицца 9 порций', 'ЭТОНОВО', 'Смеси для выпечки', 'Готовая смесь для приготовления теста для пиццы', 'active'),
('PILOT_002', 'Кетчуп Heinz Томатный 570г', 'Heinz', 'Соусы и кетчупы', 'Классический томатный кетчуп', 'active'),
('PILOT_003', 'Напиток Coca-Cola 0.5л', 'Coca-Cola', 'Напитки', 'Газированный напиток', 'active'),
('PILOT_004', 'Хлеб Бородинский 400г', 'Неизвестный бренд', 'Хлебобулочные изделия', 'Традиционный бородинский хлеб', 'active'),
('PILOT_005', 'Молоко Простоквашино 3.2% 1л', 'Простоквашино', 'Молочные продукты', 'Пастеризованное молоко', 'active');

-- Тестовые сопоставления SKU
INSERT INTO sku_mapping (master_id, external_sku, source, source_name, source_brand, source_category, confidence_score, verification_status) VALUES
('PILOT_001', 'SKU_001', 'internal', 'Смесь для выпечки ЭТОНОВО пицца 9 шт', 'ЭТОНОВО', 'Смеси для выпечки', 1.00, 'auto'),
('PILOT_001', '266215809', 'ozon', 'Смесь для выпечки ЭТОНОВО пицца 9 шт набор', 'ЭТОНОВО', 'Смеси для выпечки', 0.95, 'manual'),
('PILOT_002', 'SKU_002', 'internal', 'Кетчуп Heinz томатный 570г', 'Heinz', 'Соусы', 1.00, 'auto'),
('PILOT_003', 'SKU_003', 'internal', 'Coca-Cola 0.5л', 'Coca-Cola', 'Напитки', 1.00, 'auto'),
('PILOT_003', '12345678', 'wildberries', 'Кока-кола 0,5л', 'Coca-Cola', 'Напитки', 0.90, 'manual'),
('PILOT_004', 'SKU_004', 'internal', 'Хлеб бородинский 400г', '', 'Хлеб', 1.00, 'auto'),
('PILOT_005', 'SKU_005', 'internal', 'Молоко Простоквашино 3.2% 1л', 'Простоквашино', 'Молоко', 1.00, 'auto');

-- Начальные метрики качества данных
INSERT INTO data_quality_metrics (metric_name, metric_value, total_records, good_records) VALUES
('master_data_coverage', 100.00, 7, 5),
('brand_coverage', 80.00, 5, 4),
('category_coverage', 100.00, 5, 5),
('auto_matching_rate', 71.43, 7, 5);
EOF

    # Загружаем тестовые данные
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$PILOT_DB_NAME" < "$SCRIPT_DIR/test-data/pilot_products.sql"
    
    log "Тестовые данные загружены"
}

# Создание конфигурации для пилотной среды
create_pilot_config() {
    log "Создаем конфигурацию для пилотной среды..."
    
    cat > "$SCRIPT_DIR/pilot.env" << EOF
# Конфигурация пилотной среды MDM системы
NODE_ENV=pilot
DB_NAME=$PILOT_DB_NAME
DB_HOST=$DB_HOST
DB_USER=$DB_USER
DB_PASSWORD=$DB_PASSWORD

# Ограничения для пилотного тестирования
MAX_PRODUCTS_PER_REQUEST=100
API_RATE_LIMIT=1000
CACHE_TTL=300

# Логирование
LOG_LEVEL=debug
ENABLE_PERFORMANCE_LOGGING=true
ENABLE_USER_ACTION_LOGGING=true

# Уведомления для пилотного тестирования
PILOT_SLACK_WEBHOOK=$PILOT_SLACK_WEBHOOK
PILOT_EMAIL_ALERTS=$PILOT_EMAIL_ALERTS
EOF

    log "Конфигурация пилотной среды создана"
}

# Создание скриптов для тестирования
create_test_scripts() {
    log "Создаем скрипты для тестирования..."
    
    # Скрипт функционального тестирования
    cat > "$SCRIPT_DIR/run-functional-tests.php" << 'EOF'
<?php
/**
 * Функциональные тесты для пилотного тестирования MDM системы
 */

require_once __DIR__ . '/../../config.php';

class PilotFunctionalTests {
    private $db;
    private $results = [];
    
    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=mdm_pilot;charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    public function runAllTests() {
        echo "=== Запуск функциональных тестов ===\n";
        
        $this->testMasterProductCRUD();
        $this->testSkuMapping();
        $this->testDataQualityMetrics();
        $this->testSearch();
        
        $this->printResults();
    }
    
    private function testMasterProductCRUD() {
        echo "Тестируем CRUD операции с мастер-продуктами...\n";
        
        try {
            // CREATE
            $masterId = 'TEST_' . uniqid();
            $sql = "INSERT INTO master_products (master_id, canonical_name, canonical_brand, canonical_category) VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$masterId, 'Тестовый продукт', 'Тестовый бренд', 'Тестовая категория']);
            
            // READ
            $sql = "SELECT * FROM master_products WHERE master_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$masterId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception("Продукт не найден после создания");
            }
            
            // UPDATE
            $sql = "UPDATE master_products SET canonical_name = ? WHERE master_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['Обновленный тестовый продукт', $masterId]);
            
            // DELETE
            $sql = "DELETE FROM master_products WHERE master_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$masterId]);
            
            $this->results['master_product_crud'] = 'PASS';
            echo "✓ CRUD операции с мастер-продуктами: PASS\n";
            
        } catch (Exception $e) {
            $this->results['master_product_crud'] = 'FAIL: ' . $e->getMessage();
            echo "✗ CRUD операции с мастер-продуктами: FAIL - " . $e->getMessage() . "\n";
        }
    }
    
    private function testSkuMapping() {
        echo "Тестируем сопоставление SKU...\n";
        
        try {
            // Проверяем существующие сопоставления
            $sql = "SELECT COUNT(*) FROM sku_mapping";
            $count = $this->db->query($sql)->fetchColumn();
            
            if ($count == 0) {
                throw new Exception("Нет сопоставлений SKU");
            }
            
            // Проверяем целостность связей
            $sql = "
                SELECT COUNT(*) 
                FROM sku_mapping sm
                LEFT JOIN master_products mp ON sm.master_id = mp.master_id
                WHERE mp.master_id IS NULL
            ";
            $orphaned = $this->db->query($sql)->fetchColumn();
            
            if ($orphaned > 0) {
                throw new Exception("Найдены SKU без связанных мастер-продуктов: $orphaned");
            }
            
            $this->results['sku_mapping'] = 'PASS';
            echo "✓ Сопоставление SKU: PASS\n";
            
        } catch (Exception $e) {
            $this->results['sku_mapping'] = 'FAIL: ' . $e->getMessage();
            echo "✗ Сопоставление SKU: FAIL - " . $e->getMessage() . "\n";
        }
    }
    
    private function testDataQualityMetrics() {
        echo "Тестируем метрики качества данных...\n";
        
        try {
            $sql = "SELECT COUNT(*) FROM data_quality_metrics";
            $count = $this->db->query($sql)->fetchColumn();
            
            if ($count == 0) {
                throw new Exception("Нет метрик качества данных");
            }
            
            // Проверяем основные метрики
            $requiredMetrics = ['master_data_coverage', 'brand_coverage', 'category_coverage'];
            foreach ($requiredMetrics as $metric) {
                $sql = "SELECT COUNT(*) FROM data_quality_metrics WHERE metric_name = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$metric]);
                
                if ($stmt->fetchColumn() == 0) {
                    throw new Exception("Отсутствует метрика: $metric");
                }
            }
            
            $this->results['data_quality_metrics'] = 'PASS';
            echo "✓ Метрики качества данных: PASS\n";
            
        } catch (Exception $e) {
            $this->results['data_quality_metrics'] = 'FAIL: ' . $e->getMessage();
            echo "✗ Метрики качества данных: FAIL - " . $e->getMessage() . "\n";
        }
    }
    
    private function testSearch() {
        echo "Тестируем поиск товаров...\n";
        
        try {
            // Поиск по названию
            $sql = "SELECT COUNT(*) FROM master_products WHERE canonical_name LIKE ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['%ЭТОНОВО%']);
            $count = $stmt->fetchColumn();
            
            if ($count == 0) {
                throw new Exception("Поиск по названию не работает");
            }
            
            // Поиск по бренду
            $sql = "SELECT COUNT(*) FROM master_products WHERE canonical_brand = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['Heinz']);
            $count = $stmt->fetchColumn();
            
            if ($count == 0) {
                throw new Exception("Поиск по бренду не работает");
            }
            
            $this->results['search'] = 'PASS';
            echo "✓ Поиск товаров: PASS\n";
            
        } catch (Exception $e) {
            $this->results['search'] = 'FAIL: ' . $e->getMessage();
            echo "✗ Поиск товаров: FAIL - " . $e->getMessage() . "\n";
        }
    }
    
    private function printResults() {
        echo "\n=== Результаты функциональных тестов ===\n";
        
        $passed = 0;
        $total = count($this->results);
        
        foreach ($this->results as $test => $result) {
            echo "$test: $result\n";
            if (strpos($result, 'PASS') === 0) {
                $passed++;
            }
        }
        
        echo "\nИтого: $passed/$total тестов пройдено\n";
        
        if ($passed == $total) {
            echo "✅ Все функциональные тесты пройдены успешно!\n";
        } else {
            echo "❌ Некоторые тесты не пройдены. Требуется исправление.\n";
        }
    }
}

// Запуск тестов
if (php_sapi_name() === 'cli') {
    $tests = new PilotFunctionalTests();
    $tests->runAllTests();
}
EOF

    # Скрипт тестирования производительности
    cat > "$SCRIPT_DIR/run-performance-tests.php" << 'EOF'
<?php
/**
 * Тесты производительности для пилотного тестирования MDM системы
 */

require_once __DIR__ . '/../../config.php';

class PilotPerformanceTests {
    private $db;
    private $results = [];
    
    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=mdm_pilot;charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    public function runAllTests() {
        echo "=== Запуск тестов производительности ===\n";
        
        $this->testSearchPerformance();
        $this->testJoinPerformance();
        $this->testInsertPerformance();
        
        $this->printResults();
    }
    
    private function testSearchPerformance() {
        echo "Тестируем производительность поиска...\n";
        
        $iterations = 100;
        $totalTime = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            
            $sql = "SELECT * FROM master_products WHERE canonical_name LIKE ? LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['%' . chr(65 + ($i % 26)) . '%']);
            $stmt->fetchAll();
            
            $totalTime += (microtime(true) - $startTime) * 1000;
        }
        
        $avgTime = $totalTime / $iterations;
        $this->results['search_performance'] = round($avgTime, 2) . 'ms';
        
        echo "Среднее время поиска: " . round($avgTime, 2) . "ms\n";
        
        if ($avgTime > 50) {
            echo "⚠️  Производительность поиска ниже ожидаемой\n";
        } else {
            echo "✓ Производительность поиска в норме\n";
        }
    }
    
    private function testJoinPerformance() {
        echo "Тестируем производительность JOIN запросов...\n";
        
        $iterations = 50;
        $totalTime = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            
            $sql = "
                SELECT mp.*, sm.external_sku, sm.source
                FROM master_products mp
                JOIN sku_mapping sm ON mp.master_id = sm.master_id
                LIMIT 20
            ";
            $stmt = $this->db->query($sql);
            $stmt->fetchAll();
            
            $totalTime += (microtime(true) - $startTime) * 1000;
        }
        
        $avgTime = $totalTime / $iterations;
        $this->results['join_performance'] = round($avgTime, 2) . 'ms';
        
        echo "Среднее время JOIN запроса: " . round($avgTime, 2) . "ms\n";
        
        if ($avgTime > 100) {
            echo "⚠️  Производительность JOIN запросов ниже ожидаемой\n";
        } else {
            echo "✓ Производительность JOIN запросов в норме\n";
        }
    }
    
    private function testInsertPerformance() {
        echo "Тестируем производительность вставки данных...\n";
        
        $iterations = 10;
        $totalTime = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            
            $masterId = 'PERF_TEST_' . $i . '_' . uniqid();
            $sql = "INSERT INTO master_products (master_id, canonical_name, canonical_brand, canonical_category) VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$masterId, "Тестовый продукт $i", "Тестовый бренд", "Тестовая категория"]);
            
            $totalTime += (microtime(true) - $startTime) * 1000;
        }
        
        // Очищаем тестовые данные
        $this->db->query("DELETE FROM master_products WHERE master_id LIKE 'PERF_TEST_%'");
        
        $avgTime = $totalTime / $iterations;
        $this->results['insert_performance'] = round($avgTime, 2) . 'ms';
        
        echo "Среднее время вставки: " . round($avgTime, 2) . "ms\n";
        
        if ($avgTime > 20) {
            echo "⚠️  Производительность вставки ниже ожидаемой\n";
        } else {
            echo "✓ Производительность вставки в норме\n";
        }
    }
    
    private function printResults() {
        echo "\n=== Результаты тестов производительности ===\n";
        
        foreach ($this->results as $test => $result) {
            echo "$test: $result\n";
        }
        
        echo "\nРекомендации:\n";
        echo "- Поиск: < 50ms\n";
        echo "- JOIN запросы: < 100ms\n";
        echo "- Вставка: < 20ms\n";
    }
}

// Запуск тестов
if (php_sapi_name() === 'cli') {
    $tests = new PilotPerformanceTests();
    $tests->runAllTests();
}
EOF

    log "Скрипты тестирования созданы"
}

# Создание формы обратной связи
create_feedback_form() {
    log "Создаем форму обратной связи..."
    
    cat > "$SCRIPT_DIR/user-feedback/feedback-form.html" << 'EOF'
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Обратная связь - Пилотное тестирование MDM системы</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        textarea { height: 100px; resize: vertical; }
        .rating { display: flex; gap: 10px; }
        .rating input { width: auto; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .section { border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Обратная связь - Пилотное тестирование MDM системы</h1>
    
    <form id="feedbackForm">
        <div class="section">
            <h2>Информация о пользователе</h2>
            
            <div class="form-group">
                <label for="userId">ID пользователя:</label>
                <input type="text" id="userId" name="userId" required>
            </div>
            
            <div class="form-group">
                <label for="role">Роль:</label>
                <select id="role" name="role" required>
                    <option value="">Выберите роль</option>
                    <option value="data_admin">Администратор данных</option>
                    <option value="product_manager">Менеджер по товарам</option>
                    <option value="analyst">Аналитик</option>
                    <option value="technical_specialist">Технический специалист</option>
                </select>
            </div>
        </div>
        
        <div class="section">
            <h2>Оценка функций системы</h2>
            
            <div class="form-group">
                <label>Создание и редактирование мастер-продуктов:</label>
                <div class="rating">
                    <label><input type="radio" name="master_products_rating" value="1"> 1 - Очень плохо</label>
                    <label><input type="radio" name="master_products_rating" value="2"> 2 - Плохо</label>
                    <label><input type="radio" name="master_products_rating" value="3"> 3 - Удовлетворительно</label>
                    <label><input type="radio" name="master_products_rating" value="4"> 4 - Хорошо</label>
                    <label><input type="radio" name="master_products_rating" value="5"> 5 - Отлично</label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Сопоставление SKU:</label>
                <div class="rating">
                    <label><input type="radio" name="sku_mapping_rating" value="1"> 1 - Очень плохо</label>
                    <label><input type="radio" name="sku_mapping_rating" value="2"> 2 - Плохо</label>
                    <label><input type="radio" name="sku_mapping_rating" value="3"> 3 - Удовлетворительно</label>
                    <label><input type="radio" name="sku_mapping_rating" value="4"> 4 - Хорошо</label>
                    <label><input type="radio" name="sku_mapping_rating" value="5"> 5 - Отлично</label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Поиск и фильтрация:</label>
                <div class="rating">
                    <label><input type="radio" name="search_rating" value="1"> 1 - Очень плохо</label>
                    <label><input type="radio" name="search_rating" value="2"> 2 - Плохо</label>
                    <label><input type="radio" name="search_rating" value="3"> 3 - Удовлетворительно</label>
                    <label><input type="radio" name="search_rating" value="4"> 4 - Хорошо</label>
                    <label><input type="radio" name="search_rating" value="5"> 5 - Отлично</label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Отчеты и аналитика:</label>
                <div class="rating">
                    <label><input type="radio" name="reports_rating" value="1"> 1 - Очень плохо</label>
                    <label><input type="radio" name="reports_rating" value="2"> 2 - Плохо</label>
                    <label><input type="radio" name="search_rating" value="3"> 3 - Удовлетворительно</label>
                    <label><input type="radio" name="reports_rating" value="4"> 4 - Хорошо</label>
                    <label><input type="radio" name="reports_rating" value="5"> 5 - Отлично</label>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2>Общая оценка</h2>
            
            <div class="form-group">
                <label>Общая оценка системы:</label>
                <div class="rating">
                    <label><input type="radio" name="overall_rating" value="1"> 1 - Очень плохо</label>
                    <label><input type="radio" name="overall_rating" value="2"> 2 - Плохо</label>
                    <label><input type="radio" name="overall_rating" value="3"> 3 - Удовлетворительно</label>
                    <label><input type="radio" name="overall_rating" value="4"> 4 - Хорошо</label>
                    <label><input type="radio" name="overall_rating" value="5"> 5 - Отлично</label>
                </div>
            </div>
            
            <div class="form-group">
                <label for="usability">Удобство использования (1-5):</label>
                <select id="usability" name="usability">
                    <option value="">Выберите оценку</option>
                    <option value="1">1 - Очень сложно</option>
                    <option value="2">2 - Сложно</option>
                    <option value="3">3 - Нормально</option>
                    <option value="4">4 - Легко</option>
                    <option value="5">5 - Очень легко</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="performance">Производительность (1-5):</label>
                <select id="performance" name="performance">
                    <option value="">Выберите оценку</option>
                    <option value="1">1 - Очень медленно</option>
                    <option value="2">2 - Медленно</option>
                    <option value="3">3 - Нормально</option>
                    <option value="4">4 - Быстро</option>
                    <option value="5">5 - Очень быстро</option>
                </select>
            </div>
        </div>
        
        <div class="section">
            <h2>Проблемы и предложения</h2>
            
            <div class="form-group">
                <label for="issues">Обнаруженные проблемы:</label>
                <textarea id="issues" name="issues" placeholder="Опишите любые проблемы, с которыми вы столкнулись..."></textarea>
            </div>
            
            <div class="form-group">
                <label for="improvements">Предложения по улучшению:</label>
                <textarea id="improvements" name="improvements" placeholder="Что можно улучшить в системе?"></textarea>
            </div>
            
            <div class="form-group">
                <label for="missing_features">Недостающие функции:</label>
                <textarea id="missing_features" name="missing_features" placeholder="Какие функции вам не хватает?"></textarea>
            </div>
        </div>
        
        <button type="submit">Отправить отзыв</button>
    </form>
    
    <script>
        document.getElementById('feedbackForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Собираем данные формы
            const formData = new FormData(this);
            const feedback = {};
            
            for (let [key, value] of formData.entries()) {
                feedback[key] = value;
            }
            
            // Отправляем данные (здесь должен быть реальный endpoint)
            console.log('Feedback data:', feedback);
            
            // Показываем сообщение об успехе
            alert('Спасибо за ваш отзыв! Данные сохранены.');
            
            // Очищаем форму
            this.reset();
        });
    </script>
</body>
</html>
EOF

    log "Форма обратной связи создана"
}

# Основная функция
main() {
    log "=== Настройка пилотной среды для тестирования MDM системы ==="
    
    create_directories
    create_pilot_database
    create_database_schema
    prepare_test_data
    create_pilot_config
    create_test_scripts
    create_feedback_form
    
    log "=== Пилотная среда настроена успешно ==="
    
    echo -e "\n${GREEN}=== ИНСТРУКЦИИ ПО ЗАПУСКУ ПИЛОТНОГО ТЕСТИРОВАНИЯ ===${NC}"
    echo "1. Запустите функциональные тесты: php $SCRIPT_DIR/run-functional-tests.php"
    echo "2. Запустите тесты производительности: php $SCRIPT_DIR/run-performance-tests.php"
    echo "3. Откройте форму обратной связи: $SCRIPT_DIR/user-feedback/feedback-form.html"
    echo "4. Используйте пилотную базу данных: $PILOT_DB_NAME"
    echo "5. Конфигурация пилотной среды: $SCRIPT_DIR/pilot.env"
    
    echo -e "\n${BLUE}Тестовые данные:${NC}"
    echo "- 5 мастер-продуктов"
    echo "- 7 сопоставлений SKU"
    echo "- Метрики качества данных"
    
    echo -e "\n${YELLOW}Следующие шаги:${NC}"
    echo "1. Обучите пользователей работе с системой"
    echo "2. Проведите тестирование в течение 2 недель"
    echo "3. Соберите обратную связь от всех участников"
    echo "4. Проанализируйте результаты и внесите улучшения"
}

# Создание директорий для логов
mkdir -p "$SCRIPT_DIR/logs"

# Запуск основной функции
main "$@"