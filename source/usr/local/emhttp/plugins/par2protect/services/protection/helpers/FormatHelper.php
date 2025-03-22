<?php
namespace Par2Protect\Services\Protection\Helpers;

/**
 * Helper class for formatting protection-related data
 */
class FormatHelper {
    /**
     * Format protected item for API response
     *
     * @param array $item Protected item from database
     * @return array
     */
    public function formatProtectedItem($item) {
        return [
            'id' => $item['id'],
            'path' => $item['path'],
            'mode' => $item['mode'],
            'redundancy' => $item['redundancy'],
            'protected_date' => $item['protected_date'],
            'last_verified' => $item['last_verified'],
            'last_status' => $item['last_status'],
            'last_details' => $item['last_details'] ?? null,
            'size' => $item['size'], // Keep original size for backward compatibility
            'size_formatted' => $this->formatSizeDual($item['par2_size'] ?? 0, $item['data_size'] ?? $item['size']),
            'par2_size' => $item['par2_size'] ?? 0,
            'data_size' => $item['data_size'] ?? $item['size'],
            'par2_path' => $item['par2_path'],
            'file_types' => $item['file_types'] ? json_decode($item['file_types'], true) : null,
            'parent_dir' => $item['parent_dir'] ?? null,
            'protected_files' => $item['protected_files'] ? json_decode($item['protected_files'], true) : null
        ];
    }
    
    /**
     * Format sizes in dual human-readable format (protection files / data)
     *
     * @param int $par2Size Size of protection files in bytes
     * @param int $dataSize Size of protected data in bytes
     * @return string
     */
    public function formatSizeDual($par2Size, $dataSize) {
        return $this->formatSize($par2Size) . ' / ' . $this->formatSize($dataSize);
    }
    
    /**
     * Format size in human-readable format
     *
     * @param int $bytes Size in bytes
     * @return string
     */
    public function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Get a category name for file types
     *
     * @param array $fileTypes File types to categorize
     * @return string
     */
    public function getFileCategoryName($fileTypes) {
        // If no file types, return "all"
        if (!$fileTypes || empty($fileTypes)) {
            return 'all';
        }
        
        // Sort file types for consistent naming
        sort($fileTypes);
        
        // If there are too many file types, use a hash
        if (count($fileTypes) > 5) {
            return 'types-' . substr(md5(implode('-', $fileTypes)), 0, 8);
        }
        
        // Otherwise, use the file types directly
        return implode('-', $fileTypes);
    }
}