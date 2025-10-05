#!/bin/bash
# Скрипт обновления файлов на сервере после исправления демографии

SERVER="vladimir@178.72.129.61"
REMOTE_PATH="/var/www/html/api"
LOCAL_PATH="/Users/vladimirbragin/CascadeProjects/mi_core_etl"

echo "🚀 Обновление файлов на сервере..."

# Копируем исправленный JavaScript файл
echo "📦 Копирование OzonAnalyticsIntegration.js..."
scp "${LOCAL_PATH}/src/js/OzonAnalyticsIntegration.js" "${SERVER}:${REMOTE_PATH}/src/js/"
scp "${LOCAL_PATH}/js/ozon/OzonAnalyticsIntegration.js" "${SERVER}:${REMOTE_PATH}/js/ozon/"

echo "✅ Файлы успешно обновлены на сервере!"
echo ""
echo "📋 Обновленные файлы:"
echo "  - src/js/OzonAnalyticsIntegration.js"
echo "  - js/ozon/OzonAnalyticsIntegration.js"
echo ""
echo "🔄 Перезагрузите страницу в браузере (Ctrl+Shift+R) для применения изменений"
ssh -t vladimir@178.72.129.61 << 'EOF'
echo 'K9@xN2#vR6*qYmL4p' | sudo -S cp ~/demo_dashboard.php /var/www/html/demo_dashboard.php
echo 'K9@xN2#vR6*qYmL4p' | sudo -S chown www-data:www-data /var/www/html/demo_dashboard.php
ls -la /var/www/html/demo_dashboard.php
EOF
