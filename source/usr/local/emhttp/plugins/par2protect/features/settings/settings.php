<form markdown="1" method="POST" action="/plugins/par2protect/scripts/save_settings.php" target="progressFrame">

<input type="hidden" name="par2protect_form_submission" value="true">
<input type="hidden" name="par2protect_timestamp" value="<?=time()?>">
<input type="hidden" name="return_url" value="/Settings/PAR2ProtectSettings">

<div markdown="1" class="title">
Display Settings
</div>

<div class="settings-group">
<?php
// Get current settings
$place = $settings['display']['place'] ?? 'Tasks:95';
?>

<dl>
  <dt style="cursor: help">Menu Location:</dt>
  <dd>
    <select name="place">
      <?=mk_option($place, "Tasks:95", _("Header menu"))?>
      <?=mk_option($place, "SystemInformation", _("Tools menu"))?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  Controls where the PAR2Protect plugin appears in the Unraid interface.<br>
  <strong>Header menu:</strong> Shows the plugin in the top navigation bar.<br>
  <strong>Tools menu:</strong> Places the plugin under the Tools section.
</blockquote>
</div>

<dl>
  <dt>&nbsp;</dt>
  <dd>
    <input type="submit" value="Apply" class="category-apply">
  </dd>
</dl>

<div markdown="1" class="title">
Protection Settings
</div>

<div class="settings-group">
<dl>
  <dt style="cursor: help">Default Redundancy Level (%):</dt>
  <dd>
    <select name="default_redundancy">
      <?php
      $currentRedundancy = $settings['protection']['default_redundancy'] ?? 10;
      for($i=1; $i<=20; $i++):
      ?>
      <option value="<?=$i?>" <?=$currentRedundancy==$i?'selected':''?>><?=$i?></option>
      <?php endfor; ?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  The default percentage of redundancy to use when protecting files.<br>
  Higher values provide better protection against data corruption but require more storage space.<br>
  <strong>Recommended:</strong> 10% for most use cases, 5% for large media files, 15-20% for critical documents.
</blockquote>
<?php
// Parse the verification schedule
$cron = explode(' ', $settings['protection']['verify_cron'] ?? '-1');
// -1 means disabled
if ($cron[0] === '-1') {
    $schedule = -1; // Disabled
} else {
    $schedule = $cron[2]!='*' ? 3 : ($cron[4]!='*' ? 2 : (substr($cron[1],0,1)!='*' ? 1 : 0));
}
$scheduleMode = ['Disabled', 'Hourly', 'Daily', 'Weekly', 'Monthly'];
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>

<dl>
  <dt style="cursor: help">Verification Schedule:</dt>
  <dd>
    <select name="verify_schedule" onchange="presetVerify(this.form)">
      <?php for ($m=-1; $m<count($scheduleMode)-1; $m++): ?>
      <option value="<?=$m?>" <?=$schedule==$m?'selected':''?>><?=$scheduleMode[$m+1]?></option>
      <?php endfor; ?>
    </select>
    <input type="hidden" name="verify_cron" value="<?=$cron[0] === '-1' ? '-1' : implode(' ', $cron)?>">
  </dd>
</dl>

<blockquote class="inline_help">
  How often to automatically verify the integrity of protected files.<br>
  <strong>Disabled:</strong> No automatic verification will be performed.<br>
  <strong>Hourly:</strong> Most frequent verification, highest system resource usage.<br>
  <strong>Daily:</strong> Thorough protection with moderate resource usage.<br>
  <strong>Weekly:</strong> Balances protection and resource usage.<br>
  <strong>Monthly:</strong> Minimal resource usage, but longer time between integrity checks.
</blockquote>

<dl>
  <dt style="cursor: help">Day of the Week:</dt>
  <dd>
    <select name="verify_day" <?=$schedule!=2?'disabled':''?>>
      <?php for ($d=0; $d<count($days); $d++): ?>
      <option value="<?=$d?>" <?=$cron[4]==$d?'selected':''?>><?=$days[$d]?></option>
      <?php endfor; ?>
      <option value="*" <?=$cron[4]=='*'?'selected':''?> disabled>--------</option>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  Select which day of the week to run verification when using weekly schedule.<br>
  This setting is only active when "Weekly" is selected for the verification schedule.
</blockquote>

<dl>
  <dt style="cursor: help">Day of the Month:</dt>
  <dd>
    <select name="verify_dotm" <?=$schedule!=3?'disabled':''?>>
      <?php for ($d=1; $d<=31; $d++): ?>
      <option value="<?=$d?>" <?=$cron[2]==$d?'selected':''?>><?=sprintf("%02d", $d)?></option>
      <?php endfor; ?>
      <option value="*" <?=$cron[2]=='*'?'selected':''?> disabled>--------</option>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  Select which day of the month to run verification when using monthly schedule.<br>
  This setting is only active when "Monthly" is selected for the verification schedule.
</blockquote>

<dl>
  <dt style="cursor: help">Time of the Day:</dt>
  <dd>
    <span id="H1" <?=$schedule==0?'style="display:none"':''?>>
      <select name="verify_hour1" class="narrow">
        <?php for ($h=0; $h<=23; $h++): ?>
        <option value="<?=$h?>" <?=$cron[1]==$h?'selected':''?>><?=sprintf("%02d", $h)?></option>
        <?php endfor; ?>
      </select>
      <select name="verify_min" class="narrow">
        <?php for ($m=0; $m<=55; $m+=5): ?>
        <option value="<?=$m?>" <?=$cron[0]==$m?'selected':''?>><?=sprintf("%02d", $m)?></option>
        <?php endfor; ?>
      </select>&nbsp;&nbsp;HH:MM
    </span>
    <span id="H2" <?=$schedule!=0?'style="display:none"':''?>>
      <select name="verify_hour2">
        <option value="*/1" <?=$cron[1]=="*/1"?'selected':''?>>Every hour</option>
        <option value="*/2" <?=$cron[1]=="*/2"?'selected':''?>>Every 2 hours</option>
        <option value="*/3" <?=$cron[1]=="*/3"?'selected':''?>>Every 3 hours</option>
        <option value="*/4" <?=$cron[1]=="*/4"?'selected':''?>>Every 4 hours</option>
        <option value="*/6" <?=$cron[1]=="*/6"?'selected':''?>>Every 6 hours</option>
        <option value="*/8" <?=$cron[1]=="*/8"?'selected':''?>>Every 8 hours</option>
      </select>
    </span>
  </dd>
</dl>

<blockquote class="inline_help">
  Set the time when verification will run.<br>
  For hourly schedule, select how frequently verification runs throughout the day.<br>
  For daily, weekly, and monthly schedules, select the specific time of day to run verification.<br>
  It's recommended to set this to a time when your system is typically idle.
</blockquote>

<dl>
  <dt>&nbsp;</dt>
  <dd>
    <input type="submit" value="Apply" class="category-apply">
  </dd>
</dl>

<div markdown="1" class="title">
Resource Management
</div>

<div class="settings-group">
<dl>
  <dt style="cursor: help">Maximum CPU Usage (Threads):</dt>
  <dd>
    <select name="max_cpu_usage">
      <?php
      // Get total CPU threads
      $totalThreads = intval(trim(shell_exec('nproc')));
      if ($totalThreads <= 0) $totalThreads = 4; // Fallback if nproc fails
      
      // Current setting (default to par2 default)
      $currentCpu = $settings['resource_limits']['max_cpu_usage'] ?? '';
      
      // Add par2 default option
      ?>
      <option value="" <?=$currentCpu===''?'selected':''?>>par2 default</option>
      <?php
      // Generate options for all available threads
      for($i=1; $i<=$totalThreads; $i++):
      ?>
      <option value="<?=$i?>" <?=$currentCpu===$i?'selected':''?>><?=$i?></option>
      <?php endfor; ?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  Sets the number of CPU threads used for par2 operations (-t parameter).<br>
  Lower values reduce system impact but increase operation time.<br>
  <strong>par2 default:</strong> Let par2 determine the optimal thread count.<br>
  <strong>Recommended:</strong> Use "par2 default" or maximum available threads for best performance.
</blockquote>

<dl>
  <dt style="cursor: help">Maximum Memory Usage (MB):</dt>
  <dd>
    <input type="number" name="max_memory" min="0" value="<?=$settings['resource_limits']['max_memory_usage'] ?? ''?>" placeholder="Leave empty for par2 default">
  </dd>
</dl>

<blockquote class="inline_help">
  Limits how much memory the plugin can use during operations (-m parameter).<br>
  This helps prevent the plugin from using too much system memory.<br>
  <strong>Empty:</strong> Let par2 determine the optimal memory usage.<br>
  <strong>Recommended:</strong> Leave empty for most systems. Set a specific value only if you need to limit memory usage.
</blockquote>

<dl>
  <dt style="cursor: help">I/O Priority:</dt>
  <dd>
    <select name="io_priority">
      <?php
      $currentIo = $settings['resource_limits']['io_priority'] ?? 'low';
      $ioOptions = $schema['resource_limits']['settings']['io_priority']['options'] ?? ['high', 'normal', 'low'];
      foreach($ioOptions as $option):
      ?>
      <option value="<?=$option?>" <?=$currentIo==$option?'selected':''?>><?=ucfirst($option)?></option>
      <?php endforeach; ?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  Controls how the plugin accesses storage during operations.<br>
  <strong>High:</strong> Faster operations but may impact other disk activities.<br>
  <strong>Normal:</strong> Balanced performance.<br>
  <strong>Low:</strong> Minimal impact on other disk activities but slower operations.
</blockquote>

<dl>
  <dt style="cursor: help">Maximum Concurrent Operations:</dt>
  <dd>
    <select name="max_concurrent_operations">
      <?php
      $currentOps = $settings['resource_limits']['max_concurrent_operations'] ?? 2;
      for($i=1; $i<=5; $i++):
      ?>
      <option value="<?=$i?>" <?=$currentOps==$i?'selected':''?>><?=$i?></option>
      <?php endfor; ?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  The maximum number of protection or verification operations that can run simultaneously.<br>
  Higher values can process more files in parallel but use more system resources.<br>
  <strong>Recommended:</strong> 2 for most systems. Increase for systems with more CPU cores.
</blockquote>

<dl>
  <dt style="cursor: help">Parallel File Hashing:</dt>
  <dd>
    <select name="parallel_file_hashing">
      <?php
      $currentParallelHashing = $settings['resource_limits']['parallel_file_hashing'] ?? 2;
      for($i=1; $i<=8; $i++):
      ?>
      <option value="<?=$i?>" <?=$currentParallelHashing==$i?'selected':''?>><?=$i?></option>
      <?php endfor; ?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  Number of files to hash in parallel during par2 operations (-T parameter).<br>
  Higher values can improve performance for directories with many small files.<br>
  <strong>Recommended:</strong> 2-4 for most systems.
</blockquote>

<dl>
  <dt>&nbsp;</dt>
  <dd>
    <input type="submit" value="Apply" class="category-apply">
  </dd>
</dl>
</div>

<div markdown="1" class="title">
Notification & Logging Settings
</div>

<div class="settings-group">

<dl>
  <dt style="cursor: help">Unraid Notifications:</dt>
  <dd>
    <select name="notifications_enabled">
      <?php $notificationsEnabled = $settings['notifications']['enabled'] ?? true; ?>
      <option value="true" <?=$notificationsEnabled?'selected':''?>>Enabled</option>
      <option value="false" <?=!$notificationsEnabled?'selected':''?>>Disabled</option>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  When enabled, PAR2Protect will send notifications to the Unraid notification system.<br>
  Notifications will be sent for important events such as verification results.<br>
  <strong>Recommendation:</strong> Keep enabled to stay informed about the status of your protected files.
</blockquote>

<dl>
  <dt style="cursor: help">Warning & Error Logging:</dt>
  <dd>
    <select name="error_log_mode">
      <?php
      $currentMode = $settings['logging']['error_log_mode'] ?? 'both';
      $modeOptions = [
          'none' => 'Never',
          'logfile' => 'Log file only',
          'syslog' => 'Syslog only',
          'both' => 'Syslog and Log file'
      ];
      foreach($modeOptions as $value => $label):
      ?>
      <option value="<?=$value?>" <?=$currentMode==$value?'selected':''?>><?=$label?></option>
      <?php endforeach; ?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  Controls where warnings and errors are logged.<br>
  <strong>Never:</strong> Don't log warnings and errors.<br>
  <strong>Log file only:</strong> Log only to the plugin's log file.<br>
  <strong>Syslog only:</strong> Log only to the Unraid system log.<br>
  <strong>Syslog and Log file:</strong> Log to both the plugin's log file and the Unraid system log.
</blockquote>

<dl>
  <dt style="cursor: help">Debug Logging:</dt>
  <dd>
    <select name="debug_logging">
      <?php $debugLogging = $settings['debug']['debug_logging'] ?? false; ?>
      <option value="true" <?=$debugLogging?'selected':''?>>Enabled</option>
      <option value="false" <?=!$debugLogging?'selected':''?>>Disabled</option>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  When enabled, additional detailed logs are generated for troubleshooting.<br>
  This is useful when diagnosing problems but can generate large log files.<br>
  <strong>Recommendation:</strong> Keep disabled unless you're troubleshooting an issue.
</blockquote>

<dl>
  <dt style="cursor: help">Log Backup Interval:</dt>
  <dd>
    <select name="log_backup_interval">
      <?php
      $backupInterval = $settings['logging']['backup_interval'] ?? 'daily';
      $intervalOptions = [
          'hourly' => 'Hourly',
          'daily' => 'Daily',
          'weekly' => 'Weekly',
          'never' => 'Never'
      ];
      foreach($intervalOptions as $value => $label):
      ?>
      <option value="<?=$value?>" <?=$backupInterval==$value?'selected':''?>><?=$label?></option>
      <?php endforeach; ?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  Controls how often logs are backed up from temporary storage to persistent storage.<br>
  <strong>Hourly:</strong> Backup logs every hour (minimal data loss risk, higher system activity).<br>
  <strong>Daily:</strong> Backup logs once per day (recommended for most users).<br>
  <strong>Weekly:</strong> Backup logs once per week (minimal system impact, higher risk of data loss).<br>
  <strong>Never:</strong> Never backup logs (logs will be lost on system restart).
</blockquote>

<dl>
  <dt style="cursor: help">Log Retention (Days):</dt>
  <dd>
    <select name="log_retention_days">
      <?php
      $retentionDays = $settings['logging']['retention_days'] ?? 7;
      for($i=1; $i<=30; $i++):
      ?>
      <option value="<?=$i?>" <?=$retentionDays==$i?'selected':''?>><?=$i?></option>
      <?php endfor; ?>
    </select>
  </dd>
</dl>

<blockquote class="inline_help">
  How many days to keep logs before they are automatically deleted.<br>
  <strong>Recommended:</strong> 7 days for most users. Increase for more history or decrease to save disk space.
</blockquote>

<dl>
  <dt>&nbsp;</dt>
  <dd>
    <input type="submit" value="Apply" class="category-apply">
  </dd>
</dl>
</div>

<div markdown="1" class="title">
File Type Categories
</div>

<div class="settings-group">
<p>These file types are included when selecting a category on the dashboard. You can add custom file extensions to each category.</p>

<?php
// Get file categories from JavaScript
$fileCategories = [
    'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp'],
    'images' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp', 'svg'],
    'videos' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'mpeg', 'mpg'],
    'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'],
    'archives' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'],
    'code' => ['php', 'js', 'html', 'css', 'py', 'java', 'c', 'cpp', 'h', 'sh', 'json', 'xml', 'yml', 'yaml']
];

// Get custom extensions from config
$customExtensions = $settings['file_types']['custom_extensions'] ?? [
    'documents' => [], 'images' => [], 'videos' => [], 
    'audio' => [], 'archives' => [], 'code' => []
];

// Get Unraid theme background color for dynamic styling
global $display;
$bgcolor = strstr('white,azure', $display['theme']) ? '#f2f2f2' : '#1c1c1c';

// Category descriptions
$categoryDescriptions = [
    'documents' => 'Documents',
    'images' => 'Images',
    'videos' => 'Videos',
    'audio' => 'Audio',
    'archives' => 'Archives',
    'code' => 'Code Files'
];

// Display each category
foreach ($fileCategories as $category => $extensions) {
    $customExts = $customExtensions[$category] ?? [];
    $defaultSelected = $settings['file_types']['default_extensions'][$category] ?? $extensions;
    $defaultSelectedStr = implode("\n", $defaultSelected);

    // Category header
    echo "<h4 style='margin-top: 15px; margin-bottom: 5px; margin-right: 25%; text-align: center; font-size: larger;'>{$categoryDescriptions[$category]}</h4>";

    // Default extensions section
    echo "<dl>";
    echo "<dt style='cursor: help'>Default extensions:</dt>";
    echo "<dd>";
    echo "<div class='extension-picker-container' style='display: inline-block; position: relative;'>";
    echo "<input type='text' id='default_ext_{$category}_input' class='filetree-input' 
          data-category='{$category}' data-extensions='".htmlspecialchars(json_encode($extensions))."'
          readonly placeholder='Click to select extensions'>";
    echo "<input type='hidden' id='default_ext_{$category}' name='default_ext_{$category}' value='".htmlspecialchars($defaultSelectedStr)."' data-bgcolor='{$bgcolor}'>";
    echo "</div>";
    echo "</dd>"; 
    echo "</dl>";

    // Help text for default extensions
    echo "<blockquote class='inline_help'>";
    echo "Click to select which default extensions to include in this category.";
    echo "</blockquote>";

    // Custom extensions section
    echo "<dl>";
    echo "<dt style='cursor: help'>Custom extensions:</dt>";
    echo "<dd>";
    echo "<input type='text' name='custom_ext_{$category}' id='custom_ext_{$category}' value='" . implode(',', $customExts) . "' class='narrow' placeholder='e.g., ext1,ext2,ext3'>";
    echo "</dd>";
    echo "</dl>";

    // Help text for custom extensions
    echo "<blockquote class='inline_help'>";
    echo "Add custom extensions as a comma-separated list (e.g., 'ext1,ext2,ext3').";
    echo "</blockquote><br>";
}
?>

</div>

<dl>
  <dt>&nbsp;</dt>
  <dd>
    <input type="hidden" id="all_custom_extensions" name="custom_extensions" value="<?=htmlspecialchars(json_encode($customExtensions))?>">
    <input type="hidden" id="all_default_extensions" name="default_extensions" value="">
  </dd>
  <dd>
    <input type="submit" value="Apply">
  </dd>
</dl>
</form>

<script>
// Function to prepare the verification schedule for submission
function prepareVerify(form) {
    var mode = parseInt(form.verify_schedule.value);
    
    // If disabled, set special value
    if (mode === -1) {
        form.verify_cron.value = '-1';
        return;
    }
    
    var min = mode != 0 ? form.verify_min.value : 0;
    var hour = mode != 0 ? form.verify_hour1.value : form.verify_hour2.value;
    form.verify_cron.value = min + ' ' + hour + ' ' + form.verify_dotm.value + ' * ' + form.verify_day.value;
}

// Function to update the verification schedule UI based on selected mode
function presetVerify(form) {
    var mode = parseInt(form.verify_schedule.value);
    
    // Handle disabled state
    if (mode === -1) {
        $('#H1').show();
        $('#H2').hide();
        form.verify_min.disabled = true;
        form.verify_hour1.disabled = true;
        form.verify_day.disabled = true;
        form.verify_dotm.disabled = true;
        return;
    }
    
    form.verify_min.disabled = false;
    form.verify_hour1.disabled = false;
    form.verify_day.disabled = mode != 2;
    form.verify_dotm.disabled = mode != 3;
    form.verify_day.value = form.verify_day.disabled ? '*' : (form.verify_day.value == '*' ? 0 : form.verify_day.value);
    form.verify_dotm.value = form.verify_dotm.disabled ? '*' : (form.verify_dotm.value == '*' ? 1 : form.verify_dotm.value);
    
    if (mode == 0) {
        $('#H1').hide();
        $('#H2').show();
    } else {
        $('#H2').hide();
        $('#H1').show();
    }
}

$(function() {
    // Initialize the verification schedule UI
    presetVerify(document.forms[0]);
    
    // Handle form submission
    $('form').submit(function(e) {
        prepareVerify(this);
        // Let the form submit normally
    });
});
</script>