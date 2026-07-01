// client/scripts/tenants.js

let currentPage = 1;
let currentFilters = {
    property_code: '',
    status: '',
    search: '',
    sort_by: 'tenant_name',
    sort_order: 'desc'
};
let totalPages = 1;

document.addEventListener('DOMContentLoaded', () => {
    renderTenantsPage();
    loadTenants();
});

function renderTenantsPage() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    contentArea.innerHTML = `
        <div class="tenants-container">
            <div class="page-header">
                <h1><i class="fas fa-users"></i> Manage Tenants</h1>
                <p>View and manage tenants across your properties</p>
            </div>
            
            <div class="summary-cards" id="summaryCards">
                <div class="loading-overlay"><div class="loading-spinner"></div></div>
            </div>
            
            <div class="filters-bar">
                <div class="filters-row">
                    <div class="filter-group">
                        <label>Property</label>
                        <select id="filterProperty">
                            <option value="">All Properties</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select id="filterStatus">
                            <option value="">All Status</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" id="searchInput" placeholder="Name, email, or phone...">
                    </div>
                    <div class="filter-actions">
                        <button class="btn-apply" onclick="applyFilters()">
                            <i class="fas fa-search"></i> Apply
                        </button>
                        <button class="btn-reset" onclick="resetFilters()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
            
            <div id="tenantsLoading" class="loading-overlay" style="display: none;">
                <div class="loading-spinner"></div>
                <p>Loading tenants...</p>
            </div>
            
            <div id="tenantsTableContainer" class="tenants-table-container" style="display: none;">
                <table class="tenants-table">
                    <thead>
                        <tr>
                            <th onclick="sortTable('tenant_name')">
                                Tenant Name <i class="fas fa-sort"></i>
                            </th>
                            <th onclick="sortTable('apartment_number')">
                                Apartment <i class="fas fa-sort"></i>
                            </th>
                            <th>Contact</th>
                            <th onclick="sortTable('status')">
                                Status <i class="fas fa-sort"></i>
                            </th>
                            <th onclick="sortTable('lease_end_date')">
                                Lease End <i class="fas fa-sort"></i>
                            </th>
                            <th onclick="sortTable('rating')">
                                Rating <i class="fas fa-sort"></i>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tenantsTableBody">
                        <tr><td colspan="7" class="empty-state">No tenants found</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="tenantsCardsContainer" class="tenants-cards-container"></div>
            
            <div id="pagination" class="pagination"></div>
        </div>
    `;
}

async function loadTenants(page = 1) {
    currentPage = page;
    
    const params = new URLSearchParams({
        page: currentPage,
        limit: 20,
        property_code: currentFilters.property_code,
        status: currentFilters.status,
        search: currentFilters.search,
        sort_by: currentFilters.sort_by,
        sort_order: currentFilters.sort_order
    });
    
    const loadingDiv = document.getElementById('tenantsLoading');
    const tableContainer = document.getElementById('tenantsTableContainer');
    
    if (loadingDiv) loadingDiv.style.display = 'block';
    if (tableContainer) tableContainer.style.display = 'none';
    
    try {
        const response = await fetch(`../backend/tenants/fetch_tenants.php?${params}`);
        const data = await response.json();
        
        if (data.success) {
            renderSummary(data.data.summary);
            renderTenantsTable(data.data);
            renderPagination(data.data.pagination);
            updateFilters(data.data);
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Error loading tenants:', error);
        if (window.showToast) {
            window.showToast('Failed to load tenants', 'error');
        }
    } finally {
        if (loadingDiv) loadingDiv.style.display = 'none';
        if (tableContainer) tableContainer.style.display = 'block';
    }
}

function renderSummary(summary) {
    const container = document.getElementById('summaryCards');
    if (!container) return;
    
    container.innerHTML = `
        <div class="summary-card total">
            <div class="summary-icon"><i class="fas fa-users"></i></div>
            <div class="summary-info">
                <div class="summary-label">Total Tenants</div>
                <div class="summary-value">${summary.total_tenants || 0}</div>
            </div>
        </div>
        <div class="summary-card active">
            <div class="summary-icon"><i class="fas fa-user-check"></i></div>
            <div class="summary-info">
                <div class="summary-label">Active Tenants</div>
                <div class="summary-value">${summary.active_tenants || 0}</div>
            </div>
        </div>
        <div class="summary-card inactive">
            <div class="summary-icon"><i class="fas fa-user-slash"></i></div>
            <div class="summary-info">
                <div class="summary-label">Inactive Tenants</div>
                <div class="summary-value">${summary.inactive_tenants || 0}</div>
            </div>
        </div>
        <div class="summary-card expired">
            <div class="summary-icon"><i class="fas fa-calendar-times"></i></div>
            <div class="summary-info">
                <div class="summary-label">Expired Leases</div>
                <div class="summary-value">${summary.expired_leases || 0}</div>
            </div>
        </div>
    `;
}

function renderTenantsTable(data) {
    const container = document.getElementById('tenantsTableBody');
    const cardsContainer = document.getElementById('tenantsCardsContainer');
    if (!container) return;
    
    if (!data.tenants || data.tenants.length === 0) {
        container.innerHTML = '<tr><td colspan="7" class="empty-state"><i class="fas fa-user-slash"></i><p>No tenants found</p></td></tr>';
        if (cardsContainer) {
            cardsContainer.innerHTML = `
                <div class="empty-card">
                    <i class="fas fa-user-slash"></i>
                    <p>No tenants found</p>
                </div>
            `;
        }
        return;
    }
    
    container.innerHTML = data.tenants.map(tenant => `
        <tr>
            <td data-label="Tenant">
                <strong>${escapeHtml(tenant.tenant_name)}</strong><br>
                <small style="color:#666;">${escapeHtml(tenant.email)}</small>
            </td>
            <td data-label="Apartment">${escapeHtml(tenant.apartment_number)}<br><small>${escapeHtml(tenant.property_name)}</small></td>
            <td data-label="Contact">${escapeHtml(tenant.phone)}</td>
            <td data-label="Status">
                <span class="status-badge status-${tenant.status_badge}">
                    ${tenant.status_badge === 'active' ? 'Active' : 'Inactive'}
                </span>
                ${tenant.lease_status === 'expiring_soon' ? '<br><small class="lease-expiring">Expiring soon</small>' : ''}
                ${tenant.lease_status === 'expired' ? '<br><small class="lease-expired">Expired</small>' : ''}
            </td>
            <td data-label="Lease End">${escapeHtml(tenant.lease_end_formatted)}<br><small>${tenant.days_remaining > 0 ? escapeHtml(tenant.days_remaining + ' days left') : 'Expired'}</small></td>
            <td data-label="Rating">
                <div class="rating-stars">
                    ${renderStarRating(tenant.avg_rating)}
                    <span class="rating-count">(${tenant.rating_count})</span>
                </div>
            </td>
            <td data-label="Actions">
                <button class="btn-view" type="button" onclick="viewTenantDetails('${escapeAttribute(tenant.tenant_code)}')">
                    <i class="fas fa-eye"></i> View Details
                </button>
            </td>
        </tr>
    `).join('');

    if (cardsContainer) {
        cardsContainer.innerHTML = data.tenants.map(tenant => `
            <article class="tenant-card">
                <div class="tenant-card-header">
                    <div>
                        <strong>${escapeHtml(tenant.tenant_name)}</strong>
                        <span>${escapeHtml(tenant.email)}</span>
                    </div>
                    <span class="status-badge status-${tenant.status_badge}">
                        ${tenant.status_badge === 'active' ? 'Active' : 'Inactive'}
                    </span>
                </div>
                <div class="tenant-card-meta">
                    <div>
                        <span>Apartment</span>
                        <strong>${escapeHtml(tenant.apartment_number)}</strong>
                        <small>${escapeHtml(tenant.property_name)}</small>
                    </div>
                    <div>
                        <span>Contact</span>
                        <strong>${escapeHtml(tenant.phone)}</strong>
                    </div>
                    <div>
                        <span>Lease End</span>
                        <strong>${escapeHtml(tenant.lease_end_formatted)}</strong>
                        <small>${tenant.days_remaining > 0 ? escapeHtml(tenant.days_remaining + ' days left') : 'Expired'}</small>
                    </div>
                    <div>
                        <span>Rating</span>
                        <div class="rating-stars">
                            ${renderStarRating(tenant.avg_rating)}
                            <span class="rating-count">(${tenant.rating_count})</span>
                        </div>
                    </div>
                </div>
                ${tenant.lease_status === 'expiring_soon' ? '<div class="tenant-card-alert lease-expiring">Expiring soon</div>' : ''}
                ${tenant.lease_status === 'expired' ? '<div class="tenant-card-alert lease-expired">Expired</div>' : ''}
                <div class="tenant-card-actions">
                    <button class="btn-view" type="button" onclick="viewTenantDetails('${escapeAttribute(tenant.tenant_code)}')">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                </div>
            </article>
        `).join('');
    }
}

function renderStarRating(rating) {
    rating = parseFloat(rating) || 0;
    let stars = '';
    for (let i = 1; i <= 5; i++) {
        if (i <= Math.floor(rating)) {
            stars += '<i class="fas fa-star active"></i>';
        } else if (i - 0.5 <= rating) {
            stars += '<i class="fas fa-star-half-alt active"></i>';
        } else {
            stars += '<i class="far fa-star"></i>';
        }
    }
    return stars;
}

function renderPagination(pagination) {
    const container = document.getElementById('pagination');
    if (!container) return;
    
    if (pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = `
        <div class="pagination-info">
            Showing ${pagination.from} to ${pagination.to} of ${pagination.total_records} tenants
        </div>
        <div class="pagination-controls">
            <button class="page-btn" onclick="loadTenants(1)" ${!pagination.has_previous ? 'disabled' : ''}>
                <i class="fas fa-angle-double-left"></i>
            </button>
            <button class="page-btn" onclick="loadTenants(${pagination.current_page - 1})" ${!pagination.has_previous ? 'disabled' : ''}>
                <i class="fas fa-angle-left"></i>
            </button>
    `;
    
    const startPage = Math.max(1, pagination.current_page - 2);
    const endPage = Math.min(pagination.total_pages, startPage + 4);
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<button class="page-btn ${i === pagination.current_page ? 'active' : ''}" onclick="loadTenants(${i})">${i}</button>`;
    }
    
    html += `
            <button class="page-btn" onclick="loadTenants(${pagination.current_page + 1})" ${!pagination.has_next ? 'disabled' : ''}>
                <i class="fas fa-angle-right"></i>
            </button>
            <button class="page-btn" onclick="loadTenants(${pagination.total_pages})" ${!pagination.has_next ? 'disabled' : ''}>
                <i class="fas fa-angle-double-right"></i>
            </button>
        </div>
    `;
    
    container.innerHTML = html;
}

function updateFilters(data) {
    const propertySelect = document.getElementById('filterProperty');
    if (propertySelect && data.properties) {
        propertySelect.innerHTML = '<option value="">All Properties</option>' +
            data.properties.map(p => `<option value="${p.property_code}" ${data.filters.current_property === p.property_code ? 'selected' : ''}>${escapeHtml(p.name)}</option>`).join('');
    }
    
    const statusSelect = document.getElementById('filterStatus');
    if (statusSelect) {
        statusSelect.value = data.filters.current_status;
    }
    
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = data.filters.current_search;
    }
}

function applyFilters() {
    currentFilters.property_code = document.getElementById('filterProperty')?.value || '';
    currentFilters.status = document.getElementById('filterStatus')?.value || '';
    currentFilters.search = document.getElementById('searchInput')?.value || '';
    loadTenants(1);
}

function resetFilters() {
    currentFilters = {
        property_code: '',
        status: '',
        search: '',
        sort_by: 'tenant_name',
        sort_order: 'desc'
    };
    
    const propertySelect = document.getElementById('filterProperty');
    const statusSelect = document.getElementById('filterStatus');
    const searchInput = document.getElementById('searchInput');
    
    if (propertySelect) propertySelect.value = '';
    if (statusSelect) statusSelect.value = '';
    if (searchInput) searchInput.value = '';
    
    loadTenants(1);
}

function sortTable(column) {
    if (currentFilters.sort_by === column) {
        currentFilters.sort_order = currentFilters.sort_order === 'desc' ? 'asc' : 'desc';
    } else {
        currentFilters.sort_by = column;
        currentFilters.sort_order = 'desc';
    }
    loadTenants(1);
}

async function viewTenantDetails(tenantCode) {
    try {
        const response = await fetch(`../backend/tenants/fetch_tenant_details.php?tenant_code=${tenantCode}`);
        const data = await response.json();
        
        if (data.success) {
            showTenantDetailsModal(data.data);
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Error fetching tenant details:', error);
        if (window.showToast) window.showToast('Failed to load tenant details', 'error');
    }
}

function showTenantDetailsModal(tenant) {
    let modal = document.getElementById('tenantDetailsModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'tenantDetailsModal';
        modal.className = 'modal';
        document.body.appendChild(modal);
    }
    
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user"></i> Tenant Details: ${escapeHtml(tenant.full_name)}</h3>
                <button class="modal-close" onclick="closeModal('tenantDetailsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="tenant-detail-grid">
                    <div class="detail-section">
                        <h4><i class="fas fa-id-card"></i> Personal Information</h4>
                        <div class="detail-row"><span class="detail-label">Full Name:</span><span class="detail-value">${escapeHtml(tenant.full_name)}</span></div>
                        <div class="detail-row"><span class="detail-label">Email:</span><span class="detail-value">${escapeHtml(tenant.email)}</span></div>
                        <div class="detail-row"><span class="detail-label">Phone:</span><span class="detail-value">${escapeHtml(tenant.phone)}</span></div>
                        <div class="detail-row"><span class="detail-label">Tenant ID:</span><span class="detail-value">${escapeHtml(tenant.tenant_code || 'N/A')}</span></div>
                        <div class="detail-row"><span class="detail-label">Gender:</span><span class="detail-value">${escapeHtml(tenant.gender || 'N/A')}</span></div>
                        
                    </div>
                    
                    <div class="detail-section">
                        <h4><i class="fas fa-briefcase"></i> Employment & Emergency</h4>
                        <div class="detail-row"><span class="detail-label">Occupation:</span><span class="detail-value">${escapeHtml(tenant.occupation || 'N/A')}</span></div>
                        <div class="detail-row"><span class="detail-label">Employer:</span><span class="detail-value">${escapeHtml(tenant.name_of_employer || 'N/A')}</span></div>
                        <div class="detail-row"><span class="detail-label">Employer Address:</span><span class="detail-value">${escapeHtml(tenant.employer_address || 'N/A')}</span></div>
                        <div class="detail-row"><span class="detail-label">Emergency Contact:</span><span class="detail-value">${escapeHtml(tenant.emergency_contact_name || 'N/A')}</span></div>
                        <div class="detail-row"><span class="detail-label">Emergency Phone:</span><span class="detail-value">${escapeHtml(tenant.emergency_contact_phone || 'N/A')}</span></div>
                        
                    </div>
                    
                    <div class="detail-section">
                        <h4><i class="fas fa-home"></i> Property & Lease</h4>
                        <div class="detail-row"><span class="detail-label">Property:</span><span class="detail-value">${escapeHtml(tenant.property_name)}</span></div>
                        <div class="detail-row"><span class="detail-label">Apartment:</span><span class="detail-value">${escapeHtml(tenant.apartment_number)}</span></div>
                        <div class="detail-row"><span class="detail-label">Lease Start:</span><span class="detail-value">${tenant.lease_start_formatted}</span></div>
                        <div class="detail-row"><span class="detail-label">Lease End:</span><span class="detail-value">${tenant.lease_end_formatted}</span></div>
                        
                        <div class="detail-row"><span class="detail-label">Rent Amount:</span><span class="detail-value">${formatCurrency(tenant.agreed_rent_amount)}</span></div>
                        <div class="detail-row"><span class="detail-label">Payment Frequency:</span><span class="detail-value">${escapeHtml(tenant.payment_frequency || 'Monthly')}</span></div>
                        <div class="detail-row"><span class="detail-label">Days Remaining:</span><span class="detail-value ${tenant.days_remaining <= 30 ? 'lease-expiring' : ''}">${tenant.days_remaining} days (${tenant.months_remaining} months)</span></div>
                    </div>
                    
                    <div class="detail-section">
                        <h4><i class="fas fa-chart-line"></i> Statistics</h4>
                        <div class="detail-row"><span class="detail-label">Total Paid:</span><span class="detail-value">${formatCurrency(tenant.total_paid)}</span></div>
                        <div class="detail-row"><span class="detail-label">Total Pending:</span><span class="detail-value">${formatCurrency(tenant.total_pending)}</span></div>
                        <div class="detail-row"><span class="detail-label">Maintenance Requests:</span><span class="detail-value">${tenant.total_maintenance_requests}</span></div>
                        <div class="detail-row"><span class="detail-label">Completed Maintenance:</span><span class="detail-value">${tenant.completed_maintenance} (${tenant.maintenance_completion_rate}%)</span></div>
                        <div class="detail-row"><span class="detail-label">Tenant Since:</span><span class="detail-value">${tenant.created_at}</span></div>
                        <div class="detail-row"><span class="detail-label">Overall Rating:</span><span class="detail-value">${renderStarRating(tenant.avg_rating)} (${tenant.rating_count} reviews)</span></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-primary" type="button" data-tenant-code="${escapeAttribute(tenant.tenant_code)}" data-tenant-name="${escapeAttribute(tenant.full_name)}" onclick="openRateTenantModal(this.dataset.tenantCode, this.dataset.tenantName)">
                    <i class="fas fa-star"></i> Rate Tenant
                </button>
                <button class="btn-secondary" type="button" onclick="closeModal('tenantDetailsModal')">Close</button>
            </div>
        </div>
    `;
    
    modal.style.display = 'flex';
}

let currentRatingTenant = null;
let currentRatingTenantName = '';
let selectedCategory = 'overall';
let selectedRating = 0;

function openRateTenantModal(tenantCode, tenantName) {
    currentRatingTenant = tenantCode;
    currentRatingTenantName = tenantName;
    selectedCategory = 'overall';
    selectedRating = 0;
    
    let modal = document.getElementById('rateTenantModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'rateTenantModal';
        modal.className = 'modal';
        document.body.appendChild(modal);
    }
    
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-star"></i> Rate Tenant: ${escapeHtml(tenantName)}</h3>
                <button class="modal-close" onclick="closeModal('rateTenantModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="rating-options">
                    <div class="rating-category ${selectedCategory === 'overall' ? 'active' : ''}" onclick="selectRatingCategory('overall')">
                        <i class="fas fa-chart-simple"></i>
                        <span>Overall</span>
                    </div>
                    <div class="rating-category ${selectedCategory === 'payment' ? 'active' : ''}" onclick="selectRatingCategory('payment')">
                        <i class="fas fa-credit-card"></i>
                        <span>Payment</span>
                    </div>
                    <div class="rating-category ${selectedCategory === 'behavior' ? 'active' : ''}" onclick="selectRatingCategory('behavior')">
                        <i class="fas fa-hand-peace"></i>
                        <span>Behavior</span>
                    </div>
                    <div class="rating-category ${selectedCategory === 'cleanliness' ? 'active' : ''}" onclick="selectRatingCategory('cleanliness')">
                        <i class="fas fa-broom"></i>
                        <span>Cleanliness</span>
                    </div>
                    <div class="rating-category ${selectedCategory === 'maintenance' ? 'active' : ''}" onclick="selectRatingCategory('maintenance')">
                        <i class="fas fa-tools"></i>
                        <span>Maintenance</span>
                    </div>
                </div>
                
                <div class="star-rating-selector" id="starRatingSelector">
                    ${[1, 2, 3, 4, 5].map(star => `
                        <i class="fas fa-star" data-rating="${star}" onmouseover="highlightStarRating(${star})" onmouseout="resetStarRating()" onclick="setStarRating(${star})"></i>
                    `).join('')}
                </div>
                
                <textarea id="ratingComment" class="rating-comment" placeholder="Share your feedback about this tenant... (Optional)"></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" type="button" onclick="closeModal('rateTenantModal')">Cancel</button>
                <button class="btn-primary" type="button" onclick="submitTenantRating()">
                    <i class="fas fa-paper-plane"></i> Submit Rating
                </button>
            </div>
        </div>
    `;
    
    modal.style.display = 'flex';
}

function selectRatingCategory(category) {
    selectedCategory = category;
    
    document.querySelectorAll('.rating-category').forEach(el => {
        el.classList.remove('active');
    });
    event.target.closest('.rating-category').classList.add('active');
}

function highlightStarRating(rating) {
    const stars = document.querySelectorAll('#starRatingSelector i');
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.add('hover');
        } else {
            star.classList.remove('hover');
        }
    });
}

function resetStarRating() {
    const stars = document.querySelectorAll('#starRatingSelector i');
    stars.forEach(star => {
        star.classList.remove('hover');
        if (parseInt(star.dataset.rating) <= selectedRating) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
}

function setStarRating(rating) {
    selectedRating = rating;
    const stars = document.querySelectorAll('#starRatingSelector i');
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
}

async function submitTenantRating() {
    if (selectedRating === 0) {
        showInlineMessage('rateTenantModal', 'Please select a rating', 'error');
        return;
    }
    
    const comment = document.getElementById('ratingComment')?.value || '';
    
    const submitBtn = document.querySelector('#rateTenantModal .btn-primary');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    submitBtn.disabled = true;
    
    // Remove any existing inline message
    removeInlineMessage('rateTenantModal');
    
    try {
        const response = await fetch('../backend/tenants/rate_tenant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tenant_code: currentRatingTenant,
                rating: selectedRating,
                comment: comment,
                category: selectedCategory
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Show success message inside modal
            showInlineMessage('rateTenantModal', '✓ Rating submitted successfully!', 'success');
            
            // Close modal after 1.5 seconds and reload
            setTimeout(() => {
                closeModal('rateTenantModal');
                loadTenants(currentPage);
            }, 1500);
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Error submitting rating:', error);
        showInlineMessage('rateTenantModal', error.message || 'Failed to submit rating', 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

// Helper function to show inline message inside modal
function showInlineMessage(modalId, message, type) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    // Remove existing message first
    removeInlineMessage(modalId);
    
    // Create message element
    const messageDiv = document.createElement('div');
    messageDiv.className = `inline-message ${type}`;
    messageDiv.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
        <span>${escapeHtml(message)}</span>
    `;
    
    // Insert after the modal body content
    const modalBody = modal.querySelector('.modal-body');
    if (modalBody) {
        modalBody.appendChild(messageDiv);
        
        // Auto-remove after 3 seconds for non-success messages
        if (type !== 'success') {
            setTimeout(() => {
                if (messageDiv && messageDiv.remove) {
                    messageDiv.remove();
                }
            }, 3000);
        }
    }
}

// Helper function to remove inline message
function removeInlineMessage(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    const existingMessage = modal.querySelector('.inline-message');
    if (existingMessage) {
        existingMessage.remove();
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(String(text ?? ''));
}

function formatCurrency(value) {
    return `₦${new Intl.NumberFormat('en-NG', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    }).format(Number(value) || 0)}`;
}
