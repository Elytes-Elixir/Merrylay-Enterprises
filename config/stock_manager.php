<?php
/**
 * Daily Stock Management System for Merrylay Enterprises
 *
 * Handles opening stock, closing stock, and stock movements tracking
 */

class StockManager
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Ensure cache helper is available for invalidation after movements
    private function ensureCache()
    {
        $cacheFile = __DIR__ . '/cache.php';
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
        }
    }

    /**
     * Initialize daily stock for all products
     *
     * @param string $date The date to initialize (default: today)
     * @return bool Success status
     */
    public function initializeDailyStock($date = null)
    {
        if ($date === null) {
            $date = date('Y-m-d');
        }

        $date = $this->normalizeStockDate($date);
        $startedTransaction = !$this->pdo->inTransaction();

        try {
            if ($startedTransaction) {
                $this->pdo->beginTransaction();
            }

            $insertedCount = $this->initializeDailyStockStandard($date);
            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $insertedCount > 0;
        } catch (Exception $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Failed to initialize daily stock: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Standard daily stock initialization
     */
    private function initializeDailyStockStandard($date) {
        $stmt = $this->pdo->prepare("SELECT id FROM products WHERE is_active = TRUE ORDER BY id FOR UPDATE");
        if (!$stmt->execute()) {
            throw new RuntimeException('Active product lock failed.');
        }
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($products)) {
            throw new DomainException('There are no active products to initialize.');
        }

        $previousDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date)->modify('-1 day')->format('Y-m-d');
        $dailyLockStmt = $this->pdo->prepare("
            SELECT stock_date, closing_stock
            FROM daily_stock
            WHERE product_id = ? AND stock_date IN (?, ?)
            ORDER BY stock_date
            FOR UPDATE
        ");
        $initialStmt = $this->pdo->prepare(
            "SELECT quantity_change
             FROM stock_movements
             WHERE product_id = ? AND movement_type = 'initial' AND movement_date <= ?
             ORDER BY movement_date DESC, id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $insertStmt = $this->pdo->prepare("
            INSERT INTO daily_stock (product_id, stock_date, opening_stock, closing_stock)
            VALUES (?, ?, ?, ?)
        ");

        $insertedCount = 0;
        foreach ($products as $product) {
            $productId = $product['id'];
            if (!$dailyLockStmt->execute([$productId, $previousDate, $date])) {
                throw new RuntimeException("Daily stock lock failed for product {$productId}.");
            }
            $dailyRows = $dailyLockStmt->fetchAll(PDO::FETCH_ASSOC);
            $targetExists = false;
            foreach ($dailyRows as $dailyRow) {
                if ($dailyRow['stock_date'] === $date) {
                    $targetExists = true;
                    break;
                }
            }
            if ($targetExists) {
                continue;
            }

            $openingStock = null;
            foreach ($dailyRows as $dailyRow) {
                if ($dailyRow['stock_date'] === $previousDate && $dailyRow['closing_stock'] !== null) {
                    $openingStock = $dailyRow['closing_stock'];
                    break;
                }
            }
            if ($openingStock === null) {
                if (!$initialStmt->execute([$productId, $date])) {
                    throw new RuntimeException("Initial baseline lookup failed for product {$productId}.");
                }
                $initialBaseline = $initialStmt->fetchColumn();
                $openingStock = $initialBaseline === false || $initialBaseline === null ? 0.0 : $initialBaseline;
            }

            $insertStmt->execute([$productId, $date, $openingStock, $openingStock]);
            if ($insertStmt->rowCount() !== 1) {
                throw new RuntimeException("Daily stock initialization failed for product {$productId}.");
            }
            $insertedCount++;
        }

        if ($insertedCount === 0) {
            error_log("Daily stock is already initialized for {$date}; no rows needed insertion.");
            return count($products);
        }

        return $insertedCount;
    }

    /**
     * Create opening baselines for products that have not started stock activity.
     * The caller owns the transaction so all products in one form submit atomically.
     */
    public function initializeOpeningBaselines(array $stockData, $date, $userId = null)
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Opening stock must be initialized inside a transaction.');
        }

        $date = $this->normalizeStockDate($date);
        if ($date > date('Y-m-d')) {
            throw new InvalidArgumentException('Opening stock date cannot be in the future.');
        }
        if ($userId !== null) {
            $userId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) {
                throw new InvalidArgumentException('A valid user is required to record opening stock.');
            }
        }

        $baselines = [];
        $seenProductIds = [];
        foreach ($stockData as $submittedProductId => $rawQuantity) {
            $productId = filter_var((string) $submittedProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($productId === false) {
                throw new InvalidArgumentException('Every opening quantity must have a valid product ID.');
            }
            if (isset($seenProductIds[$productId])) {
                throw new InvalidArgumentException('A product can only be initialized once per submission.');
            }
            $seenProductIds[$productId] = true;

            if (!is_string($rawQuantity)) {
                throw new InvalidArgumentException('Opening quantities must be numeric values.');
            }
            if (trim($rawQuantity) === '') {
                continue;
            }

            $quantity = $this->parseOpeningQuantity($rawQuantity);
            $baselines[] = [
                'product_id' => $productId,
                'quantity' => $quantity['value'],
                'quantity_cents' => $quantity['cents'],
            ];
        }

        if (empty($baselines)) {
            throw new InvalidArgumentException('Enter an opening quantity for at least one product.');
        }

        usort($baselines, function ($left, $right) {
            return $left['product_id'] <=> $right['product_id'];
        });

        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $previousDate = $dateObject->modify('-1 day')->format('Y-m-d');
        $nextDate = $dateObject->modify('+1 day')->format('Y-m-d');
        $productLockStmt = $this->pdo->prepare(
            'SELECT id, is_active, max_stock FROM products WHERE id = ? FOR UPDATE'
        );
        $dailyLockStmt = $this->pdo->prepare(
            'SELECT stock_date, closing_stock FROM daily_stock
             WHERE product_id = ? AND stock_date IN (?, ?)
             ORDER BY stock_date FOR UPDATE'
        );
        $movementLockStmt = $this->pdo->prepare(
              'SELECT movement_type FROM stock_movements
               WHERE product_id = ? AND movement_date >= ? AND movement_date < ?
             ORDER BY id FOR UPDATE'
        );
           $laterDailyLockStmt = $this->pdo->prepare(
              'SELECT stock_date FROM daily_stock
               WHERE product_id = ? AND stock_date > ?
               ORDER BY stock_date FOR UPDATE'
           );
           $laterMovementLockStmt = $this->pdo->prepare(
              'SELECT id FROM stock_movements
               WHERE product_id = ? AND movement_date >= ?
               ORDER BY movement_date, id FOR UPDATE'
           );

        foreach ($baselines as &$baseline) {
            if (!$productLockStmt->execute([$baseline['product_id']])) {
                throw new RuntimeException('Product validation lock failed.');
            }
            $product = $productLockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product || (int) $product['is_active'] !== 1) {
                throw new InvalidArgumentException('The selected product does not exist or is inactive.');
            }

            if ($product['max_stock'] !== null) {
                $maximum = $this->parseStockDecimal($product['max_stock'], 10, 'Configured maximum stock');
                if ($baseline['quantity_cents'] > $maximum['cents']) {
                    throw new InvalidArgumentException('Opening quantity exceeds this product\'s maximum stock.');
                }
            }

            if (!$dailyLockStmt->execute([$baseline['product_id'], $previousDate, $date])) {
                throw new RuntimeException('Daily stock validation lock failed.');
            }
            $dailyRows = $dailyLockStmt->fetchAll(PDO::FETCH_ASSOC);
            $targetExists = false;
            $previousClosingExists = false;
            foreach ($dailyRows as $dailyRow) {
                if ($dailyRow['stock_date'] === $date) {
                    $targetExists = true;
                } elseif ($dailyRow['stock_date'] === $previousDate && $dailyRow['closing_stock'] !== null) {
                    $previousClosingExists = true;
                }
            }

            if (!$movementLockStmt->execute([$baseline['product_id'], $date, $nextDate])) {
                throw new RuntimeException('Stock activity validation lock failed.');
            }
            $movements = $movementLockStmt->fetchAll(PDO::FETCH_COLUMN);
            $hasInitialMovement = in_array('initial', $movements, true);
            $hasStockActivity = !empty($movements);

            if (!$laterDailyLockStmt->execute([$baseline['product_id'], $date])) {
                throw new RuntimeException('Later daily stock validation lock failed.');
            }
            $hasLaterDailyStock = $laterDailyLockStmt->fetchColumn() !== false;

            if (!$laterMovementLockStmt->execute([$baseline['product_id'], $nextDate])) {
                throw new RuntimeException('Later stock activity validation lock failed.');
            }
            $hasLaterStockActivity = $laterMovementLockStmt->fetchColumn() !== false;

            if ($previousClosingExists) {
                throw new DomainException('A previous-day closing balance controls this opening date. Choose a date with no prior closing balance.');
            }
            if ($hasInitialMovement) {
                throw new DomainException('An opening baseline already exists for this product and date.');
            }
            if ($hasStockActivity) {
                throw new DomainException('Stock activity has already begun for this product and date; opening stock cannot be reset.');
            }
            if ($hasLaterDailyStock || $hasLaterStockActivity) {
                throw new DomainException('Later stock history exists for this product; an earlier opening baseline cannot be added.');
            }
            if ($targetExists) {
                throw new DomainException('A daily stock record already exists for this product and date; its opening and closing values will not be overwritten.');
            }
        }
        unset($baseline);

        $productUpdateStmt = $this->pdo->prepare(
            'UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ? AND is_active = TRUE'
        );
        $movementInsertStmt = $this->pdo->prepare(
            "INSERT INTO stock_movements (
                product_id, movement_date, movement_type, quantity_change,
                reference_type, reference_id, reference_number, notes, user_id
            ) VALUES (?, ?, 'initial', ?, 'system', NULL, 'INITIAL', 'Opening stock baseline', ?)"
        );
        $dailyInsertStmt = $this->pdo->prepare(
            'INSERT INTO daily_stock (product_id, stock_date, opening_stock, closing_stock) VALUES (?, ?, ?, ?)'
        );

        foreach ($baselines as $baseline) {
            if (!$productUpdateStmt->execute([$baseline['quantity'], $baseline['product_id']])) {
                throw new RuntimeException('Product opening quantity update failed.');
            }
            if (!$movementInsertStmt->execute([$baseline['product_id'], $date, $baseline['quantity'], $userId]) || $movementInsertStmt->rowCount() !== 1) {
                throw new RuntimeException('Opening stock movement could not be recorded.');
            }
            if (!$dailyInsertStmt->execute([
                $baseline['product_id'],
                $date,
                $baseline['quantity'],
                $baseline['quantity'],
            ]) || $dailyInsertStmt->rowCount() !== 1) {
                throw new RuntimeException('Daily opening stock could not be recorded.');
            }
        }

        return count($baselines);
    }

    private function normalizeStockDate($date)
    {
        if (!is_string($date) || !preg_match('/\\A\\d{4}-\\d{2}-\\d{2}\\z/', $date)) {
            throw new InvalidArgumentException('Stock date must be a valid YYYY-MM-DD date.');
        }

        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$dateObject || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $dateObject->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Stock date must be a valid YYYY-MM-DD date.');
        }

        return $date;
    }

    private function parseOpeningQuantity($rawQuantity)
    {
        $quantity = trim($rawQuantity);
        if (preg_match('/\\A-/', $quantity)) {
            throw new InvalidArgumentException('Opening quantity cannot be negative.');
        }

        $parsed = $this->parseStockDecimal($quantity, 8, 'Opening quantity');
        if ($parsed['cents'] > 9999999999) {
            throw new InvalidArgumentException('Opening quantity exceeds the DECIMAL(10,2) limit.');
        }

        return $parsed;
    }

    private function parseStockDecimal($rawValue, $maxWholeDigits, $fieldName)
    {
        if (!is_string($rawValue) && !is_int($rawValue) && !is_float($rawValue)) {
            throw new InvalidArgumentException("{$fieldName} must be a finite numeric value with at most two decimal places.");
        }
        if (is_float($rawValue) && !is_finite($rawValue)) {
            throw new InvalidArgumentException("{$fieldName} must be a finite numeric value with at most two decimal places.");
        }

        $value = trim((string) $rawValue);
        if (!is_numeric($value) || !is_finite((float) $value) || !preg_match('/\\A\\d+(?:\\.\\d{1,2})?\\z/', $value)) {
            throw new InvalidArgumentException("{$fieldName} must be a finite numeric value with at most two decimal places.");
        }

        $parts = explode('.', $value, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > $maxWholeDigits) {
            throw new InvalidArgumentException("{$fieldName} exceeds the supported stock limit.");
        }

        $fraction = str_pad($parts[1] ?? '', 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;
        return [
            'value' => $whole . '.' . $fraction,
            'cents' => $cents,
        ];
    }

    /**
     * Alternative daily stock initialization using temporary table to avoid trigger conflicts
     */
    private function initializeDailyStockAlternative($date) {
        // Get all triggers on daily_stock table
        $triggers = [];
        $triggerDefs = [];
        try {
            $stmt = $this->pdo->query("
                SELECT TRIGGER_NAME, SQL_MODE, DEFINER, ACTION_TIMING, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_STATEMENT
                FROM information_schema.TRIGGERS
                WHERE EVENT_OBJECT_TABLE = 'daily_stock'
            ");
            $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Store trigger definitions for recreation
            foreach ($triggers as $trigger) {
                $triggerDefs[$trigger['TRIGGER_NAME']] = $trigger;
            }
        } catch (Exception $e) {
            error_log("Failed to query triggers: " . $e->getMessage());
        }

        // Drop any triggers on daily_stock temporarily
        foreach ($triggers as $trigger) {
            try {
                $this->pdo->exec("DROP TRIGGER IF EXISTS " . $trigger['TRIGGER_NAME']);
                error_log("Dropped trigger: " . $trigger['TRIGGER_NAME']);
            } catch (Exception $e) {
                error_log("Failed to drop trigger {$trigger['TRIGGER_NAME']}: " . $e->getMessage());
            }
        }

        try {
            // Create a temporary table to hold the data
            $this->pdo->exec("
                CREATE TEMPORARY TABLE IF NOT EXISTS temp_daily_stock_init (
                    product_id INT,
                    stock_date DATE,
                    opening_stock DECIMAL(10,2),
                    closing_stock DECIMAL(10,2),
                    PRIMARY KEY (product_id, stock_date)
                )
            ");

            // Clear the temp table
            $this->pdo->exec("TRUNCATE TABLE temp_daily_stock_init");

            // Get all active products
            $stmt = $this->pdo->prepare("SELECT id, quantity FROM products WHERE is_active = TRUE");
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Prepare statement to get previous day's closing stock
            $prevStmt = $this->pdo->prepare("
                SELECT closing_stock
                FROM daily_stock
                WHERE product_id = ? AND stock_date = DATE_SUB(?, INTERVAL 1 DAY)
                ORDER BY stock_date DESC
                LIMIT 1
            ");

            // Insert into temp table
            $tempInsertStmt = $this->pdo->prepare("
                INSERT INTO temp_daily_stock_init (product_id, stock_date, opening_stock, closing_stock)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($products as $product) {
                $productId = $product['id'];
                $currentQuantity = $product['quantity'];

                $prevStmt->execute([$productId, $date]);
                $prevClosing = $prevStmt->fetchColumn();

                if ($prevClosing === false || $prevClosing === null) {
                    $openingStock = 0.0;
                } else {
                    $openingStock = $prevClosing;
                }

                $tempInsertStmt->execute([$productId, $date, $openingStock, $openingStock]);
            }

            // Now insert from temp table to main table using INSERT IGNORE
            $this->pdo->exec("
                INSERT IGNORE INTO daily_stock (product_id, stock_date, opening_stock, closing_stock)
                SELECT product_id, stock_date, opening_stock, closing_stock
                FROM temp_daily_stock_init
            ");

            // Drop temp table
            $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS temp_daily_stock_init");

            error_log("Daily stock initialized for $date using alternative method");
            return true;
        } catch (Exception $e) {
            error_log("Alternative initialization failed: " . $e->getMessage());
            throw $e;
        } finally {
            // Note: We don't recreate the triggers because they cause recursive errors
            // The stock movement triggers on stock_movements table handle the closing stock updates
            error_log("Triggers on daily_stock were not recreated (they cause recursive errors)");
        }
    }

    /**
     * Get independent stock damage quantities for a date.
     */
    private function getDamageQuantitiesByDate($date)
    {
        $damageByProduct = [];

        $voidedReturnsStmt = $this->pdo->prepare(
            "SELECT dr.product_id, COALESCE(SUM(dr.quantity_returned), 0) AS quantity
             FROM dispatch_returns dr
             JOIN dispatches d ON d.id = dr.dispatch_id
             WHERE DATE(dr.return_date) = ?
               AND d.status = 'cancelled'
               AND dr.`condition` IN ('damaged', 'expired', 'unsaleable')
             GROUP BY dr.product_id"
        );
        if (!$voidedReturnsStmt->execute([$date])) {
            throw new RuntimeException('Cancelled dispatch damage lookup failed.');
        }
        foreach ($voidedReturnsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['product_id'];
            $quantity = (float) $row['quantity'];
            if ($productId > 0 && $quantity > 0) {
                $damageByProduct[$productId] = ($damageByProduct[$productId] ?? 0.0) + $quantity;
            }
        }

        $matchedShopDamageRefs = [];
        try {
            $movementDamageStmt = $this->pdo->prepare(
                "SELECT product_id, GREATEST(0, -SUM(quantity_change)) AS quantity,
                        reference_type, reference_id, reference_number
                 FROM stock_movements
                 WHERE DATE(movement_date) = ? AND movement_type = 'damage'
                 GROUP BY product_id, reference_type, reference_id, reference_number"
            );
            if (!$movementDamageStmt->execute([$date])) {
                throw new RuntimeException('Stock damage movement lookup failed.');
            }
            foreach ($movementDamageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $productId = intval($row['product_id']);
                $quantity = floatval($row['quantity'] ?? 0);
                if ($productId > 0 && $quantity > 0) {
                    $damageByProduct[$productId] = ($damageByProduct[$productId] ?? 0.0) + $quantity;
                }

                $referenceType = strtolower(trim((string) ($row['reference_type'] ?? '')));
                if ($referenceType === 'shop_damage' && intval($row['reference_id'] ?? 0) > 0) {
                    $matchedShopDamageRefs['shop_damage:' . intval($row['reference_id'])] = true;
                }
                if ($referenceType === 'manual' && !empty($row['reference_number'])) {
                    $matchedShopDamageRefs['damage_number:' . $row['reference_number']] = true;
                }
            }
        } catch (Exception $e) {
            error_log("Failed to load stock movement damages: " . $e->getMessage());
            throw $e;
        }

        try {
            $shopDamageStmt = $this->pdo->prepare(
                "SELECT id, product_id, quantity, damage_number
                 FROM shop_damaged_products
                 WHERE DATE(damage_date) = ?"
            );
            if (!$shopDamageStmt->execute([$date])) {
                throw new RuntimeException('Shop damage record lookup failed.');
            }
            foreach ($shopDamageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $shopDamageRef = 'shop_damage:' . intval($row['id'] ?? 0);
                $damageNumberRef = 'damage_number:' . (string) ($row['damage_number'] ?? '');
                if (isset($matchedShopDamageRefs[$shopDamageRef])
                    || isset($matchedShopDamageRefs[$damageNumberRef])) {
                    continue;
                }

                $productId = intval($row['product_id']);
                $quantity = floatval($row['quantity'] ?? 0);
                if ($productId > 0 && $quantity > 0) {
                    $damageByProduct[$productId] = ($damageByProduct[$productId] ?? 0.0) + $quantity;
                }
            }
        } catch (Exception $e) {
            error_log("Failed to load shop damaged products: " . $e->getMessage());
            throw $e;
        }

        return $damageByProduct;
    }

    /**
     * Get the opening stock for a product on a given date.
     * If no prior closing stock exists, use the initial-stock baseline.
     */
    private function getOpeningStockForDate($productId, $date)
    {
        $prevStmt = $this->pdo->prepare(
            "SELECT closing_stock FROM daily_stock WHERE product_id = ? AND stock_date = DATE_SUB(?, INTERVAL 1 DAY) ORDER BY stock_date DESC LIMIT 1"
        );
        if (!$prevStmt->execute([$productId, $date])) {
            throw new RuntimeException('Previous closing stock lookup failed.');
        }
        $prevClosing = $prevStmt->fetchColumn();

        if ($prevClosing !== false && $prevClosing !== null) {
            return $prevClosing;
        }

        // Initial movements establish the baseline and are excluded from daily movement deltas.
        $initialStmt = $this->pdo->prepare(
            "SELECT quantity_change
             FROM stock_movements
             WHERE product_id = ? AND movement_type = 'initial' AND movement_date <= ?
             ORDER BY movement_date DESC, id DESC
             LIMIT 1"
        );
        if (!$initialStmt->execute([$productId, $date])) {
            throw new RuntimeException('Initial stock baseline lookup failed.');
        }
        $initialBaseline = $initialStmt->fetchColumn();

        if ($initialBaseline !== false && $initialBaseline !== null) {
            return $initialBaseline;
        }

        // A pre-initialized current-day row is also a safe explicit zero/non-zero baseline.
        $currentStmt = $this->pdo->prepare(
            "SELECT opening_stock FROM daily_stock WHERE product_id = ? AND stock_date = ? LIMIT 1"
        );
        if (!$currentStmt->execute([$productId, $date])) {
            throw new RuntimeException('Current opening stock lookup failed.');
        }
        $currentOpening = $currentStmt->fetchColumn();

        if ($currentOpening !== false && $currentOpening !== null) {
            return $currentOpening;
        }

        throw new RuntimeException("Opening stock baseline cannot be determined safely for product {$productId} on {$date}.");
    }

    /**
     * Get dispatcher sales and return quantities for a product on a given date.
     * Uses dispatch_items table for consistency with stock summary and analysis pages.
     */
    private function getSalesQuantitiesForDate($productId, $date)
    {
        // Use dispatch_items table for consistency with stock summary and analysis pages
        $dispatchStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(di.quantity), 0) as dispatch_quantity
             FROM dispatch_items di
             JOIN dispatches d ON di.dispatch_id = d.id
             WHERE di.product_id = ?
               AND DATE(d.dispatch_date) = ?
                             AND d.sales_channel != 'shop_sales'
                             AND COALESCE(d.status, '') <> 'cancelled'"
        );
        if (!$dispatchStmt->execute([$productId, $date])) {
            throw new RuntimeException("Dispatch quantity lookup failed for product {$productId} on {$date}.");
        }
        $dispatchQty = floatval($dispatchStmt->fetchColumn() ?: 0);

        // Returns from either workflow are inbound on the date they are recorded.
        $returnStmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN DATE(d.dispatch_date) = ? THEN return_rows.quantity ELSE 0 END), 0) AS same_day_return_qty,
                COALESCE(SUM(CASE WHEN return_rows.dispatch_id IS NULL OR DATE(d.dispatch_date) < ? THEN return_rows.quantity ELSE 0 END), 0) AS prior_dispatch_return_qty
             FROM (
                SELECT dispatch_id, product_id, quantity, return_date
                FROM daily_returns
                WHERE product_id = ? AND DATE(return_date) = ?
                UNION ALL
                SELECT dispatch_id, product_id, quantity_returned AS quantity, return_date
                FROM dispatch_returns
                WHERE product_id = ? AND DATE(return_date) = ? AND `condition` = 'good'
             ) return_rows
             LEFT JOIN dispatches d ON d.id = return_rows.dispatch_id
             WHERE return_rows.dispatch_id IS NULL OR COALESCE(d.status, '') <> 'cancelled'"
        );
        if (!$returnStmt->execute([$date, $date, $productId, $date, $productId, $date])) {
            throw new RuntimeException("Dispatch return lookup failed for product {$productId} on {$date}.");
        }
        $returnQuantities = $returnStmt->fetch(PDO::FETCH_ASSOC);
        $sameDayReturnQty = floatval($returnQuantities['same_day_return_qty'] ?? 0);
        $priorDispatchReturnQty = floatval($returnQuantities['prior_dispatch_return_qty'] ?? 0);

                $voidedSalesStmt = $this->pdo->prepare(
                        "SELECT COALESCE(SUM(di.quantity_sold), 0)
                         FROM dispatch_items di
                         JOIN dispatches d ON d.id = di.dispatch_id
                         WHERE di.product_id = ?
                             AND DATE(d.dispatch_date) = ?
                             AND d.status = 'cancelled'
                             AND d.sales_channel != 'shop_sales'"
                );
                if (!$voidedSalesStmt->execute([$productId, $date])) {
                    throw new RuntimeException("Cancelled dispatch sales lookup failed for product {$productId} on {$date}.");
                }
                $voidedSalesQty = floatval($voidedSalesStmt->fetchColumn() ?: 0);

                $dispatcherSalesQty = max(0.0, $dispatchQty - $sameDayReturnQty)
                    - $priorDispatchReturnQty
                    + $voidedSalesQty;

        $shopStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(ssi.quantity), 0) AS sales_quantity
             FROM shop_sales_items ssi
             JOIN shop_sales ss ON ssi.shop_sale_id = ss.id
             WHERE ssi.product_id = ? AND DATE(ss.sale_date) = ?"
        );
        if (!$shopStmt->execute([$productId, $date])) {
            throw new RuntimeException("Shop sales quantity lookup failed for product {$productId} on {$date}.");
        }
        $shopQty = floatval($shopStmt->fetchColumn() ?: 0);

        return [
            'dispatcher_sales_qty' => $dispatcherSalesQty,
            'shop_qty' => $shopQty,
            'net_sales_qty' => $dispatcherSalesQty + $shopQty,
        ];
    }

    /**
     * Record a stock movement
     */
    public function recordMovement($productId, $movementType, $quantityChange, $referenceType = 'manual', $referenceId = null, $referenceNumber = null, $notes = null, $userId = null, $movementDate = null)
    {
        $supportedReferenceTypes = ['sale', 'dispatch', 'manual', 'system', 'daily_activity'];
        if (!in_array($referenceType, $supportedReferenceTypes, true)) {
            throw new InvalidArgumentException('Unsupported stock movement reference type.');
        }

        $inTransaction = false;
        try {
            $movementDate = $movementDate ?: date('Y-m-d');

            // Check if a transaction is already active - if not, start one
            $inTransaction = !$this->pdo->inTransaction();
            if ($inTransaction) {
                $this->pdo->beginTransaction();
            }

            // Lock the product row and determine the pre-movement quantity
            $checkStmt = $this->pdo->prepare("SELECT quantity FROM products WHERE id = ? FOR UPDATE");
            if (!$checkStmt->execute([$productId])) {
                throw new RuntimeException('Product quantity check failed.');
            }
            $product = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new Exception("Product not found with ID: {$productId}");
            }

            $currentQuantity = $product['quantity'];
            $newQuantity = $currentQuantity + $quantityChange;
            if ($newQuantity < 0) {
                throw new Exception("Insufficient stock for product ID {$productId}. Available: {$currentQuantity}, required: " . abs($quantityChange));
            }

            $stmt = $this->pdo->prepare("
                UPDATE products
                SET quantity = quantity + ?, updated_at = NOW()
                WHERE id = ?
            ");
            if (!$stmt->execute([$quantityChange, $productId])) {
                throw new RuntimeException('Product quantity update failed.');
            }

            // Record exactly one movement after the product update is secured.
            $movementStmt = $this->pdo->prepare("
                INSERT INTO stock_movements (
                    product_id, movement_date, movement_type, quantity_change,
                    reference_type, reference_id, reference_number, notes, user_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$movementStmt->execute([
                $productId,
                $movementDate,
                $movementType,
                $quantityChange,
                $referenceType,
                $referenceId,
                $referenceNumber,
                $notes,
                $userId
            ]) || $movementStmt->rowCount() !== 1) {
                throw new RuntimeException('Stock movement insert failed.');
            }

            // Determine opening stock from the prior closing or initial baseline.
            $openingStock = $this->getOpeningStockForDate($productId, $movementDate);

            // Insert or update daily stock record with opening stock
            // Closing stock will be calculated by closeDailyStock based on all movements
            $stmt = $this->pdo->prepare(
                "INSERT INTO daily_stock (product_id, stock_date, opening_stock, closing_stock)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     opening_stock = VALUES(opening_stock),
                     updated_at = NOW()"
            );
            if (!$stmt->execute([
                $productId,
                $movementDate,
                $openingStock,
                $openingStock // Initialize closing to opening, will be updated by closeDailyStock
            ])) {
                throw new RuntimeException('Daily stock insert/update failed.');
            }

            // Rebuild daily stock from the movement date onward so the next day opening
            // and all later rows inherit the corrected balance chain.
            if (!$this->recalculateDailyStock($movementDate, date('Y-m-d'), $productId)) {
                throw new RuntimeException('Daily stock recalculation failed.');
            }

            // Only commit if we started this transaction
            if ($inTransaction) {
                if (!$this->pdo->commit()) {
                    throw new RuntimeException('Stock movement transaction could not be committed.');
                }
                // Invalidate caches so dashboard and API reflect movements immediately
                $this->ensureCache();
                if (function_exists('cache_clear')) {
                    cache_clear();
                }
            }

            return true;
        } catch (Throwable $e) {
            // Only rollback if we started this transaction
            if ($inTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }
            error_log("Failed to record stock movement: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Close daily stock (calculate final closing stock from movements)
     */
    public function closeDailyStock($date = null)
    {
        $date = $date ?: date('Y-m-d');
        $startedTransaction = false;

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $nextDay = date('Y-m-d', strtotime($date . ' +1 day'));
            $lockStmt = $this->pdo->prepare(
                "SELECT product_id
                 FROM daily_stock
                 WHERE stock_date IN (?, ?)
                 FOR UPDATE"
            );
            if (!$lockStmt->execute([$date, $nextDay])) {
                throw new RuntimeException('Daily stock lock failed.');
            }

            $productStmt = $this->pdo->prepare("SELECT id FROM products");
            if (!$productStmt->execute()) {
                throw new RuntimeException('Product list query failed.');
            }
            $products = $productStmt->fetchAll(PDO::FETCH_COLUMN);
            $damageQuantities = $this->getDamageQuantitiesByDate($date);

            foreach ($products as $productId) {
                $openingStock = $this->getOpeningStockForDate($productId, $date);

                $supplyStmt = $this->pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN movement_type = 'supply' THEN quantity_change ELSE 0 END), 0) +
                           COALESCE(SUM(CASE WHEN movement_type = 'adjustment' THEN quantity_change ELSE 0 END), 0) AS net_supply
                    FROM stock_movements
                    WHERE product_id = ? AND DATE(movement_date) = ? AND movement_type IN ('supply', 'adjustment')
                ");
                if (!$supplyStmt->execute([$productId, $date])) {
                    throw new RuntimeException("Supply calculation failed for product {$productId}.");
                }
                $supplyQty = floatval($supplyStmt->fetchColumn() ?: 0);

                $salesQuantities = $this->getSalesQuantitiesForDate($productId, $date);
                $netSalesQty = $salesQuantities['net_sales_qty'];
                $damageQty = floatval($damageQuantities[$productId] ?? 0.0);

                $closingStock = max(0.0, $openingStock + $supplyQty - $netSalesQty - $damageQty);

                $upsertStmt = $this->pdo->prepare("
                    INSERT INTO daily_stock (product_id, stock_date, opening_stock, closing_stock)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        opening_stock = VALUES(opening_stock),
                        closing_stock = VALUES(closing_stock),
                        updated_at = NOW()
                ");
                if (!$upsertStmt->execute([$productId, $date, $openingStock, $closingStock])) {
                    throw new RuntimeException("Daily stock close failed for product {$productId}.");
                }
            }

            foreach ($products as $productId) {
                $currentStmt = $this->pdo->prepare("SELECT closing_stock FROM daily_stock WHERE product_id = ? AND stock_date = ?");
                if (!$currentStmt->execute([$productId, $date])) {
                    throw new RuntimeException("Daily stock readback failed for product {$productId}.");
                }
                $closingValue = $currentStmt->fetchColumn();
                if ($closingValue === false || $closingValue === null) {
                    throw new RuntimeException("Closed stock readback failed for product {$productId}.");
                }
                $nextUpsertStmt = $this->pdo->prepare("
                    INSERT INTO daily_stock (product_id, stock_date, opening_stock, closing_stock)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        opening_stock = VALUES(opening_stock),
                        updated_at = NOW()
                ");
                if (!$nextUpsertStmt->execute([$productId, $nextDay, $closingValue, $closingValue])) {
                    throw new RuntimeException("Next-day stock initialization failed for product {$productId}.");
                }
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return true;
        } catch (Exception $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Failed to close daily stock: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fix any negative stock values so stock bottoms out at zero
     */
    public function fixNegativeStock()
    {
        try {
            $results = [];

            $stmt = $this->pdo->prepare("UPDATE products SET quantity = 0 WHERE quantity < 0");
            $stmt->execute();
            $results['products_corrected'] = $stmt->rowCount();

            $stmt = $this->pdo->prepare("UPDATE daily_stock SET opening_stock = 0 WHERE opening_stock < 0");
            $stmt->execute();
            $results['daily_openings_corrected'] = $stmt->rowCount();

            $stmt = $this->pdo->prepare("UPDATE daily_stock SET closing_stock = 0 WHERE closing_stock < 0");
            $stmt->execute();
            $results['daily_closings_corrected'] = $stmt->rowCount();

            return $results;
        } catch (Exception $e) {
            error_log("Failed to fix negative stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current stock status for a product
     */
    public function getCurrentStock($productId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM current_stock_status
                WHERE id = ?
            ");
            $stmt->execute([$productId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get current stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get daily stock report
     */
    public function getDailyStockReport($date = null)
    {
        $date = $date ?: date('Y-m-d');

        try {
            // Try to use the view first if it exists, then fall back to direct query
            $stmt = $this->pdo->prepare("
                SELECT ds.id, ds.product_id, ds.stock_date, ds.opening_stock, ds.closing_stock,
                       p.name as product_name, p.sku, p.unit
                FROM daily_stock ds
                JOIN products p ON ds.product_id = p.id
                WHERE ds.stock_date = ?
                ORDER BY p.name
            ");
            $stmt->execute([$date]);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($result)) {
                // Return empty array if no records found
                return [];
            }

            return $result;
        } catch (Exception $e) {
            error_log("Failed to get daily stock report: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Recalculate daily stock from movements for a date range
     * This ensures daily_stock table is always consistent with stock_movements
     */
    public function recalculateDailyStock($startDate, $endDate = null, $productId = null)
    {
        $endDate = $endDate ?: date('Y-m-d');

        try {
            $productIds = [];
            if ($productId) {
                $productIds[] = $productId;
            } else {
                $productStmt = $this->pdo->prepare("SELECT id FROM products ORDER BY id");
                $productStmt->execute();
                $productIds = $productStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $currentDate = $startDate;
            $lastComputedClosingByProduct = [];
            while ($currentDate <= $endDate) {
                $damageQuantities = $this->getDamageQuantitiesByDate($currentDate);
                foreach ($productIds as $pid) {
                    if (array_key_exists($pid, $lastComputedClosingByProduct) && $lastComputedClosingByProduct[$pid] !== null) {
                        $openingStock = floatval($lastComputedClosingByProduct[$pid]);
                    } else {
                        $openingStock = $this->getOpeningStockForDate($pid, $currentDate);
                    }

                    $supplyStmt = $this->pdo->prepare("
                        SELECT COALESCE(SUM(CASE WHEN movement_type = 'supply' THEN quantity_change ELSE 0 END), 0) +
                               COALESCE(SUM(CASE WHEN movement_type = 'adjustment' THEN quantity_change ELSE 0 END), 0) AS net_supply
                        FROM stock_movements
                        WHERE product_id = ? AND DATE(movement_date) = ? AND movement_type IN ('supply', 'adjustment')
                    ");
                    if (!$supplyStmt->execute([$pid, $currentDate])) {
                        throw new RuntimeException("Supply calculation failed for product {$pid} on {$currentDate}.");
                    }
                    $supplyQty = floatval($supplyStmt->fetchColumn() ?: 0);

                    $salesQuantities = $this->getSalesQuantitiesForDate($pid, $currentDate);
                    $netSalesQty = $salesQuantities['net_sales_qty'];
                    $damageQty = floatval($damageQuantities[$pid] ?? 0.0);

                    $closingStock = max(0.0, $openingStock + $supplyQty - $netSalesQty - $damageQty);

                    $upsertStmt = $this->pdo->prepare("
                        INSERT INTO daily_stock (product_id, stock_date, opening_stock, closing_stock)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            opening_stock = VALUES(opening_stock),
                            closing_stock = VALUES(closing_stock),
                            updated_at = NOW()
                    ");
                    if (!$upsertStmt->execute([$pid, $currentDate, $openingStock, $closingStock])) {
                        throw new RuntimeException("Daily stock update failed for product {$pid} on {$currentDate}.");
                    }
                    $lastComputedClosingByProduct[$pid] = $closingStock;
                }

                $nextDay = date('Y-m-d', strtotime($currentDate . ' +1 day'));
                foreach ($productIds as $pid) {
                    $currentStmt = $this->pdo->prepare("SELECT closing_stock FROM daily_stock WHERE product_id = ? AND stock_date = ?");
                    if (!$currentStmt->execute([$pid, $currentDate])) {
                        throw new RuntimeException("Daily stock readback failed for product {$pid} on {$currentDate}.");
                    }
                    $closingValue = $currentStmt->fetchColumn();
                    if ($closingValue === false || $closingValue === null) {
                        continue;
                    }
                    $nextUpsertStmt = $this->pdo->prepare("
                        INSERT INTO daily_stock (product_id, stock_date, opening_stock, closing_stock)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            opening_stock = VALUES(opening_stock),
                            updated_at = NOW()
                    ");
                    if (!$nextUpsertStmt->execute([$pid, $nextDay, $closingValue, $closingValue])) {
                        throw new RuntimeException("Next-day stock update failed for product {$pid} on {$nextDay}.");
                    }
                }

                $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
            }

            return true;
        } catch (Exception $e) {
            error_log("Failed to recalculate daily stock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get stock movements for a date range
     */
    public function getStockMovements($startDate, $endDate = null, $productId = null)
    {
        $endDate = $endDate ?: date('Y-m-d');

        try {
            $query = "
                SELECT sm.*, p.name as product_name, p.sku, u.name as user_name
                FROM stock_movements sm
                JOIN products p ON sm.product_id = p.id
                LEFT JOIN users u ON sm.user_id = u.id
                WHERE sm.movement_date BETWEEN ? AND ?
            ";
            $params = [$startDate, $endDate];

            if ($productId) {
                $query .= " AND sm.product_id = ?";
                $params[] = $productId;
            }

            $query .= " ORDER BY sm.movement_date DESC, sm.created_at DESC";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get stock movements: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if daily stock is initialized for today
     */
    public function isDailyStockInitialized($date = null)
    {
        $date = $date ?: date('Y-m-d');

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM daily_stock
                WHERE stock_date = ?
            ");
            $stmt->execute([$date]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
        } catch (Exception $e) {
            error_log("Failed to check daily stock initialization: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get opening stock for a product on a specific date
     */
    public function getOpeningStock($productId, $date = null)
    {
        $date = $date ?: date('Y-m-d');

        try {
            $stmt = $this->pdo->prepare("
                SELECT opening_stock
                FROM daily_stock
                WHERE product_id = ? AND stock_date = ?
            ");
            $stmt->execute([$productId, $date]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['opening_stock'] : null;
        } catch (Exception $e) {
            error_log("Failed to get opening stock: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get closing stock for a product on a specific date
     */
    public function getClosingStock($productId, $date = null)
    {
        $date = $date ?: date('Y-m-d');

        try {
            $stmt = $this->pdo->prepare("
                SELECT closing_stock
                FROM daily_stock
                WHERE product_id = ? AND stock_date = ?
            ");
            $stmt->execute([$productId, $date]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['closing_stock'] : null;
        } catch (Exception $e) {
            error_log("Failed to get closing stock: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Helper functions for stock management
 */

/**
 * Initialize stock manager
 */
function getStockManager()
{
    global $pdo;
    return new StockManager($pdo);
}

/**
 * Record sale stock movement
 */
function recordSaleMovement($saleId, $productId, $quantity, $userId = null)
{
    $stockManager = getStockManager();
    return $stockManager->recordMovement(
        $productId,
        'sale',
        -$quantity, // Negative for reduction
        'sale',
        $saleId,
        "Sale #$saleId",
        "Sale transaction",
        $userId
    );
}

/**
 * Record dispatch stock movement
 */
function recordDispatchMovement($dispatchId, $productId, $quantity, $userId = null)
{
    $stockManager = getStockManager();
    return $stockManager->recordMovement(
        $productId,
        'dispatch',
        -$quantity, // Negative for reduction
        'dispatch',
        $dispatchId,
        "Dispatch #$dispatchId",
        "Dispatch transaction",
        $userId
    );
}

/**
 * Record return stock movement
 */
function recordReturnMovement($referenceId, $productId, $quantity, $referenceType = 'manual', $userId = null, $movementDate = null, $referenceLabel = 'Return')
{
    $stockManager = getStockManager();
    return $stockManager->recordMovement(
        $productId,
        'return',
        $quantity, // Positive to add stock back to inventory
        $referenceType,
        $referenceId,
        $referenceLabel . " #$referenceId",
        $referenceLabel . " stock return record #$referenceId",
        $userId,
        $movementDate
    );
}


/**
 * Record supply stock movement
 */
function recordSupplyMovement($supplyId, $productId, $quantity, $userId = null)
{
    $stockManager = getStockManager();
    return $stockManager->recordMovement(
        $productId,
        'purchase',
        $quantity, // Positive for addition
        'manual',
        $supplyId,
        "Supply #$supplyId",
        "Supply/Inbound stock",
        $userId
    );
}

/**
 * Create a new supply record (simplified - only date)
 */
function createSupply($supplyDate, $userId, $supplierName = 'Unknown Supplier')
{
    global $pdo;

    try {
        // Generate unique supply number
        $supplyNumber = 'SUP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("
            INSERT INTO supplies (
                supply_number, supply_date, supplier_name, created_by
            ) VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$supplyNumber, $supplyDate, $supplierName, $userId]);

        $supplyId = $pdo->lastInsertId();
        if ($stmt->rowCount() !== 1 || !$supplyId) {
            throw new RuntimeException('Supply record was not saved.');
        }

        return $supplyId;
    } catch (Exception $e) {
        error_log("Failed to create supply: " . $e->getMessage());
        error_log("Supply date: $supplyDate, User ID: $userId");
        throw $e; // Re-throw to show the actual error
    }
}

/**
 * Add item to supply and update stock (simplified)
 */
function addSupplyItem($supplyId, $productId, $quantity, $unitCost, $batchNumber, $expiryDate, $notes, $userId)
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get supply date
        $stmt = $pdo->prepare("SELECT supply_date FROM supplies WHERE id = ?");
        $stmt->execute([$supplyId]);
        $supply = $stmt->fetch();

        if (!$supply) {
            throw new Exception("Supply not found with ID: $supplyId");
        }

        $supplyDate = $supply['supply_date'];
        $date = DateTime::createFromFormat('!Y-m-d', $supplyDate);
        if (!$date || $date->format('Y-m-d') !== $supplyDate) {
            throw new InvalidArgumentException('Supply has an invalid date.');
        }

        $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ? AND is_active = TRUE FOR UPDATE");
        $stmt->execute([$productId]);
        if (!$stmt->fetch()) {
            throw new InvalidArgumentException('The selected product does not exist or is inactive.');
        }

        $stmt = $pdo->prepare("SELECT id FROM supply_items WHERE supply_id = ? AND product_id = ? LIMIT 1");
        $stmt->execute([$supplyId, $productId]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('This product is already listed on the selected supply.');
        }

        // Insert supply item (simplified - only product_id and quantity)
        $stmt = $pdo->prepare("
            INSERT INTO supply_items (
                supply_id, product_id, quantity
            ) VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $supplyId,
            $productId,
            $quantity
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Supply item was not saved.');
        }

        // Use StockManager for automatic stock recalculation
        $stockManager = getStockManager();
        $stockManager->recordMovement(
            $productId,
            'supply',
            $quantity,
            'manual', // Use 'manual' instead of 'supply' for reference_type (enum constraint)
            $supplyId,
            "SUP-$supplyId",
            "Supply",
            $userId,
            $supplyDate
        );

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to add supply item: " . $e->getMessage());
        error_log("Supply ID: $supplyId, Product ID: $productId, Quantity: $quantity");
        throw $e; // Re-throw to show the actual error
    }
}

/**
 * Initialize daily stock for today
 */
function initializeTodayStock()
{
    $stockManager = getStockManager();
    return $stockManager->initializeDailyStock();
}

/**
 * Close daily stock for today
 */
function closeTodayStock()
{
    $stockManager = getStockManager();
    return $stockManager->closeDailyStock();
}

