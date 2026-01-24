<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL Query Runner - Property Management</title>
    <!-- External Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/report.css">
    <link rel="stylesheet" href="../../styles.css">
    <style>
        /* Additional styles for better UX */
        .loading {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin: 10px 0;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        #runQueryBtn{
            display: none;
        }
    </style>
</head>
<body>
    <?php 
    include('navbar.php'); 
    ?>

    <div class="container">
        <div class="query-runner">
            <div class="security-warning">
                ⚠️ <strong>SECURITY WARNING:</strong> This tool allows direct SQL queries.
                Only SELECT statements are allowed. All queries are logged and monitored.
            </div>
            
            <div class="query-editor">
                <label for="queryTitle"><strong>Enter a Title for your Report</strong></label>
                <input type="text" name = "queryTitle" id ="queryTitle">
                <label for="sqlQuery"><strong>SQL Query (SELECT only):</strong></label>
                <textarea 
                    id="sqlQuery" 
                    placeholder="SELECT firstname, lastname, email FROM admin_tbl WHERE status = '1' LIMIT 10"
                    rows="8"
                    spellcheck="false"
                    style="width: 100%; padding: 10px; font-family: monospace;"
                ></textarea>

                 <!-- Error display -->
            <div id="errorAlert" style="display: none;" class="alert alert-danger"></div>
                
                <div class="query-controls">
                    <button id="validateBtn" class="btn btn-secondary">
                        Validate Syntax
                    </button>
                    <button id="runQueryBtn" class="btn btn-primary" >
                        Run Query
                    </button>
                    <button id="clearBtn" class="btn btn-outline">
                        Clear
                    </button>
                </div>
            </div>
            
            <!-- Query History -->
            <div class="query-history" id="queryHistory" style="display: none;">
                <h3>Recent Queries</h3>
                <ul id="historyList"></ul>
            </div>
            
            <!-- Loading indicator -->
            <div id="loading" style="display: none; text-align: center; padding: 20px;">
                <div class="loading"></div>
                <p>Processing query...</p>
            </div>
            
           
            
            <!-- Results -->
            <div id="resultsContainer" style="display: none;">
                <div class="results-header">
                    <span id="rowCount">Results: 0 rows</span>
                    <div class="export-options">
                        <button id="exportCsvBtn" class="btn btn-outline">
                            📊 CSV
                        </button>
                        <button id="exportExcelBtn" class="btn btn-outline">
                            📈 Excel
                        </button>
                        <button id="exportPdfBtn" class="btn btn-outline">
                            📄 PDF
                        </button>
                        <button id="printBtn" class="btn btn-outline">
                            🖨️ Print
                        </button>
                    </div>
                </div>
                
                <div class="results-table">
                    <table id="resultsTable" style="width: 100%; border-collapse: collapse;">
                        <thead id="tableHeader">
                            <!-- Headers will be inserted here -->
                        </thead>
                        <tbody id="tableBody">
                            <!-- Data will be inserted here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom JavaScript -->
    <script>
        // Store username for use in JavaScript
        document.body.setAttribute('data-username', 
            '<?php echo ($_SESSION["firstname"] ?? "Admin") . " " . ($_SESSION["lastname"] ?? "User"); ?>');
    </script>
    <script src="../scripts/report.js"></script>
</body>
</html>