<?php

namespace MDM\Services;

use MDM\Models\MasterProduct;
use MDM\Models\SkuMapping;

/**
 * MatchingEngine - Движок автоматического сопоставления товаров
 * 
 * Реализует различные алгоритмы для сопоставления товаров из внешних источников
 * с существующими мастер-данными в системе MDM.
 */
class MatchingEngine
{
    /**
     * Типы алгоритмов сопоставления
     */
    const MATCH_TYPE_EXACT_SKU = 'exact_sku';
    const MATCH_TYPE_EXACT_BARCODE = 'exact_barcode';
    const MATCH_TYPE_FUZZY_NAME = 'fuzzy_name';
    const MATCH_TYPE_BRAND_CATEGORY = 'brand_category';

    /**
     * Весовые коэффициенты для разных типов совпадений
     */
    private array $weights = [
        self::MATCH_TYPE_EXACT_SKU => 1.0,
        self::MATCH_TYPE_EXACT_BARCODE => 1.0,
        self::MATCH_TYPE_FUZZY_NAME => 0.4,
        self::MATCH_TYPE_BRAND_CATEGORY => 0.3
    ];

    /**
     * Найти потенциальные совпадения для товара
     * 
     * @param array $productData Данные товара для сопоставления
     * @param array $masterProducts Массив существующих мастер-продуктов
     * @return array Массив потенциальных совпадений с оценками
     */
    public function findMatches(array $productData, array $masterProducts): array
    {
        $matches = [];

        foreach ($masterProducts as $masterProduct) {
            $score = $this->calculateMatchScore($productData, $masterProduct);
            
            if ($score > 0) {
                $matches[] = [
                    'master_product' => $masterProduct,
                    'score' => $score,
                    'match_details' => $this->getMatchDetails($productData, $masterProduct)
                ];
            }
        }

        // Сортировка по убыванию оценки
        usort($matches, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $matches;
    }

    /**
     * Рассчитать общую оценку совпадения между товарами
     * 
     * @param array $productData Данные нового товара
     * @param MasterProduct $masterProduct Существующий мастер-продукт
     * @return float Оценка совпадения от 0 до 1
     */
    public function calculateMatchScore(array $productData, MasterProduct $masterProduct): float
    {
        $totalScore = 0.0;
        $totalWeight = 0.0;

        // Точное сопоставление по SKU
        if ($this->exactSkuMatch($productData, $masterProduct)) {
            return 1.0; // Максимальная оценка для точного совпадения SKU
        }

        // Точное сопоставление по штрихкоду
        if ($this->exactBarcodeMatch($productData, $masterProduct)) {
            return 1.0; // Максимальная оценка для точного совпадения штрихкода
        }

        // Нечеткое сравнение названий
        $nameScore = $this->fuzzyNameMatch($productData, $masterProduct);
        if ($nameScore > 0) {
            $totalScore += $nameScore * $this->weights[self::MATCH_TYPE_FUZZY_NAME];
            $totalWeight += $this->weights[self::MATCH_TYPE_FUZZY_NAME];
        }

        // Сопоставление по бренду и категории
        $brandCategoryScore = $this->brandCategoryMatch($productData, $masterProduct);
        if ($brandCategoryScore > 0) {
            $totalScore += $brandCategoryScore * $this->weights[self::MATCH_TYPE_BRAND_CATEGORY];
            $totalWeight += $this->weights[self::MATCH_TYPE_BRAND_CATEGORY];
        }

        return $totalWeight > 0 ? $totalScore / $totalWeight : 0.0;
    }

    /**
     * Точное сопоставление по SKU
     * 
     * @param array $productData Данные товара
     * @param MasterProduct $masterProduct Мастер-продукт
     * @return bool True если найдено точное совпадение
     */
    public function exactSkuMatch(array $productData, MasterProduct $masterProduct): bool
    {
        $productSku = $productData['sku'] ?? '';
        
        if (empty($productSku)) {
            return false;
        }

        // Проверяем совпадение с существующими SKU маппингами
        // В реальной реализации здесь будет запрос к базе данных
        return false; // Заглушка - будет реализовано при интеграции с репозиториями
    }

    /**
     * Точное сопоставление по штрихкоду
     * 
     * @param array $productData Данные товара
     * @param MasterProduct $masterProduct Мастер-продукт
     * @return bool True если найдено точное совпадение
     */
    public function exactBarcodeMatch(array $productData, MasterProduct $masterProduct): bool
    {
        $productBarcode = $productData['barcode'] ?? '';
        $masterBarcode = $masterProduct->getAttributes()['barcode'] ?? '';

        if (empty($productBarcode) || empty($masterBarcode)) {
            return false;
        }

        return $productBarcode === $masterBarcode;
    }

    /**
     * Нечеткое сравнение названий с использованием расстояния Левенштейна
     * 
     * @param array $productData Данные товара
     * @param MasterProduct $masterProduct Мастер-продукт
     * @return float Оценка совпадения от 0 до 1
     */
    public function fuzzyNameMatch(array $productData, MasterProduct $masterProduct): float
    {
        $productName = $this->normalizeString($productData['name'] ?? '');
        $masterName = $this->normalizeString($masterProduct->getCanonicalName());

        if (empty($productName) || empty($masterName)) {
            return 0.0;
        }

        return $this->calculateLevenshteinSimilarity($productName, $masterName);
    }

    /**
     * Сопоставление по бренду и категории
     * 
     * @param array $productData Данные товара
     * @param MasterProduct $masterProduct Мастер-продукт
     * @return float Оценка совпадения от 0 до 1
     */
    public function brandCategoryMatch(array $productData, MasterProduct $masterProduct): float
    {
        $productBrand = $this->normalizeString($productData['brand'] ?? '');
        $masterBrand = $this->normalizeString($masterProduct->getCanonicalBrand() ?? '');
        
        $productCategory = $this->normalizeString($productData['category'] ?? '');
        $masterCategory = $this->normalizeString($masterProduct->getCanonicalCategory() ?? '');

        $brandMatch = 0.0;
        $categoryMatch = 0.0;

        // Проверка совпадения бренда
        if (!empty($productBrand) && !empty($masterBrand)) {
            $brandMatch = ($productBrand === $masterBrand) ? 1.0 : 0.0;
        }

        // Проверка совпадения категории
        if (!empty($productCategory) && !empty($masterCategory)) {
            $categoryMatch = ($productCategory === $masterCategory) ? 1.0 : 0.0;
        }

        // Возвращаем среднее значение совпадений
        $totalMatches = 0;
        $totalScore = 0;

        if ($brandMatch > 0 || !empty($productBrand)) {
            $totalScore += $brandMatch;
            $totalMatches++;
        }

        if ($categoryMatch > 0 || !empty($productCategory)) {
            $totalScore += $categoryMatch;
            $totalMatches++;
        }

        return $totalMatches > 0 ? $totalScore / $totalMatches : 0.0;
    }

    /**
     * Рассчитать схожесть строк на основе расстояния Левенштейна
     * 
     * @param string $str1 Первая строка
     * @param string $str2 Вторая строка
     * @return float Оценка схожести от 0 до 1
     */
    private function calculateLevenshteinSimilarity(string $str1, string $str2): float
    {
        $maxLength = max(strlen($str1), strlen($str2));
        
        if ($maxLength === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        return 1.0 - ($distance / $maxLength);
    }

    /**
     * Нормализовать строку для сравнения
     * 
     * @param string $str Исходная строка
     * @return string Нормализованная строка
     */
    private function normalizeString(string $str): string
    {
        // Приведение к нижнему регистру
        $normalized = mb_strtolower($str, 'UTF-8');
        
        // Удаление лишних пробелов
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        // Удаление специальных символов (оставляем только буквы, цифры и пробелы)
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
        
        return $normalized;
    }

    /**
     * Получить детали совпадения для анализа
     * 
     * @param array $productData Данные товара
     * @param MasterProduct $masterProduct Мастер-продукт
     * @return array Детали совпадения
     */
    private function getMatchDetails(array $productData, MasterProduct $masterProduct): array
    {
        return [
            'exact_sku_match' => $this->exactSkuMatch($productData, $masterProduct),
            'exact_barcode_match' => $this->exactBarcodeMatch($productData, $masterProduct),
            'name_similarity' => $this->fuzzyNameMatch($productData, $masterProduct),
            'brand_category_match' => $this->brandCategoryMatch($productData, $masterProduct),
            'product_name' => $productData['name'] ?? '',
            'master_name' => $masterProduct->getCanonicalName(),
            'product_brand' => $productData['brand'] ?? '',
            'master_brand' => $masterProduct->getCanonicalBrand() ?? ''
        ];
    }

    /**
     * Установить весовые коэффициенты для алгоритмов
     * 
     * @param array $weights Массив весов
     */
    public function setWeights(array $weights): void
    {
        $this->weights = array_merge($this->weights, $weights);
    }

    /**
     * Получить текущие весовые коэффициенты
     * 
     * @return array Массив весов
     */
    public function getWeights(): array
    {
        return $this->weights;
    }
}