#!/usr/bin/env python3
"""
Automation System Test Runner
Запуск всех тестов системы автоматизации синхронизации остатков

Выполняет:
- Тесты корректности выполнения cron задач
- Тесты мониторинга расписания
- Тесты восстановления после сбоев
- Интеграционные тесты

Автор: Inventory Sync System
Версия: 1.0
"""

import os
import sys
import subprocess
import json
import time
from datetime import datetime
import argparse


class AutomationTestRunner:
    """Класс для запуска всех тестов автоматизации."""
    
    def __init__(self):
        self.script_dir = os.path.dirname(os.path.abspath(__file__))
        self.test_results = {}
        self.overall_success = True
    
    def run_test_script(self, script_name, description):
        """Запуск отдельного тестового скрипта."""
        print(f"\n{'='*60}")
        print(f"ЗАПУСК: {description}")
        print(f"Скрипт: {script_name}")
        print(f"{'='*60}")
        
        script_path = os.path.join(self.script_dir, script_name)
        
        if not os.path.exists(script_path):
            print(f"❌ ОШИБКА: Тестовый скрипт не найден: {script_path}")
            self.test_results[script_name] = {
                'success': False,
                'error': 'Script not found',
                'duration': 0
            }
            self.overall_success = False
            return False
        
        start_time = time.time()
        
        try:
            # Запускаем тестовый скрипт
            result = subprocess.run(
                [sys.executable, script_path],
                capture_output=True,
                text=True,
                cwd=self.script_dir,
                timeout=300  # 5 минут таймаут
            )
            
            duration = time.time() - start_time
            
            # Выводим результат
            if result.stdout:
                print(result.stdout)
            
            if result.stderr:
                print("STDERR:", result.stderr)
            
            success = result.returncode == 0
            
            self.test_results[script_name] = {
                'success': success,
                'return_code': result.returncode,
                'duration': duration,
                'stdout_length': len(result.stdout),
                'stderr_length': len(result.stderr)
            }
            
            if success:
                print(f"✅ УСПЕХ: {description} завершен успешно ({duration:.1f}с)")
            else:
                print(f"❌ НЕУДАЧА: {description} завершен с ошибкой (код: {result.returncode}, {duration:.1f}с)")
                self.overall_success = False
            
            return success
            
        except subprocess.TimeoutExpired:
            duration = time.time() - start_time
            print(f"⏰ ТАЙМАУТ: {description} превысил лимит времени ({duration:.1f}с)")
            
            self.test_results[script_name] = {
                'success': False,
                'error': 'Timeout',
                'duration': duration
            }
            self.overall_success = False
            return False
            
        except Exception as e:
            duration = time.time() - start_time
            print(f"❌ ОШИБКА: Не удалось запустить {description}: {e}")
            
            self.test_results[script_name] = {
                'success': False,
                'error': str(e),
                'duration': duration
            }
            self.overall_success = False
            return False
    
    def check_prerequisites(self):
        """Проверка предварительных условий для тестирования."""
        print("=== ПРОВЕРКА ПРЕДВАРИТЕЛЬНЫХ УСЛОВИЙ ===")
        
        prerequisites_ok = True
        
        # Проверяем Python версию
        python_version = sys.version_info
        if python_version.major < 3 or (python_version.major == 3 and python_version.minor < 6):
            print(f"❌ Требуется Python 3.6+, найден: {python_version.major}.{python_version.minor}")
            prerequisites_ok = False
        else:
            print(f"✅ Python версия: {python_version.major}.{python_version.minor}.{python_version.micro}")
        
        # Проверяем наличие необходимых модулей
        required_modules = ['subprocess', 'json', 'tempfile', 'unittest']
        for module in required_modules:
            try:
                __import__(module)
                print(f"✅ Модуль {module}: доступен")
            except ImportError:
                print(f"❌ Модуль {module}: не найден")
                prerequisites_ok = False
        
        # Проверяем наличие тестовых скриптов
        test_scripts = [
            'test_automation_system.py',
            'test_cron_execution.py',
            'test_failure_recovery.py'
        ]
        
        for script in test_scripts:
            script_path = os.path.join(self.script_dir, script)
            if os.path.exists(script_path):
                print(f"✅ Тестовый скрипт {script}: найден")
            else:
                print(f"❌ Тестовый скрипт {script}: не найден")
                prerequisites_ok = False
        
        # Проверяем наличие основных скриптов автоматизации
        automation_scripts = [
            'run_inventory_sync.sh',
            'run_weekly_inventory_resync.sh',
            'check_inventory_health.sh',
            'inventory_crontab.txt'
        ]
        
        for script in automation_scripts:
            script_path = os.path.join(self.script_dir, script)
            if os.path.exists(script_path):
                print(f"✅ Скрипт автоматизации {script}: найден")
            else:
                print(f"⚠️ Скрипт автоматизации {script}: не найден (некоторые тесты могут быть пропущены)")
        
        # Проверяем права на запись в рабочую директорию
        try:
            test_file = os.path.join(self.script_dir, 'test_write_permissions.tmp')
            with open(test_file, 'w') as f:
                f.write('test')
            os.remove(test_file)
            print("✅ Права на запись в рабочую директорию: есть")
        except Exception as e:
            print(f"❌ Права на запись в рабочую директорию: отсутствуют ({e})")
            prerequisites_ok = False
        
        if prerequisites_ok:
            print("✅ Все предварительные условия выполнены")
        else:
            print("❌ Некоторые предварительные условия не выполнены")
        
        print()
        return prerequisites_ok
    
    def run_all_tests(self, test_filter=None):
        """Запуск всех тестов автоматизации."""
        print("=== ЗАПУСК ТЕСТОВ СИСТЕМЫ АВТОМАТИЗАЦИИ ===")
        print(f"Время запуска: {datetime.now()}")
        print(f"Рабочая директория: {self.script_dir}")
        
        # Проверяем предварительные условия
        if not self.check_prerequisites():
            print("❌ Не удалось выполнить предварительные проверки")
            return False
        
        # Определяем тесты для запуска
        all_tests = [
            {
                'script': 'test_automation_system.py',
                'description': 'Комплексные тесты системы автоматизации',
                'category': 'comprehensive'
            },
            {
                'script': 'test_cron_execution.py',
                'description': 'Тесты выполнения cron задач',
                'category': 'cron'
            },
            {
                'script': 'test_failure_recovery.py',
                'description': 'Тесты восстановления после сбоев',
                'category': 'recovery'
            }
        ]
        
        # Фильтруем тесты если указан фильтр
        if test_filter:
            filtered_tests = [t for t in all_tests if test_filter.lower() in t['category'].lower()]
            if not filtered_tests:
                print(f"❌ Не найдено тестов для фильтра: {test_filter}")
                return False
            tests_to_run = filtered_tests
        else:
            tests_to_run = all_tests
        
        print(f"Будет запущено {len(tests_to_run)} тестовых наборов:")
        for test in tests_to_run:
            print(f"  - {test['description']} ({test['script']})")
        print()
        
        # Запускаем тесты
        start_time = time.time()
        
        for test in tests_to_run:
            self.run_test_script(test['script'], test['description'])
        
        total_duration = time.time() - start_time
        
        # Выводим итоговую сводку
        self.print_final_summary(total_duration)
        
        return self.overall_success
    
    def print_final_summary(self, total_duration):
        """Вывод итоговой сводки всех тестов."""
        print(f"\n{'='*60}")
        print("ИТОГОВАЯ СВОДКА ТЕСТИРОВАНИЯ")
        print(f"{'='*60}")
        
        total_tests = len(self.test_results)
        successful_tests = len([r for r in self.test_results.values() if r['success']])
        failed_tests = total_tests - successful_tests
        
        print(f"Общее время выполнения: {total_duration:.1f} секунд")
        print(f"Всего тестовых наборов: {total_tests}")
        print(f"Успешных: {successful_tests}")
        print(f"Неудачных: {failed_tests}")
        
        if total_tests > 0:
            success_rate = (successful_tests / total_tests) * 100
            print(f"Процент успеха: {success_rate:.1f}%")
        
        # Детали по каждому тесту
        print(f"\nДетали по тестам:")
        for script_name, result in self.test_results.items():
            status = "✅ PASS" if result['success'] else "❌ FAIL"
            duration = result.get('duration', 0)
            print(f"  {status} {script_name} ({duration:.1f}с)")
            
            if not result['success']:
                error = result.get('error', 'Unknown error')
                return_code = result.get('return_code', 'N/A')
                print(f"    Ошибка: {error} (код: {return_code})")
        
        # Общий результат
        print(f"\n{'='*60}")
        if self.overall_success:
            print("🎉 ВСЕ ТЕСТЫ АВТОМАТИЗАЦИИ ПРОШЛИ УСПЕШНО!")
            print("Система автоматизации готова к использованию.")
        else:
            print("⚠️ ОБНАРУЖЕНЫ ПРОБЛЕМЫ В СИСТЕМЕ АВТОМАТИЗАЦИИ")
            print("Рекомендуется исправить ошибки перед использованием.")
        print(f"{'='*60}")
        
        # Сохраняем отчет
        self.save_test_report(total_duration)
    
    def save_test_report(self, total_duration):
        """Сохранение детального отчета о тестировании."""
        report_data = {
            'test_run_info': {
                'timestamp': datetime.now().isoformat(),
                'total_duration': total_duration,
                'script_directory': self.script_dir,
                'python_version': f"{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}"
            },
            'summary': {
                'total_tests': len(self.test_results),
                'successful_tests': len([r for r in self.test_results.values() if r['success']]),
                'failed_tests': len([r for r in self.test_results.values() if not r['success']]),
                'overall_success': self.overall_success
            },
            'test_results': self.test_results
        }
        
        report_file = os.path.join(self.script_dir, 'automation_test_report.json')
        
        try:
            with open(report_file, 'w', encoding='utf-8') as f:
                json.dump(report_data, f, indent=2, ensure_ascii=False)
            
            print(f"\n📄 Детальный отчет сохранен: {report_file}")
            
        except Exception as e:
            print(f"\n❌ Ошибка сохранения отчета: {e}")
    
    def run_quick_check(self):
        """Быстрая проверка основных компонентов автоматизации."""
        print("=== БЫСТРАЯ ПРОВЕРКА АВТОМАТИЗАЦИИ ===")
        
        checks = [
            {
                'name': 'Скрипты синхронизации',
                'files': ['run_inventory_sync.sh', 'run_weekly_inventory_resync.sh'],
                'check_executable': True
            },
            {
                'name': 'Скрипты мониторинга',
                'files': ['check_inventory_health.sh', 'check_data_freshness.sh', 'monitor_log_size.sh'],
                'check_executable': True
            },
            {
                'name': 'Конфигурация cron',
                'files': ['inventory_crontab.txt'],
                'check_executable': False
            },
            {
                'name': 'Тестовые скрипты',
                'files': ['test_automation_system.py', 'test_cron_execution.py', 'test_failure_recovery.py'],
                'check_executable': False
            }
        ]
        
        all_checks_passed = True
        
        for check in checks:
            print(f"\nПроверка: {check['name']}")
            
            missing_files = []
            non_executable = []
            
            for file_name in check['files']:
                file_path = os.path.join(self.script_dir, file_name)
                
                if not os.path.exists(file_path):
                    missing_files.append(file_name)
                elif check['check_executable'] and not os.access(file_path, os.X_OK):
                    non_executable.append(file_name)
                else:
                    print(f"  ✅ {file_name}")
            
            if missing_files:
                print(f"  ❌ Отсутствуют файлы: {', '.join(missing_files)}")
                all_checks_passed = False
            
            if non_executable:
                print(f"  ⚠️ Нет прав на выполнение: {', '.join(non_executable)}")
                all_checks_passed = False
        
        print(f"\n{'='*50}")
        if all_checks_passed:
            print("✅ Быстрая проверка пройдена успешно")
            print("Все основные компоненты автоматизации на месте")
        else:
            print("❌ Обнаружены проблемы в компонентах автоматизации")
            print("Рекомендуется запустить полное тестирование")
        print(f"{'='*50}")
        
        return all_checks_passed


def main():
    """Главная функция."""
    parser = argparse.ArgumentParser(
        description='Запуск тестов системы автоматизации синхронизации остатков'
    )
    
    parser.add_argument(
        '--filter', '-f',
        help='Фильтр тестов (comprehensive, cron, recovery)',
        default=None
    )
    
    parser.add_argument(
        '--quick', '-q',
        action='store_true',
        help='Быстрая проверка без запуска полных тестов'
    )
    
    args = parser.parse_args()
    
    runner = AutomationTestRunner()
    
    if args.quick:
        success = runner.run_quick_check()
    else:
        success = runner.run_all_tests(args.filter)
    
    return 0 if success else 1


if __name__ == "__main__":
    sys.exit(main())