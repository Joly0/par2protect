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
    $config = $components['config'];
    
    // Get Par2 instance
    $par2 = Par2::getInstance();
    
    // Get current status
    $status = $par2->getStatus();
    
    // Get protection stats from database
    $result = $db->query("
        SELECT
            COUNT(*) as total_items,
            SUM(size) as total_size,
            MAX(last_verified) as last_verification,
            SUM(CASE WHEN last_status = 'DAMAGED' OR last_status = 'ERROR' THEN 1 ELSE 0 END) as error_count,
            SUM(CASE WHEN last_status = 'PROTECTED' THEN 1 ELSE 0 END) as protected_count,
            SUM(CASE WHEN last_status = 'MISSING' THEN 1 ELSE 0 END) as missing_count,
            SUM(CASE WHEN last_status IS NULL OR last_status = 'UNKNOWN' THEN 1 ELSE 0 END) as unknown_count
        FROM protected_items
    ");
    $stats = $db->fetchOne($result);
    
    // Calculate health status
    $health = 'unknown';
    $totalItems = $stats['total_items'] ?? 0;
    if ($totalItems > 0) {
        $protectedCount = $stats['protected_count'] ?? 0;
        $errorCount = $stats['error_count'] ?? 0;
        $missingCount = $stats['missing_count'] ?? 0;
        
        if ($protectedCount === $totalItems) {
            $health = 'good';
        } else if ($errorCount > 0) {
            $health = 'error';
        } else if ($missingCount > 0) {
            $health = 'warning';
        }
    }
    
    // Get system resources
    $resources = Functions::getSystemResources();
    
    // Get resource limits
    $maxCpuUsage = $config->get('resource_limits.max_cpu_usage', 50);
    $maxOperations = $config->get('resource_limits.max_concurrent_operations', 2);
    $ioPriority = $config->get('resource_limits.io_priority', 'low');
    
    // Calculate resource usage percentages
    $cpuUsage = $resources['cpu']['load_1'] * 100;
    $memTotal = $resources['memory']['total'];
    $memUsed = $memTotal - $resources['memory']['available'];
    $memUsagePercent = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 1) : 0;
    
    // Process active operations
    $activeOperations = [];
    if ($status['running'] && !empty($status['processes'])) {
        foreach ($status['processes'] as $process) {
            $operation = [
                'pid' => $process['pid'],
                'cpu' => $process['cpu'],
                'memory' => $process['mem'],
                'command' => $process['command'],
                'operation_id' => $process['operation_id'] ?? null,
                'type' => 'Processing'
            ];
            
            // Determine operation type
            if (strpos($process['command'], 'par2 c') !== false) {
                $operation['type'] = 'Protecting';
            } elseif (strpos($process['command'], 'par2 v') !== false) {
                $operation['type'] = 'Verifying';
            } elseif (strpos($process['command'], 'par2 r') !== false) {
                $operation['type'] = 'Repairing';
            }
            
            // Extract progress if available
            if (preg_match('/(\d+)%/', $process['command'], $matches)) {
                $operation['progress'] = intval($matches[1]);
            }
            
            $activeOperations[] = $operation;
        }
    }
    
    // Get recent activity
    try {
        $recentActivity = $logger->getRecentActivity(5);
    } catch (\Exception $e) {
        $logger->error("Failed to get recent activity", ['exception' => $e]);
        $recentActivity = [];
    }
    
    $response = [
        'success' => true,
        'data' => [
            'stats' => [
                'total_files' => $stats['total_items'] ?? 0,
                'total_size' => $fileOps->formatSize($stats['total_size'] ?? 0),
                'last_verification' => $stats['last_verification'] ? date('Y-m-d H:i:s', strtotime($stats['last_verification'])) : null,
                'verification_errors' => $stats['error_count'] ?? 0,
                'health' => $health,
                'health_details' => [
                    'protected' => $stats['protected_count'] ?? 0,
                    'damaged' => $stats['error_count'] ?? 0,
                    'missing' => $stats['missing_count'] ?? 0,
                    'unknown' => $stats['unknown_count'] ?? 0
                ]
            ],
            'active_operations' => $activeOperations,
            'system_resources' => [
                'cpu' => [
                    'current' => round($cpuUsage, 1),
                    'limit' => $maxCpuUsage,
                    'load_1' => $resources['cpu']['load_1'],
                    'load_5' => $resources['cpu']['load_5'],
                    'load_15' => $resources['cpu']['load_15']
                ],
                'memory' => [
                    'total' => $fileOps->formatSize($memTotal),
                    'used' => $fileOps->formatSize($memUsed),
                    'available' => $fileOps->formatSize($resources['memory']['available']),
                    'usage_percent' => $memUsagePercent
                ],
                'limits' => [
                    'max_cpu_usage' => $maxCpuUsage,
                    'max_operations' => $maxOperations,
                    'io_priority' => $ioPriority
                ]
            ],
            'recent_activity' => $recentActivity
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    
} catch (\Exception $e) {
    $logger->error("Status check failed", ['exception' => $e]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}