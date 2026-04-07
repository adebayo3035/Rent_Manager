// apartment.js
let apartmentDetails = null;

document.addEventListener('DOMContentLoaded', function() {
    // Wait for user data to be loaded from navbar
    if (window.currentUser) {
        initializePage();
    } else {
        // Listen for user data loaded event
        window.addEventListener('userDataLoaded', function(e) {
            currentUser = e.detail;
            initializePage();
        });
        
        // Also try to fetch if not available after a short delay
        setTimeout(() => {
            if (!window.currentUser && !apartmentDetails) {
                initializePage();
            }
        }, 1000);
    }
});

async function initializePage() {
    await fetchApartmentDetails();
    renderApartmentDetails();
}

async function fetchApartmentDetails() {
    try {
        const response = await fetch('../backend/tenant/fetch_apartment_details.php');
        const data = await response.json();
        
        console.log('Apartment details response:', data); // Debug log
        
        if (data.success && data.data) {
            apartmentDetails = data.data;
            console.log('Apartment details loaded:', apartmentDetails);
        } else {
            throw new Error(data.message || 'Failed to load apartment details');
        }
    } catch (error) {
        console.error('Error fetching apartment details:', error);
        if (window.showToast) {
            window.showToast('Failed to load apartment details', 'error');
        }
    }
}

function renderApartmentDetails() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    
    if (!apartmentDetails) {
        contentArea.innerHTML = `
            <div class="apartment-container">
                <div class="empty-state">
                    <i class="fas fa-building"></i>
                    <h3>No Apartment Found</h3>
                    <p>You don't have an apartment assigned yet. Please contact your property manager.</p>
                </div>
            </div>
        `;
        return;
    }

    // Extract data from the nested structure
    const apartmentInfo = apartmentDetails.apartment_details || {};
    const propertyInfo = apartmentDetails.property_details || {};
    const agentInfo = apartmentDetails.agent_details || {};
    const leaseInfo = apartmentDetails.lease_details || {};

    const tenantInfo = apartmentDetails.tenant_details || {};
    let monthly_rent = 0;
    if(leaseInfo.payment_frequency === "Annually"){
        monthly_rent = leaseInfo.rent_amount/12
    }else if (leaseInfo.payment_frequency === "Quaterly"){
        monthly_rent = leaseInfo.rent_amount/3
    }else if(leaseInfo.payment_frequency === "Semi-Annually"){
        monthly_rent = leaseInfo.rent_amount/6
    }
    else{
        monthly_rent = leaseInfo.rent_amount;
    }
   

    const html = `
        <div class="apartment-container">
            <div class="page-header">
                <h1>My Apartment</h1>
                <p>View your apartment details and amenities</p>
            </div>

            <div class="details-grid">
                <!-- Apartment Information -->
                <div class="detail-card">
                    <h3><i class="fas fa-info-circle"></i> Apartment Information</h3>
                    <div class="detail-item">
                        <span class="detail-label">Apartment Code:</span>
                        <span class="detail-value">${apartmentInfo.apartment_code || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Apartment Number:</span>
                        <span class="detail-value">${apartmentInfo.apartment_number || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Apartment Type:</span>
                        <span class="detail-value">${apartmentInfo.apartment_type || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Unit Type:</span>
                        <span class="detail-value">${apartmentInfo.apartment_type_unit || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Rent Amount:</span>
                        <span class="detail-value">₦${formatNumber(apartmentInfo.rent_amount)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Security Deposit:</span>
                        <span class="detail-value">₦${formatNumber(apartmentInfo.security_deposit)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Occupancy Status:</span>
                        <span class="detail-value">
                            <span style="color: ${apartmentInfo.occupancy_status === 'OCCUPIED' ? '#10b981' : '#f59e0b'}">
                                ${apartmentInfo.occupancy_status || 'N/A'}
                            </span>
                        </span>
                    </div>
                </div>

                <!-- Lease Information -->
                <div class="detail-card">
                    <h3><i class="fas fa-file-signature"></i> Lease Information</h3>
                    <div class="detail-item">
                        <span class="detail-label">Lease Start Date:</span>
                        <span class="detail-value">${formatDate(leaseInfo.lease_start_date)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Lease End Date:</span>
                        <span class="detail-value">${formatDate(leaseInfo.lease_end_date)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Days Remaining:</span>
                        <span class="detail-value">${leaseInfo.days_remaining || 0} days</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Payment Frequency:</span>
                        <span class="detail-value">${leaseInfo.payment_frequency || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Monthly Rent:</span>
                        <span class="detail-value">₦${formatNumber(monthly_rent)}</span>
                    </div>
                </div>

                <!-- Property Information -->
                <div class="detail-card">
                    <h3><i class="fas fa-building"></i> Property Information</h3>
                    <div class="detail-item">
                        <span class="detail-label">Property Name:</span>
                        <span class="detail-value">${propertyInfo.property_name || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Property Code:</span>
                        <span class="detail-value">${propertyInfo.property_code || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Property Address:</span>
                        <span class="detail-value">${propertyInfo.property_address || 'N/A'}</span>
                    </div>
                    ${propertyInfo.amenities && propertyInfo.amenities.length > 0 ? `
                        <div class="detail-item">
                            <span class="detail-label">Amenities:</span>
                            <span class="detail-value">
                                <div class="amenities-list">
                                    ${propertyInfo.amenities.map(amenity => `<span class="amenity-tag">${escapeHtml(amenity.amenity_name)}</span>`).join('')}
                                </div>
                            </span>
                        </div>
                    ` : ''}
                </div>

                <!-- Agent Information -->
                <div class="detail-card">
                    <h3><i class="fas fa-user-tie"></i> Property Agent</h3>
                    <div class="detail-item">
                        <span class="detail-label">Agent Name:</span>
                        <span class="detail-value">${agentInfo.agent_name || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Agent Code:</span>
                        <span class="detail-value">${agentInfo.agent_code || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Phone:</span>
                        <span class="detail-value">
                            ${agentInfo.agent_phone ? `<a href="tel:${agentInfo.agent_phone}">${agentInfo.agent_phone}</a>` : 'N/A'}
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value">
                            ${agentInfo.agent_email ? `<a href="mailto:${agentInfo.agent_email}">${agentInfo.agent_email}</a>` : 'N/A'}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Tenant Information Section -->
            <div class="detail-card">
                <h3><i class="fas fa-user"></i> Tenant Information</h3>
                <div class="details-grid" style="grid-template-columns: repeat(2, 1fr); margin-top: 15px;">
                    <div class="detail-item">
                        <span class="detail-label">Full Name:</span>
                        <span class="detail-value">${escapeHtml(tenantInfo.firstname || '')} ${escapeHtml(tenantInfo.lastname || '')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value">${tenantInfo.email || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Phone:</span>
                        <span class="detail-value">${tenantInfo.phone || 'N/A'}</span>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <button class="btn-primary" onclick="window.location.href='maintenance.php'">
                    <i class="fas fa-tools"></i> Report Maintenance Issue
                </button>
                <button class="btn-secondary" onclick="downloadLeaseAgreement()">
                    <i class="fas fa-download"></i> Download Lease Agreement
                </button>
                <button class="btn-secondary" onclick="contactAgent()">
                    <i class="fas fa-phone-alt"></i> Contact Agent
                </button>
            </div>
        </div>
    `;

    contentArea.innerHTML = html;
}

function downloadLeaseAgreement() {
    const tenantCode = window.currentUser?.tenant_code;
    if (tenantCode) {
        window.open(`../backend/tenant/download_lease.php?tenant_code=${tenantCode}`, '_blank');
    } else {
        if (window.showToast) {
            window.showToast('Unable to download lease agreement. Tenant information not found.', 'error');
        }
    }
}

function contactAgent() {
    const agentInfo = apartmentDetails?.agent_details || {};
    if (agentInfo.agent_phone) {
        window.location.href = `tel:${agentInfo.agent_phone}`;
    } else if (agentInfo.agent_email) {
        window.location.href = `mailto:${agentInfo.agent_email}`;
    } else {
        if (window.showToast) {
            window.showToast('Agent contact information not available', 'warning');
        }
    }
}

function formatNumber(value) {
    if (!value || value === '0') return '0.00';
    return new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
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