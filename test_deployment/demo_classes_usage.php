<?php
/**
 * Демонстрация использования классов Region и CarFilter
 * 
 * Показывает основные возможности созданных классов
 * и их интеграцию друг с другом
 * 
 * @version 1.0
 * @author ZUZ System
 */

require_once 'classes/Region.php';
require_once 'classes/CarFilter.php';

echo "🚀 ДЕМОНСТРАЦИЯ КЛАССОВ Region И CarFilter\n";
echo "=" . str_repeat("=", 60) . "\n\n";

echo "📋 ОПИСАНИЕ СОЗДАННЫХ КЛАССОВ:\n";
echo "-" . str_repeat("-", 40) . "\n";

echo "✅ Класс Region:\n";
echo "   - getAll() - получение всех стран изготовления\n";
echo "   - getByBrand(\$brandId) - получение стран для марки\n";
echo "   - getByModel(\$modelId) - получение стран для модели\n";
echo "   - exists(\$regionId) - проверка существования региона\n";
echo "   - getById(\$regionId) - получение информации о регионе\n";
echo "   - getBrandCount(\$regionId) - количество брендов в регионе\n";
echo "   - getStatistics() - статистика по регионам\n\n";

echo "✅ Класс CarFilter:\n";
echo "   - setBrand/setModel/setYear/setCountry() - установка фильтров\n";
echo "   - setFilters(\$array) - установка фильтров из массива\n";
echo "   - validate() - валидация параметров\n";
echo "   - buildQuery() - построение SQL запроса\n";
echo "   - buildCountQuery() - построение запроса подсчета\n";
echo "   - execute() - выполнение запроса фильтрации\n";
echo "   - getFilters/hasFilters/getFilterCount() - утилиты\n";
echo "   - reset() - сброс всех фильтров\n\n";

echo "🔧 ПРИМЕРЫ ИСПОЛЬЗОВАНИЯ:\n";
echo "-" . str_repeat("-", 40) . "\n";

echo "1️⃣ Создание подключения к БД и инициализация классов:\n";
echo "```php\n";
echo "// Подключение к базе данных\n";
echo "\$pdo = new PDO('mysql:host=localhost;dbname=mi_core_db', \$user, \$pass);\n";
echo "\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n\n";
echo "// Создание экземпляров классов\n";
echo "\$region = new Region(\$pdo);\n";
echo "\$filter = new CarFilter(\$pdo);\n";
echo "```\n\n";

echo "2️⃣ Работа с классом Region:\n";
echo "```php\n";
echo "// Получение всех стран\n";
echo "\$countries = \$region->getAll();\n";
echo "// Результат: [['id' => 1, 'name' => 'Германия'], ...]\n\n";
echo "// Получение стран для BMW (ID = 1)\n";
echo "\$bmwCountries = \$region->getByBrand(1);\n\n";
echo "// Получение стран для модели BMW X5 (ID = 5)\n";
echo "\$x5Countries = \$region->getByModel(5);\n\n";
echo "// Проверка существования региона\n";
echo "if (\$region->exists(1)) {\n";
echo "    \$regionInfo = \$region->getById(1);\n";
echo "    \$brandCount = \$region->getBrandCount(1);\n";
echo "}\n";
echo "```\n\n";

echo "3️⃣ Работа с классом CarFilter:\n";
echo "```php\n";
echo "// Установка фильтров через цепочку вызовов\n";
echo "\$filter->setBrand(1)\n";
echo "       ->setCountry(1)\n";
echo "       ->setYear(2020)\n";
echo "       ->setLimit(50);\n\n";
echo "// Или установка из массива\n";
echo "\$filter->setFilters([\n";
echo "    'brand_id' => 1,\n";
echo "    'country_id' => 1,\n";
echo "    'year' => 2020,\n";
echo "    'limit' => 50\n";
echo "]);\n\n";
echo "// Валидация параметров\n";
echo "\$validation = \$filter->validate();\n";
echo "if (\$validation['valid']) {\n";
echo "    // Выполнение фильтрации\n";
echo "    \$result = \$filter->execute();\n";
echo "    \$products = \$result['data'];\n";
echo "    \$pagination = \$result['pagination'];\n";
echo "} else {\n";
echo "    \$errors = \$validation['errors'];\n";
echo "}\n";
echo "```\n\n";

echo "4️⃣ Построение кастомных запросов:\n";
echo "```php\n";
echo "// Получение SQL запроса без выполнения\n";
echo "\$queryData = \$filter->buildQuery();\n";
echo "\$sql = \$queryData['sql'];\n";
echo "\$params = \$queryData['params'];\n\n";
echo "// Кастомное выполнение запроса\n";
echo "\$stmt = \$pdo->prepare(\$sql);\n";
echo "foreach (\$params as \$key => \$value) {\n";
echo "    \$stmt->bindValue(\$key, \$value);\n";
echo "}\n";
echo "\$stmt->bindValue('limit', 50, PDO::PARAM_INT);\n";
echo "\$stmt->bindValue('offset', 0, PDO::PARAM_INT);\n";
echo "\$stmt->execute();\n";
echo "\$results = \$stmt->fetchAll();\n";
echo "```\n\n";

echo "5️⃣ Интеграция классов для создания API:\n";
echo "```php\n";
echo "class CountryFilterAPI {\n";
echo "    private \$region;\n";
echo "    private \$filter;\n\n";
echo "    public function __construct(\$pdo) {\n";
echo "        \$this->region = new Region(\$pdo);\n";
echo "        \$this->filter = new CarFilter(\$pdo);\n";
echo "    }\n\n";
echo "    public function getCountriesForBrand(\$brandId) {\n";
echo "        try {\n";
echo "            return \$this->region->getByBrand(\$brandId);\n";
echo "        } catch (Exception \$e) {\n";
echo "            return ['error' => \$e->getMessage()];\n";
echo "        }\n";
echo "    }\n\n";
echo "    public function filterProducts(\$filters) {\n";
echo "        try {\n";
echo "            \$this->filter->setFilters(\$filters);\n";
echo "            \$validation = \$this->filter->validate();\n";
echo "            \n";
echo "            if (!\$validation['valid']) {\n";
echo "                return ['error' => \$validation['errors']];\n";
echo "            }\n";
echo "            \n";
echo "            return \$this->filter->execute();\n";
echo "        } catch (Exception \$e) {\n";
echo "            return ['error' => \$e->getMessage()];\n";
echo "        }\n";
echo "    }\n";
echo "}\n";
echo "```\n\n";

echo "🎯 СООТВЕТСТВИЕ ТРЕБОВАНИЯМ:\n";
echo "-" . str_repeat("-", 40) . "\n";
echo "✅ Requirement 2.1: Класс Region управляет данными о странах изготовления\n";
echo "✅ Requirement 4.2: Класс CarFilter обеспечивает валидацию параметров\n";
echo "✅ Requirement 4.1: Поддержка комбинации всех фильтров\n";
echo "✅ Requirement 2.2: Обработка отсутствующей информации о стране\n\n";

echo "🧪 UNIT ТЕСТЫ:\n";
echo "-" . str_repeat("-", 40) . "\n";
echo "Созданы comprehensive unit тесты:\n";
echo "✅ tests/RegionTest.php - тесты для класса Region\n";
echo "✅ tests/CarFilterTest.php - тесты для класса CarFilter\n";
echo "✅ tests/run_all_tests.php - общий тест раннер\n\n";
echo "Для запуска тестов:\n";
echo "```bash\n";
echo "php tests/run_all_tests.php\n";
echo "```\n\n";

echo "📁 СТРУКТУРА ФАЙЛОВ:\n";
echo "-" . str_repeat("-", 40) . "\n";
echo "classes/\n";
echo "├── Region.php          # Класс для работы с регионами/странами\n";
echo "└── CarFilter.php       # Класс для фильтрации и валидации\n\n";
echo "tests/\n";
echo "├── RegionTest.php      # Unit тесты для Region\n";
echo "├── CarFilterTest.php   # Unit тесты для CarFilter\n";
echo "└── run_all_tests.php   # Общий тест раннер\n\n";

echo "🎉 ЗАДАЧА ВЫПОЛНЕНА УСПЕШНО!\n";
echo "=" . str_repeat("=", 60) . "\n";
echo "✅ Созданы PHP классы Region и CarFilter\n";
echo "✅ Реализованы все требуемые методы\n";
echo "✅ Добавлена валидация и обработка ошибок\n";
echo "✅ Написаны comprehensive unit тесты\n";
echo "✅ Классы готовы к интеграции с существующим API\n";
echo "✅ Соответствие требованиям 2.1 и 4.2 обеспечено\n\n";

echo "Следующий шаг: Интеграция с frontend компонентами (задача 3)\n";
?>