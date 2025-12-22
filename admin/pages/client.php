<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Clients</title>
    <link rel="stylesheet" href="../../styles.css">
</head>

<body>
    <?php include('navbar.php'); ?>

    <div class="container">
        <h1>Manage Clients</h1>

        <button id="addNewClientBtn" class="addNewBtnModal">
            <i class="fa fa-plus" aria-hidden="true"></i> Add New Client
        </button>

        <div class="livesearch">
            <input type="text" id="clientLiveSearch" placeholder="Search for Client...">
        </div>
    </div>

    <table id="clientSummary" class="summaryTable">
        <thead>
            <tr>
                <th>Client ID</th>
                <th>Client Name</th>
                <th>Email</th>
                <th>Status</th>
                <th colspan="2">Actions</th>
            </tr>
        </thead>
        <tbody id="clientSummaryBody" class="summaryTableBody">
            <!-- Data loads dynamically -->
        </tbody>
    </table>

    <div id="clientPagination" class="pagination"></div>

    <!-- View/Edit Modal -->
    <div id="clientModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Client Information</h2>

            <table class="summaryTable" id="clientDetailsTable">
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Add New Client Modal -->
    <div id="addClientModal" class="modal">
        <div class="modal-content">
            <span class="close" id="addClientClose">&times;</span>
            <h2>Add New Client</h2>
            <form id="addClientForm" name = "add_client_form" enctype="multipart/form-data">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" id="clientFirstName" placeholder="Enter first name" name="client_firstname" class ="validate" data-type ="text" required>
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" id="clientLastName" placeholder="Enter last name" name="client_lastname" class ="validate" data-type ="text" required>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="clientEmail" placeholder="Enter email address" name="client_email" class ="validate" data-type ="email" required>
                </div>

                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" id="clientPhone" placeholder="Enter phone number" name="client_phone_number" class ="validate" data-type ="phone" required>
                </div>

                <div class="form-group">
                    <label>Address</label>
                    <textarea id="clientAddress" placeholder="Enter address" name="client_address" class ="validate" data-type ="textarea" required></textarea>
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <select id="clientGender" name="client_gender" class ="validate" data-type ="select" required>
                        <option value="">Select gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Photo</label>
                    <input type="file" id="clientPhoto" name="client_photo" accept="image/*" required>
                </div>

                <div class="form-group">
                    <label>Preview</label>
                    <div id="photoPreview" class ="photoPreview">
                        <span id="defaultText" class = "photoPreviewText">No image</span>
                    </div>
                </div>
                <button id="saveClientBtn" class="btn-primary addNewBtn">Save Client</button>

            </form>
             <div id="addClientMessage"></div>
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

    <script src="../scripts/client.js"></script>
    <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>
    <script src = "../../validator.js"></script>

</body>

</html>