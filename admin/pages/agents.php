<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Agents</title>
    <link rel="stylesheet" href="../../styles.css">
</head>

<body>
    <?php include('navbar.php'); ?>

    <div class="container">
        <h1>Manage Agents</h1>

        <button id="addNewAgentBtn" class="addNewBtn">
            <i class="fa fa-plus" aria-hidden="true"></i> Add New Agent
        </button>

        <div class="livesearch">
            <input type="text" id="agentLiveSearch" placeholder="Search for Agent...">
        </div>
    </div>

    <table id="agentSummary" class="summaryTable">
        <thead>
            <tr>
                <th>Agent ID</th>
                <th>Agent Name</th>
                <th>Email</th>
                <th>Status</th>
                <th colspan="2">Actions</th>
            </tr>
        </thead>
        <tbody id="agentSummaryBody" class="summaryTableBody">
            <!-- Data loads dynamically -->
        </tbody>
    </table>

    <div id="agentPagination" class="pagination"></div>

    <!-- View/Edit Modal -->
    <div id="agentModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Agent Information</h2>

            <table class="summaryTable" id="agentDetailsTable">
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Add New Agent Modal -->
    <div id="addAgentModal" class="modal">
        <div class="modal-content">
            <span class="close" id="addAgentClose">&times;</span>
            <h2>Add New Agent</h2>
            <form id="addAgentForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" id="agentFirstName" placeholder="Enter first name" name ="agent_firstname">
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" id="agentLastName" placeholder="Enter last name" name ="agent_lastname">
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="agentEmail" placeholder="Enter email address" name ="agent_email">
                </div>

                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" id="agentPhone" placeholder="Enter phone number" name ="agent_phone_number">
                </div>

                <div class="form-group">
                    <label>Address</label>
                    <textarea id="agentAddress" placeholder="Enter address" name = "agent_address"></textarea>
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <select id="agentGender" name ="agent_gender">
                        <option value="">Select gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Photo</label>
                    <input type="file" id="agentPhoto" name="agent_photo" accept="image/*">
                </div>

                <div class="form-group">
                    <label>Preview</label>
                    <div id="photoPreview" style="width:120px;height:120px;border:1px solid #ccc;display:flex;
                        align-items:center;justify-content:center;overflow:hidden;">
                        <span style="font-size:12px;color:#777;">No image</span>
                    </div>
                </div>

                <button id="saveAgentBtn" class="btn-primary">Save Agent</button>
            </form>
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

    <script src="../scripts/agent.js"></script>
    <script src="../scripts/main.js"></script>
    <script src="../../ui.js"></script>

</body>

</html>