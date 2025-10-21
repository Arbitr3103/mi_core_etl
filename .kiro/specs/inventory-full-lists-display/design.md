# Дизайн отображения полных списков товаров в дашборде

## Обзор

Улучшение дашборда складских остатков для отображения полных списков товаров во всех категориях с компактным и удобным интерфейсом. Система должна показывать все товары в каждой категории с возможностью переключения между полным и сокращенным видом.

## Архитектура

### Компоненты системы

1. **Контроллер отображения списков** (JavaScript)

   - Управление переключением между режимами отображения
   - Динамическое изменение стилей и количества элементов
   - Сохранение пользовательских предпочтений

2. **API для получения полных списков** (PHP)

   - Модификация существующего API для возврата всех товаров
   - Добавление параметра limit для контроля количества
   - Оптимизация запросов для больших списков

3. **Компоненты UI** (HTML/CSS)
   - Компактные карточки товаров
   - Переключатели режимов отображения
   - Индикаторы количества и прогресса

## Компоненты и интерфейсы

### API Endpoints

#### GET /api/inventory-analytics.php?action=dashboard&limit=all

Возвращает полные списки товаров:

```json
{
  "status": "success",
  "data": {
    "critical_products": {
      "count": 32,
      "items": [
        {
          "sku": "BRG-6205-2RS",
          "product_name": "Подшипник 6205-2RS",
          "current_stock": 3,
          "warehouse_name": "Основной склад",
          "last_updated": "2025-01-13 10:30:00"
        }
      ]
    },
    "low_stock_products": {
      "count": 53,
      "items": [...]
    },
    "overstock_products": {
      "count": 28,
      "items": [...]
    }
  }
}
```

#### GET /api/inventory-analytics.php?action=products&category=critical&limit=10

Возвращает ограниченный список для режима "топ-10"

### Модели данных

#### Конфигурация отображения

```javascript
const displayConfig = {
  modes: {
    compact: {
      fontSize: "12px",
      rowHeight: "32px",
      showAll: true,
      maxHeight: "400px",
    },
    normal: {
      fontSize: "14px",
      rowHeight: "48px",
      showAll: false,
      limit: 10,
    },
  },
  categories: {
    critical: { count: 32, color: "#e74c3c" },
    low_stock: { count: 53, color: "#f39c12" },
    overstock: { count: 28, color: "#3498db" },
  },
};
```

## Пользовательский интерфейс

### Структура компонентов

#### 1. Заголовок секции с переключателем

```html
<div class="inventory-section">
  <div class="section-header">
    <h3>Критические товары</h3>
    <div class="display-controls">
      <span class="item-counter">Показано 32 из 32 товаров</span>
      <div class="toggle-switch">
        <button class="toggle-btn active" data-mode="all">Показать все</button>
        <button class="toggle-btn" data-mode="top10">Топ-10</button>
      </div>
    </div>
  </div>
</div>
```

#### 2. Компактный список товаров

```html
<div class="products-list compact-mode" data-category="critical">
  <div class="products-container">
    <div class="product-item">
      <div class="product-sku">BRG-6205-2RS</div>
      <div class="product-name">Подшипник 6205-2RS</div>
      <div class="product-stock critical">3 шт</div>
      <div class="product-warehouse">Основной склад</div>
    </div>
    <!-- Повторить для всех товаров -->
  </div>
</div>
```

### CSS стили

#### Компактный режим отображения

```css
.products-list.compact-mode {
  max-height: 400px;
  overflow-y: auto;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.products-list.compact-mode .product-item {
  display: grid;
  grid-template-columns: 120px 1fr 80px 120px;
  gap: 8px;
  padding: 6px 12px;
  font-size: 12px;
  line-height: 1.3;
  border-bottom: 1px solid #f0f0f0;
  transition: background-color 0.2s;
}

.products-list.compact-mode .product-item:nth-child(even) {
  background-color: #f9f9f9;
}

.products-list.compact-mode .product-item:hover {
  background-color: #e8f4f8;
}

.product-sku {
  font-weight: 600;
  color: #2c3e50;
}

.product-name {
  color: #34495e;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-stock {
  font-weight: 600;
  text-align: center;
  padding: 2px 6px;
  border-radius: 3px;
}

.product-stock.critical {
  background-color: #ffebee;
  color: #c62828;
}

.product-stock.low {
  background-color: #fff3e0;
  color: #ef6c00;
}

.product-stock.overstock {
  background-color: #e3f2fd;
  color: #1565c0;
}
```

#### Переключатель режимов

```css
.display-controls {
  display: flex;
  align-items: center;
  gap: 16px;
}

.item-counter {
  font-size: 13px;
  color: #666;
  font-weight: 500;
}

.toggle-switch {
  display: flex;
  background: #f5f5f5;
  border-radius: 6px;
  padding: 2px;
}

.toggle-btn {
  padding: 6px 12px;
  border: none;
  background: transparent;
  border-radius: 4px;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.2s;
}

.toggle-btn.active {
  background: white;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  color: #2c3e50;
}
```

### JavaScript функциональность

#### Контроллер переключения режимов

```javascript
class InventoryDisplayController {
  constructor() {
    this.currentMode = localStorage.getItem("inventory-display-mode") || "all";
    this.initializeControls();
    this.loadData();
  }

  initializeControls() {
    document.querySelectorAll(".toggle-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        const mode = e.target.dataset.mode;
        this.switchMode(mode);
      });
    });
  }

  switchMode(mode) {
    this.currentMode = mode;
    localStorage.setItem("inventory-display-mode", mode);

    // Обновить активные кнопки
    document.querySelectorAll(".toggle-btn").forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.mode === mode);
    });

    // Перезагрузить данные с новыми параметрами
    this.loadData();
  }

  async loadData() {
    const limit = this.currentMode === "all" ? "all" : "10";

    try {
      const response = await fetch(
        `/api/inventory-analytics.php?action=dashboard&limit=${limit}`
      );
      const data = await response.json();

      this.renderProducts(data.data);
      this.updateCounters(data.data);
    } catch (error) {
      console.error("Ошибка загрузки данных:", error);
      this.showError("Не удалось загрузить данные");
    }
  }

  renderProducts(data) {
    ["critical", "low_stock", "overstock"].forEach((category) => {
      const container = document.querySelector(
        `[data-category="${category}"] .products-container`
      );
      const products = data[`${category}_products`];

      container.innerHTML = products.items
        .map(
          (product) => `
        <div class="product-item">
          <div class="product-sku">${product.sku}</div>
          <div class="product-name" title="${product.product_name}">${
            product.product_name
          }</div>
          <div class="product-stock ${category.replace("_", "")}">${
            product.current_stock
          } шт</div>
          <div class="product-warehouse">${product.warehouse_name}</div>
        </div>
      `
        )
        .join("");

      // Применить стили в зависимости от режима
      const listElement = container.closest(".products-list");
      listElement.classList.toggle("compact-mode", this.currentMode === "all");
    });
  }

  updateCounters(data) {
    ["critical", "low_stock", "overstock"].forEach((category) => {
      const products = data[`${category}_products`];
      const counter = document.querySelector(
        `[data-category="${category}"] .item-counter`
      );

      if (this.currentMode === "all") {
        counter.textContent = `Показано ${products.count} из ${products.count} товаров`;
      } else {
        const shown = Math.min(10, products.count);
        counter.textContent = `Показано ${shown} из ${products.count} товаров`;
      }
    });
  }

  showError(message) {
    // Показать уведомление об ошибке
    const notification = document.createElement("div");
    notification.className = "error-notification";
    notification.textContent = message;
    document.body.appendChild(notification);

    setTimeout(() => notification.remove(), 5000);
  }
}
```

## Обработка ошибок

### Сценарии ошибок

1. **Большое количество товаров**

   - Добавить виртуализацию для списков > 100 товаров
   - Показать индикатор загрузки при переключении режимов

2. **Медленная загрузка данных**

   - Показать скелетон-загрузчик
   - Добавить таймаут для запросов (10 секунд)

3. **Отсутствие данных**
   - Показать сообщение "Нет товаров в данной категории"
   - Предложить обновить данные

### Оптимизация производительности

- Ленивая загрузка для больших списков
- Кэширование данных в localStorage на 5 минут
- Дебаунсинг для переключения режимов
- Виртуальная прокрутка для списков > 50 элементов

## Стратегия тестирования

### Функциональные тесты

- Переключение между режимами отображения
- Корректность счетчиков товаров
- Сохранение пользовательских предпочтений
- Отображение всех категорий товаров

### Тесты производительности

- Время загрузки списка из 100+ товаров < 2 секунд
- Плавность прокрутки больших списков
- Отзывчивость переключения режимов < 300ms

### Тесты совместимости

- Корректное отображение в Chrome, Firefox, Safari
- Адаптивность на мобильных устройствах
- Поддержка клавиатурной навигации

## Безопасность

### Валидация данных

- Проверка параметра limit (только 'all' или числовые значения)
- Санитизация названий товаров для предотвращения XSS
- Ограничение максимального количества товаров (1000)

### Производительность

- Пагинация для очень больших списков
- Кэширование на стороне сервера
- Сжатие JSON ответов
