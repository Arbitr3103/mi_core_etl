# Тесты API управления синхронизацией остатков

Комплексная система тестирования для API управления синхронизацией остатков товаров с маркетплейсами.

## Обзор

Система тестирования включает:

- **Integration тесты** - проверка всех API endpoints
- **Тесты веб-интерфейса** - проверка HTML страниц и JavaScript функциональности
- **Тесты безопасности** - проверка защиты от уязвимостей
- **Unit тесты** - тестирование отдельных компонентов
- **Тесты производительности** - проверка времени отклика и нагрузки

## Структура тестов

### Файлы тестов

```
test_inventory_sync_api.py                    # Базовые unit тесты
test_inventory_sync_api_integration.py        # Интеграционные тесты
test_inventory_sync_api_security.py           # Тесты безопасности
test_inventory_sync_web_interface.py          # Тесты веб-интерфейса
run_inventory_sync_api_tests.py               # Комплексный запуск тестов
README_API_TESTS.md                           # Документация тестов
```

### Покрытие тестами

#### API Endpoints

- ✅ `GET /api/sync/status` - получение статуса синхронизации
- ✅ `POST /api/sync/trigger` - запуск принудительной синхронизации
- ✅ `GET /api/sync/reports` - получение отчетов о синхронизации
- ✅ `GET /api/sync/logs` - получение логов синхронизации
- ✅ `GET /api/sync/health` - проверка состояния системы

#### Веб-интерфейс

- ✅ `GET /` - главная страница дашборда
- ✅ `GET /logs` - страница логов
- ✅ JavaScript функциональность
- ✅ CSS стили и адаптивность
- ✅ Интерактивные элементы

#### Безопасность

- ✅ SQL injection защита
- ✅ XSS защита
- ✅ Command injection защита
- ✅ Path traversal защита
- ✅ HTTP header injection защита
- ✅ Валидация входных данных
- ✅ Rate limiting поведение
- ✅ Обработка ошибок без раскрытия информации

## Запуск тестов

### Требования

```bash
# Основные зависимости
pip install flask flask-cors requests beautifulsoup4

# Для тестов веб-интерфейса (опционально)
pip install selenium

# Для установки ChromeDriver (если используется Selenium)
# Ubuntu/Debian:
sudo apt-get install chromium-chromedriver

# macOS:
brew install chromedriver
```

### Быстрый запуск

```bash
# Запуск всех тестов
python run_inventory_sync_api_tests.py

# Или
python run_inventory_sync_api_tests.py all
```

### Запуск отдельных групп тестов

```bash
# Unit тесты (не требуют запущенного API)
python run_inventory_sync_api_tests.py unit

# Интеграционные тесты
python run_inventory_sync_api_tests.py integration

# Тесты безопасности
python run_inventory_sync_api_tests.py security

# Тесты веб-интерфейса
python run_inventory_sync_api_tests.py web
```

### Запуск отдельных файлов тестов

```bash
# Базовые unit тесты
python test_inventory_sync_api.py

# Интеграционные тесты
python test_inventory_sync_api_integration.py

# Тесты безопасности
python test_inventory_sync_api_security.py

# Тесты веб-интерфейса
python test_inventory_sync_web_interface.py
```

## Детальное описание тестов

### Integration тесты (`test_inventory_sync_api_integration.py`)

#### TestInventorySyncAPIIntegration

Тестирует все API endpoints с реальными HTTP запросами:

- `test_api_sync_status_endpoint()` - проверка получения статуса
- `test_api_sync_reports_endpoint()` - проверка отчетов
- `test_api_sync_trigger_endpoint()` - проверка запуска синхронизации
- `test_api_sync_health_endpoint()` - проверка health check
- `test_api_sync_logs_endpoint()` - проверка получения логов

#### TestInventorySyncAPIWebInterface

Тестирует веб-интерфейс:

- `test_dashboard_page_loads()` - загрузка главной страницы
- `test_logs_page_loads()` - загрузка страницы логов
- `test_dashboard_javascript_functionality()` - JavaScript функции
- `test_web_interface_cors_headers()` - CORS заголовки

#### TestInventorySyncAPISecurity

Базовые тесты безопасности:

- `test_api_input_validation()` - валидация входных данных
- `test_api_parameter_limits()` - ограничения параметров
- `test_api_sql_injection_protection()` - защита от SQL injection
- `test_api_http_methods_security()` - безопасность HTTP методов

#### TestInventorySyncAPIPerformance

Тесты производительности:

- `test_api_response_time()` - время отклика endpoints
- `test_api_concurrent_requests()` - обработка параллельных запросов

### Тесты безопасности (`test_inventory_sync_api_security.py`)

#### TestAPISecurityVulnerabilities

Проверка защиты от основных уязвимостей:

- `test_sql_injection_attacks()` - SQL injection атаки
- `test_xss_attacks()` - XSS атаки
- `test_command_injection_attacks()` - Command injection
- `test_path_traversal_attacks()` - Path traversal
- `test_http_header_injection()` - HTTP header injection

#### TestAPIInputValidation

Валидация входных данных:

- `test_json_payload_validation()` - проверка JSON данных
- `test_parameter_type_validation()` - проверка типов параметров
- `test_parameter_length_limits()` - ограничения длины
- `test_special_characters_handling()` - обработка спецсимволов

#### TestAPIAuthenticationSecurity

Безопасность аутентификации:

- `test_no_authentication_bypass()` - отсутствие обхода аутентификации
- `test_session_security()` - безопасность сессий

#### TestAPIRateLimitingSecurity

Защита от DoS атак:

- `test_rapid_requests_handling()` - обработка быстрых запросов
- `test_large_payload_handling()` - обработка больших данных

#### TestAPIErrorHandlingSecurity

Безопасность обработки ошибок:

- `test_error_information_disclosure()` - раскрытие информации в ошибках
- `test_database_error_handling()` - обработка ошибок БД

### Тесты веб-интерфейса (`test_inventory_sync_web_interface.py`)

#### TestWebInterfaceBasic

Базовые тесты веб-интерфейса:

- `test_dashboard_page_structure()` - структура дашборда
- `test_logs_page_structure()` - структура страницы логов
- `test_html_validation()` - валидность HTML
- `test_css_styles_presence()` - наличие CSS стилей
- `test_javascript_presence()` - наличие JavaScript

#### TestWebInterfaceInteractive

Интерактивные тесты (требуют Selenium):

- `test_dashboard_page_loads()` - загрузка в браузере
- `test_status_loading_functionality()` - загрузка статуса
- `test_refresh_button_functionality()` - кнопка обновления
- `test_sync_trigger_button_functionality()` - кнопка синхронизации
- `test_checkbox_interaction()` - взаимодействие с чекбоксами
- `test_responsive_behavior()` - адаптивное поведение

#### TestWebInterfaceAccessibility

Тесты доступности:

- `test_semantic_html_elements()` - семантические элементы
- `test_form_labels_and_accessibility()` - доступность форм
- `test_color_contrast_indicators()` - цветовой контраст
- `test_keyboard_navigation_support()` - навигация с клавиатуры

#### TestWebInterfacePerformance

Производительность веб-интерфейса:

- `test_page_load_time()` - время загрузки страниц
- `test_html_size_optimization()` - оптимизация размера HTML
- `test_css_optimization()` - оптимизация CSS

### Unit тесты (`test_inventory_sync_api.py`)

#### TestInventorySyncAPI

Unit тесты Flask приложения:

- `test_get_sync_status_success()` - успешное получение статуса
- `test_get_sync_reports_success()` - успешное получение отчетов
- `test_trigger_sync_success()` - успешный запуск синхронизации
- `test_sync_health_check_success()` - успешный health check
- `test_get_sync_logs_success()` - успешное получение логов

#### TestInventorySyncAPIClass

Тесты класса InventorySyncAPI:

- `test_get_sync_status_method()` - метод получения статуса
- `test_get_sync_reports_method()` - метод получения отчетов

## Конфигурация тестов

### Переменные окружения

```bash
# Для тестов с реальной БД (опционально)
export DB_HOST=localhost
export DB_USER=test_user
export DB_PASSWORD=test_password
export DB_NAME=test_inventory

# Для тестов API
export API_BASE_URL=http://localhost:5001
```

### Настройка тестовой БД

Тесты автоматически создают временную SQLite БД для изоляции.

### Selenium настройка

Для интерактивных тестов веб-интерфейса:

```bash
# Установка ChromeDriver
# Ubuntu/Debian:
sudo apt-get install chromium-chromedriver

# macOS:
brew install chromedriver

# Или скачать вручную с:
# https://chromedriver.chromium.org/
```

## Интерпретация результатов

### Успешный запуск

```
🧪 КОМПЛЕКСНОЕ ТЕСТИРОВАНИЕ API УПРАВЛЕНИЯ СИНХРОНИЗАЦИЕЙ ОСТАТКОВ
================================================================================
✅ UNIT: 12 тестов, 0 неудач, 0 ошибок
✅ INTEGRATION: 15 тестов, 0 неудач, 0 ошибок
✅ SECURITY: 25 тестов, 0 неудач, 0 ошибок
✅ WEB_INTERFACE: 18 тестов, 0 неудач, 0 ошибок

📈 ОБЩАЯ СТАТИСТИКА:
   Всего тестов: 70
   Успешных: 70
   Неудачных: 0
   Ошибок: 0

🎉 ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО!
```

### Обнаружение проблем

```
❌ SECURITY: 25 тестов, 2 неудачи, 0 ошибок
⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ В ТЕСТАХ

❌ Проблемы безопасности:
  - test_sql_injection_attacks
  - test_xss_attacks
```

## Непрерывная интеграция

### GitHub Actions

```yaml
name: API Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Set up Python
        uses: actions/setup-python@v2
        with:
          python-version: 3.9
      - name: Install dependencies
        run: |
          pip install -r requirements.txt
          sudo apt-get install chromium-chromedriver
      - name: Run tests
        run: python run_inventory_sync_api_tests.py
```

### Jenkins

```groovy
pipeline {
    agent any
    stages {
        stage('Test') {
            steps {
                sh 'python run_inventory_sync_api_tests.py'
            }
        }
    }
    post {
        always {
            publishTestResults testResultsPattern: 'test-results.xml'
        }
    }
}
```

## Отладка тестов

### Логирование

```python
import logging
logging.basicConfig(level=logging.DEBUG)
```

### Запуск отдельного теста

```bash
python -m unittest test_inventory_sync_api.TestInventorySyncAPI.test_get_sync_status_success
```

### Пропуск Selenium тестов

Если ChromeDriver недоступен, Selenium тесты автоматически пропускаются.

## Расширение тестов

### Добавление нового теста

```python
def test_new_functionality(self):
    """Тест новой функциональности."""
    # Arrange
    test_data = {"key": "value"}

    # Act
    response = requests.post(f"{self.base_url}/api/new-endpoint", json=test_data)

    # Assert
    self.assertEqual(response.status_code, 200)
    data = response.json()
    self.assertTrue(data['success'])
```

### Добавление нового тестового класса

```python
class TestNewFeature(unittest.TestCase):
    """Тесты новой функциональности."""

    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"

    def test_feature_works(self):
        """Тест работы функции."""
        pass
```

## Лучшие практики

### Изоляция тестов

- Каждый тест должен быть независимым
- Используйте setUp/tearDown для подготовки данных
- Не полагайтесь на порядок выполнения тестов

### Мокирование

```python
from unittest.mock import patch, MagicMock

@patch('module.external_service')
def test_with_mock(self, mock_service):
    mock_service.return_value = "mocked_result"
    # тест код
```

### Параметризованные тесты

```python
import parameterized

@parameterized.expand([
    ("input1", "expected1"),
    ("input2", "expected2"),
])
def test_multiple_cases(self, input_val, expected):
    result = function_under_test(input_val)
    self.assertEqual(result, expected)
```

## Поддержка и развитие

### Обновление тестов

При изменении API:

1. Обновите соответствующие тесты
2. Добавьте тесты для новой функциональности
3. Убедитесь, что все тесты проходят
4. Обновите документацию

### Отчеты о проблемах

При обнаружении проблем в тестах:

1. Проверьте логи тестов
2. Убедитесь, что API сервер запущен
3. Проверьте зависимости (ChromeDriver для Selenium)
4. Создайте issue с подробным описанием

### Метрики покрытия

```bash
# Установка coverage
pip install coverage

# Запуск с измерением покрытия
coverage run run_inventory_sync_api_tests.py
coverage report
coverage html
```

## Заключение

Система тестирования обеспечивает комплексную проверку API управления синхронизацией остатков, включая функциональность, безопасность, производительность и пользовательский интерфейс. Регулярный запуск тестов помогает поддерживать высокое качество кода и быстро выявлять проблемы.
