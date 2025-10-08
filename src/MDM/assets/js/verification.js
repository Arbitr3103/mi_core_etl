/**
 * MDM Verification Interface JavaScript
 * Handles verification functionality and user interactions
 */

class MDMVerification {
  constructor() {
    this.currentPage = 1;
    this.perPage = 20;
    this.currentFilter = "all";
    this.selectedItems = new Set();
    this.currentSkuMappingId = null;

    this.init();
  }

  /**
   * Initialize verification interface
   */
  init() {
    this.setupEventListeners();
    this.loadVerificationItems();
  }

  /**
   * Setup event listeners
   */
  setupEventListeners() {
    // Filter and pagination controls
    document.getElementById("filter-select").addEventListener("change", (e) => {
      this.currentFilter = e.target.value;
      this.currentPage = 1;
      this.loadVerificationItems();
    });

    document
      .getElementById("per-page-select")
      .addEventListener("change", (e) => {
        this.perPage = parseInt(e.target.value);
        this.currentPage = 1;
        this.loadVerificationItems();
      });

    document.getElementById("refresh-btn").addEventListener("click", () => {
      this.loadVerificationItems();
    });

    // Selection controls
    document.getElementById("select-all-btn").addEventListener("click", () => {
      this.selectAllItems();
    });

    document
      .getElementById("clear-selection-btn")
      .addEventListener("click", () => {
        this.clearSelection();
      });

    // Bulk actions
    document
      .getElementById("approve-selected-btn")
      .addEventListener("click", () => {
        this.bulkApproveSelected();
      });

    document
      .getElementById("reject-selected-btn")
      .addEventListener("click", () => {
        this.bulkRejectSelected();
      });

    // Modal actions
    document
      .getElementById("approve-match-btn")
      .addEventListener("click", () => {
        this.approveCurrentMatch();
      });

    document
      .getElementById("reject-match-btn")
      .addEventListener("click", () => {
        this.rejectCurrentMatch();
      });

    document
      .getElementById("create-new-master-btn")
      .addEventListener("click", () => {
        this.showCreateMasterModal();
      });

    document.getElementById("save-master-btn").addEventListener("click", () => {
      this.createNewMaster();
    });
  }
}
    /**
     * Load verification items
     */
    async loadVerificationItems() {
        try {
            this.showLoading();
            
            const params = new URLSearchParams({
                page: this.currentPage,
                limit: this.perPage,
                filter: this.currentFilter
            });

            const response = await fetch(`/mdm/verification/items?${params}`);
            const result = await response.json();

            if (result.success) {
                this.renderVerificationItems(result.data.items);
                this.renderPagination(result.data.pagination);
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            console.error('Error loading verification items:', error);
            this.showNotification('Ошибка загрузки данных: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Render verification items
     */
    renderVerificationItems(items) {
        const container = document.getElementById('verification-items-container');
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="text-center p-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h5>Нет товаров для верификации</h5>
                    <p class="text-muted">Все товары в выбранной категории уже проверены.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = items.map(item => this.renderVerificationItem(item)).join('');
        
        // Setup item event listeners
        this.setupItemEventListeners();
    }

    /**
     * Render single verification item
     */
    renderVerificationItem(item) {
        const confidenceClass = this.getConfidenceClass(item.confidence_score);
        const confidencePercent = Math.round(item.confidence_score * 100);
        
        return `
            <div class="verification-item" data-id="${item.id}">
                <div class="product-info">
                    <div class="product-checkbox">
                        <input type="checkbox" class="form-check-input item-checkbox" 
                               data-id="${item.id}" ${this.selectedItems.has(item.id) ? 'checked' : ''}>
                    </div>
                    <div class="product-details">
                        <div class="product-name">${this.escapeHtml(item.source_name)}</div>
                        <div class="product-meta">
                            <div class="meta-item">
                                <i class="fas fa-tag meta-icon"></i>
                                <span>SKU: ${item.external_sku}</span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-store meta-icon"></i>
                                <span>${item.source}</span>
                            </div>
                            ${item.source_brand ? `
                                <div class="meta-item">
                                    <i class="fas fa-copyright meta-icon"></i>
                                    <span>${this.escapeHtml(item.source_brand)}</span>
                                </div>
                            ` : ''}
                            ${item.source_category ? `
                                <div class="meta-item">
                                    <i class="fas fa-folder meta-icon"></i>
                                    <span>${this.escapeHtml(item.source_category)}</span>
                                </div>
                            ` : ''}
                        </div>
                        
                        ${item.confidence_score ? `
                            <div class="confidence-score">
                                <span class="confidence-text">${confidencePercent}%</span>
                                <div class="confidence-bar">
                                    <div class="confidence-fill ${confidenceClass}" 
                                         style="width: ${confidencePercent}%"></div>
                                </div>
                            </div>
                        ` : ''}
                        
                        ${item.master_id ? `
                            <div class="match-status suggested">
                                <i class="fas fa-lightbulb"></i>
                                Предложено: ${this.escapeHtml(item.canonical_name || 'Неизвестно')}
                            </div>
                        ` : `
                            <div class="match-status no-match">
                                <i class="fas fa-question-circle"></i>
                                Совпадения не найдены
                            </div>
                        `}
                    </div>
                    <div class="verification-actions">
                        <button class="action-btn compare" onclick="verification.showComparison(${item.id})">
                            <i class="fas fa-search"></i>
                            Сравнить
                        </button>
                        ${item.master_id ? `
                            <button class="action-btn approve" onclick="verification.quickApprove(${item.id})">
                                <i class="fas fa-check"></i>
                                Одобрить
                            </button>
                        ` : ''}
                        <button class="action-btn reject" onclick="verification.quickReject(${item.id})">
                            <i class="fas fa-times"></i>
                            Отклонить
                        </button>
                    </div>
                </div>
            </div>
        `;
    } 
   /**
     * Setup item event listeners
     */
    setupItemEventListeners() {
        // Checkbox selection
        document.querySelectorAll('.item-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const itemId = parseInt(e.target.dataset.id);
                if (e.target.checked) {
                    this.selectedItems.add(itemId);
                } else {
                    this.selectedItems.delete(itemId);
                }
                this.updateSelectionUI();
            });
        });
    }

    /**
     * Show comparison modal
     */
    async showComparison(skuMappingId) {
        try {
            this.currentSkuMappingId = skuMappingId;
            this.showLoading();

            const response = await fetch(`/mdm/verification/details?sku_mapping_id=${skuMappingId}`);
            const result = await response.json();

            if (result.success) {
                this.renderComparisonModal(result.data);
                const modal = new bootstrap.Modal(document.getElementById('comparison-modal'));
                modal.show();
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            console.error('Error loading comparison:', error);
            this.showNotification('Ошибка загрузки сравнения: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Render comparison modal content
     */
    renderComparisonModal(data) {
        const skuMapping = data.sku_mapping;
        const suggestedMatches = data.suggested_matches || [];

        const content = `
            <div class="comparison-container">
                <div class="comparison-side source">
                    <div class="comparison-header">
                        <i class="fas fa-file-import"></i>
                        Исходный товар (${skuMapping.source})
                    </div>
                    <div class="comparison-field">
                        <div class="field-label">Название</div>
                        <div class="field-value">${this.escapeHtml(skuMapping.source_name)}</div>
                    </div>
                    <div class="comparison-field">
                        <div class="field-label">Бренд</div>
                        <div class="field-value ${!skuMapping.source_brand ? 'empty' : ''}">
                            ${skuMapping.source_brand ? this.escapeHtml(skuMapping.source_brand) : 'Не указан'}
                        </div>
                    </div>
                    <div class="comparison-field">
                        <div class="field-label">Категория</div>
                        <div class="field-value ${!skuMapping.source_category ? 'empty' : ''}">
                            ${skuMapping.source_category ? this.escapeHtml(skuMapping.source_category) : 'Не указана'}
                        </div>
                    </div>
                    <div class="comparison-field">
                        <div class="field-label">SKU</div>
                        <div class="field-value">${skuMapping.external_sku}</div>
                    </div>
                </div>
                
                <div class="comparison-side master">
                    <div class="comparison-header">
                        <i class="fas fa-database"></i>
                        ${skuMapping.master_id ? 'Предложенный мастер-товар' : 'Мастер-товар не найден'}
                    </div>
                    ${skuMapping.master_id ? `
                        <div class="comparison-field">
                            <div class="field-label">Каноническое название</div>
                            <div class="field-value">${this.escapeHtml(skuMapping.canonical_name || '')}</div>
                        </div>
                        <div class="comparison-field">
                            <div class="field-label">Бренд</div>
                            <div class="field-value ${!skuMapping.canonical_brand ? 'empty' : ''}">
                                ${skuMapping.canonical_brand ? this.escapeHtml(skuMapping.canonical_brand) : 'Не указан'}
                            </div>
                        </div>
                        <div class="comparison-field">
                            <div class="field-label">Категория</div>
                            <div class="field-value ${!skuMapping.canonical_category ? 'empty' : ''}">
                                ${skuMapping.canonical_category ? this.escapeHtml(skuMapping.canonical_category) : 'Не указана'}
                            </div>
                        </div>
                        <div class="comparison-field">
                            <div class="field-label">Master ID</div>
                            <div class="field-value">${skuMapping.master_id}</div>
                        </div>
                    ` : `
                        <div class="text-center p-4">
                            <i class="fas fa-search fa-2x text-muted mb-3"></i>
                            <p class="text-muted">Автоматическое совпадение не найдено</p>
                        </div>
                    `}
                </div>
            </div>
            
            ${suggestedMatches.length > 0 ? `
                <div class="similar-products">
                    <h6><i class="fas fa-lightbulb me-2"></i>Похожие товары</h6>
                    ${suggestedMatches.map(match => `
                        <div class="similar-product" data-master-id="${match.master_id}">
                            <div class="similar-product-info">
                                <div class="similar-product-name">${this.escapeHtml(match.canonical_name)}</div>
                                <div class="similar-product-meta">
                                    ${match.canonical_brand ? `Бренд: ${this.escapeHtml(match.canonical_brand)} • ` : ''}
                                    ${match.canonical_category ? `Категория: ${this.escapeHtml(match.canonical_category)}` : ''}
                                </div>
                            </div>
                            <div class="similar-product-score">${Math.round(match.match_score * 100)}%</div>
                        </div>
                    `).join('')}
                </div>
            ` : ''}
        `;

        document.getElementById('comparison-content').innerHTML = content;

        // Setup similar product selection
        document.querySelectorAll('.similar-product').forEach(product => {
            product.addEventListener('click', () => {
                document.querySelectorAll('.similar-product').forEach(p => p.classList.remove('selected'));
                product.classList.add('selected');
            });
        });
    }

    /**
     * Quick approve item
     */
    async quickApprove(skuMappingId) {
        try {
            const response = await fetch('/mdm/verification/approve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sku_mapping_id: skuMappingId })
            });

            const result = await response.json();
            if (result.success) {
                this.showNotification('Товар одобрен', 'success');
                this.removeItemFromList(skuMappingId);
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            this.showNotification('Ошибка одобрения: ' + error.message, 'error');
        }
    }

    /**
     * Quick reject item
     */
    async quickReject(skuMappingId) {
        try {
            const response = await fetch('/mdm/verification/reject', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sku_mapping_id: skuMappingId, reason: 'Quick reject' })
            });

            const result = await response.json();
            if (result.success) {
                this.showNotification('Товар отклонен', 'success');
                this.removeItemFromList(skuMappingId);
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            this.showNotification('Ошибка отклонения: ' + error.message, 'error');
        }
    }

    /**
     * Utility methods
     */
    getConfidenceClass(score) {
        if (score >= 0.9) return 'high';
        if (score >= 0.7) return 'medium';
        return 'low';
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    showLoading() {
        document.getElementById('loading-overlay').classList.remove('d-none');
    }

    hideLoading() {
        document.getElementById('loading-overlay').classList.add('d-none');
    }

    showNotification(message, type = 'info') {
        // Implementation similar to dashboard notification
        console.log(`${type.toUpperCase()}: ${message}`);
    }

    removeItemFromList(skuMappingId) {
        const item = document.querySelector(`[data-id="${skuMappingId}"]`);
        if (item) {
            item.remove();
        }
        this.selectedItems.delete(skuMappingId);
        this.updateSelectionUI();
    }

    updateSelectionUI() {
        document.getElementById('selected-count').textContent = this.selectedItems.size;
        const hasSelection = this.selectedItems.size > 0;
        document.getElementById('approve-selected-btn').disabled = !hasSelection;
        document.getElementById('reject-selected-btn').disabled = !hasSelection;
    }
}

// Initialize verification interface
document.addEventListener('DOMContentLoaded', function() {
    window.verification = new MDMVerification();
});