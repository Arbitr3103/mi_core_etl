/**
 * Russian translations for Warehouse Dashboard
 */

export const ru = {
  // Dashboard Header
  dashboard: {
    title: "Складской дашборд",
    subtitle: "Детальный мониторинг инвентаря и рекомендации по пополнению",
    lastUpdated: "Последнее обновление:",
    nextUpdate: "след. через",
    autoRefresh: "Авто-обновление",
    share: "Поделиться",
    refresh: "Обновить",
  },

  // Summary Cards
  summary: {
    totalItems: "Всего позиций",
    criticalStock: "Критический запас",
    lowStock: "Низкий запас",
    urgentActions: "Срочные действия",
    replenishmentValue: "Сумма пополнения",
    totalRecommended: "Всего рекомендовано",
    daysLabel: "< {days} дней",
    highPriority: "Высокий приоритет",
  },

  // Filters
  filters: {
    title: "Фильтры",
    productSearch: "Поиск товара",
    searchPlaceholder: "Поиск по названию или артикулу...",
    warehouses: "Склады",
    allWarehouses: "Все склады",
    expand: "Развернуть",
    collapse: "Свернуть",
    stockStatus: "Статус запаса",
    critical: "Критический",
    criticalDesc: "< 14 дней",
    lowStock: "Низкий",
    lowStockDesc: "14-30 дней",
    normal: "Нормальный",
    normalDesc: "30-60 дней",
    excess: "Избыток",
    excessDesc: "> 60 дней",
    noSales: "Нет продаж",
    noSalesDesc: "Нет продаж за период",
    clearAll: "Очистить всё",
  },

  // Export
  export: {
    title: "Экспорт данных",
    summary: "Сводка экспорта",
    totalItems: "Всего позиций:",
    selected: "Выбрано:",
    filtered: "Отфильтровано:",
    needReplenishment: "Требуется пополнение:",
    chooseFormat: "Выберите формат экспорта:",
    csvExport: "CSV экспорт",
    csvDesc: "Файл со значениями, разделёнными запятыми",
    csvNote: "CSV: Совместим с Excel и другими приложениями",
    excelExport: "Excel экспорт",
    excelDesc: "Таблица Microsoft Excel",
    excelNote: "Excel: Нативный формат Excel с форматированием (скоро)",
    procurementOrder: "Заказ на закупку",
    procurementDesc: "Формат заказа на пополнение",
    procurementNote:
      "Закупка: Отфильтровано только позиции, требующие пополнения",
    itemsAvailable: "{count} позиций доступно",
    scrollToSee: "Прокрутите, чтобы увидеть больше",
  },

  // Table
  table: {
    showing: "Показано {count} позиций",
    product: "Товар",
    warehouse: "Склад",
    currentStock: "Текущий запас",
    availableStock: "Доступно",
    dailySales: "Продажи/день",
    daysOfStock: "Дней запаса",
    status: "Статус",
    recommended: "Рекомендовано",
    urgency: "Срочность",
    lastUpdated: "Обновлено",
    noData: "Нет данных",
    loading: "Загрузка...",
    selectAll: "Выбрать всё",
    deselectAll: "Снять выбор",
  },

  // Status
  status: {
    critical: "Критический",
    low: "Низкий",
    normal: "Нормальный",
    excess: "Избыток",
    outOfStock: "Нет в наличии",
    noSales: "Нет продаж",
  },

  // Actions
  actions: {
    export: "Экспорт",
    filter: "Фильтр",
    sort: "Сортировка",
    refresh: "Обновить",
    share: "Поделиться",
    clearFilters: "Очистить фильтры",
  },

  // Messages
  messages: {
    loading: "Загрузка дашборда...",
    error: "Ошибка загрузки",
    noResults: "Нет результатов",
    tryAdjustingFilters: "Попробуйте изменить фильтры",
    dataRefreshed: "Данные обновлены",
    exportSuccess: "Экспорт выполнен успешно",
    exportError: "Ошибка экспорта",
  },

  // Time
  time: {
    minutes: "минут",
    hours: "часов",
    days: "дней",
    ago: "назад",
    in: "через",
  },

  // Common
  common: {
    yes: "Да",
    no: "Нет",
    ok: "ОК",
    cancel: "Отмена",
    close: "Закрыть",
    save: "Сохранить",
    delete: "Удалить",
    edit: "Редактировать",
    view: "Просмотр",
    search: "Поиск",
    filter: "Фильтр",
    sort: "Сортировка",
    export: "Экспорт",
    import: "Импорт",
    download: "Скачать",
    upload: "Загрузить",
    back: "Назад",
    next: "Далее",
    previous: "Предыдущий",
    loading: "Загрузка...",
    error: "Ошибка",
    success: "Успешно",
    warning: "Предупреждение",
    info: "Информация",
  },
};

export type TranslationKeys = typeof ru;
