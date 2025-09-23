# 🔧 Альтернативные способы настройки MySQL

Если основные скрипты не работают, используйте один из этих методов:

## Метод 1: Через sudo mysql (рекомендуется для Ubuntu)

```bash
# Создание базы данных
sudo mysql -e "CREATE DATABASE IF NOT EXISTS replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Создание пользователя (замените YOUR_PASSWORD на свой пароль)
sudo mysql -e "CREATE USER IF NOT EXISTS 'replenishment_user'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD';"

# Предоставление прав
sudo mysql -e "GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# Применение схемы
mysql -u replenishment_user -pYOUR_PASSWORD replenishment_db < create_replenishment_schema_clean.sql
```

## Метод 2: Интерактивная настройка

```bash
# Вход в MySQL как root
sudo mysql

# В консоли MySQL выполните:
CREATE DATABASE IF NOT EXISTS replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'replenishment_user'@'localhost' IDENTIFIED BY 'your_strong_password';
GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Затем примените схему
mysql -u replenishment_user -p replenishment_db < create_replenishment_schema_clean.sql
```

## Метод 3: Если MySQL требует пароль root

```bash
# Сначала установите пароль для root (если нужно)
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'your_root_password';"

# Затем используйте пароль
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER IF NOT EXISTS 'replenishment_user'@'localhost' IDENTIFIED BY 'your_password';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';"
mysql -u root -p -e "FLUSH PRIVILEGES;"
```

## Метод 4: Ручное создание конфигурации

Создайте файл `importers/config.py`:

```python
# Конфигурация базы данных
DB_CONFIG = {
    'host': 'localhost',
    'user': 'replenishment_user',
    'password': 'your_password_here',
    'database': 'replenishment_db',
    'charset': 'utf8mb4',
    'autocommit': True
}

# Настройки системы
SYSTEM_CONFIG = {
    'critical_stockout_threshold': 3,
    'high_priority_threshold': 7,
    'max_recommended_order_multiplier': 3.0,
    'enable_email_alerts': False,
    'alert_email_recipients': []
}
```

## Проверка подключения

После любого метода проверьте подключение:

```bash
python3 -c "
import mysql.connector
try:
    conn = mysql.connector.connect(
        host='localhost',
        user='replenishment_user',
        password='your_password',
        database='replenishment_db'
    )
    print('✅ Подключение успешно!')
    conn.close()
except Exception as e:
    print(f'❌ Ошибка: {e}')
"
```

## Устранение проблем

### Ошибка "Access denied"

```bash
# Проверьте права пользователя
sudo mysql -e "SHOW GRANTS FOR 'replenishment_user'@'localhost';"

# Если нужно, пересоздайте пользователя
sudo mysql -e "DROP USER IF EXISTS 'replenishment_user'@'localhost';"
sudo mysql -e "CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY 'new_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

### Ошибка "Database doesn't exist"

```bash
# Проверьте существование базы
sudo mysql -e "SHOW DATABASES LIKE 'replenishment_db';"

# Если нет, создайте
sudo mysql -e "CREATE DATABASE replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Ошибка подключения Python

```bash
# Установите MySQL connector если нужно
pip install mysql-connector-python

# Проверьте версию
python3 -c "import mysql.connector; print(mysql.connector.__version__)"
```

## Быстрый тест системы

После настройки базы данных:

```bash
# Тест анализатора запасов
python3 -c "
from inventory_analyzer import InventoryAnalyzer
analyzer = InventoryAnalyzer()
print('✅ Анализатор запасов работает')
analyzer.close()
"

# Тест API сервера
python3 simple_api_server.py &
sleep 3
curl http://localhost:8000/api/health
pkill -f simple_api_server.py
```
