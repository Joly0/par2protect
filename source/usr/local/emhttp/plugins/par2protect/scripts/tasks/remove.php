<?php
namespace Par2Protect;

require_once("/usr/local/emhttp/plugins/par2protect/include/par2.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/functions.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/config.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/logging.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/database.php");
require_once("/usr/local/emhttp/plugins/par2protect/include/FileOperations.php");

header('Content-Type: application/json');

try {
    $logger = Logger::getInstance();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new \Exception('Invalid request method');
    }

    $paths = json_decode($_POST['paths'] ?? '[]', true);
    if (!is_array($paths)) {
        throw new \Exception('Invalid paths format');
    }

    $removedCount = 0;
    $errors = [];

    foreach ($paths as $path) {
        try {
            // Get protected item info before removing from database
            $items = Database::getProtectedItems();
            $logger->debug("Protected items:", ['items' => $items]);
            $item = array_filter($items, function($i) use ($path, $logger) {
                $logger->debug("Filtering item:", ['item' => $i, 'path' => $path]);
                return $i['path'] === $path;
            });
            
            if (!empty($item)) {
                $item = reset($item);
                $parityDir = $item['par2_path'];
                
                // Verify this is actually a .parity directory before removing
                if (!str_ends_with($parityDir, '/.parity')) {
                    throw new \Exception("Invalid parity directory path: $parityDir");
                }
                
                // Remove .parity directory and its contents
                if (is_dir($parityDir)) {
                    $logger->info("Removing parity directory", ['path' => $parityDir]);
                    
                    // Function to recursively remove directory and its contents
                    $removeDirectory = function($dir) use (&$removeDirectory, $logger) {
                        if (!is_dir($dir)) {
                            return;
                        }

                        // Extra safety check - only remove .parity directories
                        if (!str_ends_with($dir, '/.parity')) {
                            throw new \Exception("Attempted to remove non-parity directory: $dir");
                        }

                        $logger->debug("Cleaning directory", ['dir' => $dir]);
                        
                        // Get all files and subdirectories
                        $files = array_diff(scandir($dir), ['.', '..']);
                        
                        foreach ($files as $file) {
                            $path = $dir . '/' . $file;
                            
                            if (is_dir($path)) {
                                // Recursively remove subdirectories
                                $removeDirectory($path);
                            } else {
                                // Remove files
                                if (!unlink($path)) {
                                    throw new \Exception("Failed to delete file: $path");
                                }
                                $logger->debug("Removed file", ['file' => $path]);
                            }
                        }
                        
                        // Remove empty directory
                        if (!rmdir($dir)) {
                            throw new \Exception("Failed to remove directory: $dir");
                        }
                        $logger->debug("Removed directory", ['dir' => $dir]);
                    };
                    
                    // Remove the .parity directory and all its contents
                    $removeDirectory($parityDir);
                }
                
                // Remove from database after successful parity directory removal
                try {
                    Database::removeProtectedItem($path);
                    $removedCount++;
                    $logger->info("Removed protection", [
                        'path' => $path,
                        'parity_path' => $parityDir
                    ]);
                } catch (\Exception $e) {
                    throw new \Exception("Failed to remove database entry after removing parity files: " . $e->getMessage());
                }
            } else {
                throw new \Exception("Path not found in protected items: $path");
            }
        } catch (\Exception $e) {
            $errors[] = "Failed to remove protection from $path: " . $e->getMessage();
            $logger->error("Failed to remove protection", [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    if ($removedCount > 0 && !empty($errors)) {
        // Partial success
        $response = [
            'success' => true,
            'partial' => true,
            'message' => "Successfully removed protection from $removedCount item(s)",
            'errors' => $errors
        ];
        $logger->info("Partial success removing protection", $response);
        echo json_encode($response);
    } elseif ($removedCount === 0) {
        // All failed
        throw new \Exception("Failed to remove protection from any items: " . implode("; ", $errors));
    } else {
        // All succeeded
        $response = [
            'success' => true,
            'message' => "Successfully removed protection from $removedCount item(s)"
        ];
        $logger->info("Successfully removed all protections", $response);
        echo json_encode($response);
    }
} catch (\Exception $e) {
    $logger->error("Failed to process removal request", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}