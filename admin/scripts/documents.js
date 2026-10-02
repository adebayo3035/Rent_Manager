// admin/scripts/documents.js

const Documents = {
    currentPage: 1,
    currentSector: '',
    currentType: '',
    currentTenant: '',
    currentSearch: '',
    currentDateFrom: '',
    currentDateTo: '',
    sectors: [],
    currentDocument: null,
    pendingDeleteId: null,

    // ==================== INIT ====================
    init: function () {
        this.loadTenants();
        this.loadDocuments();
        this.bindFilters();
    },

    refresh: function () {
        this.loadDocuments();
    },

    // ==================== LOAD TENANTS ====================
    loadTenants: async function () {
        try {
            const res = await fetch('../backend/documents/fetch_tenants_for_filter.php', {
                credentials: 'include'
            });
            const data = await res.json();
            if (!data.success) return;

            const select = document.getElementById('filterTenant');
            data.message.tenants.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.tenant_code;
                opt.textContent = `${t.display} (${t.tenant_code})`;
                select.appendChild(opt);
            });
        } catch (err) {
            console.error('Error loading tenants:', err);
        }
    },

    // ==================== LOAD DOCUMENTS ====================
    loadDocuments: async function () {
        const tbody = document.getElementById('documentsTableBody');
        tbody.innerHTML = '<tr><td colspan="8" class="loading-cell"><div class="spinner"></div></td></tr>';

        const params = new URLSearchParams();
        params.append('page', this.currentPage);
        params.append('limit', 20);
        if (this.currentSector)   params.append('sector', this.currentSector);
        if (this.currentType)     params.append('document_type', this.currentType);
        if (this.currentTenant)   params.append('tenant_code', this.currentTenant);
        if (this.currentSearch)   params.append('search', this.currentSearch);
        if (this.currentDateFrom) params.append('date_from', this.currentDateFrom);
        if (this.currentDateTo)   params.append('date_to', this.currentDateTo);

        try {
            const res = await fetch(`../backend/documents/fetch_documents.php?${params}`, {
                credentials: 'include'
            });
            const data = await res.json();

            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="8" class="empty-cell">${this.escapeHtml(data.message || 'Failed to load documents')}</td></tr>`;
                return;
            }

            // Cache sectors for later
            this.sectors = data.message.sectors || [];

            this.renderSectorsTabs();
            this.renderDocuments(data.message.documents || []);
            this.renderPagination(data.message.pagination || {});
            this.updateSummary(data.message.documents || []);
            this.populateTypeFilter();

        } catch (err) {
            console.error('Error loading documents:', err);
            tbody.innerHTML = '<tr><td colspan="8" class="empty-cell">Network error. Please try again.</td></tr>';
        }
    },

    // ==================== RENDER SECTOR TABS ====================
    renderSectorsTabs: function () {
        const container = document.getElementById('sectorTabs');
        let html = `
            <button class="sector-tab ${this.currentSector === '' ? 'active' : ''}" onclick="Documents.switchSector('')">
                <i class="fas fa-th-large"></i> All Documents
            </button>`;

        this.sectors.forEach(s => {
            html += `
                <button class="sector-tab ${this.currentSector === s.key ? 'active' : ''}" 
                        onclick="Documents.switchSector('${s.key}')"
                        style="--sector-color: ${s.color}; --sector-bg: ${s.bg_color};">
                    <i class="fas ${s.icon}"></i> ${this.escapeHtml(s.label)}
                </button>`;
        });

        container.innerHTML = html;
    },

    // ==================== POPULATE TYPE FILTER ====================
    populateTypeFilter: function () {
        const select = document.getElementById('filterType');
        const current = select.value;
        select.innerHTML = '<option value="">All Types</option>';

        // If a sector is selected, only show its types; otherwise show all
        const relevantSectors = this.currentSector
            ? this.sectors.filter(s => s.key === this.currentSector)
            : this.sectors;

        relevantSectors.forEach(s => {
            s.types.forEach(type => {
                const opt = document.createElement('option');
                opt.value = type;
                opt.textContent = this.formatTypeLabel(type);
                select.appendChild(opt);
            });
        });

        select.value = current;
    },

    formatTypeLabel: function (type) {
        return type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    },

    // ==================== RENDER DOCUMENTS ====================
    renderDocuments: function (docs) {
        const tbody = document.getElementById('documentsTableBody');
        if (!docs || docs.length === 0) {
            tbody.innerHTML = `
                <tr><td colspan="8" class="empty-cell">
                    <div class="empty-state-inline">
                        <i class="fas fa-folder-open"></i>
                        <h4>No documents found</h4>
                        <p>Try adjusting your filters or search terms.</p>
                    </div>
                </td></tr>`;
            return;
        }

        tbody.innerHTML = docs.map(d => `
            <tr>
                <td>
                    <div class="doc-cell">
                        <div class="doc-icon" style="background:${d.sector_bg_color}; color:${d.sector_color};">
                            <i class="fas ${d.document_type_icon}"></i>
                        </div>
                        <div class="doc-info">
                            <div class="doc-name">${this.escapeHtml(d.document_name)}</div>
                            <div class="doc-file">${this.escapeHtml(d.original_file_name)}</div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="sector-badge" style="background:${d.sector_bg_color}; color:${d.sector_color};">
                        <i class="fas ${d.sector_icon}"></i> ${this.escapeHtml(d.sector_label)}
                    </span>
                </td>
                <td>
                    <div class="tenant-cell">
                        <strong>${this.escapeHtml(d.tenant_name)}</strong>
                        <small>${this.escapeHtml(d.tenant_code)}</small>
                    </div>
                </td>
                <td>
                    <div class="property-cell">
                        <div>${this.escapeHtml(d.property_name)}</div>
                        <small>Apt ${this.escapeHtml(String(d.apartment_number))}</small>
                    </div>
                </td>
                <td>
                    <div class="uploader-cell">
                        <div>${this.escapeHtml(d.uploader_name)}</div>
                        <small>${this.escapeHtml(d.uploaded_by_label)}</small>
                    </div>
                </td>
                <td>${this.escapeHtml(d.file_size_formatted)}</td>
                <td class="date-cell">${this.escapeHtml(d.uploaded_at_formatted)}</td>
                <td style="text-align: right;">
                    <div class="action-buttons">
                        <button class="icon-btn" title="View Details" onclick="Documents.viewDetails(${d.document_id})">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="icon-btn" title="Download" onclick="Documents.download(${d.document_id})">
                            <i class="fas fa-download"></i>
                        </button>
                        ${d.document_type !== 'INVOICE' ? `
                            <button class="icon-btn danger" title="Delete" onclick="Documents.openDelete(${d.document_id}, '${this.escapeAttr(d.document_name)}')">
                                <i class="fas fa-trash"></i>
                            </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `).join('');
    },

    // ==================== SUMMARY ====================
    updateSummary: function (docs) {
        // These are per-page counts; for real totals you'd need a stats endpoint.
        // We'll show page-scoped counts as a lightweight summary.
        const total    = docs.length;
        const invoices = docs.filter(d => d.document_type === 'INVOICE').length;
        const leases   = docs.filter(d => d.document_type === 'LEASE_AGREEMENT').length;
        const ids      = docs.filter(d => d.document_type === 'IDENTIFICATION').length;

        const sevenDaysAgo = new Date();
        sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
        const recent = docs.filter(d => new Date(d.uploaded_at) >= sevenDaysAgo).length;

        document.getElementById('summaryTotal').textContent = total;
        document.getElementById('summaryInvoices').textContent = invoices;
        document.getElementById('summaryLeases').textContent = leases;
        document.getElementById('summaryIds').textContent = ids;
        document.getElementById('summaryRecent').textContent = recent;
    },

    // ==================== PAGINATION ====================
    renderPagination: function (p) {
        const container = document.getElementById('documentsPagination');
        if (!p || p.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '';
        if (p.page > 1) {
            html += `<button class="page-btn" onclick="Documents.goToPage(${p.page - 1})">‹ Prev</button>`;
        }
        for (let i = 1; i <= p.total_pages; i++) {
            if (i === p.page) {
                html += `<button class="page-btn active">${i}</button>`;
            } else if (i <= 2 || i > p.total_pages - 2 || Math.abs(i - p.page) <= 1) {
                html += `<button class="page-btn" onclick="Documents.goToPage(${i})">${i}</button>`;
            } else if (i === 3 && p.page > 4) {
                html += `<span class="page-dots">...</span>`;
            } else if (i === p.total_pages - 2 && p.page < p.total_pages - 3) {
                html += `<span class="page-dots">...</span>`;
            }
        }
        if (p.page < p.total_pages) {
            html += `<button class="page-btn" onclick="Documents.goToPage(${p.page + 1})">Next ›</button>`;
        }
        container.innerHTML = html;
    },

    goToPage: function (n) {
        this.currentPage = n;
        this.loadDocuments();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    // ==================== FILTERS ====================
    bindFilters: function () {
        let timer;
        document.getElementById('filterSearch').addEventListener('input', (e) => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                this.currentSearch = e.target.value.trim();
                this.currentPage = 1;
                this.loadDocuments();
            }, 400);
        });

        document.getElementById('filterType').addEventListener('change', (e) => {
            this.currentType = e.target.value;
            this.currentPage = 1;
            this.loadDocuments();
        });

        document.getElementById('filterTenant').addEventListener('change', (e) => {
            this.currentTenant = e.target.value;
            this.currentPage = 1;
            this.loadDocuments();
        });

        document.getElementById('filterDateFrom').addEventListener('change', (e) => {
            this.currentDateFrom = e.target.value;
            this.currentPage = 1;
            this.loadDocuments();
        });

        document.getElementById('filterDateTo').addEventListener('change', (e) => {
            this.currentDateTo = e.target.value;
            this.currentPage = 1;
            this.loadDocuments();
        });
    },

    switchSector: function (key) {
        this.currentSector = key;
        this.currentType = '';  // reset type filter
        this.currentPage = 1;
        document.getElementById('filterType').value = '';
        this.loadDocuments();
    },

    clearFilters: function () {
        this.currentSector = '';
        this.currentType = '';
        this.currentTenant = '';
        this.currentSearch = '';
        this.currentDateFrom = '';
        this.currentDateTo = '';
        this.currentPage = 1;

        document.getElementById('filterSearch').value = '';
        document.getElementById('filterType').value = '';
        document.getElementById('filterTenant').value = '';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';

        this.loadDocuments();
    },

    // ==================== DETAILS ====================
    viewDetails: async function (documentId) {
        UI.loader.show('Loading...');
        try {
            const res = await fetch('../backend/documents/fetch_document_details.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ document_id: documentId })
            });
            const data = await res.json();

            if (!data.success) {
                UI.toast(data.message || 'Failed to load details', 'danger');
                return;
            }

            this.currentDocument = data.message;
            this.renderDetails(data.message);
            this.openModal('documentDetailsModal');

        } catch (err) {
            console.error(err);
            UI.toast('Network error', 'danger');
        } finally {
            UI.loader.hide();
        }
    },

    renderDetails: function (d) {
        const previewButtons = (d.is_pdf || d.is_image) ? `
            <button class="btn-outline" onclick="Documents.preview(${d.document_id}, '${d.is_pdf ? 'pdf' : 'image'}')">
                <i class="fas fa-eye"></i> Preview
            </button>` : '';

        const body = document.getElementById('documentDetailsBody');
        body.innerHTML = `
            <div style="display:flex; align-items:center; gap:14px; padding:16px; background:#f8fafc; border-radius:12px; margin-bottom:20px;">
                <div style="width:56px; height:56px; border-radius:14px; background:${d.sector_bg_color}; color:${d.sector_color}; display:flex; align-items:center; justify-content:center; font-size:24px;">
                    <i class="fas ${d.document_type_icon}"></i>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:16px; font-weight:700; color:#0a0a1a; word-break:break-word;">${this.escapeHtml(d.document_name)}</div>
                    <div style="font-size:12px; color:#9ca3af; margin-top:2px; word-break:break-all;">${this.escapeHtml(d.original_file_name)}</div>
                </div>
                <span class="sector-badge" style="background:${d.sector_bg_color}; color:${d.sector_color};">
                    <i class="fas ${d.sector_icon}"></i> ${this.escapeHtml(d.sector_label)}
                </span>
            </div>

            <div class="details-section">
                <h4><i class="fas fa-user"></i> Tenant</h4>
                <div class="details-row"><span>Name</span><span>${this.escapeHtml(d.tenant_name)}</span></div>
                <div class="details-row"><span>Code</span><span>${this.escapeHtml(d.tenant_code)}</span></div>
                <div class="details-row"><span>Email</span><span>${this.escapeHtml(d.tenant_email)}</span></div>
            </div>

            <div class="details-section">
                <h4><i class="fas fa-building"></i> Property</h4>
                <div class="details-row"><span>Property</span><span>${this.escapeHtml(d.property_name)}</span></div>
                <div class="details-row"><span>Apartment</span><span>${this.escapeHtml(String(d.apartment_number))}</span></div>
            </div>

            <div class="details-section">
                <h4><i class="fas fa-upload"></i> Upload Info</h4>
                <div class="details-row"><span>Uploaded By</span><span>${this.escapeHtml(d.uploader_name)} (${this.escapeHtml(d.uploaded_by_label)})</span></div>
                <div class="details-row"><span>Uploaded At</span><span>${this.escapeHtml(d.uploaded_at_formatted)}</span></div>
                <div class="details-row"><span>File Size</span><span>${this.escapeHtml(d.file_size_formatted)}</span></div>
                <div class="details-row"><span>File Type</span><span>${this.escapeHtml(d.file_type)}</span></div>
            </div>

            <div style="display:flex; gap:10px; margin-top:20px; flex-wrap:wrap;">
                ${previewButtons}
            </div>
        `;
    },

    closeDetails: function () {
        this.closeModal('documentDetailsModal');
        this.currentDocument = null;
    },

    downloadCurrent: function () {
        if (this.currentDocument) {
            this.download(this.currentDocument.document_id);
        }
    },

    download: function (documentId) {
    // First, ping the backend to check if the session is still valid
    fetch(`../backend/documents/download_document.php?document_id=${documentId}&check=1`, {
        credentials: 'include'
    })
    .then(res => {
        if (res.status === 401) {
            UI.toast('Your session has expired. Please log in again.', 'warning');
            setTimeout(() => {
                window.location.href = '../pages/index.php';
            }, 1500);
            return;
        }
        // Session is fine — trigger the actual download
        window.open(`../backend/documents/download_document.php?document_id=${documentId}`, '_blank');
    })
    .catch(err => {
        console.error('Download check failed:', err);
        UI.toast('Unable to start download.', 'danger');
    });
},

    preview: function (documentId, type) {
        const body = document.getElementById('previewBody');
        const url = `../backend/documents/download_document.php?document_id=${documentId}&inline=1`;

        if (type === 'pdf') {
            body.innerHTML = `<iframe src="${url}" style="width:100%; height:100%; border:none;"></iframe>`;
        } else {
            body.innerHTML = `<div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; padding:20px;"><img src="${url}" style="max-width:100%; max-height:100%; border-radius:8px;" /></div>`;
        }
        this.openModal('previewModal');
    },

    closePreview: function () {
        this.closeModal('previewModal');
        document.getElementById('previewBody').innerHTML = '';
    },

    // ==================== DELETE ====================
    openDelete: function (documentId, name) {
        this.pendingDeleteId = documentId;
        document.getElementById('deleteDocName').textContent = name;
        this.openModal('deleteConfirmModal');
    },

    closeDeleteModal: function () {
        this.closeModal('deleteConfirmModal');
        this.pendingDeleteId = null;
    },

    confirmDelete: async function () {
        if (!this.pendingDeleteId) return;
        const id = this.pendingDeleteId;

        const btn = document.getElementById('deleteConfirmBtn');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        btn.disabled = true;

        UI.loader.show('Deleting document...');
        try {
            // Get CSRF token
            const csrfRes = await fetch('../../utilities/get_token.php?form=admin_delete_document_form', {
                credentials: 'include'
            });
            const csrfData = await csrfRes.json();

            const res = await fetch('../backend/documents/delete_document.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    document_id: id,
                    csrf_token: csrfData.token,
                    token_id: 'admin_delete_document_form'
                })
            });
            const data = await res.json();

            if (data.success) {
                UI.toast('Document deleted successfully', 'success');
                this.closeDeleteModal();
                this.loadDocuments();
            } else {
                UI.toast(data.message || 'Failed to delete', 'danger');
            }
        } catch (err) {
            console.error(err);
            UI.toast('Network error', 'danger');
        } finally {
            btn.innerHTML = original;
            btn.disabled = false;
            UI.loader.hide();
        }
    },

    // ==================== MODAL HELPERS ====================
    openModal: function (id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('active');
    },
    closeModal: function (id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('active');
    },

    // ==================== UTILS ====================
    escapeHtml: function (text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    },
    escapeAttr: function (text) {
        return String(text || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
    },
};

document.addEventListener('DOMContentLoaded', () => Documents.init());

// Close modals on outside click
document.addEventListener('click', (e) => {
    ['documentDetailsModal', 'deleteConfirmModal', 'previewModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m && e.target === m) {
            m.classList.remove('active');
        }
    });
});

// Escape key closes modals
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        ['documentDetailsModal', 'deleteConfirmModal', 'previewModal'].forEach(id => {
            const m = document.getElementById(id);
            if (m && m.classList.contains('active')) m.classList.remove('active');
        });
    }
});