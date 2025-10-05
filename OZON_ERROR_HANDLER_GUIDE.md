# Ozon Error Handler - Руководство по использованию

## Обзор

`OzonErrorHandler` - это специализированный класс для обработки ошибок и управления состояниями загрузки в системе аналитики Ozon. Он расширяет базовый `MarketplaceErrorHandler` и предоставляет специфичную для Ozon функциональность.

## Основные возможности

### 1. Автоматическая обработка ошибок API

- Перехват всех запросов к Ozon API
- Автоматическая retry логика с экспоненциальной задержкой
- Специализированные сообщения об ошибках для различных типов проблем

### 2. Индикаторы загрузки

- Компонентно-специфичные индикаторы загрузки
- Глобальные индикаторы для множественных запросов
- Таймауты для предотвращения зависания

### 3. Graceful Degradation

- Автоматическое использование кэшированных данных при ошибках
- Fallback состояния с полезными сообщениями
- Предложения по решению проблем

### 4. Пользовательские уведомления

- Toast уведомления с различными типами (успех, предупреждение, ошибка)
- Контекстные сообщения с предложениями
- Адаптивные уведомления в зависимости от серьезности ошибки

## Инициализация

```javascript
const ozonErrorHandler = new OzonErrorHandler({
  showToasts: true,
  autoHideToasts: true,
  toastDuration: 5000,
  ozonOptions: {
    maxRetries: 3,
    retryDelay: 2000,
    fallbackDataEnabled: true,
    gracefulDegradation: true,
    loadingTimeout: 30000,
  },
});
```

### Параметры конфигурации

#### Базовые параметры (наследуются от MarketplaceErrorHandler)

- `showToasts` - показывать toast уведомления
- `autoHideToasts` - автоматически скрывать уведомления
- `toastDuration` - длительность показа уведомлений (мс)
- `logErrors` - логировать ошибки в консоль

#### Специфичные для Ozon параметры

- `maxRetries` - максимальное количество повторных попыток
- `retryDelay` - базовая задержка между попытками (мс)
- `fallbackDataEnabled` - использовать fallback данные
- `gracefulDegradation` - включить graceful degradation
- `loadingTimeout` - таймаут для запросов (мс)

## Типы ошибок Ozon API

### Критические ошибки

- `AUTHENTICATION_ERROR` - проблемы с аутентификацией
- `INSUFFICIENT_PERMISSIONS` - недостаточно прав доступа

### Временные ошибки (с retry)

- `RATE_LIMIT_EXCEEDED` - превышение лимита запросов
- `API_UNAVAILABLE` - временная недоступность API
- `TIMEOUT` - превышение времени ожидания
- `NETWORK_ERROR` - сетевые проблемы

### Ошибки данных

- `NO_DATA` - отсутствие данных за период
- `INVALID_PARAMETERS` - неверные параметры запроса

## Индикаторы загрузки

### Воронка продаж

```javascript
// Показать индикатор загрузки для воронки
ozonErrorHandler.showFunnelLoadingState(requestId);

// Скрыть индикатор
ozonErrorHandler.hideLoadingState(requestId);
```

### Демографические данные

```javascript
ozonErrorHandler.showDemographicsLoadingState(requestId);
```

### Данные кампаний

```javascript
ozonErrorHandler.showCampaignsLoadingState(requestId);
```

### Экспорт данных

```javascript
ozonErrorHandler.showExportLoadingState(requestId);
```

## Обработка ошибок

### Автоматическая обработка

Обработчик автоматически перехватывает все fetch запросы к Ozon API:

```javascript
// Этот запрос будет автоматически обработан
const response = await fetch("/src/api/ozon-analytics.php?action=funnel-data");
```

### Ручная обработка

```javascript
try {
  const data = await loadOzonData();
} catch (error) {
  ozonErrorHandler.handleOzonAPIError(error, url, requestId);
}
```

## Fallback состояния

### Кэшированные данные

Обработчик автоматически пытается использовать кэшированные данные при ошибках:

```javascript
// Данные сохраняются в localStorage
localStorage.setItem(
  "ozon_funnel_cache",
  JSON.stringify({
    content: data,
    timestamp: Date.now(),
  })
);
```

### Пустые состояния

При отсутствии кэшированных данных показываются информативные fallback состояния с:

- Описанием проблемы
- Предложениями по решению
- Кнопками для повторных попыток

## Уведомления

### Типы уведомлений

- `success` - успешные операции
- `info` - информационные сообщения
- `warning` - предупреждения
- `error` - ошибки

### Показ уведомлений

```javascript
ozonErrorHandler.showToast({
  type: "success",
  title: "Успешно",
  message: "Данные загружены",
  duration: 3000,
});
```

## Интеграция с компонентами

### OzonAnalyticsIntegration

```javascript
class OzonAnalyticsIntegration {
  constructor(options = {}) {
    this.errorHandler = new OzonErrorHandler({
      // конфигурация
    });
  }

  async loadData() {
    try {
      const response = await fetch(url);
      // Обработка успешного ответа
    } catch (error) {
      // Ошибка уже обработана автоматически
    }
  }
}
```

### OzonFunnelChart

Методы `showLoading()` и `showError()` в OzonFunnelChart помечены как deprecated, так как теперь обработка состояний выполняется через OzonErrorHandler.

## Мониторинг производительности

Обработчик автоматически отслеживает:

- Время ответа API
- Частоту ошибок
- Качество соединения

При обнаружении проблем с производительностью показываются соответствующие предупреждения.

## CSS стили

Подключите стили для корректного отображения:

```html
<link href="src/css/ozon-error-handler.css" rel="stylesheet" />
```

### Основные CSS классы

- `.ozon-loading-state` - состояния загрузки
- `.ozon-fallback-state` - fallback состояния
- `.ozon-loading-overlay` - overlay для загрузки
- `.ozon-error-container` - контейнер ошибок

## Адаптивность и доступность

### Адаптивный дизайн

- Оптимизация для мобильных устройств
- Адаптивные размеры индикаторов
- Упрощенные интерфейсы на малых экранах

### Доступность

- Поддержка `prefers-reduced-motion`
- Высокий контраст для `prefers-contrast: high`
- Семантическая разметка
- ARIA атрибуты для скринридеров

## Тестирование

Используйте `test_ozon_error_handler.html` для тестирования различных сценариев:

1. Откройте файл в браузере
2. Используйте кнопки для тестирования различных типов ошибок
3. Проверьте индикаторы загрузки
4. Убедитесь в корректности fallback состояний

## Лучшие практики

### 1. Кэширование данных

```javascript
// Сохраняйте данные для fallback
try {
  localStorage.setItem(
    "ozon_data_cache",
    JSON.stringify({
      content: data,
      timestamp: Date.now(),
    })
  );
} catch (e) {
  console.warn("Could not cache data:", e);
}
```

### 2. Обработка специфичных ошибок

```javascript
// Создавайте специфичные ошибки для лучшей обработки
throw new OzonAPIError(
  "Custom error message",
  "CUSTOM_ERROR_CODE",
  "error_type",
  { additionalInfo: "details" }
);
```

### 3. Graceful degradation

```javascript
// Всегда предоставляйте fallback функциональность
if (this.ozonOptions.gracefulDegradation) {
  this.attemptGracefulDegradation(error, requestId);
}
```

## Отладка

### Логирование

Включите подробное логирование для отладки:

```javascript
const ozonErrorHandler = new OzonErrorHandler({
  logErrors: true,
  // другие параметры
});
```

### Консольные сообщения

Обработчик выводит подробную информацию об ошибках в консоль браузера.

### Мониторинг запросов

Все запросы к Ozon API отслеживаются и логируются с уникальными ID.

## Производительность

### Оптимизации

- Кэширование данных в localStorage
- Debouncing для частых запросов
- Lazy loading для больших наборов данных
- Минимизация DOM манипуляций

### Мониторинг

- Автоматическое отслеживание времени ответа
- Предупреждения о медленных запросах
- Статистика ошибок

## Заключение

OzonErrorHandler обеспечивает надежную и пользовательски-дружественную обработку ошибок в системе аналитики Ozon. Он автоматически управляет состояниями загрузки, предоставляет информативные сообщения об ошибках и обеспечивает graceful degradation при проблемах с API.
