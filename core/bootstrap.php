<?php
/**
 * Core Bootstrap File
 * 
 * Loads core classes and service classes for the application.
 * Include this file after init.php to get access to all core functionality.
 * 
 * Usage:
 * require_once __DIR__ . '/../init.php';
 * require_once __DIR__ . '/core/bootstrap.php';
 */

// Load core classes
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Middleware.php';

// Load service classes
require_once __DIR__ . '/../services/BaseService.php';
require_once __DIR__ . '/../services/DispatchService.php';
require_once __DIR__ . '/../services/ProductService.php';
require_once __DIR__ . '/../services/SalesService.php';
require_once __DIR__ . '/../services/StockService.php';
require_once __DIR__ . '/../services/FinanceService.php';
require_once __DIR__ . '/../services/UserService.php';
require_once __DIR__ . '/../services/ShopService.php';

/**
 * Convenience function to get service instances
 * 
 * @param string $serviceName Name of the service class
 * @return object Service instance
 */
function service($serviceName) {
    static $services = [];
    
    if (!isset($services[$serviceName])) {
        $className = $serviceName . 'Service';
        if (class_exists($className)) {
            $services[$serviceName] = new $className();
        } else {
            throw new Exception("Service class {$className} not found");
        }
    }
    
    return $services[$serviceName];
}
