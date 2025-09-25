# Руководство по интеграции фильтра по стране изготовления

## Обзор

Данное руководство описывает интеграцию компонента `CountryFilter` в существующую систему фильтрации товаров ZUZ проставок. Компонент обеспечивает фильтрацию по стране изготовления автомобиля с поддержкой динамической загрузки данных и кэширования.

## Структура файлов

```
js/
└── CountryFilter.js          # Основной класс фильтра

css/
└── country-filter.css        # Стили для фильтра

templates/
└── country-filter-integration.html  # Пример интеграции

docs/
└── CountryFilter-Integration-Guide.md  # Данное руководство
```

## Быстрый старт

### 1. Подключение файлов

```html
<!-- Подключение стилей -->
<link href="css/country-filter.css" rel="stylesheet" />

<!-- Подключение скрипта -->
<script src="js/CountryFilter.js"></script>
```

### 2. HTML разметка

```html
<!-- Контейнер для фильтра по стране -->
<div id="country-filter-container"></div>
```

### 3. Инициализация

```javascript
// Создание экземпляра фильтра
const countryFilter = new CountryFilter("country-filter-container", function (
  countryId
) {
  console.log("Выбрана страна:", countryId);
  // Здесь обрабатываем изменение фильтра
});
```

## Подробное описание API

### Конструктор

```javascript
new CountryFilter(containerId, onChangeCallback);
```

**Параметры:**

- `containerId` (string) - ID HTML элемента для размещения фильтра
- `onChangeCallback` (function) - Функция обратного вызова при изменении выбора

### Основные методы

#### loadCountries()

Загружает все доступные страны изготовления.

```javascript
await countryFilter.loadCountries();
```

#### loadCountriesForBrand(brandId)

Загружает страны для конкретной марки автомобиля.

```javascript
await countryFilter.loadCountriesForBrand(1); // BMW
```

#### loadCountriesForModel(modelId)

Загружает страны для конкретной модели автомобиля.

```javascript
await countryFilter.loadCountriesForModel(5); // BMW X5
```

#### getSelectedCountry()

Возвращает ID выбранной страны.

```javascript
const countryId = countryFilter.getSelectedCountry();
```

#### setSelectedCountry(countryId)

Устанавливает выбранную страну программно.

```javascript
countryFilter.setSelectedCountry(1); // Германия
```

#### reset()

Сбрасывает выбор страны.

```javascript
countryFilter.reset();
```

#### getSelectedCountryInfo()

Возвращает полную информацию о выбранной стране.

```javascript
const countryInfo = countryFilter.getSelectedCountryInfo();
// Результат: { id: 1, name: "Германия" }
```

#### hasSelection()

Проверяет, выбрана ли страна.

```javascript
if (countryFilter.hasSelection()) {
  console.log("Страна выбрана");
}
```

#### clearCache()

Очищает кэш загруженных данных.

```javascript
countryFilter.clearCache();
```

#### destroy()

Уничтожает компонент и освобождает ресурсы.

```javascript
countryFilter.destroy();
```

## Интеграция с существующими фильтрами

### Пример полной интеграции

```javascript
class FilterManager {
  constructor() {
    this.filters = {
      brand_id: null,
      model_id: null,
      year: null,
      country_id: null,
    };

    this.countryFilter = null;
    this.initializeFilters();
  }

  initializeFilters() {
    // Инициализация фильтра по стране
    this.countryFilter = new CountryFilter(
      "country-filter-container",
      (countryId) => {
        this.filters.country_id = countryId;
        this.applyFilters();
      }
    );

    // Обработчики других фильтров
    document.getElementById("brand-select").addEventListener("change", (e) => {
      this.onBrandChange(e.target.value);
    });

    document.getElementById("model-select").addEventListener("change", (e) => {
      this.onModelChange(e.target.value);
    });
  }

  onBrandChange(brandId) {
    this.filters.brand_id = brandId;
    this.filters.model_id = null; // Сбрасываем модель

    // Обновляем доступные страны
    if (brandId) {
      this.countryFilter.loadCountriesForBrand(brandId);
    } else {
      this.countryFilter.loadCountries();
    }

    this.applyFilters();
  }

  onModelChange(modelId) {
    this.filters.model_id = modelId;

    // Обновляем доступные страны для модели
    if (modelId) {
      this.countryFilter.loadCountriesForModel(modelId);
    } else if (this.filters.brand_id) {
      this.countryFilter.loadCountriesForBrand(this.filters.brand_id);
    } else {
      this.countryFilter.loadCountries();
    }

    this.applyFilters();
  }

  async applyFilters() {
    try {
      const response = await fetch(
        "/api/products-filter.php?" + new URLSearchParams(this.filters)
      );
      const result = await response.json();

      if (result.success) {
        this.displayResults(result.data);
      }
    } catch (error) {
      console.error("Ошибка применения фильтров:", error);
    }
  }

  resetAllFilters() {
    this.filters = {
      brand_id: null,
      model_id: null,
      year: null,
      country_id: null,
    };

    // Сбрасываем все UI элементы
    document.getElementById("brand-select").value = "";
    document.getElementById("model-select").value = "";
    document.getElementById("year-select").value = "";

    this.countryFilter.reset();
    this.countryFilter.loadCountries();

    this.applyFilters();
  }
}

// Инициализация
const filterManager = new FilterManager();
```

## Стилизация

### Базовые стили

Компонент использует CSS классы для стилизации:

- `.country-filter` - основной контейнер
- `.filter-group` - группа фильтра
- `.filter-label` - лейбл фильтра
- `.filter-select` - select элемент
- `.filter-loading` - индикатор загрузки
- `.filter-error` - сообщение об ошибке

### Кастомизация стилей

```css
/* Изменение цветовой схемы */
.country-filter .filter-select:focus {
  border-color: #your-brand-color;
  box-shadow: 0 0 0 0.2rem rgba(your-brand-color-rgb, 0.25);
}

/* Компактный вид */
.country-filter--compact .filter-select {
  padding: 0.4rem 0.6rem;
  font-size: 0.8rem;
}
```

### Адаптивность

Стили автоматически адаптируются для мобильных устройств:

```css
@media (max-width: 768px) {
  .filter-select {
    padding: 0.6rem 0.8rem;
    font-size: 0.85rem;
  }
}
```

## Обработка ошибок

### Типы ошибок

1. **Ошибка загрузки данных** - проблемы с API
2. **Ошибка сети** - отсутствие подключения
3. **Ошибка инициализации** - неверные параметры

### Обработка ошибок

```javascript
const countryFilter = new CountryFilter(
  "country-filter-container",
  (countryId) => {
    // Обработка изменения
  },
  {
    onError: (error) => {
      console.error("Ошибка фильтра:", error);
      // Показать уведомление пользователю
    },
  }
);
```

## Производительность

### Кэширование

Компонент автоматически кэширует результаты API запросов на 5 минут. Для очистки кэша:

```javascript
countryFilter.clearCache();
```

### Оптимизация

1. **Ленивая загрузка** - данные загружаются только при необходимости
2. **Дебаунсинг** - предотвращение частых запросов
3. **Кэширование** - сохранение результатов в памяти

## Тестирование

### Unit тесты

```javascript
// Пример теста
describe("CountryFilter", () => {
  let container, countryFilter;

  beforeEach(() => {
    container = document.createElement("div");
    container.id = "test-container";
    document.body.appendChild(container);

    countryFilter = new CountryFilter("test-container", jest.fn());
  });

  afterEach(() => {
    countryFilter.destroy();
    document.body.removeChild(container);
  });

  test("should initialize correctly", () => {
    expect(countryFilter.container).toBeTruthy();
    expect(countryFilter.selectElement).toBeTruthy();
  });

  test("should load countries", async () => {
    const countries = await countryFilter.loadCountries();
    expect(Array.isArray(countries)).toBe(true);
  });
});
```

### Интеграционные тесты

```javascript
// Тест интеграции с API
test("should integrate with API correctly", async () => {
  const mockFetch = jest.fn().mockResolvedValue({
    json: () =>
      Promise.resolve({
        success: true,
        data: [{ id: 1, name: "Германия" }],
      }),
  });

  global.fetch = mockFetch;

  const countries = await countryFilter.loadCountries();

  expect(mockFetch).toHaveBeenCalledWith("/api/countries.php");
  expect(countries).toEqual([{ id: 1, name: "Германия" }]);
});
```

## Accessibility (Доступность)

### ARIA атрибуты

Компонент автоматически добавляет необходимые ARIA атрибуты:

```html
<select
  id="country-select"
  class="filter-select"
  aria-label="Выберите страну изготовления автомобиля"
  aria-describedby="country-filter-help"
></select>
```

### Клавиатурная навигация

- `Tab` - переход к фильтру
- `Space/Enter` - открытие списка
- `Arrow keys` - навигация по опциям
- `Escape` - закрытие списка

## Совместимость

### Браузеры

- Chrome 60+
- Firefox 55+
- Safari 12+
- Edge 79+
- IE 11 (с полифиллами)

### Полифиллы

Для поддержки старых браузеров подключите:

```html
<script src="https://polyfill.io/v3/polyfill.min.js?features=fetch,Promise"></script>
```

## Миграция с существующих решений

### Замена простого select

```javascript
// Было
const select = document.getElementById("country-select");
select.addEventListener("change", handleCountryChange);

// Стало
const countryFilter = new CountryFilter(
  "country-container",
  handleCountryChange
);
```

### Интеграция с jQuery

```javascript
// Обертка для jQuery
$.fn.countryFilter = function (options) {
  return this.each(function () {
    const filter = new CountryFilter(this.id, options.onChange);
    $(this).data("countryFilter", filter);
  });
};

// Использование
$("#country-container").countryFilter({
  onChange: function (countryId) {
    console.log("Country changed:", countryId);
  },
});
```

## Примеры использования

### Базовый пример

```html
<!DOCTYPE html>
<html>
  <head>
    <link href="css/country-filter.css" rel="stylesheet" />
  </head>
  <body>
    <div id="country-filter"></div>

    <script src="js/CountryFilter.js"></script>
    <script>
      const filter = new CountryFilter("country-filter", function (countryId) {
        console.log("Selected country:", countryId);
      });
    </script>
  </body>
</html>
```

### Расширенный пример с валидацией

```javascript
class ValidatedCountryFilter extends CountryFilter {
  constructor(containerId, onChangeCallback, options = {}) {
    super(containerId, onChangeCallback);
    this.required = options.required || false;
    this.validationMessage = options.validationMessage || "Выберите страну";
  }

  validate() {
    if (this.required && !this.hasSelection()) {
      this.showError(this.validationMessage);
      return false;
    }

    this.hideError();
    return true;
  }

  triggerChange() {
    if (this.validate()) {
      super.triggerChange();
    }
  }
}

// Использование
const validatedFilter = new ValidatedCountryFilter(
  "country-filter",
  (countryId) => console.log("Valid country selected:", countryId),
  {
    required: true,
    validationMessage: "Пожалуйста, выберите страну изготовления",
  }
);
```

## Поддержка и обновления

### Версионирование

Компонент следует семантическому версионированию:

- `1.0.0` - стабильная версия
- `1.1.0` - новые функции
- `1.0.1` - исправления ошибок

### Обновления

Для обновления до новой версии:

1. Скачайте новые файлы
2. Обновите подключения в HTML
3. Проверьте changelog на breaking changes
4. Протестируйте интеграцию

### Получение поддержки

- Документация: `/docs/`
- Примеры: `/templates/`
- Тесты: `/tests/`

## Заключение

Компонент `CountryFilter` обеспечивает полнофункциональную фильтрацию по стране изготовления автомобиля с поддержкой:

- ✅ Динамической загрузки данных
- ✅ Кэширования для производительности
- ✅ Адаптивного дизайна
- ✅ Обработки ошибок
- ✅ Accessibility
- ✅ Интеграции с существующими системами

Компонент готов к использованию в продакшене и соответствует всем требованиям спецификации.
