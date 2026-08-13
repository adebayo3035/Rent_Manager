<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent's Portfolio - Rent Pilot</title>
    
    <!-- CSS -->
    <link rel="stylesheet" href="../css/agent_properties.css">
    
    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/7cab3097e7.js" crossorigin="anonymous"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="agent-properties-container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-user-tie"></i> Agent's Portfolio</h1>
                <p class="subtitle">View all agents and their managed properties</p>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="summary-stats" id="summaryStats">
            <!-- Rendered by JavaScript -->
        </div>
        
        <!-- Filters -->
        <div class="filters-bar">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search agents by name, email or code...">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select id="statusFilter" class="filter-select">
                    <option value="">All Statuses</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Gender</label>
                <select id="genderFilter" class="filter-select">
                    <option value="">All Genders</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="btn-clear" id="clearFiltersBtn">
                    <i class="fas fa-times"></i> Clear Filters
                </button>
            </div>
        </div>
        
        <!-- Agents Grid -->
        <div class="agents-grid" id="agentsGrid">
            <!-- Rendered by JavaScript -->
        </div>
        
        <!-- Pagination -->
        <div class="pagination-wrapper" id="paginationControls">
            <!-- Rendered by JavaScript -->
        </div>
    </div>
    
    <!-- Properties Modal -->
    <div id="propertiesModal" class="modal" style="display: none;">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3 id="modalAgentTitle"><i class="fas fa-building"></i> Properties Managed by <span id="modalAgentName"></span></h3>
                <button class="modal-close" onclick="closePropertiesModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalPropertiesGrid">
                    <div class="loading-state">
                        <div class="spinner"></div>
                        <p>Loading properties...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closePropertiesModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer"></div>
    
    <!-- JavaScript -->
    <script src="../scripts/agent_properties.js"></script>
</body>
</html>