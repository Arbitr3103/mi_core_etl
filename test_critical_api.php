<?php
/**
 * Тест API для критических остатков
 */

// Симулируем GET параметр
$_GET['action'] = 'critical';
$_GET['threshold'] = '5';

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Включаем API
require_once 'api/inventory-v4.php';
?>