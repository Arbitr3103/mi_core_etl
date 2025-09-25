# Руководство по интеграции FilterManager

## Обзор

FilterManager - это комплексное решение для управления фильтрами товаров с поддержкой фильтра по стране изготовления автомобиля. Он обеспечивает интеграцию с существующими фильтрами (марка, модель, год) и автоматическую загрузку доступных стран при изменении других фильтров.

## Основные возможности

- ✅ Интеграция фильтра по стране с существующими фильтрами
- ✅ Автоматическая загрузка доступных стран при выборе марки/модели
- ✅ Обработка событий изменения всех фильтров
- ✅ Кэширование результатов для оптимизации производительности
- ✅ Предотвращение циклических обновлений
- ✅ Обработка ошибок и graceful degradation
- ✅ Полная интеграция с существующими HTML элементами

## Быстрый старт

### 1. Подключение скриптов

```html
<!-- Подключаем необходимые скрипты в правильном порядке -->
<script src="js/CountryFilter.js"></script>
<script src="js/FilterManager.js"></script>
<script src="js/FilterManagerIntegration.js"></script>
```

### 2. HTML структура

```html
<!-- Существующие фильтры -->
<select id="brand-select">
  <option value="">Выберите марку</option>
  <!-- опции марок -->
</select>

<select id="model-select">
  <option value="">Выберите модель</option>
  <!-- опции моделей -->
</select>

<select id="year-select">
  <option value="">Любой год</option>
  <!-- опции годов -->
</select>

<!-- Контейнер для фильтра по стране -->
<div id="country-filter-container"></div>

<!-- Кнопка сброса -->
<button id="reset-filters">Сбросить фильтры</button>

<!-- Контейнер для результатов -->
<div id="products-list"></div>
```

### 3. Автоматическая инициализация

FilterManagerIntegration автоматически инициализируется при загрузке страницы:

```javascript
// Автоматическая инициализация с настройками по умолчанию
document.addEventListener("DOMContentLoaded", function () {
  filterIntegration = new FilterManagerIntegration();
  window.filterIntegration = filterIntegration;
});
```

## Подробное руководство

### Создание FilterManager

```javascript
// Создание с кастомными настройками
const filterManager = new FilterManager({
  apiBaseUrl: "/api",
  onFiltersChange: (result, filters) => {
    console.log("Фильтры изменились:", filters);
    console.log("Результаты:", result);
    updateUI(result);
  },
});

// Инициализация фильтра по стране
filterManager.initCountryFilter("country-filter-container");
```

### Обработка событий фильтров

```javascript
// Обработчики для существующих фильтров
document.getElementById("brand-select").addEventListener("change", (e) => {
  filterManager.onBrandChange(e.target.value || null);
});

document.getElementById("model-select").addEventListener("change", (e) => {
  filterManager.onModelChange(e.target.value || null);
});

document.getElementById("year-select").addEventListener("change", (e) => {
  filterManager.onYearChange(e.target.value || null);
});

// Сброс всех фильтров
document.getElementById("reset-filters").addEventListener("click", () => {
  filterManager.resetAllFilters();
});
```

### Кастомная интеграция

```javascript
class CustomFilterIntegration extends FilterManagerIntegration {
  constructor(options) {
    super(options);
  }

  // Переопределяем обработчик изменения фильтров
  onFiltersChangeCustom(result, filters) {
    // Кастомная логика обработки
    this.updateCustomUI(result, filters);
    this.trackAnalytics(filters);
  }

  updateCustomUI(result, filters) {
    // Обновление пользовательского интерфейса
    const activeFiltersCount = Object.keys(this.getActiveFilters()).length;
    document.getElementById("filters-count").textContent = activeFiltersCount;
  }

  trackAnalytics(filters) {
    // Отправка аналитики
    if (typeof gtag !== "undefined") {
      gtag("event", "filter_change", {
        custom_parameter: JSON.stringify(filters),
      });
    }
  }
}

// Использование кастомной интеграции
const customIntegration = new CustomFilterIntegration({
  apiBaseUrl: "/api",
  countryContainerId: "my-country-filter",
});
```

## API Reference

### FilterManager

#### Конструктор

```javascript
new FilterManager(options);
```

**Параметры:**

- `options.apiBaseUrl` (string) - Базовый URL для API запросов
- `options.onFiltersChange` (function) - Callback для изменения фильтров

#### Методы

##### initCountryFilter(containerId)

Инициализирует фильтр по стране в указанном контейнере.

```javascript
filterManager.initCountryFilter("country-filter-container");
```

##### onBrandChange(brandId)

Обрабатывает изменение марки автомобиля.

```javascript
await filterManager.onBrandChange("1"); // BMW
await filterManager.onBrandChange(null); // сброс
```

##### onModelChange(modelId)

Обрабатывает изменение модели автомобиля.

```javascript
await filterManager.onModelChange("1"); // X5
await filterManager.onModelChange(null); // сброс
```

##### onYearChange(year)

Обрабатывает изменение года выпуска.

```javascript
filterManager.onYearChange("2023");
filterManager.onYearChange(null); // сброс
```

##### onCountryChange(countryId)

Обрабатывает изменение страны изготовления.

```javascript
filterManager.onCountryChange("1"); // Германия
filterManager.onCountryChange(null); // сброс
```

##### getCurrentFilters()

Возвращает текущие значения всех фильтров.

```javascript
const filters = filterManager.getCurrentFilters();
// { brand_id: '1', model_id: null, year: '2023', country_id: '1' }
```

##### getActiveFilters()

Возвращает только активные фильтры (с непустыми значениями).

```javascript
const activeFilters = filterManager.getActiveFilters();
// { brand_id: '1', year: '2023', country_id: '1' }
```

##### hasActiveFilters()

Проверяет наличие активных фильтров.

```javascript
const hasFilters = filterManager.hasActiveFilters(); // true/false
```

##### resetAllFilters()

Сбрасывает все фильтры.

```javascript
await filterManager.resetAllFilters();
```

##### setFilter(filterName, value)

Устанавливает значение конкретного фильтра.

```javascript
filterManager.setFilter("brand_id", "1");
filterManager.setFilter("country_id", "2");
```

##### getFilter(filterName)

Получает значение конкретного фильтра.

```javascript
const brandId = filterManager.getFilter("brand_id");
```

### FilterManagerIntegration

#### Конструктор

```javascript
new FilterManagerIntegration(options);
```

**Параметры:**

- `options.brandSelectId` (string) - ID элемента выбора марки (по умолчанию: 'brand-select')
- `options.modelSelectId` (string) - ID элемента выбора модели (по умолчанию: 'model-select')
- `options.yearSelectId` (string) - ID элемента выбора года (по умолчанию: 'year-select')
- `options.countryContainerId` (string) - ID контейнера для фильтра по стране (по умолчанию: 'country-filter-container')
- `options.resetButtonId` (string) - ID кнопки сброса (по умолчанию: 'reset-filters')
- `options.resultsContainerId` (string) - ID контейнера результатов (по умолчанию: 'products-list')
- `options.apiBaseUrl` (string) - Базовый URL для API (по умолчанию: '/api')

#### Методы

##### getCurrentFilters()

Возвращает текущие фильтры.

##### getActiveFilters()

Возвращает активные фильтры.

##### hasActiveFilters()

Проверяет наличие активных фильтров.

##### setFilter(filterName, value)

Устанавливает значение фильтра программно.

##### resetAllFilters()

Сбрасывает все фильтры.

##### getFilterManager()

Возвращает экземпляр FilterManager.

##### isReady()

Проверяет готовность интеграции.

## Примеры использования

### Базовая интеграция

```html
<!DOCTYPE html>
<html>
  <head>
    <title>Фильтры товаров</title>
  </head>
  <body>
    <!-- Фильтры -->
    <select id="brand-select">
      <option value="">Выберите марку</option>
      <option value="1">BMW</option>
      <option value="2">Mercedes-Benz</option>
    </select>

    <select id="model-select">
      <option value="">Выберите модель</option>
    </select>

    <select id="year-select">
      <option value="">Любой год</option>
      <option value="2023">2023</option>
      <option value="2022">2022</option>
    </select>

    <div id="country-filter-container"></div>

    <button id="reset-filters">Сбросить</button>

    <!-- Результаты -->
    <div id="products-list"></div>

    <!-- Скрипты -->
    <script src="js/CountryFilter.js"></script>
    <script src="js/FilterManager.js"></script>
    <script src="js/FilterManagerIntegration.js"></script>
  </body>
</html>
```

### Программное управление фильтрами

```javascript
// Получаем экземпляр интеграции
const integration = window.filterIntegration;

// Устанавливаем фильтры программно
integration.setFilter("brand_id", "1");
integration.setFilter("year", "2023");

// Получаем текущие фильтры
const currentFilters = integration.getCurrentFilters();
console.log("Текущие фильтры:", currentFilters);

// Проверяем активные фильтры
if (integration.hasActiveFilters()) {
  console.log("Активные фильтры:", integration.getActiveFilters());
}

// Сбрасываем все фильтры
integration.resetAllFilters();
```

### Обработка событий

```javascript
// Кастомный обработчик изменения фильтров
class MyFilterHandler extends FilterManagerIntegration {
  onFiltersChangeCustom(result, filters) {
    // Обновляем URL с параметрами фильтров
    this.updateURL(filters);

    // Показываем/скрываем элементы интерфейса
    this.toggleUIElements(result);

    // Отправляем аналитику
    this.trackFilterUsage(filters);
  }

  updateURL(filters) {
    const params = new URLSearchParams();
    Object.keys(filters).forEach((key) => {
      if (filters[key]) {
        params.set(key, filters[key]);
      }
    });

    const newURL = `${window.location.pathname}?${params.toString()}`;
    window.history.replaceState({}, "", newURL);
  }

  toggleUIElements(result) {
    const noResultsElement = document.getElementById("no-results");
    const resultsElement = document.getElementById("products-list");

    if (result.data.length === 0) {
      noResultsElement.style.display = "block";
      resultsElement.style.display = "none";
    } else {
      noResultsElement.style.display = "none";
      resultsElement.style.display = "block";
    }
  }

  trackFilterUsage(filters) {
    // Отправляем данные в аналитику
    if (typeof gtag !== "undefined") {
      gtag("event", "filter_applied", {
        filters_count: Object.keys(filters).length,
        has_country_filter: !!filters.country_id,
      });
    }
  }
}

// Используем кастомный обработчик
const myHandler = new MyFilterHandler();
```

## Тестирование

### Запуск тестов

```bash
# Установка зависимостей для тестирования
npm install --save-dev jest jsdom

# Запуск тестов
npm test

# Запуск тестов с покрытием
npm run test:coverage
```

### Пример теста

```javascript
describe("FilterManager Integration", () => {
  let filterManager;

  beforeEach(() => {
    document.body.innerHTML = '<div id="country-container"></div>';
    filterManager = new FilterManager();
    filterManager.initCountryFilter("country-container");
  });

  test("должен автоматически загружать страны при изменении марки", async () => {
    await filterManager.onBrandChange("1");

    expect(filterManager.filters.brand_id).toBe("1");
    expect(filterManager.countryFilter.countries.length).toBeGreaterThan(0);
  });
});
```

## Устранение неполадок

### Частые проблемы

#### 1. Фильтр по стране не отображается

**Проблема:** Контейнер для фильтра не найден.

**Решение:**

```javascript
// Убедитесь, что элемент существует
const container = document.getElementById("country-filter-container");
if (!container) {
  console.error("Контейнер для фильтра по стране не найден");
}
```

#### 2. Страны не загружаются автоматически

**Проблема:** API endpoints недоступны или возвращают ошибки.

**Решение:**

```javascript
// Проверьте доступность API
fetch("/api/countries.php")
  .then((response) => response.json())
  .then((data) => console.log("API работает:", data))
  .catch((error) => console.error("Ошибка API:", error));
```

#### 3. Циклические обновления

**Проблема:** Фильтры обновляются бесконечно.

**Решение:** FilterManager автоматически предотвращает циклические обновления с помощью флага `isUpdating`.

#### 4. Callback не вызывается

**Проблема:** Обработчик изменения фильтров не срабатывает.

**Решение:**

```javascript
// Убедитесь, что callback установлен правильно
filterManager.onFiltersChange((result, filters) => {
  console.log("Callback вызван:", filters);
});
```

### Отладка

```javascript
// Включение режима отладки
window.DEBUG_FILTERS = true;

// Проверка состояния FilterManager
console.log("Текущие фильтры:", filterManager.getCurrentFilters());
console.log("Активные фильтры:", filterManager.getActiveFilters());
console.log("Доступные страны:", filterManager.getAvailableCountries());

// Проверка состояния интеграции
console.log("Интеграция готова:", filterIntegration.isReady());
console.log("FilterManager:", filterIntegration.getFilterManager());
```

## Производительность

### Оптимизация

1. **Кэширование:** Результаты API запросов кэшируются на 5 минут
2. **Дебаунсинг:** Предотвращение частых запросов при быстрых изменениях
3. **Ленивая загрузка:** Страны загружаются только при необходимости

### Мониторинг

```javascript
// Отслеживание производительности
const startTime = performance.now();

filterManager.onBrandChange("1").then(() => {
  const endTime = performance.now();
  console.log(`Время загрузки стран: ${endTime - startTime}ms`);
});
```

## Заключение

FilterManager обеспечивает полную интеграцию фильтра по стране с существующими фильтрами, автоматическую загрузку доступных стран и надежную обработку всех событий изменения фильтров. Система спроектирована для простоты использования и максимальной совместимости с существующим кодом.
