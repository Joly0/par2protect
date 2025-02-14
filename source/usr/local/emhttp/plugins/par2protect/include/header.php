<?php
require_once("/usr/local/emhttp/plugins/par2protect/include/config.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/par2.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/functions.php");

// Initialize classes
$config = Par2Protect\Config::getInstance();
$par2 = Par2Protect\Par2::getInstance();
$logger = Par2Protect\Logger::getInstance();

// Get plugin version
$plugin_version = "2025.02.14";

// Common header function
function par2protect_header() {
    global $plugin_version;
    $header = <<<HTML
    <link type="text/css" rel="stylesheet" href="/plugins/par2protect/assets/css/par2protect.css">
    <script type="text/javascript" src="/webGui/javascript/jquery.min.js"></script>
    <script type="text/javascript" src="/plugins/par2protect/assets/js/par2protect.js"></script>
    <div class="title">
        <span class="left">PAR2Protect v{$plugin_version}</span>
        <span class="status" id="par2-status"></span>
    </div>
    HTML;
    return $header;
}