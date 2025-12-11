<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tenants</title>
    <link rel="stylesheet" href="../../styles.css">
</head>

<body>
    <?php include('navbar.php'); ?>

    <div class="container">
        <h1>Manage Tenants</h1>

        <button id="addNewTenantBtn" class="addNewBtnModal">
            <i class="fa fa-plus" aria-hidden="true"></i> Add New Tenant
        </button>

        <div class="livesearch">
            <input type="text" id="tenantLiveSearch" placeholder="Search for Tenant...">
        </div>
    </div>

    <table id="tenantSummary" class="summaryTable">
        <thead>
            <tr>
                <th>Tenant ID</th>
                <th>Tenant Name</th>
                <th>Email</th>
                <th>Phone Number</th>
                <th>Status</th>
                <th colspan="2">Actions</th>
            </tr>
        </thead>
        <tbody id="tenantSummaryBody" class="summaryTableBody">
            <!-- Data loads dynamically -->
        </tbody>
    </table>

    <div id="tenantPagination" class="pagination"></div>

    <!-- View/Edit Modal -->
    <div id="tenantModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Tenant Information</h2>

            <table class="summaryTable" id="tenantDetailsTable">
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Add New Tenant Modal -->
    <div id="addTenantModal" class="modal">
        <div class="modal-content">
            <span class="close" id="addTenantClose">&times;</span>
            <h2>Add New Tenant</h2>
            <form id="addTenantForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" id="tenantFirstName" placeholder="Enter first name" name="tenant_firstname" class ="validate" data-type ="text" required>
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" id="tenantLastName" placeholder="Enter last name" name="tenant_lastname" class ="validate" data-type ="text" required>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="tenantEmail" placeholder="Enter email address" name="tenant_email" class ="validate" data-type ="email" required>
                </div>

                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" id="tenantPhone" placeholder="Enter phone number" name="tenant_phone_number" class ="validate" data-type ="phone" required>
                </div>

                <div class="form-group">
                    <label>Address</label>
                    <textarea id="tenantAddress" placeholder="Enter address" name="tenant_address" class ="validate" data-type ="textarea" required></textarea>
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <select id="tenantGender" name="tenant_gender" class ="validate" data-type ="select" required>
                        <option value="">Select gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Photo</label>
                    <input type="file" id="tenantPhoto" name="tenant_photo" accept="image/*" required>
                </div>

                <div class="form-group">
                    <label>Preview</label>
                    <div id="photoPreview" class ="photoPreview">
                        <span id="defaultText" class = "photoPreviewText">No image</span>
                    </div>
                </div>
                <button id="saveTenantBtn" class="btn-primary addNewBtn">Save Tenant</button>

            </form>
             <div id="addTenantMessage"></div>
        </div>
    </div>

    <!-- UI Library -->
    <div id="toastContainer"></div>

    <div id="alertModal" class="ui-modal">
        <div class="ui-modal-content">
            <h3 id="alertTitle">Alert</h3>
            <p id="alertMessage"></p>
            <button id="alertOkBtn">OK</button>
        </div>
    </div>

    <div id="confirmModal" class="ui-modal">
        <div class="ui-modal-content">
            <h3 id="confirmTitle">Confirm Action</h3>
            <p id="confirmMessage"></p>
            <div class="ui-modal-buttons">
                <button id="confirmCancelBtn">Cancel</button>
                <button id="confirmOkBtn">Yes</button>
            </div>
        </div>
    </div>

    <div id="uiLoaderOverlay">
        <div class="ui-loader"></div>
    </div>

    <script src="../scripts/tenant.js"></script>
    <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>
    <script src = "../../validator.js"></script>

</body>

</html>