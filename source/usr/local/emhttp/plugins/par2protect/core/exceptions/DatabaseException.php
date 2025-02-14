<?php
namespace Par2Protect\Core\Exceptions;

class DatabaseException extends \Exception {
    protected $query;
    protected $errorCode;
    protected $errorInfo;
    
    public function __construct($message, $query = null, $errorCode = null, $errorInfo = null, $code = 0, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->query = $query;
        $this->errorCode = $errorCode;
        $this->errorInfo = $errorInfo;
    }
    
    public function getQuery() {
        return $this->query;
    }
    
    public function getErrorCode() {
        return $this->errorCode;
    }
    
    public function getErrorInfo() {
        return $this->errorInfo;
    }
    
    public function getContext() {
        return [
            'query' => $this->query,
            'error_code' => $this->errorCode,
            'error_info' => $this->errorInfo
        ];
    }
}

class DatabaseConnectionException extends DatabaseException {
    public function __construct($message, $errorCode = null, $errorInfo = null, $code = 0, \Throwable $previous = null) {
        parent::__construct('Database connection error: ' . $message, null, $errorCode, $errorInfo, $code, $previous);
    }
}

class DatabaseQueryException extends DatabaseException {
    public function __construct($message, $query, $errorCode = null, $errorInfo = null, $code = 0, \Throwable $previous = null) {
        parent::__construct('Database query error: ' . $message, $query, $errorCode, $errorInfo, $code, $previous);
    }
}

class DatabaseTransactionException extends DatabaseException {
    protected $transactionState;
    
    public function __construct($message, $transactionState, $errorCode = null, $errorInfo = null, $code = 0, \Throwable $previous = null) {
        parent::__construct('Database transaction error: ' . $message, null, $errorCode, $errorInfo, $code, $previous);
        $this->transactionState = $transactionState;
    }
    
    public function getTransactionState() {
        return $this->transactionState;
    }
    
    public function getContext() {
        $context = parent::getContext();
        $context['transaction_state'] = $this->transactionState;
        return $context;
    }
}