#!/usr/bin/env python3
"""
Специализированные тесты безопасности для API управления синхронизацией остатков.

Проверяет:
- Аутентификацию и авторизацию
- Защиту от атак
- Валидацию входных данных
- Безопасность конфигурации

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
import requests
import json
import time
import sys
import os
from unittest.mock import patch, MagicMock
import urllib.parse
import base64

# Добавляем путь к модулям
sys.path.append(os.path.dirname(os.path.dirname(__file__)))


class TestAPISecurityVulnerabilities(unittest.TestCase):
    """Тесты на уязвимости безопасности API."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
        self.headers = {'Content-Type': 'application/json'}
    
    def test_sql_injection_attacks(self):
        """Тест защиты от SQL инъекций."""
        sql_payloads = [
            "'; DROP TABLE sync_logs; --",
            "' OR '1'='1",
            "' UNION SELECT * FROM sync_logs --",
            "'; INSERT INTO sync_logs VALUES (1,2,3,4,5,6,7,8,9,10); --",
            "' AND (SELECT COUNT(*) FROM sync_logs) > 0 --"
        ]
        
        for payload in sql_payloads:
            # Тестируем через параметр source
            response = requests.get(f"{self.base_url}/api/sync/logs?source={urllib.parse.quote(payload)}")
            
            # API должен обработать запрос безопасно
            self.assertEqual(response.status_code, 200, f"SQL injection payload failed: {payload}")
            
            # Проверяем, что система все еще работает
            health_response = requests.get(f"{self.base_url}/api/sync/health")
            self.assertEqual(health_response.status_code, 200)
    
    def test_xss_attacks(self):
        """Тест защиты от XSS атак."""
        xss_payloads = [
            "<script>alert('xss')</script>",
            "<img src=x onerror=alert('xss')>",
            "javascript:alert('xss')",
            "<svg onload=alert('xss')>",
            "';alert('xss');//"
        ]
        
        for payload in xss_payloads:
            # Тестируем через параметры API
            response = requests.get(f"{self.base_url}/api/sync/logs?source={urllib.parse.quote(payload)}")
            
            self.assertEqual(response.status_code, 200)
            
            # Проверяем, что ответ не содержит исполняемый код
            if response.headers.get('Content-Type', '').startswith('application/json'):
                data = response.json()
                response_text = json.dumps(data)
                
                # Не должно содержать теги script или обработчики событий
                dangerous_patterns = ['<script', 'javascript:', 'onerror=', 'onload=']
                for pattern in dangerous_patterns:
                    self.assertNotIn(pattern.lower(), response_text.lower())
    
    def test_command_injection_attacks(self):
        """Тест защиты от инъекций команд."""
        command_payloads = [
            "; ls -la",
            "| cat /etc/passwd",
            "&& rm -rf /",
            "`whoami`",
            "$(id)"
        ]
        
        for payload in command_payloads:
            # Тестируем через POST данные
            response = requests.post(
                f"{self.base_url}/api/sync/trigger",
                json={"sources": [payload]},
                headers=self.headers
            )
            
            # API должен отклонить невалидные источники
            self.assertIn(response.status_code, [400, 422])
    
    def test_path_traversal_attacks(self):
        """Тест защиты от атак обхода пути."""
        path_payloads = [
            "../../../etc/passwd",
            "..\\..\\..\\windows\\system32\\config\\sam",
            "%2e%2e%2f%2e%2e%2f%2e%2e%2fetc%2fpasswd",
            "....//....//....//etc/passwd"
        ]
        
        for payload in path_payloads:
            # Тестируем через параметры
            response = requests.get(f"{self.base_url}/api/sync/logs?source={urllib.parse.quote(payload)}")
            
            self.assertEqual(response.status_code, 200)
            
            # Проверяем, что не возвращается содержимое системных файлов
            if response.headers.get('Content-Type', '').startswith('application/json'):
                data = response.json()
                response_text = json.dumps(data)
                
                # Не должно содержать типичное содержимое системных файлов
                system_patterns = ['root:x:', '[boot loader]', 'etc/passwd']
                for pattern in system_patterns:
                    self.assertNotIn(pattern, response_text)
    
    def test_http_header_injection(self):
        """Тест защиты от инъекций в HTTP заголовки."""
        malicious_headers = {
            'X-Forwarded-For': '127.0.0.1\r\nSet-Cookie: admin=true',
            'User-Agent': 'Mozilla/5.0\r\nX-Admin: true',
            'Referer': 'http://example.com\r\nLocation: http://evil.com'
        }
        
        for header_name, header_value in malicious_headers.items():
            response = requests.get(
                f"{self.base_url}/api/sync/status",
                headers={header_name: header_value}
            )
            
            # API должен обработать запрос
            self.assertEqual(response.status_code, 200)
            
            # Проверяем, что вредоносные заголовки не отражаются в ответе
            response_headers = str(response.headers)
            self.assertNotIn('Set-Cookie: admin=true', response_headers)
            self.assertNotIn('X-Admin: true', response_headers)


class TestAPIInputValidation(unittest.TestCase):
    """Тесты валидации входных данных API."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
        self.headers = {'Content-Type': 'application/json'}
    
    def test_json_payload_validation(self):
        """Тест валидации JSON данных."""
        invalid_payloads = [
            "invalid json",
            '{"sources": [}',  # Невалидный JSON
            '{"sources": "not_array"}',  # Неправильный тип
            '{"sources": [null]}',  # null значения
            '{"sources": [""]}',  # Пустые строки
        ]
        
        for payload in invalid_payloads:
            response = requests.post(
                f"{self.base_url}/api/sync/trigger",
                data=payload,
                headers=self.headers
            )
            
            # API должен отклонить невалидные данные
            self.assertIn(response.status_code, [400, 422])
    
    def test_parameter_type_validation(self):
        """Тест валидации типов параметров."""
        # Тест с невалидными типами для days
        invalid_days = ['abc', '-1', '999999', 'null', '[]']
        
        for days_value in invalid_days:
            response = requests.get(f"{self.base_url}/api/sync/reports?days={days_value}")
            
            # API должен обработать или отклонить невалидные значения
            self.assertIn(response.status_code, [200, 400])
            
            if response.status_code == 200:
                data = response.json()
                # Если обработал, должен использовать значение по умолчанию или ограничить
                self.assertIn(data['data']['period_days'], range(1, 91))
    
    def test_parameter_length_limits(self):
        """Тест ограничений длины параметров."""
        # Очень длинная строка
        long_string = 'A' * 10000
        
        response = requests.get(f"{self.base_url}/api/sync/logs?source={long_string}")
        
        # API должен обработать запрос (может обрезать или отклонить)
        self.assertIn(response.status_code, [200, 400, 414])  # 414 = URI Too Long
    
    def test_special_characters_handling(self):
        """Тест обработки специальных символов."""
        special_chars = [
            'тест',  # Кириллица
            '测试',   # Китайские символы
            '🔄',    # Emoji
            '\x00\x01\x02',  # Контрольные символы
            '\\n\\r\\t',     # Escape последовательности
        ]
        
        for chars in special_chars:
            response = requests.get(f"{self.base_url}/api/sync/logs?source={urllib.parse.quote(chars)}")
            
            # API должен безопасно обработать специальные символы
            self.assertEqual(response.status_code, 200)


class TestAPIAuthenticationSecurity(unittest.TestCase):
    """Тесты безопасности аутентификации API."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_no_authentication_bypass(self):
        """Тест отсутствия обхода аутентификации."""
        # Попытки обхода аутентификации через заголовки
        bypass_headers = [
            {'X-Forwarded-User': 'admin'},
            {'X-Remote-User': 'admin'},
            {'Authorization': 'Bearer fake_token'},
            {'X-API-Key': 'fake_key'},
            {'Cookie': 'session=admin_session'}
        ]
        
        for headers in bypass_headers:
            response = requests.get(f"{self.base_url}/api/sync/status", headers=headers)
            
            # API должен работать одинаково независимо от заголовков
            self.assertEqual(response.status_code, 200)
    
    def test_session_security(self):
        """Тест безопасности сессий."""
        # Проверяем, что API не использует небезопасные сессии
        response = requests.get(f"{self.base_url}/api/sync/status")
        
        # Не должно устанавливать небезопасные cookies
        cookies = response.cookies
        for cookie in cookies:
            # Проверяем флаги безопасности (если cookies используются)
            if hasattr(cookie, 'secure'):
                self.assertTrue(cookie.secure or 'localhost' in self.base_url)
            if hasattr(cookie, 'httponly'):
                self.assertTrue(cookie.httponly)


class TestAPIRateLimitingSecurity(unittest.TestCase):
    """Тесты защиты от DoS атак и rate limiting."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_rapid_requests_handling(self):
        """Тест обработки быстрых запросов."""
        # Отправляем много запросов быстро
        responses = []
        start_time = time.time()
        
        for i in range(50):
            try:
                response = requests.get(f"{self.base_url}/api/sync/status", timeout=1)
                responses.append(response.status_code)
            except requests.exceptions.Timeout:
                responses.append(408)  # Timeout
        
        end_time = time.time()
        
        # Проверяем, что сервер не упал
        self.assertGreater(len(responses), 0)
        
        # Большинство запросов должны быть успешными
        successful_requests = sum(1 for status in responses if status == 200)
        self.assertGreater(successful_requests, len(responses) * 0.5)  # Минимум 50% успешных
    
    def test_large_payload_handling(self):
        """Тест обработки больших данных."""
        # Создаем большой payload
        large_sources = ['Source' + str(i) for i in range(1000)]
        large_payload = {"sources": large_sources}
        
        response = requests.post(
            f"{self.base_url}/api/sync/trigger",
            json=large_payload,
            headers={'Content-Type': 'application/json'},
            timeout=10
        )
        
        # API должен обработать или отклонить большой payload
        self.assertIn(response.status_code, [200, 400, 413, 422])  # 413 = Payload Too Large


class TestAPIErrorHandlingSecurity(unittest.TestCase):
    """Тесты безопасности обработки ошибок."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_error_information_disclosure(self):
        """Тест на раскрытие информации в ошибках."""
        # Запросы, которые могут вызвать ошибки
        error_requests = [
            ('GET', '/api/nonexistent'),
            ('POST', '/api/sync/status'),  # Неправильный метод
            ('GET', '/api/sync/trigger'),  # Неправильный метод
        ]
        
        for method, endpoint in error_requests:
            if method == 'GET':
                response = requests.get(f"{self.base_url}{endpoint}")
            else:
                response = requests.post(f"{self.base_url}{endpoint}")
            
            # Проверяем, что ошибки не раскрывают чувствительную информацию
            if response.headers.get('Content-Type', '').startswith('application/json'):
                try:
                    data = response.json()
                    error_text = json.dumps(data).lower()
                except:
                    error_text = response.text.lower()
            else:
                error_text = response.text.lower()
            
            # Не должно содержать пути к файлам, стек трейсы и т.д.
            sensitive_patterns = [
                '/home/', '/var/', '/usr/', 'c:\\',  # Пути к файлам
                'traceback', 'stack trace', 'exception',  # Стек трейсы
                'password', 'secret', 'key', 'token',  # Секреты
                'mysql', 'database', 'connection string',  # Информация о БД
            ]
            
            for pattern in sensitive_patterns:
                self.assertNotIn(pattern, error_text, 
                               f"Sensitive information '{pattern}' found in error response for {method} {endpoint}")
    
    def test_database_error_handling(self):
        """Тест обработки ошибок базы данных."""
        # Симулируем ошибку БД через мокирование
        with patch('inventory_sync_api.api_instance.cursor') as mock_cursor:
            mock_cursor.execute.side_effect = Exception("Database connection failed")
            
            response = requests.get(f"{self.base_url}/api/sync/status")
            
            # API должен обработать ошибку БД gracefully
            self.assertIn(response.status_code, [200, 500, 503])
            
            if response.status_code == 500:
                # Ошибка не должна раскрывать детали БД
                if response.headers.get('Content-Type', '').startswith('application/json'):
                    data = response.json()
                    error_text = json.dumps(data).lower()
                    self.assertNotIn('database connection failed', error_text)


def run_security_tests():
    """Запуск всех тестов безопасности."""
    print("🔒 Запуск тестов безопасности API управления синхронизацией остатков")
    print("=" * 80)
    
    # Создаем test suite
    test_suite = unittest.TestSuite()
    
    # Добавляем тесты уязвимостей
    test_suite.addTest(unittest.makeSuite(TestAPISecurityVulnerabilities))
    
    # Добавляем тесты валидации
    test_suite.addTest(unittest.makeSuite(TestAPIInputValidation))
    
    # Добавляем тесты аутентификации
    test_suite.addTest(unittest.makeSuite(TestAPIAuthenticationSecurity))
    
    # Добавляем тесты rate limiting
    test_suite.addTest(unittest.makeSuite(TestAPIRateLimitingSecurity))
    
    # Добавляем тесты обработки ошибок
    test_suite.addTest(unittest.makeSuite(TestAPIErrorHandlingSecurity))
    
    # Запускаем тесты
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(test_suite)
    
    # Выводим результаты
    print("\n" + "=" * 80)
    print("🔒 Результаты тестов безопасности:")
    print(f"✅ Успешных тестов: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"❌ Неудачных тестов: {len(result.failures)}")
    print(f"💥 Ошибок: {len(result.errors)}")
    
    if result.failures:
        print("\n❌ Проблемы безопасности:")
        for test, traceback in result.failures:
            print(f"  - {test}")
    
    if result.errors:
        print("\n💥 Ошибки тестирования:")
        for test, traceback in result.errors:
            print(f"  - {test}")
    
    return result.wasSuccessful()


if __name__ == '__main__':
    success = run_security_tests()
    sys.exit(0 if success else 1)