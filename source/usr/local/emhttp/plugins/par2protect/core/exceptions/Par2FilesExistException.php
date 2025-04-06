<?php
namespace Par2Protect\Core\Exceptions;

/**
 * Exception thrown when PAR2 creation is skipped because files already exist.
 */
class Par2FilesExistException extends \Exception {
    // You can add specific properties or methods if needed later
    public function __construct($message = "PAR2 files already exist, creation skipped.", $code = 0, \Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}