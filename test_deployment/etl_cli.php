#!/usr/bin/env php
<?php

/**
 * CLI интерфейс для управления ETL системой MDM
 * 
 * Использование:
 * php etl_cli.php <command> [options]
 * 
 * Команды:
 * - run [source] [--incremental] [--limit=N] - запуск ETL процесса
 * - status - показать статус ETL системы
 * - config [source] [key] [value] - управление конфигурацией
 * - logs [--level=LEVEL] [--source=SOURCE] [--tail=N] - просмотр логов
 * - setup - первоначальная настройка системы
 * - test [source] - тестирование подключения к источникам
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.py'; // Для совместимости с существующей конфигурацией

use MDM\ETL\Scheduler\ETLScheduler;
use MDM\ETL\Config\ETLConfigManager;

class ETLCli
{
    private PDO $pdo;
    private ETLConfigManager $configManager;
    private ETLScheduler $scheduler;
    
    public function __construct()
    {
        $this->initializeDatabase();
        $this->configManager = new ETLConfigManager($this->pdo);
        $this->scheduler = new ETLScheduler($this->pdo, $this->configManager->getETLConfig());
    }
    
    /**
     * Инициализация подключения к базе данных
     */
    private function initializeDatabase(): void
    {
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
        $dbname = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'mi_core_db';
        $username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'root';
        $password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?? '';
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306';
        
        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (Exception $e) {
            $this->error("Ошибка подключения к базе данных: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Главная функция обработки команд
     */
    public function run(array $argv): void
    {
        if (count($argv) < 2) {
            $this->showHelp();
            return;
        }
        
        $command = $argv[1];
        $args = array_slice($argv, 2);
        
        try {
            switch ($command) {
                case 'run':
                    $this->runETL($args);
                    break;
                    
                case 'status':
                    $this->showStatus();
                    break;
                    
                case 'config':
                    $this->manageConfig($args);
                    break;
                    
                case 'logs':
                    $this->showLogs($args);
                    break;
                    
                case 'setup':
                    $this->setupSystem();
                    break;
                    
                case 'test':
                    $this->testConnections($args);
                    break;
                    
                case 'help':
                case '--help':
                case '-h':
                    $this->showHelp();
                    break;
                    
                default:
                    $this->error("Неизвестная команда: $command");
                    $this->showHelp();
            }
        } catch (Exception $e) {
            $this->error("Ошибка выполнения команды: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Запуск ETL процесса
     */
    private function runETL(array $args): void
    {
        $source = null;
        $incremental = false;
        $limit = null;
        $filters = [];
        
        // Парсим аргументы
        foreach ($args as $arg) {
            if ($arg === '--incremental') {
                $incremental = true;
            } elseif (strpos($arg, '--limit=') === 0) {
                $limit = (int)substr($arg, 8);
            } elseif (strpos($arg, '--') !== 0 && !$source) {
                $source = $arg;
            }
        }
        
        $this->info("Запуск ETL процесса...");
        
        if ($source) {
            $this->info("Источник: $source");
        }
        
        if ($incremental) {
            $this->info("Режим: инкрементальное обновление");
        }
        
        if ($limit) {
            $this->info("Лимит записей: $limit");
            $filters['limit'] = $limit;
        }
        
        $startTime = microtime(true);
        
        try {
            if ($source) {
                $results = $this->scheduler->runSourceETL($source, ['filters' => $filters]);
            } elseif ($incremental) {
                $results = $this->scheduler->runIncrementalETL(['filters' => $filters]);
            } else {
                $results = $this->scheduler->runFullETL(['filters' => $filters]);
            }
            
            $duration = microtime(true) - $startTime;
            
            $this->success("ETL процесс завершен успешно за " . round($duration, 2) . " сек");
            
            // Показываем результаты
            $this->showResults($results);
            
        } catch (Exception $e) {
            $this->error("Ошибка выполнения ETL: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Показать статус ETL системы
     */
    private function showStatus(): void
    {
        $this->info("Статус ETL системы:");
        
        $status = $this->scheduler->getStatus();
        
        // Статус выполнения
        if ($status['is_running']) {
            $this->warning("🔄 ETL процесс выполняется");
        } else {
            $this->success("✅ ETL система готова к работе");
        }
        
        // Статус экстракторов
        echo "\n📊 Статус источников данных:\n";
        foreach ($status['extractors'] as $name => $extractor) {
            $statusIcon = $extractor['available'] ? '✅' : '❌';
            $statusText = $extractor['available'] ? 'доступен' : 'недоступен';
            echo "  $statusIcon $name: $statusText\n";
        }
        
        // Последние запуски
        if (!empty($status['last_runs'])) {
            echo "\n📈 Последние запуски:\n";
            foreach (array_slice($status['last_runs'], 0, 5) as $run) {
                $statusIcon = $run['status'] === 'success' ? '✅' : 
                            ($run['status'] === 'partial_success' ? '⚠️' : '❌');
                
                echo sprintf(
                    "  %s %s | Извлечено: %d | Сохранено: %d | Время: %.2f сек | %s\n",
                    $statusIcon,
                    $run['created_at'],
                    $run['total_extracted'],
                    $run['total_saved'],
                    $run['duration'],
                    $run['status']
                );
            }
        }
        
        // Валидация конфигурации
        $errors = $this->configManager->validateConfig();
        if (!empty($errors)) {
            echo "\n⚠️ Проблемы конфигурации:\n";
            foreach ($errors as $error) {
                echo "  ❌ $error\n";
            }
        }
    }
    
    /**
     * Управление конфигурацией
     */
    private function manageConfig(array $args): void
    {
        if (empty($args)) {
            // Показать всю конфигурацию
            $this->showAllConfig();
            return;
        }
        
        $source = $args[0];
        
        if (count($args) === 1) {
            // Показать конфигурацию источника
            $this->showSourceConfig($source);
            return;
        }
        
        if (count($args) === 2) {
            // Показать конкретное значение
            $key = $args[1];
            $value = $this->configManager->getConfigValue($source, $key);
            echo "$source.$key = $value\n";
            return;
        }
        
        if (count($args) >= 3) {
            // Установить значение
            $key = $args[1];
            $value = $args[2];
            $description = $args[3] ?? null;
            
            $this->configManager->setConfigValue($source, $key, $value, $description);
            $this->success("Конфигурация обновлена: $source.$key = $value");
        }
    }
    
    /**
     * Показать всю конфигурацию
     */
    private function showAllConfig(): void
    {
        $sources = ['ozon', 'wildberries', 'internal', 'scheduler'];
        
        foreach ($sources as $source) {
            echo "\n📋 Конфигурация $source:\n";
            $this->showSourceConfig($source);
        }
    }
    
    /**
     * Показать конфигурацию источника
     */
    private function showSourceConfig(string $source): void
    {
        try {
            $config = $this->configManager->getSourceConfig($source);
            
            if (empty($config)) {
                echo "  Конфигурация не найдена\n";
                return;
            }
            
            foreach ($config as $key => $data) {
                $value = $data['is_encrypted'] ? '[ENCRYPTED]' : $data['value'];
                $description = $data['description'] ? " # {$data['description']}" : '';
                echo "  $key = $value$description\n";
            }
        } catch (Exception $e) {
            $this->error("Ошибка получения конфигурации: " . $e->getMessage());
        }
    }
    
    /**
     * Показать логи
     */
    private function showLogs(array $args): void
    {
        $level = null;
        $source = null;
        $tail = 50;
        
        // Парсим аргументы
        foreach ($args as $arg) {
            if (strpos($arg, '--level=') === 0) {
                $level = substr($arg, 8);
            } elseif (strpos($arg, '--source=') === 0) {
                $source = substr($arg, 9);
            } elseif (strpos($arg, '--tail=') === 0) {
                $tail = (int)substr($arg, 7);
            }
        }
        
        try {
            $sql = "SELECT source, level, message, created_at FROM etl_logs WHERE 1=1";
            $params = [];
            
            if ($level) {
                $sql .= " AND level = ?";
                $params[] = strtoupper($level);
            }
            
            if ($source) {
                $sql .= " AND source = ?";
                $params[] = $source;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT ?";
            $params[] = $tail;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $logs = $stmt->fetchAll();
            
            if (empty($logs)) {
                $this->info("Логи не найдены");
                return;
            }
            
            echo "📋 Логи ETL системы (последние $tail записей):\n\n";
            
            foreach (array_reverse($logs) as $log) {
                $levelIcon = $this->getLevelIcon($log['level']);
                echo sprintf(
                    "%s [%s] [%s] %s: %s\n",
                    $levelIcon,
                    $log['created_at'],
                    $log['level'],
                    $log['source'],
                    $log['message']
                );
            }
            
        } catch (Exception $e) {
            $this->error("Ошибка получения логов: " . $e->getMessage());
        }
    }
    
    /**
     * Первоначальная настройка системы
     */
    private function setupSystem(): void
    {
        $this->info("🚀 Настройка ETL системы MDM...");
        
        // Создаем таблицы
        $this->info("Создание таблиц базы данных...");
        $this->createTables();
        
        // Проверяем конфигурацию
        $this->info("Проверка конфигурации...");
        $errors = $this->configManager->validateConfig();
        
        if (!empty($errors)) {
            $this->warning("Найдены проблемы конфигурации:");
            foreach ($errors as $error) {
                echo "  ❌ $error\n";
            }
            
            $this->info("\nДля настройки API ключей создайте .env файл с переменными:");
            echo "OZON_CLIENT_ID=your_ozon_client_id\n";
            echo "OZON_API_KEY=your_ozon_api_key\n";
            echo "WB_API_KEY=your_wildberries_api_token\n";
        } else {
            $this->success("✅ Конфигурация корректна");
        }
        
        $this->success("🎉 Настройка завершена!");
        $this->info("Используйте 'php etl_cli.php status' для проверки статуса системы");
    }
    
    /**
     * Создание таблиц базы данных
     */
    private function createTables(): void
    {
        $schemaFile = __DIR__ . '/src/ETL/Database/etl_schema.sql';
        
        if (!file_exists($schemaFile)) {
            throw new Exception("Файл схемы не найден: $schemaFile");
        }
        
        $sql = file_get_contents($schemaFile);
        
        // Разбиваем на отдельные запросы
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) {
                return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
            }
        );
        
        foreach ($statements as $statement) {
            if (stripos($statement, 'DELIMITER') !== false) {
                continue; // Пропускаем DELIMITER команды
            }
            
            try {
                $this->pdo->exec($statement);
            } catch (Exception $e) {
                // Игнорируем ошибки "таблица уже существует"
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }
    
    /**
     * Тестирование подключений к источникам
     */
    private function testConnections(array $args): void
    {
        $source = $args[0] ?? null;
        
        if ($source) {
            $this->testSingleSource($source);
        } else {
            $this->testAllSources();
        }
    }
    
    /**
     * Тестирование одного источника
     */
    private function testSingleSource(string $source): void
    {
        $this->info("🔍 Тестирование подключения к $source...");
        
        try {
            $config = $this->configManager->getETLConfig();
            
            if (!isset($config[$source])) {
                throw new Exception("Конфигурация для источника '$source' не найдена");
            }
            
            $extractorClass = "MDM\\ETL\\DataExtractors\\" . ucfirst($source) . "Extractor";
            
            if (!class_exists($extractorClass)) {
                throw new Exception("Экстрактор для источника '$source' не найден");
            }
            
            $extractor = new $extractorClass($this->pdo, $config[$source]);
            
            if ($extractor->isAvailable()) {
                $this->success("✅ $source: подключение успешно");
            } else {
                $this->error("❌ $source: подключение недоступно");
            }
            
        } catch (Exception $e) {
            $this->error("❌ $source: " . $e->getMessage());
        }
    }
    
    /**
     * Тестирование всех источников
     */
    private function testAllSources(): void
    {
        $sources = ['ozon', 'wildberries', 'internal'];
        
        foreach ($sources as $source) {
            $this->testSingleSource($source);
        }
    }
    
    /**
     * Показать результаты выполнения ETL
     */
    private function showResults(array $results): void
    {
        echo "\n📊 Результаты выполнения:\n";
        
        $totalExtracted = 0;
        $totalSaved = 0;
        
        foreach ($results as $source => $result) {
            $statusIcon = $result['status'] === 'success' ? '✅' : 
                         ($result['status'] === 'unavailable' ? '⚠️' : '❌');
            
            echo sprintf(
                "  %s %s: извлечено %d, сохранено %d за %.2f сек\n",
                $statusIcon,
                $source,
                $result['extracted_count'] ?? 0,
                $result['saved_count'] ?? 0,
                $result['duration'] ?? 0
            );
            
            if (isset($result['error'])) {
                echo "    ❌ Ошибка: {$result['error']}\n";
            }
            
            $totalExtracted += $result['extracted_count'] ?? 0;
            $totalSaved += $result['saved_count'] ?? 0;
        }
        
        echo "\n📈 Итого: извлечено $totalExtracted, сохранено $totalSaved записей\n";
    }
    
    /**
     * Показать справку
     */
    private function showHelp(): void
    {
        echo "ETL CLI - Система управления извлечением данных MDM\n\n";
        echo "Использование: php etl_cli.php <command> [options]\n\n";
        echo "Команды:\n";
        echo "  run [source] [--incremental] [--limit=N]  Запуск ETL процесса\n";
        echo "  status                                     Показать статус системы\n";
        echo "  config [source] [key] [value]              Управление конфигурацией\n";
        echo "  logs [--level=LEVEL] [--source=SOURCE]     Просмотр логов\n";
        echo "  setup                                      Первоначальная настройка\n";
        echo "  test [source]                              Тестирование подключений\n";
        echo "  help                                       Показать эту справку\n\n";
        echo "Примеры:\n";
        echo "  php etl_cli.php run                        # Полный ETL всех источников\n";
        echo "  php etl_cli.php run ozon --limit=100       # ETL только Ozon, лимит 100\n";
        echo "  php etl_cli.php run --incremental          # Инкрементальное обновление\n";
        echo "  php etl_cli.php config ozon api_key XXX    # Установить API ключ\n";
        echo "  php etl_cli.php logs --level=ERROR         # Показать только ошибки\n";
        echo "  php etl_cli.php test ozon                  # Тест подключения к Ozon\n";
    }
    
    /**
     * Получить иконку для уровня лога
     */
    private function getLevelIcon(string $level): string
    {
        switch (strtoupper($level)) {
            case 'ERROR': return '❌';
            case 'WARNING': return '⚠️';
            case 'INFO': return 'ℹ️';
            case 'DEBUG': return '🐛';
            default: return '📝';
        }
    }
    
    /**
     * Вывод информационного сообщения
     */
    private function info(string $message): void
    {
        echo "ℹ️  $message\n";
    }
    
    /**
     * Вывод сообщения об успехе
     */
    private function success(string $message): void
    {
        echo "✅ $message\n";
    }
    
    /**
     * Вывод предупреждения
     */
    private function warning(string $message): void
    {
        echo "⚠️  $message\n";
    }
    
    /**
     * Вывод ошибки
     */
    private function error(string $message): void
    {
        echo "❌ $message\n";
    }
}

// Запуск CLI
if (php_sapi_name() === 'cli') {
    $cli = new ETLCli();
    $cli->run($argv);
} else {
    echo "Этот скрипт должен запускаться из командной строки\n";
    exit(1);
}