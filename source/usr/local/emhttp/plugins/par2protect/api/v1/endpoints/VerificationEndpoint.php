<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Services\Verification;
use Par2Protect\Services\Queue;

class VerificationEndpoint {
    private $verificationService;
    private $queueService;
    
    /**
     * VerificationEndpoint constructor
     */
    public function __construct() {
        $this->verificationService = new Verification();
        $this->queueService = new Queue();
    }
    
    /**
     * Get verification status for a protected item
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getStatus($params) {
        try {
            if (!isset($params['path'])) {
                throw ApiException::badRequest("Path parameter is required");
            }
            
            $path = urldecode($params['path']);
            $status = $this->verificationService->getStatus($path);
            Response::success($status);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get verification status: " . $e->getMessage());
        }
    }
    
    /**
     * Verify a protected item
     *
     * @param array $params Request parameters
     * @return void
     */
    public function verify($params) {
        try {
            // Get request data
            $data = $_POST;
            
            // Validate request - either path or id must be provided
            if ((!isset($data['path']) || empty($data['path'])) && (!isset($data['id']) || empty($data['id']))) {
                throw ApiException::badRequest("Either path or id parameter is required");
            }
            
            // Get path and id parameters
            $path = isset($data['path']) ? $data['path'] : null;
            $id = isset($data['id']) ? intval($data['id']) : null;
            $force = isset($data['force']) ? (bool)$data['force'] : false;
            $verifyMetadata = isset($data['verify_metadata']) ? (bool)$data['verify_metadata'] : false;
            $autoRestoreMetadata = isset($data['auto_restore_metadata']) ? (bool)$data['auto_restore_metadata'] : false;
            $useQueue = isset($data['use_queue']) ? (bool)$data['use_queue'] : true;
            
            if ($useQueue) {
                // Add to queue
                $params = [
                    'force' => $force,
                    'verify_metadata' => $verifyMetadata,
                    'auto_restore_metadata' => $autoRestoreMetadata
                ];
                
                // Add either path or id or both to the parameters
                if ($path) {
                    $params['path'] = $path;
                }
                if ($id) {
                    $params['id'] = $id;
                }
                
                $result = $this->queueService->addOperation('verify', $params);
                
                Response::success($result, 'Verification operation added to queue');
            } else {
                // Direct execution
                if ($id) {
                    // If ID is provided, use it for verification
                    $result = $this->verificationService->verifyById($id, $force, $verifyMetadata, $autoRestoreMetadata);
                } else {
                    // Otherwise fall back to path-based verification
                    $result = $this->verificationService->verify($path, $force, $verifyMetadata, $autoRestoreMetadata);
                }
                
                // Add refresh_list flag to indicate the protected files list should be refreshed
                $result['refresh_list'] = true;
                $result['operation_type'] = 'verify';
                
                Response::success($result, 'Verification operation completed successfully');
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to verify item: " . $e->getMessage());
        }
    }
    
    /**
     * Repair a protected item
     *
     * @param array $params Request parameters
     * @return void
     */
    public function repair($params) {
        try {
            // Get request data
            $data = $_POST;
            
            // Validate request - either path or id must be provided
            if ((!isset($data['path']) || empty($data['path'])) && (!isset($data['id']) || empty($data['id']))) {
                throw ApiException::badRequest("Either path or id parameter is required");
            }
            
            // Get path and id parameters
            $path = isset($data['path']) ? $data['path'] : null;
            $id = isset($data['id']) ? intval($data['id']) : null;
            $restoreMetadata = isset($data['restore_metadata']) ? (bool)$data['restore_metadata'] : true;
            $useQueue = isset($data['use_queue']) ? (bool)$data['use_queue'] : true;
            
            if ($useQueue) {
                // Add to queue
                $params = [
                    'restore_metadata' => $restoreMetadata
                ];
                
                // Add either path or id or both to the parameters
                if ($path) {
                    $params['path'] = $path;
                }
                if ($id) {
                    $params['id'] = $id;
                }
                
                $result = $this->queueService->addOperation('repair', $params);
                
                Response::success($result, 'Repair operation added to queue');
            } else {
                // Direct execution
                if ($id) {
                    // If ID is provided, use it for repair
                    $result = $this->verificationService->repairById($id, $restoreMetadata);
                } else {
                    // Otherwise fall back to path-based repair
                    $result = $this->verificationService->repair($path, $restoreMetadata);
                }
                
                // Add refresh_list flag to indicate the protected files list should be refreshed
                $result['refresh_list'] = true;
                $result['operation_type'] = 'repair';
                
                Response::success($result, 'Repair operation completed successfully');
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to repair item: " . $e->getMessage());
        }
    }
}