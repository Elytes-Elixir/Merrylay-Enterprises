<?php
/**
 * Response Class
 * 
 * Helper class for consistent HTTP responses
 */

class Response {
    /**
     * Return JSON response
     * 
     * @param mixed $data Data to encode
     * @param int $statusCode HTTP status code
     * @return void
     */
    public static function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
    
    /**
     * Return success JSON response
     * 
     * @param mixed $data Response data
     * @param string $message Success message
     * @return void
     */
    public static function success($data = null, $message = 'Success') {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }
    
    /**
     * Return error JSON response
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param mixed $errors Additional error details
     * @return void
     */
    public static function error($message, $statusCode = 400, $errors = null) {
        $response = [
            'success' => false,
            'message' => $message
        ];
        
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        self::json($response, $statusCode);
    }
    
    /**
     * Return validation error response
     * 
     * @param array $errors Validation errors
     * @return void
     */
    public static function validationError($errors) {
        self::error('Validation failed', 422, $errors);
    }
    
    /**
     * Return unauthorized response
     * 
     * @param string $message Error message
     * @return void
     */
    public static function unauthorized($message = 'Unauthorized') {
        self::error($message, 401);
    }
    
    /**
     * Return forbidden response
     * 
     * @param string $message Error message
     * @return void
     */
    public static function forbidden($message = 'Forbidden') {
        self::error($message, 403);
    }
    
    /**
     * Return not found response
     * 
     * @param string $message Error message
     * @return void
     */
    public static function notFound($message = 'Not Found') {
        self::error($message, 404);
    }
    
    /**
     * Return server error response
     * 
     * @param string $message Error message
     * @return void
     */
    public static function serverError($message = 'Internal Server Error') {
        self::error($message, 500);
    }
}
