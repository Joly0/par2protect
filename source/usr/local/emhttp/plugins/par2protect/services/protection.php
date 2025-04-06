<?php
namespace Par2Protect\Services;

// Include the new Protection class and its dependencies
require_once __DIR__ . '/protection/Protection.php';
require_once __DIR__ . '/protection/ProtectionRepository.php';
require_once __DIR__ . '/protection/ProtectionOperations.php';
// require_once __DIR__ . '/protection/MetadataManager.php'; // Removed - Consolidated to Core
require_once __DIR__ . '/protection/helpers/FormatHelper.php';

// For backward compatibility, we'll create a class with the same name in the same namespace
// that extends our new Protection class
class Protection extends Protection\Protection {
    // No need to add any code here, as we're just extending the new class
    // This maintains backward compatibility with existing code that uses Par2Protect\Services\Protection
}