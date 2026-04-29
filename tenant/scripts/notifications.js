// notifications.js - Tenant Notification Management
// Mirrors the fees.js structure

let notifications = [];
let currentFilter = 'all'; // all, unread, read
let currentType = 'all'; // all, payment, maintenance, document, lease, profile, security
let currentPage = 1;
let totalPages = 1;

document.addEventListener('DOMContentLoaded', function() {
    initializeNotifications();
});

async function initializeNotifications() {
    if (window.currentUser) {
        await loadNotifications();
    } else {
        window.addEventListener('userDataLoaded', async function(e) {
            await loadNotifications();
        });
        
        setTimeout(async () => {
            if (!window.currentUser && !document.querySelector('.notifications-container')) {
                await loadNotifications();
            }
        }, 1000);
    }
}

async function loadNotifications() {
    try {
        let url = `../backend/tenant/fetch_notifications.php?page=${currentPage}&limit=10`;
        if (currentType !== 'all') url += `&type=${currentType}`;
        if (currentFilter === 'unread') url += `&is_read=false`;
        if (currentFilter === 'read') url += `&is_read=true`;
        
        const response = await fetch(url);
        const data = await response.json();
        
        console.log('Notifications response:', data);
        
        if (data.success) {
            notifications = data.data.notifications || [];
            renderNotificationsPage();
            updateStats(data.data.unread_count, data.data.pagination.total);
            renderPagination(data.data.pagination);
        } else {
            throw new Error(data.message || 'Failed to load notifications');
        }
    } catch (error) {
        console.error('Error loading notifications:', error);
        if (window.showToast) {
            window.showToast('Failed to load notifications', 'error');
        }
        showEmptyState();
    }
}

function renderNotificationsPage() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    // Calculate summary
    const totalUnread = notifications.filter(n => !n.is_read).length;
    const totalNotifications = notifications.length;
    
    // Filter notifications based on current tab and filter
    let filteredNotifications = [...notifications];
    
    if (currentType !== 'all') {
        filteredNotifications = filteredNotifications.filter(n => n.notification_type === currentType);
    }
    
    if (currentFilter === 'unread') {
        filteredNotifications = filteredNotifications.filter(n => !n.is_read);
    } else if (currentFilter === 'read') {
        filteredNotifications = filteredNotifications.filter(n => n.is_read);
    }
    const baseUrl = "/pages";
    
    const html = `
        <div class="notifications-container">
            <div class="page-header">
                <h1><i class="fas fa-bell"></i> Notifications</h1>
                <p>Stay updated with your account activities</p>
            </div>
            
            <div class="summary-cards">
                <div class="summary-card unread">
                    <h4>Unread</h4>
                    <div class="amount">${totalUnread}</div>
                    <div class="label">Notifications to read</div>
                </div>
                <div class="summary-card total">
                    <h4>Total</h4>
                    <div class="amount">${totalNotifications}</div>
                    <div class="label">All notifications</div>
                </div>
                <div class="summary-card">
                    <button class="btn-mark-all" onclick="markAllAsRead()">
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                </div>
            </div>
            
            <div class="notification-tabs">
                <button class="notification-tab ${currentType === 'all' ? 'active' : ''}" onclick="switchNotificationTab('all')">All</button>
                <button class="notification-tab ${currentType === 'payment' ? 'active' : ''}" onclick="switchNotificationTab('payment')">Payments</button>
                <button class="notification-tab ${currentType === 'maintenance' ? 'active' : ''}" onclick="switchNotificationTab('maintenance')">Maintenance</button>
                <button class="notification-tab ${currentType === 'document' ? 'active' : ''}" onclick="switchNotificationTab('document')">Documents</button>
                <button class="notification-tab ${currentType === 'lease' ? 'active' : ''}" onclick="switchNotificationTab('lease')">Lease</button>
                <button class="notification-tab ${currentType === 'security' ? 'active' : ''}" onclick="switchNotificationTab('security')">Security</button>
            </div>
            
            <div class="notification-filters">
                <div class="filter-group">
                    <label>Status</label>
                    <select class="filter-select" id="statusFilter" onchange="filterByStatus()">
                        <option value="all" ${currentFilter === 'all' ? 'selected' : ''}>All</option>
                        <option value="unread" ${currentFilter === 'unread' ? 'selected' : ''}>Unread</option>
                        <option value="read" ${currentFilter === 'read' ? 'selected' : ''}>Read</option>
                    </select>
                </div>
            </div>
            
            ${filteredNotifications.length === 0 ? `
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No Notifications Found</h3>
                    <p>You don't have any notifications at the moment.</p>
                </div>
            ` : `
                <div class="notifications-grid">
                    ${filteredNotifications.map(notification => `
                        <div class="notification-card ${!notification.is_read ? 'unread' : ''}" onclick="viewNotificationDetails(${notification.notification_id})">
                            <div class="notification-header">
                                <div class="notification-title">
                                    <span class="notification-icon ${notification.notification_type}">
                                        <i class="fas ${getNotificationIcon(notification.notification_type)}"></i>
                                    </span>
                                    ${escapeHtml(notification.title)}
                                    <span class="priority-badge priority-${notification.priority}">${notification.priority.toUpperCase()}</span>
                                </div>
                                <div class="notification-time">
                                    <i class="far fa-clock"></i> ${notification.time_ago || formatDate(notification.created_at)}
                                </div>
                            </div>
                            <div class="notification-message">
                                ${escapeHtml(notification.message)}
                            </div>
                            ${renderNotificationDetails(notification)}
                            <div class="notification-actions" onclick="event.stopPropagation()">
                                ${notification.action_url ? `
                                    <button class="btn-action btn-action-primary" onclick="window.location.href='baseUrl/${notification.action_url}'">
                                        <i class="fas fa-arrow-right"></i> ${notification.action_text || 'View Details'}
                                    </button>
                                ` : ''}
                                ${!notification.is_read ? `
                                    <button class="btn-action btn-action-secondary" onclick="markAsRead(${notification.notification_id})">
                                        <i class="fas fa-check"></i> Mark as Read
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            `}
        </div>
    `;
    
    contentArea.innerHTML = html;
}

function renderNotificationDetails(notification) {
    if (!notification.details) return '';
    
    let detailsHtml = '<div class="notification-details">';
    for (const [key, value] of Object.entries(notification.details)) {
        if (value) {
            detailsHtml += `
                <div class="detail-row">
                    <span class="detail-label">${formatDetailLabel(key)}:</span>
                    <span class="detail-value">${escapeHtml(String(value))}</span>
                </div>
            `;
        }
    }
    detailsHtml += '</div>';
    
    return detailsHtml;
}

function formatDetailLabel(key) {
    const labels = {
        'amount': 'Amount',
        'status': 'Status',
        'period_number': 'Period',
        'receipt_number': 'Receipt No.',
        'request_id': 'Request ID',
        'issue_type': 'Issue Type',
        'document_name': 'Document Name',
        'action': 'Action',
        'new_end_date': 'New End Date',
        'days_left': 'Days Left'
    };
    return labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function getNotificationIcon(type) {
    const icons = {
        'payment': 'fa-credit-card',
        'maintenance': 'fa-tools',
        'document': 'fa-file-alt',
        'lease': 'fa-file-signature',
        'profile': 'fa-user',
        'security': 'fa-shield-alt'
    };
    return icons[type] || 'fa-bell';
}

function showEmptyState() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    contentArea.innerHTML = `
        <div class="notifications-container">
            <div class="page-header">
                <h1><i class="fas fa-bell"></i> Notifications</h1>
                <p>Stay updated with your account activities</p>
            </div>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <h3>No Notifications Found</h3>
                <p>You don't have any notifications at the moment.</p>
            </div>
        </div>
    `;
}

function updateStats(unreadCount, totalCount) {
    // Stats are displayed directly in the summary cards
    // Update navbar badge
    const badge = document.getElementById('notificationBadge');
    if (badge && unreadCount > 0) {
        badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
        badge.style.display = 'flex';
    } else if (badge) {
        badge.style.display = 'none';
    }
}

function renderPagination(pagination) {
    const container = document.getElementById('pagination');
    if (!container) return;
    
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    currentPage = pagination.page;
    totalPages = pagination.total_pages;
    
    let html = '<div class="pagination">';
    
    // Previous button
    html += `<button class="page-btn ${pagination.has_previous_page ? '' : 'disabled'}" onclick="goToPage(${pagination.page - 1})" ${!pagination.has_previous_page ? 'disabled' : ''}>
        <i class="fas fa-chevron-left"></i>
    </button>`;
    
    // Page numbers
    for (let i = 1; i <= pagination.total_pages; i++) {
        if (i === 1 || i === pagination.total_pages || (i >= pagination.page - 2 && i <= pagination.page + 2)) {
            html += `<button class="page-btn ${i === pagination.page ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
        } else if (i === pagination.page - 3 || i === pagination.page + 3) {
            html += `<span class="page-dots">...</span>`;
        }
    }
    
    // Next button
    html += `<button class="page-btn ${pagination.has_next_page ? '' : 'disabled'}" onclick="goToPage(${pagination.page + 1})" ${!pagination.has_next_page ? 'disabled' : ''}>
        <i class="fas fa-chevron-right"></i>
    </button>`;
    
    html += '</div>';
    container.innerHTML = html;
}

function goToPage(page) {
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    loadNotifications();
}

function switchNotificationTab(tab) {
    currentType = tab;
    currentPage = 1;
    loadNotifications();
}

function filterByStatus() {
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        currentFilter = statusFilter.value;
        currentPage = 1;
        loadNotifications();
    }
}

async function viewNotificationDetails(notificationId) {
    const notification = notifications.find(n => n.notification_id === notificationId);
    if (!notification) return;
    
    const modalBody = document.getElementById('notificationDetailsBody');
    modalBody.innerHTML = `
        <div class="notification-detail-section">
            <div class="detail-row">
                <span class="detail-label">Title:</span>
                <span class="detail-value">${escapeHtml(notification.title)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Type:</span>
                <span class="detail-value">${notification.notification_type.toUpperCase()}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Priority:</span>
                <span class="detail-value">
                    <span class="priority-badge priority-${notification.priority}">${notification.priority.toUpperCase()}</span>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Date:</span>
                <span class="detail-value">${formatDateTime(notification.created_at)}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Message:</span>
                <span class="detail-value">${escapeHtml(notification.message)}</span>
            </div>
            ${renderNotificationDetails(notification)}
            ${!notification.is_read ? `
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">Unread</span>
                </div>
            ` : ''}
        </div>
    `;
    
    // Add styles for detail rows
    const style = document.createElement('style');
    style.textContent = `
        .notification-detail-section {
            margin-top: 10px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 500;
            color: #666;
        }
        .detail-value {
            color: #1a1f36;
            font-weight: 500;
        }
    `;
    document.head.appendChild(style);
    
    // If notification is unread, mark it as read when viewed
    if (!notification.is_read) {
        await markAsRead(notificationId);
    }
    
    openModal('notificationDetailsModal');
}

async function markAsRead(notificationId) {
    try {
        const response = await fetch('../backend/tenant/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: notificationId })
        });
        const data = await response.json();
        
        if (data.success) {
            // Update local data
            const notification = notifications.find(n => n.notification_id === notificationId);
            if (notification) {
                notification.is_read = true;
            }
            
            // Re-render the page
            await loadNotifications();
        }
    } catch (error) {
        console.error('Error marking notification as read:', error);
    }
}

async function markAllAsRead() {
    if (!confirm('Mark all notifications as read?')) return;
    
    try {
        const response = await fetch('../backend/tenant/mark_all_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await response.json();
        
        if (data.success) {
            // Update local data
            notifications.forEach(n => { n.is_read = true; });
            
            // Re-render the page
            await loadNotifications();
            
            if (window.showToast) {
                window.showToast('All notifications marked as read', 'success');
            }
        }
    } catch (error) {
        console.error('Error marking all as read:', error);
        if (window.showToast) {
            window.showToast('Failed to mark all as read', 'error');
        }
    }
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
}

function formatNumber(value) {
    if (!value || value === '0') return '0.00';
    return new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
        return dateString;
    }
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return dateString;
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}