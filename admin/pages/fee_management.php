<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Management | Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/fee_management.css">
    
</head>
<?php include('navbar.php'); ?>
<body>
     
    <div class="admin-wrapper">
       
        
        <main class="admin-main">
            <div class="fee-management-container">
                <div class="page-header">
                    <h1>Fee Management</h1>
                    <p>Configure fee types and property-specific fees</p>
                </div>
                
                <div class="tabs">
                    <button class="tab-btn active" data-tab="fee-types">Fee Types</button>
                    <button class="tab-btn" data-tab="property-fees">Property Fees</button>
                    <button class="tab-btn" data-tab="tenant-fees">Tenant Fees</button>
                </div>
                
                <!-- Fee Types Tab -->
                <div id="fee-types-tab" class="tab-content active">
                    <div class="section-header">
                        <h2>Fee Types</h2>
                        <button class="btn-primary" onclick="openFeeTypeModal()">
                            <i class="fas fa-plus"></i> Add Fee Type
                        </button>
                    </div>
                    <div id="feeTypesGrid" class="fee-types-grid">
                        <div class="loading-spinner"><div class="spinner"></div></div>
                    </div>
                </div>
                
                <!-- Property Fees Tab -->
                <div id="property-fees-tab" class="tab-content">
                    <div class="property-selector">
                        <label>Select Property</label>
                        <select id="propertySelect" class="filter-select">
                            <option value="">Select a property</option>
                        </select>
                    </div>
                    <div id="propertyFeesContent">
                        <div class="loading-spinner"><div class="spinner"></div></div>
                    </div>
                </div>
                
                <!-- Tenant Fees Tab -->
                <div id="tenant-fees-tab" class="tab-content">
                    <div class="filters">
                        <select id="tenantFeeStatusFilter" class="filter-select">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                            <option value="waived">Waived</option>
                        </select>
                        <input type="text" id="tenantSearch" placeholder="Search tenant..." class="filter-select">
                        <button class="btn-primary" onclick="searchTenantFees()">Search</button>
                    </div>
                    <div id="tenantFeesContent">
                        <div class="loading-spinner"><div class="spinner"></div></div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Fee Type Modal -->
    <div class="modal" id="feeTypeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="feeTypeModalTitle">Add Fee Type</h3>
                <button class="modal-close" onclick="closeFeeTypeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="feeTypeForm">
                    <input type="hidden" id="editFeeTypeId">
                    <div class="form-group">
                        <label>Fee Code *</label>
                        <input type="text" id="feeCode" required placeholder="e.g., SERVICE_CHARGE">
                    </div>
                    <div class="form-group">
                        <label>Fee Name *</label>
                        <input type="text" id="feeName" required placeholder="e.g., Service Charge">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea id="feeDescription" rows="3" placeholder="Optional description"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Is Mandatory?</label>
                        <select id="isMandatory">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Calculation Type</label>
                        <select id="calculationType">
                            <option value="fixed">Fixed Amount</option>
                            <option value="percentage">Percentage</option>
                            <option value="per_unit">Per Unit</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Is Recurring?</label>
                        <select id="isRecurring">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Recurrence Period</label>
                        <select id="recurrencePeriod">
                            <option value="one-time">One Time</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="annually">Annually</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" id="displayOrder" value="0">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeFeeTypeModal()">Cancel</button>
                <button class="btn-primary" onclick="saveFeeType()">Save</button>
            </div>
        </div>
    </div>

    <!-- View Tenant Fee Modal -->
<div id="viewTenantFeeModal" class="modal" onclick="closeModalOnOutsideClick(event, 'viewTenantFeeModal')">
    <div class="modal-content" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3><i class="fas fa-receipt"></i> Fee Details</h3>
            <button class="modal-close" onclick="closeViewTenantFeeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="tenantFeeDetails"></div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeViewTenantFeeModal()">Close</button>
            <button class="btn-primary" id="markPaidBtn" onclick="markFeeAsPaidFromModal()" style="display: none;">
                <i class="fas fa-check-circle"></i> Mark as Paid
            </button>
        </div>
    </div>
</div>

<!-- Custom Confirmation Modal -->
<div id="customConfirmModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <div class="confirm-icon" id="confirmIcon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 id="confirmTitle">Confirm Action</h3>
            <button class="modal-close" onclick="closeCustomConfirmModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p id="confirmMessage">Are you sure you want to proceed?</p>
            <div id="confirmDetails" class="confirm-details" style="display: none;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" id="confirmCancelBtn">Cancel</button>
            <button class="btn-primary" id="confirmOkBtn">Confirm</button>
        </div>
    </div>
</div>
    
    <script src = "../scripts/fee_management.js"> </script>
</body>
</html>
