<?php
$agentsContent = <<<'HTML'
<section class="agents-container" aria-label="Property agents">
    <div class="page-header">
        <div>
            <p class="page-eyebrow">Client Portal</p>
            <h1><i class="fas fa-user-tie"></i> Property Agents</h1>
            <p>View and rate the agents managing your properties.</p>
        </div>
    </div>

    <div class="agents-grid" id="agentsGrid">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>Loading agents...</p>
        </div>
    </div>
</section>
HTML;

ob_start();
include 'navbar.php';
$page = ob_get_clean();

$page = preg_replace(
    '/<div class="loading-spinner">\s*<div class="spinner"><\/div>\s*<\/div>/',
    $agentsContent,
    $page,
    1
);

$agentModals = <<<'HTML'
    <div class="modal" id="agentDetailsModal" aria-hidden="true">
        <div class="modal-content modal-content-wide" role="dialog" aria-modal="true" aria-labelledby="agentDetailsTitle">
            <div class="modal-header">
                <h3 id="agentDetailsTitle">Agent Details</h3>
                <button class="modal-close" type="button" onclick="closeAgentDetailsModal()" aria-label="Close agent details">&times;</button>
            </div>
            <div class="modal-body" id="agentDetailsBody"></div>
            <div class="modal-footer">
                <button class="btn-secondary" type="button" onclick="closeAgentDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <div class="modal" id="rateAgentModal" aria-hidden="true">
        <div class="modal-content modal-content-narrow" role="dialog" aria-modal="true" aria-labelledby="rateAgentTitle">
            <div class="modal-header">
                <h3 id="rateAgentTitle">Rate Agent</h3>
                <button class="modal-close" type="button" onclick="closeRateAgentModal()" aria-label="Close rating form">&times;</button>
            </div>
            <div class="modal-body" id="rateAgentBody"></div>
            <div class="modal-footer">
                <button class="btn-secondary" type="button" onclick="closeRateAgentModal()">Cancel</button>
                <button class="btn-primary" type="button" onclick="submitRating()">Submit Rating</button>
            </div>
        </div>
    </div>
HTML;

$page = str_replace('</head>', '<link rel="stylesheet" href="../css/agents.css"></head>', $page);
$page = str_replace('</body>', $agentModals . '<script src="../scripts/agents.js"></script></body>', $page);

echo $page;
?>
