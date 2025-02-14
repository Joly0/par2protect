<?php
namespace Par2Protect\Exceptions;

class VerificationException extends \Exception {
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

class VerificationFailedException extends VerificationException {
    protected $errors;
    
    public function __construct($path, $errors, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Verification failed for path: ' . $path,
            $path,
            'verify',
            $details,
            $code,
            $previous
        );
        $this->errors = $errors;
    }
    
    public function getErrors() {
        return $this->errors;
    }
    
    public function getContext() {
        $context = parent::getContext();
        $context['errors'] = $this->errors;
        return $context;
    }
}

class VerificationParityException extends VerificationException {
    protected $parityFile;
    
    public function __construct($path, $parityFile, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Parity file error during verification for path: ' . $path,
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

class VerificationScheduleException extends VerificationException {
    protected $schedule;
    
    public function __construct($path, $schedule, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Verification schedule error for path: ' . $path,
            $path,
            'schedule',
            $details,
            $code,
            $previous
        );
        $this->schedule = $schedule;
    }
    
    public function getSchedule() {
        return $this->schedule;
    }
    
    public function getContext() {
        $context = parent::getContext();
        $context['schedule'] = $this->schedule;
        return $context;
    }
}

class VerificationLockException extends VerificationException {
    protected $lockFile;
    
    public function __construct($path, $lockFile, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Verification lock error for path: ' . $path,
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

class VerificationConfigException extends VerificationException {
    public function __construct($message, $details = null, $code = 0, \Throwable $previous = null) {
        parent::__construct(
            'Verification configuration error: ' . $message,
            null,
            'config',
            $details,
            $code,
            $previous
        );
    }
}