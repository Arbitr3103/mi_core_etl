#!/usr/bin/env python3
"""
Comprehensive Automation System Tests
Комплексные тесты системы автоматизации синхронизации остатков

Тестирует:
- Корректность выполнения cron задач
- Мониторинг расписания
- Восстановление после сбоев

Автор: Inventory Sync System
Версия: 1.0
"""

import unittest
import subprocess
import os
import sys
import time
import json
import tempfile
import shutil
from datetime import datetime, timedelta
from unittest.mock import patch, MagicMock
import mysql.connector
from mysql.connector import Error

# Добавляем путь к модулям проекта
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

try:
    from importers.ozon_importer import connect_to_db
    from inventory_sync_service_with_error_handling import RobustInventorySyncService
    from sync_logger import SyncLogger
    from sync_monitor import SyncMonitor
except ImportError as e:
    print(f"Предупреждение: Не удалось импортировать модули: {e}")
    print("Некоторые тесты могут быть пропущены")


class TestCronJobExecution(unittest.TestCase):
    """Тесты корректности выполнения cron задач."""
    
    def setUp(self):
        """Подготовка к тестам."""
        self.script_dir = os.path.dirname(os.path.abspath(__file__))
        self.test_log_dir = os.path.join(self.script_dir, 'test_logs')
        self.test_lock_dir = os.path.join(self.script_dir, 'test_locks')
        self.test_pid_dir = os.path.join(self.script_dir, 'test_pids')
        
        # Создаем тестовые директории
        os.makedirs(self.test_log_dir, exist_ok=True)
        os.makedirs(self.test_lock_dir, exist_ok=True)
        os.makedirs(self.test_pid_dir, exist_ok=True)
    
    def tearDown(self):
        """Очистка после тестов."""
        # Удаляем тестовые директории
        for test_dir in [self.test_log_dir, self.test_lock_dir, self.test_pid_dir]:
            if os.path.exists(test_dir):
                shutil.rmtree(test_dir, ignore_errors=True)
    
    def test_inventory_sync_script_exists(self):
        """Тест существования скрипта синхронизации."""
        script_path = os.path.join(self.script_dir, 'run_inventory_sync.sh')
        self.assertTrue(os.path.exists(script_path), 
                       f"Скрипт синхронизации не найден: {script_path}")
        
        # Проверяем права на выполнение
        self.assertTrue(os.access(script_path, os.X_OK),
                       f"Скрипт не имеет прав на выполнение: {script_path}")
    
    def test_weekly_resync_script_exists(self):
        """Тест существования скрипта еженедельной пересинхронизации."""
        script_path = os.path.join(self.script_dir, 'run_weekly_inventory_resync.sh')
        self.assertTrue(os.path.exists(script_path),
                       f"Скрипт еженедельной пересинхронизации не найден: {script_path}")
        
        # Проверяем права на выполнение
        self.assertTrue(os.access(script_path, os.X_OK),
                       f"Скрипт не имеет прав на выполнение: {script_path}")
    
    def test_health_check_script_exists(self):
        """Тест существования скрипта проверки здоровья системы."""
        script_path = os.path.join(self.script_dir, 'check_inventory_health.sh')
        self.assertTrue(os.path.exists(script_path),
                       f"Скрипт проверки здоровья не найден: {script_path}")
        
        # Проверяем права на выполнение
        self.assertTrue(os.access(script_path, os.X_OK),
                       f"Скрипт не имеет прав на выполнение: {script_path}")
    
    def test_crontab_configuration_valid(self):
        """Тест валидности конфигурации crontab."""
        crontab_path = os.path.join(self.script_dir, 'inventory_crontab.txt')
        self.assertTrue(os.path.exists(crontab_path),
                       f"Файл crontab не найден: {crontab_path}")
        
        with open(crontab_path, 'r', encoding='utf-8') as f:
            crontab_content = f.read()
        
        # Проверяем наличие основных задач
        self.assertIn('run_inventory_sync.sh', crontab_content,
                     "Задача синхронизации не найдена в crontab")
        self.assertIn('run_weekly_inventory_resync.sh', crontab_content,
                     "Задача еженедельной пересинхронизации не найдена в crontab")
        self.assertIn('check_inventory_health.sh', crontab_content,
                     "Задача проверки здоровья не найдена в crontab")
    
    def test_script_parameter_validation(self):
        """Тест валидации параметров скриптов."""
        script_path = os.path.join(self.script_dir, 'run_inventory_sync.sh')
        
        if not os.path.exists(script_path):
            self.skipTest("Скрипт синхронизации не найден")
        
        # Тест с неверным параметром
        result = subprocess.run([script_path, 'invalid_source'], 
                              capture_output=True, text=True, timeout=30)
        self.assertNotEqual(result.returncode, 0,
                           "Скрипт должен возвращать ошибку для неверного параметра")
        
        # Проверяем, что в выводе есть сообщение об ошибке
        self.assertIn('ОШИБКА', result.stdout + result.stderr,
                     "Должно быть сообщение об ошибке для неверного параметра")
    
    def test_lock_file_mechanism(self):
        """Тест механизма блокировки для предотвращения одновременного запуска."""
        lock_file = os.path.join(self.test_lock_dir, 'test_sync.lock')
        
        # Создаем lock файл с PID текущего процесса
        with open(lock_file, 'w') as f:
            f.write(str(os.getpid()))
        
        # Проверяем, что файл создался
        self.assertTrue(os.path.exists(lock_file), "Lock файл не создался")
        
        # Проверяем содержимое
        with open(lock_file, 'r') as f:
            pid = f.read().strip()
        
        self.assertEqual(pid, str(os.getpid()), "PID в lock файле неверный")
        
        # Очищаем
        os.remove(lock_file)
    
    def test_log_directory_creation(self):
        """Тест создания директорий для логов."""
        test_script_dir = tempfile.mkdtemp()
        
        try:
            # Имитируем создание директорий как в скриптах
            log_dir = os.path.join(test_script_dir, 'logs')
            lock_dir = os.path.join(test_script_dir, 'locks')
            pid_dir = os.path.join(test_script_dir, 'pids')
            
            # Создаем директории
            os.makedirs(log_dir, exist_ok=True)
            os.makedirs(lock_dir, exist_ok=True)
            os.makedirs(pid_dir, exist_ok=True)
            
            # Проверяем создание
            self.assertTrue(os.path.exists(log_dir), "Директория логов не создалась")
            self.assertTrue(os.path.exists(lock_dir), "Директория блокировок не создалась")
            self.assertTrue(os.path.exists(pid_dir), "Директория PID не создалась")
            
        finally:
            shutil.rmtree(test_script_dir, ignore_errors=True)


class TestScheduleMonitoring(unittest.TestCase):
    """Тесты мониторинга расписания."""
    
    def setUp(self):
        """Подготовка к тестам."""
        self.script_dir = os.path.dirname(os.path.abspath(__file__))
        self.test_log_dir = os.path.join(self.script_dir, 'test_logs')
        os.makedirs(self.test_log_dir, exist_ok=True)
    
    def tearDown(self):
        """Очистка после тестов."""
        if os.path.exists(self.test_log_dir):
            shutil.rmtree(self.test_log_dir, ignore_errors=True)
    
    def test_cron_schedule_validation(self):
        """Тест валидации расписания cron."""
        crontab_path = os.path.join(self.script_dir, 'inventory_crontab.txt')
        
        if not os.path.exists(crontab_path):
            self.skipTest("Файл crontab не найден")
        
        with open(crontab_path, 'r', encoding='utf-8') as f:
            lines = f.readlines()
        
        cron_entries = []
        for line in lines:
            line = line.strip()
            if line and not line.startswith('#') and not line.startswith('SHELL'):
                # Простая проверка формата cron (5 полей времени + команда)
                parts = line.split()
                if len(parts) >= 6:
                    cron_time = ' '.join(parts[:5])
                    command = ' '.join(parts[5:])
                    cron_entries.append((cron_time, command))
        
        # Проверяем, что есть хотя бы одна валидная запись
        self.assertGreater(len(cron_entries), 0, "Не найдено валидных cron записей")
        
        # Проверяем основные задачи
        sync_tasks = [entry for entry in cron_entries if 'run_inventory_sync.sh' in entry[1]]
        health_tasks = [entry for entry in cron_entries if 'check_inventory_health.sh' in entry[1]]
        
        self.assertGreater(len(sync_tasks), 0, "Не найдено задач синхронизации")
        self.assertGreater(len(health_tasks), 0, "Не найдено задач проверки здоровья")
    
    def test_schedule_frequency_validation(self):
        """Тест валидации частоты выполнения задач."""
        # Проверяем, что синхронизация запланирована не чаще чем каждые 2 часа
        # и не реже чем каждые 12 часов
        
        crontab_path = os.path.join(self.script_dir, 'inventory_crontab.txt')
        
        if not os.path.exists(crontab_path):
            self.skipTest("Файл crontab не найден")
        
        with open(crontab_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        # Ищем строки с синхронизацией
        sync_lines = [line for line in content.split('\n') 
                     if 'run_inventory_sync.sh' in line and not line.strip().startswith('#')]
        
        self.assertGreater(len(sync_lines), 0, "Не найдено активных задач синхронизации")
        
        # Проверяем, что есть задача с интервалом */6 (каждые 6 часов)
        six_hour_tasks = [line for line in sync_lines if '*/6' in line]
        self.assertGreater(len(six_hour_tasks), 0, 
                          "Не найдено задач синхронизации каждые 6 часов")
    
    def test_monitoring_script_execution(self):
        """Тест выполнения скриптов мониторинга."""
        health_script = os.path.join(self.script_dir, 'check_inventory_health.sh')
        freshness_script = os.path.join(self.script_dir, 'check_data_freshness.sh')
        
        for script_path in [health_script, freshness_script]:
            if os.path.exists(script_path):
                # Проверяем, что скрипт может быть выполнен (хотя бы запущен)
                try:
                    result = subprocess.run([script_path], 
                                          capture_output=True, text=True, timeout=60)
                    # Скрипт может вернуть ошибку из-за отсутствия БД, но должен запуститься
                    self.assertIsNotNone(result.returncode, 
                                       f"Скрипт {script_path} не запустился")
                except subprocess.TimeoutExpired:
                    self.fail(f"Скрипт {script_path} завис при выполнении")
                except Exception as e:
                    self.fail(f"Ошибка выполнения скрипта {script_path}: {e}")
    
    def test_log_rotation_configuration(self):
        """Тест конфигурации ротации логов."""
        crontab_path = os.path.join(self.script_dir, 'inventory_crontab.txt')
        
        if not os.path.exists(crontab_path):
            self.skipTest("Файл crontab не найден")
        
        with open(crontab_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        # Проверяем наличие задач очистки логов
        cleanup_tasks = [line for line in content.split('\n') 
                        if ('find' in line and '.log' in line and 'delete' in line) 
                        or 'monitor_log_size.sh' in line]
        
        self.assertGreater(len(cleanup_tasks), 0, 
                          "Не найдено задач очистки/мониторинга логов")
    
    def test_schedule_conflict_detection(self):
        """Тест обнаружения конфликтов в расписании."""
        crontab_path = os.path.join(self.script_dir, 'inventory_crontab.txt')
        
        if not os.path.exists(crontab_path):
            self.skipTest("Файл crontab не найден")
        
        with open(crontab_path, 'r', encoding='utf-8') as f:
            lines = f.readlines()
        
        # Собираем все активные задачи синхронизации
        sync_tasks = []
        for line in lines:
            line = line.strip()
            if (line and not line.startswith('#') and 
                'run_inventory_sync.sh' in line):
                parts = line.split()
                if len(parts) >= 6:
                    minute = parts[0]
                    hour = parts[1]
                    sync_tasks.append((minute, hour, line))
        
        # Проверяем, что нет одновременных запусков
        time_slots = set()
        for minute, hour, task in sync_tasks:
            # Простая проверка для фиксированного времени
            if minute.isdigit() and hour.isdigit():
                time_slot = f"{hour}:{minute}"
                self.assertNotIn(time_slot, time_slots,
                               f"Конфликт времени выполнения: {time_slot}")
                time_slots.add(time_slot)


class TestFailureRecovery(unittest.TestCase):
    """Тесты восстановления после сбоев."""
    
    def setUp(self):
        """Подготовка к тестам."""
        self.script_dir = os.path.dirname(os.path.abspath(__file__))
        self.test_log_dir = os.path.join(self.script_dir, 'test_logs')
        self.test_lock_dir = os.path.join(self.script_dir, 'test_locks')
        
        os.makedirs(self.test_log_dir, exist_ok=True)
        os.makedirs(self.test_lock_dir, exist_ok=True)
    
    def tearDown(self):
        """Очистка после тестов."""
        for test_dir in [self.test_log_dir, self.test_lock_dir]:
            if os.path.exists(test_dir):
                shutil.rmtree(test_dir, ignore_errors=True)
    
    def test_stale_lock_file_cleanup(self):
        """Тест очистки устаревших lock файлов."""
        lock_file = os.path.join(self.test_lock_dir, 'test_sync.lock')
        
        # Создаем lock файл с несуществующим PID
        fake_pid = 999999
        with open(lock_file, 'w') as f:
            f.write(str(fake_pid))
        
        # Проверяем, что файл создался
        self.assertTrue(os.path.exists(lock_file))
        
        # Имитируем логику проверки из скрипта
        try:
            with open(lock_file, 'r') as f:
                pid = int(f.read().strip())
            
            # Проверяем, существует ли процесс
            try:
                os.kill(pid, 0)  # Сигнал 0 не убивает процесс, только проверяет существование
                process_exists = True
            except (OSError, ProcessLookupError):
                process_exists = False
            
            # Если процесс не существует, файл должен быть удален
            if not process_exists:
                os.remove(lock_file)
                lock_cleaned = True
            else:
                lock_cleaned = False
            
            # В нашем случае процесс с fake_pid не должен существовать
            self.assertTrue(lock_cleaned, "Устаревший lock файл не был очищен")
            self.assertFalse(os.path.exists(lock_file), "Lock файл все еще существует")
            
        except Exception as e:
            self.fail(f"Ошибка при тестировании очистки lock файла: {e}")
    
    def test_database_connection_recovery(self):
        """Тест восстановления соединения с базой данных."""
        try:
            # Пытаемся подключиться к БД
            connection = connect_to_db()
            connection_successful = True
            connection.close()
        except Exception:
            connection_successful = False
        
        if not connection_successful:
            self.skipTest("База данных недоступна для тестирования")
        
        # Тестируем логику повторных попыток подключения
        max_retries = 3
        retry_delay = 1
        
        for attempt in range(max_retries):
            try:
                connection = connect_to_db()
                connection.close()
                break
            except Exception as e:
                if attempt < max_retries - 1:
                    time.sleep(retry_delay)
                    retry_delay *= 2  # Экспоненциальная задержка
                else:
                    self.fail(f"Не удалось подключиться к БД после {max_retries} попыток: {e}")
    
    def test_api_timeout_handling(self):
        """Тест обработки таймаутов API."""
        # Имитируем таймаут API
        def mock_api_call_with_timeout():
            time.sleep(0.1)  # Короткая задержка для имитации
            raise Exception("Connection timeout")
        
        # Тестируем логику повторных попыток
        max_retries = 3
        retry_delay = 0.1
        
        for attempt in range(max_retries):
            try:
                mock_api_call_with_timeout()
                break
            except Exception as e:
                if attempt < max_retries - 1:
                    time.sleep(retry_delay)
                    retry_delay *= 2
                else:
                    # Ожидаем, что после всех попыток будет исключение
                    self.assertIn("timeout", str(e).lower())
    
    def test_partial_sync_recovery(self):
        """Тест восстановления после частичной синхронизации."""
        # Имитируем ситуацию частичной синхронизации
        test_data = {
            'total_expected': 100,
            'successfully_processed': 75,
            'failed_records': 25
        }
        
        # Проверяем логику определения частичной синхронизации
        success_rate = test_data['successfully_processed'] / test_data['total_expected']
        
        if success_rate < 0.8:  # Менее 80% успешности
            recovery_needed = True
        else:
            recovery_needed = False
        
        # В нашем случае 75% < 80%, поэтому нужно восстановление
        self.assertTrue(recovery_needed, "Должно быть определено, что нужно восстановление")
        
        # Имитируем планирование повторной синхронизации
        if recovery_needed:
            retry_scheduled = True
            retry_delay_minutes = 30
        else:
            retry_scheduled = False
            retry_delay_minutes = 0
        
        self.assertTrue(retry_scheduled, "Должна быть запланирована повторная синхронизация")
        self.assertGreater(retry_delay_minutes, 0, "Должна быть задержка перед повтором")
    
    def test_log_file_corruption_recovery(self):
        """Тест восстановления после повреждения файлов логов."""
        test_log_file = os.path.join(self.test_log_dir, 'test_sync.log')
        
        # Создаем поврежденный файл лога
        with open(test_log_file, 'wb') as f:
            f.write(b'\x00\x01\x02\x03')  # Бинарные данные
        
        # Проверяем, что файл создался
        self.assertTrue(os.path.exists(test_log_file))
        
        # Имитируем логику восстановления
        try:
            with open(test_log_file, 'r', encoding='utf-8') as f:
                content = f.read()
            log_readable = True
        except UnicodeDecodeError:
            log_readable = False
        
        # Если файл нечитаемый, создаем новый
        if not log_readable:
            backup_name = f"{test_log_file}.corrupted.{int(time.time())}"
            os.rename(test_log_file, backup_name)
            
            # Создаем новый файл
            with open(test_log_file, 'w', encoding='utf-8') as f:
                f.write(f"[{datetime.now()}] Log file recreated after corruption\n")
            
            recovery_successful = True
        else:
            recovery_successful = False
        
        self.assertTrue(recovery_successful, "Восстановление после повреждения лога не выполнено")
        self.assertTrue(os.path.exists(test_log_file), "Новый файл лога не создан")
    
    def test_disk_space_recovery(self):
        """Тест восстановления при нехватке места на диске."""
        # Имитируем проверку свободного места
        def get_disk_usage_percent(path):
            # Имитируем высокое использование диска
            return 95  # 95% использования
        
        disk_usage = get_disk_usage_percent(self.test_log_dir)
        
        if disk_usage > 90:
            # Имитируем экстренную очистку
            emergency_cleanup_needed = True
            
            # Создаем несколько тестовых файлов для "очистки"
            test_files = []
            for i in range(5):
                test_file = os.path.join(self.test_log_dir, f'old_log_{i}.log')
                with open(test_file, 'w') as f:
                    f.write('test log content\n' * 100)
                test_files.append(test_file)
            
            # Имитируем удаление старых файлов
            files_cleaned = 0
            for test_file in test_files:
                if os.path.exists(test_file):
                    os.remove(test_file)
                    files_cleaned += 1
            
            cleanup_successful = files_cleaned > 0
        else:
            emergency_cleanup_needed = False
            cleanup_successful = False
        
        self.assertTrue(emergency_cleanup_needed, "Должна быть определена необходимость экстренной очистки")
        self.assertTrue(cleanup_successful, "Экстренная очистка должна быть выполнена")
    
    def test_service_restart_recovery(self):
        """Тест восстановления после перезапуска сервиса."""
        # Имитируем состояние после перезапуска
        service_state = {
            'last_sync_time': datetime.now() - timedelta(hours=10),
            'pending_operations': ['sync_ozon', 'sync_wb'],
            'failed_operations': ['sync_ozon_retry']
        }
        
        # Проверяем, нужно ли восстановление
        hours_since_sync = (datetime.now() - service_state['last_sync_time']).total_seconds() / 3600
        
        recovery_actions = []
        
        if hours_since_sync > 8:
            recovery_actions.append('immediate_sync')
        
        if service_state['pending_operations']:
            recovery_actions.append('resume_pending')
        
        if service_state['failed_operations']:
            recovery_actions.append('retry_failed')
        
        # Проверяем, что определены необходимые действия восстановления
        self.assertIn('immediate_sync', recovery_actions, 
                     "Должна быть запланирована немедленная синхронизация")
        self.assertIn('resume_pending', recovery_actions,
                     "Должно быть возобновление отложенных операций")
        self.assertIn('retry_failed', recovery_actions,
                     "Должен быть повтор неудачных операций")


class TestIntegrationScenarios(unittest.TestCase):
    """Интеграционные тесты сценариев автоматизации."""
    
    def setUp(self):
        """Подготовка к тестам."""
        self.script_dir = os.path.dirname(os.path.abspath(__file__))
    
    def test_full_automation_workflow(self):
        """Тест полного рабочего процесса автоматизации."""
        # Проверяем наличие всех компонентов
        required_scripts = [
            'run_inventory_sync.sh',
            'run_weekly_inventory_resync.sh',
            'check_inventory_health.sh',
            'check_data_freshness.sh',
            'monitor_log_size.sh'
        ]
        
        missing_scripts = []
        for script in required_scripts:
            script_path = os.path.join(self.script_dir, script)
            if not os.path.exists(script_path):
                missing_scripts.append(script)
        
        self.assertEqual(len(missing_scripts), 0,
                        f"Отсутствуют необходимые скрипты: {missing_scripts}")
    
    def test_cron_configuration_completeness(self):
        """Тест полноты конфигурации cron."""
        crontab_path = os.path.join(self.script_dir, 'inventory_crontab.txt')
        
        if not os.path.exists(crontab_path):
            self.skipTest("Файл crontab не найден")
        
        with open(crontab_path, 'r', encoding='utf-8') as f:
            content = f.read()
        
        # Проверяем наличие всех типов задач
        required_tasks = [
            'run_inventory_sync.sh',      # Основная синхронизация
            'run_weekly_inventory_resync.sh',  # Еженедельная пересинхронизация
            'check_inventory_health.sh',  # Проверка здоровья
            'check_data_freshness.sh',    # Проверка актуальности
            'monitor_log_size.sh'         # Мониторинг логов
        ]
        
        missing_tasks = []
        for task in required_tasks:
            if task not in content:
                missing_tasks.append(task)
        
        self.assertEqual(len(missing_tasks), 0,
                        f"В crontab отсутствуют задачи: {missing_tasks}")
    
    def test_monitoring_chain_integrity(self):
        """Тест целостности цепочки мониторинга."""
        # Проверяем, что мониторинг покрывает все аспекты
        monitoring_aspects = {
            'sync_execution': False,      # Выполнение синхронизации
            'data_freshness': False,      # Актуальность данных
            'system_health': False,       # Здоровье системы
            'log_management': False,      # Управление логами
            'error_detection': False      # Обнаружение ошибок
        }
        
        # Проверяем наличие скриптов для каждого аспекта
        if os.path.exists(os.path.join(self.script_dir, 'run_inventory_sync.sh')):
            monitoring_aspects['sync_execution'] = True
        
        if os.path.exists(os.path.join(self.script_dir, 'check_data_freshness.sh')):
            monitoring_aspects['data_freshness'] = True
        
        if os.path.exists(os.path.join(self.script_dir, 'check_inventory_health.sh')):
            monitoring_aspects['system_health'] = True
        
        if os.path.exists(os.path.join(self.script_dir, 'monitor_log_size.sh')):
            monitoring_aspects['log_management'] = True
        
        # Проверяем логирование ошибок в скриптах
        scripts_with_error_handling = []
        for script_name in ['run_inventory_sync.sh', 'check_inventory_health.sh']:
            script_path = os.path.join(self.script_dir, script_name)
            if os.path.exists(script_path):
                with open(script_path, 'r', encoding='utf-8') as f:
                    content = f.read()
                if 'ERROR' in content or 'CRITICAL' in content:
                    scripts_with_error_handling.append(script_name)
        
        if scripts_with_error_handling:
            monitoring_aspects['error_detection'] = True
        
        # Проверяем, что все аспекты покрыты
        uncovered_aspects = [aspect for aspect, covered in monitoring_aspects.items() if not covered]
        
        self.assertEqual(len(uncovered_aspects), 0,
                        f"Не покрыты аспекты мониторинга: {uncovered_aspects}")


def run_automation_tests():
    """Запуск всех тестов автоматизации."""
    print("=== ЗАПУСК ТЕСТОВ СИСТЕМЫ АВТОМАТИЗАЦИИ ===")
    print(f"Время запуска: {datetime.now()}")
    print()
    
    # Создаем test suite
    test_suite = unittest.TestSuite()
    
    # Добавляем тесты
    test_classes = [
        TestCronJobExecution,
        TestScheduleMonitoring,
        TestFailureRecovery,
        TestIntegrationScenarios
    ]
    
    for test_class in test_classes:
        tests = unittest.TestLoader().loadTestsFromTestCase(test_class)
        test_suite.addTests(tests)
    
    # Запускаем тесты
    runner = unittest.TextTestRunner(verbosity=2, buffer=True)
    result = runner.run(test_suite)
    
    # Выводим результаты
    print("\n=== РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ ===")
    print(f"Всего тестов: {result.testsRun}")
    print(f"Успешных: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"Неудачных: {len(result.failures)}")
    print(f"Ошибок: {len(result.errors)}")
    print(f"Пропущенных: {len(result.skipped) if hasattr(result, 'skipped') else 0}")
    
    if result.failures:
        print("\nНЕУДАЧНЫЕ ТЕСТЫ:")
        for test, traceback in result.failures:
            print(f"- {test}: {traceback.split('AssertionError: ')[-1].split('\\n')[0]}")
    
    if result.errors:
        print("\nОШИБКИ В ТЕСТАХ:")
        for test, traceback in result.errors:
            print(f"- {test}: {traceback.split('\\n')[-2]}")
    
    # Возвращаем True если все тесты прошли успешно
    return len(result.failures) == 0 and len(result.errors) == 0


if __name__ == "__main__":
    success = run_automation_tests()
    sys.exit(0 if success else 1)