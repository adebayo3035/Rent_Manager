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
    
    <!-- Add New Property Button -->
    <button id="addNewPropertyBtn" class="addNewBtn">
        <i class="fa fa-plus" aria-hidden="true"></i> Add New Property
    </button>

    <!-- Live Search -->
    <div class="livesearch">
        <input type="text" id="propertyLiveSearch" placeholder="Search Properties...">
    </div>
</div>

<!-- Summary Table -->
<table id="propertySummary" class="summaryTable">
    <thead>
        <tr>
            <th>Property ID</th>
            <th>Property Name</th>
            <th>Type</th>
            <th>Agent</th>
            <th>Location</th>
            <th>Status</th>
            <th colspan="2">Actions</th>
        </tr>
    </thead>
    <tbody id="propertySummaryBody" class="summaryTableBody">
        <!-- Properties will be inserted here dynamically -->
    </tbody>
</table>

<!-- Pagination -->
<div id="propertyPagination" class="pagination"></div>


<!-- ========================= -->
<!-- Edit Property Modal -->
<!-- ========================= -->
<div id="propertyModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Edit Property</h2>

        <table id="propertyDetailsTable" class="summaryTable">
            <tbody>
                <!-- Property details will populate here -->
            </tbody>
        </table>
    </div>
</div>


<!-- ========================= -->
<!-- Add New Property Modal -->
<!-- ========================= -->
<div id="addNewPropertyModal" class="modal">
    <div class="modal-content" id="card-form">
        <span class="close2 close">&times;</span>
        <h2>Add New Property</h2>

        <form id="addPropertyForm" enctype="multipart/form-data">

            <div class="form-input">
                <label for="property_name">Property Name:</label>
                <input type="text" id="property_name" name="property_name" required>
            </div>

            <div class="form-input">
                <label for="agent_code">Agent:</label>
                <select id="agent_code" name="property_agent_code" required>
                    <!-- Filled from API -->
                </select>
            </div>

            <div class="form-input">
                <label for="property_type_id">Property Type:</label>
                <select id="property_type_id" name="property_type" required>
                    <!-- Filled from API -->
                </select>
            </div>
            <div class="form-input">
                <label for="property_country">Country:</label>
                <select id="property_country" name="property_country" required>
                    <!-- Filled from API -->
                </select>
            </div>
            <div class="form-input">
                <label for="property_state">State:</label>
                <select id="property_state" name="property_state" required>
                    <!-- Filled from API -->
                </select>
            </div>
            <div class="form-input">
                <label for="property_city">City:</label>
                <select id="property_city" name="property_city" required>
                    <!-- Filled from API -->
                </select>
            </div>

            <div class="form-input">
                <label for="property_address">Address:</label>
                <input type="text" id="location" name="property_address" required>
            </div>

            <div class="form-input">
                <label for="property_contact_name">Contact Name:</label>
                <input type="text" id="property_contact_name" name="property_contact_name" required>
            </div>
            <div class="form-input">
                <label for="property_contact_phone">Contact Phone Number:</label>
                <input type="text" id="property_contact_phone" name="property_contact_phone" required>
            </div>
            <div class="form-input">
                <label for="property_note">Additional Notes:</label>
                <textarea name="property_note" id="property_note" ></textarea>
            </div>
            <div class="form-group">
                    <label>PropertyPhoto</label>
                    <input type="file" id="propertyPhoto" name="property_photo" accept="image/*">
                </div>

                <div class="form-group">
                    <label>Preview</label>
                    <div id="photoPreview" style="width:120px;height:120px;border:1px solid #ccc;display:flex;
                        align-items:center;justify-content:center;overflow:hidden;">
                        <span style="font-size:12px;color:#777;">No image</span>
                    </div>
                </div>


            <button type="submit" id="submitBtnAddProperty" class="addNewBtn">
                Add Property
            </button>
        </form>

        <div id="addPropertyMessage"></div>
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

<script src="../scripts/property.js"></script>
<script src="../scripts/main.js"></script>
<script src="../../ui.js"></script>

</body>
</html>
