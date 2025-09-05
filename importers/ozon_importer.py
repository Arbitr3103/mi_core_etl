"""
Модуль для импорта данных из API Ozon в базу данных MySQL.

Включает функции для:
- Подключения к базе данных
- Загрузки конфигурации из .env файла
- Получения товаров из API Ozon
- Получения заказов из API Ozon
- Получения финансовых транзакций из API Ozon
"""

import os
import json
import logging
import mysql.connector
from mysql.connector import Error
import requests
import csv
import time
import io
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
        'OZON_CLIENT_ID': os.getenv('OZON_CLIENT_ID'),
        'OZON_API_KEY': os.getenv('OZON_API_KEY'),
        'OZON_API_URL': os.getenv('OZON_API_URL', 'https://api-seller.ozon.ru')
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
            autocommit=True
        )
        
        logger.info(f"Успешное подключение к базе данных {config['DB_NAME']}")
        return connection
        
    except Error as e:
        logger.error(f"Ошибка подключения к базе данных: {e}")
        raise


def make_ozon_request(endpoint: str, data: Dict[str, Any]) -> Dict[str, Any]:
    """
    Выполняет POST-запрос к API Ozon.
    
    Args:
        endpoint (str): Конечная точка API (например, '/v1/product/list')
        data (Dict[str, Any]): Данные для отправки в теле запроса
    
    Returns:
        Dict[str, Any]: Ответ от API в формате JSON
    """
    config = load_config()
    
    url = f"{config['OZON_API_URL']}{endpoint}"
    headers = {
        'Client-Id': config['OZON_CLIENT_ID'],
        'Api-Key': config['OZON_API_KEY'],
        'Content-Type': 'application/json'
    }
    
    try:
        response = requests.post(url, headers=headers, json=data, timeout=30)
        response.raise_for_status()
        
        logger.info(f"Успешный запрос к {endpoint}")
        return response.json()
        
    except requests.exceptions.RequestException as e:
        logger.error(f"Ошибка запроса к API Ozon {endpoint}: {e}")
        raise


def request_products_report() -> str:
    """
    Заказывает отчет по товарам в API Ozon.
    
    Returns:
        str: Код отчета для последующего получения
    """
    logger.info("Заказываем отчет по товарам из API Ozon")
    
    # Данные для запроса согласно документации
    request_data = {
        "language": "DEFAULT",
        "offer_id": [],
        "search": "",
        "sku": [],
        "visibility": "ALL"
    }
    
    try:
        # Выполняем запрос к API
        response = make_ozon_request('/v1/report/products/create', request_data)
        
        # Извлекаем код отчета из ответа
        report_code = response.get('result', {}).get('code')
        
        if not report_code:
            raise ValueError("Не удалось получить код отчета из ответа API")
        
        logger.info(f"Отчет заказан успешно, код: {report_code}")
        return report_code
        
    except Exception as e:
        logger.error(f"Ошибка при заказе отчета по товарам: {e}")
        raise


def get_report_by_code(report_code: str) -> str:
    """
    Проверяет готовность отчета и скачивает его содержимое.
    
    Args:
        report_code (str): Код отчета, полученный от request_products_report()
    
    Returns:
        str: Содержимое CSV-файла с товарами
    """
    logger.info(f"Проверяем готовность отчета {report_code}")
    
    max_attempts = 20  # Максимальное количество попыток
    attempt = 0
    
    while attempt < max_attempts:
        attempt += 1
        
        # Данные для запроса статуса отчета
        request_data = {
            "code": report_code
        }
        
        try:
            # Выполняем запрос к API
            response = make_ozon_request('/v1/report/info', request_data)
            
            # Проверяем статус отчета
            status = response.get('result', {}).get('status')
            
            if status == 'success':
                # Отчет готов, получаем ссылку на файл
                file_url = response.get('result', {}).get('file')
                
                if not file_url:
                    raise ValueError("Не удалось получить ссылку на файл отчета")
                
                logger.info(f"Отчет готов, скачиваем файл: {file_url}")
                
                # Скачиваем CSV-файл с правильной кодировкой
                file_response = requests.get(file_url, timeout=60)
                file_response.raise_for_status()
                
                # Пробуем разные кодировки
                try:
                    # Сначала пробуем UTF-8 с BOM
                    csv_content = file_response.content.decode('utf-8-sig')
                except UnicodeDecodeError:
                    try:
                        # Затем обычный UTF-8
                        csv_content = file_response.content.decode('utf-8')
                    except UnicodeDecodeError:
                        # В крайнем случае CP1251 (Windows-1251)
                        csv_content = file_response.content.decode('cp1251')
                
                logger.info(f"CSV-файл скачан, размер: {len(csv_content)} символов")
                return csv_content
                
            else:
                logger.info(f"Отчет еще формируется (статус: {status}), ждем 15 секунд... (попытка {attempt}/{max_attempts})")
                time.sleep(15)
                
        except Exception as e:
            logger.error(f"Ошибка при проверке статуса отчета (попытка {attempt}): {e}")
            if attempt >= max_attempts:
                raise
            time.sleep(15)
    
    raise TimeoutError(f"Отчет не был готов после {max_attempts} попыток")


def get_products_from_api() -> List[Dict[str, Any]]:
    """
    Получает список всех товаров из API Ozon через систему отчетов.
    
    Returns:
        List[Dict[str, Any]]: Список товаров, распарсенный из CSV-отчета
    """
    logger.info("Начинаем загрузку товаров из API Ozon через отчеты")
    
    try:
        # Этап 1: Заказываем отчет
        report_code = request_products_report()
        
        # Этап 2: Ждем готовности и скачиваем отчет
        csv_content = get_report_by_code(report_code)
        
        # Этап 3: Парсим CSV-содержимое
        logger.info("Парсим CSV-данные товаров")
        
        # Используем StringIO для работы с CSV (с правильным разделителем)
        csv_file = io.StringIO(csv_content)
        csv_reader = csv.DictReader(csv_file, delimiter=';')
        
        products = []
        for row in csv_reader:
            products.append(row)
        
        logger.info(f"Загрузка товаров завершена. Всего товаров: {len(products)}")
        
        # Выводим первые несколько товаров для отладки
        if products:
            logger.info("Пример товара из CSV:")
            sample_product = products[0]
            for key, value in sample_product.items():
                logger.info(f"  {key}: {value}")
        
        return products
        
    except Exception as e:
        logger.error(f"Ошибка при получении товаров через отчеты: {e}")
        raise


def transform_product_data(product_item: Dict[str, Any]) -> Dict[str, Any]:
    """
    Преобразует данные товара из CSV-отчета в формат для таблицы dim_products.
    
    Args:
        product_item (Dict[str, Any]): Данные товара из CSV-отчета
    
    Returns:
        Dict[str, Any]: Преобразованные данные товара
    """
    # Извлекаем данные из CSV-отчета по названиям колонок
    # Убираем кавычки и лишние символы из значений
    def clean_value(value):
        if isinstance(value, str):
            return value.strip().strip('"').strip("'")
        return value or ''
    
    return {
        'sku_ozon': clean_value(product_item.get('Артикул', '')),
        'barcode': clean_value(product_item.get('Barcode', '')),
        'product_name': clean_value(product_item.get('Название товара', '')),
        'cost_price': None  # Оставляем пустым на этом этапе
    }


def load_products_to_db(products_list: List[Dict[str, Any]]) -> None:
    """
    Загружает список товаров в таблицу dim_products.
    
    Args:
        products_list (List[Dict[str, Any]]): Список преобразованных товаров
    """
    if not products_list:
        logger.warning("Нет товаров для загрузки в базу данных")
        return
    
    connection = connect_to_db()
    
    try:
        cursor = connection.cursor()
        # SQL запрос с логикой INSERT ... ON DUPLICATE KEY UPDATE
        sql = """
        INSERT INTO dim_products (sku_ozon, barcode, product_name, cost_price)
        VALUES (%(sku_ozon)s, %(barcode)s, %(product_name)s, %(cost_price)s)
        ON DUPLICATE KEY UPDATE
            product_name = VALUES(product_name),
            barcode = VALUES(barcode)
        """
        
        # Выполняем массовую вставку
        cursor.executemany(sql, products_list)
        cursor.close()
        
        logger.info(f"Успешно загружено {len(products_list)} товаров в dim_products")
            
    except Exception as e:
        logger.error(f"Ошибка загрузки товаров в базу данных: {e}")
        raise
    finally:
        connection.close()


def get_postings_from_api(start_date: str, end_date: str) -> List[Dict[str, Any]]:
    """
    Получает список заказов (отправлений) из API Ozon за указанный период.
    
    Args:
        start_date (str): Начальная дата в формате 'YYYY-MM-DD'
        end_date (str): Конечная дата в формате 'YYYY-MM-DD'
    
    Returns:
        List[Dict[str, Any]]: Список заказов
    """
    logger.info(f"Начинаем загрузку заказов с {start_date} по {end_date}")
    
    all_postings = []
    offset = 0
    limit = 1000  # Максимальное количество заказов за один запрос
    
    while True:
        # Формируем данные для запроса
        request_data = {
            "dir": "ASC",
            "filter": {
                "since": f"{start_date}T00:00:00.000Z",
                "to": f"{end_date}T23:59:59.999Z",
                "status": ""
            },
            "limit": limit,
            "offset": offset,
            "with": {
                "analytics_data": True,
                "financial_data": True
            }
        }
        
        # Выполняем запрос к API (используем FBS метод)
        response = make_ozon_request('/v3/posting/fbs/list', request_data)
        
        # Извлекаем заказы из ответа
        postings = response.get('result', {}).get('postings', [])
        
        if not postings:
            break
            
        all_postings.extend(postings)
        
        # Сохраняем сырые данные в raw_events
        save_raw_events(postings, 'ozon_posting')
        
        offset += limit
        
        logger.info(f"Загружено {len(postings)} заказов, всего: {len(all_postings)}")
        
        # Если заказов меньше лимита, значит это последняя страница
        if len(postings) < limit:
            break
    
    logger.info(f"Загрузка заказов завершена. Всего заказов: {len(all_postings)}")
    return all_postings


def save_raw_events(events: List[Dict[str, Any]], event_type: str) -> None:
    """
    Сохраняет сырые данные в таблицу raw_events.
    
    Args:
        events (List[Dict[str, Any]]): Список событий для сохранения
        event_type (str): Тип события (например, 'ozon_posting')
    """
    if not events:
        return
    
    connection = connect_to_db()
    
    try:
        cursor = connection.cursor()
        for event in events:
            # Формируем данные для вставки
            raw_data = {
                'event_id': event.get('posting_number', event.get('operation_id', '')),
                'event_type': event_type,
                'payload': json.dumps(event, ensure_ascii=False),
                'ingested_at': datetime.now()
            }
            
            # SQL запрос с IGNORE для избежания дублей
            sql = """
            INSERT IGNORE INTO raw_events (event_id, event_type, payload, ingested_at)
            VALUES (%(event_id)s, %(event_type)s, %(payload)s, %(ingested_at)s)
            """
            
            cursor.execute(sql, raw_data)
        cursor.close()
        
        logger.info(f"Сохранено {len(events)} событий типа {event_type} в raw_events")
            
    except Exception as e:
        logger.error(f"Ошибка сохранения сырых данных: {e}")
        raise
    finally:
        connection.close()


def transform_posting_data(posting_json: Dict[str, Any]) -> List[Dict[str, Any]]:
    """
    Преобразует данные заказа из API в формат для таблицы fact_orders.
    Создает отдельную запись для каждого товара в заказе.
    
    Args:
        posting_json (Dict[str, Any]): Данные заказа из API
    
    Returns:
        List[Dict[str, Any]]: Список записей для fact_orders
    """
    connection = connect_to_db()
    orders_list = []
    
    try:
        cursor = connection.cursor()
        # Извлекаем основную информацию о заказе
        order_id = posting_json.get('posting_number', '')
        order_date = posting_json.get('created_at', '')[:10]  # Берем только дату
        
        # Проходим по каждому товару в заказе
        for product in posting_json.get('products', []):
            sku_ozon = product.get('offer_id', '')
            
            # Получаем product_id и cost_price из dim_products
            sql = "SELECT id, cost_price FROM dim_products WHERE sku_ozon = %s"
            cursor.execute(sql, (sku_ozon,))
            product_info = cursor.fetchone()
            
            if not product_info:
                logger.warning(f"Товар с SKU {sku_ozon} не найден в dim_products")
                continue
            
            # Формируем запись для fact_orders (product_info теперь кортеж)
            order_record = {
                'product_id': product_info[0],  # id
                'order_id': order_id,
                'transaction_type': 'продажа',
                'sku': sku_ozon,
                'qty': product.get('quantity', 0),
                'price': float(product.get('price', 0)),
                'order_date': order_date,
                'cost_price': product_info[1]  # cost_price
            }
            
            orders_list.append(order_record)
        cursor.close()
        
        logger.info(f"Преобразован заказ {order_id}, создано {len(orders_list)} записей")
        
    except Exception as e:
        logger.error(f"Ошибка преобразования данных заказа: {e}")
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
        cursor = connection.cursor()
        # SQL запрос с логикой INSERT ... ON DUPLICATE KEY UPDATE
        sql = """
        INSERT INTO fact_orders (product_id, order_id, transaction_type, sku, qty, price, order_date, cost_price)
        VALUES (%(product_id)s, %(order_id)s, %(transaction_type)s, %(sku)s, %(qty)s, %(price)s, %(order_date)s, %(cost_price)s)
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


def get_transactions_from_api(start_date: str, end_date: str) -> List[Dict[str, Any]]:
    """
    Получает список финансовых транзакций из API Ozon за указанный период.
    
    Args:
        start_date (str): Начальная дата в формате 'YYYY-MM-DD'
        end_date (str): Конечная дата в формате 'YYYY-MM-DD'
    
    Returns:
        List[Dict[str, Any]]: Список транзакций
    """
    logger.info(f"Начинаем загрузку транзакций с {start_date} по {end_date}")
    
    all_transactions = []
    page = 1
    page_size = 1000
    
    while True:
        # Формируем данные для запроса
        request_data = {
            "filter": {
                "date": {
                    "from": f"{start_date}T00:00:00.000Z",
                    "to": f"{end_date}T23:59:59.999Z"
                },
                "operation_type": [],
                "posting_number": "",
                "transaction_type": "all"
            },
            "page": page,
            "page_size": page_size
        }
        
        # Выполняем запрос к API
        response = make_ozon_request('/v3/finance/transaction/list', request_data)
        
        # Извлекаем транзакции из ответа
        operations = response.get('result', {}).get('operations', [])
        
        if not operations:
            break
            
        all_transactions.extend(operations)
        
        # Сохраняем сырые данные в raw_events
        save_raw_events(operations, 'ozon_transaction')
        
        page += 1
        
        logger.info(f"Загружено {len(operations)} транзакций, всего: {len(all_transactions)}")
        
        # Если транзакций меньше размера страницы, значит это последняя страница
        if len(operations) < page_size:
            break
    
    logger.info(f"Загрузка транзакций завершена. Всего транзакций: {len(all_transactions)}")
    return all_transactions


def transform_transaction_data(transaction_item: Dict[str, Any]) -> Dict[str, Any]:
    """
    Преобразует данные транзакции из API в формат для таблицы fact_transactions.
    
    Args:
        transaction_item (Dict[str, Any]): Данные транзакции из API
    
    Returns:
        Dict[str, Any]: Преобразованные данные транзакции
    """
    # Определяем тип транзакции
    operation_type = transaction_item.get('operation_type', '')
    
    # Получаем сумму (расходы должны быть отрицательными)
    amount = float(transaction_item.get('amount', 0))
    
    # Список типов операций, которые являются расходами
    expense_operations = [
        'OperationMarketplaceServiceItemFulfillment',
        'OperationMarketplaceServiceItemPickup',
        'OperationMarketplaceServiceItemDropoffPVZ',
        'OperationMarketplaceServiceItemDropoffSC',
        'OperationMarketplaceServiceItemDropoffFF',
        'OperationMarketplaceServiceItemDirectFlowTrans',
        'OperationMarketplaceServiceItemReturnFlowTrans',
        'OperationMarketplaceServiceItemDelivToCustomer',
        'OperationMarketplaceServiceItemReturnNotDelivToCustomer',
        'OperationMarketplaceServiceItemReturnPartGoodsCustomer',
        'OperationMarketplaceServiceItemRedistributionReturnsPVZ',
        'OperationMarketplaceServiceItemReturnAfterDelivToCustomer'
    ]
    
    # Если это расходная операция, делаем сумму отрицательной
    if operation_type in expense_operations and amount > 0:
        amount = -amount
    
    return {
        'transaction_id': transaction_item.get('operation_id', ''),
        'order_id': transaction_item.get('posting', {}).get('posting_number', ''),
        'transaction_type': operation_type,
        'amount': amount,
        'transaction_date': transaction_item.get('operation_date', '')[:10],  # Берем только дату
        'description': transaction_item.get('operation_type_name', '')
    }


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
        cursor = connection.cursor()
        # SQL запрос с логикой INSERT ... ON DUPLICATE KEY UPDATE
        sql = """
        INSERT INTO fact_transactions (transaction_id, order_id, transaction_type, amount, transaction_date, description)
        VALUES (%(transaction_id)s, %(order_id)s, %(transaction_type)s, %(amount)s, %(transaction_date)s, %(description)s)
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


def import_products() -> None:
    """
    Полный цикл импорта товаров: получение из API, преобразование и загрузка в БД.
    """
    logger.info("=== Начинаем импорт товаров ===")
    
    try:
        # Получаем товары из API
        products = get_products_from_api()
        
        # Преобразуем данные
        transformed_products = [transform_product_data(product) for product in products]
        
        # Загружаем в базу данных
        load_products_to_db(transformed_products)
        
        logger.info("=== Импорт товаров завершен успешно ===")
        
    except Exception as e:
        logger.error(f"Ошибка при импорте товаров: {e}")
        raise


def import_orders(start_date: str, end_date: str) -> None:
    """
    Полный цикл импорта заказов: получение из API, преобразование и загрузка в БД.
    
    Args:
        start_date (str): Начальная дата в формате 'YYYY-MM-DD'
        end_date (str): Конечная дата в формате 'YYYY-MM-DD'
    """
    logger.info(f"=== Начинаем импорт заказов с {start_date} по {end_date} ===")
    
    try:
        # Получаем заказы из API (сырые данные уже сохраняются в raw_events)
        postings = get_postings_from_api(start_date, end_date)
        
        # Преобразуем каждый заказ и собираем все записи
        all_orders = []
        for posting in postings:
            orders = transform_posting_data(posting)
            all_orders.extend(orders)
        
        # Загружаем в базу данных
        load_orders_to_db(all_orders)
        
        logger.info("=== Импорт заказов завершен успешно ===")
        
    except Exception as e:
        logger.error(f"Ошибка при импорте заказов: {e}")
        raise


def import_transactions(start_date: str, end_date: str) -> None:
    """
    Полный цикл импорта транзакций: получение из API, преобразование и загрузка в БД.
    
    Args:
        start_date (str): Начальная дата в формате 'YYYY-MM-DD'
        end_date (str): Конечная дата в формате 'YYYY-MM-DD'
    """
    logger.info(f"=== Начинаем импорт транзакций с {start_date} по {end_date} ===")
    
    try:
        # Получаем транзакции из API (сырые данные уже сохраняются в raw_events)
        transactions = get_transactions_from_api(start_date, end_date)
        
        # Преобразуем данные
        transformed_transactions = [transform_transaction_data(transaction) for transaction in transactions]
        
        # Загружаем в базу данных
        load_transactions_to_db(transformed_transactions)
        
        logger.info("=== Импорт транзакций завершен успешно ===")
        
    except Exception as e:
        logger.error(f"Ошибка при импорте транзакций: {e}")
        raise
