<?php
namespace Par2Protect\Api\V1;

use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Response;

use Par2Protect\Core\Container; // Add use statement for Container

class Router {
    private $routes = [];
    private $logger;
    private $container; // Add property for container
    private $basePath = '/plugins/par2protect/api/v1';

    /**
     * Router constructor
     * @param Container $container The DI container instance
     */
    public function __construct(Container $container) {
        $this->container = $container;
        // Get logger from container if needed (e.g., for dispatch logging)
        $this->logger = $this->container->get('logger');
    }
    
    /**
     * Set base path for API
     *
     * @param string $path
     * @return void
     */
    public function setBasePath($path) {
        $this->basePath = $path;
    }
    
    /**
     * Add GET route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler (Controller@method or callable)
     * @return Router
     */
    public function get($path, $handler) {
        return $this->addRoute('GET', $path, $handler);
    }
    
    /**
     * Add POST route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler (Controller@method or callable)
     * @return Router
     */
    public function post($path, $handler) {
        return $this->addRoute('POST', $path, $handler);
    }
    
    /**
     * Add PUT route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler (Controller@method or callable)
     * @return Router
     */
    public function put($path, $handler) {
        return $this->addRoute('PUT', $path, $handler);
    }
    
    /**
     * Add DELETE route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler (Controller@method or callable)
     * @return Router
     */
    public function delete($path, $handler) {
        return $this->addRoute('DELETE', $path, $handler);
    }
    
    /**
     * Add route for any HTTP method
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|callable $handler Handler (Controller@method or callable)
     * @return Router
     */
    public function addRoute($method, $path, $handler) {
        $this->routes[$method][$path] = $handler;
        return $this;
    }
    
    /**
     * Dispatch request to appropriate handler
     *
     * @param string $uri Request URI
     * @return void
     */
    public function dispatch($uri) {
        $method = $_SERVER['REQUEST_METHOD'];
        // Use the request ID from index.php if available, otherwise generate a new one
        $requestId = isset($GLOBALS['requestId']) ? $GLOBALS['requestId'] : uniqid('req_');
        
        // Handle OPTIONS requests for CORS
        if ($method === 'OPTIONS') {
            $this->handleCors();
            exit;
        }
        
        // Parse path from URI
        $path = parse_url($uri, PHP_URL_PATH);
        
        // Remove base path
        if (strpos($path, $this->basePath) === 0) {
            $path = substr($path, strlen($this->basePath));
        }
        
        // Ensure path starts with /
        if (empty($path) || $path[0] !== '/') {
            $path = '/' . $path;
        }
        
        // Log detailed request information only for non-automatic requests
        // This helps reduce log spam from frequent automatic refreshes
        $isAutoRequest = isset($_GET['_manual']) && $_GET['_manual'] === 'false';
        $isRefreshRequest = isset($_GET['_caller']) && ($_GET['_caller'] === 'refreshProtectedList' || $_GET['_caller'] === 'updateStatus');
        
        // Only log if it's a manual request or if debug mode is explicitly enabled
        if ((!$isAutoRequest && !$isRefreshRequest) || (defined('DEBUG_MODE') && DEBUG_MODE)) {
            $this->logger->debug("API request received", [
                'request_id' => $requestId,
                'method' => $method,
                'uri' => $uri,
                'query' => $_GET,
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
                'referer' => $_SERVER['HTTP_REFERER'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        }
        
        // Find matching route
        $handler = null;
        $params = [];
        
        if (isset($this->routes[$method])) {
            // First try exact match
            if (isset($this->routes[$method][$path])) {
                $handler = $this->routes[$method][$path];
            } else {
                // Try to match routes with parameters
                foreach ($this->routes[$method] as $routePath => $routeHandler) {
                    $pattern = $this->pathToPattern($routePath);
                    if (preg_match($pattern, $path, $matches)) {
                        $handler = $routeHandler;
                        
                        // Extract named parameters
                        foreach ($matches as $key => $value) {
                            if (is_string($key)) {
                                $params[$key] = $value;
                            }
                        }
                        
                        break;
                    }
                }
            }
        }
        
        // If no handler found, return 404
        if ($handler === null) {
            $this->logger->warning("Endpoint not found", [
                'method' => $method,
                'path' => $path
            ]);
            
            throw ApiException::notFound("Endpoint not found: $method $path");
        }
        
        // Execute handler
        return $this->executeHandler($handler, $params);
    }
    
    /**
     * Execute route handler
     *
     * @param string|callable $handler Handler (Controller@method or callable)
     * @param array $params Route parameters
     * @return mixed
     */
    private function executeHandler($handler, $params = []) {
        if (is_callable($handler)) {
            // If handler is a callable, execute it directly
            return call_user_func_array($handler, [$params]);
        } else if (is_string($handler)) {
            // If handler is a string, parse controller and method
            list($controller, $method) = explode('@', $handler);
            
            // Add namespace if not present
            if (strpos($controller, '\\') === false) {
                $controller = "\\Par2Protect\\Api\\V1\\Endpoints\\$controller";
            }
            
            // Create controller instance
            if (!class_exists($controller)) {
                throw new ApiException("Controller not found: $controller", 500, 'controller_not_found');
            }
            
            // Get controller instance from container
            // Assumes controllers will be registered in the container later
            try {
                // Try with the original controller name
                if ($this->container->has($controller)) {
                    $instance = $this->container->get($controller);
                }
                // Try without the leading backslash if present
                else if (strpos($controller, '\\') === 0 && $this->container->has(substr($controller, 1))) {
                    $instance = $this->container->get(substr($controller, 1));
                }
                // Try with a leading backslash if not present
                else if (strpos($controller, '\\') !== 0 && $this->container->has('\\' . $controller)) {
                    $instance = $this->container->get('\\' . $controller);
                }
                else {
                    // Log available services for debugging
                    $this->logger->error("Controller not found in container", [
                        'controller' => $controller,
                        'controller_without_backslash' => strpos($controller, '\\') === 0 ? substr($controller, 1) : null,
                        'controller_with_backslash' => strpos($controller, '\\') !== 0 ? '\\' . $controller : null
                    ]);
                    throw new \Exception("Controller not found in container: $controller");
                }
            } catch (\Exception $e) {
                // Log the error
                $this->logger->error("Failed to get controller from container", [
                    'controller' => $controller,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw new ApiException("Failed to get controller from container: $controller - " . $e->getMessage(), 500, 'controller_not_found');
            }
            
            // Check if method exists
            if (!method_exists($instance, $method)) {
                throw new ApiException("Method not found: $controller::$method", 500, 'method_not_found');
            }
            
            // Execute method
            return call_user_func_array([$instance, $method], [$params]);
        }
        
        throw new ApiException("Invalid handler", 500, 'invalid_handler');
    }
    
    /**
     * Convert route path to regex pattern
     *
     * @param string $path Route path
     * @return string Regex pattern
     */
    private function pathToPattern($path) {
        // Replace named parameters with regex patterns
        $pattern = preg_replace('/:([a-zA-Z0-9_]+)/', '(?<$1>[^/]+)', $path);
        
        // Escape slashes and add start/end anchors
        $pattern = '#^' . $pattern . '$#';
        
        return $pattern;
    }
    
    /**
     * Handle CORS preflight requests
     *
     * @return void
     */
    private function handleCors() {
        // Allow requests from any origin
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400'); // 24 hours
        header('Content-Length: 0');
        http_response_code(204); // No content
    }
}