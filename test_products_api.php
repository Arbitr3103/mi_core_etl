<?php
/**
 * Тест API для получения товаров
 */

// Симулируем GET параметр
$_GET['action'] = 'products';
$_GET['limit'] = '5';
$_GET['offset'] = '0';

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Включаем API
require_once 'api/inventory-v4.php';
?>