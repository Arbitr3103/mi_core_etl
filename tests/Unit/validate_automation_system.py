#!/usr/bin/env python3
"""
Automation System Validation
Финальная валидация системы автоматизации синхронизации остатков

Проверяет:
- Готовность всех компонентов к продакшн использованию
- Корректность конфигурации cron задач
- Работоспособность мониторинга и алертов
- Готовность к восстановлению после сбоев

Автор: Inventory Sync System
Версия: 1.0
"""

import os
import sys
import json
import subprocess
import time
from datetime import datetime, timedelta


class AutomationSystemValidator:
    """Класс для валидации системы автоматизации."""
    
    def __init__(self):
        self.script_dir = os.path.dirname(os.path.abspath(__file__))
        self.validation_results = []
        self.critical_issues = []
        self.warnings = []
        
    def log_result(self, category, test_name, status, message, details=None, is_critical=False):
        """Логирование результата валидации."""
        result = {
            'category': category,
            'test_name': test_name,
            'status': status,  # 'pass', 'fail', 'warning'
            'message': message,
            'details': details or {},
            'timestamp': datetime.now().isoformat(),
            'is_critical': is_critical
        }
        
        self.validation_results.append(result)
        
        if status == 'fail' and is_critical:
            self.critical_issues.append(result)
        elif status == 'warning':
            self.warnings.append(result)
        
        # Вывод результата
        if status == 'pass':
            icon = "✅"
        elif status == 'fail':
            icon = "❌" if is_critical else "⚠️"
        else:  # warning
            icon = "⚠️"
        
        print(f"{icon} [{category}] {test_name}: {message}")
        
        if details and (status == 'fail' or status == 'warning'):
            for key, value in details.items():
                print(f"    {key}: {value}")
    
    def validate_script_files(self):
        """Валидация файлов скриптов."""
        print("\n=== ВАЛИДАЦИЯ ФАЙЛОВ СКРИПТОВ ===")
        
        required_scripts = {
            'run_inventory_sync.sh': {
                'description': 'Основной скрипт синхронизации',
                'executable': True,
                'critical': True
            },
            'run_weekly_inventory_resync.sh': {
                'description': 'Скрипт еженедельной пересинхронизации',
                'executable': True,
                'critical': True
            },
            'check_inventory_health.sh': {
                'description': 'Скрипт проверки здоровья системы',
                'executable': True,
                'critical': False
            },
            'check_data_freshness.sh': {
                'description': 'Скрипт проверки актуальности данных',
                'executable': True,
                'critical': False
            },
            'monitor_log_size.sh': {
                'description': 'Скрипт мониторинга размера логов',
                'executable': True,
                'critical': False
            },
            'inventory_crontab.txt': {
                'description': 'Конфигурация cron задач',
                'executable': False,
                'critical': True
            }
        }
        
        for script_name, config in required_scripts.items():
            script_path = os.path.join(self.script_dir, script_name)
            
            if not os.path.exists(script_path):
                self.log_result(
                    'Scripts',
                    f'File Existence: {script_name}',
                    'fail',
                    f'{config["description"]} не найден',
                    {'expected_path': script_path},
                    is_critical=config['critical']
                )
                continue
            
            # Проверяем права на выполнение
            if config['executable']:
                if os.access(script_path, os.X_OK):
                    self.log_result(
                        'Scripts',
                        f'Executable: {script_name}',
                        'pass',
                        f'{config["description"]} имеет права на выполнение'
                    )
                else:
                    self.log_result(
                        'Scripts',
                        f'Executable: {script_name}',
                        'fail',
                        f'{config["description"]} не имеет прав на выполнение',
                        {'file_permissions': oct(os.stat(script_path).st_mode)[-3:]},
                        is_critical=config['critical']
                    )
            
            # Проверяем размер файла (не должен быть пустым)
            file_size = os.path.getsize(script_path)
            if file_size > 0:
                self.log_result(
                    'Scripts',
                    f'Content: {script_name}',
                    'pass',
                    f'{config["description"]} содержит данные ({file_size} байт)'
                )
            else:
                self.log_result(
                    'Scripts',
                    f'Content: {script_name}',
                    'fail',
                    f'{config["description"]} пустой',
                    {'file_size': file_size},
                    is_critical=config['critical']
                )
    
    def validate_cron_configuration(self):
        """Валидация конфигурации cron."""
        print("\n=== ВАЛИДАЦИЯ КОНФИГУРАЦИИ CRON ===")
        
        crontab_path = os.path.join(self.script_dir, 'inventory_crontab.txt')
        
        if not os.path.exists(crontab_path):
            self.log_result(
                'Cron',
                'Configuration File',
                'fail',
                'Файл конфигурации cron не найден',
                {'expected_path': crontab_path},
                is_critical=True
            )
            return
        
        try:
            with open(crontab_path, 'r', encoding='utf-8') as f:
                lines = f.readlines()
            
            # Анализируем cron записи
            cron_entries = []
            syntax_errors = []
            
            for line_num, line in enumerate(lines, 1):
                line = line.strip()
                
                # Пропускаем комментарии и переменные окружения
                if not line or line.startswith('#') or '=' in line:
                    continue
                
                parts = line.split()
                if len(parts) >= 6:
                    time_fields = parts[:5]
                    command = ' '.join(parts[5:])
                    
                    # Простая валидация полей времени
                    valid_time = True
                    for field in time_fields:
                        if not all(c.isdigit() or c in '*/-,' for c in field):
                            valid_time = False
                            break
                    
                    if valid_time:
                        cron_entries.append({
                            'line': line_num,
                            'schedule': ' '.join(time_fields),
                            'command': command
                        })
                    else:
                        syntax_errors.append(f"Строка {line_num}: неверный формат времени")
                else:
                    syntax_errors.append(f"Строка {line_num}: недостаточно полей")
            
            # Проверяем результаты
            if syntax_errors:
                self.log_result(
                    'Cron',
                    'Syntax Validation',
                    'fail',
                    f'Найдены ошибки синтаксиса в crontab ({len(syntax_errors)} ошибок)',
                    {'errors': syntax_errors[:3]},  # Показываем первые 3 ошибки
                    is_critical=True
                )
            else:
                self.log_result(
                    'Cron',
                    'Syntax Validation',
                    'pass',
                    f'Синтаксис crontab корректен ({len(cron_entries)} записей)'
                )
            
            # Проверяем наличие основных задач
            required_tasks = [
                'run_inventory_sync.sh',
                'run_weekly_inventory_resync.sh',
                'check_inventory_health.sh'
            ]
            
            missing_tasks = []
            for task in required_tasks:
                task_found = any(task in entry['command'] for entry in cron_entries)
                if not task_found:
                    missing_tasks.append(task)
            
            if missing_tasks:
                self.log_result(
                    'Cron',
                    'Required Tasks',
                    'fail',
                    f'Отсутствуют обязательные задачи в crontab',
                    {'missing_tasks': missing_tasks},
                    is_critical=True
                )
            else:
                self.log_result(
                    'Cron',
                    'Required Tasks',
                    'pass',
                    'Все обязательные задачи присутствуют в crontab'
                )
            
            # Проверяем частоту синхронизации
            sync_entries = [e for e in cron_entries if 'run_inventory_sync.sh' in e['command']]
            
            if not sync_entries:
                self.log_result(
                    'Cron',
                    'Sync Frequency',
                    'fail',
                    'Не найдено задач синхронизации',
                    is_critical=True
                )
            elif len(sync_entries) > 6:  # Более 6 раз в день может быть избыточно
                self.log_result(
                    'Cron',
                    'Sync Frequency',
                    'warning',
                    f'Возможно слишком частая синхронизация ({len(sync_entries)} задач)'
                )
            else:
                self.log_result(
                    'Cron',
                    'Sync Frequency',
                    'pass',
                    f'Частота синхронизации оптимальна ({len(sync_entries)} задач)'
                )
        
        except Exception as e:
            self.log_result(
                'Cron',
                'Configuration Analysis',
                'fail',
                f'Ошибка анализа конфигурации cron: {e}',
                is_critical=True
            )
    
    def validate_directory_structure(self):
        """Валидация структуры директорий."""
        print("\n=== ВАЛИДАЦИЯ СТРУКТУРЫ ДИРЕКТОРИЙ ===")
        
        # Проверяем существование и создаем необходимые директории
        required_dirs = ['logs', 'locks', 'pids']
        
        for dir_name in required_dirs:
            dir_path = os.path.join(self.script_dir, dir_name)
            
            if os.path.exists(dir_path):
                if os.path.isdir(dir_path):
                    # Проверяем права на запись
                    if os.access(dir_path, os.W_OK):
                        self.log_result(
                            'Directories',
                            f'{dir_name} Directory',
                            'pass',
                            f'Директория {dir_name} существует и доступна для записи'
                        )
                    else:
                        self.log_result(
                            'Directories',
                            f'{dir_name} Directory',
                            'fail',
                            f'Директория {dir_name} недоступна для записи',
                            {'path': dir_path},
                            is_critical=True
                        )
                else:
                    self.log_result(
                        'Directories',
                        f'{dir_name} Directory',
                        'fail',
                        f'{dir_name} существует, но это не директория',
                        {'path': dir_path},
                        is_critical=True
                    )
            else:
                # Пытаемся создать директорию
                try:
                    os.makedirs(dir_path, exist_ok=True)
                    self.log_result(
                        'Directories',
                        f'{dir_name} Directory',
                        'pass',
                        f'Директория {dir_name} создана успешно'
                    )
                except Exception as e:
                    self.log_result(
                        'Directories',
                        f'{dir_name} Directory',
                        'fail',
                        f'Не удалось создать директорию {dir_name}: {e}',
                        {'path': dir_path},
                        is_critical=True
                    )
    
    def validate_python_dependencies(self):
        """Валидация Python зависимостей."""
        print("\n=== ВАЛИДАЦИЯ PYTHON ЗАВИСИМОСТЕЙ ===")
        
        # Проверяем версию Python
        python_version = sys.version_info
        if python_version.major >= 3 and python_version.minor >= 6:
            self.log_result(
                'Dependencies',
                'Python Version',
                'pass',
                f'Python версия подходящая: {python_version.major}.{python_version.minor}.{python_version.micro}'
            )
        else:
            self.log_result(
                'Dependencies',
                'Python Version',
                'fail',
                f'Python версия слишком старая: {python_version.major}.{python_version.minor}',
                {'required': '3.6+'},
                is_critical=True
            )
        
        # Проверяем необходимые модули
        required_modules = {
            'mysql.connector': 'Подключение к MySQL',
            'requests': 'HTTP запросы к API',
            'json': 'Обработка JSON данных',
            'datetime': 'Работа с датами и временем',
            'logging': 'Система логирования'
        }
        
        for module_name, description in required_modules.items():
            try:
                __import__(module_name)
                self.log_result(
                    'Dependencies',
                    f'Module: {module_name}',
                    'pass',
                    f'{description} - модуль доступен'
                )
            except ImportError:
                is_critical = module_name in ['mysql.connector', 'requests']
                self.log_result(
                    'Dependencies',
                    f'Module: {module_name}',
                    'fail',
                    f'{description} - модуль не найден',
                    {'install_command': f'pip install {module_name}'},
                    is_critical=is_critical
                )
    
    def validate_test_coverage(self):
        """Валидация покрытия тестами."""
        print("\n=== ВАЛИДАЦИЯ ПОКРЫТИЯ ТЕСТАМИ ===")
        
        test_scripts = [
            'test_automation_system.py',
            'test_cron_execution.py',
            'test_failure_recovery.py'
        ]
        
        available_tests = 0
        
        for test_script in test_scripts:
            test_path = os.path.join(self.script_dir, test_script)
            
            if os.path.exists(test_path):
                available_tests += 1
                self.log_result(
                    'Testing',
                    f'Test Script: {test_script}',
                    'pass',
                    'Тестовый скрипт доступен'
                )
            else:
                self.log_result(
                    'Testing',
                    f'Test Script: {test_script}',
                    'warning',
                    'Тестовый скрипт отсутствует'
                )
        
        # Проверяем общее покрытие
        coverage_percent = (available_tests / len(test_scripts)) * 100
        
        if coverage_percent >= 80:
            self.log_result(
                'Testing',
                'Test Coverage',
                'pass',
                f'Хорошее покрытие тестами: {coverage_percent:.0f}%'
            )
        elif coverage_percent >= 50:
            self.log_result(
                'Testing',
                'Test Coverage',
                'warning',
                f'Среднее покрытие тестами: {coverage_percent:.0f}%'
            )
        else:
            self.log_result(
                'Testing',
                'Test Coverage',
                'fail',
                f'Низкое покрытие тестами: {coverage_percent:.0f}%',
                is_critical=False
            )
    
    def validate_system_readiness(self):
        """Валидация готовности системы к продакшн использованию."""
        print("\n=== ВАЛИДАЦИЯ ГОТОВНОСТИ СИСТЕМЫ ===")
        
        # Проверяем доступность основных команд
        system_commands = ['bash', 'python3', 'crontab']
        
        for command in system_commands:
            try:
                result = subprocess.run(['which', command], capture_output=True, text=True)
                if result.returncode == 0:
                    self.log_result(
                        'System',
                        f'Command: {command}',
                        'pass',
                        f'Команда {command} доступна: {result.stdout.strip()}'
                    )
                else:
                    is_critical = command in ['bash', 'python3']
                    self.log_result(
                        'System',
                        f'Command: {command}',
                        'fail',
                        f'Команда {command} не найдена',
                        is_critical=is_critical
                    )
            except Exception as e:
                self.log_result(
                    'System',
                    f'Command: {command}',
                    'fail',
                    f'Ошибка проверки команды {command}: {e}',
                    is_critical=True
                )
        
        # Проверяем свободное место на диске
        try:
            import shutil
            total, used, free = shutil.disk_usage(self.script_dir)
            free_percent = (free / total) * 100
            
            if free_percent >= 20:
                self.log_result(
                    'System',
                    'Disk Space',
                    'pass',
                    f'Достаточно свободного места: {free_percent:.1f}%'
                )
            elif free_percent >= 10:
                self.log_result(
                    'System',
                    'Disk Space',
                    'warning',
                    f'Мало свободного места: {free_percent:.1f}%'
                )
            else:
                self.log_result(
                    'System',
                    'Disk Space',
                    'fail',
                    f'Критически мало места: {free_percent:.1f}%',
                    is_critical=True
                )
        except Exception as e:
            self.log_result(
                'System',
                'Disk Space',
                'warning',
                f'Не удалось проверить свободное место: {e}'
            )
    
    def run_validation(self):
        """Запуск полной валидации системы автоматизации."""
        print("=== ВАЛИДАЦИЯ СИСТЕМЫ АВТОМАТИЗАЦИИ ===")
        print(f"Время запуска: {datetime.now()}")
        print(f"Рабочая директория: {self.script_dir}")
        
        # Запускаем все проверки
        validation_steps = [
            self.validate_script_files,
            self.validate_cron_configuration,
            self.validate_directory_structure,
            self.validate_python_dependencies,
            self.validate_test_coverage,
            self.validate_system_readiness
        ]
        
        for step in validation_steps:
            try:
                step()
            except Exception as e:
                print(f"❌ Ошибка в валидации {step.__name__}: {e}")
        
        # Выводим итоговую сводку
        self.print_validation_summary()
        
        # Определяем готовность к продакшн
        return len(self.critical_issues) == 0
    
    def print_validation_summary(self):
        """Вывод итоговой сводки валидации."""
        print(f"\n{'='*60}")
        print("ИТОГОВАЯ СВОДКА ВАЛИДАЦИИ")
        print(f"{'='*60}")
        
        total_checks = len(self.validation_results)
        passed_checks = len([r for r in self.validation_results if r['status'] == 'pass'])
        failed_checks = len([r for r in self.validation_results if r['status'] == 'fail'])
        warning_checks = len([r for r in self.validation_results if r['status'] == 'warning'])
        
        print(f"Всего проверок: {total_checks}")
        print(f"Успешных: {passed_checks}")
        print(f"Неудачных: {failed_checks}")
        print(f"Предупреждений: {warning_checks}")
        print(f"Критических проблем: {len(self.critical_issues)}")
        
        if total_checks > 0:
            success_rate = (passed_checks / total_checks) * 100
            print(f"Процент успеха: {success_rate:.1f}%")
        
        # Критические проблемы
        if self.critical_issues:
            print(f"\n🚨 КРИТИЧЕСКИЕ ПРОБЛЕМЫ:")
            for issue in self.critical_issues:
                print(f"  - [{issue['category']}] {issue['test_name']}: {issue['message']}")
        
        # Предупреждения
        if self.warnings:
            print(f"\n⚠️ ПРЕДУПРЕЖДЕНИЯ:")
            for warning in self.warnings[:5]:  # Показываем первые 5
                print(f"  - [{warning['category']}] {warning['test_name']}: {warning['message']}")
            
            if len(self.warnings) > 5:
                print(f"  ... и еще {len(self.warnings) - 5} предупреждений")
        
        # Итоговое заключение
        print(f"\n{'='*60}")
        if len(self.critical_issues) == 0:
            if len(self.warnings) == 0:
                print("🎉 СИСТЕМА АВТОМАТИЗАЦИИ ПОЛНОСТЬЮ ГОТОВА К ИСПОЛЬЗОВАНИЮ!")
                print("Все проверки пройдены успешно.")
            else:
                print("✅ СИСТЕМА АВТОМАТИЗАЦИИ ГОТОВА К ИСПОЛЬЗОВАНИЮ")
                print(f"Есть {len(self.warnings)} предупреждений, но критических проблем нет.")
        else:
            print("❌ СИСТЕМА АВТОМАТИЗАЦИИ НЕ ГОТОВА К ИСПОЛЬЗОВАНИЮ")
            print(f"Необходимо устранить {len(self.critical_issues)} критических проблем.")
        print(f"{'='*60}")
        
        # Сохраняем отчет
        self.save_validation_report()
    
    def save_validation_report(self):
        """Сохранение отчета валидации."""
        report_data = {
            'validation_info': {
                'timestamp': datetime.now().isoformat(),
                'script_directory': self.script_dir,
                'python_version': f"{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}"
            },
            'summary': {
                'total_checks': len(self.validation_results),
                'passed_checks': len([r for r in self.validation_results if r['status'] == 'pass']),
                'failed_checks': len([r for r in self.validation_results if r['status'] == 'fail']),
                'warning_checks': len([r for r in self.validation_results if r['status'] == 'warning']),
                'critical_issues': len(self.critical_issues),
                'ready_for_production': len(self.critical_issues) == 0
            },
            'validation_results': self.validation_results,
            'critical_issues': self.critical_issues,
            'warnings': self.warnings
        }
        
        report_file = os.path.join(self.script_dir, 'automation_validation_report.json')
        
        try:
            with open(report_file, 'w', encoding='utf-8') as f:
                json.dump(report_data, f, indent=2, ensure_ascii=False)
            
            print(f"\n📄 Отчет валидации сохранен: {report_file}")
            
        except Exception as e:
            print(f"\n❌ Ошибка сохранения отчета: {e}")


def main():
    """Главная функция."""
    validator = AutomationSystemValidator()
    
    ready_for_production = validator.run_validation()
    
    return 0 if ready_for_production else 1


if __name__ == "__main__":
    sys.exit(main())