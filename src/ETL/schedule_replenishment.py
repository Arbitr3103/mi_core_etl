#!/usr/bin/env python3
"""
Скрипт для автоматизации и планирования анализа пополнения склада.
Поддерживает запуск по расписанию и различные режимы работы.
"""

import os
import sys
import time
import logging
import schedule
import subprocess
from datetime import datetime, timedelta
from typing import Dict, List

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('replenishment_scheduler.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)


class ReplenishmentScheduler:
    """Планировщик для автоматического запуска анализа пополнения."""
    
    def __init__(self):
        """Инициализация планировщика."""
        self.script_path = os.path.join(os.path.dirname(__file__), 'replenishment_orchestrator.py')
        self.last_full_analysis = None
        self.last_quick_check = None
        
    def run_full_analysis(self):
        """Запустить полный анализ пополнения."""
        logger.info("🚀 Запуск планового полного анализа пополнения")
        
        try:
            # Запускаем полный анализ
            result = subprocess.run([
                sys.executable, self.script_path,
                '--mode', 'full'
            ], capture_output=True, text=True, timeout=3600)  # Таймаут 1 час
            
            if result.returncode == 0:
                logger.info("✅ Полный анализ завершен успешно")
                self.last_full_analysis = datetime.now()
                
                # Экспортируем результаты
                self._export_daily_report()
                
            else:
                logger.error(f"❌ Ошибка полного анализа: {result.stderr}")
                
        except subprocess.TimeoutExpired:
            logger.error("⏰ Полный анализ превысил лимит времени (1 час)")
        except Exception as e:
            logger.error(f"❌ Критическая ошибка полного анализа: {e}")
    
    def run_quick_check(self):
        """Запустить быструю проверку критических остатков."""
        logger.info("⚡ Запуск плановой быстрой проверки")
        
        try:
            # Запускаем быструю проверку
            result = subprocess.run([
                sys.executable, self.script_path,
                '--mode', 'quick'
            ], capture_output=True, text=True, timeout=300)  # Таймаут 5 минут
            
            if result.returncode == 0:
                logger.info("✅ Быстрая проверка завершена успешно")
                self.last_quick_check = datetime.now()
            else:
                logger.error(f"❌ Ошибка быстрой проверки: {result.stderr}")
                
        except subprocess.TimeoutExpired:
            logger.error("⏰ Быстрая проверка превысила лимит времени (5 минут)")
        except Exception as e:
            logger.error(f"❌ Критическая ошибка быстрой проверки: {e}")
    
    def _export_daily_report(self):
        """Экспорт ежедневного отчета."""
        try:
            today = datetime.now().strftime('%Y-%m-%d')
            
            # Экспорт в JSON
            json_filename = f"replenishment_report_{today}.json"
            result = subprocess.run([
                sys.executable, self.script_path,
                '--mode', 'export',
                '--export-file', json_filename,
                '--export-format', 'json'
            ], capture_output=True, text=True, timeout=300)
            
            if result.returncode == 0:
                logger.info(f"📄 Ежедневный отчет экспортирован: {json_filename}")
            
            # Экспорт в CSV для удобства
            csv_filename = f"replenishment_report_{today}.csv"
            result = subprocess.run([
                sys.executable, self.script_path,
                '--mode', 'export',
                '--export-file', csv_filename,
                '--export-format', 'csv'
            ], capture_output=True, text=True, timeout=300)
            
            if result.returncode == 0:
                logger.info(f"📊 CSV отчет экспортирован: {csv_filename}")
                
        except Exception as e:
            logger.error(f"❌ Ошибка экспорта ежедневного отчета: {e}")
    
    def cleanup_old_reports(self, days_to_keep: int = 30):
        """Очистка старых отчетов."""
        try:
            current_dir = os.path.dirname(__file__)
            cutoff_date = datetime.now() - timedelta(days=days_to_keep)
            
            deleted_count = 0
            for filename in os.listdir(current_dir):
                if filename.startswith('replenishment_report_') and filename.endswith(('.json', '.csv')):
                    file_path = os.path.join(current_dir, filename)
                    file_time = datetime.fromtimestamp(os.path.getmtime(file_path))
                    
                    if file_time < cutoff_date:
                        os.remove(file_path)
                        deleted_count += 1
                        logger.info(f"🗑️  Удален старый отчет: {filename}")
            
            if deleted_count > 0:
                logger.info(f"🧹 Очищено {deleted_count} старых отчетов")
            
        except Exception as e:
            logger.error(f"❌ Ошибка очистки старых отчетов: {e}")
    
    def setup_schedule(self):
        """Настройка расписания выполнения задач."""
        logger.info("📅 Настройка расписания задач")
        
        # Полный анализ каждый день в 6:00 утра
        schedule.every().day.at("06:00").do(self.run_full_analysis)
        logger.info("   📊 Полный анализ: каждый день в 06:00")
        
        # Быстрая проверка каждые 4 часа в рабочее время
        schedule.every().day.at("08:00").do(self.run_quick_check)
        schedule.every().day.at("12:00").do(self.run_quick_check)
        schedule.every().day.at("16:00").do(self.run_quick_check)
        schedule.every().day.at("20:00").do(self.run_quick_check)
        logger.info("   ⚡ Быстрая проверка: каждые 4 часа (08:00, 12:00, 16:00, 20:00)")
        
        # Очистка старых отчетов каждое воскресенье в 2:00 ночи
        schedule.every().sunday.at("02:00").do(self.cleanup_old_reports)
        logger.info("   🧹 Очистка отчетов: каждое воскресенье в 02:00")
        
        logger.info("✅ Расписание настроено")
    
    def run_scheduler(self):
        """Запуск планировщика."""
        logger.info("🎯 ЗАПУСК ПЛАНИРОВЩИКА ПОПОЛНЕНИЯ СКЛАДА")
        logger.info("=" * 60)
        
        self.setup_schedule()
        
        logger.info("⏰ Планировщик запущен. Ожидание выполнения задач...")
        logger.info("   Для остановки нажмите Ctrl+C")
        
        try:
            while True:
                schedule.run_pending()
                time.sleep(60)  # Проверяем каждую минуту
                
        except KeyboardInterrupt:
            logger.info("⏹️  Планировщик остановлен пользователем")
        except Exception as e:
            logger.error(f"❌ Критическая ошибка планировщика: {e}")
    
    def run_manual_task(self, task: str):
        """
        Запуск задачи вручную.
        
        Args:
            task: Тип задачи ('full', 'quick', 'export', 'cleanup')
        """
        logger.info(f"🔧 Ручной запуск задачи: {task}")
        
        if task == 'full':
            self.run_full_analysis()
        elif task == 'quick':
            self.run_quick_check()
        elif task == 'export':
            self._export_daily_report()
        elif task == 'cleanup':
            self.cleanup_old_reports()
        else:
            logger.error(f"❌ Неизвестная задача: {task}")
    
    def get_status(self) -> Dict[str, any]:
        """Получить статус планировщика."""
        next_jobs = []
        for job in schedule.jobs:
            next_jobs.append({
                'job': str(job.job_func.__name__),
                'next_run': job.next_run.strftime('%Y-%m-%d %H:%M:%S') if job.next_run else 'Не запланировано'
            })
        
        status = {
            'scheduler_running': True,
            'last_full_analysis': self.last_full_analysis.strftime('%Y-%m-%d %H:%M:%S') if self.last_full_analysis else 'Никогда',
            'last_quick_check': self.last_quick_check.strftime('%Y-%m-%d %H:%M:%S') if self.last_quick_check else 'Никогда',
            'scheduled_jobs': len(schedule.jobs),
            'next_jobs': next_jobs
        }
        
        return status


def main():
    """Основная функция."""
    import argparse
    
    parser = argparse.ArgumentParser(description='Планировщик системы пополнения склада')
    parser.add_argument('--mode', choices=['schedule', 'manual', 'status'], default='schedule',
                       help='Режим работы')
    parser.add_argument('--task', choices=['full', 'quick', 'export', 'cleanup'],
                       help='Задача для ручного запуска')
    
    args = parser.parse_args()
    
    scheduler = ReplenishmentScheduler()
    
    try:
        if args.mode == 'schedule':
            # Запуск планировщика
            scheduler.run_scheduler()
            
        elif args.mode == 'manual':
            # Ручной запуск задачи
            if not args.task:
                print("❌ Для ручного режима необходимо указать задачу (--task)")
                return
            
            scheduler.run_manual_task(args.task)
            
        elif args.mode == 'status':
            # Показать статус
            status = scheduler.get_status()
            
            print("\n📊 СТАТУС ПЛАНИРОВЩИКА:")
            print("=" * 50)
            print(f"Планировщик активен: {'✅' if status['scheduler_running'] else '❌'}")
            print(f"Последний полный анализ: {status['last_full_analysis']}")
            print(f"Последняя быстрая проверка: {status['last_quick_check']}")
            print(f"Запланированных задач: {status['scheduled_jobs']}")
            
            if status['next_jobs']:
                print(f"\n📅 БЛИЖАЙШИЕ ЗАДАЧИ:")
                for job in status['next_jobs']:
                    print(f"   {job['job']}: {job['next_run']}")
    
    except Exception as e:
        logger.error(f"❌ Критическая ошибка: {e}")
        print(f"❌ Критическая ошибка: {e}")


if __name__ == "__main__":
    main()