<?php
require_once("/usr/local/emhttp/plugins/par2protect/shared/components/FileTreePicker/FileTreePicker.php");
require_once("/usr/local/emhttp/webGui/include/Helpers.php");

// Get Unraid theme background color for dynamic styling
global $display;
$bgcolor = strstr('white,azure', $display['theme']) ? '#f2f2f2' : '#1c1c1c';
?>

<!-- Page-specific CSS -->
<link type="text/css" rel="stylesheet" href="/plugins/par2protect/features/dashboard/dashboard.css">

<!-- Page-specific JavaScript -->
<script src="/plugins/par2protect/features/dashboard/dashboard.js"></script>

<div class="error-message" id="error-display"></div>

<div class="par2-dashboard">
    <!-- Status Overview -->
    <div class="par2-status-card">
        <h4><i class="fa fa-info-circle"></i> Protection Status</h4>
        <div class="grid">
            <div class="grid-item">
                <span class="label">Protected Files:</span>
                <span class="value" id="protected-files">-</span>
            </div>
            <div class="grid-item">
                <span class="label">Protected Size:</span>
                <span class="value" id="protected-size">-</span>
            </div>
            <div class="grid-item">
                <span class="label">Last Verification:</span>
                <span class="value" id="last-verification">Never</span>
            </div>
            <div class="grid-item">
                <span class="label">Protection Health:</span>
                <span class="value" id="protection-health">
                    <div class="health-indicator">Unknown</div>
                </span>
            </div>
        </div>
    </div>

    <!-- Active Operations -->
    <div class="par2-status-card">
        <h4><i class="fa fa-cogs"></i> Active Operations</h4>
        <div id="active-operations">
            <div class="notice">No active operations</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="par2-status-card">
        <h4><i class="fa fa-bolt"></i> Quick Actions</h4>
        <div>
            <button onclick="Par2Protect.dashboard.showProtectDialog()">
                <i class="fa fa-shield"></i> Protect Files
            </button>
            <button onclick="Par2Protect.dashboard.startVerification('all')">
                <i class="fa fa-check-circle"></i> Verify All
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
                        <th style="border-bottom:none;">Time</th>
                        <th style="border-bottom:none;">Action</th>
                        <th style="border-bottom:none;">Path</th>
                        <th style="border-bottom:none;">Status</th>
                        <th style="border-bottom:none;">Details</th>
                    </tr>
                </thead>
                <tr class="separator">
                    <td colspan="5" style="padding: 0; border-bottom: 2px solid #666; height: 0;"></td>
                </tr>
                <tbody id="activity-log">
                    <tr class="activity-row template" style="display: none;">
                        <td class="time"></td>
                        <td class="action"></td>
                        <td class="path"></td>
                        <td class="status"></td>
                        <td class="details"></td>
                    </tr>
                    <!-- Additional rows will be cloned from template -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Protection Dialog -->
<div id="protect-dialog" class="par2-dialog" style="display:none; background:<?php echo $bgcolor; ?>">
    <div class="dialog-content">
        <h3>Protect Files and Folders</h3>
        
        <!-- Selected Folders List -->
        <div class="selected-folders-section">
            <h4>Selected Folders:</h4>
            <div class="selected-folders-list">
                <table class="par2-table" id="selected-folders-table">
                    <thead>
                        <tr>
                            <th>Path</th>
                            <th>Mode</th>
                            <th>File Types</th>
                            <th>Redundancy</th>
                            <th>Advanced</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="selected-folders-body">
                        <!-- Folders will be added here dynamically -->
                        <tr class="empty-row">
                            <td colspan="6">No folders selected yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Configuration Area -->
        <div class="configuration-section">
            <h4>Configuration:</h4>
            
            <!-- Folder Selection Area -->
            <div class="folder-selection-area">
                <h5>Select Folder(s):</h5>
                <?php
                echo \Par2Protect\Components\FileTreePicker::render(
                    'protectedPaths',
                    'protectedPaths',
                    '',
                    true,
                    true // Set foldersOnly to true
                );
                ?>
            </div>
            
            <!-- Settings Configuration -->
            <div class="settings-configuration">
                <h5>Settings for selected folder(s):</h5>
                <form id="folder-settings-form">
                    <div class="form-group">
                        <dl>
                            <dt class="help-trigger" style="cursor: help">Protection Mode:</dt>
                            <dd>
                                <?php $default_mode = "directory"; ?>
                                <select name="mode" id="protection-mode" onchange="Par2Protect.dashboard.updateModeOptions(this.value)">
                                    <?=mk_option($default_mode, "directory", _("Entire Directory"))?>
                                    <?=mk_option($default_mode, "file", _("Individual Files"))?>
                                </select>
                            </dd>
                        </dl>
                        <blockquote class="inline_help" style="display: none;">
                            Directory mode protects entire folders. Individual Files mode lets you select specific file types to protect.
                        </blockquote>
                    </div>
                    
                    <div class="form-group" id="file-type-group">
                        <dl>
                            <dt class="help-trigger" style="cursor: help">File Types:</dt>
                            <dd>
                                <div class="checkbox-group" id="file-types">
                                    <!-- Dynamically populated via JavaScript -->
                                </div>
                            </dd>
                        </dl>
                        <blockquote class="inline_help" style="display: none;">
                            Select which types of files to protect. Only applies when using Individual Files mode.
                        </blockquote>
                    </div>
                    
                    <div class="form-group">
                        <dl>
                            <dt class="help-trigger" style="cursor: help">Redundancy Level:</dt>
                            <dd>
                                <input type="range" name="redundancy" id="redundancy-slider" min="1" max="50" value="10">
                                <span class="redundancy-value">10</span>%
                            </dd>
                        </dl>
                        <blockquote class="inline_help" style="display: none;">
                            Higher redundancy provides better protection but uses more storage space.
                        </blockquote>
                    </div>
                    
                    <!-- Advanced Settings Toggle -->
                    <div class="form-group">
                        <dl>
                            <dt>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="advanced-settings-toggle">
                                    Advanced Settings
                                </label>
                            </dt>
                        </dl>
                    </div>
                    </br>
                    <!-- Advanced Settings Panel -->
                    <div id="advanced-settings-panel" class="settings-group" style="display:none;">
                        <div class="form-group">
                            <dl>
                                <dt style="cursor: help">Block-Count:</dt>
                                <dd>
                                    <input type="number" name="block_count" id="block-count" class="advanced-settings-input">
                                </dd>
                            </dl>
                            <blockquote class="inline_help" style="display: none;">
                                Sets the number of blocks to split the data into. More blocks can improve recovery options but may increase processing time. Max limit is 32768. Cannot be used together with Block-Size.
                            </blockquote>
                        </div>
                        <div class="form-group">
                            <dl>
                                <dt style="cursor: help">Block-Size (KB):</dt>
                                <dd>
                                    <input type="number" name="block_size" id="block-size" class="advanced-settings-input">
                                </dd>
                            </dl>
                            <blockquote class="inline_help" style="display: none;">
                                Sets the size of each block. Larger blocks reduce overhead but may be less efficient for small files. Cannot be used together with Block-Count.
                            </blockquote>
                        </div>
                        <div class="form-group">
                            <dl>
                                <dt style="cursor: help">Redundancy target size (MB):</dt>
                                <dd>
                                    <input type="number" name="target_size" id="target-size" class="advanced-settings-input">
                                </dd>
                            </dl>
                            <blockquote class="inline_help" style="display: none;">
                                Sets a specific target size for the redundancy data instead of using a percentage.
                            </blockquote>
                        </div>
                        <div class="warning-message">Advanced settings are for experienced users only! Incorrect settings may result in inefficient protection or excessive storage usage.</div>
                    </div>
                    
                    <button type="button" id="add-to-list-btn" class="btn-primary">Add to List</button>
                </div>
            </form>
        </div>
        
        <!-- Dialog Footer -->
        <div class="dialog-footer">
            <button type="button" id="start-protection-btn" class="btn-success">Start Protection</button>
            <button type="button" onclick="Par2Protect.dashboard.closeDialog()" class="btn-cancel">Cancel</button>
        </div>
    </div>
</div>

<!-- Include Verification Options Dialog -->
<?php include 'verification-options-dialog.php'; ?>