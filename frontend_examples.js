/**
 * Примеры интеграции с системой маржинальности для фронтенд разработчиков
 * 
 * JavaScript/jQuery примеры для работы с API маржинальности
 * Включает обработку ошибок, форматирование данных и создание графиков
 */

class MarginAPI {
    constructor(baseUrl = '/api/margins') {
        this.baseUrl = baseUrl;
    }

    /**
     * Базовый метод для выполнения запросов
     */
    async request(endpoint, params = {}) {
        const url = new URL(this.baseUrl + endpoint, window.location.origin);
        
        // Добавляем параметры в URL
        Object.keys(params).forEach(key => {
            if (params[key] !== null && params[key] !== undefined) {
                url.searchParams.append(key, params[key]);
            }
        });

        try {
            const response = await fetch(url);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error.message || 'Ошибка API');
            }
            
            return data.data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    }

    /**
     * Получить сводную маржинальность
     */
    async getSummary(startDate, endDate, clientId = null) {
        return await this.request('/summary', {
            start_date: startDate,
            end_date: endDate,
            client_id: clientId
        });
    }

    /**
     * Получить маржинальность по дням
     */
    async getDailyMargins(startDate, endDate, clientId = null) {
        return await this.request('/daily', {
            start_date: startDate,
            end_date: endDate,
            client_id: clientId
        });
    }

    /**
     * Получить топ товаров по маржинальности
     */
    async getTopProducts(limit = 20, startDate = null, endDate = null, minRevenue = 0) {
        return await this.request('/products/top', {
            limit,
            start_date: startDate,
            end_date: endDate,
            min_revenue: minRevenue
        });
    }

    /**
     * Получить товары с низкой маржинальностью
     */
    async getLowMarginProducts(threshold = 15, minRevenue = 1000, limit = 20) {
        return await this.request('/products/low', {
            threshold,
            min_revenue: minRevenue,
            limit
        });
    }

    /**
     * Получить статистику покрытия
     */
    async getCoverageStats() {
        return await this.request('/coverage');
    }
}

/**
 * Утилиты для форматирования данных
 */
class MarginUtils {
    /**
     * Форматировать валюту
     */
    static formatCurrency(amount, currency = 'руб.') {
        return new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount) + ' ' + currency;
    }

    /**
     * Форматировать процент
     */
    static formatPercent(percent) {
        return new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1
        }).format(percent) + '%';
    }

    /**
     * Получить цвет для маржинальности
     */
    static getMarginColor(margin) {
        if (margin >= 50) return '#28a745'; // Зеленый
        if (margin >= 30) return '#ffc107'; // Желтый
        if (margin >= 15) return '#fd7e14'; // Оранжевый
        return '#dc3545'; // Красный
    }

    /**
     * Получить статус маржинальности
     */
    static getMarginStatus(margin) {
        if (margin >= 50) return 'Отличная';
        if (margin >= 30) return 'Хорошая';
        if (margin >= 15) return 'Средняя';
        return 'Низкая';
    }
}

/**
 * Компонент дашборда маржинальности
 */
class MarginDashboard {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.api = new MarginAPI();
        this.init();
    }

    async init() {
        try {
            await this.loadSummary();
            await this.loadDailyChart();
            await this.loadTopProducts();
            await this.loadCoverageStats();
        } catch (error) {
            this.showError('Ошибка загрузки данных: ' + error.message);
        }
    }

    /**
     * Загрузить сводную информацию
     */
    async loadSummary() {
        const startDate = this.getStartDate();
        const endDate = this.getEndDate();
        
        const summary = await this.api.getSummary(startDate, endDate);
        
        this.renderSummaryCards(summary);
    }

    /**
     * Отобразить карточки сводной информации
     */
    renderSummaryCards(summary) {
        const cardsHtml = `
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <h5 class="card-title">Выручка</h5>
                            <h3>${MarginUtils.formatCurrency(summary.totals.revenue)}</h3>
                            <small>За ${summary.period.days_count} дней</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h5 class="card-title">Себестоимость</h5>
                            <h3>${MarginUtils.formatCurrency(summary.totals.cogs)}</h3>
                            <small>Затраты на товары</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h5 class="card-title">Прибыль</h5>
                            <h3>${MarginUtils.formatCurrency(summary.totals.profit)}</h3>
                            <small>Чистая прибыль</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white" style="background-color: ${MarginUtils.getMarginColor(summary.totals.margin_percent)}">
                        <div class="card-body">
                            <h5 class="card-title">Маржинальность</h5>
                            <h3>${MarginUtils.formatPercent(summary.totals.margin_percent)}</h3>
                            <small>${MarginUtils.getMarginStatus(summary.totals.margin_percent)}</small>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        this.container.insertAdjacentHTML('afterbegin', cardsHtml);
    }

    /**
     * Загрузить график по дням
     */
    async loadDailyChart() {
        const startDate = this.getStartDate();
        const endDate = this.getEndDate();
        
        const dailyData = await this.api.getDailyMargins(startDate, endDate);
        
        this.renderDailyChart(dailyData.data);
    }

    /**
     * Отобразить график маржинальности по дням (Chart.js)
     */
    renderDailyChart(data) {
        const chartHtml = `
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5>Маржинальность по дням</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="dailyMarginChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        this.container.insertAdjacentHTML('beforeend', chartHtml);
        
        // Создаем график (требует Chart.js)
        const ctx = document.getElementById('dailyMarginChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(item => new Date(item.date).toLocaleDateString('ru-RU')),
                datasets: [{
                    label: 'Маржинальность (%)',
                    data: data.map(item => item.margin_percent),
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Выручка (тыс. руб.)',
                    data: data.map(item => item.revenue / 1000),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    yAxisID: 'y1',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Маржинальность (%)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Выручка (тыс. руб.)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });
    }

    /**
     * Загрузить топ товаров
     */
    async loadTopProducts() {
        const topProducts = await this.api.getTopProducts(10, null, null, 1000);
        
        this.renderTopProducts(topProducts.data);
    }

    /**
     * Отобразить таблицу топ товаров
     */
    renderTopProducts(products) {
        const tableHtml = `
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5>Топ-10 товаров по маржинальности</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Товар</th>
                                            <th>Количество</th>
                                            <th>Выручка</th>
                                            <th>Прибыль</th>
                                            <th>Маржа</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${products.map(product => `
                                            <tr>
                                                <td>
                                                    <strong>${product.sku_ozon || product.sku_wb || 'N/A'}</strong><br>
                                                    <small class="text-muted">${product.product_name || 'Без названия'}</small>
                                                </td>
                                                <td>${product.quantity}</td>
                                                <td>${MarginUtils.formatCurrency(product.revenue)}</td>
                                                <td>${MarginUtils.formatCurrency(product.profit)}</td>
                                                <td>
                                                    <span class="badge" style="background-color: ${MarginUtils.getMarginColor(product.margin_percent)}">
                                                        ${MarginUtils.formatPercent(product.margin_percent)}
                                                    </span>
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        this.container.insertAdjacentHTML('beforeend', tableHtml);
    }

    /**
     * Загрузить статистику покрытия
     */
    async loadCoverageStats() {
        const coverage = await this.api.getCoverageStats();
        
        this.renderCoverageStats(coverage);
    }

    /**
     * Отобразить статистику покрытия
     */
    renderCoverageStats(coverage) {
        const coverageHtml = `
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Покрытие себестоимостью</h5>
                        </div>
                        <div class="card-body">
                            <div class="progress mb-3" style="height: 30px;">
                                <div class="progress-bar" role="progressbar" 
                                     style="width: ${coverage.coverage_percent}%" 
                                     aria-valuenow="${coverage.coverage_percent}" 
                                     aria-valuemin="0" aria-valuemax="100">
                                    ${MarginUtils.formatPercent(coverage.coverage_percent)}
                                </div>
                            </div>
                            <p>
                                <strong>${coverage.products_with_cost}</strong> из <strong>${coverage.total_products}</strong> товаров<br>
                                <small class="text-muted">имеют данные о себестоимости</small>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Статистика себестоимости</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Средняя:</strong> ${MarginUtils.formatCurrency(coverage.cost_price_stats.average)}</p>
                            <p><strong>Минимальная:</strong> ${MarginUtils.formatCurrency(coverage.cost_price_stats.minimum)}</p>
                            <p><strong>Максимальная:</strong> ${MarginUtils.formatCurrency(coverage.cost_price_stats.maximum)}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        this.container.insertAdjacentHTML('beforeend', coverageHtml);
    }

    /**
     * Получить дату начала периода (по умолчанию - начало месяца)
     */
    getStartDate() {
        const date = new Date();
        return new Date(date.getFullYear(), date.getMonth(), 1).toISOString().split('T')[0];
    }

    /**
     * Получить дату окончания периода (по умолчанию - сегодня)
     */
    getEndDate() {
        return new Date().toISOString().split('T')[0];
    }

    /**
     * Показать ошибку
     */
    showError(message) {
        const errorHtml = `
            <div class="alert alert-danger" role="alert">
                <h4 class="alert-heading">Ошибка!</h4>
                <p>${message}</p>
            </div>
        `;
        
        this.container.innerHTML = errorHtml;
    }
}

/**
 * jQuery плагин для быстрой интеграции
 */
(function($) {
    $.fn.marginDashboard = function(options) {
        const settings = $.extend({
            apiUrl: '/api/margins',
            autoRefresh: false,
            refreshInterval: 300000 // 5 минут
        }, options);

        return this.each(function() {
            const dashboard = new MarginDashboard(this.id);
            
            if (settings.autoRefresh) {
                setInterval(() => {
                    dashboard.init();
                }, settings.refreshInterval);
            }
        });
    };
})(jQuery);

/**
 * Примеры использования
 */

// Пример 1: Простое получение данных
async function example1() {
    const api = new MarginAPI();
    
    try {
        const summary = await api.getSummary('2025-09-01', '2025-09-19');
        console.log('Общая маржинальность:', summary.totals.margin_percent + '%');
        console.log('Прибыль:', MarginUtils.formatCurrency(summary.totals.profit));
    } catch (error) {
        console.error('Ошибка:', error.message);
    }
}

// Пример 2: Создание дашборда
function example2() {
    // HTML: <div id="margin-dashboard"></div>
    const dashboard = new MarginDashboard('margin-dashboard');
}

// Пример 3: jQuery плагин
function example3() {
    $('#margin-dashboard').marginDashboard({
        autoRefresh: true,
        refreshInterval: 300000
    });
}

// Пример 4: Обработка товаров с низкой маржинальностью
async function example4() {
    const api = new MarginAPI();
    
    try {
        const lowMarginProducts = await api.getLowMarginProducts(20, 1000, 10);
        
        if (lowMarginProducts.data.length > 0) {
            console.warn('Найдены товары с низкой маржинальностью:');
            lowMarginProducts.data.forEach(product => {
                console.log(`${product.sku_ozon}: ${product.margin_percent}%`);
            });
        }
    } catch (error) {
        console.error('Ошибка:', error.message);
    }
}

// Экспорт для использования в модулях
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { MarginAPI, MarginUtils, MarginDashboard };
}
