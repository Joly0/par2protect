<?php
$bgcolor = strstr('white,azure', $display['theme']) ? '#f2f2f2' : '#1c1c1c';
?>
<!-- Load verification options JS -->
<script src="/plugins/par2protect/features/list/verification-options.js"></script>

<div class="error-message" id="error-display"></div>

<div class="protected-files-list">
    <div class="action-bar">
        <div class="left-actions">
            <button onclick="Par2Protect.list.removeSelectedProtections()" disabled id="removeSelectedBtn">
                <i class="fa fa-trash"></i> Remove Selected
            </button>
            <button onclick="Par2Protect.list.verifySelected(null, true)" disabled id="verifySelectedBtn"> 
                <i class="fa fa-check-circle"></i> Verify Selected
            </button>
            <button onclick="Par2Protect.list.reprotectSelected()" disabled id="reprotectSelectedBtn">
                <i class="fa fa-refresh"></i> Re-protect Selected
            </button>
        </div>
        <div class="right-actions">
            <select id="statusFilter" class="filter-dropdown" onchange="Par2Protect.list.filterByStatus(this.value)">
                <option value="all">All Statuses</option>
                <option value="protected">Protected</option>
                <option value="unprotected">Unprotected</option>
                <option value="damaged">Damaged</option>
                <option value="repaired">Repaired</option>
                <option value="verified">Verified</option>
                <option value="missing">Missing</option>
                <option value="error">Error</option>
            </select>
            <select id="modeFilter" class="filter-dropdown" onchange="Par2Protect.list.filterByMode(this.value)">
                <option value="all">All Types</option>
                <option value="file">Files</option>
                <option value="directory">Directories</option>
            </select>
            <button id="refresh-list-btn">
                <i class="fa fa-refresh"></i> Refresh
            </button>
        </div>
    </div>

    <table class="protected-files-table">
        <thead>
            <tr>
                <th class="checkbox-column">
                    <input type="checkbox" id="selectAll" onchange="Par2Protect.list.toggleSelectAll(this)">
                </th>
                <th style="display:none;">ID</th>
                <th>Path</th>
                <th class="mode-column">Type</th>
                <th class="redundancy-column">Redundancy</th>
                <th class="size-column">Size <i class="fa fa-info-circle size-info-icon" title="Size format: Protection Files / Protected Data. The first value shows the size of the PAR2 protection files, and the second value shows the size of the actual protected data."></i></th>
                <th class="status-column">Status</th>
                <th class="date-column">Protected Date</th>
                <th class="date-column">Last Verified</th>
                <th class="actions-column">Actions</th>
            </tr>
        </thead>
        <tbody id="protected-files-list">
            <!-- Dynamically populated via JavaScript -->
        </tbody>
    </table>
</div>

<script>
$(function() {
    // Don't show loading for initial automatic refresh
    Par2Protect.list.refreshProtectedList(false);
});
</script>

<!-- Include Verification Options Dialog -->
<?php include 'verification-options-dialog.php'; ?>