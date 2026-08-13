// agent_properties.js - Agent Portfolio View

// ==================== STATE ====================
const state = {
    currentPage: 1,
    totalPages: 0,
    totalRecords: 0,
    perPage: 10,
    agents: [],
    selectedAgent: null,
    filters: {
        search: '',
        status: '',
        gender: ''
    }
};

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', function() {
    loadAgents();
    setupEventListeners();
});

// ==================== EVENT LISTENERS ====================
function setupEventListeners() {
    // Search input - Enter key
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    }
    
    // Status filter
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', applyFilters);
    }
    
    // Gender filter
    const genderFilter = document.getElementById('genderFilter');
    if (genderFilter) {
        genderFilter.addEventListener('change', applyFilters);
    }
    
    // Clear filters
    const clearBtn = document.getElementById('clearFiltersBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', clearFilters);
    }
    
    // Close modal on outside click
    const modal = document.getElementById('propertiesModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closePropertiesModal();
            }
        });
    }
}

// ==================== LOAD AGENTS ====================
async function loadAgents() {
    const container = document.getElementById('agentsGrid');
    container.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading agents...</p>
        </div>
    `;

    try {
        const params = new URLSearchParams({
            page: state.currentPage,
            limit: state.perPage,
            gender: state.filters.gender,
            search: state.filters.search,
            status: state.filters.status
        });

        const response = await fetch(`../backend/agents/get_agent.php?${params}`, {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        
        const data = await response.json();

        if (data.success) {
            const agents = data.agents || [];
            const pagination = data.pagination || {};

            state.agents = agents;
            state.totalRecords = parseInt(pagination.total, 10) || 0;
            state.totalPages = parseInt(pagination.total_pages, 10) || 0;
            
            // Render summary
            renderSummary(data.summary);
            
            // Render agents
            if (agents.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-user-tie"></i>
                        <h3>No Agents Found</h3>
                        <p>No agents match your search criteria.</p>
                    </div>
                `;
            } else {
                renderAgents(agents);
            }
            renderPagination();
        } else {
            throw new Error(data.message || 'Failed to load agents');
        }
    } catch (error) {
        console.error('Error loading agents:', error);
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                <h3>Failed to Load Agents</h3>
                <p>${escapeHtml(error.message || 'Please refresh the page and try again.')}</p>
                <button class="btn btn-primary" onclick="location.reload()" style="margin-top: 12px;">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        `;
    }
}

// ==================== RENDER SUMMARY ====================
function renderSummary(summary = {}) {
    const container = document.getElementById('summaryStats');
    if (!container) return;

    const totalAgents = parseInt(summary.total_agents, 10) || 0;
    const activeAgents = parseInt(summary.active_agents, 10) || 0;
    const inactiveAgents = parseInt(summary.inactive_agents, 10) || 0;
    const maleAgents = parseInt(summary.male_agents, 10) || 0;
    const femaleAgents = parseInt(summary.female_agents, 10) || 0;
    
    const summaryItems = [
        { label: 'Total Agents', value: totalAgents, class: 'primary' },
        { label: 'Active', value: activeAgents, class: 'success' },
        { label: 'Inactive', value: inactiveAgents, class: 'danger' },
        { label: 'Male', value: maleAgents, class: 'info' },
        { label: 'Female', value: femaleAgents, class: 'info' }
    ];
    
    container.innerHTML = summaryItems.map(item => `
        <div class="summary-stat">
            <span class="stat-value ${item.class}">${item.value}</span>
            <span class="stat-label">${item.label}</span>
        </div>
    `).join('');
}

// ==================== RENDER AGENTS ====================
function renderAgents(agents) {
    const container = document.getElementById('agentsGrid');
    
    container.innerHTML = agents.map(agent => {
        const fullName = `${agent.firstname || ''} ${agent.lastname || ''}`.trim() || 'Unknown Agent';
        const statusClass = agent.status == 1 ? 'active' : 'inactive';
        const statusText = agent.status == 1 ? 'Active' : 'Inactive';
        const rating = parseFloat(agent.avg_rating) || 0;
        const totalRatings = parseInt(agent.total_ratings) || 0;
        const propertyCount = agent.property_count || 0;
        
        // Generate star rating
        let starsHtml = '';
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating - fullStars >= 0.5;
        
        for (let i = 0; i < 5; i++) {
            if (i < fullStars) {
                starsHtml += '<i class="fas fa-star"></i>';
            } else if (i === fullStars && hasHalfStar) {
                starsHtml += '<i class="fas fa-star-half-alt"></i>';
            } else {
                starsHtml += '<i class="far fa-star"></i>';
            }
        }
        
        // Agent photo
        let photoHtml = '';
        if (agent.photo) {
            photoHtml = `<img src="../backend/agents/agent_photos/${agent.photo}" alt="${escapeHtml(fullName)}">`;
        } else {
            photoHtml = `<div class="no-photo"><i class="fas fa-user-circle"></i></div>`;
        }
        
        return `
            <div class="agent-card" data-agent-code="${escapeHtml(agent.agent_code)}">
                <div class="agent-avatar">
                    ${photoHtml}
                </div>
                <div class="agent-info">
                    <h4>${escapeHtml(fullName)}</h4>
                    <div class="agent-code">${escapeHtml(agent.agent_code)}</div>
                    <div class="agent-contact-info">
                        <span><i class="fas fa-envelope"></i> ${escapeHtml(agent.email || 'N/A')}</span>
                        <span><i class="fas fa-phone"></i> ${escapeHtml(agent.phone || 'N/A')}</span>
                    </div>
                    <div class="agent-rating">
                        <span class="stars">${starsHtml}</span>
                        <span class="rating-value">${rating.toFixed(1)}</span>
                        ${totalRatings > 0 ? `<span class="rating-count">(${totalRatings} reviews)</span>` : ''}
                    </div>
                    <div class="agent-stats">
                        <span class="badge ${statusClass}">${statusText}</span>
                        <span class="badge property-count">
                            <i class="fas fa-building"></i> ${propertyCount} Properties
                        </span>
                    </div>
                </div>
                <div class="agent-action">
                    <i class="fas fa-chevron-right"></i>
                </div>
            </div>
        `;
    }).join('');

    container.querySelectorAll('.agent-card').forEach(card => {
        card.addEventListener('click', () => {
            viewAgentProperties(card.dataset.agentCode);
        });
    });
}

// ==================== VIEW AGENT PROPERTIES ====================
async function viewAgentProperties(agentCode) {
    // Show modal with loading
    const modal = document.getElementById('propertiesModal');
    const grid = document.getElementById('modalPropertiesGrid');
    const agentName = document.getElementById('modalAgentName');
    
    // Find agent name from state
    const agent = state.agents.find(a => a.agent_code === agentCode);
    if (agent) {
        agentName.textContent = `${agent.firstname} ${agent.lastname}`;
    } else {
        agentName.textContent = agentCode;
    }
    
    grid.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading properties...</p>
        </div>
    `;
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    try {
        const params = new URLSearchParams({
            agent_code: agentCode,
            limit: 50
        });

        const response = await fetch(`../backend/agents/agent_properties.php?${params}`, {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        
        const data = await response.json();
        
        if (data.success) {
            const properties = data.data?.properties || [];
            const summary = data.data?.summary || {};
            
            if (properties.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <h3>No Properties Found</h3>
                        <p>This agent is not currently managing any properties.</p>
                    </div>
                `;
            } else {
                renderModalProperties(properties, summary);
            }
        } else {
            throw new Error(data.message || 'Failed to load properties');
        }
    } catch (error) {
        console.error('Error loading agent properties:', error);
        grid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                <p>${escapeHtml(error.message || 'Failed to load properties')}</p>
            </div>
        `;
    }
}

// ==================== RENDER MODAL PROPERTIES ====================
function renderModalProperties(properties, summary) {
    const container = document.getElementById('modalPropertiesGrid');
    
    // Show summary
    let summaryHtml = `
        <div class="modal-summary">
            <div class="modal-summary-item">
                <span class="label">Total Properties</span>
                <span class="value">${summary.total_properties || 0}</span>
            </div>
            <div class="modal-summary-item">
                <span class="label">Total Units</span>
                <span class="value">${summary.total_units || 0}</span>
            </div>
            <div class="modal-summary-item">
                <span class="label">Occupied</span>
                <span class="value">${summary.total_occupied || 0}</span>
            </div>
            <div class="modal-summary-item">
                <span class="label">Vacant</span>
                <span class="value">${summary.total_vacant || 0}</span>
            </div>
            <div class="modal-summary-item">
                <span class="label">Occupancy Rate</span>
                <span class="value">${summary.overall_occupancy_rate || 0}%</span>
            </div>
        </div>
    `;
    
    // Show properties grid
    let propertiesHtml = '<div class="modal-properties-grid">';
    properties.forEach(property => {
        const statusClass = property.status == 1 ? 'active' : 'inactive';
        const statusText = property.status == 1 ? 'Active' : 'Inactive';
        const occupancy = property.occupancy_percentage || 0;
        
        let photoHtml = '';
        if (property.photo) {
            photoHtml = `<img src="../backend/properties/property_photos/${property.photo}" alt="${escapeHtml(property.name)}">`;
        } else {
            photoHtml = `<div class="no-image"><i class="fas fa-building"></i></div>`;
        }
        
        propertiesHtml += `
            <div class="modal-property-card" data-property-code="${escapeHtml(property.property_code)}">
                <div class="property-image">
                    ${photoHtml}
                    <span class="property-status-badge ${statusClass}">${statusText}</span>
                </div>
                <div class="property-body">
                    <h4>${escapeHtml(property.name)}</h4>
                    <div class="property-address">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>${escapeHtml(property.address || 'N/A')}${property.city ? ', ' + escapeHtml(property.city) : ''}</span>
                    </div>
                    <div class="property-stats-grid">
                        <div class="stat">
                            <span class="stat-value">${property.apartments_created || 0}</span>
                            <span class="stat-label">Units</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${property.occupied_apartments || 0}</span>
                            <span class="stat-label">Occupied</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${property.vacant_apartments || 0}</span>
                            <span class="stat-label">Vacant</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${occupancy}%</span>
                            <span class="stat-label">Occupancy</span>
                        </div>
                    </div>
                    ${property.client_name ? `
                        <div class="property-client">
                            <i class="fas fa-user"></i> Client: ${escapeHtml(property.client_name)}
                        </div>
                    ` : ''}
                </div>
                <div class="property-footer">
                    <span class="property-code">${escapeHtml(property.property_code)}</span>
                    <span>Added: ${property.created_at_formatted || 'N/A'}</span>
                </div>
            </div>
        `;
    });
    propertiesHtml += '</div>';
    
    container.innerHTML = summaryHtml + propertiesHtml;

    container.querySelectorAll('.modal-property-card').forEach(card => {
        card.addEventListener('click', () => {
            viewProperty(card.dataset.propertyCode);
        });
    });
}

// ==================== VIEW PROPERTY DETAILS ====================
function viewProperty(propertyCode) {
    window.open(`property.php?property_code=${encodeURIComponent(propertyCode)}`, '_blank');
}

// ==================== CLOSE MODAL ====================
function closePropertiesModal() {
    document.getElementById('propertiesModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ==================== PAGINATION ====================
function renderPagination() {
    const container = document.getElementById('paginationControls');
    if (!container) return;
    
    const start = (state.currentPage - 1) * state.perPage + 1;
    const end = Math.min(state.currentPage * state.perPage, state.totalRecords);
    
    let paginationHtml = `
        <div class="pagination-info">
            Showing ${state.totalRecords > 0 ? start : 0} to ${end} of ${state.totalRecords} agents
        </div>
        <div class="pagination-controls">
            <button class="btn-page" onclick="changePage('prev')" ${state.currentPage <= 1 ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i> Prev
            </button>
    `;
    
    // Page numbers
    const totalPages = state.totalPages;
    const currentPage = state.currentPage;
    
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, currentPage + 2);
    
    if (startPage > 1) {
        paginationHtml += `<button class="btn-page" onclick="goToPage(1)">1</button>`;
        if (startPage > 2) {
            paginationHtml += `<span class="page-info-text">...</span>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        const activeClass = i === currentPage ? 'active' : '';
        paginationHtml += `<button class="btn-page ${activeClass}" onclick="goToPage(${i})">${i}</button>`;
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            paginationHtml += `<span class="page-info-text">...</span>`;
        }
        paginationHtml += `<button class="btn-page" onclick="goToPage(${totalPages})">${totalPages}</button>`;
    }
    
    paginationHtml += `
            <button class="btn-page" onclick="changePage('next')" ${state.currentPage >= state.totalPages ? 'disabled' : ''}>
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
    
    container.innerHTML = paginationHtml;
}

function changePage(direction) {
    if (direction === 'prev' && state.currentPage > 1) {
        state.currentPage--;
    } else if (direction === 'next' && state.currentPage < state.totalPages) {
        state.currentPage++;
    }
    loadAgents();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goToPage(page) {
    if (page >= 1 && page <= state.totalPages) {
        state.currentPage = page;
        loadAgents();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// ==================== FILTERS ====================
function applyFilters() {
    state.filters.search = document.getElementById('searchInput')?.value || '';
    state.filters.status = document.getElementById('statusFilter')?.value || '';
    state.filters.gender = document.getElementById('genderFilter')?.value || '';
    state.currentPage = 1;
    loadAgents();
}

function clearFilters() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const genderFilter = document.getElementById('genderFilter');
    
    if (searchInput) searchInput.value = '';
    if (statusFilter) statusFilter.value = '';
    if (genderFilter) genderFilter.value = '';
    
    state.filters = { search: '', status: '', gender: '' };
    state.currentPage = 1;
    loadAgents();
}

// ==================== UTILITY FUNCTIONS ====================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    toast.innerHTML = `
        <i class="fas ${icons[type] || icons.info}"></i>
        <span>${escapeHtml(message)}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        if (toast && toast.remove) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => {
                if (toast && toast.remove) {
                    toast.remove();
                }
            }, 300);
        }
    }, 3000);
}
