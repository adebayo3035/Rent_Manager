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
            <form id="addTenantForm" name = "add_tenant_form" enctype="multipart/form-data">

                <!-- PERSONAL INFORMATION -->
                <h3 class="section-title">Personal Information</h3>

                <div class="form-group">
                    <label for="firstname">First Name</label>
                    <input type="text" id="firstname" name="firstname" class="validate" data-type="text" required>
                </div>

                <div class="form-group">
                    <label for="lastname">Last Name</label>
                    <input type="text" id="lastname" name="lastname" class="validate" data-type="text" required>
                </div>

                <div class="form-group">
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender" class="validate" data-type="select" required>
                        <option value="">Select gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>

                <!-- CONTACT INFORMATION -->
                <h3 class="section-title">Contact Information</h3>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="validate" data-type="email" required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" class="validate" data-type="phone" required>
                </div>

               

                <!-- EMERGENCY CONTACT -->
                <h3 class="section-title">Emergency Contact</h3>

                <div class="form-group">
                    <label for="emergency_contact_name">Contact Name</label>
                    <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="validate"
                        data-type="text" required>
                </div>

                <div class="form-group">
                    <label for="emergency_contact_phone">Contact Phone</label>
                    <input type="text" id="emergency_contact_phone" name="emergency_contact_phone" class="validate"
                        data-type="phone" required>
                </div>

                <!-- EMPLOYMENT DETAILS -->
                <h3 class="section-title">Employment Information</h3>

                <div class="form-group">
                    <label for="occupation">Occupation</label>
                    <input type="text" id="occupation" name="occupation" class="validate" data-type="text" required>
                </div>

                <div class="form-group">
                    <label for="name_of_employer">Employer Name</label>
                    <input type="text" id="name_of_employer" name="name_of_employer" class="validate" data-type="text">
                </div>

                <div class="form-group">
                    <label for="employer_address">Employer Address</label>
                    <textarea id="employer_address" name="employer_address" class="validate"
                        data-type="textarea"></textarea>
                </div>

                <div class="form-group">
                    <label for="employer_contact">Employer Contact</label>
                    <input type="text" id="employer_contact" name="employer_contact" class="validate" data-type="text">
                </div>

                <!-- PROPERTY ALLOCATION -->
                <h3 class="section-title">Property Allocation</h3>

                <div class="form-group">
                    <label for="property_code">Property</label>
                    <select id="property_code" name="property_code" class="validate" data-type="select" required>
                        <option value="">Select Property</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="apartment_code">Apartment Unit</label>
                    <select id="apartment_code" name="apartment_code" class="validate" data-type="select" required>
                        <option value="">Select Apartment</option>
                    </select>
                </div>

                <!-- PAYMENT STRUCTURE -->
                <h3 class="section-title">Payment Structure</h3>

                <div class="form-group">
                    <label for="payment_frequency">Payment Frequency</label>
                    <select id="payment_frequency" name="payment_frequency" class="validate" data-type="select"
                        required>
                        <option value="">Select Frequency</option>
                        <option value="Monthly">Monthly</option>
                        <option value="Quarterly">Quarterly</option>
                        <option value="Semi-Annually">Semi-Annually</option>
                        <option value="Annually">Annually</option>
                    </select>
                </div>


                <!-- LEASE DETAILS -->
                <h3 class="section-title">Lease Details</h3>

                <div class="form-group">
                    <label for="lease_start_date">Lease Start Date</label>
                    <input type="date" id="lease_start_date" name="lease_start_date" class="validate" data-type="date"
                        required>
                </div>

                <div class="form-group">
                    <label for="lease_end_date">Lease End Date</label>
                    <input type="date" id="lease_end_date" name="lease_end_date" class="validate" data-type="date">
                </div>

                
                <!-- REFEREE -->
                <h3 class="section-title">Referee Information</h3>

                <div class="form-group">
                    <label for="referee_name">Referee Name</label>
                    <input type="text" id="referee_name" name="referee_name" class="validate" data-type="text">
                </div>

                <div class="form-group">
                    <label for="referee_phone">Referee Phone</label>
                    <input type="text" id="referee_phone" name="referee_phone" class="validate" data-type="phone">
                </div>

                <!-- PHOTO -->
                <h3 class="section-title">Tenant Photo</h3>

                <div class="form-group">
                    <label for="photo">Upload Photo</label>
                    <input type="file" id="tenantPhoto" name="photo" accept="image/*" required>
                </div>

                <div class="form-group">
                    <label>Preview</label>
                    <div id="photoPreview" class="photoPreview">
                        <span class="photoPreviewText">No image</span>
                    </div>
                </div>


                <!-- SUBMIT BUTTON -->
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
    <script src="../../validator.js"></script>

</body>

</html>