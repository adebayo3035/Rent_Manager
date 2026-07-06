// settlement.js - Property Settlement Management

// ==================== STATE ====================
let properties = [];
let selectedPropertyId = null;
let resetPropertyId = null;

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', function() {
    loadProperties();
});

// ==================== LOAD PROPERTIES ====================
async function loadProperties() {
    const tbody = document.getElementById('settlementTableBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="10" class="loading-cell">
                <div class="spinner"></div>
                Loading properties...
            </td>
        </tr>
    `;

    try {
        const response = await fetch('../backend/settlement/manage_settlement.php?action=get_properties');
        const data = await response.json();

        if (data.success && Array.isArray(data.message)) {
            properties = data.message;
            renderTable(properties);
            updateRowCount(properties.length);
        } 
        else if(data.responseCode = "403"){
            window.location.replace("../pages/unauthorized.php")
        }
        else {
            showToast(data.data || 'Failed to load properties', 'error');
            tbody.innerHTML = `
                <tr>
                    <td colspan="10" class="loading-cell" style="color: #ef4444;">
                        <i class="fas fa-exclamation-circle"></i>
                        ${data.data || 'Failed to load properties'}
                    </td>
                </tr>
            `;
        }
    } catch (error) {
        console.error('Error loading properties:', error);
        showToast('Network error. Please try again.', 'error');
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="loading-cell" style="color: #ef4444;">
                    <i class="fas fa-exclamation-circle"></i>
                    Failed to load properties. Please refresh the page.
                </td>
            </tr>
        `;
    }
}

// ==================== RENDER TABLE ====================
function renderTable(propertiesData) {
    const tbody = document.getElementById('settlementTableBody');
    
    if (!propertiesData || !Array.isArray(propertiesData) || propertiesData.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="loading-cell">
                    No properties found.
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    propertiesData.forEach(property => {
        const adminPct = parseFloat(property.admin_percentage) || 10.00;
        const agentPct = parseFloat(property.agent_percentage) || 5.00;
        const clientPct = parseFloat(property.client_percentage) || 85.00;
        const total = adminPct + agentPct + clientPct;
        const isValid = Math.abs(total - 100) <= 0.01;
        const statusClass = property.status == 1 ? 'badge-success' : 'badge-danger';
        const statusText = property.status == 1 ? 'Active' : 'Inactive';
        
        // Settlement status
        const settlementStatus = property.settlement_status || 'active';
        const isPending = settlementStatus === 'pending';
        const settlementStatusClass = isPending ? 'badge-warning' : 'badge-success';
        const settlementStatusText = isPending ? 'Pending Approval' : 'Active';

        html += `
            <tr>
                <td>
                    <div class="property-cell">
                        <span class="property-code">${escapeHtml(property.property_code)}</span>
                        <span class="property-name">${escapeHtml(property.property_name)}</span>
                        <span class="badge ${statusClass}">${statusText}</span>
                    </div>
                </td>
                <td>${escapeHtml(property.client_code)}</td>
                <td>${escapeHtml(property.agent_code || 'None')}</td>
                <td>
                    <input type="number" class="percentage-input input-sm" 
                           value="${adminPct.toFixed(2)}" step="0.01" min="0" max="100"
                           data-property-id="${property.id}" data-type="admin"
                           onchange="onPercentageChange(this)" ${isPending ? 'disabled' : ''}>
                </td>
                <td>
                    <input type="number" class="percentage-input input-sm" 
                           value="${agentPct.toFixed(2)}" step="0.01" min="0" max="100"
                           data-property-id="${property.id}" data-type="agent"
                           onchange="onPercentageChange(this)" ${isPending ? 'disabled' : ''}>
                </td>
                <td>
                    <input type="number" class="percentage-input input-sm" 
                           value="${clientPct.toFixed(2)}" step="0.01" min="0" max="100"
                           data-property-id="${property.id}" data-type="client"
                           onchange="onPercentageChange(this)" ${isPending ? 'disabled' : ''}>
                </td>
                <td class="total-cell ${isValid ? 'total-valid' : 'total-invalid'}">
                    ${total.toFixed(2)}%
                </td>
                <td>
                    <span class="badge ${settlementStatusClass}">${settlementStatusText}</span>
                </td>
                <td class="updated-cell">
                    ${property.updated_at ? formatDate(property.updated_at) : 'Never'}
                    ${property.updated_by_name ? `<br><span class="updated-by">by ${escapeHtml(property.updated_by_name)}</span>` : ''}
                </td>
                <td>
                    <div class="action-cell">
                        ${!isPending ? `
                            <button class="btn btn-primary btn-sm" onclick="openUpdateModal(${property.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-warning btn-sm" onclick="openResetModal(${property.id}, '${escapeHtml(property.property_name)}')">
                                <i class="fas fa-undo"></i>
                            </button>
                        ` : `
                            <span class="text-muted" style="font-size: 12px;">
                                <i class="fas fa-clock"></i> Pending approval
                            </span>
                        `}
                    </div>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

// ==================== PERCENTAGE CHANGE (Inline Edit) ====================
function onPercentageChange(input) {
    const row = input.closest('tr');
    const adminInput = row.querySelector('[data-type="admin"]');
    const agentInput = row.querySelector('[data-type="agent"]');
    const clientInput = row.querySelector('[data-type="client"]');
    const totalCell = row.querySelector('.total-cell');

    const admin = parseFloat(adminInput.value) || 0;
    const agent = parseFloat(agentInput.value) || 0;
    const client = parseFloat(clientInput.value) || 0;
    const total = admin + agent + client;

    totalCell.textContent = total.toFixed(2) + '%';
    totalCell.className = `total-cell ${Math.abs(total - 100) <= 0.01 ? 'total-valid' : 'total-invalid'}`;
}

// ==================== UPDATE MODAL ====================
function openUpdateModal(propertyId) {
    const property = properties.find(p => parseInt(p.id) === propertyId);
    if (!property) {
        showToast('Property not found', 'error');
        return;
    }

    selectedPropertyId = propertyId;
    document.getElementById('modalPropertyId').value = propertyId;
    document.getElementById('modalAdminPct').value = property.admin_percentage || 10.00;
    document.getElementById('modalAgentPct').value = property.agent_percentage || 5.00;
    document.getElementById('modalClientPct').value = property.client_percentage || 85.00;
    document.getElementById('modalNotes').value = '';

    document.getElementById('modalPropertyInfo').innerHTML = `
        <div><span class="label">Property:</span> <span class="value">${escapeHtml(property.property_code)} - ${escapeHtml(property.property_name)}</span></div>
        <div><span class="label">Client:</span> <span class="value">${escapeHtml(property.client_code)}</span></div>
        <div><span class="label">Agent:</span> <span class="value">${escapeHtml(property.agent_code || 'None')}</span></div>
        <div><span class="label">Current Formula:</span> <span class="value">Admin ${property.admin_percentage}% / Agent ${property.agent_percentage}% / Client ${property.client_percentage}%</span></div>
    `;

    // Add event listeners for real-time total calculation
    ['modalAdminPct', 'modalAgentPct', 'modalClientPct'].forEach(id => {
        document.getElementById(id).addEventListener('input', updateModalTotal);
    });

    updateModalTotal();
    document.getElementById('updateModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function updateModalTotal() {
    const admin = parseFloat(document.getElementById('modalAdminPct').value) || 0;
    const agent = parseFloat(document.getElementById('modalAgentPct').value) || 0;
    const client = parseFloat(document.getElementById('modalClientPct').value) || 0;
    const total = admin + agent + client;
    const isValid = Math.abs(total - 100) <= 0.01;

    document.getElementById('modalTotal').textContent = total.toFixed(2);
    const statusEl = document.getElementById('modalTotalStatus');
    statusEl.textContent = isValid ? '✅ Valid' : '❌ Must equal 100%';
    statusEl.style.color = isValid ? '#10b981' : '#ef4444';
}

function closeModal() {
    document.getElementById('updateModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ==================== UPDATE SETTLEMENT ====================
async function updateSettlement() {
    const propertyId = document.getElementById('modalPropertyId').value;
    const adminPct = parseFloat(document.getElementById('modalAdminPct').value);
    const agentPct = parseFloat(document.getElementById('modalAgentPct').value);
    const clientPct = parseFloat(document.getElementById('modalClientPct').value);
    const notes = document.getElementById('modalNotes').value.trim();

    // Validate
    if (isNaN(adminPct) || isNaN(agentPct) || isNaN(clientPct)) {
        showToast('Please enter valid percentages', 'error');
        return;
    }

    const total = adminPct + agentPct + clientPct;
    if (Math.abs(total - 100) > 0.01) {
        showToast(`Percentages must total 100%. Current total: ${total.toFixed(2)}%`, 'error');
        return;
    }

    const updateBtn = document.querySelector('#updateModal .btn-primary');
    const originalText = updateBtn.innerHTML;
    updateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    updateBtn.disabled = true;

    try {
        const response = await fetch('../backend/settlement/manage_settlement.php?action=update', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                property_id: propertyId,
                admin_percentage: adminPct,
                agent_percentage: agentPct,
                client_percentage: clientPct,
                notes: notes
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Settlement change proposal submitted. Awaiting client approval.', 'success');
            closeModal();
            loadProperties(); // Refresh table
        } else {
            showToast(data.data || 'Update failed', 'error');
        }
    } catch (error) {
        console.error('Update error:', error);
        showToast('Network error. Please try again.', 'error');
    } finally {
        updateBtn.innerHTML = originalText;
        updateBtn.disabled = false;
    }
}

// ==================== RESET MODAL ====================
function openResetModal(propertyId, propertyName) {
    resetPropertyId = propertyId;
    document.getElementById('resetPropertyName').textContent = propertyName;
    document.getElementById('resetModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
    document.body.style.overflow = '';
    resetPropertyId = null;
}

async function confirmReset() {
    if (!resetPropertyId) return;

    const resetBtn = document.querySelector('#resetModal .btn-warning');
    const originalText = resetBtn.innerHTML;
    resetBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    resetBtn.disabled = true;

    try {
        const response = await fetch('../backend/settlement/manage_settlement.php?action=reset', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                property_id: resetPropertyId,
                notes: 'Reset to default (10%, 5%, 85%)'
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Reset proposal submitted. Awaiting client approval.', 'success');
            closeResetModal();
            loadProperties(); // Refresh table
        } else {
            showToast(data.message || 'Reset failed', 'error');
            closeResetModal();
        }
    } catch (error) {
        console.error('Reset error:', error);
        showToast('Network error. Please try again.', 'error');
        closeResetModal();
    } finally {
        resetBtn.innerHTML = originalText;
        resetBtn.disabled = false;
    }
}

// ==================== RESET ALL ====================
function resetAllToDefault() {
    showCustomConfirm(
        'Reset All Properties',
        'Are you sure you want to reset all properties to default (10%, 5%, 85%)? This will create individual proposals for each property awaiting client approval.',
        async () => {
            try {
                const response = await fetch('../backend/settlement/manage_settlement.php?action=reset_all', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.data || 'Reset proposals submitted for all properties', 'success');
                    loadProperties();
                } else {
                    showToast(data.data || 'Reset all failed', 'error');
                }
            } catch (error) {
                console.error('Reset all error:', error);
                showToast('Network error. Please try again.', 'error');
            }
        },
        () => {
            console.log('Reset all cancelled');
        }
    );
}

// ==================== CUSTOM CONFIRM MODAL ====================
function showCustomConfirm(title, message, onConfirm, onCancel) {
    const existingDialog = document.getElementById('customConfirmDialog');
    if (existingDialog) {
        existingDialog.remove();
    }

    const dialogHtml = `
        <div id="customConfirmDialog" class="confirm-dialog">
            <div class="confirm-dialog-content">
                <div class="confirm-dialog-header">
                    <h3>${escapeHtml(title)}</h3>
                    <button class="confirm-dialog-close">&times;</button>
                </div>
                <div class="confirm-dialog-body">
                    <p>${escapeHtml(message)}</p>
                </div>
                <div class="confirm-dialog-footer">
                    <button class="confirm-btn-cancel">Cancel</button>
                    <button class="confirm-btn-confirm">Yes, Reset All</button>
                </div>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', dialogHtml);

    const dialog = document.getElementById('customConfirmDialog');
    const confirmBtn = dialog.querySelector('.confirm-btn-confirm');
    const cancelBtn = dialog.querySelector('.confirm-btn-cancel');
    const closeBtn = dialog.querySelector('.confirm-dialog-close');

    const closeDialog = () => {
        if (dialog && dialog.remove) {
            dialog.remove();
        }
    };

    confirmBtn.onclick = () => {
        closeDialog();
        if (onConfirm) onConfirm();
    };

    cancelBtn.onclick = () => {
        closeDialog();
        if (onCancel) onCancel();
    };

    closeBtn.onclick = () => {
        closeDialog();
        if (onCancel) onCancel();
    };

    dialog.addEventListener('click', function(e) {
        if (e.target === dialog) {
            closeDialog();
            if (onCancel) onCancel();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('customConfirmDialog')) {
            closeDialog();
            if (onCancel) onCancel();
        }
    }, { once: true });
}

// ==================== SEARCH/FILTER ====================
function filterTable() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const filtered = properties.filter(p => 
        p.property_code.toLowerCase().includes(searchTerm) ||
        p.property_name.toLowerCase().includes(searchTerm) ||
        p.client_code.toLowerCase().includes(searchTerm) ||
        (p.agent_code && p.agent_code.toLowerCase().includes(searchTerm))
    );
    renderTable(filtered);
    updateRowCount(filtered.length);
}

function updateRowCount(count) {
    document.getElementById('rowCount').textContent = `${count} property${count !== 1 ? 's' : ''}`;
}

// ==================== TOAST SYSTEM ====================
function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
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

// ==================== UTILITY FUNCTIONS ====================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    if (!dateString) return 'Never';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Close modals on outside click
document.addEventListener('click', function(e) {
    const updateModal = document.getElementById('updateModal');
    const resetModal = document.getElementById('resetModal');
    
    if (e.target === updateModal) {
        closeModal();
    }
    if (e.target === resetModal) {
        closeResetModal();
    }
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('updateModal').style.display === 'flex') {
            closeModal();
        }
        if (document.getElementById('resetModal').style.display === 'flex') {
            closeResetModal();
        }
    }
});