class NotificationManager {
    constructor() {
        this.currentFilter = 'all';
        this.currentPage = 1;
        this.pageSize = 10;
        this.totalNotifications = 0;
        this.notifications = [];
        this.apiUrl = '../backend/staffs/notifications.php';
        this.initialize();
    }

    async initialize() {
        await this.loadNotifications();
        this.setupEventListeners();
    }

    async loadNotifications() {
        const container = document.getElementById('notificationsList');
        container.innerHTML = `
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading notifications...</p>
            </div>
        `;

        try {
            const params = new URLSearchParams({
                type: this.currentFilter,
                limit: this.pageSize,
                offset: (this.currentPage - 1) * this.pageSize
            });

            const response = await fetch(`${this.apiUrl}?${params}`);
            const data = await response.json();
            
            if (data.success) {
                this.notifications = data.notifications;
                this.totalNotifications = data.total;
                this.updateUI(data);
            } else {
                this.showError('Failed to load notifications');
                this.showEmptyState();
            }
        } catch (error) {
            console.error('Error loading notifications:', error);
            this.showError('Failed to load notifications');
            this.showEmptyState();
        }
    }

    updateUI(data) {
        // Update counts
        if (data.counts) {
            document.getElementById('totalUnread').textContent = data.counts.unread || 0;
            document.getElementById('totalNotifications').textContent = data.counts.total_unarchived || 0;
            document.getElementById('unreadCount').textContent = data.counts.unread || 0;
        }

        // Render notifications
        this.renderNotifications();
        
        // Render pagination
        this.renderPagination();
    }

    renderNotifications() {
        const container = document.getElementById('notificationsList');
        
        if (this.notifications.length === 0) {
            this.showEmptyState();
            return;
        }

        container.innerHTML = this.notifications.map(notification => `
            <div class="notification-item ${notification.is_read ? '' : 'unread'}" 
                 data-id="${notification.id}">
                ${!notification.is_read ? '<div class="notification-badge"></div>' : ''}
                
                <a href="${notification.link}" class="notification-link">
                    <div class="notification-icon">
                        ${notification.icon}
                    </div>
                    <div class="notification-content">
                        <div class="notification-header">
                            <div class="notification-title">${this.escapeHtml(notification.title)}</div>
                            <div class="notification-time">${notification.time_ago}</div>
                        </div>
                        <div class="notification-message">${this.escapeHtml(notification.message)}</div>
                        <div class="notification-category">${notification.category}</div>
                    </div>
                </a>
                
                <div class="notification-actions">
                    ${!notification.is_read ? `
                        <button class="action-btn-small mark-read" 
                                onclick="notificationManager.markAsRead(${notification.id}, event)">
                            <i class="fas fa-check"></i>
                        </button>
                    ` : ''}
                    <button class="action-btn-small archive" 
                            onclick="notificationManager.archiveNotification(${notification.id}, event)">
                        <i class="fas fa-archive"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }

    showEmptyState() {
        const container = document.getElementById('notificationsList');
        const filterName = this.getFilterName(this.currentFilter);
        
        container.innerHTML = `
            <div class="empty-state">
                <i class="far fa-bell-slash"></i>
                <h3>No notifications found</h3>
                <p>You're all caught up! No ${filterName} notifications to display.</p>
            </div>
        `;
    }

    getFilterName(filter) {
        const names = {
            'all': '',
            'unread': 'unread',
            'account_reactivation': 'account reactivation',
            'payment': 'payment',
            'account_lock': 'account lock',
            'archived': 'archived'
        };
        return names[filter] || filter;
    }

    renderPagination() {
        const container = document.getElementById('pagination');
        const totalPages = Math.ceil(this.totalNotifications / this.pageSize);
        
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        let buttons = '';
        
        // Previous button
        if (this.currentPage > 1) {
            buttons += `
                <button class="page-btn" onclick="notificationManager.changePage(${this.currentPage - 1})">
                    <i class="fas fa-chevron-left"></i>
                </button>
            `;
        }

        // Page buttons (show max 5 pages)
        const maxVisible = 5;
        let startPage = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);
        
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        for (let i = startPage; i <= endPage; i++) {
            buttons += `
                <button class="page-btn ${i === this.currentPage ? 'active' : ''}" 
                        onclick="notificationManager.changePage(${i})">
                    ${i}
                </button>
            `;
        }

        // Next button
        if (this.currentPage < totalPages) {
            buttons += `
                <button class="page-btn" onclick="notificationManager.changePage(${this.currentPage + 1})">
                    <i class="fas fa-chevron-right"></i>
                </button>
            `;
        }

        container.innerHTML = buttons;
    }

    setupEventListeners() {
        // Filter buttons
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                this.currentFilter = e.target.dataset.filter;
                this.currentPage = 1;
                this.loadNotifications();
            });
        });

        // Mark all read button
        document.getElementById('markAllReadBtn').addEventListener('click', () => {
            this.markAllAsRead();
        });
    }

    changePage(page) {
        this.currentPage = page;
        this.loadNotifications();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async markAsRead(notificationId, event) {
        if (event) event.stopPropagation();
        
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'mark_as_read',
                    notification_id: notificationId
                })
            });

            const data = await response.json();
            if (data.success) {
                this.loadNotifications();
            } else {
                this.showError(data.message || 'Failed to mark as read');
            }
        } catch (error) {
            console.error('Error marking as read:', error);
            this.showError('Failed to mark as read');
        }
    }

    async markAllAsRead() {
        if (!confirm('Mark all notifications as read?')) return;
        
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'mark_all_read'
                })
            });

            const data = await response.json();
            if (data.success) {
                this.showSuccess(data.message);
                this.loadNotifications();
            } else {
                this.showError('Failed to mark all as read');
            }
        } catch (error) {
            console.error('Error marking all as read:', error);
            this.showError('Failed to mark all as read');
        }
    }

    async archiveNotification(notificationId, event) {
        if (event) event.stopPropagation();
        
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'archive',
                    notification_id: notificationId
                })
            });

            const data = await response.json();
            if (data.success) {
                this.loadNotifications();
            } else {
                this.showError(data.message || 'Failed to archive');
            }
        } catch (error) {
            console.error('Error archiving notification:', error);
            this.showError('Failed to archive');
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    showError(message) {
        // You can replace this with a toast notification
        console.error('Error:', message);
        alert(message);
    }

    showSuccess(message) {
        // You can replace this with a toast notification
        console.log('Success:', message);
        alert(message);
    }
}

// Initialize the notification manager
const notificationManager = new NotificationManager();