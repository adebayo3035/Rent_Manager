// settlement.js - Client Settlement Management

// ==================== STATE ====================
let currentTab = 'pending';
let selectedRequestId = null;
let selectedAction = null;
let pendingRequestsCache = [];

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', function() {
    loadPendingRequests();
    setInterval(loadPendingRequests, 60000); // Auto-refresh every 60 seconds
});

// ==================== TAB SWITCHING ====================
function switchTab(tab) {
    currentTab = tab;
    
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        const isActive = btn.dataset.tab === tab;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    if (tab === 'pending') {
        document.getElementById('pendingSection').classList.add('active');
        loadPendingRequests();
    } else {
        document.getElementById('historySection').classList.add('active');
        loadHistory();
    }
}

// ==================== LOAD PENDING REQUESTS ====================
async function loadPendingRequests() {
    const container = document.getElementById('pendingRequests');
    container.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading pending requests...</p>
        </div>
    `;

    try {
        const response = await fetch('../backend/client/settlement_api.php?action=get_pending', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (response.ok && data.success && Array.isArray(data.data)) {
            const requests = data.data;
            pendingRequestsCache = requests;
            updatePendingCount(requests.length);
            
            if (requests.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No pending settlement changes.</p>
                        <p style="font-size: 13px; color: #94a3b8;">All settlement formulas are up to date.</p>
                    </div>
                `;
            } else {
                renderPendingRequests(requests);
            }
        } else {
            const message = data.message || 'Failed to load pending requests';
            showToast(message, 'error');
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                    <p>${escapeHtml(message)}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading pending requests:', error);
        showToast('Network error. Please try again.', 'error');
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                <p>Failed to load pending requests. Please refresh the page.</p>
            </div>
        `;
    }
}

function updatePendingCount(count) {
    const badge = document.getElementById('pendingCount');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline' : 'none';
    }
}

function renderPendingRequests(requests) {
    const container = document.getElementById('pendingRequests');
    let html = '';

    requests.forEach(request => {
        const adminDiff = toNumber(request.admin_diff);
        const agentDiff = toNumber(request.agent_diff);
        const clientDiff = toNumber(request.client_diff);
        
        const adminDiffClass = adminDiff > 0 ? 'increase' : (adminDiff < 0 ? 'decrease' : '');
        const agentDiffClass = agentDiff > 0 ? 'increase' : (agentDiff < 0 ? 'decrease' : '');
        const clientDiffClass = clientDiff > 0 ? 'increase' : (clientDiff < 0 ? 'decrease' : '');
        
        const adminDiffText = adminDiff > 0 ? `+${adminDiff.toFixed(2)}%` : (adminDiff < 0 ? `${adminDiff.toFixed(2)}%` : '');
        const agentDiffText = agentDiff > 0 ? `+${agentDiff.toFixed(2)}%` : (agentDiff < 0 ? `${agentDiff.toFixed(2)}%` : '');
        const clientDiffText = clientDiff > 0 ? `+${clientDiff.toFixed(2)}%` : (clientDiff < 0 ? `${clientDiff.toFixed(2)}%` : '');

        html += `
            <div class="settlement-card" data-request-id="${escapeAttribute(request.request_id)}">
                <div class="settlement-card-header">
                    <h4>
                        ${escapeHtml(request.property_code)}
                        <span class="property-code">- ${escapeHtml(request.property_name)}</span>
                    </h4>
                    <span class="badge badge-pending">Pending Approval</span>
                </div>
                
                <div class="settlement-comparison">
                    <div class="current-values">
                        <strong>Current Formula</strong>
                        <ul>
                            <li>Admin: ${formatPercent(request.current_admin_percentage)}</li>
                            <li>Agent: ${formatPercent(request.current_agent_percentage)}</li>
                            <li>Client: ${formatPercent(request.current_client_percentage)}</li>
                        </ul>
                    </div>
                    <div class="arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="proposed-values">
                        <strong>Proposed Formula</strong>
                        <ul>
                            <li>Admin: ${formatPercent(request.proposed_admin_percentage)} <span class="${adminDiffClass}">${adminDiffText}</span></li>
                            <li>Agent: ${formatPercent(request.proposed_agent_percentage)} <span class="${agentDiffClass}">${agentDiffText}</span></li>
                            <li>Client: ${formatPercent(request.proposed_client_percentage)} <span class="${clientDiffClass}">${clientDiffText}</span></li>
                        </ul>
                    </div>
                </div>
                
                <div class="settlement-meta">
                    <span><i class="far fa-user"></i> Proposed by: ${escapeHtml(request.proposed_by_fullname || 'Unknown')}</span>
                    <span><i class="far fa-clock"></i> ${request.proposed_at_formatted}</span>
                    ${request.agent_code ? `<span><i class="fas fa-user-tie"></i> Agent: ${escapeHtml(request.agent_code)}</span>` : ''}
                </div>
                
                ${request.notes ? `
                    <div class="settlement-notes">
                        <strong>Note:</strong> ${escapeHtml(request.notes)}
                    </div>
                ` : ''}
                
                <div class="settlement-actions">
                    <button class="btn btn-success" onclick="openActionModal(${request.request_id}, 'approve')">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="btn btn-danger" onclick="openActionModal(${request.request_id}, 'decline')">
                        <i class="fas fa-times"></i> Decline
                    </button>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

// ==================== LOAD HISTORY ====================
async function loadHistory() {
    const container = document.getElementById('historyRequests');
    container.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Loading history...</p>
        </div>
    `;

    try {
        const response = await fetch('../backend/client/settlement_api.php?action=get_history', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (response.ok && data.success && Array.isArray(data.data)) {
            const history = data.data;
            
            if (history.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No settlement history available.</p>
                    </div>
                `;
            } else {
                renderHistory(history);
            }
        } else {
            const message = data.message || 'Failed to load history';
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                    <p>${escapeHtml(message)}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading history:', error);
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                <p>Failed to load history. Please refresh the page.</p>
            </div>
        `;
    }
}

function renderHistory(history) {
    const container = document.getElementById('historyRequests');
    let html = '';

    history.forEach(item => {
        html += `
            <div class="settlement-card">
                <div class="settlement-card-header">
                    <h4>
                        ${escapeHtml(item.property_code)}
                        <span class="property-code">- ${escapeHtml(item.property_name)}</span>
                    </h4>
                    <span class="badge badge-${item.status_class}">${item.status_label}</span>
                </div>
                
                <div class="settlement-comparison">
                    <div class="current-values">
                        <strong>Previous Formula</strong>
                        <ul>
                            <li>Admin: ${formatPercent(item.current_admin_percentage)}</li>
                            <li>Agent: ${formatPercent(item.current_agent_percentage)}</li>
                            <li>Client: ${formatPercent(item.current_client_percentage)}</li>
                        </ul>
                    </div>
                    <div class="arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="proposed-values">
                        <strong>${item.status_label} Formula</strong>
                        <ul>
                            <li>Admin: ${formatPercent(item.proposed_admin_percentage)}</li>
                            <li>Agent: ${formatPercent(item.proposed_agent_percentage)}</li>
                            <li>Client: ${formatPercent(item.proposed_client_percentage)}</li>
                        </ul>
                    </div>
                </div>
                
                <div class="settlement-meta">
                    <span><i class="far fa-user"></i> Proposed by: ${escapeHtml(item.proposed_by_fullname || 'Unknown')}</span>
                    <span><i class="far fa-clock"></i> ${item.proposed_at_formatted}</span>
                    ${item.approved_by_fullname ? `<span><i class="fas fa-check-circle"></i> ${item.status_label} by: ${escapeHtml(item.approved_by_fullname)}</span>` : ''}
                    ${item.approved_at_formatted ? `<span><i class="far fa-calendar-check"></i> ${item.approved_at_formatted}</span>` : ''}
                </div>
                
                ${item.rejection_reason ? `
                    <div class="settlement-notes" style="border-left-color: #ef4444; background: #fef2f2;">
                        <strong>Reason:</strong> ${escapeHtml(item.rejection_reason)}
                    </div>
                ` : ''}
                
                ${item.notes ? `
                    <div class="settlement-notes" style="margin-top: 8px;">
                        <strong>Note:</strong> ${escapeHtml(item.notes)}
                    </div>
                ` : ''}
            </div>
        `;
    });

    container.innerHTML = html;
}

// ==================== ACTION MODAL ====================
function openActionModal(requestId, action) {
    selectedRequestId = requestId;
    selectedAction = action;
    
    const modal = document.getElementById('actionModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const approveBtn = document.getElementById('approveBtn');
    const declineBtn = document.getElementById('declineBtn');
    const declineReasonContainer = document.getElementById('declineReasonContainer');
    const declineReason = document.getElementById('declineReason');

    // Find the request from the DOM
    const card = document.querySelector(`.settlement-card[data-request-id="${escapeCssIdentifier(requestId)}"]`);
    if (!card) {
        showToast('Request not found', 'error');
        return;
    }

    const propertyCode = card.querySelector('.settlement-card-header h4')?.textContent?.trim() || 'Property';
    
    if (action === 'approve') {
        modalTitle.textContent = `Approve Settlement Change - ${propertyCode}`;
        approveBtn.style.display = 'inline-flex';
        declineBtn.style.display = 'none';
        declineReasonContainer.style.display = 'none';
        
        modalContent.innerHTML = `
            <p style="margin-bottom: 12px;">Are you sure you want to approve this settlement change?</p>
            <p style="color: #64748b; font-size: 14px;">The new formula will take effect immediately.</p>
        `;
    } else {
        modalTitle.textContent = `Decline Settlement Change - ${propertyCode}`;
        approveBtn.style.display = 'none';
        declineBtn.style.display = 'inline-flex';
        declineReasonContainer.style.display = 'block';
        declineReason.value = '';
        
        modalContent.innerHTML = `
            <p style="margin-bottom: 12px;">Are you sure you want to decline this settlement change?</p>
            <p style="color: #64748b; font-size: 14px;">Please provide a reason for declining.</p>
        `;
    }
    
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeActionModal() {
    const modal = document.getElementById('actionModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    selectedRequestId = null;
    selectedAction = null;
}

// ==================== CONFIRM APPROVE ====================
async function confirmApprove() {
    if (!selectedRequestId) return;

    const approveBtn = document.getElementById('approveBtn');
    const originalText = approveBtn.innerHTML;
    approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    approveBtn.disabled = true;

    try {
        const response = await fetch('../backend/client/settlement_api.php?action=approve', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                request_id: selectedRequestId
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Settlement change approved successfully!', 'success');
            closeActionModal();
            loadPendingRequests(); // Refresh list
        } else {
            showToast(data.message || 'Failed to approve', 'error');
        }
    } catch (error) {
        console.error('Error approving:', error);
        showToast('Network error. Please try again.', 'error');
    } finally {
        approveBtn.innerHTML = originalText;
        approveBtn.disabled = false;
    }
}

// ==================== CONFIRM DECLINE ====================
async function confirmDecline() {
    if (!selectedRequestId) return;

    const reason = document.getElementById('declineReason').value.trim();
    if (!reason) {
        showToast('Please provide a reason for declining', 'warning');
        document.getElementById('declineReason').focus();
        return;
    }

    const declineBtn = document.getElementById('declineBtn');
    const originalText = declineBtn.innerHTML;
    declineBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    declineBtn.disabled = true;

    try {
        const response = await fetch('../backend/client/settlement_api.php?action=decline', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({
                request_id: selectedRequestId,
                reason: reason
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Settlement change declined.', 'info');
            closeActionModal();
            loadPendingRequests(); // Refresh list
        } else {
            showToast(data.message || 'Failed to decline', 'error');
        }
    } catch (error) {
        console.error('Error declining:', error);
        showToast('Network error. Please try again.', 'error');
    } finally {
        declineBtn.innerHTML = originalText;
        declineBtn.disabled = false;
    }
}

// ==================== CLOSE MODAL ON OUTSIDE CLICK ====================
document.addEventListener('click', function(e) {
    const modal = document.getElementById('actionModal');
    if (e.target === modal) {
        closeActionModal();
    }
});

// ==================== CLOSE MODAL ON ESCAPE KEY ====================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('actionModal').style.display === 'flex') {
            closeActionModal();
        }
    }
});

// ==================== UTILITY FUNCTIONS ====================
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(text).replace(/"/g, '&quot;');
}

function toNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
}

function formatPercent(value) {
    return `${toNumber(value).toFixed(2)}%`;
}

function escapeCssIdentifier(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

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
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => {
            if (toast && toast.remove) {
                toast.remove();
            }
        }, 300);
    }, 5000);
}
