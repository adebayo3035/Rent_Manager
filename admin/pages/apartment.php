<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apartments</title>
    <link rel="stylesheet" href="../../styles.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

</head>

<body>

    <?php include('navbar.php'); ?>

    <div class="container">
        <h1>Manage Apartments</h1>

        <!-- Add New Apartment Button -->
        <button id="addNewApartmentBtn" class="addNewBtnModal">
            <i class="fa fa-plus" aria-hidden="true"></i> Add New Apartment
        </button>

        <!-- Live Search -->
        <div class="livesearch">
            <input type="text" id="apartmentLiveSearch" placeholder="Search Apartments...">
        </div>
    </div>

    <!-- Summary Table -->
    <table id="apartmentSummary" class="summaryTable">
        <thead>
            <tr>
                <th>Apartment ID</th>
                <th>Property Name</th>
                <th>Type</th>
                <th>Agent</th>
                <th>Status</th>
                <th colspan="2">Actions</th>
            </tr>
        </thead>
        <tbody id="apartmentSummaryBody" class="summaryTableBody">
            <!-- Apartments will be inserted here dynamically -->
        </tbody>
    </table>

    <!-- Pagination -->
    <div id="apartmentPagination" class="pagination"></div>


    <!-- ========================= -->
    <!-- Edit Apartment Modal -->
    <!-- ========================= -->
    <div id="apartmentModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Apartment</h2>

            <table id="apartmentDetailsTable" class="summaryTable">
                <tbody>
                    <!-- Apartment details will populate here -->
                </tbody>
            </table>
            <div id="editModalLoader" class="editModalLoader">
                <div class="spinner"></div>
                <span style="margin-left:10px;">Loading...</span>
            </div>

        </div>
    </div>


    <!-- ========================= -->
    <!-- Add New Apartment Modal -->
    <!-- ========================= -->
    <div id="addNewApartmentModal" class="modal">
        <div class="modal-content" id="card-form">
            <span class="close2 close">&times;</span>
            <h2>Add New Apartment</h2>

            <form id="addApartmentForm" name = "add_apartment_form" enctype="multipart/form-data">
                <div class="form-input">
                    <label for="property_code">Property Name:</label>
                    <select id="property_code" name="apartment_property_code" class="validate" data-type="text" required>
                        <!-- Filled from API -->
                    </select>
                </div>

                <div class="form-input">
                    <label for="agent_code">Agent:</label>
                    <select id="agent_code" name="apartment_agent_code" class="validate" data-type="text" required>
                        <!-- Filled from API -->
                    </select>
                </div>

                <div class="form-input">
                    <label for="apartment_type_id">Apartment Type:</label>
                    <select id="apartment_type_id" name="apartment_type" class="validate" data-type="select" required>
                        <!-- Filled from API -->
                    </select>
                </div>
                <div class="form-input">
                    <label for="apartment_type_unit">No. Of Units:</label>
                    <input id="apartment_type_unit" type ="number" name="apartment_type_unit" class="validate" data-type="number" required>
                </div>
                
                <button type="submit" id="submitBtnAddApartment" class="addNewBtn">
                    Add Apartment
                </button>
            </form>

            <div id="addApartmentMessage"></div>
        </div>
    </div>


    <!-- UI Framework Containers -->
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

    <script src="../scripts/apartment.js"></script>
    <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>
    <script src="../../validator.js"></script>

</body>

</html>