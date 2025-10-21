<?php
/**
 * Простой скрипт для запуска тестового веб-сервера
 */

echo "🚀 ЗАПУСК ТЕСТОВОГО СЕРВЕРА\n";
echo str_repeat('=', 50) . "\n";

$host = '127.0.0.1';
$port = 8080;
$docroot = __DIR__;

echo "📍 Хост: $host\n";
echo "🔌 Порт: $port\n";
echo "📁 Корневая папка: $docroot\n";
echo "\n";

echo "🌐 Доступные URL:\n";
echo "  • Дашборд: http://$host:$port/test_dashboard.html\n";
echo "  • API: http://$host:$port/api/inventory-analytics.php?action=dashboard\n";
echo "\n";

echo "⚠️ Для остановки сервера нажмите Ctrl+C\n";
echo str_repeat('=', 50) . "\n";

// Запускаем встроенный PHP сервер
$command = "php -S $host:$port -t $docroot";
echo "Выполняем: $command\n\n";

passthru($command);
?>