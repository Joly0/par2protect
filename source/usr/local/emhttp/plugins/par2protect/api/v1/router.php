<?php
namespace Par2Protect\Api\V1;

use Par2Protect\Core\Exceptions\ApiException;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Response;

class Router {
    private $routes = [];
    private $logger;
    private $basePath = '/plugins/par2protect/api/v1';
    
    /**
     * Router constructor
     */
    public function __construct() {
        $this->logger = Logger::getInstance();
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
        
        $this->logger->debug("Dispatching request", [
            'method' => $method,
            'path' => $path,
            'uri' => $uri
        ]);
        
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
            
            $instance = new $controller();
            
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