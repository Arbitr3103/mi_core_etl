<?php

namespace MDM\ETL\DataTransformers;

use Exception;
use PDO;

/**
 * Трансформер данных товаров
 * Специализированная обработка товарных данных из различных источников
 */
class ProductDataTransformer extends BaseTransformer
{
    private array $brandMappings = [];
    private array $categoryMappings = [];
    private array $commonTypos = [];
    
    public function __construct(PDO $pdo, array $config = [])
    {
        parent::__construct($pdo, $config);
        
        $this->loadBrandMappings();
        $this->loadCategoryMappings();
        $this->loadCommonTypos();
    }
    
    /**
     * Трансформация данных товара
     * 
     * @param array $data Данные товара
     * @return array Трансформированные данные
     */
    public function transform(array $data): array
    {
        $originalData = $data;
        
        try {
            // Применяем базовые правила трансформации
            foreach ($data as $field => $value) {
                $data[$field] = $this->applyFieldRules($field, $value);
            }
            
            // Специализированная обработка товарных полей
            $data = $this->transformProductName($data);
            $data = $this->transformBrand($data);
            $data = $this->transformCategory($data);
            $data = $this->transformPrice($data);
            $data = $this->transformDescription($data);
            $data = $this->transformAttributes($data);
            
            // Валидация результата
            if (!$this->validateTransformation($originalData, $data)) {
                throw new Exception('Валидация трансформации не прошла');
            }
            
            // Добавляем метаданные трансформации
            $data['transformation_stats'] = $this->getTransformationStats($originalData, $data);
            $data['transformed_at'] = date('Y-m-d H:i:s');
            
            $this->log('INFO', 'Трансформация товара завершена', [
                'sku' => $data['external_sku'],
                'stats' => $data['transformation_stats']
            ]);
            
            return $data;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка трансформации товара', [
                'sku' => $data['external_sku'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            // Возвращаем исходные данные с пометкой об ошибке
            $originalData['transformation_error'] = $e->getMessage();
            $originalData['transformed_at'] = date('Y-m-d H:i:s');
            
            return $originalData;
        }
    }
    
    /**
     * Получение имени трансформера
     * 
     * @return string
     */
    public function getTransformerName(): string
    {
        return 'product';
    }
    
    /**
     * Трансформация названия товара
     * 
     * @param array $data Данные товара
     * @return array Обновленные данные
     */
    private function transformProductName(array $data): array
    {
        if (empty($data['source_name'])) {
            return $data;
        }
        
        $name = $data['source_name'];
        
        // Удаляем лишние пробелы и символы
        $name = preg_replace('/\s+/', ' ', trim($name));
        
        // Исправляем типичные опечатки
        $name = $this->fixCommonTypos($name);
        
        // Стандартизируем единицы измерения в названии
        $name = $this->standardizeUnitsInName($name);
        
        // Удаляем избыточную информацию
        $name = $this->removeRedundantInfo($name);
        
        // Нормализуем регистр
        $name = $this->normalizeNameCase($name);
        
        $data['source_name'] = $name;
        
        return $data;
    }
    
    /**
     * Трансформация бренда
     * 
     * @param array $data Данные товара
     * @return array Обновленные данные
     */
    private function transformBrand(array $data): array
    {
        if (empty($data['source_brand'])) {
            // Пытаемся извлечь бренд из названия
            $data['source_brand'] = $this->extractBrandFromName($data['source_name'] ?? '');
        }
        
        if (!empty($data['source_brand'])) {
            $brand = $data['source_brand'];
            
            // Очищаем бренд
            $brand = trim($brand);
            $brand = preg_replace('/\s+/', ' ', $brand);
            
            // Применяем маппинг брендов
            $brand = $this->mapBrand($brand);
            
            // Нормализуем регистр бренда
            $brand = $this->normalizeBrandCase($brand);
            
            $data['source_brand'] = $brand;
        }
        
        // Если бренд все еще пустой, устанавливаем значение по умолчанию
        if (empty($data['source_brand'])) {
            $data['source_brand'] = 'Неизвестный бренд';
        }
        
        return $data;
    }
    
    /**
     * Трансформация категории
     * 
     * @param array $data Данные товара
     * @return array Обновленные данные
     */
    private function transformCategory(array $data): array
    {
        if (empty($data['source_category'])) {
            // Пытаемся определить категорию по названию
            $data['source_category'] = $this->detectCategoryFromName($data['source_name'] ?? '');
        }
        
        if (!empty($data['source_category'])) {
            $category = $data['source_category'];
            
            // Очищаем категорию
            $category = trim($category);
            $category = preg_replace('/\s+/', ' ', $category);
            
            // Применяем маппинг категорий
            $category = $this->mapCategory($category);
            
            // Нормализуем категорию
            $category = $this->normalizeCategory($category);
            
            $data['source_category'] = $category;
        }
        
        // Если категория все еще пустая, устанавливаем значение по умолчанию
        if (empty($data['source_category'])) {
            $data['source_category'] = 'Без категории';
        }
        
        return $data;
    }
    
    /**
     * Трансформация цены
     * 
     * @param array $data Данные товара
     * @return array Обновленные данные
     */
    private function transformPrice(array $data): array
    {
        if (isset($data['price'])) {
            $price = $data['price'];
            
            // Если цена строка, очищаем её
            if (is_string($price)) {
                // Удаляем все кроме цифр, точки и запятой
                $price = preg_replace('/[^\d.,]/', '', $price);
                
                // Заменяем запятую на точку
                $price = str_replace(',', '.', $price);
                
                // Конвертируем в число
                $price = floatval($price);
            }
            
            // Валидируем цену
            if ($price < 0) {
                $price = 0;
            }
            
            // Проверяем на разумность (не больше 10 миллионов)
            if ($price > 10000000) {
                $this->log('WARNING', 'Подозрительно высокая цена', [
                    'sku' => $data['external_sku'],
                    'price' => $price
                ]);
            }
            
            $data['price'] = round($price, 2);
        }
        
        return $data;
    }
    
    /**
     * Трансформация описания
     * 
     * @param array $data Данные товара
     * @return array Обновленные данные
     */
    private function transformDescription(array $data): array
    {
        if (!empty($data['description'])) {
            $description = $data['description'];
            
            // Удаляем HTML теги
            $description = strip_tags($description);
            
            // Декодируем HTML сущности
            $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
            
            // Удаляем лишние пробелы и переносы строк
            $description = preg_replace('/\s+/', ' ', trim($description));
            
            // Ограничиваем длину описания
            if (mb_strlen($description, 'UTF-8') > 1000) {
                $description = mb_substr($description, 0, 997, 'UTF-8') . '...';
            }
            
            $data['description'] = $description;
        }
        
        return $data;
    }
    
    /**
     * Трансформация атрибутов
     * 
     * @param array $data Данные товара
     * @return array Обновленные данные
     */
    private function transformAttributes(array $data): array
    {
        if (!empty($data['attributes']) && is_array($data['attributes'])) {
            $attributes = $data['attributes'];
            $cleanedAttributes = [];
            
            foreach ($attributes as $key => $value) {
                // Очищаем ключ атрибута
                $cleanKey = $this->cleanAttributeKey($key);
                
                // Очищаем значение атрибута
                $cleanValue = $this->cleanAttributeValue($value);
                
                if (!empty($cleanKey) && !empty($cleanValue)) {
                    $cleanedAttributes[$cleanKey] = $cleanValue;
                }
            }
            
            $data['attributes'] = $cleanedAttributes;
        }
        
        return $data;
    }
    
    /**
     * Исправление типичных опечаток
     * 
     * @param string $text Текст для исправления
     * @return string Исправленный текст
     */
    private function fixCommonTypos(string $text): string
    {
        foreach ($this->commonTypos as $typo => $correct) {
            $text = str_ireplace($typo, $correct, $text);
        }
        
        return $text;
    }
    
    /**
     * Стандартизация единиц измерения в названии
     * 
     * @param string $name Название товара
     * @return string Стандартизированное название
     */
    private function standardizeUnitsInName(string $name): string
    {
        $unitPatterns = [
            '/(\d+)\s*гр\b/iu' => '$1г',
            '/(\d+)\s*грамм\b/iu' => '$1г',
            '/(\d+)\s*кг\b/iu' => '$1кг',
            '/(\d+)\s*килограмм\b/iu' => '$1кг',
            '/(\d+)\s*мл\b/iu' => '$1мл',
            '/(\d+)\s*миллилитр\b/iu' => '$1мл',
            '/(\d+)\s*л\b/iu' => '$1л',
            '/(\d+)\s*литр\b/iu' => '$1л',
            '/(\d+)\s*шт\b/iu' => '$1шт',
            '/(\d+)\s*штук\b/iu' => '$1шт',
            '/(\d+)\s*см\b/iu' => '$1см',
            '/(\d+)\s*сантиметр\b/iu' => '$1см'
        ];
        
        foreach ($unitPatterns as $pattern => $replacement) {
            $name = preg_replace($pattern, $replacement, $name);
        }
        
        return $name;
    }
    
    /**
     * Удаление избыточной информации из названия
     * 
     * @param string $name Название товара
     * @return string Очищенное название
     */
    private function removeRedundantInfo(string $name): string
    {
        // Удаляем артикулы и коды товаров
        $name = preg_replace('/\b(арт\.?|код\.?|sku\.?)\s*[:\-]?\s*[\w\-]+/iu', '', $name);
        
        // Удаляем избыточные фразы
        $redundantPhrases = [
            'высокое качество',
            'лучшая цена',
            'быстрая доставка',
            'в наличии',
            'новинка',
            'хит продаж',
            'акция',
            'скидка'
        ];
        
        foreach ($redundantPhrases as $phrase) {
            $name = preg_replace('/\b' . preg_quote($phrase, '/') . '\b/iu', '', $name);
        }
        
        // Удаляем лишние пробелы после очистки
        $name = preg_replace('/\s+/', ' ', trim($name));
        
        return $name;
    }
    
    /**
     * Нормализация регистра названия
     * 
     * @param string $name Название товара
     * @return string Нормализованное название
     */
    private function normalizeNameCase(string $name): string
    {
        // Если название полностью в верхнем регистре, приводим к заглавному
        if (mb_strtoupper($name, 'UTF-8') === $name) {
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }
        
        // Исправляем регистр после цифр и специальных символов
        $name = preg_replace_callback('/(\d+)([а-яё]+)/iu', function($matches) {
            return $matches[1] . mb_strtolower($matches[2], 'UTF-8');
        }, $name);
        
        return $name;
    }
    
    /**
     * Извлечение бренда из названия товара
     * 
     * @param string $name Название товара
     * @return string Извлеченный бренд
     */
    private function extractBrandFromName(string $name): string
    {
        // Ищем известные бренды в названии
        foreach ($this->brandMappings as $brand => $standardBrand) {
            if (stripos($name, $brand) !== false) {
                return $standardBrand;
            }
        }
        
        // Пытаемся извлечь первое слово как бренд
        $words = explode(' ', trim($name));
        if (!empty($words[0]) && mb_strlen($words[0], 'UTF-8') > 2) {
            return $words[0];
        }
        
        return '';
    }
    
    /**
     * Маппинг бренда к стандартному названию
     * 
     * @param string $brand Исходный бренд
     * @return string Стандартизированный бренд
     */
    private function mapBrand(string $brand): string
    {
        // Прямое соответствие
        if (isset($this->brandMappings[$brand])) {
            return $this->brandMappings[$brand];
        }
        
        // Поиск по частичному совпадению
        foreach ($this->brandMappings as $pattern => $standardBrand) {
            if (stripos($brand, $pattern) !== false) {
                return $standardBrand;
            }
        }
        
        return $brand;
    }
    
    /**
     * Нормализация регистра бренда
     * 
     * @param string $brand Бренд
     * @return string Нормализованный бренд
     */
    private function normalizeBrandCase(string $brand): string
    {
        // Известные бренды с особым написанием
        $specialCaseBrands = [
            'apple' => 'Apple',
            'samsung' => 'Samsung',
            'xiaomi' => 'Xiaomi',
            'huawei' => 'Huawei',
            'lg' => 'LG',
            'sony' => 'Sony',
            'nike' => 'Nike',
            'adidas' => 'Adidas'
        ];
        
        $lowerBrand = mb_strtolower($brand, 'UTF-8');
        
        if (isset($specialCaseBrands[$lowerBrand])) {
            return $specialCaseBrands[$lowerBrand];
        }
        
        // По умолчанию делаем первую букву заглавной
        return mb_convert_case($brand, MB_CASE_TITLE, 'UTF-8');
    }
    
    /**
     * Определение категории по названию товара
     * 
     * @param string $name Название товара
     * @return string Определенная категория
     */
    private function detectCategoryFromName(string $name): string
    {
        $categoryKeywords = [
            'Электроника' => ['телефон', 'смартфон', 'планшет', 'ноутбук', 'компьютер', 'наушники'],
            'Одежда' => ['футболка', 'рубашка', 'брюки', 'джинсы', 'платье', 'куртка'],
            'Обувь' => ['кроссовки', 'ботинки', 'туфли', 'сапоги', 'сандалии'],
            'Дом и сад' => ['мебель', 'декор', 'посуда', 'текстиль', 'инструмент'],
            'Красота и здоровье' => ['косметика', 'парфюм', 'крем', 'шампунь', 'витамины'],
            'Спорт и отдых' => ['тренажер', 'мяч', 'велосипед', 'лыжи', 'палатка'],
            'Автотовары' => ['шины', 'масло', 'фильтр', 'аккумулятор', 'автозапчасти'],
            'Продукты питания' => ['мука', 'сахар', 'масло', 'крупа', 'консервы', 'чай', 'кофе']
        ];
        
        $nameLower = mb_strtolower($name, 'UTF-8');
        
        foreach ($categoryKeywords as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($nameLower, $keyword) !== false) {
                    return $category;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Маппинг категории к стандартному названию
     * 
     * @param string $category Исходная категория
     * @return string Стандартизированная категория
     */
    private function mapCategory(string $category): string
    {
        // Прямое соответствие
        if (isset($this->categoryMappings[$category])) {
            return $this->categoryMappings[$category];
        }
        
        // Поиск по частичному совпадению
        foreach ($this->categoryMappings as $pattern => $standardCategory) {
            if (stripos($category, $pattern) !== false) {
                return $standardCategory;
            }
        }
        
        return $category;
    }
    
    /**
     * Нормализация категории
     * 
     * @param string $category Категория
     * @return string Нормализованная категория
     */
    private function normalizeCategory(string $category): string
    {
        // Приводим к заглавному регистру
        return mb_convert_case($category, MB_CASE_TITLE, 'UTF-8');
    }
    
    /**
     * Очистка ключа атрибута
     * 
     * @param string $key Ключ атрибута
     * @return string Очищенный ключ
     */
    private function cleanAttributeKey(string $key): string
    {
        // Удаляем лишние символы
        $key = preg_replace('/[^\w\s\-_]/u', '', $key);
        
        // Заменяем пробелы на подчеркивания
        $key = preg_replace('/\s+/', '_', trim($key));
        
        // Приводим к нижнему регистру
        $key = mb_strtolower($key, 'UTF-8');
        
        return $key;
    }
    
    /**
     * Очистка значения атрибута
     * 
     * @param mixed $value Значение атрибута
     * @return mixed Очищенное значение
     */
    private function cleanAttributeValue($value)
    {
        if (is_string($value)) {
            // Удаляем HTML теги
            $value = strip_tags($value);
            
            // Удаляем лишние пробелы
            $value = preg_replace('/\s+/', ' ', trim($value));
            
            // Ограничиваем длину
            if (mb_strlen($value, 'UTF-8') > 255) {
                $value = mb_substr($value, 0, 252, 'UTF-8') . '...';
            }
        }
        
        return $value;
    }
    
    /**
     * Загрузка маппингов брендов
     */
    private function loadBrandMappings(): void
    {
        // Можно загружать из базы данных или конфигурационного файла
        $this->brandMappings = [
            'apple' => 'Apple',
            'эппл' => 'Apple',
            'samsung' => 'Samsung',
            'самсунг' => 'Samsung',
            'xiaomi' => 'Xiaomi',
            'сяоми' => 'Xiaomi',
            'huawei' => 'Huawei',
            'хуавей' => 'Huawei',
            'lg' => 'LG',
            'эл джи' => 'LG',
            'sony' => 'Sony',
            'сони' => 'Sony',
            'nike' => 'Nike',
            'найк' => 'Nike',
            'adidas' => 'Adidas',
            'адидас' => 'Adidas'
        ];
    }
    
    /**
     * Загрузка маппингов категорий
     */
    private function loadCategoryMappings(): void
    {
        $this->categoryMappings = [
            'electronics' => 'Электроника',
            'электроника' => 'Электроника',
            'clothing' => 'Одежда',
            'одежда' => 'Одежда',
            'shoes' => 'Обувь',
            'обувь' => 'Обувь',
            'home' => 'Дом и сад',
            'дом и сад' => 'Дом и сад',
            'beauty' => 'Красота и здоровье',
            'красота' => 'Красота и здоровье',
            'sport' => 'Спорт и отдых',
            'спорт' => 'Спорт и отдых',
            'auto' => 'Автотовары',
            'авто' => 'Автотовары',
            'food' => 'Продукты питания',
            'продукты' => 'Продукты питания'
        ];
    }
    
    /**
     * Загрузка типичных опечаток
     */
    private function loadCommonTypos(): void
    {
        $this->commonTypos = [
            'телефон' => 'телефон',
            'тилефон' => 'телефон',
            'смартфон' => 'смартфон',
            'смарфон' => 'смартфон',
            'компьютер' => 'компьютер',
            'компютер' => 'компьютер',
            'ноутбук' => 'ноутбук',
            'нотбук' => 'ноутбук',
            'планшет' => 'планшет',
            'планшэт' => 'планшет'
        ];
    }
}