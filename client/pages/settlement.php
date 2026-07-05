<?php
$settlementContent = <<<'HTML'
<section class="settlement-container" aria-label="Settlement approvals">
    <div class="settlement-header">
        <div>
            <p class="settlement-eyebrow">Client Settlement</p>
            <h1>Settlement Management</h1>
            <p class="subtitle">Review and approve settlement formula changes for your properties.</p>
        </div>
        <button class="btn btn-outline" type="button" onclick="loadPendingRequests()">
            <i class="fas fa-sync"></i>
            <span>Refresh</span>
        </button>
    </div>

    <div id="alertContainer" aria-live="polite"></div>

    <div class="settlement-tabs" role="tablist" aria-label="Settlement request tabs">
        <button class="tab-btn active" type="button" role="tab" aria-selected="true" data-tab="pending" onclick="switchTab('pending')">
            <i class="fas fa-clock"></i>
            <span>Pending Approval</span>
            <span id="pendingCount" class="tab-badge">0</span>
        </button>
        <button class="tab-btn" type="button" role="tab" aria-selected="false" data-tab="history" onclick="switchTab('history')">
            <i class="fas fa-history"></i>
            <span>History</span>
        </button>
    </div>

    <div id="pendingSection" class="tab-content active" role="tabpanel">
        <div id="pendingRequests">
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading pending requests...</p>
            </div>
        </div>
    </div>

    <div id="historySection" class="tab-content" role="tabpanel">
        <div id="historyRequests">
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading history...</p>
            </div>
        </div>
    </div>
</section>

<div id="actionModal" class="modal" style="display:none;" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-header">
            <h3 id="modalTitle">Review Settlement Change</h3>
            <button class="modal-close" type="button" onclick="closeActionModal()" aria-label="Close modal">&times;</button>
        </div>
        <div class="modal-body">
            <div id="modalContent"></div>

            <div id="declineReasonContainer" class="decline-reason">
                <div class="form-group">
                    <label for="declineReason">Reason for Declining <span class="required">*</span></label>
                    <textarea id="declineReason" rows="4" placeholder="Please explain why you're declining this change..."></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" type="button" onclick="closeActionModal()">Cancel</button>
            <button class="btn btn-danger" type="button" id="declineBtn" onclick="confirmDecline()" style="display:none;">
                <i class="fas fa-times"></i>
                <span>Decline</span>
            </button>
            <button class="btn btn-success" type="button" id="approveBtn" onclick="confirmApprove()">
                <i class="fas fa-check"></i>
                <span>Approve</span>
            </button>
        </div>
    </div>
</div>

<div id="toastContainer" aria-live="polite"></div>
HTML;

ob_start();
include 'navbar.php';
$page = ob_get_clean();

$page = str_replace('<title>Rent Pilot</title>', '<title>Settlement Management | Client Portal</title>', $page);
$page = preg_replace(
    '/<div class="loading-spinner">\s*<div class="spinner"><\/div>\s*<\/div>/',
    $settlementContent,
    $page,
    1
);
$page = str_replace('</head>', '<link rel="stylesheet" href="../css/settlement.css"></head>', $page);
$page = str_replace('</body>', '<script src="../scripts/settlement.js"></script></body>', $page);

echo $page;
?>
