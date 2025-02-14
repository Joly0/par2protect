<?php
namespace Par2Protect;

require_once("/usr/local/emhttp/plugins/par2protect/include/bootstrap.php");

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get initialized components
    $components = getInitializedComponents();
    $db = $components['db'];
    $fileOps = $components['fileOps'];
    $logger = $components['logger'];
    
    // Get Par2 instance
    $par2 = Par2::getInstance();
    
    $logger->debug("Generating status report");
    
    try {
        // Get protection stats
        $logger->debug("Fetching protection stats");
        $stats = Functions::getProtectionStats();
    } catch (\Exception $e) {
        $logger->error("Failed to get protection stats", ['exception' => $e]);
        $stats = [
            'total_files' => 0,
            'total_size' => '0 B',
            'health' => 'unknown',
            'last_verification' => null,
            'verification_errors' => 0
        ];
    }
    
    try {
        // Get system resources
        $logger->debug("Fetching system resources");
        $resources = Functions::getSystemResources();
    } catch (\Exception $e) {
        $logger->error("Failed to get system resources", ['exception' => $e]);
        $resources = [
            'cpu' => ['load_1' => 0, 'load_5' => 0, 'load_15' => 0],
            'memory' => ['total' => 0, 'free' => 0, 'available' => 0]
        ];
    }
    
    // Build HTML report
    $report = "<h4>Protection Status</h4>";
    $report .= "<p>Protected Files: " . ($stats['total_files'] ?? 0) . "</p>";
    $report .= "<p>Total Size: " . ($stats['total_size'] ?? '0 B') . "</p>";
    if (isset($stats['parity_size'])) {
        $report .= "<p>Parity Size: {$stats['parity_size']}</p>";
    }
    $report .= "<p>Last Verification: " . ($stats['last_verification'] ?: 'Never') . "</p>";
    
    // Add health status with color coding
    $health = $stats['health'] ?? 'unknown';
    $healthClass = match($health) {
        'good' => 'text-success',
        'warning' => 'text-warning',
        'error' => 'text-danger',
        default => 'text-info'
    };
    $report .= "<p>Health Status: <span class='{$healthClass}'>" . ucfirst($health) . "</span></p>";
    
    // Add verification errors if any
    if ($stats['verification_errors'] > 0) {
        $report .= "<p class='text-warning'>Verification Errors: {$stats['verification_errors']}</p>";
    }
    
    $report .= "<h4>System Resources</h4>";
    
    // Add CPU load with color coding
    $cpuLoad = isset($resources['cpu']['load_1']) ? $resources['cpu']['load_1'] * 100 : 0;
    $cpuClass = match(true) {
        $cpuLoad > 80 => 'text-danger',
        $cpuLoad > 50 => 'text-warning',
        default => 'text-success'
    };
    $load1 = $resources['cpu']['load_1'] ?? 0;
    $load5 = $resources['cpu']['load_5'] ?? 0;
    $load15 = $resources['cpu']['load_15'] ?? 0;
    $report .= "<p>CPU Load: <span class='{$cpuClass}'>{$load1}</span> (1m), {$load5} (5m), {$load15} (15m)</p>";
    
    // Add memory usage with percentage
    $memTotal = $resources['memory']['total'];
    $memAvailable = $resources['memory']['available'];
    $memUsed = $memTotal - $memAvailable;
    $memPercent = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 1) : 0;
    $memClass = match(true) {
        $memPercent > 90 => 'text-danger',
        $memPercent > 70 => 'text-warning',
        default => 'text-success'
    };
    $report .= "<p>Memory Usage: <span class='{$memClass}'>" . $fileOps->formatSize($memUsed) . " ({$memPercent}%)</span> of " . $fileOps->formatSize($memTotal) . "</p>";
    
    $report .= "<h4>Protected Paths</h4>";
    try {
        $logger->debug("Fetching protected paths");
        $paths = Functions::getProtectedPaths();
        if (!empty($paths)) {
            $report .= "<ul class='protected-paths'>";
            foreach ($paths as $path) {
                $exists = @file_exists($path);
                $statusClass = $exists ? 'text-success' : 'text-danger';
                $statusIcon = $exists ? '✓' : '✗';
                $report .= "<li><span class='{$statusClass}'>{$statusIcon}</span> " . htmlspecialchars($path) . "</li>";
            }
            $report .= "</ul>";
        } else {
            $report .= "<p class='text-muted'>No paths currently protected</p>";
        }
    } catch (\Exception $e) {
        $logger->error("Failed to get protected paths", ['exception' => $e]);
        $report .= "<p class='text-danger'>Failed to retrieve protected paths</p>";
    }
    
    // Add recent activity
    $report .= "<h4>Recent Activity</h4>";
    try {
        $logger->debug("Fetching recent activity");
        $recentActivity = $logger->getRecentActivity(5);
        if (!empty($recentActivity)) {
            $report .= "<ul class='recent-activity'>";
            foreach ($recentActivity as $activity) {
                $activityClass = $activity['status'] === 'error' ? 'text-danger' : 'text-success';
                $report .= "<li class='{$activityClass}'>";
                $report .= "<strong>" . htmlspecialchars($activity['time']) . "</strong>: " . htmlspecialchars($activity['action']);
                if (isset($activity['path'])) {
                    $report .= " - " . htmlspecialchars($activity['path']);
                }
                if (isset($activity['details'])) {
                    $report .= " <i class='fa fa-info-circle' title='" . htmlspecialchars($activity['details']) . "'></i>";
                }
                $report .= "</li>";
            }
            $report .= "</ul>";
        } else {
            $report .= "<p class='text-muted'>No recent activity</p>";
        }
    } catch (\Exception $e) {
        $logger->error("Failed to get recent activity", ['exception' => $e]);
        $report .= "<p class='text-danger'>Failed to retrieve recent activity</p>";
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'report' => $report,
            'stats' => $stats,
            'resources' => $resources
        ]
    ]);
    
} catch (\Exception $e) {
    $logger->error("Failed to generate status report", ['exception' => $e]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}