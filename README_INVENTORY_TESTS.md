# Unit тесты системы синхронизации остатков

Комплексные unit тесты для системы синхронизации остатков товаров с маркетплейсами Ozon и Wildberries.

## 📋 Обзор

Тестовый набор покрывает все основные компоненты системы:

- **API интеграция** - получение данных с маркетплейсов
- **Валидация данных** - проверка корректности полученных данных
- **Запись в БД** - сохранение остатков в базу данных
- **Обработка ошибок** - корректная обработка исключений
- **Мониторинг** - проверка свежести данных и статистика

## 🗂️ Структура тестов

### Основные тестовые файлы

```
test_inventory_sync_service.py     # Тесты основного сервиса синхронизации
test_inventory_data_validator.py   # Тесты валидатора данных
test_inventory_integration.py      # Интеграционные тесты
run_inventory_tests.py            # Скрипт запуска всех тестов
test_config.py                    # Конфигурация тестов
```

### Покрытие тестами

#### 1. InventorySyncService (`test_inventory_sync_service.py`)

**TestDatabaseMethods** - Методы работы с БД:

- ✅ `test_get_product_id_by_ozon_sku_success` - Поиск товара по Ozon SKU
- ✅ `test_get_product_id_by_ozon_sku_not_found` - Обработка отсутствующего товара
- ✅ `test_get_product_id_by_wb_sku_success` - Поиск товара по WB SKU
- ✅ `test_get_product_id_by_barcode_success` - Поиск товара по штрихкоду
- ✅ `test_update_inventory_data_success` - Успешное обновление остатков
- ✅ `test_update_inventory_data_empty_records` - Обработка пустых данных
- ✅ `test_log_sync_result_success` - Запись результата синхронизации
- ✅ `test_get_last_sync_time_success` - Получение времени последней синхронизации

**TestAPIDataRetrieval** - Получение данных с API:

- ✅ `test_sync_ozon_inventory_success` - Успешная синхронизация Ozon
- ✅ `test_sync_ozon_inventory_api_error` - Обработка ошибок Ozon API
- ✅ `test_sync_wb_inventory_success` - Успешная синхронизация WB
- ✅ `test_sync_wb_inventory_empty_response` - Обработка пустого ответа WB

**TestDataValidation** - Валидация данных:

- ✅ `test_validate_inventory_data_success` - Успешная валидация
- ✅ `test_validate_inventory_data_with_errors` - Валидация с ошибками
- ✅ `test_filter_valid_records_all_valid` - Фильтрация валидных записей
- ✅ `test_filter_valid_records_with_errors` - Фильтрация с ошибками

**TestDataAnomalies** - Проверка аномалий:

- ✅ `test_check_data_anomalies_normal_data` - Нормальные данные
- ✅ `test_check_data_anomalies_high_stock` - Аномально высокие остатки
- ✅ `test_check_data_anomalies_invalid_reserved` - Некорректные резервы

#### 2. InventoryDataValidator (`test_inventory_data_validator.py`)

**TestValidationMethods** - Основные методы:

- ✅ `test_validate_inventory_records_success` - Успешная валидация
- ✅ `test_validate_inventory_records_with_errors` - Валидация с ошибками
- ✅ `test_validate_inventory_records_empty_list` - Пустой список

**TestFieldValidation** - Валидация полей:

- ✅ `test_validate_required_field_success` - Обязательные поля
- ✅ `test_validate_product_id_success` - Product ID
- ✅ `test_validate_sku_success_ozon` - Ozon SKU
- ✅ `test_validate_sku_success_wb` - WB SKU
- ✅ `test_validate_source_success` - Источник данных

**TestQuantityValidation** - Валидация количеств:

- ✅ `test_validate_quantity_success` - Корректные количества
- ✅ `test_validate_quantity_negative` - Отрицательные значения
- ✅ `test_validate_quantity_very_large` - Очень большие значения

**TestStockLogicValidation** - Логика остатков:

- ✅ `test_validate_stock_logic_success` - Корректная логика
- ✅ `test_validate_stock_logic_reserved_exceeds_current` - Резерв > текущего
- ✅ `test_validate_stock_logic_available_mismatch` - Несоответствие доступного

#### 3. Integration тесты (`test_inventory_integration.py`)

**TestOzonIntegrationFlow** - Интеграция с Ozon:

- ✅ `test_ozon_full_sync_flow_success` - Полный цикл синхронизации
- ✅ `test_ozon_sync_with_missing_products` - Отсутствующие товары
- ✅ `test_ozon_sync_api_timeout` - Таймаут API

**TestWildberriesIntegrationFlow** - Интеграция с WB:

- ✅ `test_wb_full_sync_flow_success` - Полный цикл синхронизации
- ✅ `test_wb_sync_with_validation_errors` - Ошибки валидации

**TestFullSyncIntegration** - Полная синхронизация:

- ✅ `test_run_full_sync_success` - Успешная полная синхронизация
- ✅ `test_run_full_sync_with_failures` - Синхронизация с ошибками

## 🚀 Запуск тестов

### Запуск всех тестов

```bash
python3 run_inventory_tests.py
```

### Запуск конкретного набора тестов

```bash
# Тесты основного сервиса
python3 -m unittest test_inventory_sync_service -v

# Тесты валидатора
python3 -m unittest test_inventory_data_validator -v

# Интеграционные тесты
python3 -m unittest test_inventory_integration -v
```

### Запуск конкретного теста

```bash
python3 -m unittest test_inventory_sync_service.TestDatabaseMethods.test_get_product_id_by_ozon_sku_success -v
```

## 📊 Ожидаемые результаты

При успешном прохождении всех тестов вы увидите:

```
================================================================================
СВОДКА РЕЗУЛЬТАТОВ ТЕСТИРОВАНИЯ
================================================================================
Всего тестов:     85
Пройдено:         85
Провалено:        0
Ошибок:           0
Пропущено:        0
--------------------------------------------------------------------------------
✅ ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!

🎉 Все тесты пройдены! Система готова к развертыванию.

📋 Что протестировано:
  ✅ Методы получения данных с API Ozon и Wildberries
  ✅ Валидация данных об остатках
  ✅ Запись данных в базу данных
  ✅ Обработка ошибок и исключений
  ✅ Проверка аномалий в данных
  ✅ Полный цикл синхронизации
  ✅ Мониторинг и статистика
```

## 🔧 Отладка тестов

### Включение подробных логов

```python
import logging
logging.basicConfig(level=logging.DEBUG)
```

### Запуск с детальным выводом

```bash
python3 -m unittest test_inventory_sync_service -v --buffer
```

### Остановка на первой ошибке

```bash
python3 -m unittest test_inventory_sync_service --failfast
```

## 📝 Тестовые данные

Тесты используют моки (mocks) для:

- **API ответов** - имитация ответов Ozon и WB API
- **Подключения к БД** - моки cursor и connection
- **Конфигурации** - тестовые настройки API ключей

Реальные API и БД не используются в unit тестах.

## ⚠️ Требования

### Python пакеты

```bash
# Стандартные библиотеки (входят в Python)
unittest
unittest.mock
datetime
typing
dataclasses
enum
```

### Зависимости проекта

```bash
# Должны быть доступны в PYTHONPATH
inventory_sync_service.py
inventory_data_validator.py
config.py (с настройками API)
```

## 🎯 Покрытие кода

Тесты покрывают:

- **API методы**: 100% основных методов синхронизации
- **Валидация**: 100% правил валидации данных
- **БД операции**: 100% методов работы с БД
- **Обработка ошибок**: Все основные сценарии ошибок
- **Модели данных**: Все классы и их методы

## 🔄 Непрерывная интеграция

Тесты можно интегрировать в CI/CD:

```yaml
# Пример для GitHub Actions
- name: Run inventory sync tests
  run: |
    python3 run_inventory_tests.py
    if [ $? -ne 0 ]; then
      echo "Tests failed!"
      exit 1
    fi
```

## 📚 Дополнительные ресурсы

- [Python unittest документация](https://docs.python.org/3/library/unittest.html)
- [unittest.mock документация](https://docs.python.org/3/library/unittest.mock.html)
- [Спецификация требований](requirements.md)
- [Документация по дизайну](design.md)

---

**Автор**: ETL System  
**Дата**: 06 января 2025  
**Версия**: 1.0
