<?php
/**
 * Локальный тест API для проверки статистики складов
 */

// Симулируем GET параметр
$_GET['action'] = 'stats';

// Включаем API
require_once 'api/inventory-v4.php';
?>