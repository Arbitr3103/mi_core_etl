"""
Модуль для импорта данных из API Wildberries в базу данных MySQL.

Включает функции для:
- Подключения к базе данных
- Загрузки конфигурации из .env файла
- Получения продаж и возвратов из API Wildberries
- Получения финансовых деталей из API Wildberries
- Трансформации и загрузки данных в таблицы fact_orders и fact_transactions
"""

import os
import json
import logging
import mysql.connector
from mysql.connector import Error
import requests
import time
from datetime import datetime, timedelta
from dotenv import load_dotenv
from typing import Dict, List, Optional, Any

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def load_config() -> Dict[str, str]:
    """
    Загружает конфигурацию из .env файла.
    
    Returns:
        Dict[str, str]: Словарь с конфигурационными параметрами
    """
    # Загружаем переменные окружения из .env файла
    load_dotenv()
    
    config = {
        'DB_HOST': os.getenv('DB_HOST'),
        'DB_USER': os.getenv('DB_USER'),
        'DB_PASSWORD': os.getenv('DB_PASSWORD'),
        'DB_NAME': os.getenv('DB_NAME'),
        'WB_API_KEY': os.getenv('WB_API_KEY'),
        'WB_API_URL': os.getenv('WB_API_URL', 'https://statistics-api.wildberries.ru')
    }
    
    # Проверяем, что все необходимые параметры загружены
    missing_params = [key for key, value in config.items() if not value]
    if missing_params:
        raise ValueError(f"Отсутствуют обязательные параметры в .env файле: {missing_params}")
    
    logger.info("Конфигурация успешно загружена")
    return config


def connect_to_db() -> mysql.connector.MySQLConnection:
    """
    Устанавливает соединение с базой данных MySQL.
    
    Returns:
        mysql.connector.MySQLConnection: Объект соединения с базой данных
    """
    config = load_config()
    
    try:
        connection = mysql.connector.connect(
            host=config['DB_HOST'],
            user=config['DB_USER'],
            password=config['DB_PASSWORD'],
            database=config['DB_NAME'],
            charset='utf8mb4',
            collation='utf8mb4_unicode_ci',
            use_unicode=True,
            autocommit=True,
            connection_timeout=5
        )
        
        logger.info(f"Успешное подключение к базе данных {config['DB_NAME']}")
        return connection
        
    except Error as e:
        logger.error(f"Ошибка подключения к базе данных: {e}")
        raise


def get_client_id_by_name(client_name: str) -> Optional[int]:
    """
    Получает ID клиента по имени из таблицы clients.
    
    Args:
        client_name (str): Имя клиента
        
    Returns:
        Optional[int]: ID клиента или None если не найден
    """
    connection = connect_to_db()
    
    try:
        cursor = connection.cursor(dictionary=True)
        sql = "SELECT id FROM clients WHERE name = %s"
        cursor.execute(sql, (client_name,))
        result = cursor.fetchone()
        cursor.close()
        
        if result:
            logger.info(f"Найден клиент '{client_name}' с ID: {result['id']}")
            return result['id']
        else:
            logger.warning(f"Клиент '{client_name}' не найден в таблице clients")
            return None
            
    except Exception as e:
        logger.error(f"Ошибка получения ID клиента: {e}")
        raise
    finally:
        connection.close()


def get_source_id_by_code(source_code: str) -> Optional[int]:
    """
    Получает ID источника по коду из таблицы sources.
    
    Args:
        source_code (str): Код источника
        
    Returns:
        Optional[int]: ID источника или None если не найден
    """
    connection = connect_to_db()
    
    try:
        cursor = connection.cursor(dictionary=True)
        sql = "SELECT id FROM sources WHERE code = %s"
        cursor.execute(sql, (source_code,))
        result = cursor.fetchone()
        cursor.close()
        
        if result:
            logger.info(f"Найден источник '{source_code}' с ID: {result['id']}")
            return result['id']
        else:
            logger.warning(f"Источник '{source_code}' не найден в таблице sources")
            return None
            
    except Exception as e:
        logger.error(f"Ошибка получения ID источника: {e}")
        raise
    finally:
        connection.close()


def make_wb_request(endpoint: str, params: Dict[str, Any] = None, method: str = 'GET', data: Dict[str, Any] = None) -> Dict[str, Any]:
    """
    Выполняет запрос к API Wildberries с автоматическим выбором правильного базового URL.
    
    Args:
        endpoint (str): Конечная точка API (например, '/api/v1/supplier/sales')
        params (Dict[str, Any], optional): Параметры запроса для GET
        method (str): HTTP метод ('GET' или 'POST')
        data (Dict[str, Any], optional): Данные для POST запроса
    
    Returns:
        Dict[str, Any]: Ответ от API в формате JSON
    """
    config = load_config()
    
    # Определяем правильный базовый URL в зависимости от типа API
    if endpoint.startswith('/content/'):
        base_url = 'https://content-api.wildberries.ru'
        logger.info(f"Используем Content API: {base_url}")
    elif endpoint.startswith('/marketplace/'):
        base_url = 'https://marketplace-api.wildberries.ru'
        logger.info(f"Используем Marketplace API: {base_url}")
    elif endpoint.startswith('/analytics/'):
        base_url = 'https://analytics-api.wildberries.ru'
        logger.info(f"Используем Analytics API: {base_url}")
    else:
        # Statistics API (по умолчанию)
        base_url = config['WB_API_URL']
        logger.info(f"Используем Statistics API: {base_url}")
    
    url = f"{base_url}{endpoint}"
    headers = {
        'Authorization': f"Bearer {config['WB_API_KEY']}",
        'Content-Type': 'application/json'
    }
    
    logger.info(f"Выполняем {method} запрос к {url}")
    
    try:
        if method.upper() == 'POST':
            response = requests.post(url, headers=headers, json=data or {}, timeout=30)
        else:
            response = requests.get(url, headers=headers, params=params or {}, timeout=30)
            
        response.raise_for_status()
        
        logger.info(f"Успешный {method} запрос к {endpoint}")
        return response.json()
        
    except requests.exceptions.RequestException as e:
        logger.error(f"Ошибка {method} запроса к API Wildberries {endpoint}: {e}")
        logger.error(f"URL: {url}")
        logger.error(f"Headers: {dict(headers)}")
        raise


def get_sales_from_api(start_date: str, end_date: str) -> List[Dict[str, Any]]:
    """
    Получает данные о продажах и возвратах из API Wildberries с пагинацией.
    
    Args:
        start_date (str): Начальная дата в формате 'YYYY-MM-DD'
        end_date (str): Конечная дата в формате 'YYYY-MM-DD'
    
    Returns:
        List[Dict[str, Any]]: Список продаж и возвратов
    """
    logger.info(f"Начинаем загрузку продаж WB с {start_date} по {end_date}")
    
    all_sales = []
    current_date = start_date
    
    # Конвертируем даты в RFC3339 формат
    date_from = f"{start_date}T00:00:00Z"
    
    while True:
        params = {
            'dateFrom': date_from
        }
        
        logger.info(f"Запрашиваем продажи с {date_from}")
        
        try:
            # Делаем запрос к API
            response = make_wb_request('/api/v1/supplier/sales', params)
            
            # Проверяем, что ответ содержит данные
            if not isinstance(response, list):
                logger.warning(f"Неожиданный формат ответа API: {type(response)}")
                break
            
            sales_batch = response
            
            if not sales_batch:
                logger.info("Получен пустой ответ, завершаем загрузку")
                break
            
            all_sales.extend(sales_batch)
            logger.info(f"Загружено {len(sales_batch)} записей, всего: {len(all_sales)}")
            
            # Сохраняем сырые данные
            save_raw_events(sales_batch, 'wb_sale')
            
            # Если получили максимальное количество записей (80000), 
            # нужно продолжить с последней даты
            if len(sales_batch) >= 80000:
                # Находим последнюю дату в полученных данных
                last_sale = sales_batch[-1]
                last_date = last_sale.get('date', '')
                
                if last_date:
                    date_from = last_date
                    logger.info(f"Продолжаем с даты: {date_from}")
                else:
                    logger.warning("Не удалось определить последнюю дату, прерываем загрузку")
                    break
            else:
                # Если записей меньше максимума, это последняя порция
                break
            
            # Ограничение API: 1 запрос в минуту
            logger.info("Ожидание 61 секунда согласно ограничениям API...")
            time.sleep(61)
            
        except Exception as e:
            logger.error(f"Ошибка при получении продаж: {e}")
            break
    
    logger.info(f"Загрузка продаж завершена. Всего записей: {len(all_sales)}")
    return all_sales


def save_raw_events(events: List[Dict[str, Any]], event_type: str) -> None:
    """
    Сохраняет сырые данные в таблицу raw_events.
    
    Args:
        events (List[Dict[str, Any]]): Список событий для сохранения
        event_type (str): Тип события (например, 'wb_sale')
    """
    if not events:
        return
    
    connection = connect_to_db()
    
    try:
        cursor = connection.cursor(dictionary=True)
        for event in events:
            # Определяем ext_id в зависимости от типа события
            if event_type == 'wb_sale':
                ext_id = event.get('srid', event.get('saleID', ''))
            elif event_type == 'wb_finance_detail':
                ext_id = event.get('realizationreport_id', event.get('rrd_id', ''))
            else:
                ext_id = event.get('id', '')
            
            raw_data = {
                'ext_id': str(ext_id),
                'event_type': event_type,
                'payload': json.dumps(event, ensure_ascii=False),
                'ingested_at': datetime.now()
            }
            
            # SQL запрос с IGNORE для избежания дублей
            sql = """
            INSERT IGNORE INTO raw_events (ext_id, event_type, payload, ingested_at)
            VALUES (%(ext_id)s, %(event_type)s, %(payload)s, %(ingested_at)s)
            """
            
            cursor.execute(sql, raw_data)
        cursor.close()
        
        logger.info(f"Сохранено {len(events)} событий типа {event_type} в raw_events")
            
    except Exception as e:
        logger.error(f"Ошибка сохранения сырых данных: {e}")
        raise
    finally:
        connection.close()


def transform_sale_data(sale_item: Dict[str, Any], client_id: int, source_id: int) -> List[Dict[str, Any]]:
    """
    Преобразует данные продажи/возврата из API WB в формат для таблицы fact_orders.
    
    Args:
        sale_item (Dict[str, Any]): Данные продажи из API
        client_id (int): ID клиента
        source_id (int): ID источника
    
    Returns:
        List[Dict[str, Any]]: Список записей для fact_orders (обычно одна запись)
    """
    connection = connect_to_db()
    orders_list = []
    
    try:
        cursor = connection.cursor(dictionary=True)
        
        # Извлекаем информацию из API ответа
        supplier_article = sale_item.get('supplierArticle', '')
        barcode = sale_item.get('barcode', '')
        sale_id = sale_item.get('saleID', sale_item.get('srid', ''))
        
        # Парсим дату продажи
        sale_date_str = sale_item.get('date', '')
        sale_date = sale_date_str[:10] if sale_date_str else ''
        
        if not sale_id:
            logger.warning(f"Пропущена запись: отсутствует ID продажи")
            return []
        
        # Получаем product_id и cost_price из dim_products
        # Ищем по артикулу поставщика или штрихкоду
        sql = """
        SELECT id, cost_price FROM dim_products 
        WHERE sku_ozon = %s OR barcode = %s
        LIMIT 1
        """
        cursor.execute(sql, (supplier_article, barcode))
        product_info = cursor.fetchone()
        
        if not product_info:
            logger.warning(f"Товар с артикулом {supplier_article} или штрихкодом {barcode} не найден в dim_products")
            product_info = {'id': None, 'cost_price': None}
        
        # Определяем тип транзакции
        transaction_type = 'продажа'
        if sale_item.get('isCancel', False):
            transaction_type = 'возврат'
        elif not sale_item.get('isRealization', True):
            transaction_type = 'возврат'
        
        # Извлекаем числовые значения
        try:
            quantity = int(sale_item.get('quantity', 0))
            # В WB API цена может быть в разных полях
            price = float(sale_item.get('totalPrice', sale_item.get('priceWithDisc', 0)))
        except (ValueError, TypeError) as e:
            logger.warning(f"Ошибка парсинга числовых значений для продажи {sale_id}: {e}")
            quantity = 0
            price = 0.0
        
        # Формируем запись для fact_orders
        order_record = {
            'product_id': product_info['id'],
            'order_id': str(sale_id),
            'transaction_type': transaction_type,
            'sku': supplier_article,
            'qty': quantity,
            'price': price,
            'order_date': sale_date,
            'cost_price': product_info['cost_price'],
            'client_id': client_id,
            'source_id': source_id
        }
        
        orders_list.append(order_record)
        cursor.close()
        
        logger.debug(f"Преобразована продажа {sale_id}, тип: {transaction_type}")
        
    except Exception as e:
        logger.error(f"Ошибка преобразования данных продажи: {e}")
        raise
    finally:
        connection.close()
    
    return orders_list


def load_orders_to_db(orders_list: List[Dict[str, Any]]) -> None:
    """
    Загружает список заказов в таблицу fact_orders.
    
    Args:
        orders_list (List[Dict[str, Any]]): Список записей заказов
    """
    if not orders_list:
        logger.warning("Нет заказов для загрузки в базу данных")
        return
    
    connection = connect_to_db()
    
    try:
        cursor = connection.cursor(dictionary=True)
        # SQL запрос с логикой INSERT ... ON DUPLICATE KEY UPDATE
        sql = """
        INSERT INTO fact_orders (product_id, order_id, transaction_type, sku, qty, price, order_date, cost_price, client_id, source_id)
        VALUES (%(product_id)s, %(order_id)s, %(transaction_type)s, %(sku)s, %(qty)s, %(price)s, %(order_date)s, %(cost_price)s, %(client_id)s, %(source_id)s)
        ON DUPLICATE KEY UPDATE
            qty = VALUES(qty),
            price = VALUES(price),
            cost_price = VALUES(cost_price)
        """
        
        # Выполняем массовую вставку
        cursor.executemany(sql, orders_list)
        cursor.close()
        
        logger.info(f"Успешно загружено {len(orders_list)} записей заказов в fact_orders")
            
    except Exception as e:
        logger.error(f"Ошибка загрузки заказов в базу данных: {e}")
        raise
    finally:
        connection.close()


def get_financial_details_api(start_date: str, end_date: str) -> List[Dict[str, Any]]:
    """
    Получает финансовые детали из API Wildberries с пагинацией.
    
    Args:
        start_date (str): Начальная дата в формате 'YYYY-MM-DD'
        end_date (str): Конечная дата в формате 'YYYY-MM-DD'
    
    Returns:
        List[Dict[str, Any]]: Список финансовых деталей
    """
    logger.info(f"Начинаем загрузку финансовых деталей WB с {start_date} по {end_date}")
    
    all_details = []
    rrd_id = 0  # Начинаем с 0 для первого запроса
    
    while True:
        params = {
            'dateFrom': start_date,
            'dateTo': end_date,
            'rrdid': rrd_id
        }
        
        logger.info(f"Запрашиваем финансовые детали с rrdid: {rrd_id}")
        
        try:
            # Делаем запрос к API
            response = make_wb_request('/api/v5/supplier/reportDetailByPeriod', params)
            
            # Проверяем, что ответ содержит данные
            if not isinstance(response, list):
                logger.warning(f"Неожиданный формат ответа API: {type(response)}")
                break
            
            details_batch = response
            
            if not details_batch:
                logger.info("Получен пустой ответ, завершаем загрузку")
                break
            
            all_details.extend(details_batch)
            logger.info(f"Загружено {len(details_batch)} записей, всего: {len(all_details)}")
            
            # Сохраняем сырые данные
            save_raw_events(details_batch, 'wb_finance_detail')
            
            # Получаем ID последней записи для следующего запроса
            last_detail = details_batch[-1]
            last_rrd_id = last_detail.get('rrd_id', 0)
            
            if last_rrd_id <= rrd_id:
                # Если ID не увеличился, прерываем цикл
                logger.info("ID последней записи не изменился, завершаем загрузку")
                break
            
            rrd_id = last_rrd_id
            
            # Ограничение API: 1 запрос в минуту
            logger.info("Ожидание 61 секунда согласно ограничениям API...")
            time.sleep(61)
            
        except Exception as e:
            logger.error(f"Ошибка при получении финансовых деталей: {e}")
            break
    
    logger.info(f"Загрузка финансовых деталей завершена. Всего записей: {len(all_details)}")
    return all_details


def transform_finance_data(finance_item: Dict[str, Any], client_id: int, source_id: int) -> List[Dict[str, Any]]:
    """
    Преобразует данные финансового отчета из API WB в формат для таблицы fact_transactions.
    Каждая финансовая операция становится отдельной строкой.
    
    Args:
        finance_item (Dict[str, Any]): Данные финансового отчета из API
        client_id (int): ID клиента
        source_id (int): ID источника
    
    Returns:
        List[Dict[str, Any]]: Список записей для fact_transactions
    """
    transactions_list = []
    
    try:
        # Общие данные для всех транзакций
        realizationreport_id = finance_item.get('realizationreport_id', '')
        order_id = finance_item.get('srid', finance_item.get('odid', ''))
        transaction_date = finance_item.get('date_from', '')[:10] if finance_item.get('date_from') else ''
        
        # Список финансовых операций для извлечения
        financial_operations = [
            ('ppvz_for_pay', 'К доплате за товар'),
            ('ppvz_sales_commission', 'Комиссия за продажу'),
            ('ppvz_reward', 'Вознаграждение'),
            ('acquiring_fee', 'Эквайринг'),
            ('ppvz_vw', 'Возмещение издержек по эквайрингу'),
            ('ppvz_vw_nds', 'НДС с возмещения издержек'),
            ('ppvz_office_id', 'Офис'),
            ('ppvz_office_name', 'Название офиса'),
            ('ppvz_supplier_id', 'ID поставщика'),
            ('ppvz_supplier_name', 'Название поставщика'),
            ('ppvz_inn', 'ИНН'),
            ('declaration_number', 'Номер декларации'),
            ('bonus_type_name', 'Тип бонуса'),
            ('sticker_id', 'ID стикера'),
            ('site_country', 'Страна'),
            ('penalty', 'Штраф'),
            ('additional_payment', 'Доплата'),
            ('storage_fee', 'Плата за хранение'),
            ('deduction', 'Удержания'),
            ('acceptance', 'Приёмка'),
        ]
        
        for field_name, description in financial_operations:
            amount = finance_item.get(field_name, 0)
            
            # Пропускаем нулевые и пустые значения
            if not amount or amount == 0:
                continue
            
            try:
                amount = float(amount)
            except (ValueError, TypeError):
                continue
            
            # Определяем, является ли операция расходом
            expense_fields = [
                'ppvz_sales_commission', 'acquiring_fee', 'penalty', 
                'storage_fee', 'deduction', 'acceptance'
            ]
            
            # Расходы должны быть отрицательными
            if field_name in expense_fields and amount > 0:
                amount = -amount
            
            # Формируем уникальный ID транзакции
            transaction_id = f"{realizationreport_id}_{field_name}"
            
            transaction_record = {
                'transaction_id': transaction_id,
                'order_id': str(order_id),
                'transaction_type': field_name,
                'amount': amount,
                'transaction_date': transaction_date,
                'description': description,
                'client_id': client_id,
                'source_id': source_id
            }
            
            transactions_list.append(transaction_record)
        
        logger.debug(f"Преобразован финансовый отчет {realizationreport_id}, создано {len(transactions_list)} транзакций")
        
    except Exception as e:
        logger.error(f"Ошибка преобразования финансовых данных: {e}")
        raise
    
    return transactions_list


def load_transactions_to_db(transactions_list: List[Dict[str, Any]]) -> None:
    """
    Загружает список транзакций в таблицу fact_transactions.
    
    Args:
        transactions_list (List[Dict[str, Any]]): Список транзакций
    """
    if not transactions_list:
        logger.warning("Нет транзакций для загрузки в базу данных")
        return
    
    connection = connect_to_db()
    
    try:
        cursor = connection.cursor(dictionary=True)
        # SQL запрос с логикой INSERT ... ON DUPLICATE KEY UPDATE
        sql = """
        INSERT INTO fact_transactions (transaction_id, order_id, transaction_type, amount, transaction_date, description, client_id, source_id)
        VALUES (%(transaction_id)s, %(order_id)s, %(transaction_type)s, %(amount)s, %(transaction_date)s, %(description)s, %(client_id)s, %(source_id)s)
        ON DUPLICATE KEY UPDATE
            amount = VALUES(amount),
            description = VALUES(description)
        """
        
        # Выполняем массовую вставку
        cursor.executemany(sql, transactions_list)
        cursor.close()
        
        logger.info(f"Успешно загружено {len(transactions_list)} транзакций в fact_transactions")
            
    except Exception as e:
        logger.error(f"Ошибка загрузки транзакций в базу данных: {e}")
        raise
    finally:
        connection.close()


def import_sales(start_date: str, end_date: str) -> None:
    """
    Полный цикл импорта продаж: получение из API, преобразование и загрузка в БД.
    
    Args:
        start_date (str): Начальная дата в формате 'YYYY-MM-DD'
        end_date (str): Конечная дата в формате 'YYYY-MM-DD'
    """
    logger.info(f"=== Начинаем импорт продаж WB с {start_date} по {end_date} ===")
    
    try:
        # Получаем ID клиента и источника
        client_id = get_client_id_by_name('ТД Манхэттен')
        source_id = get_source_id_by_code('WB')
        
        if not client_id or not source_id:
            raise ValueError("Не удалось получить client_id или source_id")
        
        # Получаем продажи из API (сырые данные уже сохраняются в raw_events)
        sales = get_sales_from_api(start_date, end_date)
        
        # Преобразуем каждую продажу и собираем все записи
        all_orders = []
        for sale in sales:
            orders = transform_sale_data(sale, client_id, source_id)
            all_orders.extend(orders)
        
        # Загружаем в базу данных
        load_orders_to_db(all_orders)
        
        logger.info("=== Импорт продаж WB завершен успешно ===")
        
    except Exception as e:
        logger.error(f"Ошибка при импорте продаж WB: {e}")
        raise


def import_financial_details(start_date: str, end_date: str) -> None:
    """
    Полный цикл импорта финансовых деталей: получение из API, преобразование и загрузка в БД.
    
    Args:
        start_date (str): Начальная дата в формате 'YYYY-MM-DD'
        end_date (str): Конечная дата в формате 'YYYY-MM-DD'
    """
    logger.info(f"=== Начинаем импорт финансовых деталей WB с {start_date} по {end_date} ===")
    
    try:
        # Получаем ID клиента и источника
        client_id = get_client_id_by_name('ТД Манхэттен')
        source_id = get_source_id_by_code('WB')
        
        if not client_id or not source_id:
            raise ValueError("Не удалось получить client_id или source_id")
        
        # Получаем финансовые детали из API (сырые данные уже сохраняются в raw_events)
        financial_details = get_financial_details_api(start_date, end_date)
        
        # Преобразуем каждую запись и собираем все транзакции
        all_transactions = []
        for detail in financial_details:
            transactions = transform_finance_data(detail, client_id, source_id)
            all_transactions.extend(transactions)
        
        # Загружаем в базу данных
        load_transactions_to_db(all_transactions)
        
        logger.info("=== Импорт финансовых деталей WB завершен успешно ===")
        
    except Exception as e:
        logger.error(f"Ошибка при импорте финансовых деталей WB: {e}")
        raise


def get_wb_products_from_api() -> List[Dict[str, Any]]:
    """
    Получает список всех товаров из Content API Wildberries с пагинацией.
    
    Returns:
        List[Dict[str, Any]]: Список товаров
    """
    logger.info("Начинаем загрузку товаров из WB Content API")
    
    all_products = []
    cursor = None
    
    while True:
        # Формируем запрос для получения товаров
        request_data = {
            "settings": {
                "filter": {
                    "withPhoto": -1  # Все товары (с фото и без)
                },
                "cursor": {
                    "limit": 100  # Максимум за один запрос
                }
            }
        }
        
        # Добавляем cursor для пагинации если есть
        if cursor:
            request_data["settings"]["cursor"].update(cursor)
        
        logger.info(f"Запрашиваем товары, лимит: {request_data['settings']['cursor']['limit']}")
        
        try:
            # Делаем POST запрос к Content API
            response = make_wb_request('/content/v2/get/cards/list', method='POST', data=request_data)
            
            # Проверяем структуру ответа
            if not isinstance(response, dict) or 'cards' not in response:
                logger.warning(f"Неожиданный формат ответа API: {response}")
                break
            
            products_batch = response.get('cards', [])
            
            if not products_batch:
                logger.info("Получен пустой ответ, завершаем загрузку")
                break
            
            all_products.extend(products_batch)
            logger.info(f"Загружено {len(products_batch)} товаров, всего: {len(all_products)}")
            
            # Проверяем, есть ли еще данные для загрузки
            cursor_info = response.get('cursor')
            if not cursor_info or len(products_batch) < 100:
                logger.info("Достигнут конец списка товаров")
                break
            
            # Обновляем cursor для следующего запроса
            cursor = {
                'updatedAt': cursor_info.get('updatedAt'),
                'nmID': cursor_info.get('nmID')
            }
            
            logger.info(f"Продолжаем с cursor: {cursor}")
            
            # Ограничение API: 100 запросов в минуту (600ms интервал)
            logger.info("Ожидание 700ms согласно ограничениям API...")
            time.sleep(0.7)
            
        except Exception as e:
            logger.error(f"Ошибка при получении товаров: {e}")
            break
    
    logger.info(f"Загрузка товаров завершена. Всего записей: {len(all_products)}")
    return all_products


def transform_wb_product_data(product_item: Dict[str, Any]) -> Dict[str, Any]:
    """
    Преобразует данные товара из WB Content API в формат для таблицы dim_products.
    
    Args:
        product_item (Dict[str, Any]): Данные товара из API
    
    Returns:
        Dict[str, Any]: Запись для dim_products
    """
    try:
        # Извлекаем основные поля
        nm_id = product_item.get('nmID')  # Артикул WB
        vendor_code = product_item.get('vendorCode', '')  # Артикул продавца
        
        # Получаем название товара
        title = ''
        if 'title' in product_item:
            title = product_item['title']
        elif 'object' in product_item:
            title = product_item['object']
        
        # Получаем бренд
        brand = product_item.get('brand', '')
        
        # Получаем штрихкоды из размеров
        barcodes = []
        sizes = product_item.get('sizes', [])
        for size in sizes:
            barcode = size.get('skus', [])
            if barcode:
                barcodes.extend(barcode)
        
        # Берем первый штрихкод как основной
        main_barcode = barcodes[0] if barcodes else ''
        
        # Получаем категорию
        category = product_item.get('object', '')
        
        # Формируем запись для dim_products
        product_record = {
            'sku_wb': str(nm_id) if nm_id else '',  # Артикул WB
            'sku_ozon': vendor_code,  # Артикул продавца (может совпадать с Ozon)
            'name': title,
            'brand': brand,
            'category': category,
            'barcode': main_barcode,
            'cost_price': None,  # Будет заполнено позже или из других источников
            'created_at': datetime.now(),
            'updated_at': datetime.now()
        }
        
        logger.debug(f"Преобразован товар WB: {nm_id}, артикул: {vendor_code}")
        return product_record
        
    except Exception as e:
        logger.error(f"Ошибка преобразования данных товара WB: {e}")
        logger.error(f"Данные товара: {product_item}")
        raise


def load_wb_products_to_db(products_list: List[Dict[str, Any]]) -> None:
    """
    Загружает список товаров WB в таблицу dim_products.
    Использует barcode для поиска дубликатов и обновления sku_wb.
    
    Args:
        products_list (List[Dict[str, Any]]): Список товаров для загрузки
    """
    if not products_list:
        logger.warning("Нет товаров WB для загрузки в базу данных")
        return
    
    connection = connect_to_db()
    
    try:
        cursor = connection.cursor(dictionary=True)
        
        # SQL запрос с логикой поиска по barcode и обновления sku_wb
        sql = """
        INSERT INTO dim_products (sku_wb, sku_ozon, name, brand, category, barcode, cost_price, created_at, updated_at)
        VALUES (%(sku_wb)s, %(sku_ozon)s, %(name)s, %(brand)s, %(category)s, %(barcode)s, %(cost_price)s, %(created_at)s, %(updated_at)s)
        ON DUPLICATE KEY UPDATE
            sku_wb = VALUES(sku_wb),
            name = COALESCE(NULLIF(name, ''), VALUES(name)),
            brand = COALESCE(NULLIF(brand, ''), VALUES(brand)),
            category = COALESCE(NULLIF(category, ''), VALUES(category)),
            updated_at = VALUES(updated_at)
        """
        
        # Выполняем массовую вставку
        cursor.executemany(sql, products_list)
        affected_rows = cursor.rowcount
        cursor.close()
        
        logger.info(f"Успешно обработано {affected_rows} записей товаров WB в dim_products")
            
    except Exception as e:
        logger.error(f"Ошибка загрузки товаров WB в базу данных: {e}")
        raise
    finally:
        connection.close()


def import_wb_products() -> None:
    """
    Полный цикл импорта товаров WB: получение из Content API, преобразование и загрузка в БД.
    """
    logger.info("=== Начинаем импорт товаров WB ===")
    
    try:
        # Получаем товары из Content API
        products = get_wb_products_from_api()
        
        if not products:
            logger.warning("Не получено товаров из WB API")
            return
        
        # Преобразуем каждый товар
        transformed_products = []
        for product in products:
            try:
                product_record = transform_wb_product_data(product)
                # Пропускаем товары без штрихкода
                if product_record.get('barcode'):
                    transformed_products.append(product_record)
                else:
                    logger.debug(f"Пропущен товар без штрихкода: {product.get('nmID')}")
            except Exception as e:
                logger.warning(f"Ошибка обработки товара {product.get('nmID', 'unknown')}: {e}")
                continue
        
        logger.info(f"Подготовлено {len(transformed_products)} товаров для загрузки")
        
        # Загружаем в базу данных
        load_wb_products_to_db(transformed_products)
        
        logger.info("=== Импорт товаров WB завершен успешно ===")
        
    except Exception as e:
        logger.error(f"Ошибка при импорте товаров WB: {e}")
        raise
