<?php
namespace Par2Protect\Core\Exceptions;

class ApiException extends \Exception {
    private $statusCode;
    private $errorCode;
    private $context;
    
    /**
     * ApiException constructor
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param string $errorCode Error code
     * @param array $context Additional context
     */
    public function __construct($message, $statusCode = 500, $errorCode = 'internal_error', $context = []) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->context = $context;
    }
    
    /**
     * Get HTTP status code
     *
     * @return int
     */
    public function getStatusCode() {
        return $this->statusCode;
    }
    
    /**
     * Get error code
     *
     * @return string
     */
    public function getErrorCode() {
        return $this->errorCode;
    }
    
    /**
     * Get context
     *
     * @return array
     */
    public function getContext() {
        return $this->context;
    }
    
    /**
     * Convert exception to array for JSON response
     *
     * @return array
     */
    public function toArray() {
        return [
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'context' => $this->context
            ]
        ];
    }
    
    /**
     * Create a not found exception
     *
     * @param string $message
     * @param array $context
     * @return ApiException
     */
    public static function notFound($message = 'Resource not found', $context = []) {
        return new self($message, 404, 'not_found', $context);
    }
    
    /**
     * Create a bad request exception
     *
     * @param string $message
     * @param array $context
     * @return ApiException
     */
    public static function badRequest($message = 'Invalid request', $context = []) {
        return new self($message, 400, 'bad_request', $context);
    }
    
    /**
     * Create an unauthorized exception
     *
     * @param string $message
     * @param array $context
     * @return ApiException
     */
    public static function unauthorized($message = 'Unauthorized', $context = []) {
        return new self($message, 401, 'unauthorized', $context);
    }
    
    /**
     * Create a forbidden exception
     *
     * @param string $message
     * @param array $context
     * @return ApiException
     */
    public static function forbidden($message = 'Forbidden', $context = []) {
        return new self($message, 403, 'forbidden', $context);
    }
    
    /**
     * Create a validation exception
     *
     * @param string $message
     * @param array $context
     * @return ApiException
     */
    public static function validation($message = 'Validation failed', $context = []) {
        return new self($message, 422, 'validation_failed', $context);
    }
    
    /**
     * Create a server error exception
     *
     * @param string $message
     * @param array $context
     * @return ApiException
     */
    public static function serverError($message = 'Internal server error', $context = []) {
        return new self($message, 500, 'server_error', $context);
    }
}