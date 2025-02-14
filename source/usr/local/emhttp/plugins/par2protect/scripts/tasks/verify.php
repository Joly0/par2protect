<?php
namespace Par2Protect;

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once("/usr/local/emhttp/plugins/par2protect/include/bootstrap.php");

// Initialize logger early for error tracking
$logger = Logger::getInstance();
$logger->info("Starting verify.php script", [
    'post_data' => $_POST,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time')
]);

// Register error handler to catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) use ($logger) {
    $logger->error("PHP Error occurred", [
        'error_number' => $errno,
        'error_string' => $errstr,
        'error_file' => $errfile,
        'error_line' => $errline
    ]);
    return false; // Allow PHP's internal error handler to run
});

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get parameters from POST data
    $target = $_POST['target'] ?? null;
    $force = filter_var($_POST['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    $logger->info("Processing verification request", [
        'target' => $target,
        'force' => $force,
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true)
    ]);
    
    // Start verification task
    $verificationManager = VerificationManager::getInstance();
    
    $logger->debug("Calling verify method", [
        'class' => get_class($verificationManager),
        'method' => 'verify',
        'params' => ['target' => $target, 'force' => $force]
    ]);
    
    $result = $verificationManager->verify($target, $force);
    
    $logger->debug("Verify method returned", [
        'result_type' => gettype($result),
        'result_keys' => is_array($result) ? array_keys($result) : null
    ]);
    
    if (isset($result['stats'])) {
        $logger->info("Verification task completed", [
            'target' => $target,
            'stats' => $result['stats']
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? 'Verification task completed',
            'stats' => $result['stats']
        ]);
    } else if (isset($result['tasks'])) {
        $logger->info("Verification tasks started", [
            'target' => $target,
            'tasks' => $result['tasks']
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Verification tasks started successfully',
            'tasks' => $result['tasks']
        ]);
    } else {
        $logger->warning("Unexpected verification result format", [
            'target' => $target,
            'result' => $result
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Verification task started'
        ]);
    }
    
} catch (Exceptions\VerificationException $e) {
    $logger->error("Verification task failed", [
        'exception' => $e,
        'context' => $e->getContext(),
        'stack_trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'context' => $e->getContext()
    ]);
} catch (Exceptions\DatabaseException $e) {
    $logger->error("Database error during verification", [
        'exception' => $e,
        'context' => $e->getContext(),
        'stack_trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'context' => $e->getContext()
    ]);
} catch (\Exception $e) {
    $logger->error("Unexpected error during verification", [
        'exception' => $e,
        'error_type' => get_class($e),
        'stack_trace' => $e->getTraceAsString(),
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true)
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred: ' . $e->getMessage(),
        'error_type' => get_class($e)
    ]);
} finally {
    // Restore default error handler
    restore_error_handler();
    
    $logger->info("Verify.php script completed", [
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true)
    ]);
}