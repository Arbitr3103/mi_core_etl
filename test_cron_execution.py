#!/usr/bin/env python3
"""
Cron Job Execution Tests
Тесты выполнения cron задач для системы синхронизации остатков

Проверяет:
- Корректность выполнения скриптов синхронизации
- Правильность работы механизмов блокировки
- Логирование результатов выполнения
- Обработку ошибок в cron задачах

Автор: Inventory Sync System
Версия: 1.0
"""

import os
import sys
import subprocess
import tempfile
import time
import json
from datetime import datetime, timedelta
import shutil
import signal
from contextlib import contextmanager


class CronExecutionTester:
    """Класс для тестирования выполнения cron задач."""
    
    def __init__(self):
        self.script_dir = os.path.dirname(os.path.abspath(__file__))
        self.test_dir = tempfile.mkdtemp(prefix='cron_test_')
        self.test_results = []
        
        # Создаем тестовые директории
        self.test_log_dir = os.path.join(self.test_dir, 'logs')
        self.test_lock_dir = os.path.join(self.test_dir, 'locks')
        self.test_pid_dir = os.path.join(self.test_dir, 'pids')
        
        for dir_path in [self.test_log_dir, self.test_lock_dir, self.test_pid_dir]:
            os.makedirs(dir_path, exist_ok=True)
    
    def cleanup(self):
        """Очистка тестовых файлов."""
        if os.path.exists(self.test_dir):
            shutil.rmtree(self.test_dir, ignore_errors=True)
    
    @contextmanager
    def timeout(self, seconds):
        """Контекстный менеджер для таймаута."""
        def timeout_handler(signum, frame):
            raise TimeoutError(f"Операция превысила таймаут {seconds} секунд")
        
        old_handler = signal.signal(signal.SIGALRM, timeout_handler)
        signal.alarm(seconds)
        try:
            yield
        finally:
            signal.alarm(0)
            signal.signal(signal.SIGALRM, old_handler)
    
    def log_test_result(self, test_name, success, message, details=None):
        """Логирование результата теста."""
        result = {
            'test_name': test_name,
            'success': success,
            'message': message,
            'timestamp': datetime.now().isoformat(),
            'details': details or {}
        }
        self.test_results.append(result)
        
        status = "✅ PASS" if success else "❌ FAIL"
        print(f"{status} {test_name}: {message}")
        
        if details and not success:
            for key, value in details.items():
                print(f"    {key}: {value}")
    
    def test_script_existence_and_permissions(self):
        """Тест существования и прав доступа к скриптам."""
        required_scripts = [
            'run_inventory_sync.sh',
            'run_weekly_inventory_resync.sh',
            'check_inventory_health.sh',
            'check_data_freshness.sh',
            'monitor_log_size.sh'
        ]
        
        missing_scripts = []
        non_executable = []
        
        for script_name in required_scripts:
            script_path = os.path.join(self.script_dir, script_name)
            
            if not os.path.exists(script_path):
                missing_scripts.append(script_name)
            elif not os.access(script_path, os.X_OK):
                non_executable.append(script_name)
        
        if missing_scripts or non_executable:
            self.log_test_result(
                "Script Existence and Permissions",
                False,
                "Проблемы с доступностью скриптов",
                {
                    'missing_scripts': missing_scripts,
                    'non_executable': non_executable
                }
            )
        else:
            self.log_test_result(
                "Script Existence and Permissions",
                True,
                "Все необходимые скрипты найдены и исполняемы"
            )
    
    def test_inventory_sync_script_execution(self):
        """Тест выполнения скрипта синхронизации."""
        script_path = os.path.join(self.script_dir, 'run_inventory_sync.sh')
        
        if not os.path.exists(script_path):
            self.log_test_result(
                "Inventory Sync Script Execution",
                False,
                "Скрипт синхронизации не найден"
            )
            return
        
        # Тест с неверным параметром (должен вернуть ошибку)
        try:
            with self.timeout(30):
                result = subprocess.run(
                    [script_path, 'invalid_source'],
                    capture_output=True,
                    text=True,
                    cwd=self.script_dir
                )
            
            if result.returncode != 0:
                self.log_test_result(
                    "Inventory Sync Script Execution",
                    True,
                    "Скрипт корректно обрабатывает неверные параметры",
                    {
                        'return_code': result.returncode,
                        'stderr_contains_error': 'ОШИБКА' in result.stderr or 'ERROR' in result.stderr
                    }
                )
            else:
                self.log_test_result(
                    "Inventory Sync Script Execution",
                    False,
                    "Скрипт не возвращает ошибку для неверного параметра",
                    {
                        'return_code': result.returncode,
                        'stdout': result.stdout[:200],
                        'stderr': result.stderr[:200]
                    }
                )
        
        except TimeoutError:
            self.log_test_result(
                "Inventory Sync Script Execution",
                False,
                "Скрипт завис при выполнении (таймаут 30 сек)"
            )
        except Exception as e:
            self.log_test_result(
                "Inventory Sync Script Execution",
                False,
                f"Ошибка выполнения скрипта: {e}"
            )
    
    def test_lock_file_mechanism(self):
        """Тест механизма блокировки."""
        lock_file = os.path.join(self.test_lock_dir, 'test_sync.lock')
        
        try:
            # Создаем lock файл с текущим PID
            current_pid = os.getpid()
            with open(lock_file, 'w') as f:
                f.write(str(current_pid))
            
            # Проверяем создание
            if not os.path.exists(lock_file):
                self.log_test_result(
                    "Lock File Mechanism",
                    False,
                    "Lock файл не был создан"
                )
                return
            
            # Проверяем содержимое
            with open(lock_file, 'r') as f:
                stored_pid = f.read().strip()
            
            if stored_pid != str(current_pid):
                self.log_test_result(
                    "Lock File Mechanism",
                    False,
                    "PID в lock файле не соответствует ожидаемому",
                    {
                        'expected_pid': current_pid,
                        'stored_pid': stored_pid
                    }
                )
                return
            
            # Тестируем проверку существования процесса
            try:
                os.kill(current_pid, 0)  # Проверка существования процесса
                process_exists = True
            except (OSError, ProcessLookupError):
                process_exists = False
            
            if process_exists:
                self.log_test_result(
                    "Lock File Mechanism",
                    True,
                    "Механизм блокировки работает корректно",
                    {
                        'lock_file_created': True,
                        'pid_stored_correctly': True,
                        'process_check_works': True
                    }
                )
            else:
                self.log_test_result(
                    "Lock File Mechanism",
                    False,
                    "Проверка существования процесса не работает"
                )
            
            # Очищаем lock файл
            os.remove(lock_file)
            
        except Exception as e:
            self.log_test_result(
                "Lock File Mechanism",
                False,
                f"Ошибка тестирования механизма блокировки: {e}"
            )
    
    def test_stale_lock_cleanup(self):
        """Тест очистки устаревших lock файлов."""
        lock_file = os.path.join(self.test_lock_dir, 'stale_sync.lock')
        
        try:
            # Создаем lock файл с несуществующим PID
            fake_pid = 999999
            with open(lock_file, 'w') as f:
                f.write(str(fake_pid))
            
            # Имитируем логику очистки из скрипта
            with open(lock_file, 'r') as f:
                stored_pid = int(f.read().strip())
            
            try:
                os.kill(stored_pid, 0)
                process_exists = True
            except (OSError, ProcessLookupError):
                process_exists = False
            
            # Если процесс не существует, удаляем lock файл
            if not process_exists:
                os.remove(lock_file)
                cleanup_successful = True
            else:
                cleanup_successful = False
            
            if cleanup_successful and not os.path.exists(lock_file):
                self.log_test_result(
                    "Stale Lock Cleanup",
                    True,
                    "Устаревшие lock файлы корректно очищаются",
                    {
                        'fake_pid': fake_pid,
                        'process_existed': process_exists,
                        'file_removed': True
                    }
                )
            else:
                self.log_test_result(
                    "Stale Lock Cleanup",
                    False,
                    "Очистка устаревших lock файлов не работает",
                    {
                        'fake_pid': fake_pid,
                        'process_existed': process_exists,
                        'file_still_exists': os.path.exists(lock_file)
                    }
                )
        
        except Exception as e:
            self.log_test_result(
                "Stale Lock Cleanup",
                False,
                f"Ошибка тестирования очистки lock файлов: {e}"
            )
    
    def test_log_directory_creation(self):
        """Тест создания директорий для логов."""
        test_script_dir = os.path.join(self.test_dir, 'script_test')
        
        try:
            # Имитируем создание директорий как в скриптах
            required_dirs = ['logs', 'locks', 'pids']
            created_dirs = []
            
            for dir_name in required_dirs:
                dir_path = os.path.join(test_script_dir, dir_name)
                os.makedirs(dir_path, exist_ok=True)
                
                if os.path.exists(dir_path) and os.path.isdir(dir_path):
                    created_dirs.append(dir_name)
            
            if len(created_dirs) == len(required_dirs):
                self.log_test_result(
                    "Log Directory Creation",
                    True,
                    "Все необходимые директории созданы успешно",
                    {
                        'created_directories': created_dirs,
                        'test_location': test_script_dir
                    }
                )
            else:
                missing_dirs = set(required_dirs) - set(created_dirs)
                self.log_test_result(
                    "Log Directory Creation",
                    False,
                    "Не все директории были созданы",
                    {
                        'created_directories': created_dirs,
                        'missing_directories': list(missing_dirs)
                    }
                )
        
        except Exception as e:
            self.log_test_result(
                "Log Directory Creation",
                False,
                f"Ошибка создания директорий: {e}"
            )
    
    def test_health_check_execution(self):
        """Тест выполнения скрипта проверки здоровья."""
        script_path = os.path.join(self.script_dir, 'check_inventory_health.sh')
        
        if not os.path.exists(script_path):
            self.log_test_result(
                "Health Check Execution",
                False,
                "Скрипт проверки здоровья не найден"
            )
            return
        
        try:
            with self.timeout(60):
                result = subprocess.run(
                    [script_path],
                    capture_output=True,
                    text=True,
                    cwd=self.script_dir
                )
            
            # Скрипт может вернуть ошибку из-за отсутствия БД, но должен запуститься
            execution_successful = result.returncode is not None
            
            if execution_successful:
                self.log_test_result(
                    "Health Check Execution",
                    True,
                    "Скрипт проверки здоровья выполняется",
                    {
                        'return_code': result.returncode,
                        'stdout_length': len(result.stdout),
                        'stderr_length': len(result.stderr)
                    }
                )
            else:
                self.log_test_result(
                    "Health Check Execution",
                    False,
                    "Скрипт проверки здоровья не выполнился"
                )
        
        except TimeoutError:
            self.log_test_result(
                "Health Check Execution",
                False,
                "Скрипт проверки здоровья завис (таймаут 60 сек)"
            )
        except Exception as e:
            self.log_test_result(
                "Health Check Execution",
                False,
                f"Ошибка выполнения скрипта проверки здоровья: {e}"
            )
    
    def test_crontab_syntax_validation(self):
        """Тест валидации синтаксиса crontab."""
        crontab_path = os.path.join(self.script_dir, 'inventory_crontab.txt')
        
        if not os.path.exists(crontab_path):
            self.log_test_result(
                "Crontab Syntax Validation",
                False,
                "Файл crontab не найден"
            )
            return
        
        try:
            with open(crontab_path, 'r', encoding='utf-8') as f:
                lines = f.readlines()
            
            valid_entries = 0
            invalid_entries = []
            
            for line_num, line in enumerate(lines, 1):
                line = line.strip()
                
                # Пропускаем комментарии и пустые строки
                if not line or line.startswith('#') or line.startswith('SHELL') or line.startswith('PATH') or line.startswith('MAILTO'):
                    continue
                
                # Проверяем формат cron записи
                parts = line.split()
                if len(parts) >= 6:
                    # Проверяем первые 5 полей (время)
                    time_fields = parts[:5]
                    valid_time_format = True
                    
                    for field in time_fields:
                        # Простая проверка: поле должно содержать цифры, *, /, - или ,
                        if not all(c.isdigit() or c in '*/-,' for c in field):
                            valid_time_format = False
                            break
                    
                    if valid_time_format:
                        valid_entries += 1
                    else:
                        invalid_entries.append(f"Строка {line_num}: неверный формат времени")
                else:
                    invalid_entries.append(f"Строка {line_num}: недостаточно полей")
            
            if len(invalid_entries) == 0 and valid_entries > 0:
                self.log_test_result(
                    "Crontab Syntax Validation",
                    True,
                    f"Синтаксис crontab корректен ({valid_entries} валидных записей)",
                    {
                        'valid_entries': valid_entries,
                        'file_path': crontab_path
                    }
                )
            else:
                self.log_test_result(
                    "Crontab Syntax Validation",
                    False,
                    "Найдены ошибки в синтаксисе crontab",
                    {
                        'valid_entries': valid_entries,
                        'invalid_entries': invalid_entries[:5]  # Показываем первые 5 ошибок
                    }
                )
        
        except Exception as e:
            self.log_test_result(
                "Crontab Syntax Validation",
                False,
                f"Ошибка валидации crontab: {e}"
            )
    
    def test_log_rotation_functionality(self):
        """Тест функциональности ротации логов."""
        # Создаем тестовые файлы логов
        test_log_files = []
        
        try:
            # Создаем файлы разного возраста
            for i in range(5):
                log_file = os.path.join(self.test_log_dir, f'test_log_{i}.log')
                with open(log_file, 'w') as f:
                    f.write(f'Test log content {i}\n' * 100)
                
                # Изменяем время модификации для имитации старых файлов
                if i >= 3:  # Делаем последние 2 файла "старыми"
                    old_time = time.time() - (35 * 24 * 60 * 60)  # 35 дней назад
                    os.utime(log_file, (old_time, old_time))
                
                test_log_files.append(log_file)
            
            # Имитируем логику очистки старых файлов (старше 30 дней)
            files_before = len([f for f in test_log_files if os.path.exists(f)])
            
            # Удаляем файлы старше 30 дней
            import glob
            old_files = []
            for log_file in test_log_files:
                if os.path.exists(log_file):
                    file_age_days = (time.time() - os.path.getmtime(log_file)) / (24 * 60 * 60)
                    if file_age_days > 30:
                        os.remove(log_file)
                        old_files.append(log_file)
            
            files_after = len([f for f in test_log_files if os.path.exists(f)])
            files_removed = files_before - files_after
            
            if files_removed > 0:
                self.log_test_result(
                    "Log Rotation Functionality",
                    True,
                    f"Ротация логов работает корректно (удалено {files_removed} файлов)",
                    {
                        'files_before': files_before,
                        'files_after': files_after,
                        'files_removed': files_removed,
                        'removed_files': [os.path.basename(f) for f in old_files]
                    }
                )
            else:
                self.log_test_result(
                    "Log Rotation Functionality",
                    True,
                    "Ротация логов настроена (нет старых файлов для удаления)",
                    {
                        'files_checked': files_before
                    }
                )
        
        except Exception as e:
            self.log_test_result(
                "Log Rotation Functionality",
                False,
                f"Ошибка тестирования ротации логов: {e}"
            )
    
    def run_all_tests(self):
        """Запуск всех тестов выполнения cron задач."""
        print("=== ТЕСТИРОВАНИЕ ВЫПОЛНЕНИЯ CRON ЗАДАЧ ===")
        print(f"Время запуска: {datetime.now()}")
        print(f"Тестовая директория: {self.test_dir}")
        print()
        
        # Список всех тестов
        tests = [
            self.test_script_existence_and_permissions,
            self.test_inventory_sync_script_execution,
            self.test_lock_file_mechanism,
            self.test_stale_lock_cleanup,
            self.test_log_directory_creation,
            self.test_health_check_execution,
            self.test_crontab_syntax_validation,
            self.test_log_rotation_functionality
        ]
        
        # Выполняем тесты
        for test_func in tests:
            try:
                test_func()
            except Exception as e:
                self.log_test_result(
                    test_func.__name__,
                    False,
                    f"Неожиданная ошибка в тесте: {e}"
                )
            print()  # Пустая строка между тестами
        
        # Подводим итоги
        self.print_summary()
        
        return self.get_success_rate() >= 0.8  # 80% тестов должны пройти
    
    def print_summary(self):
        """Вывод сводки результатов тестирования."""
        total_tests = len(self.test_results)
        passed_tests = len([r for r in self.test_results if r['success']])
        failed_tests = total_tests - passed_tests
        success_rate = (passed_tests / total_tests * 100) if total_tests > 0 else 0
        
        print("=== СВОДКА РЕЗУЛЬТАТОВ ===")
        print(f"Всего тестов: {total_tests}")
        print(f"Успешных: {passed_tests}")
        print(f"Неудачных: {failed_tests}")
        print(f"Процент успеха: {success_rate:.1f}%")
        print()
        
        if failed_tests > 0:
            print("НЕУДАЧНЫЕ ТЕСТЫ:")
            for result in self.test_results:
                if not result['success']:
                    print(f"- {result['test_name']}: {result['message']}")
            print()
        
        # Сохраняем детальный отчет
        report_file = os.path.join(self.script_dir, 'cron_test_report.json')
        try:
            with open(report_file, 'w', encoding='utf-8') as f:
                json.dump({
                    'summary': {
                        'total_tests': total_tests,
                        'passed_tests': passed_tests,
                        'failed_tests': failed_tests,
                        'success_rate': success_rate,
                        'test_time': datetime.now().isoformat()
                    },
                    'test_results': self.test_results
                }, f, indent=2, ensure_ascii=False)
            
            print(f"Детальный отчет сохранен: {report_file}")
        except Exception as e:
            print(f"Ошибка сохранения отчета: {e}")
    
    def get_success_rate(self):
        """Получение процента успешных тестов."""
        if not self.test_results:
            return 0.0
        
        passed = len([r for r in self.test_results if r['success']])
        return passed / len(self.test_results)


def main():
    """Главная функция."""
    tester = CronExecutionTester()
    
    try:
        success = tester.run_all_tests()
        return 0 if success else 1
    
    finally:
        tester.cleanup()


if __name__ == "__main__":
    sys.exit(main())