<div id="dashboard-verification-options-dialog" class="par2-dialog-verify" style="display:none">
    <div class="dialog-content" style="background:<?php echo $bgcolor; ?>">
        <h3>Verification Options</h3>
        <form id="dashboard-verification-options-form">
            <div class="form-group">
                <dl>
                    <dt class="help-trigger" style="cursor: help">Verify Metadata:</dt>
                    <dd>
                        <input type="checkbox" name="verify_metadata" id="dashboard-verify-metadata-checkbox">
                    </dd>
                </dl>
                <blockquote class="inline_help" style="display: none;">
                    Verify file permissions, ownership, and extended attributes
                </blockquote>
            </div>
            <div class="form-group" id="dashboard-auto-restore-group" style="display:none; margin-left: 20px;">
                <dl>
                    <dt class="help-trigger" style="cursor: help">Auto-restore Metadata:</dt>
                    <dd>
                        <input type="checkbox" name="auto_restore_metadata" id="dashboard-auto-restore-metadata-checkbox">
                    </dd>
                </dl>
                <blockquote class="inline_help" style="display: none;">
                    Automatically restore metadata if discrepancies are found
                </blockquote>
            </div>
            <div class="form-actions">
                <button type="submit">Start Verification</button>
                <button type="button" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>