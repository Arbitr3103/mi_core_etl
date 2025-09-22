#!/usr/bin/env python3
"""
Скрипт для пересчета маржинальности для исторических данных.
Используется после обновления системы расчета маржинальности.
"""

import sys
import os
import logging
from datetime import datetime, timedelta

# Добавляем путь к модулю importers
sys.path.append(os.path.join(os.path.dirname(__file__), 'importers'))

from ozon_importer import connect_to_db
from run_aggregation import aggregate_daily_metrics

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


class HistoricalMarginRecalculator:
    """Класс для пересчета исторических данных маржинальности."""
    
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
    
    def get_dates_for_recalculation(self, start_date: str = None, end_date: str = None):
        """Получает список дат для пересчета."""
        if not self.connection:
            return []
            
        try:
            cursor = self.connection.cursor()
            
            if start_date and end_date:
                # Пересчет для указанного периода
                cursor.execute("""
                    SELECT DISTINCT metric_date 
                    FROM metrics_daily 
                    WHERE metric_date BETWEEN %s AND %s
                    ORDER BY metric_date
                """, (start_date, end_date))
            else:
                # Пересчет всех существующих данных
                cursor.execute("""
                    SELECT DISTINCT metric_date 
                    FROM metrics_daily 
                    ORDER BY metric_date
                """)
            
            dates = [row[0].strftime('%Y-%m-%d') for row in cursor.fetchall()]
            cursor.close()
            
            return dates
            
        except Exception as e:
            logger.error(f"❌ Ошибка получения дат для пересчета: {e}")
            return []
    
    def backup_existing_data(self, backup_table_name: str = "metrics_daily_backup"):
        """Создает резервную копию существующих данных."""
        if not self.connection:
            return False
            
        try:
            cursor = self.connection.cursor()
            
            # Проверяем, существует ли уже таблица бэкапа
            cursor.execute(f"""
                SELECT COUNT(*) 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = '{backup_table_name}'
            """)
            
            table_exists = cursor.fetchone()[0] > 0
            
            if table_exists:
                logger.info(f"⚠️  Таблица {backup_table_name} уже существует")
                cursor.execute(f"SELECT COUNT(*) FROM {backup_table_name}")
                backup_count = cursor.fetchone()[0]
                logger.info(f"   Записей в бэкапе: {backup_count}")
            else:
                # Создаем резервную копию
                cursor.execute(f"""
                    CREATE TABLE {backup_table_name} AS 
                    SELECT * FROM metrics_daily
                """)
                
                cursor.execute(f"SELECT COUNT(*) FROM {backup_table_name}")
                backup_count = cursor.fetchone()[0]
                
                logger.info(f"✅ Создана резервная копия: {backup_table_name}")
                logger.info(f"   Скопировано записей: {backup_count}")
            
            cursor.close()
            return True
            
        except Exception as e:
            logger.error(f"❌ Ошибка создания резервной копии: {e}")
            return False
    
    def check_schema_readiness(self):
        """Проверяет готовность схемы БД к новому расчету маржинальности."""
        if not self.connection:
            return False
            
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            # Проверяем наличие колонки margin_percent
            cursor.execute("""
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'metrics_daily' 
                    AND COLUMN_NAME = 'margin_percent'
            """)
            
            has_margin_percent = cursor.fetchone() is not None
            
            # Проверяем наличие необходимых индексов
            cursor.execute("SHOW INDEX FROM fact_orders WHERE Key_name = 'idx_fact_orders_date_client'")
            has_orders_index = cursor.fetchone() is not None
            
            cursor.execute("SHOW INDEX FROM fact_transactions WHERE Key_name = 'idx_fact_transactions_order'")
            has_transactions_index = cursor.fetchone() is not None
            
            cursor.close()
            
            logger.info("🔍 Проверка готовности схемы:")
            logger.info(f"   - Колонка margin_percent: {'✅' if has_margin_percent else '❌'}")
            logger.info(f"   - Индекс fact_orders: {'✅' if has_orders_index else '❌'}")
            logger.info(f"   - Индекс fact_transactions: {'✅' if has_transactions_index else '❌'}")
            
            if not has_margin_percent:
                logger.error("❌ Колонка margin_percent отсутствует!")
                logger.error("   Выполните: mysql -u root -p mi_core_db < add_margin_percent_column.sql")
                return False
            
            if not has_orders_index or not has_transactions_index:
                logger.warning("⚠️  Отсутствуют рекомендуемые индексы")
                logger.warning("   Производительность может быть снижена")
            
            return True
            
        except Exception as e:
            logger.error(f"❌ Ошибка проверки схемы: {e}")
            return False
    
    def recalculate_date_range(self, dates: list, batch_size: int = 10):
        """Пересчитывает маржинальность для списка дат."""
        if not dates:
            logger.warning("⚠️  Нет дат для пересчета")
            return True
            
        logger.info(f"🔄 Начинаем пересчет для {len(dates)} дат")
        logger.info(f"   Период: {dates[0]} - {dates[-1]}")
        
        successful_recalculations = 0
        failed_recalculations = 0
        
        # Обрабатываем даты батчами
        for i in range(0, len(dates), batch_size):
            batch = dates[i:i + batch_size]
            
            logger.info(f"📦 Обработка батча {i//batch_size + 1}: {len(batch)} дат")
            
            for date in batch:
                try:
                    logger.info(f"   Пересчет {date}...")
                    
                    start_time = datetime.now()
                    success = aggregate_daily_metrics(self.connection, date)
                    end_time = datetime.now()
                    
                    execution_time = (end_time - start_time).total_seconds()
                    
                    if success:
                        successful_recalculations += 1
                        logger.info(f"     ✅ Завершено за {execution_time:.2f}s")
                    else:
                        failed_recalculations += 1
                        logger.error(f"     ❌ Ошибка пересчета")
                        
                except Exception as e:
                    failed_recalculations += 1
                    logger.error(f"     ❌ Исключение при пересчете {date}: {e}")
            
            # Небольшая пауза между батчами
            if i + batch_size < len(dates):
                logger.info("   ⏸️  Пауза 2 секунды...")
                import time
                time.sleep(2)
        
        logger.info(f"📊 Результаты пересчета:")
        logger.info(f"   - Успешно: {successful_recalculations}")
        logger.info(f"   - С ошибками: {failed_recalculations}")
        logger.info(f"   - Общий процент успеха: {(successful_recalculations/(successful_recalculations+failed_recalculations))*100:.1f}%")
        
        return failed_recalculations == 0
    
    def validate_recalculation_results(self, sample_dates: list = None):
        """Валидирует результаты пересчета."""
        if not self.connection:
            return False
            
        try:
            cursor = self.connection.cursor(dictionary=True)
            
            if not sample_dates:
                # Берем несколько случайных дат для проверки
                cursor.execute("""
                    SELECT DISTINCT metric_date 
                    FROM metrics_daily 
                    WHERE margin_percent IS NOT NULL
                    ORDER BY RAND() 
                    LIMIT 3
                """)
                sample_dates = [row['metric_date'].strftime('%Y-%m-%d') for row in cursor.fetchall()]
            
            logger.info(f"🔍 Валидация результатов пересчета")
            logger.info(f"   Проверяемые даты: {', '.join(sample_dates)}")
            
            validation_passed = True
            
            for date in sample_dates:
                cursor.execute("""
                    SELECT 
                        COUNT(*) as total_records,
                        COUNT(CASE WHEN margin_percent IS NOT NULL THEN 1 END) as records_with_margin,
                        AVG(margin_percent) as avg_margin,
                        SUM(revenue_sum) as total_revenue,
                        SUM(profit_sum) as total_profit
                    FROM metrics_daily 
                    WHERE metric_date = %s
                """, (date,))
                
                result = cursor.fetchone()
                
                logger.info(f"   {date}:")
                logger.info(f"     - Записей: {result['total_records']}")
                logger.info(f"     - С маржой: {result['records_with_margin']}")
                logger.info(f"     - Средняя маржа: {result['avg_margin']:.2f}%" if result['avg_margin'] else "     - Средняя маржа: N/A")
                logger.info(f"     - Общая выручка: {result['total_revenue']:.2f}" if result['total_revenue'] else "     - Общая выручка: 0")
                logger.info(f"     - Общая прибыль: {result['total_profit']:.2f}" if result['total_profit'] else "     - Общая прибыль: 0")
                
                # Проверки корректности
                if result['total_records'] == 0:
                    logger.warning(f"     ⚠️  Нет данных за {date}")
                elif result['records_with_margin'] == 0:
                    logger.warning(f"     ⚠️  Нет рассчитанной маржи за {date}")
                    validation_passed = False
                elif result['avg_margin'] and (result['avg_margin'] < -100 or result['avg_margin'] > 100):
                    logger.warning(f"     ⚠️  Подозрительная средняя маржа: {result['avg_margin']:.2f}%")
                    validation_passed = False
                else:
                    logger.info(f"     ✅ Данные выглядят корректно")
            
            cursor.close()
            
            if validation_passed:
                logger.info("✅ Валидация пройдена успешно")
            else:
                logger.warning("⚠️  Обнаружены потенциальные проблемы")
            
            return validation_passed
            
        except Exception as e:
            logger.error(f"❌ Ошибка валидации: {e}")
            return False
    
    def run_full_recalculation(self, start_date: str = None, end_date: str = None, 
                              create_backup: bool = True, batch_size: int = 10):
        """Выполняет полный пересчет исторических данных."""
        logger.info("🚀 Запуск пересчета исторических данных маржинальности")
        logger.info("=" * 60)
        
        if not self.setup_connection():
            return False
        
        try:
            # 1. Проверка готовности схемы
            if not self.check_schema_readiness():
                return False
            
            # 2. Создание резервной копии
            if create_backup:
                if not self.backup_existing_data():
                    logger.error("❌ Не удалось создать резервную копию")
                    return False
            
            # 3. Получение списка дат для пересчета
            dates = self.get_dates_for_recalculation(start_date, end_date)
            
            if not dates:
                logger.warning("⚠️  Нет данных для пересчета")
                return True
            
            # 4. Пересчет данных
            success = self.recalculate_date_range(dates, batch_size)
            
            if not success:
                logger.error("❌ Пересчет завершился с ошибками")
                return False
            
            # 5. Валидация результатов
            validation_success = self.validate_recalculation_results()
            
            logger.info("=" * 60)
            if success and validation_success:
                logger.info("🎉 ПЕРЕСЧЕТ ЗАВЕРШЕН УСПЕШНО!")
                logger.info("✅ Все исторические данные обновлены")
                logger.info("📊 Система готова к использованию")
            else:
                logger.error("❌ ПЕРЕСЧЕТ ЗАВЕРШИЛСЯ С ПРОБЛЕМАМИ")
                logger.error("⚠️  Рекомендуется дополнительная проверка")
            
            return success and validation_success
            
        finally:
            if self.connection:
                self.connection.close()


def main():
    """Основная функция для запуска пересчета."""
    import argparse
    
    parser = argparse.ArgumentParser(description='Пересчет исторических данных маржинальности')
    parser.add_argument('--start-date', help='Начальная дата (YYYY-MM-DD)')
    parser.add_argument('--end-date', help='Конечная дата (YYYY-MM-DD)')
    parser.add_argument('--no-backup', action='store_true', help='Не создавать резервную копию')
    parser.add_argument('--batch-size', type=int, default=10, help='Размер батча для обработки')
    
    args = parser.parse_args()
    
    recalculator = HistoricalMarginRecalculator()
    success = recalculator.run_full_recalculation(
        start_date=args.start_date,
        end_date=args.end_date,
        create_backup=not args.no_backup,
        batch_size=args.batch_size
    )
    
    if success:
        exit(0)
    else:
        exit(1)


if __name__ == "__main__":
    main()