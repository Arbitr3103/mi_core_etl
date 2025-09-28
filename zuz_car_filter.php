<?php
/**
 * ZUZ Car Filter - Система фильтрации автомобилей по стране → марке → модели → году
 */

// === КОНФИГУРАЦИЯ БД ===
define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core_db');
define('DB_USER', 'mi_core_user');
define('DB_PASS', 'secure_password_123');

// === API ENDPOINTS ===
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        $action = $_GET['api'] ?? '';

        switch ($action) {
            case 'countries':
                // Получаем список стран из таблицы regions
                $sql = "SELECT DISTINCT id, name 
                        FROM regions 
                        WHERE name IS NOT NULL AND name != ''
                        ORDER BY name";
                $stmt = $pdo->query($sql);
                $data = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'brands':
                $country_id = $_GET['country_id'] ?? '';
                if (!$country_id) {
                    echo json_encode(['success' => false, 'error' => 'country_id required']);
                    break;
                }
                
                // Получаем марки автомобилей для выбранной страны
                $sql = "SELECT DISTINCT b.id, b.name, b.region_id
                        FROM brands b
                        WHERE b.region_id = :country_id 
                        AND b.name IS NOT NULL AND b.name != ''
                        ORDER BY b.name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['country_id' => $country_id]);
                $data = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'models':
                $brand_id = $_GET['brand_id'] ?? '';
                if (!$brand_id) {
                    echo json_encode(['success' => false, 'error' => 'brand_id required']);
                    break;
                }
                
                // Получаем модели для выбранной марки
                $sql = "SELECT DISTINCT m.id, m.name, m.brand_id
                        FROM car_models m
                        WHERE m.brand_id = :brand_id 
                        AND m.name IS NOT NULL AND m.name != ''
                        ORDER BY m.name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['brand_id' => $brand_id]);
                $data = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'years':
                $model_id = $_GET['model_id'] ?? '';
                if (!$model_id) {
                    echo json_encode(['success' => false, 'error' => 'model_id required']);
                    break;
                }
                
                // Получаем годы выпуска для выбранной модели
                $sql = "SELECT DISTINCT s.year_from, s.year_to, s.id, s.engine_info
                        FROM car_specifications s
                        WHERE s.model_id = :model_id 
                        AND (s.year_from IS NOT NULL OR s.year_to IS NOT NULL)
                        ORDER BY s.year_from DESC, s.year_to DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['model_id' => $model_id]);
                $data = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'specifications':
                $model_id = $_GET['model_id'] ?? '';
                $year_from = $_GET['year_from'] ?? '';
                $year_to = $_GET['year_to'] ?? '';
                
                if (!$model_id) {
                    echo json_encode(['success' => false, 'error' => 'model_id required']);
                    break;
                }
                
                // Получаем полные характеристики
                $sql = "SELECT s.*, m.name as model_name, b.name as brand_name, r.name as region_name
                        FROM car_specifications s
                        JOIN car_models m ON s.model_id = m.id
                        JOIN brands b ON m.brand_id = b.id
                        JOIN regions r ON b.region_id = r.id
                        WHERE s.model_id = :model_id";
                
                $params = ['model_id' => $model_id];
                
                if ($year_from) {
                    $sql .= " AND s.year_from = :year_from";
                    $params['year_from'] = $year_from;
                }
                if ($year_to) {
                    $sql .= " AND s.year_to = :year_to";
                    $params['year_to'] = $year_to;
                }
                
                $sql .= " ORDER BY s.year_from DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unknown API action']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZUZ - Фильтр автомобилей</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .zuz-header { 
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); 
            color: white; 
            padding: 2rem 0; 
            margin-bottom: 2rem; 
        }
        .filter-step { 
            margin-bottom: 1.5rem; 
            padding: 1rem; 
            border: 1px solid #dee2e6; 
            border-radius: 8px; 
            background: #f8f9fa;
        }
        .filter-step.active { 
            border-color: #0d6efd; 
            background: #e7f1ff; 
        }
        .filter-step.disabled { 
            opacity: 0.6; 
            pointer-events: none; 
        }
        .step-number {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #6c757d;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
        }
        .step-number.active { background: #0d6efd; }
        .step-number.completed { background: #198754; }
        .results-section { 
            margin-top: 2rem; 
            padding: 1rem; 
            border: 1px solid #28a745; 
            border-radius: 8px; 
            background: #f8fff8; 
        }
    </style>
</head>
<body>
    <!-- ZUZ Header -->
    <div class="zuz-header">
        <div class="container">
            <h1>🚗 ZUZ - Фильтр автомобилей</h1>
            <p>Выберите страну → марку → модель → год выпуска для поиска автозапчастей</p>
        </div>
    </div>

    <div class="container">
        <!-- Шаг 1: Выбор страны -->
        <div class="filter-step active" id="step-country">
            <h5><span class="step-number active" id="num-1">1</span>Выберите страну изготовления</h5>
            <select class="form-select" id="country-select">
                <option value="">Загрузка стран...</option>
            </select>
            <div class="mt-2">
                <small class="text-muted">Выбранная страна: <span id="selected-country">не выбрана</span></small>
            </div>
        </div>

        <!-- Шаг 2: Выбор марки -->
        <div class="filter-step disabled" id="step-brand">
            <h5><span class="step-number" id="num-2">2</span>Выберите марку автомобиля</h5>
            <select class="form-select" id="brand-select" disabled>
                <option value="">Сначала выберите страну</option>
            </select>
            <div class="mt-2">
                <small class="text-muted">Выбранная марка: <span id="selected-brand">не выбрана</span></small>
            </div>
        </div>

        <!-- Шаг 3: Выбор модели -->
        <div class="filter-step disabled" id="step-model">
            <h5><span class="step-number" id="num-3">3</span>Выберите модель автомобиля</h5>
            <select class="form-select" id="model-select" disabled>
                <option value="">Сначала выберите марку</option>
            </select>
            <div class="mt-2">
                <small class="text-muted">Выбранная модель: <span id="selected-model">не выбрана</span></small>
            </div>
        </div>

        <!-- Шаг 4: Выбор года -->
        <div class="filter-step disabled" id="step-year">
            <h5><span class="step-number" id="num-4">4</span>Выберите год выпуска</h5>
            <select class="form-select" id="year-select" disabled>
                <option value="">Сначала выберите модель</option>
            </select>
            <div class="mt-2">
                <small class="text-muted">Выбранный год: <span id="selected-year">не выбран</span></small>
            </div>
        </div>

        <!-- Кнопка поиска -->
        <div class="text-center mb-4">
            <button class="btn btn-success btn-lg" id="search-btn" disabled>
                🔍 Найти автозапчасти
            </button>
        </div>

        <!-- Результаты -->
        <div class="results-section" id="results" style="display: none;">
            <h5>📋 Результаты поиска</h5>
            <div id="results-content">
                <!-- Результаты будут загружены динамически -->
            </div>
        </div>

        <!-- Футер -->
        <div class="text-center mt-4 mb-3">
            <small class="text-muted">
                ZUZ Car Filter © 2024 | Система поиска автозапчастей | 
                <a href="https://zuz.ru" target="_blank" class="text-decoration-none">🔗 ZUZ.ru</a>
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        class ZuzCarFilter {
            constructor() {
                this.apiBase = window.location.href.split('?')[0];
                this.selectedData = {
                    country: null,
                    brand: null,
                    model: null,
                    year: null
                };
                this.init();
            }

            async init() {
                this.bindEvents();
                await this.loadCountries();
            }

            bindEvents() {
                document.getElementById('country-select').addEventListener('change', (e) => {
                    this.selectCountry(e.target.value, e.target.options[e.target.selectedIndex].text);
                });

                document.getElementById('brand-select').addEventListener('change', (e) => {
                    this.selectBrand(e.target.value, e.target.options[e.target.selectedIndex].text);
                });

                document.getElementById('model-select').addEventListener('change', (e) => {
                    this.selectModel(e.target.value, e.target.options[e.target.selectedIndex].text);
                });

                document.getElementById('year-select').addEventListener('change', (e) => {
                    this.selectYear(e.target.value, e.target.options[e.target.selectedIndex].text);
                });

                document.getElementById('search-btn').addEventListener('click', () => {
                    this.searchSpecifications();
                });
            }

            async loadCountries() {
                try {
                    const response = await fetch(`${this.apiBase}?api=countries`);
                    const data = await response.json();
                    
                    if (data.success) {
                        const select = document.getElementById('country-select');
                        select.innerHTML = '<option value="">Выберите страну...</option>';
                        
                        data.data.forEach(country => {
                            const option = document.createElement('option');
                            option.value = country.id;
                            option.textContent = country.name;
                            select.appendChild(option);
                        });
                    } else {
                        throw new Error(data.error || 'Ошибка загрузки стран');
                    }
                } catch (error) {
                    console.error('Error loading countries:', error);
                    document.getElementById('country-select').innerHTML = '<option value="">Ошибка загрузки стран</option>';
                }
            }

            async selectCountry(countryId, countryName) {
                if (!countryId) {
                    this.resetFromStep(2);
                    return;
                }

                this.selectedData.country = { id: countryId, name: countryName };
                document.getElementById('selected-country').textContent = countryName;
                document.getElementById('num-1').classList.add('completed');
                
                this.activateStep(2);
                await this.loadBrands(countryId);
            }

            async loadBrands(countryId) {
                try {
                    const response = await fetch(`${this.apiBase}?api=brands&country_id=${countryId}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        const select = document.getElementById('brand-select');
                        select.innerHTML = '<option value="">Выберите марку...</option>';
                        select.disabled = false;
                        
                        data.data.forEach(brand => {
                            const option = document.createElement('option');
                            option.value = brand.id;
                            option.textContent = brand.name;
                            select.appendChild(option);
                        });
                    } else {
                        throw new Error(data.error || 'Ошибка загрузки марок');
                    }
                } catch (error) {
                    console.error('Error loading brands:', error);
                    document.getElementById('brand-select').innerHTML = '<option value="">Ошибка загрузки марок</option>';
                }
            }

            async selectBrand(brandId, brandName) {
                if (!brandId) {
                    this.resetFromStep(3);
                    return;
                }

                this.selectedData.brand = { id: brandId, name: brandName };
                document.getElementById('selected-brand').textContent = brandName;
                document.getElementById('num-2').classList.add('completed');
                
                this.activateStep(3);
                await this.loadModels(brandId);
            }

            async loadModels(brandId) {
                try {
                    const response = await fetch(`${this.apiBase}?api=models&brand_id=${brandId}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        const select = document.getElementById('model-select');
                        select.innerHTML = '<option value="">Выберите модель...</option>';
                        select.disabled = false;
                        
                        data.data.forEach(model => {
                            const option = document.createElement('option');
                            option.value = model.id;
                            option.textContent = model.name;
                            select.appendChild(option);
                        });
                    } else {
                        throw new Error(data.error || 'Ошибка загрузки моделей');
                    }
                } catch (error) {
                    console.error('Error loading models:', error);
                    document.getElementById('model-select').innerHTML = '<option value="">Ошибка загрузки моделей</option>';
                }
            }

            async selectModel(modelId, modelName) {
                if (!modelId) {
                    this.resetFromStep(4);
                    return;
                }

                this.selectedData.model = { id: modelId, name: modelName };
                document.getElementById('selected-model').textContent = modelName;
                document.getElementById('num-3').classList.add('completed');
                
                this.activateStep(4);
                await this.loadYears(modelId);
            }

            async loadYears(modelId) {
                try {
                    const response = await fetch(`${this.apiBase}?api=years&model_id=${modelId}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        const select = document.getElementById('year-select');
                        select.innerHTML = '<option value="">Выберите год...</option>';
                        select.disabled = false;
                        
                        data.data.forEach(year => {
                            const option = document.createElement('option');
                            option.value = `${year.year_from}-${year.year_to}`;
                            option.textContent = `${year.year_from || '?'} - ${year.year_to || '?'}${year.engine_info ? ' (' + year.engine_info + ')' : ''}`;
                            select.appendChild(option);
                        });
                    } else {
                        throw new Error(data.error || 'Ошибка загрузки годов');
                    }
                } catch (error) {
                    console.error('Error loading years:', error);
                    document.getElementById('year-select').innerHTML = '<option value="">Ошибка загрузки годов</option>';
                }
            }

            selectYear(yearRange, yearText) {
                if (!yearRange) {
                    document.getElementById('search-btn').disabled = true;
                    document.getElementById('selected-year').textContent = 'не выбран';
                    return;
                }

                this.selectedData.year = { range: yearRange, text: yearText };
                document.getElementById('selected-year').textContent = yearText;
                document.getElementById('num-4').classList.add('completed');
                document.getElementById('search-btn').disabled = false;
            }

            async searchSpecifications() {
                const modelId = this.selectedData.model.id;
                const [yearFrom, yearTo] = this.selectedData.year.range.split('-');
                
                try {
                    const params = new URLSearchParams({
                        api: 'specifications',
                        model_id: modelId,
                        year_from: yearFrom,
                        year_to: yearTo
                    });
                    
                    const response = await fetch(`${this.apiBase}?${params}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        this.displayResults(data.data);
                    } else {
                        throw new Error(data.error || 'Ошибка поиска');
                    }
                } catch (error) {
                    console.error('Error searching:', error);
                    document.getElementById('results-content').innerHTML = `<div class="alert alert-danger">Ошибка поиска: ${error.message}</div>`;
                    document.getElementById('results').style.display = 'block';
                }
            }

            displayResults(specifications) {
                const resultsContent = document.getElementById('results-content');
                
                if (specifications.length === 0) {
                    resultsContent.innerHTML = '<div class="alert alert-info">Автозапчасти для выбранного автомобиля не найдены</div>';
                } else {
                    const summary = `
                        <div class="alert alert-success">
                            <strong>Найдено ${specifications.length} комплектаций для:</strong><br>
                            ${this.selectedData.country.name} → ${this.selectedData.brand.name} → ${this.selectedData.model.name} → ${this.selectedData.year.text}
                        </div>
                    `;
                    
                    const table = `
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Годы выпуска</th>
                                    <th>Двигатель</th>
                                    <th>Информация</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${specifications.map(spec => `
                                    <tr>
                                        <td>${spec.id}</td>
                                        <td>${spec.year_from || '?'} - ${spec.year_to || '?'}</td>
                                        <td>${spec.engine_info || 'Не указано'}</td>
                                        <td>${spec.additional_info || 'Нет дополнительной информации'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                    
                    resultsContent.innerHTML = summary + table;
                }
                
                document.getElementById('results').style.display = 'block';
            }

            activateStep(stepNumber) {
                // Активируем нужный шаг
                const step = document.getElementById(`step-${this.getStepName(stepNumber)}`);
                step.classList.remove('disabled');
                step.classList.add('active');
                
                const num = document.getElementById(`num-${stepNumber}`);
                num.classList.add('active');
            }

            resetFromStep(stepNumber) {
                // Сбрасываем все шаги начиная с указанного
                for (let i = stepNumber; i <= 4; i++) {
                    const step = document.getElementById(`step-${this.getStepName(i)}`);
                    step.classList.add('disabled');
                    step.classList.remove('active');
                    
                    const num = document.getElementById(`num-${i}`);
                    num.classList.remove('active', 'completed');
                    
                    // Очищаем выбранные данные
                    if (i === 2) this.selectedData.brand = null;
                    if (i === 3) this.selectedData.model = null;
                    if (i === 4) this.selectedData.year = null;
                }
                
                // Отключаем кнопку поиска
                document.getElementById('search-btn').disabled = true;
                document.getElementById('results').style.display = 'none';
            }

            getStepName(stepNumber) {
                const names = { 1: 'country', 2: 'brand', 3: 'model', 4: 'year' };
                return names[stepNumber];
            }
        }

        document.addEventListener('DOMContentLoaded', () => new ZuzCarFilter());
    </script>
</body>
</html>
