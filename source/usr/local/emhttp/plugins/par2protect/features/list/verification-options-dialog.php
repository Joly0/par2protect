<?php
// Get Unraid theme background color for dynamic styling
global $display;
$bgcolor = strstr('white,azure', $display['theme']) ? '#f2f2f2' : '#1c1c1c';
?>
<div id="verification-options-dialog" class="par2-dialog-verify" style="display:none">
    <div class="dialog-content" style="background:<?php echo $bgcolor; ?>">
        <h3>Verification Options</h3>
        <form id="verification-options-form">
            <div class="form-group">
                <label>
                    <input type="checkbox" name="verify_metadata" id="verify-metadata-checkbox">
                    Verify Metadata
                </label>
                <div class="help-text">
                    Verify file permissions, ownership, and extended attributes
                </div>
            </div>
            <div class="form-group" id="auto-restore-group" style="display:none; margin-left: 20px;">
                <label>
                    <input type="checkbox" name="auto_restore_metadata" id="auto-restore-metadata-checkbox">
                    Auto-restore Metadata
                </label>
                <div class="help-text">
                    Automatically restore metadata if discrepancies are found
                </div>
            </div>
            <div class="form-actions">
                <button type="submit">Start Verification</button>
                <button type="button" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>