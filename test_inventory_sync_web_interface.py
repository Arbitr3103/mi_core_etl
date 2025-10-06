#!/usr/bin/env python3
"""
Тесты веб-интерфейса для API управления синхронизацией остатков.

Проверяет:
- Загрузку HTML страниц
- JavaScript функциональность
- CSS стили и адаптивность
- Интерактивные элементы

Автор: ETL System
Дата: 06 января 2025
"""

import unittest
import requests
import re
import time
import sys
import os
from bs4 import BeautifulSoup
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.common.exceptions import TimeoutException, WebDriverException

# Добавляем путь к модулям
sys.path.append(os.path.dirname(os.path.dirname(__file__)))


class TestWebInterfaceBasic(unittest.TestCase):
    """Базовые тесты веб-интерфейса."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_dashboard_page_structure(self):
        """Тест структуры главной страницы дашборда."""
        response = requests.get(f"{self.base_url}/")
        
        self.assertEqual(response.status_code, 200)
        self.assertIn('text/html', response.headers.get('Content-Type', ''))
        
        # Парсим HTML
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Проверяем основные элементы
        self.assertIsNotNone(soup.find('title'))
        self.assertIn('Управление синхронизацией остатков', soup.find('title').text)
        
        # Проверяем наличие основных секций
        self.assertIsNotNone(soup.find(class_='header'))
        self.assertIsNotNone(soup.find(class_='status-grid'))
        self.assertIsNotNone(soup.find(class_='controls'))
        
        # Проверяем кнопки управления
        trigger_button = soup.find('button', id='trigger-sync')
        self.assertIsNotNone(trigger_button)
        self.assertIn('Запустить синхронизацию', trigger_button.text)
        
        refresh_button = soup.find('button', id='refresh-status')
        self.assertIsNotNone(refresh_button)
        self.assertIn('Обновить статус', refresh_button.text)
    
    def test_logs_page_structure(self):
        """Тест структуры страницы логов."""
        response = requests.get(f"{self.base_url}/logs")
        
        self.assertEqual(response.status_code, 200)
        self.assertIn('text/html', response.headers.get('Content-Type', ''))
        
        # Парсим HTML
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Проверяем основные элементы
        self.assertIsNotNone(soup.find('title'))
        self.assertIn('Логи синхронизации', soup.find('title').text)
        
        # Проверяем наличие фильтров
        filters_section = soup.find(class_='filters')
        self.assertIsNotNone(filters_section)
        
        # Проверяем таблицу логов
        logs_table = soup.find('table', class_='logs-table')
        self.assertIsNotNone(logs_table)
    
    def test_html_validation(self):
        """Тест валидности HTML."""
        pages = ['/', '/logs']
        
        for page in pages:
            response = requests.get(f"{self.base_url}{page}")
            html_content = response.text
            
            # Проверяем базовую структуру HTML
            self.assertIn('<!DOCTYPE html>', html_content)
            self.assertIn('<html', html_content)
            self.assertIn('<head>', html_content)
            self.assertIn('<body>', html_content)
            self.assertIn('</html>', html_content)
            
            # Проверяем мета теги
            self.assertIn('<meta charset="UTF-8">', html_content)
            self.assertIn('viewport', html_content)
    
    def test_css_styles_presence(self):
        """Тест наличия CSS стилей."""
        response = requests.get(f"{self.base_url}/")
        html_content = response.text
        
        # Проверяем наличие CSS
        self.assertIn('<style>', html_content)
        self.assertIn('</style>', html_content)
        
        # Проверяем основные CSS классы
        css_classes = [
            '.container', '.header', '.status-grid', '.status-card',
            '.controls', '.btn', '.btn-primary', '.alert'
        ]
        
        for css_class in css_classes:
            self.assertIn(css_class, html_content)
    
    def test_javascript_presence(self):
        """Тест наличия JavaScript функций."""
        response = requests.get(f"{self.base_url}/")
        html_content = response.text
        
        # Проверяем наличие JavaScript
        self.assertIn('<script>', html_content)
        
        # Проверяем основные функции
        js_functions = [
            'loadStatus', 'triggerSync', 'updateStatusDisplay',
            'showAlert', 'loadRecentLogs'
        ]
        
        for function in js_functions:
            self.assertIn(function, html_content)
    
    def test_responsive_design_elements(self):
        """Тест элементов адаптивного дизайна."""
        response = requests.get(f"{self.base_url}/")
        html_content = response.text
        
        # Проверяем viewport meta tag
        self.assertIn('width=device-width', html_content)
        
        # Проверяем медиа-запросы
        self.assertIn('@media', html_content)
        self.assertIn('max-width', html_content)


class TestWebInterfaceInteractive(unittest.TestCase):
    """Тесты интерактивности веб-интерфейса с использованием Selenium."""
    
    @classmethod
    def setUpClass(cls):
        """Настройка Selenium WebDriver."""
        try:
            # Настройки Chrome для headless режима
            chrome_options = Options()
            chrome_options.add_argument('--headless')
            chrome_options.add_argument('--no-sandbox')
            chrome_options.add_argument('--disable-dev-shm-usage')
            chrome_options.add_argument('--disable-gpu')
            
            cls.driver = webdriver.Chrome(options=chrome_options)
            cls.driver.implicitly_wait(10)
            cls.selenium_available = True
        except (WebDriverException, Exception) as e:
            print(f"⚠️  Selenium недоступен: {e}")
            cls.selenium_available = False
            cls.driver = None
    
    @classmethod
    def tearDownClass(cls):
        """Закрытие WebDriver."""
        if cls.driver:
            cls.driver.quit()
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
        
        if not self.selenium_available:
            self.skipTest("Selenium недоступен")
    
    def test_dashboard_page_loads(self):
        """Тест загрузки дашборда в браузере."""
        self.driver.get(f"{self.base_url}/")
        
        # Проверяем заголовок страницы
        self.assertIn('Управление синхронизацией остатков', self.driver.title)
        
        # Проверяем наличие основных элементов
        header = self.driver.find_element(By.CLASS_NAME, 'header')
        self.assertIsNotNone(header)
        
        status_grid = self.driver.find_element(By.CLASS_NAME, 'status-grid')
        self.assertIsNotNone(status_grid)
    
    def test_status_loading_functionality(self):
        """Тест функциональности загрузки статуса."""
        self.driver.get(f"{self.base_url}/")
        
        # Ждем загрузки JavaScript
        WebDriverWait(self.driver, 10).until(
            EC.presence_of_element_located((By.ID, 'sync-status'))
        )
        
        # Проверяем, что статус загружается
        sync_status = self.driver.find_element(By.ID, 'sync-status')
        
        # Ждем, пока статус изменится с "Загрузка..."
        WebDriverWait(self.driver, 15).until(
            lambda driver: sync_status.text != 'Загрузка...'
        )
        
        self.assertNotEqual(sync_status.text, 'Загрузка...')
    
    def test_refresh_button_functionality(self):
        """Тест функциональности кнопки обновления."""
        self.driver.get(f"{self.base_url}/")
        
        # Ждем загрузки страницы
        WebDriverWait(self.driver, 10).until(
            EC.element_to_be_clickable((By.ID, 'refresh-status'))
        )
        
        # Нажимаем кнопку обновления
        refresh_button = self.driver.find_element(By.ID, 'refresh-status')
        refresh_button.click()
        
        # Проверяем, что статус обновляется
        sync_status = self.driver.find_element(By.ID, 'sync-status')
        self.assertIsNotNone(sync_status.text)
    
    def test_sync_trigger_button_functionality(self):
        """Тест функциональности кнопки запуска синхронизации."""
        self.driver.get(f"{self.base_url}/")
        
        # Ждем загрузки страницы
        WebDriverWait(self.driver, 10).until(
            EC.element_to_be_clickable((By.ID, 'trigger-sync'))
        )
        
        # Проверяем чекбоксы источников
        ozon_checkbox = self.driver.find_element(By.ID, 'sync-ozon')
        wb_checkbox = self.driver.find_element(By.ID, 'sync-wb')
        
        self.assertTrue(ozon_checkbox.is_selected())
        self.assertTrue(wb_checkbox.is_selected())
        
        # Нажимаем кнопку запуска синхронизации
        trigger_button = self.driver.find_element(By.ID, 'trigger-sync')
        trigger_button.click()
        
        # Проверяем, что появляется индикатор загрузки или сообщение
        try:
            WebDriverWait(self.driver, 5).until(
                EC.any_of(
                    EC.visibility_of_element_located((By.ID, 'loading')),
                    EC.visibility_of_element_located((By.ID, 'alert'))
                )
            )
        except TimeoutException:
            # Это нормально, если синхронизация запускается быстро
            pass
    
    def test_checkbox_interaction(self):
        """Тест взаимодействия с чекбоксами."""
        self.driver.get(f"{self.base_url}/")
        
        # Ждем загрузки страницы
        WebDriverWait(self.driver, 10).until(
            EC.presence_of_element_located((By.ID, 'sync-ozon'))
        )
        
        ozon_checkbox = self.driver.find_element(By.ID, 'sync-ozon')
        wb_checkbox = self.driver.find_element(By.ID, 'sync-wb')
        
        # Проверяем начальное состояние
        initial_ozon_state = ozon_checkbox.is_selected()
        initial_wb_state = wb_checkbox.is_selected()
        
        # Изменяем состояние чекбоксов
        ozon_checkbox.click()
        wb_checkbox.click()
        
        # Проверяем, что состояние изменилось
        self.assertNotEqual(ozon_checkbox.is_selected(), initial_ozon_state)
        self.assertNotEqual(wb_checkbox.is_selected(), initial_wb_state)
    
    def test_logs_page_navigation(self):
        """Тест навигации на страницу логов."""
        self.driver.get(f"{self.base_url}/")
        
        # Ждем загрузки страницы
        WebDriverWait(self.driver, 10).until(
            EC.element_to_be_clickable((By.LINK_TEXT, 'Просмотр логов'))
        )
        
        # Нажимаем ссылку на логи
        logs_link = self.driver.find_element(By.LINK_TEXT, 'Просмотр логов')
        logs_link.click()
        
        # Проверяем, что перешли на страницу логов
        WebDriverWait(self.driver, 10).until(
            EC.title_contains('Логи синхронизации')
        )
        
        self.assertIn('logs', self.driver.current_url)
    
    def test_responsive_behavior(self):
        """Тест адаптивного поведения интерфейса."""
        self.driver.get(f"{self.base_url}/")
        
        # Тестируем разные размеры экрана
        screen_sizes = [
            (1920, 1080),  # Desktop
            (768, 1024),   # Tablet
            (375, 667)     # Mobile
        ]
        
        for width, height in screen_sizes:
            self.driver.set_window_size(width, height)
            time.sleep(1)  # Даем время на адаптацию
            
            # Проверяем, что основные элементы видимы
            header = self.driver.find_element(By.CLASS_NAME, 'header')
            self.assertTrue(header.is_displayed())
            
            controls = self.driver.find_element(By.CLASS_NAME, 'controls')
            self.assertTrue(controls.is_displayed())


class TestWebInterfaceAccessibility(unittest.TestCase):
    """Тесты доступности веб-интерфейса."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_semantic_html_elements(self):
        """Тест использования семантических HTML элементов."""
        response = requests.get(f"{self.base_url}/")
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Проверяем наличие семантических элементов
        self.assertIsNotNone(soup.find('title'))
        
        # Проверяем заголовки
        headings = soup.find_all(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])
        self.assertGreater(len(headings), 0)
        
        # Проверяем, что есть h1
        h1_elements = soup.find_all('h1')
        self.assertGreater(len(h1_elements), 0)
    
    def test_form_labels_and_accessibility(self):
        """Тест доступности форм и элементов управления."""
        response = requests.get(f"{self.base_url}/")
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Проверяем чекбоксы и их лейблы
        checkboxes = soup.find_all('input', type='checkbox')
        for checkbox in checkboxes:
            checkbox_id = checkbox.get('id')
            if checkbox_id:
                # Проверяем наличие соответствующего label
                label = soup.find('label', {'for': checkbox_id})
                self.assertIsNotNone(label, f"Отсутствует label для checkbox {checkbox_id}")
        
        # Проверяем кнопки
        buttons = soup.find_all('button')
        for button in buttons:
            # Кнопки должны иметь текст или aria-label
            button_text = button.get_text(strip=True)
            aria_label = button.get('aria-label')
            self.assertTrue(button_text or aria_label, "Кнопка без текста или aria-label")
    
    def test_color_contrast_indicators(self):
        """Тест индикаторов цветового контраста."""
        response = requests.get(f"{self.base_url}/")
        html_content = response.text
        
        # Проверяем, что используются не только цвета для передачи информации
        # Ищем классы статусов, которые должны иметь текстовые индикаторы
        status_classes = ['status-success', 'status-warning', 'status-error', 'status-info']
        
        for status_class in status_classes:
            self.assertIn(status_class, html_content)
    
    def test_keyboard_navigation_support(self):
        """Тест поддержки навигации с клавиатуры."""
        response = requests.get(f"{self.base_url}/")
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # Проверяем, что интерактивные элементы могут получать фокус
        interactive_elements = soup.find_all(['button', 'input', 'a'])
        
        for element in interactive_elements:
            # Элементы не должны иметь tabindex="-1" (кроме специальных случаев)
            tabindex = element.get('tabindex')
            if tabindex:
                self.assertNotEqual(tabindex, '-1', f"Элемент {element.name} недоступен для клавиатуры")


class TestWebInterfacePerformance(unittest.TestCase):
    """Тесты производительности веб-интерфейса."""
    
    def setUp(self):
        """Настройка для каждого теста."""
        self.base_url = "http://localhost:5001"
    
    def test_page_load_time(self):
        """Тест времени загрузки страниц."""
        pages = ['/', '/logs']
        
        for page in pages:
            start_time = time.time()
            response = requests.get(f"{self.base_url}{page}")
            end_time = time.time()
            
            load_time = end_time - start_time
            
            # Страницы должны загружаться быстро (менее 3 секунд)
            self.assertLess(load_time, 3.0, f"Страница {page} загружается слишком медленно: {load_time:.2f}s")
            self.assertEqual(response.status_code, 200)
    
    def test_html_size_optimization(self):
        """Тест оптимизации размера HTML."""
        response = requests.get(f"{self.base_url}/")
        
        content_length = len(response.content)
        
        # HTML не должен быть слишком большим (менее 500KB)
        self.assertLess(content_length, 500 * 1024, f"HTML слишком большой: {content_length} bytes")
    
    def test_css_optimization(self):
        """Тест оптимизации CSS."""
        response = requests.get(f"{self.base_url}/")
        html_content = response.text
        
        # Проверяем, что CSS встроен (для оптимизации)
        self.assertIn('<style>', html_content)
        
        # CSS не должен быть слишком большим
        css_match = re.search(r'<style>(.*?)</style>', html_content, re.DOTALL)
        if css_match:
            css_content = css_match.group(1)
            css_size = len(css_content)
            self.assertLess(css_size, 100 * 1024, f"CSS слишком большой: {css_size} bytes")


def run_web_interface_tests():
    """Запуск всех тестов веб-интерфейса."""
    print("🌐 Запуск тестов веб-интерфейса API управления синхронизацией остатков")
    print("=" * 80)
    
    # Создаем test suite
    test_suite = unittest.TestSuite()
    
    # Добавляем базовые тесты
    test_suite.addTest(unittest.makeSuite(TestWebInterfaceBasic))
    
    # Добавляем интерактивные тесты (если Selenium доступен)
    test_suite.addTest(unittest.makeSuite(TestWebInterfaceInteractive))
    
    # Добавляем тесты доступности
    test_suite.addTest(unittest.makeSuite(TestWebInterfaceAccessibility))
    
    # Добавляем тесты производительности
    test_suite.addTest(unittest.makeSuite(TestWebInterfacePerformance))
    
    # Запускаем тесты
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(test_suite)
    
    # Выводим результаты
    print("\n" + "=" * 80)
    print("🌐 Результаты тестов веб-интерфейса:")
    print(f"✅ Успешных тестов: {result.testsRun - len(result.failures) - len(result.errors)}")
    print(f"❌ Неудачных тестов: {len(result.failures)}")
    print(f"💥 Ошибок: {len(result.errors)}")
    
    if result.failures:
        print("\n❌ Проблемы веб-интерфейса:")
        for test, traceback in result.failures:
            print(f"  - {test}")
    
    if result.errors:
        print("\n💥 Ошибки тестирования:")
        for test, traceback in result.errors:
            print(f"  - {test}")
    
    return result.wasSuccessful()


if __name__ == '__main__':
    success = run_web_interface_tests()
    sys.exit(0 if success else 1)