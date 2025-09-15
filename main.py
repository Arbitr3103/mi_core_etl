#!/usr/bin/env python3
"""
Главный модуль для запуска импорта данных из API маркетплейсов (Ozon, Wildberries).

Использование:
    python main.py                    # Импорт товаров и заказов за вчера (Ozon)
    python main.py --source=wb        # Импорт данных из Wildberries
    python main.py --last-7-days      # Импорт за последние 7 дней (для cron)
    python main.py --start-date 2024-01-01 --end-date 2024-01-31  # За указанный период
    python main.py --products-only    # Только товары (только для Ozon)
    python main.py --orders-only --start-date 2024-01-01  # Только заказы
    python main.py --transactions-only --start-date 2024-01-01  # Только транзакции
"""

import sys
import os
import argparse
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import import_products, import_orders, import_transactions, logger
from wb_importer import import_sales, import_financial_details, import_wb_products


def parse_arguments():
    """Парсинг аргументов командной строки."""
    parser = argparse.ArgumentParser(description='Импорт данных из API маркетплейсов (Ozon, Wildberries)')
    
    parser.add_argument(
        '--start-date',
        type=str,
        help='Начальная дата в формате YYYY-MM-DD (по умолчанию: вчера)'
    )
    
    parser.add_argument(
        '--end-date',
        type=str,
        help='Конечная дата в формате YYYY-MM-DD (по умолчанию: вчера)'
    )
    
    parser.add_argument(
        '--products-only',
        action='store_true',
        help='Импортировать только товары'
    )
    
    parser.add_argument(
        '--orders-only',
        action='store_true',
        help='Импортировать только заказы'
    )
    
    parser.add_argument(
        '--transactions-only',
        action='store_true',
        help='Импортировать только транзакции'
    )
    
    parser.add_argument(
        '--last-7-days',
        action='store_true',
        help='Импортировать данные за последние 7 дней (для cron job)'
    )
    
    parser.add_argument(
        '--source',
        nargs='+',  # Позволяет принимать несколько значений (например, --source ozon wb)
        choices=['ozon', 'wb'],  # Разрешает только эти значения
        help='Укажите источник для импорта: ozon или wb. Можно указать несколько через пробел'
    )
    
    return parser.parse_args()


def get_default_dates():
    """Получает даты по умолчанию (вчерашний день)."""
    yesterday = datetime.now() - timedelta(days=1)
    return yesterday.strftime('%Y-%m-%d'), yesterday.strftime('%Y-%m-%d')


def get_last_7_days_dates():
    """Получает даты за последние 7 дней."""
    today = datetime.now()
    start_date = (today - timedelta(days=7)).strftime('%Y-%m-%d')
    end_date = (today - timedelta(days=1)).strftime('%Y-%m-%d')
    return start_date, end_date


def validate_date(date_string):
    """Проверяет корректность формата даты."""
    try:
        datetime.strptime(date_string, '%Y-%m-%d')
        return True
    except ValueError:
        return False


def main():
    """Главная функция."""
    # Парсим аргументы
    args = parse_arguments()
    
    # Определяем источники данных
    sources = args.source if args.source else ['ozon', 'wb']  # Если не указано, запускаем оба
    
    logger.info(f"🚀 Запуск импорта данных из API: {', '.join(sources)}")
    
    # Определяем даты
    if args.last_7_days:
        start_date, end_date = get_last_7_days_dates()
        logger.info("📅 Режим: последние 7 дней для cron job")
    elif args.start_date or args.end_date:
        start_date = args.start_date
        end_date = args.end_date or args.start_date
        
        # Проверяем корректность дат
        if start_date and not validate_date(start_date):
            logger.error(f"Некорректный формат начальной даты: {start_date}. Используйте YYYY-MM-DD")
            return 1
            
        if end_date and not validate_date(end_date):
            logger.error(f"Некорректный формат конечной даты: {end_date}. Используйте YYYY-MM-DD")
            return 1
    else:
        start_date, end_date = get_default_dates()
    
    logger.info(f"📅 Период импорта: с {start_date} по {end_date}")
    
    try:
        # Обрабатываем каждый источник
        for source in sources:
            if source == "wb":
                logger.info("--- Запускаем импорт данных Wildberries ---")
                
                # Определяем, что импортировать
                import_products_flag = not (args.orders_only or args.transactions_only)
                import_orders_flag = not (args.products_only or args.transactions_only)
                import_transactions_flag = not (args.products_only or args.orders_only)
                
                # Если указаны специальные флаги, переопределяем
                if args.products_only:
                    import_products_flag = True
                    import_orders_flag = False
                    import_transactions_flag = False
                elif args.orders_only:
                    import_products_flag = False
                    import_orders_flag = True
                    import_transactions_flag = False
                elif args.transactions_only:
                    import_products_flag = False
                    import_orders_flag = False
                    import_transactions_flag = True
                
                # Импорт товаров WB
                if import_products_flag:
                    logger.info("📦 Начинаем импорт товаров WB...")
                    import_wb_products()
                    logger.info("✅ Импорт товаров WB завершен")
                
                # Импорт продаж (заказов)
                if import_orders_flag:
                    logger.info("🛒 Начинаем импорт продаж WB...")
                    import_sales(start_date, end_date)
                    logger.info("✅ Импорт продаж WB завершен")
                
                # Импорт финансовых деталей (транзакций)
                if import_transactions_flag:
                    logger.info("💰 Начинаем импорт финансовых деталей WB...")
                    import_financial_details(start_date, end_date)
                    logger.info("✅ Импорт финансовых деталей WB завершен")
            
            elif source == "ozon":
                logger.info("--- Запускаем импорт данных Ozon ---")
                
                # Определяем, что импортировать
                import_products_flag = not (args.orders_only or args.transactions_only)
                import_orders_flag = not (args.products_only or args.transactions_only)
                import_transactions_flag = not (args.products_only or args.orders_only)
                
                # Если указаны специальные флаги, переопределяем
                if args.products_only:
                    import_products_flag = True
                    import_orders_flag = False
                    import_transactions_flag = False
                elif args.orders_only:
                    import_products_flag = False
                    import_orders_flag = True
                    import_transactions_flag = False
                elif args.transactions_only:
                    import_products_flag = False
                    import_orders_flag = False
                    import_transactions_flag = True
                
                # Импорт товаров
                if import_products_flag:
                    logger.info("📦 Начинаем импорт товаров...")
                    import_products()
                    logger.info("✅ Импорт товаров завершен")
                
                # Импорт заказов
                if import_orders_flag:
                    logger.info("🛒 Начинаем импорт заказов...")
                    import_orders(start_date, end_date)
                    logger.info("✅ Импорт заказов завершен")
                
                # Импорт транзакций
                if import_transactions_flag:
                    logger.info("💰 Начинаем импорт транзакций...")
                    import_transactions(start_date, end_date)
                    logger.info("✅ Импорт транзакций завершен")
        
        logger.info("🎉 Все операции импорта завершены успешно!")
        return 0
        
    except Exception as e:
        logger.error(f"❌ Критическая ошибка при импорте: {e}")
        return 1


if __name__ == "__main__":
    exit_code = main()
    sys.exit(exit_code)
