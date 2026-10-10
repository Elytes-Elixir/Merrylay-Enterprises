<?php
/**
 * Router Class
 * 
 * Centralized routing logic for the application
 * Handles URL routing, middleware, and response handling
 */

class Router {
    private $routes = [];
    private $middleware = [];
    private $currentRoute = null;
    
    /**
     * Register a GET route
     * 
     * @param string $path URL path
     * @param callable $handler Route handler
     * @param array $middleware Middleware to apply
     */
    public function get($path, callable $handler, $middleware = []) {
        $this->addRoute('GET', $path, $handler, $middleware);
    }
    
    /**
     * Register a POST route
     * 
     * @param string $path URL path
     * @param callable $handler Route handler
     * @param array $middleware Middleware to apply
     */
    public function post($path, callable $handler, $middleware = []) {
        $this->addRoute('POST', $path, $handler, $middleware);
    }
    
    /**
     * Register a route for any method
     * 
     * @param string $path URL path
     * @param callable $handler Route handler
     * @param array $middleware Middleware to apply
     */
    public function any($path, callable $handler, $middleware = []) {
        $this->addRoute('*', $path, $handler, $middleware);
    }
    
    /**
     * Add a route to the router
     * 
     * @param string $method HTTP method
     * @param string $path URL path
     * @param callable $handler Route handler
     * @param array $middleware Middleware to apply
     */
    private function addRoute($method, $path, callable $handler, $middleware = []) {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }
    
    /**
     * Register global middleware
     * 
     * @param callable $middleware Middleware function
     */
    public function middleware(callable $middleware) {
        $this->middleware[] = $middleware;
    }
    
    /**
     * Dispatch the current request
     * 
     * @return void
     */
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Remove base path if present
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath !== '/' && strpos($uri, $basePath) === 0) {
            $uri = substr($uri, strlen($basePath));
        }
        
        // Find matching route
        foreach ($this->routes as $route) {
            if ($this->matchRoute($route, $method, $uri, $params)) {
                $this->currentRoute = $route;
                
                // Apply global middleware
                foreach ($this->middleware as $mw) {
                    $result = call_user_func($mw);
                    if ($result === false) {
                        return; // Middleware blocked the request
                    }
                }
                
                // Apply route-specific middleware
                foreach ($route['middleware'] as $mw) {
                    $result = call_user_func($mw);
                    if ($result === false) {
                        return; // Middleware blocked the request
                    }
                }
                
                // Execute route handler
                call_user_func_array($route['handler'], $params);
                return;
            }
        }
        
        // No route found - 404
        $this->notFound();
    }
    
    /**
     * Check if a route matches the current request
     * 
     * @param array $route Route definition
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param array $params Output parameter for route parameters
     * @return bool
     */
    private function matchRoute($route, $method, $uri, &$params) {
        if ($route['method'] !== '*' && $route['method'] !== $method) {
            return false;
        }
        
        $routePath = $route['path'];
        
        // Convert route path to regex pattern
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';
        
        if (preg_match($pattern, $uri, $matches)) {
            $params = array_slice($matches, 1);
            return true;
        }
        
        return false;
    }
    
    /**
     * Handle 404 Not Found
     * 
     * @return void
     */
    private function notFound() {
        http_response_code(404);
        if (defined('APP_ENV') && APP_ENV === 'development') {
            echo '<h1>404 Not Found</h1><p>No route found for: ' . htmlspecialchars($_SERVER['REQUEST_URI']) . '</p>';
        } else {
            echo '<h1>404 Not Found</h1>';
        }
    }
    
    /**
     * Redirect to a URL
     * 
     * @param string $url URL to redirect to
     * @param int $statusCode HTTP status code
     * @return void
     */
    public static function redirect($url, $statusCode = 302) {
        header('Location: ' . $url, true, $statusCode);
        exit();
    }
    
    /**
     * Get current route info
     * 
     * @return array|null
     */
    public function getCurrentRoute() {
        return $this->currentRoute;
    }
}
