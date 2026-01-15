<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff</title>
    <link rel="stylesheet" href="../../styles.css">
</head>

<body>
    <?php include('navbar.php'); ?>

    <div class="container">
        <h1>Manage Staff</h1>

        <button id="addNewStaffBtn" class="addNewBtnModal">
            <i class="fa fa-plus" aria-hidden="true"></i> Add New Staff
        </button>

        <div class="livesearch">
            <input type="text" id="staffLiveSearch" placeholder="Search for Staff...">
        </div>
    </div>

    <table id="staffSummary" class="summaryTable">
        <thead>
            <tr>
                <th>Staff ID</th>
                <th>Staff Name</th>
                <th>Email</th>
                <th>Status</th>
                <th colspan="2">Actions</th>
            </tr>
        </thead>
        <tbody id="staffSummaryBody" class="summaryTableBody">
            <!-- Data loads dynamically -->
        </tbody>
    </table>

    <div id="staffPagination" class="pagination"></div>

    <!-- View/Edit Modal -->
    <div id="staffModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Staff Information</h2>

            <table class="summaryTable" id="staffDetailsTable">
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Add New Staff Modal -->
    <div id="addStaffModal" class="modal">
        <div class="modal-content">
            <span class="close" id="addStaffClose">&times;</span>
            <h2>Add New Staff</h2>
            <form id="addStaffForm" name = "add_staff_form" enctype="multipart/form-data">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" id="staffFirstName" placeholder="Enter first name" name="staff_firstname" class ="validate" data-type ="text" required>
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" id="staffLastName" placeholder="Enter last name" name="staff_lastname" class ="validate" data-type ="text" required>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="staffEmail" placeholder="Enter email address" name="staff_email" class ="validate" data-type ="email" required>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" id="staffPhone" placeholder="Enter phone number" name="staff_phone_number" class ="validate" data-type ="phone" required>
                </div>

                <div class="form-group">
                    <label>Address</label>
                    <textarea id="staffAddress" placeholder="Enter address" name="staff_address" class ="validate" data-type ="textarea" required></textarea>
                </div>
                <div class="form-group">
                    <label for="staffRole">Select Role</label>
                <select name="staff_role" id="staffRole" required>
                    <option value="">--Select a Role--</option>
                    <option value="Admin">Admin</option>
                    <option value="Super Admin">Super Admin</option>
                </select>
                </div>
            
                 <div class="form-group">
                    <label for="staffPassword">Password:</label>
                    <input type="password" id="staffPassword" name="staff_password" class ="validate" data-type ="password" required>
                </div>

                <div class="form-group">
                    <label for="staffSecretQuestion">Secret Question:</label>
                    <input type="text" id="staffSecretQuestion" name="staff_secret_question" class ="validate" data-type ="text" required>
                </div>

                <div class="form-group">
                    <label for="staffSecretAnswer">Secret Answer:</label>
                    <input type="password" id="staffSecretAnswer" name="staff_secret_answer" required>
                </div>


                <div class="form-group">
                    <label>Gender</label>
                    <select id="staffGender" name="staff_gender" class ="validate" data-type ="select" required>
                        <option value="">Select gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Photo</label>
                    <input type="file" id="staffPhoto" name="staff_photo" accept="image/*" required>
                </div>

                <div class="form-group">
                    <label>Preview</label>
                    <div id="photoPreview" class ="photoPreview">
                        <span id="defaultText" class = "photoPreviewText">No image</span>
                    </div>
                </div>
                <button id="saveStaffBtn" class="btn-primary addNewBtn">Save Staff</button>

            </form>
             <div id="addStaffMessage"></div>
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

    <script src="../scripts/staff.js"></script>
    <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>
    <script src = "../../validator.js"></script>

</body>

</html>