<?php
/**
 * Finance Service
 * 
 * Handles all finance-related database operations with built-in caching
 * and optimized queries.
 */

require_once __DIR__ . '/BaseService.php';

class FinanceService extends BaseService {
    
    /**
     * Get daily balance for a date
     * 
     * @param string $date Date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Daily balance data
     */
    public function getDailyBalance($date, $useCache = true) {
        $cacheKey = "daily_balance_{$date}";
        
        return $this->cachedQuery($cacheKey, function() use ($date) {
            $sql = "
                SELECT 
                    opening_balance,
                    cash_in,
                    cash_out,
                    closing_balance,
                    notes,
                    recorded_at
                FROM daily_balances
                WHERE balance_date = ?
                ORDER BY recorded_at DESC
                LIMIT 1
            ";
            return $this->fetchOne($sql, [$date]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get bank deposits for a date range
     * 
     * @param string $startDate Start date in Y-m-d format
     * @param string $endDate End date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Array of bank deposits
     */
    public function getBankDeposits($startDate, $endDate, $useCache = true) {
        $cacheKey = "bank_deposits_{$startDate}_{$endDate}";
        
        return $this->cachedQuery($cacheKey, function() use ($startDate, $endDate) {
            $sql = "
                SELECT 
                    id, deposit_date, amount, bank_name,
                    reference_number, notes, deposited_by,
                    created_at
                FROM bank_deposits
                WHERE deposit_date >= ? AND deposit_date <= ?
                ORDER BY deposit_date DESC
            ";
            return $this->fetchAll($sql, [$startDate, $endDate]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get customer payments for a date range
     * 
     * @param string $startDate Start date in Y-m-d format
     * @param string $endDate End date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Array of customer payments
     */
    public function getCustomerPayments($startDate, $endDate, $useCache = true) {
        $cacheKey = "customer_payments_{$startDate}_{$endDate}";
        
        return $this->cachedQuery($cacheKey, function() use ($startDate, $endDate) {
            $sql = "
                SELECT 
                    id, customer_name, payment_date, amount,
                    payment_method, notes, recorded_by,
                    created_at
                FROM customer_payments
                WHERE payment_date >= ? AND payment_date <= ?
                ORDER BY payment_date DESC
            ";
            return $this->fetchAll($sql, [$startDate, $endDate]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get credit settlements for a date range
     * 
     * @param string $startDate Start date in Y-m-d format
     * @param string $endDate End date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Array of credit settlements
     */
    public function getCreditSettlements($startDate, $endDate, $useCache = true) {
        $cacheKey = "credit_settlements_{$startDate}_{$endDate}";
        
        return $this->cachedQuery($cacheKey, function() use ($startDate, $endDate) {
            $sql = "
                SELECT 
                    id, creditor_name, settlement_date, amount,
                    payment_method, notes, recorded_by,
                    created_at
                FROM credit_settlements
                WHERE settlement_date >= ? AND settlement_date <= ?
                ORDER BY settlement_date DESC
            ";
            return $this->fetchAll($sql, [$startDate, $endDate]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Get financial summary for a date range
     * 
     * @param string $startDate Start date in Y-m-d format
     * @param string $endDate End date in Y-m-d format
     * @param bool $useCache Whether to use cache
     * @return array Financial summary
     */
    public function getFinancialSummary($startDate, $endDate, $useCache = true) {
        $cacheKey = "financial_summary_{$startDate}_{$endDate}";
        
        return $this->cachedQuery($cacheKey, function() use ($startDate, $endDate) {
            $sql = "
                SELECT 
                    COALESCE(SUM(CASE WHEN payment_type = 'cash_in' THEN amount ELSE 0 END), 0) as total_cash_in,
                    COALESCE(SUM(CASE WHEN payment_type = 'cash_out' THEN amount ELSE 0 END), 0) as total_cash_out,
                    COALESCE(SUM(bd.amount), 0) as total_bank_deposits,
                    COALESCE(SUM(cp.amount), 0) as total_customer_payments,
                    COALESCE(SUM(cs.amount), 0) as total_credit_settlements
                FROM (
                    SELECT 'cash_in' as payment_type, cash_in as amount
                    FROM daily_balances
                    WHERE balance_date >= ? AND balance_date <= ?
                    UNION ALL
                    SELECT 'cash_out' as payment_type, cash_out as amount
                    FROM daily_balances
                    WHERE balance_date >= ? AND balance_date <= ?
                ) balances
                LEFT JOIN bank_deposits bd ON bd.deposit_date >= ? AND bd.deposit_date <= ?
                LEFT JOIN customer_payments cp ON cp.payment_date >= ? AND cp.payment_date <= ?
                LEFT JOIN credit_settlements cs ON cs.settlement_date >= ? AND cs.settlement_date <= ?
            ";
            return $this->fetchOne($sql, [
                $startDate, $endDate, $startDate, $endDate,
                $startDate, $endDate, $startDate, $endDate, $startDate, $endDate
            ]);
        }, $useCache ? 300 : 0); // 5 minute cache
    }
    
    /**
     * Create a daily balance record
     * 
     * @param array $data Balance data
     * @return int New balance ID
     */
    public function createDailyBalance($data) {
        $data['recorded_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('daily_balances', $data);
        
        // Invalidate caches
        $this->invalidateCache('daily_balance_');
        
        return $id;
    }
    
    /**
     * Create a bank deposit record
     * 
     * @param array $data Deposit data
     * @return int New deposit ID
     */
    public function createBankDeposit($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('bank_deposits', $data);
        
        // Invalidate caches
        $this->invalidateCache('bank_deposits_');
        
        return $id;
    }
    
    /**
     * Create a customer payment record
     * 
     * @param array $data Payment data
     * @return int New payment ID
     */
    public function createCustomerPayment($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('customer_payments', $data);
        
        // Invalidate caches
        $this->invalidateCache('customer_payments_');
        
        return $id;
    }
    
    /**
     * Create a credit settlement record
     * 
     * @param array $data Settlement data
     * @return int New settlement ID
     */
    public function createCreditSettlement($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        
        $id = $this->insert('credit_settlements', $data);
        
        // Invalidate caches
        $this->invalidateCache('credit_settlements_');
        
        return $id;
    }
}
