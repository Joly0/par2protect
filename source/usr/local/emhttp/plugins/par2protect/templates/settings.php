<?php
require_once 'base.php';
$config = Par2Protect\Config::getInstance();
?>

<form id="par2-settings-form" method="POST">
    <div class="par2-status-card">
        <h4>General Settings</h4>
        
        <div class="form-group">
            <label>PAR2 Storage Location:</label>
            <input type="text" name="paths[par2_storage]" class="form-control" 
                   value="<?= htmlspecialchars($config->get('paths.par2_storage')) ?>">
            <small class="form-text text-muted">Location where PAR2 files will be stored</small>
        </div>

        <div class="form-group">
            <label>Default Redundancy Level (%):</label>
            <input type="number" name="protection[default_redundancy]" class="form-control" 
                   min="1" max="100" value="<?= $config->get('protection.default_redundancy', 5) ?>">
        </div>

        <div class="form-group">
            <label>Verification Schedule:</label>
            <select name="protection[verify_schedule]" class="form-control">
                <option value="daily" <?= $config->get('protection.verify_schedule') === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $config->get('protection.verify_schedule') === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $config->get('protection.verify_schedule') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            </select>
        </div>
    </div>

    <div class="par2-status-card">
        <h4>Resource Management</h4>
        
        <div class="form-group">
            <label>Maximum CPU Usage (%):</label>
            <input type="number" name="resource_limits[max_cpu_usage]" class="form-control" 
                   min="1" max="100" value="<?= $config->get('resource_limits.max_cpu_usage', 50) ?>">
        </div>

        <div class="form-group">
            <label>Maximum Concurrent Operations:</label>
            <input type="number" name="resource_limits[max_concurrent_operations]" class="form-control" 
                   min="1" max="8" value="<?= $config->get('resource_limits.max_concurrent_operations', 2) ?>">
        </div>

        <div class="form-group">
            <label>I/O Priority:</label>
            <select name="resource_limits[io_priority]" class="form-control">
                <option value="high" <?= $config->get('resource_limits.io_priority') === 'high' ? 'selected' : '' ?>>High</option>
                <option value="normal" <?= $config->get('resource_limits.io_priority') === 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="low" <?= $config->get('resource_limits.io_priority') === 'low' ? 'selected' : '' ?>>Low</option>
            </select>
        </div>
    </div>

    <div class="par2-status-card">
        <h4>Notification Settings</h4>
        
        <div class="form-group">
            <label>Notification Level:</label>
            <select name="notifications[level]" class="form-control">
                <option value="all" <?= $config->get('notifications.level') === 'all' ? 'selected' : '' ?>>All Events</option>
                <option value="warnings" <?= $config->get('notifications.level') === 'warnings' ? 'selected' : '' ?>>Warnings & Errors</option>
                <option value="errors" <?= $config->get('notifications.level') === 'errors' ? 'selected' : '' ?>>Errors Only</option>
            </select>
        </div>
    </div>

    <div class="text-right">
        <button type="submit" class="par2-button par2-button-primary">Save Settings</button>
    </div>
</form>

<script>
$(function() {
    $('#par2-settings-form').on('submit', function(e) {
        e.preventDefault();
        $.post('/plugins/par2protect/include/exec.php', $(this).serialize(), function(response) {
            if (response.success) {
                Par2Protect.showAlert('success', 'Settings saved successfully');
            } else {
                Par2Protect.showAlert('danger', 'Failed to save settings: ' + response.error);
            }
        });
    });
});
</script>