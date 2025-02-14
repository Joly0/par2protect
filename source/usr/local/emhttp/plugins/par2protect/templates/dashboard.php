<?php
require_once("/usr/local/emhttp/plugins/par2protect/include/header.php");
echo par2protect_header();

// Get current status
$status = $par2->getStatus();
$stats = Par2Protect\Functions::getProtectionStats();
?>

<div class="par2-dashboard">
    <!-- Status Overview -->
    <div class="par2-status-card">
        <h4><i class="fa fa-info-circle"></i> Protection Status</h4>
        <div class="grid">
            <div class="grid-item">
                <span class="label">Protected Files:</span>
                <span class="value" id="protected-files"><?= $stats['total_files'] ?></span>
            </div>
            <div class="grid-item">
                <span class="label">Protected Size:</span>
                <span class="value" id="protected-size"><?= Par2Protect\Functions::formatSize($stats['total_size']) ?></span>
            </div>
            <div class="grid-item">
                <span class="label">Last Verification:</span>
                <span class="value" id="last-verification"><?= $stats['last_verification'] ? date('Y-m-d H:i', $stats['last_verification']) : 'Never' ?></span>
            </div>
            <div class="grid-item">
                <span class="label">Protection Health:</span>
                <span class="value" id="protection-health">
                    <div class="health-indicator <?= $stats['health_status'] ?>">
                        <?= ucfirst($stats['health_status']) ?>
                    </div>
                </span>
            </div>
        </div>
    </div>

    <!-- Active Operations -->
    <div class="par2-status-card">
        <h4><i class="fa fa-cogs"></i> Active Operations</h4>
        <div id="active-operations">
            <?php if (empty($status['processes'])): ?>
                <div class="notice">No active operations</div>
            <?php else: ?>
                <?php foreach ($status['processes'] as $process): ?>
                    <div class="operation-item">
                        <div class="operation-info">
                            <span class="operation-type"><?= $process['type'] ?></span>
                            <span class="operation-path"><?= $process['path'] ?></span>
                        </div>
                        <div class="operation-progress">
                            <div class="progress-bar" style="width: <?= $process['progress'] ?>%">
                                <?= $process['progress'] ?>%
                            </div>
                        </div>
                        <div class="operation-actions">
                            <button class="par2-button cancel-operation" data-pid="<?= $process['pid'] ?>">Cancel</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="par2-status-card">
        <h4><i class="fa fa-bolt"></i> Quick Actions</h4>
        <div class="action-buttons">
            <button class="par2-button par2-button-primary" onclick="Par2Protect.showProtectDialog()">
                <i class="fa fa-shield"></i> Protect Files
            </button>
            <button class="par2-button par2-button-secondary" onclick="Par2Protect.startVerification('all')">
                <i class="fa fa-check-circle"></i> Verify All
            </button>
            <button class="par2-button par2-button-info" onclick="Par2Protect.showStatusReport()">
                <i class="fa fa-file-text"></i> Status Report
            </button>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="par2-status-card">
        <h4><i class="fa fa-history"></i> Recent Activity</h4>
        <div id="recent-activity">
            <table class="par2-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Path</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="activity-log">
                    <?php foreach ($logger->getRecentActivity(10) as $activity): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i:s', $activity['timestamp']) ?></td>
                            <td><?= $activity['action'] ?></td>
                            <td><?= $activity['path'] ?></td>
                            <td>
                                <span class="status-badge <?= $activity['status'] ?>">
                                    <?= ucfirst($activity['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($activity['details'])): ?>
                                    <i class="fa fa-info-circle info-tooltip" title="<?= htmlspecialchars($activity['details']) ?>"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Protection Dialog -->
<div id="protect-dialog" class="par2-dialog" style="display: none;">
    <div class="dialog-content">
        <h3>Protect Files</h3>
        <form id="protect-form">
            <div class="form-group">
                <label>Path to Protect:</label>
                <div class="path-selector">
                    <input type="text" name="path" required>
                    <button type="button" onclick="Par2Protect.browseFolder()">Browse</button>
                </div>
            </div>
            <div class="form-group">
                <label>Protection Mode:</label>
                <select name="mode" onchange="Par2Protect.updateModeOptions(this.value)">
                    <option value="file">Individual Files</option>
                    <option value="directory">Entire Directory</option>
                </select>
            </div>
            <div class="form-group" id="file-type-group">
                <label>File Types:</label>
                <div class="checkbox-group">
                    <?php foreach (Par2Protect\Functions::getFileCategories() as $category => $info): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="file_types[]" value="<?= $category ?>">
                            <?= $info['description'] ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label>Redundancy Level:</label>
                <input type="range" name="redundancy" min="1" max="20" value="<?= $config->get('protection.default_redundancy') ?>">
                <span class="redundancy-value"></span>%
            </div>
            <div class="form-actions">
                <button type="submit" class="par2-button par2-button-primary">Start Protection</button>
                <button type="button" class="par2-button" onclick="Par2Protect.closeDialog()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    Par2Protect.initDashboard();
});
</script>