#!/usr/bin/env python3
"""
Комплексный запуск всех тестов API управления синхронизацией остатков.

Включает:
- Integration тесты для всех endpoints
- Тесты веб-интерфейса
- Проверки безопасности API endpoints
- Тесты производительности

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
import sys
import os
import time
import subprocess
import signal
from datetime import datetime

# Добавляем путь к модулям
sys.path.append(os.path.dirname(os.path.dirname(__file__)))

try:
    # Импортируем все тестовые модули
    from test_inventory_sync_api import TestInventorySyncAPI, TestInventorySyncAPIClass
    from test_inventory_sync_api_integration import (
        TestInventorySyncAPIIntegration,
        TestInventorySyncAPIWebInterface,
        TestInventorySyncAPISecurity,
        TestInventorySyncAPIPerformance
    )
    from test_inventory_sync_api_security import (
        TestAPISecurityVulnerabilities,
        TestAPIInputValidation,
        TestAPIAuthenticationSecurity,
        TestAPIRateLimitingSecurity,
        TestAPIErrorHandlingSecurity
    )
    from test_inventory_sync_web_interface import (
        TestWebInterfaceBasic,
        TestWebInterfaceInteractive,
        TestWebInterfaceAccessibility,
        TestWebInterfacePerformance
    )
except ImportError as e:
    print(f"❌ Ошибка импорта тестовых модулей: {e}")
    sys.exit(1)


class APITestRunner:
    """Класс для управления запуском тестов API."""
    
    def __init__(self):
        """Инициализация test runner."""
        self.api_process = None
        self.base_url = "http://localhost:5001"
        self.test_results = {}
    
    def start_api_server(self):
        """Запуск API сервера для тестирования."""
        try:
            print("🚀 Запуск API сервера для тестирования...")
            
            # Запускаем API сервер в отдельном процессе
            self.api_process = subprocess.Popen(
                [sys.executable, 'start_inventory_sync_api.py'],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE
            )
            
            # Ждем запуска сервера
            self._wait_for_server()
            print("✅ API сервер запущен успешно")
            
        except Exception as e:
            print(f"❌ Ошибка запуска API сервера: {e}")
            return False
        
        return True
    
    def stop_api_server(self):
        """Остановка API сервера."""
        if self.api_process:
            print("🛑 Остановка API сервера...")
            self.api_process.terminate()
            
            # Ждем завершения процесса
            try:
                self.api_process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                self.api_process.kill()
                self.api_process.wait()
            
            print("✅ API сервер остановлен")
    
    def _wait_for_server(self):
        """Ожидание запуска сервера."""
        import requests
        
        max_attempts = 30
        for attempt in range(max_attempts):
            try:
                response = requests.get(f"{self.base_url}/api/sync/health", timeout=2)
                if response.status_code == 200:
                    return
            except requests.exceptions.RequestException:
                pass
            
            time.sleep(1)
        
        raise Exception("Не удалось дождаться запуска API сервера")
    
    def run_unit_tests(self):
        """Запуск unit тестов."""
        print("\n" + "=" * 80)
        print("🧪 Запуск Unit тестов")
        print("=" * 80)
        
        test_suite = unittest.TestSuite()
        test_suite.addTest(unittest.makeSuite(TestInventorySyncAPI))
        test_suite.addTest(unittest.makeSuite(TestInventorySyncAPIClass))
        
        runner = unittest.TextTestRunner(verbosity=2)
        result = runner.run(test_suite)
        
        self.test_results['unit'] = {
            'total': result.testsRun,
            'failures': len(result.failures),
            'errors': len(result.errors),
            'success': result.wasSuccessful()
        }
        
        return result.wasSuccessful()
    
    def run_integration_tests(self):
        """Запуск интеграционных тестов."""
        print("\n" + "=" * 80)
        print("🔗 Запуск Integration тестов")
        print("=" * 80)
        
        test_suite = unittest.TestSuite()
        test_suite.addTest(unittest.makeSuite(TestInventorySyncAPIIntegration))
        test_suite.addTest(unittest.makeSuite(TestInventorySyncAPIWebInterface))
        test_suite.addTest(unittest.makeSuite(TestInventorySyncAPISecurity))
        test_suite.addTest(unittest.makeSuite(TestInventorySyncAPIPerformance))
        
        runner = unittest.TextTestRunner(verbosity=2)
        result = runner.run(test_suite)
        
        self.test_results['integration'] = {
            'total': result.testsRun,
            'failures': len(result.failures),
            'errors': len(result.errors),
            'success': result.wasSuccessful()
        }
        
        return result.wasSuccessful()
    
    def run_security_tests(self):
        """Запуск тестов безопасности."""
        print("\n" + "=" * 80)
        print("🔒 Запуск тестов безопасности")
        print("=" * 80)
        
        test_suite = unittest.TestSuite()
        test_suite.addTest(unittest.makeSuite(TestAPISecurityVulnerabilities))
        test_suite.addTest(unittest.makeSuite(TestAPIInputValidation))
        test_suite.addTest(unittest.makeSuite(TestAPIAuthenticationSecurity))
        test_suite.addTest(unittest.makeSuite(TestAPIRateLimitingSecurity))
        test_suite.addTest(unittest.makeSuite(TestAPIErrorHandlingSecurity))
        
        runner = unittest.TextTestRunner(verbosity=2)
        result = runner.run(test_suite)
        
        self.test_results['security'] = {
            'total': result.testsRun,
            'failures': len(result.failures),
            'errors': len(result.errors),
            'success': result.wasSuccessful()
        }
        
        return result.wasSuccessful()
    
    def run_web_interface_tests(self):
        """Запуск тестов веб-интерфейса."""
        print("\n" + "=" * 80)
        print("🌐 Запуск тестов веб-интерфейса")
        print("=" * 80)
        
        test_suite = unittest.TestSuite()
        test_suite.addTest(unittest.makeSuite(TestWebInterfaceBasic))
        test_suite.addTest(unittest.makeSuite(TestWebInterfaceInteractive))
        test_suite.addTest(unittest.makeSuite(TestWebInterfaceAccessibility))
        test_suite.addTest(unittest.makeSuite(TestWebInterfacePerformance))
        
        runner = unittest.TextTestRunner(verbosity=2)
        result = runner.run(test_suite)
        
        self.test_results['web_interface'] = {
            'total': result.testsRun,
            'failures': len(result.failures),
            'errors': len(result.errors),
            'success': result.wasSuccessful()
        }
        
        return result.wasSuccessful()
    
    def print_summary(self):
        """Вывод итогового отчета."""
        print("\n" + "=" * 80)
        print("📊 ИТОГОВЫЙ ОТЧЕТ ТЕСТИРОВАНИЯ API")
        print("=" * 80)
        
        total_tests = 0
        total_failures = 0
        total_errors = 0
        all_success = True
        
        for test_type, results in self.test_results.items():
            total_tests += results['total']
            total_failures += results['failures']
            total_errors += results['errors']
            
            if not results['success']:
                all_success = False
            
            status_icon = "✅" if results['success'] else "❌"
            print(f"{status_icon} {test_type.upper()}: {results['total']} тестов, "
                  f"{results['failures']} неудач, {results['errors']} ошибок")
        
        print("\n" + "-" * 80)
        print(f"📈 ОБЩАЯ СТАТИСТИКА:")
        print(f"   Всего тестов: {total_tests}")
        print(f"   Успешных: {total_tests - total_failures - total_errors}")
        print(f"   Неудачных: {total_failures}")
        print(f"   Ошибок: {total_errors}")
        
        if all_success:
            print(f"\n🎉 ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО!")
        else:
            print(f"\n⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ В ТЕСТАХ")
        
        print("=" * 80)
        
        return all_success
    
    def run_all_tests(self):
        """Запуск всех тестов."""
        start_time = datetime.now()
        
        print("🧪 КОМПЛЕКСНОЕ ТЕСТИРОВАНИЕ API УПРАВЛЕНИЯ СИНХРОНИЗАЦИЕЙ ОСТАТКОВ")
        print("=" * 80)
        print(f"Время начала: {start_time.strftime('%Y-%m-%d %H:%M:%S')}")
        
        try:
            # Запускаем API сервер
            if not self.start_api_server():
                print("❌ Не удалось запустить API сервер")
                return False
            
            # Запускаем все группы тестов
            success = True
            
            # Unit тесты (не требуют запущенного сервера)
            if not self.run_unit_tests():
                success = False
            
            # Integration тесты
            if not self.run_integration_tests():
                success = False
            
            # Тесты безопасности
            if not self.run_security_tests():
                success = False
            
            # Тесты веб-интерфейса
            if not self.run_web_interface_tests():
                success = False
            
            # Выводим итоговый отчет
            final_success = self.print_summary()
            
            end_time = datetime.now()
            duration = end_time - start_time
            
            print(f"\nВремя завершения: {end_time.strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"Общее время выполнения: {duration}")
            
            return final_success and success
            
        except KeyboardInterrupt:
            print("\n⚠️  Тестирование прервано пользователем")
            return False
        except Exception as e:
            print(f"\n❌ Критическая ошибка при тестировании: {e}")
            return False
        finally:
            # Всегда останавливаем сервер
            self.stop_api_server()


def main():
    """Главная функция запуска тестов."""
    # Проверяем аргументы командной строки
    test_type = sys.argv[1] if len(sys.argv) > 1 else 'all'
    
    runner = APITestRunner()
    
    try:
        if test_type == 'unit':
            success = runner.run_unit_tests()
        elif test_type == 'integration':
            if runner.start_api_server():
                success = runner.run_integration_tests()
                runner.stop_api_server()
            else:
                success = False
        elif test_type == 'security':
            if runner.start_api_server():
                success = runner.run_security_tests()
                runner.stop_api_server()
            else:
                success = False
        elif test_type == 'web':
            if runner.start_api_server():
                success = runner.run_web_interface_tests()
                runner.stop_api_server()
            else:
                success = False
        elif test_type == 'all':
            success = runner.run_all_tests()
        else:
            print(f"❌ Неизвестный тип тестов: {test_type}")
            print("Доступные типы: unit, integration, security, web, all")
            success = False
        
        sys.exit(0 if success else 1)
        
    except KeyboardInterrupt:
        print("\n⚠️  Тестирование прервано")
        runner.stop_api_server()
        sys.exit(1)
    except Exception as e:
        print(f"❌ Критическая ошибка: {e}")
        runner.stop_api_server()
        sys.exit(1)


if __name__ == '__main__':
    main()