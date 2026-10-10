<?php
/**
 * User Service
 * 
 * Handles all user-related database operations with built-in caching
 * and optimized queries.
 */

require_once __DIR__ . '/BaseService.php';

class UserService extends BaseService {
    
    /**
     * Get user by ID
     * 
     * @param int $userId User ID
     * @param bool $useCache Whether to use cache
     * @return array|false User data or false if not found
     */
    public function getById($userId, $useCache = true) {
        $cacheKey = "user_{$userId}";
        
        return $this->cachedQuery($cacheKey, function() use ($userId) {
            $sql = "
                SELECT 
                    id, username, full_name, email, role,
                    is_active, created_at, updated_at
                FROM users
                WHERE id = ?
            ";
            return $this->fetchOne($sql, [$userId]);
        }, $useCache ? 600 : 0); // 10 minute cache
    }
    
    /**
     * Get user by username
     * 
     * @param string $username Username
     * @param bool $useCache Whether to use cache
     * @return array|false User data or false if not found
     */
    public function getByUsername($username, $useCache = true) {
        $cacheKey = "user_username_{$username}";
        
        return $this->cachedQuery($cacheKey, function() use ($username) {
            $sql = "
                SELECT 
                    id, username, full_name, email, role,
                    is_active, created_at, updated_at
                FROM users
                WHERE username = ?
            ";
            return $this->fetchOne($sql, [$username]);
        }, $useCache ? 600 : 0); // 10 minute cache
    }
    
    /**
     * Get all active users
     * 
     * @param bool $useCache Whether to use cache
     * @return array Array of users
     */
    public function getAllActive($useCache = true) {
        $cacheKey = 'users_all_active';
        
        return $this->cachedQuery($cacheKey, function() {
            $sql = "
                SELECT 
                    id, username, full_name, email, role,
                    is_active, created_at, updated_at
                FROM users
                WHERE is_active = 1
                ORDER BY full_name ASC
            ";
            return $this->fetchAll($sql);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get users by role
     * 
     * @param string $role Role name
     * @param bool $useCache Whether to use cache
     * @return array Array of users
     */
    public function getByRole($role, $useCache = true) {
        $cacheKey = "users_role_{$role}";
        
        return $this->cachedQuery($cacheKey, function() use ($role) {
            $sql = "
                SELECT 
                    id, username, full_name, email, role,
                    is_active, created_at, updated_at
                FROM users
                WHERE role = ? AND is_active = 1
                ORDER BY full_name ASC
            ";
            return $this->fetchAll($sql, [$role]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get dispatchers (drivers)
     * 
     * @param bool $useCache Whether to use cache
     * @return array Array of dispatchers
     */
    public function getDispatchers($useCache = true) {
        return $this->getByRole('dispatcher', $useCache);
    }
    
    /**
     * Get shop attendants
     * 
     * @param bool $useCache Whether to use cache
     * @return array Array of shop attendants
     */
    public function getShopAttendants($useCache = true) {
        return $this->getByRole('shop_attendant', $useCache);
    }
    
    /**
     * Get cashiers
     * 
     * @param bool $useCache Whether to use cache
     * @return array Array of cashiers
     */
    public function getCashiers($useCache = true) {
        return $this->getByRole('cashier', $useCache);
    }
    
    /**
     * Create a new user
     * 
     * @param array $data User data
     * @return int New user ID
     */
    public function create($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('users', $data);
        
        // Invalidate caches
        $this->invalidateCache('users_');
        
        return $id;
    }
    
    /**
     * Update a user
     * 
     * @param int $userId User ID
     * @param array $data User data to update
     * @return bool Success status
     */
    public function update($userId, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        $result = $this->update(
            'users',
            $data,
            'id = ?',
            [$userId]
        );
        
        // Invalidate relevant caches
        $this->invalidateCache('user_' . $userId);
        $this->invalidateCache('users_');
        
        return $result > 0;
    }
    
    /**
     * Delete a user (soft delete by setting is_active = 0)
     * 
     * @param int $userId User ID
     * @return bool Success status
     */
    public function delete($userId) {
        $result = $this->update(
            'users',
            ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$userId]
        );
        
        // Invalidate caches
        $this->invalidateCache('user_' . $userId);
        $this->invalidateCache('users_');
        
        return $result > 0;
    }
    
    /**
     * Verify user credentials
     * 
     * @param string $username Username
     * @param string $password Password
     * @return array|false User data if valid, false otherwise
     */
    public function verifyCredentials($username, $password) {
        $user = $this->getByUsername($username, false);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        
        return false;
    }
    
    /**
     * Update user password
     * 
     * @param int $userId User ID
     * @param string $newPassword New password
     * @return bool Success status
     */
    public function updatePassword($userId, $newPassword) {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        return $this->update($userId, ['password_hash' => $passwordHash]);
    }
}
