// documents.js
let documents = [];

document.addEventListener('DOMContentLoaded', function() {
    initializeDocuments();
});

async function initializeDocuments() {
    // Wait for user data to be loaded from navbar
    if (window.currentUser) {
        await fetchDocuments();
    } else {
        // Listen for user data loaded event
        window.addEventListener('userDataLoaded', async function() {
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
        const response = await fetch('../backend/tenant/fetch_documents.php');
        const data = await response.json();
        
        console.log('Documents response:', data); // Debug log
        
        if (data.success && data.data) {
            documents = data.data;
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
                            <i class="fas ${getFileIcon(doc.file_type)}"></i>
                        </div>
                        <div class="document-name">${escapeHtml(doc.document_name)}</div>
                        <div class="document-meta">
                            <span>${formatDocumentType(doc.document_type)}</span>
                            <span class="document-size">${formatFileSize(doc.file_size)}</span>
                        </div>
                        <div class="document-meta">
                            <span><i class="fas fa-calendar"></i> ${formatDate(doc.uploaded_at)}</span>
                        </div>
                        <div class="document-actions">
                            <button class="btn-icon btn-download" onclick="downloadDocument(${doc.document_id})" title="Download">
                                <i class="fas fa-download"></i>
                            </button>
                            <button class="btn-icon btn-delete" onclick="deleteDocument(${doc.document_id})" title="Delete">
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

function getFileIcon(fileType) {
    if (!fileType) return 'fa-file';
    if (fileType.includes('pdf')) return 'fa-file-pdf';
    if (fileType.includes('doc')) return 'fa-file-word';
    if (fileType.includes('jpg') || fileType.includes('jpeg') || fileType.includes('png')) return 'fa-file-image';
    return 'fa-file';
}

function formatDocumentType(type) {
    if (!type) return 'OTHER';
    return type.replace(/_/g, ' ').toUpperCase();
}

function formatFileSize(bytes) {
    if (!bytes) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return Math.round(bytes / Math.pow(1024, i)) + ' ' + sizes[i];
}

function openUploadModal() {
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
    const docName = document.getElementById('docName')?.value;
    const docFile = document.getElementById('docFile')?.files[0];

    if (!docType || !docName || !docFile) {
        if (window.showToast) {
            window.showToast('Please fill in all fields', 'error');
        }
        return;
    }

    // Validate file size (5MB max)
    if (docFile.size > 5 * 1024 * 1024) {
        if (window.showToast) {
            window.showToast('File size must be less than 5MB', 'error');
        }
        return;
    }

    const formData = new FormData();
    formData.append('document_type', docType);
    formData.append('document_name', docName);
    formData.append('document_file', docFile);

    try {
        if (window.showToast) {
            window.showToast('Uploading document...', 'info');
        }
        
        const response = await fetch('../backend/tenant/upload_document.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Document uploaded successfully', 'success');
            }
            closeModal('uploadModal');
            await fetchDocuments();
        } else {
            throw new Error(data.message || 'Upload failed');
        }
    } catch (error) {
        console.error('Error uploading document:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        }
    }
}

async function downloadDocument(documentId) {
    window.open(`../backend/tenant/download_document.php?document_id=${documentId}`, '_blank');
}

async function deleteDocument(documentId) {
    if (!confirm('Are you sure you want to delete this document?')) return;
    
    try {
        const response = await fetch('../backend/tenant/delete_document.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ document_id: documentId })
        });
        const data = await response.json();
        
        if (data.success) {
            if (window.showToast) {
                window.showToast('Document deleted successfully', 'success');
            }
            await fetchDocuments();
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

// ==================== UTILITY FUNCTIONS ====================
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

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}