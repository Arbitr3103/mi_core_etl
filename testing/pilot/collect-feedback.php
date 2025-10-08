<?php

/**
 * Скрипт сбора и анализа обратной связи пилотного тестирования
 */

require_once __DIR__ . '/../../config.php';

class FeedbackCollector {
    private $db;
    private $logFile;
    
    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=mdm_pilot;charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->logFile = __DIR__ . '/logs/feedback_collection_' . date('Y-m-d_H-i-s') . '.log';
        $this->createLogDirectory();
    }
    
    private function createLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;
    }
    
    /**
     * Добавление обратной связи пользователя
     */
    public function addFeedback($userId, $feature, $rating, $comment, $issueType = 'other', $priority = 'medium') {
        try {
            $sql = "
                INSERT INTO user_feedback 
                (user_id, feature, rating, comment, issue_type, priority, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$userId, $feature, $rating, $comment, $issueType, $priority]);
            
            if ($result) {
                $feedbackId = $this->db->lastInsertId();
                $this->log("Добавлена обратная связь ID: $feedbackId от пользователя: $userId");
                return $feedbackId;
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->log("Ошибка при добавлении обратной связи: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Загрузка обратной связи из JSON файла
     */
    public function loadFeedbackFromFile($filename) {
        $this->log("Загружаем обратную связь из файла: $filename");
        
        if (!file_exists($filename)) {
            $this->log("Файл не найден: $filename");
            return false;
        }
        
        $data = json_decode(file_get_contents($filename), true);
        if (!$data) {
            $this->log("Ошибка при чтении JSON файла");
            return false;
        }
        
        $loaded = 0;
        foreach ($data as $feedback) {
            if ($this->addFeedback(
                $feedback['user_id'],
                $feedback['feature'],
                $feedback['rating'],
                $feedback['comment'],
                $feedback['issue_type'] ?? 'other',
                $feedback['priority'] ?? 'medium'
            )) {
                $loaded++;
            }
        }
        
        $this->log("Загружено $loaded записей обратной связи");
        return $loaded;
    }
    
    /**
     * Анализ обратной связи по функциям
     */
    public function analyzeFeedbackByFeature() {
        $this->log("Анализируем обратную связь по функциям...");
        
        $sql = "
            SELECT 
                feature,
                COUNT(*) as total_feedback,
                AVG(rating) as avg_rating,
                MIN(rating) as min_rating,
                MAX(rating) as max_rating,
                COUNT(CASE WHEN rating >= 4 THEN 1 END) as positive_feedback,
                COUNT(CASE WHEN rating <= 2 THEN 1 END) as negative_feedback
            FROM user_feedback
            GROUP BY feature
            ORDER BY avg_rating DESC
        ";
        
        $stmt = $this->db->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $analysis = [];
        foreach ($results as $row) {
            $positivePercent = ($row['positive_feedback'] / $row['total_feedback']) * 100;
            $negativePercent = ($row['negative_feedback'] / $row['total_feedback']) * 100;
            
            $analysis[] = [
                'feature' => $row['feature'],
                'total_feedback' => $row['total_feedback'],
                'avg_rating' => round($row['avg_rating'], 2),
                'min_rating' => $row['min_rating'],
                'max_rating' => $row['max_rating'],
                'positive_percent' => round($positivePercent, 1),
                'negative_percent' => round($negativePercent, 1),
                'status' => $row['avg_rating'] >= 4 ? 'Отлично' : ($row['avg_rating'] >= 3 ? 'Хорошо' : 'Требует улучшения')
            ];
        }
        
        return $analysis;
    }
    
    /**
     * Анализ проблем и предложений
     */
    public function analyzeIssuesAndSuggestions() {
        $this->log("Анализируем проблемы и предложения...");
        
        // Группировка по типам проблем
        $sql = "
            SELECT 
                issue_type,
                priority,
                COUNT(*) as count,
                GROUP_CONCAT(DISTINCT feature) as affected_features
            FROM user_feedback
            WHERE comment IS NOT NULL AND comment != ''
            GROUP BY issue_type, priority
            ORDER BY 
                FIELD(priority, 'critical', 'high', 'medium', 'low'),
                count DESC
        ";
        
        $stmt = $this->db->query($sql);
        $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Наиболее частые проблемы
        $sql = "
            SELECT 
                comment,
                feature,
                rating,
                priority,
                COUNT(*) as frequency
            FROM user_feedback
            WHERE rating <= 2 AND comment IS NOT NULL AND comment != ''
            GROUP BY comment, feature
            HAVING frequency > 1
            ORDER BY frequency DESC, priority DESC
            LIMIT 10
        ";
        
        $stmt = $this->db->query($sql);
        $commonIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'issues_by_type' => $issues,
            'common_issues' => $commonIssues
        ];
    }
    
    /**
     * Анализ обратной связи по пользователям
     */
    public function analyzeFeedbackByUser() {
        $this->log("Анализируем обратную связь по пользователям...");
        
        $sql = "
            SELECT 
                user_id,
                COUNT(*) as total_feedback,
                AVG(rating) as avg_rating,
                COUNT(DISTINCT feature) as features_tested,
                MAX(created_at) as last_feedback_date
            FROM user_feedback
            GROUP BY user_id
            ORDER BY total_feedback DESC
        ";
        
        $stmt = $this->db->query($sql);
        $userStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Активность пользователей
        $totalUsers = count($userStats);
        $activeUsers = count(array_filter($userStats, function($user) {
            return $user['total_feedback'] >= 5;
        }));
        
        return [
            'user_stats' => $userStats,
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'participation_rate' => $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 1) : 0
        ];
    }
    
    /**
     * Создание сводного отчета
     */
    public function createSummaryReport() {
        $this->log("Создаем сводный отчет по обратной связи...");
        
        // Общая статистика
        $sql = "
            SELECT 
                COUNT(*) as total_feedback,
                AVG(rating) as overall_avg_rating,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT feature) as features_covered,
                COUNT(CASE WHEN rating >= 4 THEN 1 END) as satisfied_responses,
                COUNT(CASE WHEN rating <= 2 THEN 1 END) as unsatisfied_responses
            FROM user_feedback
        ";
        
        $stmt = $this->db->query($sql);
        $overallStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Анализ по функциям
        $featureAnalysis = $this->analyzeFeedbackByFeature();
        
        // Анализ проблем
        $issuesAnalysis = $this->analyzeIssuesAndSuggestions();
        
        // Анализ по пользователям
        $userAnalysis = $this->analyzeFeedbackByUser();
        
        // Рекомендации
        $recommendations = $this->generateRecommendations($featureAnalysis, $issuesAnalysis);
        
        $report = [
            'report_date' => date('Y-m-d H:i:s'),
            'overall_statistics' => $overallStats,
            'feature_analysis' => $featureAnalysis,
            'issues_analysis' => $issuesAnalysis,
            'user_analysis' => $userAnalysis,
            'recommendations' => $recommendations,
            'pilot_status' => $this->determinePilotStatus($overallStats, $featureAnalysis)
        ];
        
        // Сохраняем отчет
        $reportFile = __DIR__ . '/results/pilot_feedback_report.json';
        $this->ensureDirectoryExists(dirname($reportFile));
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->log("Сводный отчет сохранен: $reportFile");
        
        return $report;
    }
    
    /**
     * Генерация рекомендаций
     */
    private function generateRecommendations($featureAnalysis, $issuesAnalysis) {
        $recommendations = [];
        
        // Рекомендации по функциям с низкими оценками
        foreach ($featureAnalysis as $feature) {
            if ($feature['avg_rating'] < 3) {
                $recommendations[] = [
                    'type' => 'improvement',
                    'priority' => 'high',
                    'description' => "Требуется улучшение функции '{$feature['feature']}' (средняя оценка: {$feature['avg_rating']})"
                ];
            } elseif ($feature['negative_percent'] > 30) {
                $recommendations[] = [
                    'type' => 'review',
                    'priority' => 'medium',
                    'description' => "Проверить функцию '{$feature['feature']}' - {$feature['negative_percent']}% негативных отзывов"
                ];
            }
        }
        
        // Рекомендации по критическим проблемам
        foreach ($issuesAnalysis['issues_by_type'] as $issue) {
            if ($issue['priority'] === 'critical') {
                $recommendations[] = [
                    'type' => 'critical_fix',
                    'priority' => 'critical',
                    'description' => "Немедленно исправить критические проблемы типа '{$issue['issue_type']}' ({$issue['count']} случаев)"
                ];
            }
        }
        
        // Общие рекомендации
        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'general',
                'priority' => 'low',
                'description' => 'Система готова к полному запуску. Продолжить мониторинг обратной связи.'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Определение статуса пилотного тестирования
     */
    private function determinePilotStatus($overallStats, $featureAnalysis) {
        $avgRating = $overallStats['overall_avg_rating'];
        $satisfactionRate = ($overallStats['satisfied_responses'] / $overallStats['total_feedback']) * 100;
        
        $criticalIssues = count(array_filter($featureAnalysis, function($feature) {
            return $feature['avg_rating'] < 2;
        }));
        
        if ($criticalIssues > 0) {
            return 'FAIL - Критические проблемы требуют исправления';
        } elseif ($avgRating >= 4 && $satisfactionRate >= 80) {
            return 'SUCCESS - Готово к полному запуску';
        } elseif ($avgRating >= 3 && $satisfactionRate >= 60) {
            return 'CONDITIONAL - Готово с учетом рекомендаций';
        } else {
            return 'NEEDS_IMPROVEMENT - Требуются значительные улучшения';
        }
    }
    
    private function ensureDirectoryExists($directory) {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
    
    /**
     * Создание тестовых данных обратной связи
     */
    public function createSampleFeedback() {
        $this->log("Создаем тестовые данные обратной связи...");
        
        $sampleFeedback = [
            ['user_001', 'master_products', 4, 'Удобный интерфейс для создания мастер-продуктов', 'improvement', 'low'],
            ['user_001', 'sku_mapping', 5, 'Автоматическое сопоставление работает отлично', 'other', 'low'],
            ['user_002', 'search', 3, 'Поиск работает, но медленно', 'bug', 'medium'],
            ['user_002', 'reports', 4, 'Отчеты информативные и полезные', 'other', 'low'],
            ['user_003', 'master_products', 2, 'Сложно найти нужные поля для редактирования', 'improvement', 'high'],
            ['user_003', 'sku_mapping', 4, 'Хорошо работает, но нужна возможность массового сопоставления', 'feature_request', 'medium'],
            ['user_004', 'search', 5, 'Быстрый и точный поиск', 'other', 'low'],
            ['user_004', 'reports', 3, 'Нужно больше вариантов экспорта', 'feature_request', 'medium'],
            ['user_005', 'master_products', 4, 'В целом хорошо, но нужны подсказки для новых пользователей', 'improvement', 'medium'],
            ['user_005', 'search', 2, 'Поиск по категориям работает некорректно', 'bug', 'high']
        ];
        
        $added = 0;
        foreach ($sampleFeedback as $feedback) {
            if ($this->addFeedback($feedback[0], $feedback[1], $feedback[2], $feedback[3], $feedback[4], $feedback[5])) {
                $added++;
            }
        }
        
        $this->log("Добавлено $added тестовых записей обратной связи");
        return $added;
    }
}

// Запуск сбора обратной связи
if (php_sapi_name() === 'cli') {
    $collector = new FeedbackCollector();
    
    // Проверяем аргументы командной строки
    $action = $argv[1] ?? 'report';
    
    switch ($action) {
        case 'sample':
            echo "Создание тестовых данных обратной связи...\n";
            $collector->createSampleFeedback();
            break;
            
        case 'load':
            if (isset($argv[2])) {
                echo "Загрузка обратной связи из файла: {$argv[2]}\n";
                $collector->loadFeedbackFromFile($argv[2]);
            } else {
                echo "Укажите путь к файлу: php collect-feedback.php load /path/to/feedback.json\n";
            }
            break;
            
        case 'report':
        default:
            echo "Создание сводного отчета по обратной связи...\n";
            $report = $collector->createSummaryReport();
            
            echo "\n=== СВОДНЫЙ ОТЧЕТ ПО ПИЛОТНОМУ ТЕСТИРОВАНИЮ ===\n";
            echo "Дата отчета: " . $report['report_date'] . "\n";
            echo "Статус пилота: " . $report['pilot_status'] . "\n\n";
            
            echo "Общая статистика:\n";
            echo "- Всего отзывов: " . $report['overall_statistics']['total_feedback'] . "\n";
            echo "- Средняя оценка: " . round($report['overall_statistics']['overall_avg_rating'], 2) . "\n";
            echo "- Уникальных пользователей: " . $report['overall_statistics']['unique_users'] . "\n";
            echo "- Протестированных функций: " . $report['overall_statistics']['features_covered'] . "\n";
            
            echo "\nАнализ по функциям:\n";
            foreach ($report['feature_analysis'] as $feature) {
                echo "- {$feature['feature']}: {$feature['avg_rating']} ({$feature['status']})\n";
            }
            
            echo "\nРекомендации:\n";
            foreach ($report['recommendations'] as $rec) {
                echo "- [{$rec['priority']}] {$rec['description']}\n";
            }
            
            break;
    }
}

?>