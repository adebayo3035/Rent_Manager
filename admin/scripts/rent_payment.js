let currentPage = 1;
        let currentTab = 'pending';
        let currentTrackerId = null;
        let currentPeriodNumber = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadStatistics();
            loadPendingVerifications();
        });

        // Switch tabs
        function switchTab(tab) {
            currentTab = tab;
            currentPage = 1;
            
            // Update tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Show/hide content
            document.getElementById('pendingTab').style.display = tab === 'pending' ? 'block' : 'none';
            document.getElementById('historyTab').style.display = tab === 'history' ? 'block' : 'none';
            
            // Load data
            if (tab === 'pending') {
                loadPendingVerifications();
            } else {
                loadPaymentHistory();
            }
        }

        // Load statistics
        async function loadStatistics() {
            try {
                const response = await fetch('../backend/payment/rent_payment_admin.php?action=get_statistics');
                const data = await response.json();
                
                if (data.success) {
                    renderStatistics(data.statistics);
                }
            } catch (error) {
                console.error('Error loading statistics:', error);
            }
        }

        function renderStatistics(stats) {
            const container = document.getElementById('statsContainer');
            const summary = stats.summary || {};
            
            container.innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f59e0b;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending Verifications</h3>
                        <p class="stat-number">${summary.pending_verifications || 0}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #10b981;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Collected</h3>
                        <p class="stat-number">₦${formatNumber(summary.total_collected || 0)}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ef4444;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Outstanding Balance</h3>
                        <p class="stat-number">₦${formatNumber(summary.total_outstanding || 0)}</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #3b82f6;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Active Leases</h3>
                        <p class="stat-number">${summary.active_leases || 0}</p>
                    </div>
                </div>
            `;
        }

        // Load pending verifications
        async function loadPendingVerifications() {
            try {
                const response = await fetch('../backend/payment/rent_payment_admin.php?action=fetch_pending');
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('pendingCount').innerHTML = `${data.pending_count} pending`;
                    renderPendingTable(data.payments);
                }
            } catch (error) {
                console.error('Error loading pending:', error);
                document.getElementById('pendingTableBody').innerHTML = `
                    <tr><td colspan="8" class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>Error loading pending verifications</p>
                    </td></tr>
                `;
            }
        }

        function renderPendingTable(payments) {
            const tbody = document.getElementById('pendingTableBody');
            
            if (!payments || payments.length === 0) {
                tbody.innerHTML = `
                    <tr><td colspan="8" class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No pending verifications</p>
                        <small>All payments have been processed</small>
                    </td></tr>
                `;
                return;
            }
            
            tbody.innerHTML = payments.map(payment => `
                <tr>
                    <td>${payment.created_at_formatted}</td>
                    <td>
                        <strong>${escapeHtml(payment.tenant_name)}</strong><br>
                        <small>${escapeHtml(payment.tenant_code)}</small>
                    </td>
                    <td>
                        ${escapeHtml(payment.property_name)}<br>
                        <small>${escapeHtml(payment.apartment_number)}</small>
                    </td>
                    <td>
                        Period #${payment.period_number}<br>
                        <small>${payment.period_display}</small>
                    </td>
                    <td><strong>${payment.amount_formatted}</strong></td>
                    <td>${formatPaymentMethod(payment.payment_method)}</td>
                    <td><code>${escapeHtml(payment.payment_reference || 'N/A')}</code></td>
                    <td>
                        <button class="btn btn-primary" onclick="openVerifyModal(${payment.tracker_id}, ${payment.period_number})">
                            <i class="fas fa-check-circle"></i> Verify
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Load payment history
        async function loadPaymentHistory() {
            try {
                const search = document.getElementById('searchInput')?.value || '';
                const status = document.getElementById('statusFilter')?.value || '';
                
                let url = `../backend/payment/rent_payment_admin.php?action=fetch_history&page=${currentPage}&limit=20`;
                if (search) url += `&search=${encodeURIComponent(search)}`;
                if (status) url += `&status=${status}`;
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    renderHistoryTable(data.payments);
                    renderHistoryPagination(data.pagination);
                }
            } catch (error) {
                console.error('Error loading history:', error);
            }
        }

        function renderHistoryTable(payments) {
            const tbody = document.getElementById('historyTableBody');
            
            if (!payments || payments.length === 0) {
                tbody.innerHTML = `
                    <tr><td colspan="9" class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <p>No payment history found</p>
                    </td></tr>
                `;
                return;
            }
            
            tbody.innerHTML = payments.map(payment => `
                <tr>
                    <td>${payment.payment_date_formatted}</td>
                    <td>
                        <strong>${escapeHtml(payment.tenant_name)}</strong><br>
                        <small>${escapeHtml(payment.tenant_code)}</small>
                    </td>
                    <td>${escapeHtml(payment.property_name)}</td>
                    <td>#${payment.period_number}</td>
                    <td><small>${payment.period_display}</small></td>
                    <td>${payment.amount_formatted}</td>
                    <td>${formatPaymentMethod(payment.payment_method)}</td>
                    <td><span class="badge badge-${payment.status_badge}">${payment.status_text}</span></td>
                    <td><small>${payment.verified_at_formatted}</small></td>
                </tr>
            `).join('');
        }

        function renderHistoryPagination(pagination) {
            const container = document.getElementById('historyPagination');
            if (!pagination || pagination.total_pages <= 1) {
                container.innerHTML = '';
                return;
            }
            
            let html = '';
            for (let i = 1; i <= pagination.total_pages; i++) {
                html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
            }
            container.innerHTML = html;
        }

        function goToPage(page) {
            currentPage = page;
            loadPaymentHistory();
        }

        function applyFilters() {
            currentPage = 1;
            loadPaymentHistory();
        }

        // Modal functions
        function openVerifyModal(trackerId, periodNumber) {
            currentTrackerId = trackerId;
            currentPeriodNumber = periodNumber;
            
            const modal = document.getElementById('verifyModal');
            const detailsDiv = document.getElementById('verifyDetails');
            
            detailsDiv.innerHTML = `
                <div class="detail-row">
                    <span class="detail-label">Period #:</span>
                    <span class="detail-value">${periodNumber}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Action:</span>
                    <span class="detail-value">Please verify this payment</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status will be:</span>
                    <span class="detail-value"><strong>Approve</strong> = Paid | <strong>Reject</strong> = Failed</span>
                </div>
            `;
            
            document.getElementById('verifyNotes').value = '';
            modal.classList.add('active');
        }

        function closeVerifyModal() {
            document.getElementById('verifyModal').classList.remove('active');
            currentTrackerId = null;
        }

        function showConfirmModal({
            title = 'Confirm Action',
            message = 'Are you sure you want to continue?',
            confirmText = 'Confirm',
            cancelText = 'Cancel',
            variant = 'warning'
        }) {
            return new Promise((resolve) => {
                let modal = document.getElementById('customConfirmModal');

                if (!modal) {
                    modal = document.createElement('div');
                    modal.id = 'customConfirmModal';
                    modal.className = 'custom-confirm-overlay';
                    modal.innerHTML = `
                        <div class="custom-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="customConfirmTitle">
                            <div class="custom-confirm-icon">
                                <i class="fas fa-circle-exclamation"></i>
                            </div>
                            <div class="custom-confirm-content">
                                <h3 id="customConfirmTitle"></h3>
                                <p id="customConfirmMessage"></p>
                            </div>
                            <div class="custom-confirm-actions">
                                <button type="button" class="btn btn-outline custom-confirm-cancel">Cancel</button>
                                <button type="button" class="btn custom-confirm-ok">Confirm</button>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modal);
                }

                const dialog = modal.querySelector('.custom-confirm-dialog');
                const icon = modal.querySelector('.custom-confirm-icon i');
                const titleElement = modal.querySelector('#customConfirmTitle');
                const messageElement = modal.querySelector('#customConfirmMessage');
                const confirmButton = modal.querySelector('.custom-confirm-ok');
                const cancelButton = modal.querySelector('.custom-confirm-cancel');

                const iconMap = {
                    warning: 'fa-circle-exclamation',
                    success: 'fa-circle-check',
                    info: 'fa-circle-info',
                    danger: 'fa-triangle-exclamation'
                };

                dialog.dataset.variant = variant;
                icon.className = `fas ${iconMap[variant] || iconMap.warning}`;
                titleElement.textContent = title;
                messageElement.textContent = message;
                confirmButton.textContent = confirmText;
                cancelButton.textContent = cancelText;

                const handleKeydown = (event) => {
                    if (event.key === 'Escape') {
                        cleanup(false);
                    }
                };

                const cleanup = (result) => {
                    modal.classList.remove('active');
                    confirmButton.onclick = null;
                    cancelButton.onclick = null;
                    modal.onclick = null;
                    document.removeEventListener('keydown', handleKeydown);
                    resolve(result);
                };

                confirmButton.onclick = () => cleanup(true);
                cancelButton.onclick = () => cleanup(false);
                modal.onclick = (event) => {
                    if (event.target === modal) {
                        cleanup(false);
                    }
                };

                modal.classList.add('active');
                document.addEventListener('keydown', handleKeydown);
                confirmButton.focus();
            });
        }

        async function processVerification(action) {
            if (!currentTrackerId) {
                alert('No payment selected');
                return;
            }
            
            const notes = document.getElementById('verifyNotes').value;
            const actionText = action === 'approve' ? 'approve' : 'reject';
            
            const confirmed = await showConfirmModal({
                title: `${action === 'approve' ? 'Approve' : 'Reject'} Payment?`,
                message: `Are you sure you want to ${actionText} this payment? This action cannot be undone.`,
                confirmText: action === 'approve' ? 'Yes, Approve' : 'Yes, Reject',
                cancelText: 'Cancel',
                variant: action === 'approve' ? 'success' : 'danger'
            });

            if (!confirmed) {
                return;
            }
            
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;
            
            try {
                const response = await fetch('../backend/payment/rent_payment_admin.php?action=verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tracker_id: currentTrackerId,
                        action: action,
                        notes: notes
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    closeVerifyModal();
                    loadStatistics();
                    loadPendingVerifications();
                    if (currentTab === 'history') loadPaymentHistory();
                } else {
                    alert(data.message || 'Error processing verification');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        // Utility functions
        function formatNumber(value) {
            return new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
        }

        function formatPaymentMethod(method) {
            const methods = {
                'bank_transfer': 'Bank Transfer',
                'card': 'Card',
                'cash': 'Cash',
                'cheque': 'Cheque'
            };
            return methods[method] || method || 'N/A';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
