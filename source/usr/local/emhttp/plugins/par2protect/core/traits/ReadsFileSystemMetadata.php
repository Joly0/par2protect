<?php
namespace Par2Protect\Core\Traits;

/**
 * Trait providing functionality to read file system metadata.
 */
trait ReadsFileSystemMetadata {

    /**
     * Get metadata for a file (owner, group, permissions, xattrs).
     * Requires the class using this trait to have a $logger property available.
     *
     * @param string $filePath Path to the file.
     * @return array|false Array with 'owner', 'group', 'permissions', 'extended_attributes' keys, or false on failure.
     */
    protected function getFileMetadata(string $filePath) {
        // Ensure logger is available in the class using the trait
        if (!property_exists($this, 'logger') || !$this->logger instanceof \Par2Protect\Core\Logger) {
             // Log or throw an error if logger is not available? For now, return false.
             // error_log("ReadsFileSystemMetadata trait requires a Logger instance property named 'logger'.");
             return false;
        }
        if (!file_exists($filePath)) {
             $this->logger->warning("File not found when trying to get metadata", ['file_path' => $filePath]);
             return false;
        }

        try {
            // Get file owner and group
            $ownerInfo = posix_getpwuid(fileowner($filePath));
            $groupInfo = posix_getgrgid(filegroup($filePath));
            $owner = $ownerInfo['name'] ?? 'unknown';
            $group = $groupInfo['name'] ?? 'unknown';

            // Get file permissions
            $perms = fileperms($filePath);
            if ($perms === false) {
                 $this->logger->warning("Failed to get file permissions", ['file_path' => $filePath]);
                 return false;
            }
            $permissions = '';

            // Owner
            $permissions .= (($perms & 0x0100) ? 'r' : '-');
            $permissions .= (($perms & 0x0080) ? 'w' : '-');
            $permissions .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) : (($perms & 0x0800) ? 'S' : '-'));

            // Group
            $permissions .= (($perms & 0x0020) ? 'r' : '-');
            $permissions .= (($perms & 0x0010) ? 'w' : '-');
            $permissions .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) : (($perms & 0x0400) ? 'S' : '-'));

            // World
            $permissions .= (($perms & 0x0004) ? 'r' : '-');
            $permissions .= (($perms & 0x0002) ? 'w' : '-');
            $permissions .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) : (($perms & 0x0200) ? 'T' : '-'));

            // Get extended attributes if available
            $extendedAttributes = null;
            if (function_exists('xattr_list')) {
                try {
                    // Suppress warnings during xattr calls as they can occur for various reasons
                    $attrs = @xattr_list($filePath);
                    if ($attrs && is_array($attrs) && !empty($attrs)) {
                        $extendedAttributes = [];
                        foreach ($attrs as $attr) {
                            $value = @xattr_get($filePath, $attr);
                            if ($value !== false) { // Only store if retrieval was successful
                                $extendedAttributes[$attr] = $value;
                            } else {
                                $this->logger->debug("Failed to get value for xattr", ['file_path' => $filePath, 'attribute' => $attr]);
                            }
                        }
                        // Ensure it's null if empty after potential failures
                        if (empty($extendedAttributes)) {
                            $extendedAttributes = null;
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore exceptions with extended attributes, log as debug
                    $this->logger->debug("Exception getting extended attributes", [
                        'file_path' => $filePath,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return [
                'owner' => $owner,
                'group' => $group,
                'permissions' => $permissions,
                'extended_attributes' => $extendedAttributes
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get file metadata", [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}