/**
 * Исправления для дашборда v4
 */

// Исправление ошибки "Cannot set properties of null"
function safeSetInnerHTML(elementId, content) {
    const element = document.getElementById(elementId);
    if (element) {
        element.innerHTML = content;
        return true;
    } else {
        console.warn(`Element with ID "${elementId}" not found`);
        return false;
    }
}

// Улучшенная функция загрузки статистики синхронизации
async function loadSyncStats() {
    try {
        console.log("🔄 Загрузка статистики синхронизации...");
        
        const response = await fetch("/api/sync-stats.php");
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        // Безопасное обновление элементов
        safeSetInnerHTML("sync-status", data.status || "unknown");
        safeSetInnerHTML("processed-records", data.processed_records || 0);
        safeSetInnerHTML("inserted-records", data.inserted_records || 0);
        safeSetInnerHTML("sync-errors", data.errors || 0);
        safeSetInnerHTML("execution-time", data.execution_time || 0);
        safeSetInnerHTML("api-requests", data.api_requests || 0);
        
        console.log("✅ Статистика синхронизации загружена");
        return data;
        
    } catch (error) {
        console.error("❌ Ошибка загрузки статистики синхронизации:", error);
        
        // Показываем значения по умолчанию
        safeSetInnerHTML("sync-status", "error");
        safeSetInnerHTML("processed-records", "N/A");
        safeSetInnerHTML("inserted-records", "N/A");
        safeSetInnerHTML("sync-errors", "N/A");
        safeSetInnerHTML("execution-time", "N/A");
        safeSetInnerHTML("api-requests", "N/A");
        
        return null;
    }
}

// Улучшенная функция загрузки аналитики
async function loadAnalytics() {
    try {
        console.log("📊 Загрузка аналитики...");
        
        const response = await fetch("/api/analytics.php");
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        // Безопасное обновление элементов аналитики
        safeSetInnerHTML("total-products", data.total_products || 0);
        safeSetInnerHTML("products-with-names", data.products_with_names || 0);
        safeSetInnerHTML("data-quality-score", (data.data_quality_score || 0) + "%");
        safeSetInnerHTML("critical-stock", data.critical_stock_items || 0);
        
        // Обновляем прогресс-бары если они есть
        const qualityBar = document.getElementById("quality-progress-bar");
        if (qualityBar) {
            qualityBar.style.width = (data.data_quality_score || 0) + "%";
        }
        
        console.log("✅ Аналитика загружена");
        return data;
        
    } catch (error) {
        console.error("❌ Ошибка загрузки аналитики:", error);
        return null;
    }
}

// Функция исправления товаров без названий
async function fixMissingProductNames() {
    try {
        console.log("🔧 Запуск исправления товаров без названий...");
        
        const response = await fetch("/api/fix-product-names.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        console.log("✅ Исправление завершено:", result);
        
        // Обновляем статистику после исправления
        await loadAnalytics();
        
        return result;
        
    } catch (error) {
        console.error("❌ Ошибка исправления товаров:", error);
        return null;
    }
}

// Автоматическая инициализация при загрузке страницы
document.addEventListener("DOMContentLoaded", function() {
    console.log("🚀 Инициализация улучшенного дашборда...");
    
    // Загружаем данные с интервалом
    loadSyncStats();
    loadAnalytics();
    
    // Обновляем данные каждые 30 секунд
    setInterval(() => {
        loadSyncStats();
        loadAnalytics();
    }, 30000);
    
    // Добавляем кнопку исправления товаров если её нет
    const fixButton = document.getElementById("fix-products-btn");
    if (!fixButton) {
        const button = document.createElement("button");
        button.id = "fix-products-btn";
        button.className = "btn btn-warning";
        button.innerHTML = "🔧 Исправить товары без названий";
        button.onclick = fixMissingProductNames;
        
        // Добавляем кнопку в подходящее место
        const container = document.querySelector(".dashboard-controls") || document.body;
        container.appendChild(button);
    }
});

// Экспортируем функции для глобального использования
window.dashboardFixes = {
    loadSyncStats,
    loadAnalytics,
    fixMissingProductNames,
    safeSetInnerHTML
};