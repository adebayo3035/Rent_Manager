<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Management</title>

   
</head>

<body>

    <?php include('navbar.php'); ?>

    <div class="container">
        <h1>Tenant Management</h1>

        <!-- Add Tenant Button -->
        <div id="tenant-form">
            <button onclick="toggleModal('addTenantModal')">
                <i class="fa fa-plus"></i> Add New Tenant
            </button>
        </div>

        <!-- Search -->
        <div class="livesearch">
            <input type="text" id="liveSearch" placeholder="Search for tenant...">
            <button type="submit">Search <i class="fa fa-search"></i></button>
        </div>

        <!-- Filters -->
        <div class="filter-section tenant-filters">
            <select id="filterGender" class="filter-select">
                <option value="">All Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>

            <select id="filterRestriction" class="filter-select">
                <option value="">Restriction</option>
                <option value="0">Not Restricted</option>
                <option value="1">Restricted</option>
            </select>

            <select id="filterDelete" class="filter-select">
                <option value="">Account Status</option>
                <option value="NULL">Active</option>
                <option value="Yes">Deactivated</option>
            </select>

            <button id="applyTenantFilters" class="filter-btn">Apply Filters</button>
        </div>
    </div>

    <!-- Tenant Table -->
    <table id="tenantTable" class="ordersTable">
        <thead>
            <tr>
                <th>FirstName</th>
                <th>LastName</th>
                <th>Gender</th>
                <th>Restriction</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody id="tenantTableBody">
            <!-- Tenants will be dynamically inserted here -->
        </tbody>
    </table>

    <div id="pagination" class="pagination"></div>

    <!-- Tenant View Modal -->
    <div id="tenantModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Tenant Information</h2>

            <div id="tenantPhoto" class="photo-container"></div>

            <table id="tenantDetailsTable" class="ordersTable">
                <tbody>
                    <!-- Auto populated -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Tenant Modal -->
    <div id="addTenantModal" class="modal">
        <div class="modal-content" id="tenant-form-card">

            <span class="close">&times;</span>
            <h2>Add New Tenant</h2>

            <form id="addTenantForm" class="modal-form">

                <div class="form-input">
                    <label>First Name:</label>
                    <input type="text" id="add_firstname" name="firstname" required>
                </div>

                <div class="form-input">
                    <label>Last Name:</label>
                    <input type="text" id="add_lastname" name="lastname" required>
                </div>

                <div class="form-input">
                    <label>Email:</label>
                    <input type="email" id="add_email" name="email" required>
                </div>

                <div class="form-input">
                    <label>Phone Number:</label>
                    <input type="text" id="add_phone" name="mobile_number" required>
                </div>

                <div class="form-input">
                    <label>Gender:</label>
                    <select id="add_gender" name="gender" required>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>

                <div class="form-input">
                    <label>Address:</label>
                    <input type="text" id="add_address" name="address" required>
                </div>

                <div class="form-input">
                    <label>Password:</label>
                    <input type="password" id="add_password" name="password" required>
                </div>
                <div class="form-input">
                    <label>Secret Question:</label>
                    <input type="text" id="add_secret_question" name="secret_question" required>
                </div>
                <div class="form-input">
                    <label>Secret Answer:</label>
                    <input type="password" id="add_secret_answer" name="secret_answer" required>
                </div>

                <div class="form-input">
                    <label>Photo:</label>
                    <input type="file" id="add_photo" name="photo" accept="image/*"
                        onchange="previewPhoto(event)">
                </div>

                <div id="photo_container">
                    <img id="photo_preview" src="#" style="display:none; max-width:150px;">
                </div>

                <div class="form-input">
                    <label>Select Property Group:</label>
                    <select id="selectedProperty" name="property_id" required></select>
                </div>

                <div class="form-input">
                    <label>Select Unit:</label>
                    <select id="selectedUnit" name="unit_id" required></select>
                </div>
                <div class="form-input">
                    <label>Rent Start Date:</label>
                    <input type="date" id="add_start_date" name="rent_start_date" required>
                </div>
                <div class="form-input">
                    <label>Rent End Date:</label>
                    <input type="date" id="add_end_date" name="rent_end_date" required>
                </div>

                <button type="submit">Add Tenant</button>
            </form>

            <div id="addTenantMessage"></div>
        </div>
    </div>

    <script src="../scripts/tenants.js"></script>

    <script>
        // Photo Preview
        function previewPhoto(event) {
            const file = event.target.files[0];
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.getElementById('photo_preview');
                img.style.display = "block";
                img.src = e.target.result;
            };
            if (file) reader.readAsDataURL(file);
        }
    </script>
</body>

</html>