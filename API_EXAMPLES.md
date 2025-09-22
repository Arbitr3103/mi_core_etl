# 🔌 Примеры использования API

Этот документ содержит практические примеры использования REST API системы пополнения склада.

## 🏃 Быстрый старт

### Запуск API сервера

```bash
# Простой API сервер
python3 simple_api_server.py

# Или через Docker
docker-compose up -d
```

API будет доступен по адресу: `http://localhost:8000`

## 📋 Основные эндпоинты

### 1. Проверка здоровья системы

```bash
# Базовая проверка
curl http://localhost:8000/api/health

# С форматированием JSON
curl -s http://localhost:8000/api/health | python3 -m json.tool
```

**Ответ:**

```json
{
  "status": "healthy",
  "timestamp": "2024-01-15 10:30:00",
  "components": {
    "recommender": true,
    "alert_manager": true,
    "reporting_engine": true,
    "orchestrator": true,
    "database": false
  }
}
```

### 2. Получение рекомендаций

```bash
# Все рекомендации (по умолчанию 50)
curl http://localhost:8000/api/recommendations

# Ограничить количество
curl http://localhost:8000/api/recommendations?limit=10

# Фильтр по приоритету
curl http://localhost:8000/api/recommendations?priority=CRITICAL

# Фильтр по источнику
curl http://localhost:8000/api/recommendations?source=ozon
```

**Ответ:**

```json
{
  "recommendations": [
    {
      "product_id": 1,
      "sku": "DEMO-001",
      "product_name": "Демо товар 1",
      "source": "demo",
      "current_stock": 5,
      "available_stock": 3,
      "recommended_order_quantity": 50,
      "recommended_order_value": 25000.0,
      "priority_level": "CRITICAL",
      "urgency_score": 95.0,
      "days_until_stockout": 2,
      "daily_sales_rate_7d": 2.5,
      "sales_trend": "STABLE",
      "analysis_date": "2024-01-15 10:30:00"
    }
  ],
  "total_count": 1,
  "generated_at": "2024-01-15 10:30:00"
}
```

### 3. Получение рекомендации для конкретного товара

```bash
# Рекомендация для товара с ID = 1
curl http://localhost:8000/api/recommendations/1
```

### 4. Получение алертов

```bash
# Все активные алерты
curl http://localhost:8000/api/alerts

# Ограничить количество
curl http://localhost:8000/api/alerts?limit=20
```

**Ответ:**

```json
{
  "alerts": [
    {
      "id": 1,
      "sku": "DEMO-001",
      "product_name": "Демо товар 1",
      "alert_type": "STOCKOUT_CRITICAL",
      "alert_level": "CRITICAL",
      "message": "Критический остаток товара DEMO-001",
      "current_stock": 5,
      "days_until_stockout": 2,
      "recommended_action": "Срочно заказать 50 шт",
      "status": "NEW",
      "created_at": "2024-01-15 10:30:00"
    }
  ],
  "total_count": 1,
  "generated_at": "2024-01-15 10:30:00"
}
```

### 5. Подтверждение алерта

```bash
# Подтвердить алерт с ID = 1
curl -X POST http://localhost:8000/api/alerts/1/acknowledge \
  -H "Content-Type: application/json" \
  -d '{"acknowledged_by": "Менеджер склада"}'
```

**Ответ:**

```json
{
  "message": "Алерт подтвержден",
  "alert_id": 1,
  "acknowledged_by": "Менеджер склада",
  "acknowledged_at": "2024-01-15 10:35:00"
}
```

### 6. Запуск анализа

```bash
# Быстрый анализ
curl -X POST http://localhost:8000/api/analysis/run \
  -H "Content-Type: application/json" \
  -d '{}'

# Анализ с параметрами
curl -X POST http://localhost:8000/api/analysis/run \
  -H "Content-Type: application/json" \
  -d '{
    "source": "ozon",
    "save_to_db": true,
    "send_alerts": true
  }'
```

**Ответ:**

```json
{
  "execution_time": 0.5,
  "critical_recommendations": 5,
  "critical_alerts": 3,
  "status": "SUCCESS"
}
```

### 7. Получение комплексного отчета

```bash
# Общий отчет
curl http://localhost:8000/api/reports/comprehensive

# Отчет по источнику
curl http://localhost:8000/api/reports/comprehensive?source=ozon
```

**Ответ:**

```json
{
  "report_metadata": {
    "generated_at": "2024-01-15 10:30:00",
    "source_filter": "Все источники",
    "report_type": "Демо отчет"
  },
  "inventory_metrics": {
    "total_products": 100,
    "total_inventory_value": 500000.0,
    "low_stock_products": 15,
    "zero_stock_products": 5,
    "overstocked_products": 10,
    "avg_inventory_turnover_days": 45.5,
    "total_recommended_orders": 25,
    "total_recommended_value": 150000.0
  },
  "sales_metrics": {
    "total_sales_volume_30d": 1500,
    "total_sales_value_30d": 750000.0,
    "avg_daily_sales": 50.0,
    "fast_moving_products": 20,
    "slow_moving_products": 30,
    "no_sales_products": 10
  }
}
```

## 🐍 Примеры на Python

### Базовый клиент

```python
import requests
import json

class ReplenishmentAPIClient:
    def __init__(self, base_url="http://localhost:8000"):
        self.base_url = base_url

    def get_health(self):
        """Проверка здоровья системы"""
        response = requests.get(f"{self.base_url}/api/health")
        return response.json()

    def get_recommendations(self, limit=50, priority=None, source=None):
        """Получение рекомендаций"""
        params = {"limit": limit}
        if priority:
            params["priority"] = priority
        if source:
            params["source"] = source

        response = requests.get(f"{self.base_url}/api/recommendations", params=params)
        return response.json()

    def get_alerts(self, limit=50):
        """Получение алертов"""
        params = {"limit": limit}
        response = requests.get(f"{self.base_url}/api/alerts", params=params)
        return response.json()

    def run_analysis(self, source=None, save_to_db=True, send_alerts=True):
        """Запуск анализа"""
        data = {
            "save_to_db": save_to_db,
            "send_alerts": send_alerts
        }
        if source:
            data["source"] = source

        response = requests.post(f"{self.base_url}/api/analysis/run", json=data)
        return response.json()

    def acknowledge_alert(self, alert_id, acknowledged_by="API Client"):
        """Подтверждение алерта"""
        data = {"acknowledged_by": acknowledged_by}
        response = requests.post(f"{self.base_url}/api/alerts/{alert_id}/acknowledge", json=data)
        return response.json()

# Использование
client = ReplenishmentAPIClient()

# Проверка здоровья
health = client.get_health()
print(f"Статус системы: {health['status']}")

# Получение критических рекомендаций
recommendations = client.get_recommendations(priority="CRITICAL", limit=10)
print(f"Критических рекомендаций: {recommendations['total_count']}")

# Запуск анализа
analysis_result = client.run_analysis()
print(f"Анализ завершен: {analysis_result['status']}")
```

### Мониторинг системы

```python
import time
import requests
from datetime import datetime

def monitor_system(api_url="http://localhost:8000", interval=60):
    """Мониторинг системы каждые 60 секунд"""

    while True:
        try:
            # Проверка здоровья
            health_response = requests.get(f"{api_url}/api/health", timeout=10)
            health = health_response.json()

            timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            status = health.get('status', 'unknown')

            print(f"[{timestamp}] Статус системы: {status}")

            # Проверка критических алертов
            alerts_response = requests.get(f"{api_url}/api/alerts?limit=5", timeout=10)
            alerts = alerts_response.json()

            critical_alerts = [a for a in alerts.get('alerts', []) if a['alert_level'] == 'CRITICAL']

            if critical_alerts:
                print(f"[{timestamp}] ⚠️  Критических алертов: {len(critical_alerts)}")
                for alert in critical_alerts:
                    print(f"  - {alert['sku']}: {alert['message']}")

        except requests.exceptions.RequestException as e:
            print(f"[{timestamp}] ❌ Ошибка подключения: {e}")

        time.sleep(interval)

# Запуск мониторинга
# monitor_system()
```

## 🌐 Примеры на JavaScript

### Fetch API

```javascript
class ReplenishmentAPI {
  constructor(baseUrl = "http://localhost:8000") {
    this.baseUrl = baseUrl;
  }

  async getHealth() {
    const response = await fetch(`${this.baseUrl}/api/health`);
    return await response.json();
  }

  async getRecommendations(options = {}) {
    const params = new URLSearchParams();
    if (options.limit) params.append("limit", options.limit);
    if (options.priority) params.append("priority", options.priority);
    if (options.source) params.append("source", options.source);

    const response = await fetch(
      `${this.baseUrl}/api/recommendations?${params}`
    );
    return await response.json();
  }

  async runAnalysis(options = {}) {
    const response = await fetch(`${this.baseUrl}/api/analysis/run`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(options),
    });
    return await response.json();
  }
}

// Использование
const api = new ReplenishmentAPI();

// Проверка здоровья
api.getHealth().then((health) => {
  console.log("Статус системы:", health.status);
});

// Получение рекомендаций
api.getRecommendations({ priority: "CRITICAL", limit: 10 }).then((data) => {
  console.log("Критических рекомендаций:", data.total_count);
  data.recommendations.forEach((rec) => {
    console.log(`${rec.sku}: заказать ${rec.recommended_order_quantity} шт`);
  });
});
```

## 🔧 Обработка ошибок

### Python

```python
import requests
from requests.exceptions import RequestException, Timeout, ConnectionError

def safe_api_call(url, method='GET', **kwargs):
    """Безопасный вызов API с обработкой ошибок"""
    try:
        if method.upper() == 'GET':
            response = requests.get(url, timeout=30, **kwargs)
        elif method.upper() == 'POST':
            response = requests.post(url, timeout=30, **kwargs)
        else:
            raise ValueError(f"Неподдерживаемый метод: {method}")

        # Проверка статуса ответа
        response.raise_for_status()

        return response.json()

    except Timeout:
        return {"error": "Превышено время ожидания"}
    except ConnectionError:
        return {"error": "Ошибка подключения к серверу"}
    except requests.exceptions.HTTPError as e:
        return {"error": f"HTTP ошибка: {e.response.status_code}"}
    except ValueError as e:
        return {"error": f"Ошибка данных: {str(e)}"}
    except Exception as e:
        return {"error": f"Неожиданная ошибка: {str(e)}"}

# Использование
result = safe_api_call("http://localhost:8000/api/health")
if "error" in result:
    print(f"Ошибка: {result['error']}")
else:
    print(f"Статус: {result['status']}")
```

## 📊 Интеграция с внешними системами

### Webhook для уведомлений

```python
import requests
import json

def send_webhook_notification(webhook_url, alert_data):
    """Отправка уведомления через webhook"""

    payload = {
        "text": f"🚨 Критический остаток товара {alert_data['sku']}",
        "attachments": [
            {
                "color": "danger",
                "fields": [
                    {
                        "title": "SKU",
                        "value": alert_data['sku'],
                        "short": True
                    },
                    {
                        "title": "Остаток",
                        "value": f"{alert_data['current_stock']} шт",
                        "short": True
                    },
                    {
                        "title": "Действие",
                        "value": alert_data['recommended_action'],
                        "short": False
                    }
                ]
            }
        ]
    }

    try:
        response = requests.post(webhook_url, json=payload, timeout=10)
        response.raise_for_status()
        return True
    except Exception as e:
        print(f"Ошибка отправки webhook: {e}")
        return False

# Получение алертов и отправка уведомлений
api_client = ReplenishmentAPIClient()
alerts = api_client.get_alerts()

for alert in alerts.get('alerts', []):
    if alert['alert_level'] == 'CRITICAL':
        send_webhook_notification("https://hooks.slack.com/your-webhook-url", alert)
```

## 🔄 Автоматизация

### Cron задача для регулярного анализа

```bash
# Добавить в crontab (crontab -e)

# Полный анализ каждый день в 6:00
0 6 * * * curl -X POST http://localhost:8000/api/analysis/run

# Быстрая проверка каждые 4 часа
0 */4 * * * curl -X POST http://localhost:8000/api/analysis/run

# Проверка здоровья каждые 5 минут
*/5 * * * * curl -f http://localhost:8000/api/health > /dev/null || echo "API недоступен" | mail -s "Проблема с API" admin@company.com
```

### Systemd сервис для мониторинга

```ini
# /etc/systemd/system/replenishment-monitor.service
[Unit]
Description=Replenishment System Monitor
After=network.target

[Service]
Type=simple
User=replenishment
WorkingDirectory=/opt/replenishment
ExecStart=/usr/bin/python3 monitor.py
Restart=always
RestartSec=30

[Install]
WantedBy=multi-user.target
```

## 📈 Метрики и мониторинг

### Prometheus метрики (пример)

```python
# metrics.py
import time
import requests
from prometheus_client import start_http_server, Gauge, Counter

# Метрики
health_status = Gauge('replenishment_health_status', 'Health status of the system')
critical_recommendations = Gauge('replenishment_critical_recommendations', 'Number of critical recommendations')
total_alerts = Gauge('replenishment_total_alerts', 'Total number of alerts')
api_requests = Counter('replenishment_api_requests_total', 'Total API requests', ['endpoint', 'status'])

def collect_metrics():
    """Сбор метрик из API"""
    try:
        # Здоровье системы
        health = requests.get('http://localhost:8000/api/health').json()
        health_status.set(1 if health['status'] == 'healthy' else 0)

        # Рекомендации
        recommendations = requests.get('http://localhost:8000/api/recommendations?priority=CRITICAL').json()
        critical_recommendations.set(recommendations['total_count'])

        # Алерты
        alerts = requests.get('http://localhost:8000/api/alerts').json()
        total_alerts.set(alerts['total_count'])

        api_requests.labels(endpoint='health', status='success').inc()

    except Exception as e:
        print(f"Ошибка сбора метрик: {e}")
        api_requests.labels(endpoint='health', status='error').inc()

if __name__ == '__main__':
    # Запуск HTTP сервера для метрик
    start_http_server(8001)

    while True:
        collect_metrics()
        time.sleep(60)  # Сбор каждую минуту
```

---

💡 **Совет**: Всегда проверяйте статус ответа API и обрабатывайте ошибки корректно. Используйте таймауты для предотвращения зависания запросов.
