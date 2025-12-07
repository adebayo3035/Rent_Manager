<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Properties</title>
    <link rel="stylesheet" href="../../styles.css">
    
</head>

<body>
    <?php include('navbar.php'); ?>
    <div class="container">
        <h1>Manage Properties</h1>
        <!-- Separate row for "Add New Customer" button -->
        
            <button id="addNewPropertyBtn" class ="addNewBtn"><i class="fa fa-plus" aria-hidden="true"></i> Add New
                Property</button>
     
        <div class="livesearch">
            <input type="text" id="propertyTypeLiveSearch" placeholder="Search for Property Type...">
            
        </div>


    </div>

    <table id="propertyTypeSummary" class="summaryTable">
        <thead>
            <tr>
                <th>Type ID</th>
                <th>Property Type Name</th>
                <th>Status</th>
                <th colspan ="2">Actions</th>
            </tr>
        </thead>
        <tbody id ="propertyTypeSummaryBody" class="summaryTableBody">
            <!-- PropertyType Information will be dynamically inserted here -->
        </tbody>

    </table>
    <div id="propertyTypePagination" class="pagination"></div>

    <div id="propertyTypeModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Property Type Data</h2>

            
            <table id="propertyTypeDetailsTable" class="summaryTable">
                <tbody>
                    <!-- Driver details will be automatically populated here -->
                </tbody>
            </table>
        </div>
    </div>


    <!-- Modal to add new Group -->
    <div id="addNewPropertyTypeModal" class="modal">
        <div class="modal-content" id="card-form">
            <span class="close2 close">&times;</span>
            <h2>Add New Property Type</h2>
            <form id="addPropertyTypeForm">
                <div class="form-input">
                    <label for="add_property_type_name">Property Type Name:</label>
                    <input type="text" id="add_property_type_name" name="add_property_type_name" required>
                </div>
                <div class="form-input">
                    <label for="add_property_type_description">Property Type Description:</label>
                    <input type="text" id="add_property_type_description" name="add_property_type_description" required>
                </div>
                
                <button type="submit" id="submitBtnAddPropertyType" class="addNewBtn">Add Property Type</button>
            </form>

            <div id="addPropertyTypeMessage"></div>

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


    <script src="../scripts/property.js"></script>
    <script src="../scripts/main.js"></script>
    <script src = "../../ui.js"></script>
    <!-- <script src="scripts/group.js"></script> -->
</body>

</html>