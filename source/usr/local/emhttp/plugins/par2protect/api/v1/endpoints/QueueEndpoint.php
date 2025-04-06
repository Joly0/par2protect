<?php
namespace Par2Protect\Api\V1\Endpoints;

use Par2Protect\Core\Response;
use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Services\Queue;

class QueueEndpoint {
    private $queueService;
    private $logger; // Add logger property

    /**
     * QueueEndpoint constructor
     */
    public function __construct(Queue $queueService, \Par2Protect\Core\Logger $logger) { // Inject Logger
        $this->queueService = $queueService;
        $this->logger = $logger; // Store logger instance
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
            $this->logger->debug('QueueEndpoint::add received POST data', ['post_data' => $data]); // Log POST data

            // Validate request
            if (!isset($data['operation_type']) || empty($data['operation_type'])) {
                $this->logger->warning('QueueEndpoint::add missing operation_type', ['post_data' => $data]);
                throw ApiException::badRequest("Operation type parameter is required");
            }

            if (!isset($data['parameters'])) { // Allow empty parameters string initially, check after decode
                $this->logger->warning('QueueEndpoint::add missing parameters key', ['post_data' => $data]);
                throw ApiException::badRequest("Parameters key is required");
            }

            $type = $data['operation_type'];
            $parametersRaw = $data['parameters']; // Keep raw parameters
            $this->logger->debug('QueueEndpoint::add raw parameters string', ['parameters_raw' => $parametersRaw]);

            // Attempt to decode parameters if it's a string
            if (is_string($parametersRaw)) {
                $parameters = json_decode($parametersRaw, true);
                $jsonError = json_last_error();
                $this->logger->debug('QueueEndpoint::add json_decode result', [
                    'parameters_decoded' => $parameters,
                    'json_last_error' => $jsonError,
                    'json_error_msg' => json_last_error_msg()
                ]);
                // Check for JSON decoding errors specifically
                if ($jsonError !== JSON_ERROR_NONE) {
                    $this->logger->error('QueueEndpoint::add JSON decode failed', [
                        'error_code' => $jsonError,
                        'error_message' => json_last_error_msg(),
                        'raw_parameters' => $parametersRaw
                    ]);
                    throw ApiException::badRequest("Parameters must be a valid JSON string: " . json_last_error_msg());
                }
            } elseif (is_array($parametersRaw)) {
                 $this->logger->debug('QueueEndpoint::add parameters received as array', ['parameters_array' => $parametersRaw]);
                 $parameters = $parametersRaw; // Already an array
            } else {
                 $this->logger->error('QueueEndpoint::add parameters are neither string nor array', ['parameters_type' => gettype($parametersRaw)]);
                 throw ApiException::badRequest("Parameters must be a JSON string or an array.");
            }

            // Final check if parameters resolved to an array
            if (!is_array($parameters)) {
                 $this->logger->error('QueueEndpoint::add parameters did not resolve to an array', ['resolved_parameters' => $parameters]);
                 throw ApiException::badRequest("Parameters must resolve to a valid JSON object or array");
            }
            // Also check if the resulting array is empty, if that's not allowed (adjust if empty is ok)
            if (empty($parameters)) {
                 $this->logger->warning('QueueEndpoint::add parameters resolved to an empty array', ['resolved_parameters' => $parameters]);
                 // Decide if empty parameters are allowed. If not, throw error:
                 // throw ApiException::badRequest("Parameters cannot be empty after decoding.");
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