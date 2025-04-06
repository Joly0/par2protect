<?php
namespace Par2Protect\Core;

/**
 * Response class for handling API responses
 */
class Response {
    /**
     * Send JSON response
     *
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @return void
     */
    public static function json($data, $statusCode = 200) {
        // Set content type
        header('Content-Type: application/json');
        
        // Set cache control headers to prevent caching
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Set status code
        http_response_code($statusCode);
        
        // Output JSON
        echo json_encode($data);
        exit;
    }
    
    /**
     * Send success response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $statusCode HTTP status code
     * @return void
     */
    public static function success($data = null, $message = 'Success', $additionalData = [], $statusCode = 200) {
       $response = [
           'success' => true,
           'message' => $message
       ];
       
       if ($data !== null) {
           $response['data'] = $data;
       }
       
       // Add any additional data to the response
       if (is_array($additionalData) && !empty($additionalData)) {
           foreach ($additionalData as $key => $value) {
               $response[$key] = $value;
           }
       }
       
       self::json($response, $statusCode);
   }
    
    /**
     * Send error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param string $errorCode Error code
     * @param array $context Additional context
     * @return void
     */
    public static function error($message, $statusCode = 500, $errorCode = 'internal_error', $context = []) {
        $response = [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $message
            ]
        ];
        
        if (!empty($context)) {
            $response['error']['context'] = $context;
        }
        
        self::json($response, $statusCode);
    }
    
    /**
     * Send redirect response
     *
     * @param string $url URL to redirect to
     * @param int $statusCode HTTP status code
     * @return void
     */
    public static function redirect($url, $statusCode = 302) {
        header("Location: $url", true, $statusCode);
        exit;
    }
    
    /**
     * Send file response
     *
     * @param string $filePath Path to file
     * @param string $fileName File name
     * @param string $contentType Content type
     * @return void
     */
    public static function file($filePath, $fileName = null, $contentType = null) {
        if (!file_exists($filePath)) {
            self::error('File not found', 404, 'file_not_found');
        }
        
        // Get file name if not provided
        if ($fileName === null) {
            $fileName = basename($filePath);
        }
        
        // Get content type if not provided
        if ($contentType === null) {
            $contentType = mime_content_type($filePath);
            
            // Default to octet-stream if mime_content_type fails
            if (!$contentType) {
                $contentType = 'application/octet-stream';
            }
        }
        
        // Set headers
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output file
        readfile($filePath);
        exit;
    }
    
    /**
     * Send HTML response
     *
     * @param string $html HTML content
     * @param int $statusCode HTTP status code
     * @return void
     */
    public static function html($html, $statusCode = 200) {
        // Set content type
        header('Content-Type: text/html; charset=utf-8');
        
        // Set status code
        http_response_code($statusCode);
        
        // Output HTML
        echo $html;
        exit;
    }
}