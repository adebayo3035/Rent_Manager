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

                <!-- PERSONAL INFORMATION -->
                <h3 class="section-title">Personal Information</h3>

                <div class="form-group">
                    <label for="tenantFirstName">First Name</label>
                    <input type="text" id="tenantFirstName" name="tenant_firstname" placeholder="Enter first name"
                        class="validate" data-type="text" required>
                </div>

                <div class="form-group">
                    <label for="tenantLastName">Last Name</label>
                    <input type="text" id="tenantLastName" name="tenant_lastname" placeholder="Enter last name"
                        class="validate" data-type="text" required>
                </div>

                <div class="form-group">
                    <label for="tenantGender">Gender</label>
                    <select id="tenantGender" name="tenant_gender" class="validate" data-type="select" required>
                        <option value="">Select gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>


                <!-- CONTACT INFORMATION -->
                <h3 class="section-title">Contact Information</h3>

                <div class="form-group">
                    <label for="tenantEmail">Email</label>
                    <input type="email" id="tenantEmail" name="tenant_email" placeholder="Enter email address"
                        class="validate" data-type="email" required>
                </div>

                <div class="form-group">
                    <label for="tenantPhone">Phone Number</label>
                    <input type="text" id="tenantPhone" name="tenant_phone_number" placeholder="Enter phone number"
                        class="validate" data-type="phone" required>
                </div>

                <div class="form-group">
                    <label for="tenantAddress">Address</label>
                    <textarea id="tenantAddress" name="tenant_address" placeholder="Enter address" class="validate"
                        data-type="textarea" required></textarea>
                </div>


                <!-- PROPERTY ALLOCATION -->
                <h3 class="section-title">Allocated Property</h3>

                <div class="form-group">
                    <label for="tenantProperty">Select Allocated Property</label>
                    <select id="tenantProperty" name="tenant_property" class="validate" data-type="select" required>
                        <option value="">-- Select Property --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="tenantPropertyUnit">Select Property Unit</label>
                    <select id="tenantPropertyUnit" name="tenant_property_unit" class="validate" data-type="select"
                        required>
                        <option value="">-- Select Unit --</option>
                    </select>
                </div>


                <!-- LEASE INFORMATION -->
                <h3 class="section-title">Lease Details</h3>

                <div class="form-group">
                    <label for="leaseStartDate">Lease Start Date</label>
                    <input type="date" id="leaseStartDate" name="lease_start_date" class="validate" data-type="date"
                        required>
                </div>

                <div class="form-group">
                    <label for="rentAmount">Rent Amount</label>
                    <input type="number" id="rentAmount" name="rent_amount" placeholder="Enter rent fee"
                        class="validate" data-type="number" required>
                </div>

                <div class="form-group">
                    <label for="securityDeposit">Security / Damages Deposit</label>
                    <input type="number" id="securityDeposit" name="security_deposit" placeholder="Enter deposit amount"
                        class="validate" data-type="number" required>
                </div>


                <!-- PAYMENT STRUCTURE -->
                <h3 class="section-title">Payment Structure</h3>

                <div class="form-group">
                    <label for="rentPaymentFrequency">Rent Payment Frequency</label>
                    <select id="rentPaymentFrequency" name="rent_payment_frequency" class="validate" data-type="select"
                        required>
                        <option value="">-- Select Frequency --</option>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="nextPayment">Next Rent Payment Date</label>
                    <input type="date" id="nextPayment" name="next_payment" class="validate" data-type="date" required>
                </div>


                <!-- PHOTO UPLOAD -->
                <h3 class="section-title">Tenant Photo</h3>

                <div class="form-group">
                    <label for="tenantPhoto">Upload Photo</label>
                    <input type="file" id="tenantPhoto" name="tenant_photo" accept="image/*" required>
                </div>

                <div class="form-group">
                    <label>Preview</label>
                    <div id="photoPreview" class="photoPreview">
                        <span id="defaultText" class="photoPreviewText">No image</span>
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