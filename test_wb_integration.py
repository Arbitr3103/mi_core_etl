#!/usr/bin/env python3
"""
Тест полной интеграции Wildberries с базой данных.
Проверяет весь цикл: API -> трансформация -> загрузка в БД.
"""

import sys
import os
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from wb_importer import (
    load_config, connect_to_db, get_client_id_by_name, get_source_id_by_code,
    import_sales, import_financial_details, logger
)

def test_database_setup():
    """Проверяет готовность базы данных."""
    logger.info("=== Проверка готовности БД ===")
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # Проверяем наличие необходимых таблиц
        tables_to_check = ['clients', 'sources', 'fact_orders', 'fact_transactions', 'raw_events']
        
        for table in tables_to_check:
            cursor.execute(f"SHOW TABLES LIKE '{table}'")
            result = cursor.fetchone()
            if not result:
                logger.error(f"❌ Таблица {table} не найдена")
                return False
            else:
                logger.info(f"✅ Таблица {table} найдена")
        
        # Проверяем наличие client_id и source_id в fact_transactions
        cursor.execute("DESCRIBE fact_transactions")
        columns = [row['Field'] for row in cursor.fetchall()]
        
        if 'client_id' not in columns or 'source_id' not in columns:
            logger.error("❌ В таблице fact_transactions отсутствуют поля client_id или source_id")
            logger.error("Выполните миграцию: mysql < migrate_fact_transactions.sql")
            return False
        
        logger.info("✅ Поля client_id и source_id найдены в fact_transactions")
        
        cursor.close()
        connection.close()
        
        logger.info("✅ База данных готова к работе")
        return True
        
    except Exception as e:
        logger.error(f"❌ Ошибка проверки БД: {e}")
        return False


def test_reference_data():
    """Проверяет наличие справочных данных."""
    logger.info("=== Проверка справочных данных ===")
    
    try:
        client_id = get_client_id_by_name('ТД Манхэттен')
        source_id = get_source_id_by_code('WB')
        
        if not client_id:
            logger.error("❌ Клиент 'ТД Манхэттен' не найден")
            logger.error("Выполните: mysql < setup_wb_data.sql")
            return False
        
        if not source_id:
            logger.error("❌ Источник 'WB' не найден")
            logger.error("Выполните: mysql < setup_wb_data.sql")
            return False
        
        logger.info(f"✅ client_id: {client_id}, source_id: {source_id}")
        return True
        
    except Exception as e:
        logger.error(f"❌ Ошибка проверки справочных данных: {e}")
        return False


def test_sales_import():
    """Тестирует импорт продаж за вчерашний день."""
    logger.info("=== Тест импорта продаж ===")
    
    try:
        # Импортируем за вчерашний день
        yesterday = datetime.now() - timedelta(days=1)
        test_date = yesterday.strftime('%Y-%m-%d')
        
        logger.info(f"Импортируем продажи за {test_date}")
        
        # Считаем записи до импорта
        connection = connect_to_db()
        cursor = connection.cursor()
        cursor.execute("SELECT COUNT(*) as cnt FROM fact_orders WHERE source_id = (SELECT id FROM sources WHERE code = 'WB')")
        count_before = cursor.fetchone()[0]
        
        # Выполняем импорт
        import_sales(test_date, test_date)
        
        # Считаем записи после импорта
        cursor.execute("SELECT COUNT(*) as cnt FROM fact_orders WHERE source_id = (SELECT id FROM sources WHERE code = 'WB')")
        count_after = cursor.fetchone()[0]
        
        cursor.close()
        connection.close()
        
        imported_count = count_after - count_before
        logger.info(f"✅ Импортировано продаж: {imported_count}")
        
        return True
        
    except Exception as e:
        logger.error(f"❌ Ошибка импорта продаж: {e}")
        return False


def test_finance_import():
    """Тестирует импорт финансовых деталей за вчерашний день."""
    logger.info("=== Тест импорта финансовых деталей ===")
    
    try:
        # Импортируем за вчерашний день
        yesterday = datetime.now() - timedelta(days=1)
        test_date = yesterday.strftime('%Y-%m-%d')
        
        logger.info(f"Импортируем финансовые детали за {test_date}")
        
        # Считаем записи до импорта
        connection = connect_to_db()
        cursor = connection.cursor()
        cursor.execute("SELECT COUNT(*) as cnt FROM fact_transactions WHERE source_id = (SELECT id FROM sources WHERE code = 'WB')")
        count_before = cursor.fetchone()[0]
        
        # Выполняем импорт
        import_financial_details(test_date, test_date)
        
        # Считаем записи после импорта
        cursor.execute("SELECT COUNT(*) as cnt FROM fact_transactions WHERE source_id = (SELECT id FROM sources WHERE code = 'WB')")
        count_after = cursor.fetchone()[0]
        
        cursor.close()
        connection.close()
        
        imported_count = count_after - count_before
        logger.info(f"✅ Импортировано транзакций: {imported_count}")
        
        return True
        
    except Exception as e:
        logger.error(f"❌ Ошибка импорта финансовых деталей: {e}")
        return False


def test_data_quality():
    """Проверяет качество загруженных данных."""
    logger.info("=== Проверка качества данных ===")
    
    try:
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        # Проверяем продажи
        cursor.execute("""
            SELECT 
                COUNT(*) as total_orders,
                COUNT(DISTINCT order_id) as unique_orders,
                SUM(CASE WHEN transaction_type = 'продажа' THEN 1 ELSE 0 END) as sales,
                SUM(CASE WHEN transaction_type = 'возврат' THEN 1 ELSE 0 END) as returns
            FROM fact_orders 
            WHERE source_id = (SELECT id FROM sources WHERE code = 'WB')
        """)
        
        orders_stats = cursor.fetchone()
        logger.info(f"📊 Статистика заказов WB:")
        logger.info(f"  Всего записей: {orders_stats['total_orders']}")
        logger.info(f"  Уникальных заказов: {orders_stats['unique_orders']}")
        logger.info(f"  Продажи: {orders_stats['sales']}")
        logger.info(f"  Возвраты: {orders_stats['returns']}")
        
        # Проверяем транзакции
        cursor.execute("""
            SELECT 
                COUNT(*) as total_transactions,
                COUNT(DISTINCT transaction_id) as unique_transactions,
                SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) as positive_amounts,
                SUM(CASE WHEN amount < 0 THEN 1 ELSE 0 END) as negative_amounts
            FROM fact_transactions 
            WHERE source_id = (SELECT id FROM sources WHERE code = 'WB')
        """)
        
        trans_stats = cursor.fetchone()
        logger.info(f"📊 Статистика транзакций WB:")
        logger.info(f"  Всего записей: {trans_stats['total_transactions']}")
        logger.info(f"  Уникальных транзакций: {trans_stats['unique_transactions']}")
        logger.info(f"  Положительные суммы: {trans_stats['positive_amounts']}")
        logger.info(f"  Отрицательные суммы: {trans_stats['negative_amounts']}")
        
        cursor.close()
        connection.close()
        
        logger.info("✅ Проверка качества данных завершена")
        return True
        
    except Exception as e:
        logger.error(f"❌ Ошибка проверки качества данных: {e}")
        return False


def main():
    """Главная функция тестирования."""
    logger.info("🧪 Тест полной интеграции Wildberries")
    logger.info("=" * 50)
    
    tests_passed = 0
    tests_total = 0
    
    # Тест готовности БД
    tests_total += 1
    if test_database_setup():
        tests_passed += 1
    
    logger.info("-" * 30)
    
    # Тест справочных данных
    tests_total += 1
    if test_reference_data():
        tests_passed += 1
    
    logger.info("-" * 30)
    
    # Тест импорта продаж
    tests_total += 1
    if test_sales_import():
        tests_passed += 1
    
    logger.info("-" * 30)
    
    # Тест импорта финансов
    tests_total += 1
    if test_finance_import():
        tests_passed += 1
    
    logger.info("-" * 30)
    
    # Тест качества данных
    tests_total += 1
    if test_data_quality():
        tests_passed += 1
    
    # Итоги
    logger.info("=" * 50)
    logger.info(f"🏁 Результат: {tests_passed}/{tests_total} тестов прошли успешно")
    
    if tests_passed == tests_total:
        logger.info("🎉 Полная интеграция Wildberries работает корректно!")
        logger.info("💡 Можно запускать: python main.py --source=wb --last-7-days")
        return 0
    else:
        logger.error("❌ Есть проблемы с интеграцией")
        logger.error("🔧 Проверьте настройки БД и выполните необходимые миграции")
        return 1


if __name__ == "__main__":
    exit_code = main()
    sys.exit(exit_code)
