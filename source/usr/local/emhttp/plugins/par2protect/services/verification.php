<?php
namespace Par2Protect\Services;

// Include the new Verification class and its dependencies
require_once __DIR__ . '/verification/Verification.php';
require_once __DIR__ . '/verification/VerificationRepository.php';
require_once __DIR__ . '/verification/VerificationOperations.php';
// require_once __DIR__ . '/verification/MetadataManager.php'; // Removed - Consolidated to Core

// For backward compatibility, we'll create a class with the same name in the same namespace
// that extends our new Verification class
class Verification extends Verification\Verification {
    // No need to add any code here, as we're just extending the new class
    // This maintains backward compatibility with existing code that uses Par2Protect\Services\Verification
}