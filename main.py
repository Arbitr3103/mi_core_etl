#!/usr/bin/env python3
"""
Главный модуль для запуска импорта данных из API Ozon.

Использование:
    python main.py                    # Импорт товаров и заказов за вчера
    python main.py --start-date 2024-01-01 --end-date 2024-01-31  # За указанный период
    python main.py --products-only    # Только товары
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


def parse_arguments():
    """Парсинг аргументов командной строки."""
    parser = argparse.ArgumentParser(description='Импорт данных из API Ozon')
    
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
    
    return parser.parse_args()


def get_default_dates():
    """Получает даты по умолчанию (вчерашний день)."""
    yesterday = datetime.now() - timedelta(days=1)
    return yesterday.strftime('%Y-%m-%d'), yesterday.strftime('%Y-%m-%d')


def validate_date(date_string):
    """Проверяет корректность формата даты."""
    try:
        datetime.strptime(date_string, '%Y-%m-%d')
        return True
    except ValueError:
        return False


def main():
    """Главная функция."""
    logger.info("🚀 Запуск импорта данных из API Ozon")
    
    # Парсим аргументы
    args = parse_arguments()
    
    # Определяем даты
    if args.start_date or args.end_date:
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
