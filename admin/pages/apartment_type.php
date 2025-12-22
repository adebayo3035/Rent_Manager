<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apartment Types</title>
    <link rel="stylesheet" href="../../styles.css">
    
</head>

<body>
    <?php include('navbar.php'); ?>
    <div class="container">
        <h1>Manage Apartment Types</h1>
        <!-- Separate row for "Add New Customer" button -->
        
            <button id="addNewApartmentTypeBtn" class ="addNewBtnModal"><i class="fa fa-plus" aria-hidden="true"></i> Add New
                Apartment Type</button>
     
        <div class="livesearch">
            <input type="text" id="apartmentTypeLiveSearch" placeholder="Search for Apartment Type...">
            
        </div>


    </div>

    <table id="apartmentTypeSummary" class="summaryTable">
        <thead>
            <tr>
                <th>Type ID</th>
                <th>Apartment Type Name</th>
                <th>Status</th>
                <th colspan ="2">Actions</th>
            </tr>
        </thead>
        <tbody id ="apartmentTypeSummaryBody" class="summaryTableBody">
            <!-- ApartmentType Information will be dynamically inserted here -->
        </tbody>

    </table>
    <div id="apartmentTypePagination" class="pagination"></div>

    <div id="apartmentTypeModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Apartment Type Data</h2>

            
            <table id="apartmentTypeDetailsTable" class="summaryTable">
                <tbody>
                    <!-- Driver details will be automatically populated here -->
                </tbody>
            </table>
        </div>
    </div>


    <!-- Modal to add new Group -->
    <div id="addNewApartmentTypeModal" class="modal">
        <div class="modal-content" id="card-form">
            <span class="close2 close">&times;</span>
            <h2>Add New Apartment Type</h2>
            <form id="addApartmentTypeForm" name = "add_apartment_type_form">
                <div class="form-input">
                    <label for="add_apartment_type_name">Apartment Type Name:</label>
                    <input type="text" id="add_apartment_type_name" class ="validate" data-type ="text" name="add_apartment_type_name" required>
                </div>
                <div class="form-input">
                    <label for="add_apartment_type_description">Apartment Type Description:</label>
                    <input type="text" id="add_apartment_type_description" class="validate" data-type ="text" name="add_apartment_type_description" required>
                </div>
                
                <button type="submit" id="submitBtnAddApartmentType" class="addNewBtn btnSubmitAdd">Add Apartment Type</button>
            </form>

            <div id="addApartmentTypeMessage"></div>

        </div>
    </div>

    <!-- UI Library Containers -->
<div id="toastContainer"></div>

<!-- Alert Modal -->
<div id="alertModal" class="ui-modal">
    <div class="ui-modal-content">
        <h3 id="alertTitle">Alert</h3>
        <p id="alertMessage"></p>
        <button id="alertOkBtn">OK</button>
    </div>
</div>

<!-- Confirm Modal -->
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

<!-- Loader -->
<div id="uiLoaderOverlay">
    <div class="ui-loader"></div>
</div>


    <script src="../scripts/apartment_type.js"></script>
    <script src="../scripts/main.js"></script>
    <script src = "../../ui.js"></script>
    <script src = "../../validator.js"></script>
    <!-- <script src="scripts/group.js"></script> -->
</body>

</html>