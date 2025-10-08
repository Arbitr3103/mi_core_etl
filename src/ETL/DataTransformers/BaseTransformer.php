<?php

namespace MDM\ETL\DataTransformers;

use Exception;
use PDO;

/**
 * Базовый класс для трансформации данных
 * Предоставляет общую функциональность для очистки и стандартизации данных
 */
abstract class BaseTransformer
{
    protected PDO $pdo;
    protected array $config;
    protected array $transformationRules = [];
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
        
        $this->loadTransformationRules();
    }
    
    /**
     * Трансформация массива данных
     * 
     * @param array $data Данные для трансформации
     * @return array Трансформированные данные
     */
    abstract public function transform(array $data): array;
    
    /**
     * Получение имени трансформера
     * 
     * @return string
     */
    abstract public function getTransformerName(): string;
    
    /**
     * Загрузка правил трансформации из базы данных
     */
    protected function loadTransformationRules(): void
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT field_name, rule_type, rule_config, priority
                FROM etl_transformation_rules 
                WHERE (source = ? OR source = '*') AND is_active = 1
                ORDER BY priority DESC, id ASC
            ");
            $stmt->execute([$this->getTransformerName()]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $config = json_decode($row['rule_config'], true);
                
                $this->transformationRules[$row['field_name']][] = [
                    'type' => $row['rule_type'],
                    'config' => $config,
                    'priority' => $row['priority']
                ];
            }
            
        } catch (Exception $e) {
            error_log("Ошибка загрузки правил трансформации: " . $e->getMessage());
        }
    }
    
    /**
     * Применение правил трансформации к полю
     * 
     * @param string $fieldName Имя поля
     * @param mixed $value Значение поля
     * @return mixed Трансформированное значение
     */
    protected function applyFieldRules(string $fieldName, $value)
    {
        if (!isset($this->transformationRules[$fieldName])) {
            return $value;
        }
        
        foreach ($this->transformationRules[$fieldName] as $rule) {
            try {
                $value = $this->applyRule($rule['type'], $value, $rule['config']);
            } catch (Exception $e) {
                $this->log('WARNING', "Ошибка применения правила {$rule['type']} к полю $fieldName", [
                    'error' => $e->getMessage(),
                    'value' => $value
                ]);
            }
        }
        
        return $value;
    }
    
    /**
     * Применение конкретного правила трансформации
     * 
     * @param string $ruleType Тип правила
     * @param mixed $value Значение
     * @param array $config Конфигурация правила
     * @return mixed Трансформированное значение
     */
    protected function applyRule(string $ruleType, $value, array $config)
    {
        switch ($ruleType) {
            case 'cleanup':
                return $this->applyCleanupRule($value, $config);
                
            case 'normalize':
                return $this->applyNormalizeRule($value, $config);
                
            case 'mapping':
                return $this->applyMappingRule($value, $config);
                
            case 'validation':
                return $this->applyValidationRule($value, $config);
                
            default:
                return $value;
        }
    }
    
    /**
     * Применение правил очистки
     * 
     * @param mixed $value Значение
     * @param array $config Конфигурация
     * @return mixed Очищенное значение
     */
    protected function applyCleanupRule($value, array $config)
    {
        if (!is_string($value)) {
            return $value;
        }
        
        // Удаление лишних пробелов
        if ($config['remove_extra_spaces'] ?? false) {
            $value = preg_replace('/\s+/', ' ', trim($value));
        }
        
        // Удаление управляющих символов
        if ($config['remove_control_chars'] ?? false) {
            $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        }
        
        // Удаление HTML тегов
        if ($config['strip_html'] ?? false) {
            $value = strip_tags($value);
        }
        
        // Удаление специальных символов
        if (!empty($config['remove_chars'])) {
            $chars = is_array($config['remove_chars']) ? $config['remove_chars'] : [$config['remove_chars']];
            foreach ($chars as $char) {
                $value = str_replace($char, '', $value);
            }
        }
        
        // Замена символов
        if (!empty($config['replace_chars'])) {
            foreach ($config['replace_chars'] as $from => $to) {
                $value = str_replace($from, $to, $value);
            }
        }
        
        return $value;
    }
    
    /**
     * Применение правил нормализации
     * 
     * @param mixed $value Значение
     * @param array $config Конфигурация
     * @return mixed Нормализованное значение
     */
    protected function applyNormalizeRule($value, array $config)
    {
        if (!is_string($value)) {
            return $value;
        }
        
        // Приведение к нижнему регистру
        if ($config['to_lowercase'] ?? false) {
            $value = mb_strtolower($value, 'UTF-8');
        }
        
        // Приведение к верхнему регистру
        if ($config['to_uppercase'] ?? false) {
            $value = mb_strtoupper($value, 'UTF-8');
        }
        
        // Приведение к заглавному регистру
        if ($config['to_title_case'] ?? false) {
            $value = mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        }
        
        // Обрезка пробелов
        if ($config['trim'] ?? false) {
            $value = trim($value);
        }
        
        // Стандартизация единиц измерения
        if ($config['standardize_units'] ?? false) {
            $value = $this->standardizeUnits($value);
        }
        
        // Исправление кодировки
        if ($config['fix_encoding'] ?? false) {
            $value = $this->fixEncoding($value);
        }
        
        return $value;
    }
    
    /**
     * Применение правил маппинга
     * 
     * @param mixed $value Значение
     * @param array $config Конфигурация
     * @return mixed Замапленное значение
     */
    protected function applyMappingRule($value, array $config)
    {
        if (!is_string($value)) {
            return $value;
        }
        
        // Прямое соответствие
        if (!empty($config['direct_mapping'])) {
            if (isset($config['direct_mapping'][$value])) {
                return $config['direct_mapping'][$value];
            }
        }
        
        // Соответствие по регулярным выражениям
        if (!empty($config['regex_mapping'])) {
            foreach ($config['regex_mapping'] as $pattern => $replacement) {
                if (preg_match($pattern, $value)) {
                    return preg_replace($pattern, $replacement, $value);
                }
            }
        }
        
        // Соответствие по частичному совпадению
        if (!empty($config['partial_mapping'])) {
            foreach ($config['partial_mapping'] as $search => $replacement) {
                if (stripos($value, $search) !== false) {
                    return $replacement;
                }
            }
        }
        
        // Значение по умолчанию
        if (isset($config['default_value'])) {
            return $config['default_value'];
        }
        
        return $value;
    }
    
    /**
     * Применение правил валидации
     * 
     * @param mixed $value Значение
     * @param array $config Конфигурация
     * @return mixed Валидированное значение
     * @throws Exception При ошибке валидации
     */
    protected function applyValidationRule($value, array $config)
    {
        // Проверка минимального значения для чисел
        if (isset($config['min_value']) && is_numeric($value)) {
            if ($value < $config['min_value']) {
                if (isset($config['min_value_replacement'])) {
                    return $config['min_value_replacement'];
                }
                throw new Exception("Значение $value меньше минимального {$config['min_value']}");
            }
        }
        
        // Проверка максимального значения для чисел
        if (isset($config['max_value']) && is_numeric($value)) {
            if ($value > $config['max_value']) {
                if (isset($config['max_value_replacement'])) {
                    return $config['max_value_replacement'];
                }
                throw new Exception("Значение $value больше максимального {$config['max_value']}");
            }
        }
        
        // Проверка минимальной длины для строк
        if (isset($config['min_length']) && is_string($value)) {
            if (mb_strlen($value, 'UTF-8') < $config['min_length']) {
                if (isset($config['min_length_replacement'])) {
                    return $config['min_length_replacement'];
                }
                throw new Exception("Длина строки меньше минимальной {$config['min_length']}");
            }
        }
        
        // Проверка максимальной длины для строк
        if (isset($config['max_length']) && is_string($value)) {
            if (mb_strlen($value, 'UTF-8') > $config['max_length']) {
                return mb_substr($value, 0, $config['max_length'], 'UTF-8');
            }
        }
        
        // Проверка регулярного выражения
        if (!empty($config['regex_pattern']) && is_string($value)) {
            if (!preg_match($config['regex_pattern'], $value)) {
                if (isset($config['regex_replacement'])) {
                    return $config['regex_replacement'];
                }
                throw new Exception("Значение не соответствует шаблону {$config['regex_pattern']}");
            }
        }
        
        // Проверка списка допустимых значений
        if (!empty($config['allowed_values'])) {
            if (!in_array($value, $config['allowed_values'])) {
                if (isset($config['not_allowed_replacement'])) {
                    return $config['not_allowed_replacement'];
                }
                throw new Exception("Значение не входит в список допустимых");
            }
        }
        
        return $value;
    }
    
    /**
     * Стандартизация единиц измерения
     * 
     * @param string $value Значение с единицами
     * @return string Стандартизированное значение
     */
    protected function standardizeUnits(string $value): string
    {
        $unitMappings = [
            // Вес
            'кг' => 'кг',
            'kg' => 'кг',
            'килограмм' => 'кг',
            'г' => 'г',
            'грамм' => 'г',
            'gram' => 'г',
            
            // Объем
            'л' => 'л',
            'литр' => 'л',
            'liter' => 'л',
            'мл' => 'мл',
            'миллилитр' => 'мл',
            
            // Размеры
            'см' => 'см',
            'сантиметр' => 'см',
            'мм' => 'мм',
            'миллиметр' => 'мм',
            'м' => 'м',
            'метр' => 'м'
        ];
        
        foreach ($unitMappings as $from => $to) {
            $value = preg_replace('/\b' . preg_quote($from, '/') . '\b/iu', $to, $value);
        }
        
        return $value;
    }
    
    /**
     * Исправление проблем с кодировкой
     * 
     * @param string $value Значение с проблемами кодировки
     * @return string Исправленное значение
     */
    protected function fixEncoding(string $value): string
    {
        // Определяем кодировку
        $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
        
        if ($encoding && $encoding !== 'UTF-8') {
            $value = mb_convert_encoding($value, 'UTF-8', $encoding);
        }
        
        // Исправляем типичные проблемы с кодировкой
        $encodingFixes = [
            'Ð°' => 'а', 'Ð±' => 'б', 'Ð²' => 'в', 'Ð³' => 'г', 'Ð´' => 'д',
            'Ðµ' => 'е', 'Ð¶' => 'ж', 'Ð·' => 'з', 'Ð¸' => 'и', 'Ð¹' => 'й',
            'Ðº' => 'к', 'Ð»' => 'л', 'Ð¼' => 'м', 'Ð½' => 'н', 'Ð¾' => 'о',
            'Ð¿' => 'п', 'Ñ€' => 'р', 'Ñ' => 'с', 'Ñ‚' => 'т', 'Ñƒ' => 'у',
            'Ñ„' => 'ф', 'Ñ…' => 'х', 'Ñ†' => 'ц', 'Ñ‡' => 'ч', 'Ñˆ' => 'ш',
            'Ñ‰' => 'щ', 'ÑŠ' => 'ъ', 'Ñ‹' => 'ы', 'ÑŒ' => 'ь', 'Ñ' => 'э',
            'ÑŽ' => 'ю', 'Ñ' => 'я'
        ];
        
        return str_replace(array_keys($encodingFixes), array_values($encodingFixes), $value);
    }
    
    /**
     * Логирование сообщений
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $transformerName = $this->getTransformerName();
        $fullMessage = $message;
        
        if (!empty($context)) {
            $fullMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        error_log("[$transformerName Transformer] [$level] $fullMessage");
        
        // Сохраняем в БД
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $transformerName . '_transformer', 
                $level, 
                $message, 
                !empty($context) ? json_encode($context) : null
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }
    }
    
    /**
     * Валидация трансформированных данных
     * 
     * @param array $originalData Исходные данные
     * @param array $transformedData Трансформированные данные
     * @return bool Результат валидации
     */
    protected function validateTransformation(array $originalData, array $transformedData): bool
    {
        // Проверяем, что основные поля не потерялись
        $requiredFields = ['external_sku', 'source', 'source_name'];
        
        foreach ($requiredFields as $field) {
            if (empty($transformedData[$field])) {
                $this->log('ERROR', "Потеряно обязательное поле $field при трансформации", [
                    'original' => $originalData[$field] ?? null,
                    'transformed' => $transformedData[$field] ?? null
                ]);
                return false;
            }
        }
        
        // Проверяем, что SKU не изменился
        if ($originalData['external_sku'] !== $transformedData['external_sku']) {
            $this->log('ERROR', 'SKU изменился при трансформации', [
                'original' => $originalData['external_sku'],
                'transformed' => $transformedData['external_sku']
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Получение статистики трансформации
     * 
     * @param array $originalData Исходные данные
     * @param array $transformedData Трансформированные данные
     * @return array Статистика
     */
    protected function getTransformationStats(array $originalData, array $transformedData): array
    {
        $stats = [
            'fields_processed' => 0,
            'fields_changed' => 0,
            'fields_cleaned' => 0,
            'errors' => 0
        ];
        
        foreach ($transformedData as $field => $value) {
            $stats['fields_processed']++;
            
            $originalValue = $originalData[$field] ?? null;
            
            if ($originalValue !== $value) {
                $stats['fields_changed']++;
                
                // Проверяем, была ли это очистка (удаление пробелов, символов и т.д.)
                if (is_string($originalValue) && is_string($value)) {
                    $cleanOriginal = trim(preg_replace('/\s+/', ' ', $originalValue));
                    if ($cleanOriginal !== $originalValue && $value === $cleanOriginal) {
                        $stats['fields_cleaned']++;
                    }
                }
            }
        }
        
        return $stats;
    }
}