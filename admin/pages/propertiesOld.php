<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Management</title>

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

        .property-actions {
            display: flex;
            flex-wrap: wrap;
            gap: var(--spacing-md);
            align-items: center;
        }

        .add-property-btn {
            background-color: var(--success-color);
        }

        .add-property-btn:hover {
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

        .property-details-photo {
            width: 150px;
            height: 150px;
            margin: 0 auto var(--spacing-lg);
            border-radius: var(--radius-lg);
            overflow: hidden;
            border: 3px solid var(--border-color);
        }

        .property-details-photo img {
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

            .property-actions {
                justify-content: center;
            }
        }
    </style>
</head>

<body>

    <?php include('navbar.php'); ?>

    <main class="container">
        <header class="page-header">
            <h1>property Management</h1>

            <div class="property-actions">
                <!-- Add property Button -->
                <div id="property-form">
                    <button id="addpropertyBtn" class="add-property-btn">
                        <i class="fas fa-plus"></i> Add New property
                    </button>
                </div>
            </div>
        </header>

        <!-- Search Section -->
        <section class="search-section">
            <div class="livesearch">
                <input type="text" id="liveSearch" placeholder="Search for property by name, email, or phone...">
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
            <div class="filter-section property-filters">

                <select id="filterStatus" class="filter-select">
                    <option value="">All Status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">InActive</option>
                </select>

                <button id="applypropertyFilters" class="filter-btn btn-secondary">
                    Apply Filters
                </button>

                <button id="resetFilters" class="filter-btn btn-outline">
                    Reset Filters
                </button>
            </div>
        </section>

        <!-- property Table Section -->
        <section class="table-section">
            <div class="table-responsive">
                <table id="propertyTable" class="ordersTable">
                    <thead>
                        <tr>
                            <th>Property ID</th>
                            <th>Agent ID</th>
                            <th>Property Code</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody id="propertyTableBody">
                        <!-- propertys will be dynamically inserted here -->
                        <tr class="no-data-row">
                            <td colspan="6" class="text-center">
                                <div class="empty-state">
                                    <i class="fas fa-users fa-3x"></i>
                                    <p>No property found. Add your first property to get started.</p>
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

    <!-- property View Modal -->
    <div id="propertyModal" class="modal">
        <div class="modal-content modal-lg">
            <span class="close" data-modal="propertyModal">&times;</span>

            <div class="modal-header">
                <h2>Property Information</h2>
            </div>

            <div class="modal-body">
                <div id="propertyPhoto" class="property-details-photo">
                    <div class="photo-placeholder">
                        <i class="fas fa-user-circle fa-5x"></i>
                    </div>
                </div>

                <table id="propertyDetailsTable" class="ordersTable property-details-table">
                    <tbody>
                        <!-- Auto populated -->
                    </tbody>
                </table>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" data-modal="propertyModal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Property Modal -->
    <div id="addPropertyModal" class="modal">
        <div class="modal-content modal-lg" id="property-form-card">
            <span class="close" data-modal="addPropertyModal">&times;</span>

            <div class="modal-header">
                <h2>Add New Property</h2>
            </div>

            <div class="modal-body">
                <form id="addPropertyForm" class="modal-form">
                    <!-- Property Code -->
                    <!-- <div class="form-input">
                        <label for="add_property_code">Property Code:</label>
                        <input type="text" id="add_property_code" name="property_code" required
                            placeholder="e.g., PROP-001">
                        <small class="form-help">Unique identifier for the property</small>
                    </div> -->

                    <!-- Property Name -->
                    <div class="form-input">
                        <label for="add_property_name">Property Name:</label>
                        <input type="text" id="add_property_name" name="name" required
                            placeholder="e.g., Luxury Apartments Complex">
                    </div>
                    <div class="form-input">
                        <label for="add_property_type">Select Type of Property:</label>
                        <select id="add_property_type" name="property_type" required>
                            <option value="">-- Select a Property Type --</option>
                        </select>
                            
                    </div>

                    <!-- Address Section -->
                    <div class="form-row">
                        <div class="form-input">
                            <label for="add_property_address">Street Address:</label>
                            <input type="text" id="add_property_address" name="address" required>
                        </div>

                        <div class="form-input">
                            <label for="add_property_city">City:</label>
                            <input type="text" id="add_property_city" name="city" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-input">
                            <label for="add_property_state">State/Province:</label>
                            <input type="text" id="add_property_state" name="state" required>
                        </div>

                        <div class="form-input">
                            <label for="add_property_country">Country:</label>
                            <select id="add_property_country" name="country" required>
                                <option value="">Select Country</option>
                                <option value="USA">United States</option>
                                <option value="UK">United Kingdom</option>
                                <option value="Canada">Canada</option>
                                <option value="Australia">Australia</option>
                                <option value="Germany">Germany</option>
                                <option value="France">France</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="form-row">
                        <div class="form-input">
                            <label for="add_contact_name">Contact Person Name:</label>
                            <input type="text" id="add_contact_name" name="contact_name" required>
                        </div>

                        <div class="form-input">
                            <label for="add_contact_phone">Contact Phone:</label>
                            <input type="tel" id="add_contact_phone" name="contact_phone" required
                                pattern="[0-9\-\+\s\(\)]{10,20}" placeholder="e.g., +1 (555) 123-4567">
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="form-input">
                        <label for="add_property_status">Status:</label>
                        <select id="add_property_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="maintenance">Under Maintenance</option>
                            <option value="sold">Sold</option>
                            <option value="rented">Rented</option>
                        </select>
                    </div>

                    <!-- Agent Assignment (if applicable) -->
                    <div class="form-input">
                        <label for="add_agent_id">Assign to Agent (Optional):</label>
                        <select id="add_agent_id" name="agent_id">
                            <option value="">No Agent Assigned</option>
                            <!-- Options will be populated dynamically from agents table -->
                            <option value="1">John Doe (Agent 1)</option>
                            <option value="2">Jane Smith (Agent 2)</option>
                            <!-- Add more options as needed -->
                        </select>
                    </div>

                    <!-- Photo Upload -->
                    <div class="form-input">
                        <label for="add_property_photo">Property Photo:</label>
                        <input type="file" id="add_property_photo" name="photo" accept="image/*">
                        <small class="form-help">Upload a clear image of the property (max 5MB)</small>
                    </div>

                    <!-- Photo Preview -->
                    <div id="property_photo_container" class="photo-preview-container">
                        <!-- Photo preview will appear here -->
                    </div>

                    <!-- Notes/Description -->
                    <div class="form-input">
                        <label for="add_property_notes">Notes/Description:</label>
                        <textarea id="add_property_notes" name="notes" rows="4"
                            placeholder="Additional details about the property, amenities, special features, etc."></textarea>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="reset" class="btn btn-outline">Reset Form</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-building"></i> Add Property
                        </button>
                    </div>
                </form>

                <div id="addPropertyMessage" class="message-container"></div>
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

    <script src="../scripts/properties.js"></script>