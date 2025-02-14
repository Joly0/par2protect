<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Services\Queue;

class QueueEndpoint {
    private $queueService;
    
    /**
     * QueueEndpoint constructor
     */
    public function __construct() {
        $this->queueService = new Queue();
    }
    
    /**
     * Get all operations
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getAll($params) {
        try {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
            $operations = $this->queueService->getAllOperations($limit);
            Response::success($operations);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get operations: " . $e->getMessage());
        }
    }
    
    /**
     * Get operation status
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getStatus($params) {
        try {
            if (!isset($params['id'])) {
                throw ApiException::badRequest("Operation ID parameter is required");
            }
            
            $operationId = intval($params['id']);
            $status = $this->queueService->getOperationStatus($operationId);
            Response::success($status);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get operation status: " . $e->getMessage());
        }
    }
    
    /**
     * Add operation to queue
     *
     * @param array $params Request parameters
     * @return void
     */
    public function add($params) {
        try {
            // Get request data
            $data = $_POST;
            
            // Validate request
            if (!isset($data['operation_type']) || empty($data['operation_type'])) {
                throw ApiException::badRequest("Operation type parameter is required");
            }
            
            if (!isset($data['parameters']) || empty($data['parameters'])) {
                throw ApiException::badRequest("Parameters are required");
            }
            
            $type = $data['operation_type'];
            $parameters = is_array($data['parameters']) ? $data['parameters'] : json_decode($data['parameters'], true);
            
            if (!is_array($parameters)) {
                throw ApiException::badRequest("Parameters must be a valid JSON object or array");
            }
            
            // Add to queue
            $result = $this->queueService->addOperation($type, $parameters);
            
            // Make sure operation_id is included in the response
            if (isset($result['operation_id'])) {
                $result['operation_id'] = (int)$result['operation_id'];
            }
            
            Response::success($result, 'Operation added to queue');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to add operation to queue: " . $e->getMessage());
        }
    }
    
    /**
     * Cancel operation
     *
     * @param array $params Request parameters
     * @return void
     */
    public function cancel($params) {
        try {
            if (!isset($params['id'])) {
                throw ApiException::badRequest("Operation ID parameter is required");
            }
            
            $operationId = intval($params['id']);
            $result = $this->queueService->cancelOperation($operationId);
            Response::success($result, 'Operation cancelled successfully');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to cancel operation: " . $e->getMessage());
        }
    }
    
    /**
     * Get active operations
     *
     * @param array $params Request parameters
     * @return void
     */
    public function getActive($params) {
        try {
            $operations = $this->queueService->getActiveOperations();
            Response::success($operations);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to get active operations: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up old operations
     *
     * @param array $params Request parameters
     * @return void
     */
    public function cleanup($params) {
        try {
            $days = isset($_POST['days']) ? intval($_POST['days']) : 7;
            $count = $this->queueService->cleanupOldOperations($days);
            Response::success(['count' => $count], "Cleaned up $count old operations");
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to clean up old operations: " . $e->getMessage());
        }
    }
    
    /**
     * Kill stuck operation
     *
     * @param array $params Request parameters
     * @return void
     */
    public function killStuck($params) {
        try {
            if (!isset($_POST['operation_id']) && !isset($params['id'])) {
                throw ApiException::badRequest("Operation ID parameter is required");
            }
            
            $operationId = isset($_POST['operation_id']) ? intval($_POST['operation_id']) : intval($params['id']);
            $result = $this->queueService->cancelOperation($operationId);
            Response::success($result, 'Stuck operation killed successfully');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ApiException("Failed to kill stuck operation: " . $e->getMessage());
        }
    }
}