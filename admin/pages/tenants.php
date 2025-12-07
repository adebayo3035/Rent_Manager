
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Management</title>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Main CSS Stylesheet -->
    <link rel="stylesheet" href="../../style.css">
    
    <!-- Page-specific customizations -->
    <style>
        /* Page-specific styles that don't belong in the generic styles.css */
        .page-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--spacing-xl);
            gap: var(--spacing-md);
        }
        
        .page-header h1 {
            margin-bottom: 0;
        }
        
        .tenant-actions {
            display: flex;
            flex-wrap: wrap;
            gap: var(--spacing-md);
            align-items: center;
        }
        
        .add-tenant-btn {
            background-color: var(--success-color);
        }
        
        .add-tenant-btn:hover {
            background-color: #059669;
        }
        
        .search-section {
            margin-bottom: var(--spacing-lg);
        }
        
        .filters-section {
            margin-bottom: var(--spacing-xl);
        }
        
        .photo-preview-container {
            margin-top: var(--spacing-sm);
            text-align: center;
        }
        
        .photo-preview {
            max-width: 150px;
            height: auto;
            border-radius: var(--radius-md);
            border: 2px solid var(--border-color);
        }
        
        .tenant-details-photo {
            width: 150px;
            height: 150px;
            margin: 0 auto var(--spacing-lg);
            border-radius: var(--radius-lg);
            overflow: hidden;
            border: 3px solid var(--border-color);
        }
        
        .tenant-details-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: var(--spacing-sm);
            margin-top: var(--spacing-lg);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .page-header h1 {
                text-align: center;
            }
            
            .tenant-actions {
                justify-content: center;
            }
        }
    </style>
</head>

<body>

    <?php include('navbar.php'); ?>

    <main class="container">
        <header class="page-header">
            <h1>Tenant Management</h1>
            
            <div class="tenant-actions">
                <!-- Add Tenant Button -->
                <div id="tenant-form">
                    <button id="addTenantBtn" class="add-tenant-btn">
                        <i class="fas fa-plus"></i> Add New Tenant
                    </button>
                </div>
            </div>
        </header>

        <!-- Search Section -->
        <section class="search-section">
            <div class="livesearch">
                <input type="text" id="liveSearch" placeholder="Search for tenant by name, email, or phone...">
                <button type="button" class="btn btn-secondary" id="searchBtn">
                    <i class="fas fa-search"></i> Search
                </button>
                <button type="button" class="btn btn-outline" id="clearSearchBtn">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </section>

        <!-- Filters Section -->
        <section class="filters-section">
            <div class="filter-section tenant-filters">
                <select id="filterGender" class="filter-select">
                    <option value="">All Gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>

                <select id="filterRestriction" class="filter-select">
                    <option value="">All Restrictions</option>
                    <option value="0">Not Restricted</option>
                    <option value="1">Restricted</option>
                </select>

                <select id="filterDelete" class="filter-select">
                    <option value="">All Statuses</option>
                    <option value="NULL">Active</option>
                    <option value="Yes">Deactivated</option>
                </select>

                <button id="applyTenantFilters" class="filter-btn btn-secondary">
                    Apply Filters
                </button>
                
                <button id="resetFilters" class="filter-btn btn-outline">
                    Reset Filters
                </button>
            </div>
        </section>

        <!-- Tenant Table Section -->
        <section class="table-section">
            <div class="table-responsive">
                <table id="tenantTable" class="ordersTable">
                    <thead>
                        <tr>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Gender</th>
                            <th>Restriction</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody id="tenantTableBody">
                        <!-- Tenants will be dynamically inserted here -->
                        <tr class="no-data-row">
                            <td colspan="6" class="text-center">
                                <div class="empty-state">
                                    <i class="fas fa-users fa-3x"></i>
                                    <p>No tenants found. Add your first tenant to get started.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="pagination" class="pagination">
                <!-- Pagination will be dynamically inserted here -->
            </div>
        </section>
    </main>

    <!-- Tenant View Modal -->
    <div id="tenantModal" class="modal">
        <div class="modal-content modal-lg">
            <span class="close" data-modal="tenantModal">&times;</span>
            
            <div class="modal-header">
                <h2>Tenant Information</h2>
            </div>
            
            <div class="modal-body">
                <div id="tenantPhoto" class="tenant-details-photo">
                    <div class="photo-placeholder">
                        <i class="fas fa-user-circle fa-5x"></i>
                    </div>
                </div>

                <table id="tenantDetailsTable" class="ordersTable tenant-details-table">
                    <tbody>
                        <!-- Auto populated -->
                    </tbody>
                </table>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" data-modal="tenantModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Tenant Modal -->
    <div id="addTenantModal" class="modal">
        <div class="modal-content modal-lg" id="tenant-form-card">
            <span class="close" data-modal="addTenantModal">&times;</span>
            
            <div class="modal-header">
                <h2>Add New Tenant</h2>
            </div>
            
            <div class="modal-body">
                <form id="addTenantForm" class="modal-form" autocomplete="off">
                    <div class="form-row">
                        <div class="form-input">
                            <label for="add_firstname">First Name:</label>
                            <input type="text" id="add_firstname" name="firstname" required>
                        </div>

                        <div class="form-input">
                            <label for="add_lastname">Last Name:</label>
                            <input type="text" id="add_lastname" name="lastname" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        

                        <div class="form-input">
                            <label for="add_address">Address:</label>
                            <input type="text" id="add_address" name="address" required autocomplete="off">
                        </div>

                        <div class="form-input">
                            <label for="add_phone">Phone Number:</label>
                            <input type="tel" id="add_phone" name="mobile_number" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-input">
                            <label for="add_email">Email:</label>
                            <input type="email" id="add_email" name="email" required>
                        </div>

                        <div class="form-input">
                            <label for="add_gender">Gender:</label>
                            <select id="add_gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>

                        
                    </div>
                    
                    <div class="form-row">
                        <div class="form-input">
                            <label for="add_password">Password:</label>
                            <input type="password" id="add_password" name="password" required autocomplete="off">
                        </div>
                        
                        <div class="form-input">
                            <label for="add_secret_question">Secret Question:</label>
                            <input type="text" id="add_secret_question" name="secret_question" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-input">
                            <label for="add_secret_answer">Secret Answer:</label>
                            <input type="password" id="add_secret_answer" name="secret_answer" required>
                        </div>
                        
                        <div class="form-input">
                            <label for="add_photo">Photo:</label>
                            <input type="file" id="add_photo" name="photo" accept="image/*">
                        </div>
                    </div>

                    <div id="photo_container" class="photo-preview-container">
                        <!-- Photo preview will appear here -->
                    </div>
                    
                    <div class="form-row">
                        <div class="form-input">
                            <label for="selectedProperty">Select Property Group:</label>
                            <select id="selectedProperty" name="property_id" required>
                                <option value="">Select Property</option>
                                <!-- Options will be populated dynamically -->
                            </select>
                        </div>

                        <div class="form-input">
                            <label for="selectedUnit">Select Unit:</label>
                            <select id="selectedUnit" name="unit_id" required>
                                <option value="">Select Unit</option>
                                <!-- Options will be populated dynamically -->
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-input">
                            <label for="add_start_date">Rent Start Date:</label>
                            <input type="date" id="add_start_date" name="rent_start_date" required>
                        </div>
                        
                        <div class="form-input">
                            <label for="add_end_date">Rent End Date:</label>
                            <input type="date" id="add_end_date" name="rent_end_date" required>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-outline">Reset Form</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-user-plus"></i> Add Tenant
                        </button>
                    </div>
                </form>

                <div id="addTenantMessage" class="message-container"></div>
            </div>
        </div>
    </div>

    <!-- Success/Error Message Toast -->
    <div id="messageToast" class="toast">
        <div class="toast-content">
            <i class="toast-icon"></i>
            <span class="toast-message"></span>
            <button class="toast-close">&times;</button>
        </div>
    </div>

    <script src="../scripts/tenants.js"></script>