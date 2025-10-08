<?php
/**
 * Тест статуса API
 */

// Симулируем GET параметр
$_GET['action'] = 'status';

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Включаем API
require_once 'api/inventory-v4.php';
?>