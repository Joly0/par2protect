<?php
namespace Par2Protect\Exceptions;

class ProtectionException extends \Exception {
    protected $path;
    protected $operation;
    protected $details;
    
    public function __construct($message, $path = null, $operation = null, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->path = $path;
        $this->operation = $operation;
        $this->details = $details;
    }
    
    public function getPath() {
        return $this->path;
    }
    
    public function getOperation() {
        return $this->operation;
    }
    
    public function getDetails() {
        return $this->details;
    }
    
    public function getContext() {
        return [
            'path' => $this->path,
            'operation' => $this->operation,
            'details' => $this->details
        ];
    }
}

class ProtectionCreateException extends ProtectionException {
    public function __construct($path, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Failed to create protection for path: ' . $path,
            $path,
            'create',
            $details,
            $code,
            $previous
        );
    }
}

class ProtectionUpdateException extends ProtectionException {
    public function __construct($path, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Failed to update protection for path: ' . $path,
            $path,
            'update',
            $details,
            $code,
            $previous
        );
    }
}

class ProtectionRemoveException extends ProtectionException {
    public function __construct($path, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Failed to remove protection for path: ' . $path,
            $path,
            'remove',
            $details,
            $code,
            $previous
        );
    }
}

class ProtectionConfigException extends ProtectionException {
    public function __construct($message, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Protection configuration error: ' . $message,
            null,
            'config',
            $details,
            $code,
            $previous
        );
    }
}

class ProtectionParityException extends ProtectionException {
    protected $parityFile;
    
    public function __construct($path, $parityFile, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Parity file error for path: ' . $path,
            $path,
            'parity',
            $details,
            $code,
            $previous
        );
        $this->parityFile = $parityFile;
    }
    
    public function getParityFile() {
        return $this->parityFile;
    }
    
    public function getContext() {
        $context = parent::getContext();
        $context['parity_file'] = $this->parityFile;
        return $context;
    }
}

class ProtectionLockException extends ProtectionException {
    protected $lockFile;
    
    public function __construct($path, $lockFile, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Protection lock error for path: ' . $path,
            $path,
            'lock',
            $details,
            $code,
            $previous
        );
        $this->lockFile = $lockFile;
    }
    
    public function getLockFile() {
        return $this->lockFile;
    }
    
    public function getContext() {
        $context = parent::getContext();
        $context['lock_file'] = $this->lockFile;
        return $context;
    }
}