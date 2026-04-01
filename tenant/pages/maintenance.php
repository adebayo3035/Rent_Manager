<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Requests | Tenant Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/maintenance.css">
</head>
<body>
    <?php include('navbar.php'); ?>

    <!-- Create Request Modal -->
    <div class="modal" id="createModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Report Maintenance Issue</h3>
                <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="maintenanceForm">
                    <div class="form-group">
                        <label>Issue Type *</label>
                        <select id="issueType" required>
                            <option value="">Select issue type</option>
                            <option value="Plumbing">Plumbing (Leaks, Blockages)</option>
                            <option value="Electrical">Electrical (Lights, Outlets, Wiring)</option>
                            <option value="Appliances">Appliances (AC, Fridge, Water Heater)</option>
                            <option value="Furniture">Furniture (Damaged items)</option>
                            <option value="Pest Control">Pest Control (Rats, Cockroaches)</option>
                            <option value="Structural">Structural (Walls, Ceiling, Floor)</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority *</label>
                        <select id="priority" required>
                            <option value="low">Low - Can wait a few days</option>
                            <option value="medium">Medium - Needs attention soon</option>
                            <option value="high">High - Urgent, affects daily living</option>
                            <option value="emergency">Emergency - Immediate attention needed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description *</label>
                        <textarea id="description" rows="5" placeholder="Please describe the issue in detail..." required></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button class="btn-primary" onclick="submitMaintenanceRequest()">Submit Request</button>
            </div>
        </div>
    </div>

    <script src="../scripts/maintenance.js"></script>
</body>
</html>