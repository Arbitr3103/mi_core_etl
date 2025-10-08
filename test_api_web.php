<?php
/**
 * Тестирование API endpoints через веб-интерфейс
 */

header("Content-Type: text/html; charset=utf-8");

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тестирование API</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-result { margin: 10px 0; padding: 10px; border-radius: 5px; }
        .success { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .loading { background-color: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
        button { padding: 10px 20px; margin: 5px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>🔧 Тестирование API Endpoints</h1>
    
    <div id="results"></div>
    
    <button onclick="testAPI('sync-stats')">Тест sync-stats.php</button>
    <button onclick="testAPI('analytics')">Тест analytics.php</button>
    <button onclick="testAPI('fix-product-names')">Тест fix-product-names.php</button>
    <button onclick="testAllAPIs()">Тест всех API</button>
    
    <script>
        async function testAPI(endpoint) {
            const resultsDiv = document.getElementById('results');
            const testId = 'test-' + endpoint + '-' + Date.now();
            
            // Добавляем индикатор загрузки
            resultsDiv.innerHTML += `
                <div id="${testId}" class="test-result loading">
                    🔄 Тестируем /api/${endpoint}.php...
                </div>
            `;
            
            try {
                const startTime = Date.now();
                const response = await fetch(`/api/${endpoint}.php`);
                const endTime = Date.now();
                const responseTime = endTime - startTime;
                
                const contentType = response.headers.get('content-type');
                let responseData;
                
                if (contentType && contentType.includes('application/json')) {
                    responseData = await response.json();
                } else {
                    responseData = await response.text();
                }
                
                const testResult = document.getElementById(testId);
                
                if (response.ok) {
                    testResult.className = 'test-result success';
                    testResult.innerHTML = `
                        ✅ <strong>/api/${endpoint}.php</strong> - Успешно (${responseTime}ms)
                        <br>Статус: ${response.status}
                        <br>Content-Type: ${contentType}
                        <pre>${JSON.stringify(responseData, null, 2)}</pre>
                    `;
                } else {
                    testResult.className = 'test-result error';
                    testResult.innerHTML = `
                        ❌ <strong>/api/${endpoint}.php</strong> - Ошибка ${response.status}
                        <br>Время ответа: ${responseTime}ms
                        <br>Content-Type: ${contentType}
                        <pre>${typeof responseData === 'string' ? responseData : JSON.stringify(responseData, null, 2)}</pre>
                    `;
                }
                
            } catch (error) {
                const testResult = document.getElementById(testId);
                testResult.className = 'test-result error';
                testResult.innerHTML = `
                    ❌ <strong>/api/${endpoint}.php</strong> - Ошибка сети
                    <br>Ошибка: ${error.message}
                `;
            }
        }
        
        async function testAllAPIs() {
            document.getElementById('results').innerHTML = '<h2>Результаты тестирования:</h2>';
            
            const endpoints = ['sync-stats', 'analytics', 'fix-product-names'];
            
            for (const endpoint of endpoints) {
                await testAPI(endpoint);
                // Небольшая задержка между запросами
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        }
        
        // Автоматический тест при загрузке страницы
        window.addEventListener('load', function() {
            setTimeout(testAllAPIs, 1000);
        });
    </script>
</body>
</html>