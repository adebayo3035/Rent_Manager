// documents.js - Complete Document Management System

let documents = [];

document.addEventListener('DOMContentLoaded', function() {
    initializeDocuments();
});

async function initializeDocuments() {
    // Wait for user data to be loaded from navbar
    if (window.currentUser && window.currentUser.tenant_code) {
        await fetchDocuments();
    } else {
        // Listen for user data loaded event
        window.addEventListener('userDataLoaded', async function(e) {
            await fetchDocuments();
        });
        
        // Also try to fetch if not available after a short delay
        setTimeout(async () => {
            if (!window.currentUser && !document.querySelector('.documents-container')) {
                await fetchDocuments();
            }
        }, 1000);
    }
}

async function fetchDocuments() {
    try {
        const response = await fetch('../backend/documents/fetch_documents.php');
        const data = await response.json();
        
        console.log('Documents response:', data);
        
        if (data.success) {
            // Handle different response structures
            documents = data.data || data.documents || [];
            renderDocuments();
        } else {
            throw new Error(data.message || 'Failed to fetch documents');
        }
    } catch (error) {
        console.error('Error fetching documents:', error);
        if (window.showToast) {
            window.showToast('Failed to load documents', 'error');
        }
        showEmptyState();
    }
}

function renderDocuments() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    if (!documents || documents.length === 0) {
        showEmptyState();
        return;
    }

    const html = `
        <div class="documents-container">
            <div class="page-header">
                <div>
                    <h1>My Documents</h1>
                    <p>Manage your important documents</p>
                </div>
                <button class="btn-upload" onclick="openUploadModal()">
                    <i class="fas fa-upload"></i> Upload Document
                </button>
            </div>
            
            <div class="documents-grid">
                ${documents.map(doc => `
                    <div class="document-card">
                        <div class="document-icon">
                            <i class="fas ${getFileIcon(doc.file_type || doc.original_file_name)}"></i>
                        </div>
                        <div class="document-name">${escapeHtml(doc.document_name)}</div>
                        <div class="document-meta">
                            <span class="doc-type">${formatDocumentType(doc.document_type)}</span>
                            <span class="document-size">${formatFileSize(doc.file_size)}</span>
                        </div>
                        <div class="document-meta">
                            <span><i class="fas fa-calendar"></i> ${formatDate(doc.uploaded_at)}</span>
                        </div>
                        <div class="document-actions">
                            <button class="btn-icon btn-download" onclick="downloadDocument(${doc.document_id})" title="Download">
                                <i class="fas fa-download"></i>
                            </button>
                            <button class="btn-icon btn-delete" onclick="confirmDeleteDocument(${doc.document_id}, '${escapeHtml(doc.document_name)}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
    
    contentArea.innerHTML = html;
}

function showEmptyState() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    contentArea.innerHTML = `
        <div class="documents-container">
            <div class="page-header">
                <div>
                    <h1>My Documents</h1>
                    <p>Manage your important documents</p>
                </div>
                <button class="btn-upload" onclick="openUploadModal()">
                    <i class="fas fa-upload"></i> Upload Document
                </button>
            </div>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>No Documents Found</h3>
                <p>You haven't uploaded any documents yet.</p>
                <button class="btn-primary" onclick="openUploadModal()" style="margin-top: 15px;">
                    Upload Your First Document
                </button>
            </div>
        </div>
    `;
}

function getFileIcon(fileName) {
    if (!fileName) return 'fa-file';
    const ext = fileName.split('.').pop().toLowerCase();
    const iconMap = {
        'pdf': 'fa-file-pdf',
        'doc': 'fa-file-word',
        'docx': 'fa-file-word',
        'jpg': 'fa-file-image',
        'jpeg': 'fa-file-image',
        'png': 'fa-file-image',
        'txt': 'fa-file-alt',
        'xls': 'fa-file-excel',
        'xlsx': 'fa-file-excel'
    };
    return iconMap[ext] || 'fa-file';
}

function formatDocumentType(type) {
    if (!type) return 'OTHER';
    const typeMap = {
        'LEASE_AGREEMENT': 'Lease Agreement',
        'lease_agreement': 'Lease Agreement',
        'IDENTIFICATION': 'Identification',
        'identification': 'Identification',
        'PAYMENT_RECEIPT': 'Payment Receipt',
        'payment_receipt': 'Payment Receipt',
        'MAINTENANCE_REQUEST': 'Maintenance Request',
        'maintenance_report': 'Maintenance Report',
        'OTHER': 'Other',
        'other': 'Other'
    };
    return typeMap[type] || type.replace(/_/g, ' ').toUpperCase();
}

function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
    } catch (e) {
        return dateString;
    }
}

function openUploadModal() {
    // Reset form first
    const form = document.getElementById('uploadForm');
    if (form) form.reset();
    
    const modal = document.getElementById('uploadModal');
    if (modal) {
        modal.classList.add('active');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        const form = document.getElementById('uploadForm');
        if (form) form.reset();
    }
}

async function uploadDocument() {
    const docType = document.getElementById('docType')?.value;
    const docName = document.getElementById('docName')?.value.trim();
    const docFile = document.getElementById('docFile')?.files[0];

    // Validate inputs
    if (!docType) {
        if (window.showToast) window.showToast('Please select document type', 'error');
        return;
    }
    
    if (!docName) {
        if (window.showToast) window.showToast('Please enter document name', 'error');
        return;
    }
    
    if (!docFile) {
        if (window.showToast) window.showToast('Please select a file', 'error');
        return;
    }

    // Validate file size (5MB max)
    const maxSize = 5 * 1024 * 1024;
    if (docFile.size > maxSize) {
        if (window.showToast) window.showToast('File size must be less than 5MB', 'error');
        return;
    }

    // Validate file type
    const allowedTypes = ['application/pdf', 'application/msword', 
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg', 'image/png'];
    
    if (!allowedTypes.includes(docFile.type)) {
        if (window.showToast) window.showToast('Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('document_type', docType);
    formData.append('document_name', docName);
    formData.append('document_file', docFile);

    // Show loading state
    const uploadBtn = document.querySelector('#uploadModal .btn-primary');
    const originalText = uploadBtn.innerHTML;
    uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    uploadBtn.disabled = true;

    try {
        const response = await fetch('../backend/documents/upload_document.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Document uploaded successfully', 'success');
            }
            closeModal('uploadModal');
            await fetchDocuments(); // Refresh the list
        } else {
            throw new Error(data.message || 'Upload failed');
        }
    } catch (error) {
        console.error('Error uploading document:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    } finally {
        uploadBtn.innerHTML = originalText;
        uploadBtn.disabled = false;
    }
}

function downloadDocument(documentId) {
    if (!documentId) {
        if (window.showToast) window.showToast('Invalid document', 'error');
        return;
    }
    
    // Open in new tab for download
    window.open(`../backend/documents/download_document.php?document_id=${documentId}`, '_blank');
}

async function deleteDocument(documentId) {
    try {
        const response = await fetch('../backend/documents/delete_document.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ document_id: documentId })
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Document deleted successfully', 'success');
            }
            await fetchDocuments(); // Refresh the list
        } else {
            throw new Error(data.message || 'Delete failed');
        }
    } catch (error) {
        console.error('Error deleting document:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Simple confirm modal for document delete
function showConfirmModal(options) {
    return new Promise((resolve) => {
        // Remove existing overlay if any
        const existingOverlay = document.querySelector('.simple-confirm-overlay');
        if (existingOverlay) existingOverlay.remove();
        
        // Create overlay
        const overlay = document.createElement('div');
        overlay.className = 'simple-confirm-overlay';
        
        overlay.innerHTML = `
            <div class="simple-confirm-modal">
                <div class="simple-confirm-header">
                    <div class="simple-confirm-icon">
                        <i class="fas ${options.icon || 'fa-exclamation-triangle'}"></i>
                    </div>
                    <h3>${options.title || 'Confirm'}</h3>
                </div>
                <div class="simple-confirm-body">
                    <p>${options.message || 'Are you sure?'}</p>
                    ${options.detail ? `<small>${options.detail}</small>` : ''}
                </div>
                <div class="simple-confirm-footer">
                    <button class="btn-cancel">${options.cancelText || 'Cancel'}</button>
                    <button class="btn-confirm">${options.confirmText || 'Delete'}</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        const cancelBtn = overlay.querySelector('.btn-cancel');
        const confirmBtn = overlay.querySelector('.btn-confirm');
        
        const close = (result) => {
            overlay.remove();
            resolve(result);
            if (result && options.onConfirm) options.onConfirm();
        };
        
        cancelBtn.onclick = () => close(false);
        confirmBtn.onclick = () => close(true);
        
        // Close on escape key
        const handleEscape = (e) => {
            if (e.key === 'Escape') {
                close(false);
                document.removeEventListener('keydown', handleEscape);
            }
        };
        document.addEventListener('keydown', handleEscape);
        
        // Close on backdrop click
        overlay.onclick = (e) => {
            if (e.target === overlay) close(false);
        };
    });
}

// Updated delete confirmation function
function confirmDeleteDocument(documentId, documentName) {
    showConfirmModal({
        title: 'Delete Document',
        icon: 'fa-trash-alt',
        message: `Delete "${documentName}"?`,
        detail: 'This action cannot be undone.',
        confirmText: 'Delete',
        cancelText: 'Cancel',
        onConfirm: () => deleteDocument(documentId)
    });
}