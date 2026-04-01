<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents | Tenant Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/documents.css">
</head>
<body>
    <?php include('navbar.php'); ?>

    <!-- Upload Document Modal -->
    <div class="modal" id="uploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Document</h3>
                <button class="modal-close" onclick="closeModal('uploadModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="uploadForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Document Type *</label>
                        <select id="docType" required>
                            <option value="">Select document type</option>
                            <option value="lease_agreement">Lease Agreement</option>
                            <option value="payment_receipt">Payment Receipt</option>
                            <option value="identification">Identification</option>
                            <option value="maintenance_report">Maintenance Report</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Document Name *</label>
                        <input type="text" id="docName" placeholder="Enter document name" required>
                    </div>
                    <div class="form-group">
                        <label>Select File * (PDF, DOC, JPG, PNG - Max 5MB)</label>
                        <input type="file" id="docFile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
                <button class="btn-primary" onclick="uploadDocument()">Upload</button>
            </div>
        </div>
    </div>

    <script src="../scripts/documents.js"></script>
</body>
</html>