document.addEventListener('DOMContentLoaded', () => {
    // Configuration constants
    const CONFIG = {
        itemsPerPage: 10,
        minSpinnerTime: 1000,
        modalIds: {
            tenant: 'tenantModal',
            addTenant: 'addTenantModal'
        }
    };

    // State management
    const state = {
        currentPage: 1,
        currentTenantId: null
    };

    // DOM Elements
    const elements = {
        tables: {
            tenants: {
                body: document.querySelector('#tenantTableBody tbody'),
                container: document.getElementById('tenantTableBody')
            },
            details: {
                body: document.querySelector('#tenantTableBody tbody'),
                photoCell: document.querySelector('#tenantPhoto')
            }
        },
        pagination: document.getElementById('pagination'),
        search: document.getElementById('liveSearch'),
        modals: {
            tenant: document.getElementById(CONFIG.modalIds.tenant)
        },
        forms: {
            addTenant: document.getElementById('addTenantForm'),
            propertySelect: document.getElementById('selectedProperty'),
            unitSelect: document.getElementById('selectedUnit')
        },
        messages: {
            addTenant: document.getElementById('addTenantMessage')
        }
    };

    // Utility functions
    const utils = {
        maskDetails: (details) => details.slice(0, 2) + ' ** ** ' + details.slice(-3),

        showSpinner: (container) => {
            container.innerHTML = `
                <tr>
                    <td colspan="6" class="spinner-container">
                        <div class="spinner"></div>
                    </td>
                </tr>
            `;
        },

        showError: (container, message = 'Error loading data') => {
            container.innerHTML = `
                <tr>
                    <td colspan="6" class="error-message">${message}</td>
                </tr>
            `;
        },

        toggleModal: (modalId) => {
            const modal = document.getElementById(modalId);
            modal.style.display = (modal.style.display === "none" || modal.style.display === "") ? "block" : "none";
        },

        closeModal: (event) => {
            const modal = event.target.closest('.modal');
            if (modal) modal.style.display = 'none';
        },

        handleOutsideClick: (event) => {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        },

        createButton: (label, page, disabled = false, active = false) => {
            const btn = document.createElement('button');
            btn.textContent = label;
            btn.disabled = disabled;
            if (active) btn.classList.add('active');
            btn.addEventListener('click', () => {
                state.currentPage = page;
                api.fetchTenants(page);
            });
            return btn;
        }
    };

    // API Functions
    const api = {
        fetchTenants: (page = 1) => {
            utils.showSpinner(elements.tables.tenants.container);

            // Get filter values from dropdowns
            const gender = document.getElementById('filterGender').value;
            const restriction = document.getElementById('filterRestriction').value;
            const delete_status = document.getElementById('filterDelete').value;

            const params = new URLSearchParams({
                page,
                limit: CONFIG.itemsPerPage
            });

            // Add filters only if selected
            if (gender) params.append('gender', gender);
            if (restriction) params.append('restriction', restriction);
            if (delete_status) params.append('delete_status', delete_status);

            const minDelay = new Promise(resolve => setTimeout(resolve, CONFIG.minSpinnerTime));
            const fetchData = fetch(`../backend/tenants/get_tenants.php?${params.toString()}`)
                .then(res => res.json());

            Promise.all([fetchData, minDelay])
                .then(([data]) => {
                    if (data.success && data.tenants.length > 0) {
                        ui.updateTenantsTable(data.tenants);
                        ui.updatePagination(data.pagination.total, data.pagination.page, data.pagination.limit);
                    } else {
                        utils.showError(elements.tables.tenants.container, 'No Tenant Details at the moment');
                    }
                })
                .catch(() => {
                    utils.showError(elements.tables.tenants.container, 'Error loading Tenant data');
                });
        },

        fetchTenantDetails: async (tenantId) => {
            const response = await fetch(`../backend/tenants/fetch_tenant_details.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tenant_id: tenantId })
            });
            return await response.json();
        },

        updateTenant: async (tenantData) => {
            const response = await fetch('../backend/tenants/update_tenant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(tenantData)
            });
            return await response.json();
        },

        deleteTenant: async (tenantId) => {
            const response = await fetch('../backend/tenants/delete_tenant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tenant_id: tenantId })
            });
            return await response.json();
        },

        fetchProperties: async () => {
            const response = await fetch('../backend/properties/fetch_properties.php');
            return await response.json();
        },

        fetchUnits: async (propertyId) => {
            const response = await fetch('../backend/units/fetch_units.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ property_id: propertyId })
            });
            return await response.json();
        },

        addTenant: async (formData) => {
            const response = await fetch('../backend/tenants/add_tenant.php', {
                method: 'POST',
                body: formData
            });
            return await response.json();
        }
    };

    // UI Functions
    const ui = {
        updateTenantsTable: (tenants) => {
            elements.tables.tenants.body.innerHTML = '';

            tenants.forEach(tenant => {
                const row = document.createElement('tr');
                const restrictionStatus = tenant.restriction === 1 ? 'Restricted' : 'Not Restricted';
                const accountStatus = tenant.delete_status === 'Yes' ? 'Deactivated' : 'Activated';
                const restrictionClass = tenant.restriction === 1 ? 'restricted-badge' : 'not-restricted-badge';
                const accountStatusClass = tenant.delete_status === 'Yes' ? 'restricted-badge' : 'not-restricted-badge';
                const isDeactivated = tenant.delete_status == 'Yes';
                const isRestricted = tenant.restriction == 1;

                const showViewOnly = isDeactivated || isRestricted;

                row.innerHTML = `
                    <td>${tenant.firstname}</td>
                    <td>${tenant.lastname}</td>
                    <td>${tenant.gender}</td>
                    <td><span class="${restrictionClass}">${restrictionStatus}</span></td>
                    <td><span class="${accountStatusClass}">${accountStatus}</span></td>
                    <td>
                        <button class="view-details-btn" data-tenant-id="${tenant.tenant_id}">
                            ${showViewOnly ? 'View Details' : 'Edit Details'}
                        </button>
                    </td>
                `;

                elements.tables.tenants.body.appendChild(row);
            });

            // Attach event listeners to view details buttons
            document.querySelectorAll('.view-details-btn').forEach(button => {
                button.addEventListener('click', (event) => {
                    state.currentTenantId = event.target.getAttribute('data-tenant-id');
                    tenant.loadTenantDetails(state.currentTenantId);
                });
            });
        },

        updatePagination: (totalItems, currentPage, itemsPerPage) => {
            elements.pagination.innerHTML = '';
            const totalPages = Math.ceil(totalItems / itemsPerPage);

            // First and Previous buttons
            elements.pagination.appendChild(utils.createButton('« First', 1, currentPage === 1));
            elements.pagination.appendChild(utils.createButton('‹ Prev', currentPage - 1, currentPage === 1));

            // Page numbers
            const maxVisible = 2;
            const start = Math.max(1, currentPage - maxVisible);
            const end = Math.min(totalPages, currentPage + maxVisible);

            for (let i = start; i <= end; i++) {
                const btn = utils.createButton(i, i, false, i === currentPage);
                elements.pagination.appendChild(btn);
            }

            // Next and Last buttons
            const nextBtn = utils.createButton('Next ›', currentPage + 1, currentPage === totalPages);
            elements.pagination.appendChild(nextBtn);

            const lastBtn = utils.createButton('Last »', totalPages, currentPage === totalPages);
            elements.pagination.appendChild(lastBtn);
        },

        populateTenantDetails: (tenantDetails, userRole) => {
            const { body, photoCell } = elements.tables.details;

            body.innerHTML = `
                <tr>
                    <td>Date Onboarded</td>
                    <td><input type="text" id="dateCreated" value="${tenantDetails.date_created}" disabled></td>
                </tr>
                <tr>
                    <td>First Name</td>
                    <td><input type="text" id="firstname" value="${tenantDetails.firstname}"></td>
                </tr>
                <tr>
                    <td>Last Name</td>
                    <td><input type="text" id="lastname" value="${tenantDetails.lastname}"></td>
                </tr>
                <tr>
                    <td>Email</td>
                    <td>
                        <input type="email" id="email" value="${utils.maskDetails(tenantDetails.email)}">
                        <input type="hidden" id="originalEmail" value="${tenantDetails.email}">
                        <div class="masking"> 
                            <input type="checkbox" id="toggleMaskingEmail" name="viewEmail">
                            <label id="emailLabel" for="toggleMaskingEmail">Show Email</label>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td>Phone Number</td>
                    <td>
                        <input type="text" id="phoneNumber" value="${utils.maskDetails(tenantDetails.mobile_number)}">
                        <input type="hidden" id="originalPhoneNumber" value="${tenantDetails.mobile_number}">
                        <div class="masking">
                            <input type="checkbox" id="toggleMaskingPhone" name="viewPhone">
                            <label id="phoneLabel" for="toggleMaskingPhone">Show Phone Number</label>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td>Gender</td>
                    <td>
                        <select id="gender">
                            <option value="Male" ${tenantDetails.gender === 'Male' ? 'selected' : ''}>Male</option>
                            <option value="Female" ${tenantDetails.gender === 'Female' ? 'selected' : ''}>Female</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>House Address</td>
                    <td><input type="text" id="address" value="${tenantDetails.address}"></td>
                </tr>
                <tr>
                    <td>Tenant ID</td>
                    <td><input type="text" id="licenseNumber" value="${tenantDetails.Tenant_id}" disabled></td>
                </tr>
                <tr>
                    <td>Select Property</td>
                    <td>
                        <select id="selectProperty" class="selectedProprty">
                            <option value="">--Select a Property--</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Select Unit</td>
                    <td>
                        <select id="selectUnit" class="selectedUnit">
                            <option value="">--Select a Unit--</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Restriction Status</td>
                    <td>
                        ${tenantDetails.restriction === 1 ?
                            `<input type="text" value="Restricted" readonly class="restricted-input">` :
                            `<select id="restriction">
                                <option value="0" ${tenantDetails.restriction === 0 ? 'selected' : ''}>Not Restricted</option>
                                <option value="1" ${tenantDetails.restriction === 1 ? 'selected' : ''}>Restricted</option>
                            </select>`
                        }
                    </td>
                </tr>
                <tr class="action-button-row">
                    <td colspan="2" class="action-buttons">
                        ${(tenantDetails.delete_status !== 'Yes' && tenantDetails.restriction !== 1)
                            ? (
                                userRole === 'Super Admin'
                                    ? `<button id="updateTenantBtn">Update</button>
                                       <button id="deleteTenantBtn">Deactivate</button>`
                                    : userRole === 'Admin'
                                        ? `<button id="updateTenantBtn">Update</button>`
                                        : ''
                            )
                            : ''
                        }
                    </td>
                </tr>
            `;

            // Display photo
            photoCell.innerHTML = tenantDetails.photo ?
                `<img src="backend/tenant_photos/${tenantDetails.photo}" alt="Driver Photo" class="driver-photo">` :
                `<p>No photo available</p>`;

            // Set up toggle masking
            tenant.setupMaskingToggle(
                'toggleMaskingPhone',
                'phoneNumber',
                'originalPhoneNumber',
                'phoneLabel',
                'Phone Number'
            );

            tenant.setupMaskingToggle(
                'toggleMaskingEmail',
                'email',
                'originalEmail',
                'emailLabel',
                'Email'
            );

            // Load Properties and units
            tenant.loadProperties(tenantDetails.property_id);
            tenant.loadUnits(tenantDetails.property_id, tenantDetails.unit_id);

            // Set up Properties change listener
            document.getElementById('selectProperty').addEventListener('change', function () {
                tenant.loadUnits(this.value);
            });

            // Set up action buttons
            if (tenantDetails.delete_status !== 'Yes') {
                const updateBtn = document.getElementById('updateTenantBtn');
                if (updateBtn) {
                    updateBtn.addEventListener('click', () => {
                        tenant.updateTenant(state.currentTenantId);
                    });
                }

                const deleteBtn = document.getElementById('deleteTenantBtn');
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', () => {
                        tenant.deleteTenant(state.currentTenantId);
                    });
                }
            }
        },

        populateProperties: (properties, selectedPropertyId = null, elementId = 'selectedProperty') => {
            const interval = setInterval(() => {
                const propertySelect = document.getElementById(elementId);
                if (propertySelect) {
                    clearInterval(interval);
                    propertySelect.innerHTML = '<option value="">--Select a Property--</option>';
                    properties.forEach(property => {
                        const option = document.createElement('option');
                        option.value = property.property_id;
                        option.textContent = property.name;
                        option.selected = property.property_id === selectedPropertyId;
                        propertySelect.appendChild(option);
                    });
                }
            }, 100);
        },

        populateUnits: (units, selectedUnitId = null, elementId = 'selectUnit') => {
            const unitSelect = document.getElementById(elementId);
            unitSelect.innerHTML = '<option value="">--Select a Unit--</option>';

            units.forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.unit_id;
                option.textContent = unit.unit_name;
                option.selected = unit.unit_id === selectedUnitId;
                unitSelect.appendChild(option);
            });
        }
    };

    // Tenant Management Functions
    const tenant = {
        loadTenantDetails: (tenantId) => {
            api.fetchTenantDetails(tenantId)
                .then(data => {
                    if (data.success) {
                        ui.populateTenantDetails(data.tenant_details, data.user_role);
                        utils.toggleModal(CONFIG.modalIds.tenant);
                    } else {
                        console.error('Failed to fetch Tenant details:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error fetching Tenant details:', error);
                });
        },

        setupMaskingToggle: (checkboxId, visibleInputId, hiddenInputId, labelId, fieldName) => {
            const checkbox = document.getElementById(checkboxId);
            const visibleInput = document.getElementById(visibleInputId);
            const hiddenInput = document.getElementById(hiddenInputId);
            const label = document.getElementById(labelId);

            checkbox.addEventListener('change', function () {
                if (this.checked) {
                    visibleInput.value = hiddenInput.value;
                    label.textContent = `Hide ${fieldName}`;
                } else {
                    visibleInput.value = utils.maskDetails(hiddenInput.value);
                    label.textContent = `Show ${fieldName}`;
                }
            });
        },

        loadProperties: (selectedPropertyId = null) => {
            api.fetchProperties()
                .then(data => {
                    if (data.success) {
                        ui.populateProperties(data.properties, selectedPropertyId);
                    } else {
                        console.error('Error:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error fetching Properties:', error);
                });
        },

        loadUnits: (propertyId, selectedUnitId = null) => {
            if (!propertyId) return;

            api.fetchUnits(propertyId)
                .then(data => {
                    if (data.success) {
                        ui.populateUnits(data.units, selectedUnitId);
                    } else {
                        console.error('Error:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error fetching Units:', error);
                });
        },

        updateTenant: (tenantId) => {
            const emailInput = document.getElementById('email').value;
            const phoneNumberInput = document.getElementById('phoneNumber').value;
            const originalEmail = document.getElementById('originalEmail').value;
            const originalPhoneNumber = document.getElementById('originalPhoneNumber').value;

            // Validate unmasked fields
            if (emailInput.includes('*') || phoneNumberInput.includes('*')) {
                alert('Please unmask all fields before updating.');
                return;
            }

            const tenantData = {
                tenant_id: tenantId,
                firstname: document.getElementById('firstname').value,
                lastname: document.getElementById('lastname').value,
                email: emailInput === utils.maskDetails(originalEmail) ? originalEmail : emailInput,
                phone_number: phoneNumberInput === utils.maskDetails(originalPhoneNumber) ? originalPhoneNumber : phoneNumberInput,
                gender: document.getElementById('gender').value,
                address: document.getElementById('address').value,
                property: document.getElementById('selectProperty').value,
                unit: document.getElementById('selectUnit').value
            };

            // Only include restriction if it's editable
            const restrictionElement = document.getElementById('restriction');
            if (restrictionElement && restrictionElement.tagName === 'SELECT') {
                tenantData.restriction = restrictionElement.value;
            }

            if (confirm('Are you sure you want to Update Tenant Information?')) {
                api.updateTenant(tenantData)
                    .then(data => {
                        if (data.success) {
                            alert('Tenant Details has been updated successfully.');
                            utils.toggleModal(CONFIG.modalIds.tenant);
                            api.fetchTenants(state.currentPage);
                        } else {
                            alert('Failed to update Tenant Records: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error updating Tenant Records:', error);
                    });
            }
        },

        deleteTenant: (tenantId) => {
            if (confirm('Are you sure you want to delete this Tenant?')) {
                api.deleteTenant(tenantId)
                    .then(data => {
                        if (data.success) {
                            alert('Tenant has been successfully deleted!');
                            showToast('Tenant has been successfully deleted!', 'success');
                            utils.toggleModal(CONFIG.modalIds.tenant);
                            api.fetchTenants(state.currentPage);
                        } else {
                            alert('Failed to delete Tenant: ' + data.message);
                            showToast('Failed to delete Tenant: ' + data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting Tenant:', error);
                        showToast('An error occurred. Please Try Again Later', 'error');
                    });
            }
        },

        setupAddTenantForm: () => {
            elements.forms.addTenant.addEventListener('submit', (event) => {
                event.preventDefault();

                if (confirm('Are you sure you want to add new Tenant?')) {
                    const formData = new FormData(elements.forms.addTenant);

                    api.addTenant(formData)
                        .then(data => {
                            if (data.success) {
                                elements.messages.addTenant.textContent = 'Tenant has been successfully Onboarded!';
                                elements.messages.addTenant.style.color = 'green';
                                alert('Tenant has been successfully Onboarded!');
                                location.reload();
                            } else {
                                elements.messages.addTenant.textContent = data.message;
                                elements.messages.addTenant.style.color = 'red';
                                alert(data.message);
                            }
                        })
                        .catch(error => {
                            elements.messages.addTenant.textContent = 'Error: ' + error.message;
                            elements.messages.addTenant.style.color = 'red';
                            alert('An error occurred. Please Try Again Later');
                        });
                }
            });
        }
    };

    // Search functionality
    const search = {
        filterTable: () => {
            const searchTerm = elements.search.value.toLowerCase();
            const rows = document.querySelectorAll("#tenantTable tbody tr");

            rows.forEach(row => {
                let matchFound = false;
                const cells = row.getElementsByTagName("td");

                for (let i = 0; i < cells.length; i++) {
                    if (cells[i].textContent.toLowerCase().includes(searchTerm)) {
                        matchFound = true;
                        break;
                    }
                }

                row.style.display = matchFound ? "" : "none";
            });
        }
    };

    // Event Listeners
    const setupEventListeners = () => {
        // Modal close buttons
        document.querySelectorAll('.modal .close').forEach(btn => {
            btn.addEventListener('click', utils.closeModal);
        });

        // Outside click to close modals
        window.addEventListener('click', utils.handleOutsideClick);

        // Live search
        elements.search.addEventListener('input', search.filterTable);

        // Property change for onboarding form
        elements.forms.propertySelect.addEventListener('change', () => {
            tenant.loadUnits(elements.forms.propertySelect.value, null, 'selectedUnit');
        });

        // Setup add Tenant form
        tenant.setupAddTenantForm();
    };

    // Initialize
    const init = () => {
        setupEventListeners();
        api.fetchTenants(state.currentPage);
        tenant.loadProperties(null, 'selectedProperty');
    };

    // Apply Filter button event listener
    document.getElementById('applyTenantFilters').addEventListener('click', () => {
        api.fetchTenants(1);
    });

    init();

    // Modal functionality
            const modals = document.querySelectorAll('.modal');
            const closeButtons = document.querySelectorAll('.close, [data-modal]');
            const addTenantBtn = document.getElementById('addTenantBtn');
            
            // Open modal function
            function openModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            }
            
            // Close modal function
            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
            
            // Add Tenant button click
            if (addTenantBtn) {
                addTenantBtn.addEventListener('click', function() {
                    openModal('addTenantModal');
                });
            }
            
            // Close buttons click
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modalId = this.getAttribute('data-modal');
                    closeModal(modalId);
                });
            });
            
            // Close modal when clicking outside
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            });
            
            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    modals.forEach(modal => {
                        modal.classList.remove('active');
                    });
                    document.body.style.overflow = '';
                }
            });
            
            // Photo Preview
            const photoInput = document.getElementById('add_photo');
            const photoContainer = document.getElementById('photo_container');
            
            if (photoInput) {
                photoInput.addEventListener('change', function(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        // Remove existing preview
                        const existingPreview = photoContainer.querySelector('.photo-preview');
                        if (existingPreview) {
                            existingPreview.remove();
                        }
                        
                        // Create new preview
                        const img = document.createElement('img');
                        img.className = 'photo-preview';
                        img.src = e.target.result;
                        img.alt = 'Photo preview';
                        
                        photoContainer.appendChild(img);
                    };
                    
                    reader.readAsDataURL(file);
                });
            }

             // Search functionality
            const searchBtn = document.getElementById('searchBtn');
            const clearSearchBtn = document.getElementById('clearSearchBtn');
            const liveSearch = document.getElementById('liveSearch');
            
            if (searchBtn && liveSearch) {
                searchBtn.addEventListener('click', function() {
                    const searchTerm = liveSearch.value.trim();
                    if (searchTerm) {
                        // Implement search functionality
                        console.log('Searching for:', searchTerm);
                        // You would call your existing search function here
                    }
                });
            }
            
            if (clearSearchBtn && liveSearch) {
                clearSearchBtn.addEventListener('click', function() {
                    liveSearch.value = '';
                    // Implement clear search functionality
                    console.log('Search cleared');
                    // You would call your existing clear search function here
                });
            }

            // Toast notification system
        function showToast(message, type = 'success') {
            const toast = document.getElementById('messageToast');
            const toastMessage = toast.querySelector('.toast-message');
            const toastIcon = toast.querySelector('.toast-icon');
            
            // Set message and type
            toastMessage.textContent = message;
            toast.className = `toast ${type}`;
            
            // Set icon based on type
            let iconClass = '';
            switch(type) {
                case 'success':
                    iconClass = 'fas fa-check-circle';
                    break;
                case 'error':
                    iconClass = 'fas fa-exclamation-circle';
                    break;
                case 'warning':
                    iconClass = 'fas fa-exclamation-triangle';
                    break;
                case 'info':
                    iconClass = 'fas fa-info-circle';
                    break;
            }
            toastIcon.className = `toast-icon ${iconClass}`;
            
            // Show toast
            toast.classList.add('show');
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
            
            // Close button
            const closeBtn = toast.querySelector('.toast-close');
            closeBtn.onclick = () => toast.classList.remove('show');
        }
            
           
});

// Global function for HTML onclick handlers
function toggleModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = (modal.style.display === "none" || modal.style.display === "") ? "block" : "none";
    }
}