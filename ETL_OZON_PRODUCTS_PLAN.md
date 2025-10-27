# План обновления ETL для получения статусов товаров Ozon

## Проблема

Дашборд показывает 271 товар вместо 48 активных, потому что мы используем неправильный источник данных. Текущий ETL получает данные из отчета `POST /v1/report/warehouse/stock`, который показывает только FBO-остатки в процессе обработки.

Нужные нам 48 товаров "В продаже" находятся в другом отчете - **"Отчет по товарам"**.

## Решение

### Шаг 1: Добавить новый ETL-процесс для "Отчета по товарам"

**API Endpoint:** `POST /v2/product/import/info`

**Алгоритм:**

1. Запросить генерацию отчета
2. Дождаться готовности (статус SUCCESS)
3. Скачать CSV-файл
4. Обработать и загрузить в БД

### Шаг 2: Найти нужные поля в CSV

**Ключевые столбцы:**

-   `Статус товара` (state_name) → сохранять в `dim_products.ozon_status`
-   `FBO остаток, шт` (fbo_present_stock) → сохранять в `inventory.quantity_present`
-   `Видимость` → сохранять в `dim_products.ozon_visibility`

### Шаг 3: Обновить логику фильтрации

**В API (DetailedInventoryService.php):**

```php
// Вместо current_stock > 0
// Использовать:
WHERE dp.ozon_status = 'продаётся'
```

**Во фронтенде:**

```typescript
// По умолчанию показывать только товары "в продаже"
active_only: true; // будет фильтровать по ozon_status = 'продаётся'
```

## Техническая реализация

### 1. Создать новый ETL-скрипт

**Файл:** `etl/ozon_products_report.py`

```python
import requests
import pandas as pd
import psycopg2
from datetime import datetime
import time

class OzonProductsETL:
    def __init__(self, client_id, api_key, db_config):
        self.client_id = client_id
        self.api_key = api_key
        self.db_config = db_config
        self.base_url = "https://api-seller.ozon.ru"

    def request_report(self):
        """Запросить генерацию отчета по товарам"""
        url = f"{self.base_url}/v2/product/import/info"
        headers = {
            "Client-Id": self.client_id,
            "Api-Key": self.api_key,
            "Content-Type": "application/json"
        }

        response = requests.post(url, headers=headers, json={})
        return response.json()["result"]["code"]

    def check_report_status(self, code):
        """Проверить готовность отчета"""
        url = f"{self.base_url}/v1/report/info"
        headers = {
            "Client-Id": self.client_id,
            "Api-Key": self.api_key,
            "Content-Type": "application/json"
        }

        response = requests.post(url, headers=headers, json={"code": code})
        return response.json()["result"]

    def download_report(self, file_url):
        """Скачать CSV-отчет"""
        response = requests.get(file_url)
        return response.content

    def process_and_load(self, csv_content):
        """Обработать CSV и загрузить в БД"""
        df = pd.read_csv(io.StringIO(csv_content.decode('utf-8')))

        # Подключение к БД
        conn = psycopg2.connect(**self.db_config)
        cur = conn.cursor()

        for _, row in df.iterrows():
            # Обновить статус товара
            cur.execute("""
                UPDATE dim_products
                SET ozon_status = %s,
                    ozon_visibility = %s,
                    updated_at = CURRENT_TIMESTAMP
                WHERE sku_ozon = %s
            """, (
                row.get('Статус товара'),
                row.get('Видимость'),
                row.get('SKU')
            ))

            # Обновить остатки (если есть FBO данные)
            if 'FBO остаток, шт' in row and pd.notna(row['FBO остаток, шт']):
                cur.execute("""
                    UPDATE inventory
                    SET quantity_present = %s,
                        available = %s,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE product_id = (
                        SELECT id FROM dim_products WHERE sku_ozon = %s
                    )
                """, (
                    int(row['FBO остаток, шт']),
                    int(row['FBO остаток, шт']) if row['Статус товара'] == 'продаётся' else 0,
                    row.get('SKU')
                ))

        conn.commit()
        conn.close()

    def run(self):
        """Запустить полный ETL-процесс"""
        print("Запрос отчета по товарам...")
        code = self.request_report()

        print(f"Ожидание готовности отчета {code}...")
        while True:
            status = self.check_report_status(code)
            if status["status"] == "SUCCESS":
                break
            elif status["status"] == "ERROR":
                raise Exception(f"Ошибка генерации отчета: {status}")
            time.sleep(30)

        print("Скачивание отчета...")
        csv_content = self.download_report(status["file"])

        print("Обработка и загрузка в БД...")
        self.process_and_load(csv_content)

        print("ETL завершен успешно!")

if __name__ == "__main__":
    etl = OzonProductsETL(
        client_id="your_client_id",
        api_key="your_api_key",
        db_config={
            "host": "localhost",
            "database": "mi_core_db",
            "user": "your_user",
            "password": "your_password"
        }
    )
    etl.run()
```

### 2. Обновить API-фильтрацию

**В DetailedInventoryService.php:**

```php
// В методе buildWhereConditions добавить:
if (!empty($filters['active_only'])) {
    // Новая логика: фильтровать по статусу Ozon
    $conditions[] = "ozon_status = 'продаётся'";
} else {
    // Старая логика для совместимости
    $conditions[] = "(current_stock > 0 OR sales_last_28_days > 0)";
}
```

### 3. Настроить cron для автоматического обновления

```bash
# Запускать каждые 4 часа
0 */4 * * * /usr/bin/python3 /path/to/etl/ozon_products_report.py >> /var/log/ozon_etl.log 2>&1
```

## Ожидаемый результат

После реализации:

**До:**

-   Показывается 271 товар (все товары с любыми остатками)
-   Нет различия между "в продаже" и "готов к продаже"

**После:**

-   Показывается 48 товаров со статусом "продаётся"
-   Точное соответствие с личным кабинетом Ozon
-   Возможность переключения между активными и всеми товарами

## Приоритет реализации

**Высокий приоритет** - это критично для корректной работы дашборда.

**Время реализации:** 2-3 дня разработки + тестирование

## Альтернативное временное решение

Пока ETL не готов, можно:

1. **Установить фильтр по умолчанию на критические + низкие остатки:**

    ```typescript
    DEFAULT_FILTERS = {
        statuses: ["critical", "low"], // 226 + 42 = 268 товаров
    };
    ```

2. **Добавить примечание в интерфейс:**
   "Показаны товары с критическим и низким запасом. Для точной фильтрации по статусу Ozon требуется обновление ETL."

Это даст приемлемый результат до реализации полного решения.
