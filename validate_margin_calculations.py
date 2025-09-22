#!/usr/bin/env python3
"""
Скрипт для валидации расчетов маржинальности.
Сравнивает результаты автоматического расчета с ручными вычислениями.
"""

import sys
import os
import logging
from datetime import datetime, timedelta
from decimal import Decimal, ROUND_HALF_UP

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class MarginValidator:
    """Класс для валидации расчетов маржинальности."""
    
    def __init__(self):
        self.connection = None
        
    def setup_connection(self):
        """Устанавливает соединение с базой данных."""
        try:
            self.connection = connect_to_db()
            logger.info("✅ Соединение с базой данных установлено")
            return True
        except Exception as e:
            logger.error(f"❌ Ошибка подключения к БД: {e}")
            return False
    
    def get_manual_calculation(self, client_id: int, date: str):
        """Выполняет ручной расчет маржинальности для сравнения."""
        if not self.connection:
            return None
            
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # 1. Получаем данные о продажах
            cursor.execute("""
                SELECT 
                    fo.order_id,
                    fo.qty,
                    fo.price,
                    fo.transaction_type,
                    dp.cost_price
                FROM fact_orders fo
                LEFT JOIN dim_products dp ON fo.product_id = dp.id
                WHERE fo.client_id = %s 
                    AND fo.order_date = %s
                ORDER BY fo.order_id
            """, (client_id, date))
            
            orders = cursor.fetchall()
            
            # 2. Получаем данные о транзакциях
            cursor.execute("""
                SELECT 
                    transaction_type,
                    amount,
                    order_id
                FROM fact_transactions
                WHERE client_id = %s 
                    AND transaction_date = %s
                ORDER BY transaction_type
            """, (client_id, date))
            
            transactions = cursor.fetchall()
            
            cursor.close()
            
            # 3. Ручной расчет
            manual_calc = {
                'orders_cnt': 0,
                'revenue_sum': Decimal('0'),
                'returns_sum': Decimal('0'),
                'cogs_sum': Decimal('0'),
                'commission_sum': Decimal('0'),
                'shipping_sum': Decimal('0'),
                'other_expenses_sum': Decimal('0'),
                'profit_sum': Decimal('0'),
                'margin_percent': None
            }
            
            # Обрабатываем заказы
            for order in orders:
                if order['transaction_type'] == 'продажа':
                    manual_calc['orders_cnt'] += 1
                    revenue = Decimal(str(order['qty'])) * Decimal(str(order['price']))
                    manual_calc['revenue_sum'] += revenue
                    
                    if order['cost_price']:
                        cogs = Decimal(str(order['qty'])) * Decimal(str(order['cost_price']))
                        manual_calc['cogs_sum'] += cogs
                        
                elif order['transaction_type'] == 'возврат':
                    returns = Decimal(str(order['qty'])) * Decimal(str(order['price']))
                    manual_calc['returns_sum'] += returns
            
            # Обрабатываем транзакции
            for trans in transactions:
                amount = abs(Decimal(str(trans['amount'])))
                trans_type = trans['transaction_type'].lower()
                
                if any(keyword in trans_type for keyword in ['комиссия', 'эквайринг', 'commission', 'fee']):
                    manual_calc['commission_sum'] += amount
                elif any(keyword in trans_type for keyword in ['логистика', 'доставка', 'delivery', 'shipping']):
                    manual_calc['shipping_sum'] += amount
                elif 'возврат' not in trans_type and 'return' not in trans_type:
                    manual_calc['other_expenses_sum'] += amount
            
            # Рассчитываем прибыль
            manual_calc['profit_sum'] = (
                manual_calc['revenue_sum'] - 
                manual_calc['returns_sum'] - 
                manual_calc['cogs_sum'] - 
                manual_calc['commission_sum'] - 
                manual_calc['shipping_sum'] - 
                manual_calc['other_expenses_sum']
            )
            
            # Рассчитываем процент маржинальности
            if manual_calc['revenue_sum'] > 0:
                margin = (manual_calc['profit_sum'] / manual_calc['revenue_sum']) * 100
                manual_calc['margin_percent'] = margin.quantize(Decimal('0.01'), rounding=ROUND_HALF_UP)
            
            return manual_calc
            
        except Exception as e:
            logger.error(f"❌ Ошибка ручного расчета: {e}")
            return None
    
    def get_automated_calculation(self, client_id: int, date: str):
        """Получает результаты автоматического расчета из metrics_daily."""
        if not self.connection:
            return None
            
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            cursor.execute("""
                SELECT 
                    orders_cnt,
                    revenue_sum,
                    returns_sum,
                    cogs_sum,
                    commission_sum,
                    shipping_sum,
                    other_expenses_sum,
                    profit_sum,
                    margin_percent
                FROM metrics_daily
                WHERE client_id = %s AND metric_date = %s
            """, (client_id, date))
            
            result = cursor.fetchone()
            cursor.close()
            
            if result:
                # Преобразуем в Decimal для точного сравнения
                for key, value in result.items():
                    if value is not None and key != 'orders_cnt':
                        result[key] = Decimal(str(value))
                        
            return result
            
        except Exception as e:
            logger.error(f"❌ Ошибка получения автоматического расчета: {e}")
            return None
    
    def compare_calculations(self, manual: dict, automated: dict, tolerance: Decimal = Decimal('0.01')):
        """Сравнивает ручной и автоматический расчеты."""
        if not manual or not automated:
            return False, []
        
        differences = []
        all_match = True
        
        for key in manual.keys():
            manual_val = manual[key]
            auto_val = automated.get(key)
            
            if manual_val is None and auto_val is None:
                continue
                
            if manual_val is None or auto_val is None:
                differences.append(f"{key}: manual={manual_val}, auto={auto_val} (один из значений None)")
                all_match = False
                continue
            
            if key == 'orders_cnt':
                if manual_val != auto_val:
                    differences.append(f"{key}: manual={manual_val}, auto={auto_val}")
                    all_match = False
            else:
                # Для числовых значений проверяем с допуском
                if isinstance(manual_val, Decimal) and isinstance(auto_val, Decimal):
                    diff = abs(manual_val - auto_val)
                    if diff > tolerance:
                        differences.append(f"{key}: manual={manual_val}, auto={auto_val}, diff={diff}")
                        all_match = False
                else:
                    if manual_val != auto_val:
                        differences.append(f"{key}: manual={manual_val}, auto={auto_val} (разные типы)")
                        all_match = False
        
        return all_match, differences
    
    def validate_date(self, date: str):
        """Валидирует расчеты для конкретной даты."""
        logger.info(f"🔍 Валидация расчетов за дату: {date}")
        
        if not self.connection:
            return False
            
        try:
            cursor = self.connection.cursor()
            
            # Получаем список клиентов с данными за эту дату
            cursor.execute("""
                SELECT DISTINCT client_id 
                FROM metrics_daily 
                WHERE metric_date = %s
            """, (date,))
            
            client_ids = [row[0] for row in cursor.fetchall()]
            cursor.close()
            
            if not client_ids:
                logger.warning(f"⚠️  Нет данных в metrics_daily за дату {date}")
                return False
            
            logger.info(f"📊 Найдено клиентов для валидации: {len(client_ids)}")
            
            validation_results = []
            
            for client_id in client_ids:
                logger.info(f"   Валидация клиента {client_id}...")
                
                # Получаем ручной расчет
                manual = self.get_manual_calculation(client_id, date)
                
                # Получаем автоматический расчет
                automated = self.get_automated_calculation(client_id, date)
                
                # Сравниваем
                match, differences = self.compare_calculations(manual, automated)
                
                validation_results.append({
                    'client_id': client_id,
                    'match': match,
                    'differences': differences,
                    'manual': manual,
                    'automated': automated
                })
                
                if match:
                    logger.info(f"     ✅ Клиент {client_id}: расчеты совпадают")
                else:
                    logger.warning(f"     ❌ Клиент {client_id}: найдены расхождения")
                    for diff in differences[:3]:  # Показываем первые 3 расхождения
                        logger.warning(f"       - {diff}")
            
            # Итоговая статистика
            successful_validations = sum(1 for result in validation_results if result['match'])
            total_validations = len(validation_results)
            
            logger.info(f"📊 Результаты валидации за {date}:")
            logger.info(f"   - Успешных: {successful_validations}/{total_validations}")
            logger.info(f"   - Процент совпадений: {(successful_validations/total_validations)*100:.1f}%")
            
            # Детальный отчет по расхождениям
            failed_validations = [result for result in validation_results if not result['match']]
            
            if failed_validations:
                logger.warning(f"⚠️  Детали расхождений:")
                for result in failed_validations[:3]:  # Показываем детали для первых 3 клиентов
                    logger.warning(f"   Клиент {result['client_id']}:")
                    for diff in result['differences']:
                        logger.warning(f"     - {diff}")
            
            return successful_validations == total_validations
            
        except Exception as e:
            logger.error(f"❌ Ошибка валидации даты {date}: {e}")
            return False
    
    def validate_recent_dates(self, num_dates: int = 3):
        """Валидирует расчеты за последние N дат."""
        logger.info(f"🔍 Валидация расчетов за последние {num_dates} дат")
        
        if not self.connection:
            return False
            
        try:
            cursor = self.connection.cursor()
            
            # Получаем последние даты с данными
            cursor.execute("""
                SELECT DISTINCT metric_date 
                FROM metrics_daily 
                ORDER BY metric_date DESC 
                LIMIT %s
            """, (num_dates,))
            
            dates = [row[0].strftime('%Y-%m-%d') for row in cursor.fetchall()]
            cursor.close()
            
            if not dates:
                logger.warning("⚠️  Нет данных для валидации")
                return False
            
            logger.info(f"📅 Даты для валидации: {', '.join(dates)}")
            
            all_successful = True
            
            for date in dates:
                success = self.validate_date(date)
                if not success:
                    all_successful = False
            
            logger.info("=" * 60)
            if all_successful:
                logger.info("🎉 ВСЕ ВАЛИДАЦИИ ПРОШЛИ УСПЕШНО!")
                logger.info("✅ Расчеты маржинальности работают корректно")
            else:
                logger.error("❌ ОБНАРУЖЕНЫ РАСХОЖДЕНИЯ В РАСЧЕТАХ")
                logger.error("⚠️  Требуется дополнительная проверка логики")
            
            return all_successful
            
        except Exception as e:
            logger.error(f"❌ Ошибка валидации последних дат: {e}")
            return False
    
    def run_validation(self):
        """Запускает полную валидацию расчетов маржинальности."""
        logger.info("🚀 Запуск валидации расчетов маржинальности")
        logger.info("=" * 60)
        
        if not self.setup_connection():
            return False
        
        try:
            success = self.validate_recent_dates()
            return success
            
        finally:
            if self.connection:
                self.connection.close()


def main():
    """Основная функция для запуска валидации."""
    validator = MarginValidator()
    success = validator.run_validation()
    
    if success:
        exit(0)
    else:
        exit(1)


if __name__ == "__main__":
    main()