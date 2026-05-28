// client/scripts/apartment.js - Client property overview

let propertyList = [];

document.addEventListener('DOMContentLoaded', () => {
    if (window.currentUser?.client_code) {
        initializePage();
        return;
    }

    window.addEventListener('userDataLoaded', initializePage, { once: true });
    setTimeout(() => {
        if (!propertyList.length) {
            initializePage();
        }
    }, 800);
});

async function initializePage() {
    await fetchProperties();
    renderProperties();
}

async function fetchProperties() {
    try {
        const response = await fetch('../backend/dashboard/fetch_properties.php?limit=50', {
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to load properties');
        }

        propertyList = Array.isArray(data.data?.properties) ? data.data.properties : [];
    } catch (error) {
        console.error('Error fetching properties:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Failed to load properties', 'error');
        }
        propertyList = [];
    }
}

function renderProperties() {
    const contentArea = document.getElementById('contentArea');
    if (!contentArea) return;

    if (!propertyList.length) {
        contentArea.innerHTML = `
            <div class="apartment-container">
                <div class="page-header">
                    <h1>My Properties</h1>
                    <p>View the properties linked to your client account.</p>
                </div>
                <div class="empty-state">
                    <i class="fas fa-building"></i>
                    <h3>No Properties Found</h3>
                    <p>No property has been assigned to your client account yet.</p>
                </div>
            </div>
        `;
        return;
    }

    contentArea.innerHTML = `
        <div class="apartment-container">
            <div class="page-header">
                <h1>My Properties</h1>
                <p>Review occupancy and unit distribution across your properties.</p>
            </div>

            <div class="details-grid property-overview-grid">
                ${propertyList.map(renderPropertyCard).join('')}
            </div>
        </div>
    `;
}

function renderPropertyCard(property) {
    const totalUnits = Number(property.total_units) || 0;
    const occupiedUnits = Number(property.occupied_units) || 0;
    const vacantUnits = Number(property.vacant_units) || Math.max(totalUnits - occupiedUnits, 0);
    const occupancyRate = Number(property.occupancy_rate) || 0;

    return `
        <article class="detail-card property-card">
            <h3><i class="fas fa-building"></i> ${escapeHtml(property.property_name || 'Unnamed Property')}</h3>
            <div class="detail-item">
                <span class="detail-label">Property Code:</span>
                <span class="detail-value">${escapeHtml(property.property_code || 'N/A')}</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Address:</span>
                <span class="detail-value">${escapeHtml(property.property_address || 'No address')}</span>
            </div>
            <div class="property-metrics">
                <div>
                    <strong>${formatInteger(totalUnits)}</strong>
                    <span>Total Units</span>
                </div>
                <div>
                    <strong>${formatInteger(occupiedUnits)}</strong>
                    <span>Occupied</span>
                </div>
                <div>
                    <strong>${formatInteger(vacantUnits)}</strong>
                    <span>Vacant</span>
                </div>
                <div>
                    <strong>${formatInteger(occupancyRate)}%</strong>
                    <span>Occupancy</span>
                </div>
            </div>
        </article>
    `;
}

function formatInteger(value) {
    return new Intl.NumberFormat('en-NG', { maximumFractionDigits: 0 }).format(Number(value) || 0);
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
