<?php
/**
 * Daily Dispatcher's Record
 * 
 * Records all daily dispatcher activities:
 * - Products returned to stock
 * - Cash collected
 * - Transfer payments received
 * - Credit sales
 */

require_once '../../init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/stock_manager.php';

$can_edit = $can_edit ?? in_array($_SESSION['role'] ?? '', ['admin', 'cashier', 'shop_attendant', 'dispatcher']);
$deleteActivityCsrfToken = hasRole(['admin']) ? SecurityUtils::generateCSRFToken() : '';
$error = '';
$success = '';

if (!empty($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Check if user is shop attendant - auto-set to Shop Sales dispatcher
$is_shop_attendant = isShopAttendant();
$shop_driver_id = null;
$shop_driver_name = 'Shop Sales';

if ($is_shop_attendant) {
    try {
        $stmt = executeQuery("SELECT id FROM drivers WHERE name = 'Shop Sales' LIMIT 1");
        $shopDriver = $stmt->fetch();
        if ($shopDriver) {
            $shop_driver_id = $shopDriver['id'];
        }
    } catch (PDOException $e) {
        // If Shop Sales driver doesn't exist, we'll handle it later
    }
}

$driver_id = intval($_GET['driver_id'] ?? $_POST['driver_id'] ?? 0);
$dispatch_id = intval($_GET['dispatch_id'] ?? $_POST['dispatch_id'] ?? 0);
$activity_id = intval($_GET['activity_id'] ?? $_POST['activity_id'] ?? 0);
$record_date = $_GET['record_date'] ?? $_POST['record_date'] ?? date('Y-m-d');
$selected_driver_name = '';
$selected_dispatch = null;
$edit_activity = null;

function validateDispatchedDamage(PDO $pdo, $dispatchId, $productId, $quantity, $excludeActivityId = 0)
{
    $itemStmt = $pdo->prepare(
        "SELECT di.quantity, di.quantity_sold, p.name, p.is_active
         FROM dispatch_items di
         JOIN products p ON p.id = di.product_id
         WHERE di.dispatch_id = ? AND di.product_id = ?
         FOR UPDATE"
    );
    if (!$itemStmt->execute([$dispatchId, $productId])) {
        throw new RuntimeException('Damaged product dispatch validation failed.');
    }
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new InvalidArgumentException('The damaged product is not part of the selected dispatch.');
    }
    if (!(bool) $item['is_active']) {
        throw new InvalidArgumentException('The damaged product is inactive.');
    }

    $goodReturnStmt = $pdo->prepare(
           "SELECT COALESCE(SUM(quantity), 0)
         FROM daily_returns
         WHERE dispatch_id = ? AND product_id = ? AND quantity > 0"
    );
        if (!$goodReturnStmt->execute([$dispatchId, $productId])) {
        throw new RuntimeException('Good-return validation failed.');
    }

    $dispatchReturnStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity_returned), 0)
         FROM dispatch_returns
         WHERE dispatch_id = ? AND product_id = ? AND quantity_returned > 0"
    );
    if (!$dispatchReturnStmt->execute([$dispatchId, $productId])) {
        throw new RuntimeException('Existing damaged-return validation failed.');
    }

    $damageStmt = $pdo->prepare(
        "SELECT id, damaged_products_note
         FROM daily_activities
         WHERE dispatch_id = ? AND damaged_products > 0"
        . ($excludeActivityId ? " AND id <> ?" : '')
    );
    $damageParams = [$dispatchId];
    if ($excludeActivityId) {
        $damageParams[] = $excludeActivityId;
    }
    if (!$damageStmt->execute($damageParams)) {
        throw new RuntimeException('Existing trip-damage validation failed.');
    }

    $recordedDamage = 0.0;
    $productName = strtolower(trim($item['name']));
    foreach ($damageStmt->fetchAll(PDO::FETCH_ASSOC) as $damageRow) {
        $note = trim($damageRow['damaged_products_note'] ?? '');
        if (preg_match('/Damaged\s*([\d.,]+)\s*units?\s*of\s*(.+)$/i', $note, $matches)) {
            $noteProduct = strtolower(trim(rtrim($matches[2], '. ')));
            if ($noteProduct === $productName
                || strpos($productName, $noteProduct) !== false
                || strpos($noteProduct, $productName) !== false) {
                $recordedDamage += (float) str_replace(',', '', $matches[1]);
            }
        }
    }

    $remaining = (float) $item['quantity']
        - (float) $item['quantity_sold']
        - (float) $goodReturnStmt->fetchColumn()
        - (float) $dispatchReturnStmt->fetchColumn()
        - $recordedDamage;
    if (!is_finite($remaining) || $remaining < 0 || $quantity > $remaining) {
        throw new InvalidArgumentException('Damaged quantity exceeds the unaccounted dispatch quantity.');
    }
}

function validateDispatchedReturn(PDO $pdo, $dispatchId, $productId, $quantity, $excludeReturnId = 0)
{
    $itemStmt = $pdo->prepare(
        "SELECT di.quantity, di.quantity_sold, di.unit_price, di.discount_amount, p.name
         FROM dispatch_items di
         JOIN products p ON p.id = di.product_id
         WHERE di.dispatch_id = ? AND di.product_id = ?
         FOR UPDATE"
    );
    if (!$itemStmt->execute([$dispatchId, $productId])) {
        throw new RuntimeException('Return product dispatch validation failed.');
    }
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new InvalidArgumentException('The return product is not part of the selected dispatch.');
    }

    $goodReturnStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity), 0)
         FROM daily_returns
         WHERE dispatch_id = ? AND product_id = ? AND quantity > 0"
            . ($excludeReturnId ? ' AND id <> ?' : '')
    );
    $goodReturnParams = [$dispatchId, $productId];
    if ($excludeReturnId) {
        $goodReturnParams[] = $excludeReturnId;
    }
    $dispatchReturnStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity_returned), 0)
         FROM dispatch_returns
         WHERE dispatch_id = ? AND product_id = ? AND quantity_returned > 0"
    );
    if (!$goodReturnStmt->execute($goodReturnParams)) {
        throw new RuntimeException('Good-return validation failed.');
    }
    if (!$dispatchReturnStmt->execute([$dispatchId, $productId])) {
        throw new RuntimeException('Existing dispatch-return validation failed.');
    }

    $damageStmt = $pdo->prepare(
        "SELECT damaged_products_note
         FROM daily_activities
         WHERE dispatch_id = ? AND damaged_products > 0"
    );
    if (!$damageStmt->execute([$dispatchId])) {
        throw new RuntimeException('Existing trip-damage validation failed.');
    }

    $recordedDamage = 0.0;
    $productName = strtolower(trim($item['name']));
    foreach ($damageStmt->fetchAll(PDO::FETCH_ASSOC) as $damageRow) {
        $note = trim($damageRow['damaged_products_note'] ?? '');
        if (preg_match('/Damaged\s*([\d.,]+)\s*units?\s*of\s*(.+)$/i', $note, $matches)) {
            $noteProduct = strtolower(trim(rtrim($matches[2], '. ')));
            if ($noteProduct === $productName
                || strpos($productName, $noteProduct) !== false
                || strpos($noteProduct, $productName) !== false) {
                $recordedDamage += (float) str_replace(',', '', $matches[1]);
            }
        }
    }

    $remaining = (float) $item['quantity']
        - (float) $item['quantity_sold']
        - (float) $goodReturnStmt->fetchColumn()
        - (float) $dispatchReturnStmt->fetchColumn()
        - $recordedDamage;
    if (!is_finite($remaining) || $remaining < 0 || $quantity > $remaining) {
        throw new InvalidArgumentException('Return quantity exceeds the unaccounted dispatch quantity.');
    }

    return $item;
}

function parseReturnProductId($value)
{
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('Please select a valid return product.');
    }
    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value) || (int) $value < 1) {
        throw new InvalidArgumentException('Please select a valid return product.');
    }
    return (int) $value;
}

function parseReturnQuantity($value)
{
    if (!is_scalar($value) || trim((string) $value) === '' || !is_numeric($value)) {
        throw new InvalidArgumentException('Return quantity must be a valid positive number.');
    }
    $quantity = (float) $value;
    if (!is_finite($quantity) || $quantity <= 0) {
        throw new InvalidArgumentException('Return quantity must be a valid positive number.');
    }
    return $quantity;
}

if (!defined('MERRYLAY_MAX_PAYMENT_AMOUNT')) {
    define('MERRYLAY_MAX_PAYMENT_AMOUNT', 99999999.99);
}

function parseControlledMoney($value, $fieldName)
{
    if (!is_scalar($value) || trim((string) $value) === '' || !is_numeric($value)) {
        throw new InvalidArgumentException($fieldName . ' must be a valid amount.');
    }

    $amount = (float) $value;
    if (!is_finite($amount) || $amount < 0 || $amount > MERRYLAY_MAX_PAYMENT_AMOUNT) {
        throw new InvalidArgumentException($fieldName . ' must be non-negative and within the permitted limit.');
    }
    if (abs($amount - round($amount, 2)) > 0.00000001) {
        throw new InvalidArgumentException($fieldName . ' may contain no more than two decimal places.');
    }

    return round($amount, 2);
}

function parseActivityCreditAllocations($amountValues, $nameValues)
{
    if (!is_array($amountValues) || !is_array($nameValues)) {
        throw new InvalidArgumentException('Credit amounts and customer identities must be valid lists.');
    }

    $allocations = [];
    foreach ($amountValues as $index => $rawAmount) {
        $amount = is_scalar($rawAmount) && trim((string) $rawAmount) === ''
            ? 0.0
            : parseControlledMoney($rawAmount, 'Credit amount');
        if ($amount <= 0) {
            continue;
        }

        $rawName = $nameValues[$index] ?? null;
        if (!is_scalar($rawName)) {
            throw new InvalidArgumentException('A valid customer name is required for each credit sale.');
        }
        $customerName = trim((string) $rawName);
        if ($customerName === '' || strlen($customerName) > 100) {
            throw new InvalidArgumentException('A customer name of 1 to 100 characters is required for each credit sale.');
        }

        $allocations[] = ['customer_name' => $customerName, 'amount' => $amount];
    }

    return $allocations;
}

function parseDailyActivityExpense($amountValue, $typeValue, $descriptionValue)
{
    if (!is_scalar($amountValue)) {
        throw new InvalidArgumentException('Enter a valid expense amount.');
    }

    $rawAmount = trim((string) $amountValue);
    if ($rawAmount === '') {
        $rawAmount = '0';
    }
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $rawAmount)) {
        throw new InvalidArgumentException('Expense amount must be a positive amount with no more than two decimal places.');
    }

    $amount = (float) $rawAmount;
    if (!is_finite($amount) || $amount < 0 || $amount > 99999999.99) {
        throw new InvalidArgumentException('Expense amount is outside the permitted limit.');
    }

    if ($amount === 0.0) {
        return ['amount' => 0.0, 'type' => '', 'description' => ''];
    }

    $allowedTypes = ['fuel', 'repair', 'maintenance', 'toll', 'parking', 'food', 'accommodation', 'other'];
    if (!is_scalar($typeValue) || !in_array((string) $typeValue, $allowedTypes, true)) {
        throw new InvalidArgumentException('Select a valid expense category.');
    }
    if (!is_scalar($descriptionValue)) {
        throw new InvalidArgumentException('Enter a description for the expense.');
    }

    $description = trim((string) $descriptionValue);
    if ($description === '' || strlen($description) > 255) {
        throw new InvalidArgumentException('Expense description is required and must be no more than 255 characters.');
    }

    return ['amount' => $amount, 'type' => (string) $typeValue, 'description' => $description];
}

function isValidActivityDate($value)
{
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function assertAssignedDispatch(PDO $pdo, $dispatchId, $driverId = null, $activityDate = null)
{
    $dispatchStmt = $pdo->prepare(
        'SELECT dispatcher_id, dispatcher_name, dispatch_date, status
         FROM dispatches WHERE id = ? LIMIT 1'
    );
    if (!$dispatchStmt->execute([(int) $dispatchId])) {
        throw new RuntimeException('Dispatcher assignment lookup failed.');
    }
    $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$dispatch || $dispatch['status'] === 'cancelled') {
        throw new InvalidArgumentException('The selected dispatch is missing or cancelled.');
    }
    if ($activityDate !== null
        && (!isValidActivityDate($activityDate) || $dispatch['dispatch_date'] !== $activityDate)) {
        throw new InvalidArgumentException('The selected dispatch does not match the activity date.');
    }

    if ($dispatch['dispatcher_id'] !== null) {
        $driverStmt = $pdo->prepare('SELECT id, name FROM drivers WHERE id = ? LIMIT 1');
        if (!$driverStmt->execute([(int) $dispatch['dispatcher_id']])) {
            throw new RuntimeException('Assigned driver lookup failed.');
        }
        $driver = $driverStmt->fetch(PDO::FETCH_ASSOC);
        if (!$driver || strcasecmp(trim((string) $dispatch['dispatcher_name']), trim((string) $driver['name'])) !== 0) {
            throw new InvalidArgumentException('The selected dispatch has an invalid driver assignment.');
        }
    } else {
        $driverStmt = $pdo->prepare('SELECT id, name FROM drivers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))');
        if (!$driverStmt->execute([$dispatch['dispatcher_name']])) {
            throw new RuntimeException('Assigned driver lookup failed.');
        }
        $drivers = $driverStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($drivers) !== 1) {
            throw new InvalidArgumentException('The selected dispatch has no unique driver assignment.');
        }
        $driver = $drivers[0];
    }

    if ($driverId !== null && (int) $driverId !== (int) $driver['id']) {
        throw new InvalidArgumentException('The selected driver does not match the dispatch assignment.');
    }
    return $driver;
}

function assertDispatcherPostOwnership(PDO $pdo, array $post)
{
    $driverId = null;
    if (isset($post['driver_id'])) {
        $driverId = filter_var($post['driver_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($driverId === false) {
            throw new InvalidArgumentException('Select a valid driver.');
        }
    }

    $dispatchId = null;
    if (isset($post['dispatch_id'])) {
        $dispatchId = filter_var($post['dispatch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($dispatchId === false) {
            throw new InvalidArgumentException('Select a valid dispatch.');
        }
        $activityDate = $post['record_date'] ?? null;
        if ($activityDate !== null && !is_string($activityDate)) {
            throw new InvalidArgumentException('Enter a valid activity date.');
        }
        assertAssignedDispatch($pdo, $dispatchId, $driverId, $activityDate);
    } elseif ($driverId !== null) {
        $driverStmt = $pdo->prepare("SELECT id FROM drivers WHERE id = ? AND status = 'active'");
        if (!$driverStmt->execute([$driverId]) || $driverStmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Select a valid active driver.');
        }
    }

    if (isset($post['activity_id'])) {
        $activityId = filter_var($post['activity_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($activityId === false) {
            throw new InvalidArgumentException('Select a valid activity.');
        }
        $activityStmt = $pdo->prepare('SELECT driver_id, dispatch_id, activity_date FROM daily_activities WHERE id = ?');
        if (!$activityStmt->execute([$activityId])) {
            throw new RuntimeException('Dispatcher activity lookup failed.');
        }
        $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);
        if (!$activity || ($dispatchId !== null && (int) $activity['dispatch_id'] !== $dispatchId)
            || ($driverId !== null && (int) $activity['driver_id'] !== $driverId)) {
            throw new InvalidArgumentException('The selected activity does not match the dispatch.');
        }
        assertAssignedDispatch($pdo, $activity['dispatch_id'], $activity['driver_id'], $activity['activity_date']);
    }

    if (isset($post['return_id'])) {
        $returnId = filter_var($post['return_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($returnId === false) {
            throw new InvalidArgumentException('Select a valid return.');
        }
        $returnStmt = $pdo->prepare('SELECT driver_id, dispatch_id, return_date FROM daily_returns WHERE id = ?');
        if (!$returnStmt->execute([$returnId])) {
            throw new RuntimeException('Dispatcher return lookup failed.');
        }
        $return = $returnStmt->fetch(PDO::FETCH_ASSOC);
        if (!$return || ($dispatchId !== null && (int) $return['dispatch_id'] !== $dispatchId)
            || ($driverId !== null && (int) $return['driver_id'] !== $driverId)) {
            throw new InvalidArgumentException('The selected return does not match the dispatch.');
        }
        assertAssignedDispatch($pdo, $return['dispatch_id'], $return['driver_id'], $return['return_date']);
    }
}

function assertActivityExpenseOwnership(PDO $pdo, $driverId, $driverName, $dispatchId, $activityDate)
{
    if (!isValidActivityDate($activityDate)) {
        throw new InvalidArgumentException('Enter a valid activity date.');
    }
    if (!canRecordFinancials() || !hasRole(['admin', 'cashier', 'dispatcher'])) {
        throw new InvalidArgumentException('You are not permitted to record trip expenses.');
    }
    $dispatchStmt = $pdo->prepare(
        'SELECT dispatcher_id, dispatcher_name, dispatch_date, status
         FROM dispatches WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$dispatchStmt->execute([(int) $dispatchId])) {
        throw new RuntimeException('Expense dispatch validation failed.');
    }
    $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$dispatch
        || $dispatch['status'] === 'cancelled'
        || ($dispatch['dispatcher_id'] !== null && (int) $dispatch['dispatcher_id'] !== (int) $driverId)
        || strcasecmp(trim((string) $dispatch['dispatcher_name']), trim((string) $driverName)) !== 0
        || $dispatch['dispatch_date'] !== $activityDate) {
        throw new InvalidArgumentException('The selected dispatch does not belong to the authorized dispatcher and date.');
    }
}

function reconcileDailyActivityExpense(PDO $pdo, $activityId, $dispatchId, $dispatcherName, $expenseDate, array $expense)
{
    $marker = '[daily_activity_id:' . (int) $activityId . ']';
    $existingStmt = $pdo->prepare('SELECT id FROM dispatcher_expenses WHERE notes = ? FOR UPDATE');
    if (!$existingStmt->execute([$marker])) {
        throw new RuntimeException('Existing activity expense lookup failed.');
    }
    $existingIds = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($existingIds) > 1) {
        throw new RuntimeException('Duplicate activity expense links require administrator review.');
    }

    if ($existingIds) {
        $write = $pdo->prepare(
            'UPDATE dispatcher_expenses
             SET dispatch_id = ?, dispatcher_name = ?, expense_date = ?, expense_type = ?,
                 description = ?, amount = ?, recorded_by = ?
             WHERE id = ?'
        );
        $saved = $write->execute([
            $expense['amount'] > 0 ? (int) $dispatchId : null,
            $dispatcherName,
            $expenseDate,
            $expense['amount'] > 0 ? $expense['type'] : 'other',
            $expense['amount'] > 0 ? $expense['description'] : 'Expense cleared from activity',
            $expense['amount'],
            $_SESSION['user_id'],
            (int) $existingIds[0]
        ]);
    } elseif ($expense['amount'] > 0) {
        $write = $pdo->prepare(
            'INSERT INTO dispatcher_expenses
             (dispatch_id, dispatcher_name, expense_date, expense_type, description, amount, payment_method, notes, recorded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, \'cash\', ?, ?, NOW())'
        );
        $saved = $write->execute([
            (int) $dispatchId,
            $dispatcherName,
            $expenseDate,
            $expense['type'],
            $expense['description'],
            $expense['amount'],
            $marker,
            $_SESSION['user_id']
        ]);
    } else {
        return;
    }

    if (!$saved) {
        throw new RuntimeException('Activity expense could not be reconciled.');
    }
}

function reconcileActivityCreditLedger(PDO $pdo, $activityId, $driverId, $driverName, $creditDate, $oldActivityCredit, array $allocations)
{
    $detailStmt = $pdo->prepare(
        'SELECT id, customer_name, amount
         FROM daily_activity_credits
         WHERE daily_activity_id = ?
         ORDER BY id
         FOR UPDATE'
    );
    if (!$detailStmt->execute([$activityId])) {
        throw new RuntimeException('Existing activity credit validation failed.');
    }
    $oldDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
    $oldDetailTotal = round(array_sum(array_map(static function ($detail) {
        return (float) $detail['amount'];
    }, $oldDetails)), 2);
    if (abs($oldDetailTotal - round((float) $oldActivityCredit, 2)) > 0.009) {
        throw new RuntimeException('This activity credit does not match its saved credit details and cannot be safely edited.');
    }

    $oldLedger = [];
    foreach ($oldDetails as $detail) {
        $marker = 'DAILY_ACTIVITY_CREDIT:' . (int) $detail['id'];
        $ledgerStmt = $pdo->prepare(
            'SELECT id, creditor_name, credit_amount, amount_paid, driver_id, credit_date
             FROM creditors
             WHERE notes = ?
             FOR UPDATE'
        );
        if (!$ledgerStmt->execute([$marker])) {
            throw new RuntimeException('Existing creditor credit validation failed.');
        }
        $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);
        if ((float) $detail['amount'] > 0 && count($ledgerRows) !== 1) {
            throw new RuntimeException('This historical credit has no unique activity-linked creditor record and cannot be safely edited.');
        }
        if (count($ledgerRows) > 1) {
            throw new RuntimeException('This activity credit has duplicate creditor links and cannot be safely edited.');
        }
        $ledger = $ledgerRows[0] ?? null;
        if ($ledger && (
            strcasecmp(trim((string) $ledger['creditor_name']), trim((string) $detail['customer_name'])) !== 0
            || (int) $ledger['driver_id'] !== (int) $driverId
            || $ledger['credit_date'] !== $creditDate
            || abs(round((float) $ledger['credit_amount'], 2) - round((float) $detail['amount'], 2)) > 0.009
            || round((float) $ledger['amount_paid'], 2) > round((float) $ledger['credit_amount'], 2)
        )) {
            throw new RuntimeException('The activity-linked creditor record does not match its saved details.');
        }
        $oldLedger[(int) $detail['id']] = $ledger;
    }

    if (!$oldDetails && round((float) $oldActivityCredit, 2) > 0) {
        throw new RuntimeException('This historical credit has no activity-linked details and cannot be safely edited.');
    }

    $usedDetails = [];
    foreach ($allocations as $allocation) {
        $matchedDetail = null;
        foreach ($oldDetails as $detail) {
            $detailId = (int) $detail['id'];
            if (!isset($usedDetails[$detailId])
                && strcasecmp(trim((string) $detail['customer_name']), $allocation['customer_name']) === 0) {
                $matchedDetail = $detail;
                break;
            }
        }

        if ($matchedDetail) {
            $detailId = (int) $matchedDetail['id'];
            $ledger = $oldLedger[$detailId];
            if (!$ledger) {
                $detailUpdate = $pdo->prepare('UPDATE daily_activity_credits SET customer_name = ?, amount = ? WHERE id = ?');
                if (!$detailUpdate->execute([$allocation['customer_name'], $allocation['amount'], $detailId])) {
                    throw new RuntimeException('Activity credit detail update failed.');
                }
                $ledgerInsert = $pdo->prepare(
                    "INSERT INTO creditors (creditor_name, credit_amount, amount_paid, balance, credit_date, driver_id, driver_name, status, notes, created_by, created_at)
                     VALUES (?, ?, 0, ?, ?, ?, ?, 'active', ?, ?, NOW())"
                );
                if (!$ledgerInsert->execute([
                    $allocation['customer_name'], $allocation['amount'], $allocation['amount'], $creditDate,
                    $driverId, $driverName, 'DAILY_ACTIVITY_CREDIT:' . $detailId, $_SESSION['user_id']
                ])) {
                    throw new RuntimeException('Creditor credit could not be recorded.');
                }
            } else {
                $paid = round((float) $ledger['amount_paid'], 2);
                if ($allocation['amount'] < $paid) {
                    throw new InvalidArgumentException('The edited credit cannot be less than payments already collected.');
                }
                $detailUpdate = $pdo->prepare('UPDATE daily_activity_credits SET customer_name = ?, amount = ? WHERE id = ?');
                if (!$detailUpdate->execute([$allocation['customer_name'], $allocation['amount'], $detailId])) {
                    throw new RuntimeException('Activity credit detail update failed.');
                }
                $balance = round($allocation['amount'] - $paid, 2);
                $status = $balance > 0 ? 'active' : 'cleared';
                $ledgerUpdate = $pdo->prepare(
                    'UPDATE creditors
                     SET creditor_name = ?, credit_amount = ?, balance = ?, status = ?,
                         cleared_at = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                if (!$ledgerUpdate->execute([
                    $allocation['customer_name'], $allocation['amount'], $balance, $status,
                    $balance > 0 ? null : date('Y-m-d H:i:s'), $ledger['id']
                ])) {
                    throw new RuntimeException('Creditor balance update failed.');
                }
            }
            $usedDetails[$detailId] = true;
            continue;
        }

        $detailInsert = $pdo->prepare(
            'INSERT INTO daily_activity_credits (daily_activity_id, customer_name, amount, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        if (!$detailInsert->execute([$activityId, $allocation['customer_name'], $allocation['amount']])) {
            throw new RuntimeException('Activity credit detail could not be recorded.');
        }
        $detailId = (int) $pdo->lastInsertId();
        $ledgerInsert = $pdo->prepare(
            "INSERT INTO creditors (creditor_name, credit_amount, amount_paid, balance, credit_date, driver_id, driver_name, status, notes, created_by, created_at)
             VALUES (?, ?, 0, ?, ?, ?, ?, 'active', ?, ?, NOW())"
        );
        if (!$ledgerInsert->execute([
            $allocation['customer_name'], $allocation['amount'], $allocation['amount'], $creditDate,
            $driverId, $driverName, 'DAILY_ACTIVITY_CREDIT:' . $detailId, $_SESSION['user_id']
        ])) {
            throw new RuntimeException('Creditor credit could not be recorded.');
        }
    }

    foreach ($oldDetails as $detail) {
        $detailId = (int) $detail['id'];
        if (isset($usedDetails[$detailId])) {
            continue;
        }
        $ledger = $oldLedger[$detailId];
        if ($ledger && (float) $ledger['amount_paid'] > 0) {
            throw new InvalidArgumentException('A credit with collected payments cannot be removed or reassigned.');
        }
        $detailUpdate = $pdo->prepare('UPDATE daily_activity_credits SET amount = 0 WHERE id = ?');
        if (!$detailUpdate->execute([$detailId])) {
            throw new RuntimeException('Removed activity credit could not be reconciled.');
        }
        if ($ledger) {
            $ledgerUpdate = $pdo->prepare(
                "UPDATE creditors
                 SET credit_amount = 0, balance = 0, status = 'cleared', cleared_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            );
            if (!$ledgerUpdate->execute([$ledger['id']])) {
                throw new RuntimeException('Removed creditor credit could not be reconciled.');
            }
        }
    }
}

function postedAmountIsNonZero($value)
{
    if (!is_scalar($value)) {
        return true;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }
    return !is_numeric($value) || (float) $value != 0.0;
}

function postedArrayHasNonZeroAmount($values)
{
    if (!is_array($values)) {
        return false;
    }
    foreach ($values as $value) {
        if (postedAmountIsNonZero($value)) {
            return true;
        }
    }
    return false;
}

/**
 * Record current dispatch sales with validated cash, transfer, and optional credit allocations.
 */
function recordDailyTripActivity(PDO $pdo, $driverId, $dispatchId, $recordDate, $activityId = 0)
{
    if (!canRecordFinancials() || !hasRole(['admin', 'cashier', 'dispatcher'])) {
        throw new RuntimeException('You are not permitted to record dispatch payments.');
    }
    $isEdit = (int) $activityId > 0;

    $cash = parseControlledMoney($_POST['cash_collected'] ?? 0, 'Cash amount');
    $transfer = parseControlledMoney($_POST['transfer_amount'] ?? 0, 'Transfer amount');
    $creditAllocations = parseActivityCreditAllocations(
        $_POST['credit_amounts'] ?? [],
        $_POST['credit_customer_names'] ?? []
    );
    $creditAmount = round(array_sum(array_column($creditAllocations, 'amount')), 2);
    $creditCustomerName = $creditAllocations[0]['customer_name'] ?? '';
    $activityExpense = parseDailyActivityExpense(
        $_POST['expenses_amount'] ?? '',
        $_POST['expense_type'] ?? '',
        $_POST['expenses_note'] ?? ''
    );
    if ($activityExpense['amount'] > 0 && !isValidActivityDate($recordDate)) {
        throw new InvalidArgumentException('Enter a valid activity date.');
    }
    if ($activityExpense['amount'] > 0
        && (!canRecordFinancials() || !hasRole(['admin', 'cashier', 'dispatcher']))) {
        throw new InvalidArgumentException('You are not permitted to record trip expenses.');
    }

    $rawDamageQuantity = $_POST['damaged_product_quantity'] ?? 0;
    $damageQuantity = is_scalar($rawDamageQuantity) && trim((string) $rawDamageQuantity) === ''
        ? 0.0
        : (is_scalar($rawDamageQuantity) && is_numeric($rawDamageQuantity) ? (float) $rawDamageQuantity : -1);
    if (!is_finite($damageQuantity) || $damageQuantity < 0 || $damageQuantity > 999999.99) {
        throw new InvalidArgumentException('Damaged quantity must be finite and within the permitted maximum.');
    }
    $damageProductId = (int) ($_POST['damaged_product_id'] ?? 0);
    if ($damageQuantity > 0 && $damageProductId < 1) {
        throw new InvalidArgumentException('Please select the damaged product for the damaged quantity entered.');
    }
    $rawDamagedValue = $_POST['damaged_products'] ?? 0;
    $damagedValue = is_scalar($rawDamagedValue) && trim((string) $rawDamagedValue) === ''
        ? 0.0
        : parseControlledMoney($rawDamagedValue, 'Damaged product value');
    $damagedNote = $_POST['damaged_products_note'] ?? '';
    if (!is_scalar($damagedNote)) {
        throw new InvalidArgumentException('Enter a valid damaged-products note.');
    }
    $damagedNote = trim((string) $damagedNote);

    $returnProductIds = $_POST['return_product_id'] ?? [];
    $returnQuantities = $_POST['return_quantity'] ?? [];
    if (!is_array($returnProductIds) || !is_array($returnQuantities)) {
        throw new InvalidArgumentException('Return products and quantities must be valid lists.');
    }
    $returnRows = [];
    $returnIndexes = array_unique(array_merge(array_keys($returnProductIds), array_keys($returnQuantities)));
    foreach ($returnIndexes as $index) {
        $rawProductId = $returnProductIds[$index] ?? '';
        $rawQuantity = $returnQuantities[$index] ?? '';
        $hasProduct = is_scalar($rawProductId) && trim((string) $rawProductId) !== '';
        $hasQuantity = is_scalar($rawQuantity) && trim((string) $rawQuantity) !== '';
        if (!$hasProduct && !$hasQuantity) {
            continue;
        }
        if (!$hasProduct && is_numeric($rawQuantity) && (float) $rawQuantity <= 0) {
            continue;
        }
        if (!$hasProduct) {
            throw new InvalidArgumentException('Please select a return product for the quantity entered.');
        }
        $returnRows[] = [
            'product_id' => parseReturnProductId($rawProductId),
            'quantity' => parseReturnQuantity($rawQuantity)
        ];
    }

    $driverStmt = $pdo->prepare('SELECT id, name FROM drivers WHERE id = ? FOR UPDATE');
    if (!$driverStmt->execute([$driverId])) {
        throw new RuntimeException('Driver validation failed.');
    }
    $driver = $driverStmt->fetch(PDO::FETCH_ASSOC);
    if (!$driver) {
        throw new InvalidArgumentException('The selected driver is invalid.');
    }
    $dispatchStmt = $pdo->prepare(
        'SELECT id, dispatch_number, dispatcher_id, dispatcher_name, dispatch_date, actual_sales_amount, status
         FROM dispatches WHERE id = ? FOR UPDATE'
    );
    if (!$dispatchStmt->execute([$dispatchId])) {
        throw new RuntimeException('Dispatch validation failed.');
    }
    $dispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC);
    if (!$dispatch) {
        throw new InvalidArgumentException('The selected dispatch was not found.');
    }
    if ($dispatch['status'] === 'cancelled') {
        throw new InvalidArgumentException('The selected dispatch is cancelled and cannot accept new activity.');
    }
    if (($dispatch['dispatcher_id'] !== null && (int) $dispatch['dispatcher_id'] !== (int) $driverId)
        || strcasecmp(trim((string) $dispatch['dispatcher_name']), trim((string) $driver['name'])) !== 0
        || date('Y-m-d', strtotime($dispatch['dispatch_date'])) !== $recordDate) {
        throw new InvalidArgumentException('The selected dispatch does not belong to the selected driver and date.');
    }

    $existingActivity = null;
    if ($isEdit) {
        $activityStmt = $pdo->prepare('SELECT id, driver_id, dispatch_id, activity_date, cash_collected, transfer_amount, credit_amount, expenses_amount FROM daily_activities WHERE id = ? FOR UPDATE');
        if (!$activityStmt->execute([$activityId])) {
            throw new RuntimeException('Activity validation failed.');
        }
        $existingActivity = $activityStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingActivity || (int) $existingActivity['driver_id'] !== (int) $driverId
            || (int) $existingActivity['dispatch_id'] !== (int) $dispatchId
            || $existingActivity['activity_date'] !== $recordDate) {
            throw new InvalidArgumentException('The activity does not belong to the selected dispatch.');
        }
    }
    if ($activityExpense['amount'] > 0 || (float) ($existingActivity['expenses_amount'] ?? 0) > 0) {
        if (!canRecordFinancials() || !hasRole(['admin', 'cashier', 'dispatcher'])) {
            throw new InvalidArgumentException('You are not permitted to record trip expenses.');
        }
    }
    if ($activityExpense['amount'] > 0) {
        assertActivityExpenseOwnership($pdo, $driverId, $driver['name'], $dispatchId, $recordDate);
    }
    $oldActivityCredit = round((float) ($existingActivity['credit_amount'] ?? 0), 2);
    $shouldReconcileCredit = $creditAmount > 0 || ($existingActivity && $oldActivityCredit > 0);

    $itemStmt = $pdo->prepare(
        'SELECT di.product_id, di.quantity, di.quantity_sold, di.unit_price, di.discount_amount,
            p.id AS product_exists, p.name, p.cost_price
         FROM dispatch_items di
         JOIN products p ON p.id = di.product_id
         WHERE di.dispatch_id = ?
         FOR UPDATE'
    );
    if (!$itemStmt->execute([$dispatchId])) {
        throw new RuntimeException('Dispatch product validation failed.');
    }
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        throw new InvalidArgumentException('The selected dispatch has no valid products.');
    }
    if ($damageQuantity > 0) {
        foreach ($items as $item) {
            if ((int) $item['product_id'] === $damageProductId) {
                $unitValue = (float) $item['unit_price'];
                if ($unitValue <= 0) {
                    $unitValue = (float) $item['cost_price'];
                }
                if ($unitValue <= 0) {
                    throw new InvalidArgumentException('Unable to determine unit value for the selected damaged product.');
                }
                $damagedValue = round($damageQuantity * $unitValue, 2);
                if ($damagedNote === '') {
                    $damagedNote = sprintf('Damaged %.2f units of %s.', $damageQuantity, trim($item['name']));
                }
                break;
            }
        }
    }

    $soldQuantities = $_POST['quantity_sold'] ?? null;
    if (!is_array($soldQuantities)) {
        throw new InvalidArgumentException('Enter the quantity sold for each dispatch product.');
    }

    $currentActivitySales = 0.0;
    $replacedActivitySales = 0.0;
    foreach ($items as $item) {
        $productId = (string) (int) $item['product_id'];
        if (!array_key_exists($productId, $soldQuantities)) {
            throw new InvalidArgumentException('A sold quantity is missing for a dispatch product.');
        }
        $quantity = parseControlledMoney($item['quantity'], 'Dispatch quantity');
        $previousQuantitySold = parseControlledMoney($item['quantity_sold'], 'Previous quantity sold');
        $quantitySold = parseControlledMoney($soldQuantities[$productId], 'Quantity sold');
        $unitPrice = parseControlledMoney($item['unit_price'], 'Selling price');
        $discount = parseControlledMoney($item['discount_amount'], 'Discount amount');
        if ($quantitySold > $quantity || $previousQuantitySold > $quantity) {
            throw new InvalidArgumentException('A dispatch quantity sold is greater than the dispatched quantity.');
        }
        $gross = $quantitySold * $unitPrice;
        if ($discount > $quantity * $unitPrice) {
            throw new InvalidArgumentException('A dispatch discount exceeds its selling value.');
        }
        $currentActivitySales += $gross - ($quantity > 0 ? ($quantitySold / $quantity) * $discount : 0);
        $previousGross = $previousQuantitySold * $unitPrice;
        $replacedActivitySales += $previousGross - ($quantity > 0 ? ($previousQuantitySold / $quantity) * $discount : 0);
    }
    $currentActivitySales = round($currentActivitySales, 2);
    if (!is_finite($currentActivitySales) || $currentActivitySales < 0 || $currentActivitySales > MERRYLAY_MAX_PAYMENT_AMOUNT) {
        throw new InvalidArgumentException('The calculated sales amount is outside the permitted limit.');
    }
    if ($shouldReconcileCredit
        && abs(round($cash + $transfer + $creditAmount, 2) - $currentActivitySales) > 0.009) {
        throw new InvalidArgumentException('Cash, transfer, and new credit must equal the current activity sales.');
    }
    if ($currentActivitySales <= 0 && $cash <= 0 && $transfer <= 0 && $creditAmount <= 0
        && $activityExpense['amount'] <= 0 && $damageQuantity <= 0 && $damagedValue <= 0
        && $damagedNote === '' && !$returnRows) {
        throw new InvalidArgumentException('Enter sales, a payment, a return, damage, or an expense for this trip.');
    }

    $activityTotalsStmt = $pdo->prepare(
        'SELECT id, cash_collected, transfer_amount, credit_amount
         FROM daily_activities
         WHERE dispatch_id = ? AND id <> ?
         FOR UPDATE'
    );
    if (!$activityTotalsStmt->execute([$dispatchId, $activityId])) {
        throw new RuntimeException('Existing dispatch activity validation failed.');
    }
    $previousPayments = 0.0;
    $previousCredits = 0.0;
    foreach ($activityTotalsStmt->fetchAll(PDO::FETCH_ASSOC) as $previousActivity) {
        $previousCash = parseControlledMoney($previousActivity['cash_collected'] ?? 0, 'Existing cash amount');
        $previousTransfer = parseControlledMoney($previousActivity['transfer_amount'] ?? 0, 'Existing transfer amount');
        $previousPayments += $previousCash + $previousTransfer;
        if ($shouldReconcileCredit) {
            $previousCredits += parseControlledMoney($previousActivity['credit_amount'] ?? 0, 'Existing credit amount');
        }
    }

    $priorRecognizedSales = max(0, (float) ($dispatch['actual_sales_amount'] ?? 0));
    if ($existingActivity) {
        $priorRecognizedSales = max(0, $priorRecognizedSales - $replacedActivitySales);
    }

    $currentSales = round($priorRecognizedSales + $currentActivitySales, 2);
    $projectedPayments = round($previousPayments + $previousCredits + $cash + $transfer + $creditAmount, 2);
    if (!is_finite($currentSales) || $currentSales < 0 || $currentSales > MERRYLAY_MAX_PAYMENT_AMOUNT) {
        throw new InvalidArgumentException('The projected dispatch sales amount is outside the permitted limit.');
    }
    if ($projectedPayments > $currentSales) {
        throw new InvalidArgumentException($shouldReconcileCredit
            ? 'Cash, transfer, and credit allocations cannot exceed the current dispatch sales.'
            : 'Cash and transfer payments cannot exceed the current dispatch sales.');
    }

    if ($activityId) {
        if ($shouldReconcileCredit) {
            $activityWrite = $pdo->prepare(
                'UPDATE daily_activities
                 SET cash_collected = ?, transfer_amount = ?, credit_amount = ?, credit_customer_name = ?,
                     expenses_amount = ?, expenses_note = ?, damaged_products = ?, damaged_products_note = ?,
                     recorded_by = ?, recorded_at = NOW()
                 WHERE id = ?'
            );
            $activityWriteSucceeded = $activityWrite->execute([
                $cash, $transfer, $creditAmount, $creditCustomerName,
                $activityExpense['amount'], $activityExpense['description'], $damagedValue, $damagedNote,
                $_SESSION['user_id'], $activityId
            ]);
        } else {
            $activityWrite = $pdo->prepare(
                'UPDATE daily_activities
                 SET cash_collected = ?, transfer_amount = ?, expenses_amount = ?, expenses_note = ?,
                     damaged_products = ?, damaged_products_note = ?, recorded_by = ?, recorded_at = NOW()
                 WHERE id = ?'
            );
            $activityWriteSucceeded = $activityWrite->execute([
                $cash, $transfer, $activityExpense['amount'], $activityExpense['description'],
                $damagedValue, $damagedNote, $_SESSION['user_id'], $activityId
            ]);
        }
        if (!$activityWriteSucceeded) {
            throw new RuntimeException('Current sales activity update failed.');
        }
    } else {
        $activityCheck = $pdo->prepare('SELECT id FROM daily_activities WHERE driver_id = ? AND activity_date = ? AND dispatch_id = ? FOR UPDATE');
        if (!$activityCheck->execute([$driverId, $recordDate, $dispatchId])) {
            throw new RuntimeException('Duplicate activity check failed.');
        }
        if ($activityCheck->fetchColumn()) {
            throw new RuntimeException('This dispatch activity has already been recorded. Refresh the page before trying again.');
        }
        if ($shouldReconcileCredit) {
            $activityWrite = $pdo->prepare(
                'INSERT INTO daily_activities
                 (driver_id, dispatch_id, driver_name, activity_date, cash_collected, transfer_amount,
                  credit_amount, credit_customer_name, expenses_amount, expenses_note,
                  damaged_products, damaged_products_note, recorded_by, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $activityWriteSucceeded = $activityWrite->execute([
                $driverId, $dispatchId, $driver['name'], $recordDate, $cash, $transfer,
                $creditAmount, $creditCustomerName, $activityExpense['amount'], $activityExpense['description'],
                $damagedValue, $damagedNote, $_SESSION['user_id']
            ]);
        } else {
            $activityWrite = $pdo->prepare(
                'INSERT INTO daily_activities
                 (driver_id, dispatch_id, driver_name, activity_date, cash_collected, transfer_amount,
                  expenses_amount, expenses_note, damaged_products, damaged_products_note, recorded_by, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $activityWriteSucceeded = $activityWrite->execute([
                $driverId, $dispatchId, $driver['name'], $recordDate, $cash, $transfer,
                $activityExpense['amount'], $activityExpense['description'], $damagedValue, $damagedNote,
                $_SESSION['user_id']
            ]);
        }
        if (!$activityWriteSucceeded) {
            throw new RuntimeException('Current sales activity could not be recorded.');
        }
        $activityId = (int) $pdo->lastInsertId();
    }

    if ($shouldReconcileCredit) {
        reconcileActivityCreditLedger(
            $pdo,
            $activityId,
            $driverId,
            $driver['name'],
            $recordDate,
            $oldActivityCredit,
            $creditAllocations
        );
    }

    reconcileDailyActivityExpense(
        $pdo,
        $activityId,
        $dispatchId,
        $driver['name'],
        $recordDate,
        $activityExpense
    );

    $soldQuantityUpdate = $pdo->prepare('UPDATE dispatch_items SET quantity_sold = ? WHERE dispatch_id = ? AND product_id = ?');
    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        if (!$soldQuantityUpdate->execute([
            parseControlledMoney($soldQuantities[(string) $productId], 'Quantity sold'),
            $dispatchId,
            $productId
        ])) {
            throw new RuntimeException('Sold quantity could not be saved.');
        }
    }
    if ($damageQuantity > 0) {
        validateDispatchedDamage($pdo, $dispatchId, $damageProductId, $damageQuantity, $activityId);
    }

    $dispatchUpdate = $pdo->prepare('UPDATE dispatches SET actual_sales_amount = ?, cash_collected = ?, transfer_collected = ? WHERE id = ?');
    if (!$dispatchUpdate->execute([$currentSales, $cash, $transfer, $dispatchId])) {
        throw new RuntimeException('Current dispatch sales amount update failed.');
    }

    foreach ($returnRows as $returnRow) {
        validateDispatchedReturn(
            $pdo,
            $dispatchId,
            $returnRow['product_id'],
            $returnRow['quantity']
        );
        $returnWrite = $pdo->prepare(
            'INSERT INTO daily_returns
             (driver_id, driver_name, product_id, dispatch_id, quantity, return_date, recorded_by, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        if (!$returnWrite->execute([
            $driverId,
            $driver['name'],
            $returnRow['product_id'],
            $dispatchId,
            $returnRow['quantity'],
            $recordDate,
            $_SESSION['user_id']
        ])) {
            throw new RuntimeException('Trip return could not be recorded.');
        }
        $returnId = (int) $pdo->lastInsertId();
        if (!recordReturnMovement($returnId, $returnRow['product_id'], $returnRow['quantity'], 'manual', $_SESSION['user_id'], $recordDate)) {
            throw new RuntimeException('Trip return stock movement could not be recorded.');
        }
    }

    foreach (['cash' => $cash, 'transfer' => $transfer] as $method => $amount) {
        $notes = ucfirst($method) . ' collected for trip #' . $dispatch['dispatch_number'];
        $paymentCheck = $pdo->prepare('SELECT id, amount FROM customer_payments WHERE dispatcher_name = ? AND payment_method = ? AND notes = ? FOR UPDATE');
        if (!$paymentCheck->execute([$driver['name'], $method, $notes])) {
            throw new RuntimeException('Existing payment check failed.');
        }
        $existing = $paymentCheck->fetchAll(PDO::FETCH_ASSOC);
        if ($isEdit && $existing) {
            if ($amount <= 0) {
                $deletePayment = $pdo->prepare('DELETE FROM customer_payments WHERE id = ?');
                foreach ($existing as $payment) {
                    if (!$deletePayment->execute([$payment['id']])) {
                        throw new RuntimeException('Existing payment could not be removed.');
                    }
                }
                continue;
            }

            $updatePayment = $pdo->prepare('UPDATE customer_payments SET amount = ? WHERE id = ?');
            if (!$updatePayment->execute([$amount, $existing[0]['id']])) {
                throw new RuntimeException('Existing payment could not be updated.');
            }
            if (count($existing) > 1) {
                $deletePayment = $pdo->prepare('DELETE FROM customer_payments WHERE id = ?');
                foreach (array_slice($existing, 1) as $payment) {
                    if (!$deletePayment->execute([$payment['id']])) {
                        throw new RuntimeException('Duplicate payment could not be removed.');
                    }
                }
            }
            continue;
        }
        if ($existing) {
            $existingAmount = round(array_sum(array_map(static function ($payment) {
                return (float) $payment['amount'];
            }, $existing)), 2);
            if ($amount <= 0) {
                continue;
            }
            if (abs($existingAmount - $amount) > 0.009) {
                throw new RuntimeException('A different payment for this dispatch already exists.');
            }
            continue;
        }
        if ($amount <= 0) {
            continue;
        }
        $paymentWrite = $pdo->prepare(
            "INSERT INTO customer_payments (dispatcher_name, payment_date, payment_method, amount, notes, recorded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$paymentWrite->execute([$driver['name'], $recordDate, $method, $amount, $notes, $_SESSION['user_id']])) {
            throw new RuntimeException('Payment record could not be saved.');
        }
    }

    return $activityId;
}

// Auto-set driver for shop attendants
if ($is_shop_attendant && $shop_driver_id) {
    $driver_id = $shop_driver_id;
}

// Load specific activity for editing if activity_id is provided
if ($activity_id) {
    try {
        $stmt = executeQuery(
            "SELECT da.*,
                    (SELECT de.expense_type FROM dispatcher_expenses de
                     WHERE de.notes = CONCAT('[daily_activity_id:', da.id, ']') LIMIT 1) AS expense_type
             FROM daily_activities da WHERE da.id = ?",
            [$activity_id]
        );
        $edit_activity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($edit_activity) {
            $driver_id = $edit_activity['driver_id'];
            $record_date = $edit_activity['activity_date'];
            $dispatch_id = intval($edit_activity['dispatch_id'] ?? 0);
        }
    } catch (PDOException $e) {
        error_log("Failed to load activity for editing: " . $e->getMessage());
    }
}

$drivers = [];
$dispatches = [];
$products = [];
$creditors = [];

try {
    $stmt = executeQuery("SELECT id, name FROM drivers WHERE status = 'active' ORDER BY name");
    $drivers = $stmt->fetchAll();
} catch (PDOException $e) {
    $drivers = [];
}

// Get creditors for the selected driver, or all active creditors if no driver selected
try {
    if ($driver_id) {
        $stmt = executeQuery("SELECT id, creditor_name, balance FROM creditors WHERE driver_id = ? AND status = 'active' ORDER BY creditor_name", [$driver_id]);
    } else {
        $stmt = executeQuery("SELECT id, creditor_name, balance FROM creditors WHERE status = 'active' ORDER BY creditor_name");
    }
    $creditors = $stmt->fetchAll();
} catch (PDOException $e) {
    $creditors = [];
}

if ($driver_id) {
    try {
        $stmt = executeQuery("SELECT name FROM drivers WHERE id = ?", [$driver_id]);
        $driverRow = $stmt->fetch();
        $selected_driver_name = $driverRow['name'] ?? '';
    } catch (PDOException $e) {
        $selected_driver_name = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isDispatcher()) {
    try {
        assertDispatcherPostOwnership(getDBConnection(), $_POST);
    } catch (Throwable $e) {
        error_log('Rejected dispatcher daily workflow POST for user ' . ($_SESSION['user_id'] ?? 'unknown') . ': ' . $e->getMessage());
        http_response_code(403);
        if (isset($_POST['load_dispatches']) || isset($_POST['load_products']) || isset($_POST['load_activity'])
            || isset($_POST['delete_return']) || isset($_POST['update_return'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'The selected driver does not match the dispatch assignment.']);
            exit();
        }
        exit('The selected driver does not match the dispatch assignment.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_dispatches'])) {
    header('Content-Type: application/json');
    $driverId = intval($_POST['driver_id'] ?? 0);
    $recordDate = $_POST['record_date'] ?? date('Y-m-d');
    $dispatchList = [];

    if ($driverId) {
        try {
            $stmt = executeQuery("SELECT name FROM drivers WHERE id = ?", [$driverId]);
            $driverRow = $stmt->fetch();
            $driverName = $driverRow['name'] ?? '';

            error_log("Loading dispatches for driverId=$driverId, driverName=$driverName, recordDate=$recordDate");

            if ($driverName) {
                // First, check all dispatches for this driver on this date (without date filter for debugging)
                $debugStmt = executeQuery(
                    "SELECT d.id, d.dispatch_number, d.dispatch_date, d.dispatcher_name, d.dispatcher_id
                     FROM dispatches d
                     WHERE (d.dispatcher_id = ? OR LOWER(TRIM(d.dispatcher_name)) = LOWER(TRIM(?)))
                     ORDER BY d.dispatch_date DESC, d.dispatch_number",
                    [$driverId, $driverName]
                );
                $allDispatches = $debugStmt->fetchAll();
                $stmt = executeQuery(
                    "SELECT d.id, d.dispatch_number, d.expected_amount, d.actual_sales_amount, d.dispatch_date, d.dispatcher_name
                         FROM dispatches d
                         WHERE (d.dispatcher_id = ? OR LOWER(TRIM(d.dispatcher_name)) = LOWER(TRIM(?)))
                             AND DATE(d.dispatch_date) = ?
                             AND COALESCE(d.status, '') <> 'cancelled'
                         ORDER BY d.dispatch_number",
                        [$driverId, $driverName, $recordDate]
                );
                $dispatchList = $stmt->fetchAll();
                error_log("Filtered dispatches for date $recordDate: " . json_encode($dispatchList));
            }
        } catch (PDOException $e) {
            error_log("Failed to load dispatches: " . $e->getMessage());
            $dispatchList = [];
        }
    }

    // Include debug info for easier client-side inspection
    $debugInfo = [
        'driverId' => $driverId,
        'driverName' => $driverName ?? null,
        'recordDate' => $recordDate,
        'dispatchCount' => count($dispatchList)
    ];
    echo json_encode(['dispatches' => $dispatchList, 'debug' => $debugInfo]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_products'])) {
    header('Content-Type: application/json');
    $dispatchId = intval($_POST['dispatch_id'] ?? 0);
    $productList = [];

    if ($dispatchId) {
        try {
            $stmt = executeQuery(
                "SELECT di.product_id AS id, p.name, di.unit_price, di.discount_amount, di.quantity AS dispatched_quantity, di.quantity_sold, p.cost_price
                 FROM dispatch_items di
                 JOIN products p ON di.product_id = p.id
                 WHERE di.dispatch_id = ?
                 ORDER BY p.name",
                [$dispatchId]
            );
            $productList = $stmt->fetchAll();
            foreach ($productList as &$product) {
                // Use full unit price (without discount) - discounts are tracked separately for account balancing
                $price = floatval($product['unit_price'] ?? 0);
                $product['price'] = $price > 0 ? $price : floatval($product['cost_price'] ?? 0);
            }
            unset($product);
        } catch (PDOException $e) {
            $productList = [];
        }
    }

    echo json_encode(['products' => $productList]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_activity'])) {
    header('Content-Type: application/json');
    $dispatchId = intval($_POST['dispatch_id'] ?? 0);
    $driverId = intval($_POST['driver_id'] ?? 0);
    $recordDate = $_POST['record_date'] ?? date('Y-m-d');
    
    $activity = null;
    $returns = [];
    
    if ($dispatchId && $driverId && $recordDate) {
        try {
            // Load activity only for the selected dispatch to avoid showing another trip's activity data.
            $query = "
                SELECT da.*,
                       (SELECT de.expense_type FROM dispatcher_expenses de
                        WHERE de.notes = CONCAT('[daily_activity_id:', da.id, ']') LIMIT 1) AS expense_type
                FROM daily_activities da
                WHERE da.driver_id = ? AND da.activity_date = ? AND da.dispatch_id = ?
            ";
            $params = [$driverId, $recordDate, $dispatchId];
            $stmt = executeQuery($query, $params);
            $activity = $stmt->fetch();
            if (!$activity) {
                error_log(sprintf('load_activity: no daily_activities row for driver_id=%d activity_date=%s dispatch_id=%d', $driverId, $recordDate, $dispatchId));
            }

            // Load returns for the selected dispatch
            $returnsQuery = "
                SELECT dr.*, p.name as product_name,
                       COALESCE(di.unit_price, p.cost_price, p.cost_price) as unit_price
                FROM daily_returns dr
                JOIN products p ON dr.product_id = p.id
                LEFT JOIN dispatch_items di ON dr.dispatch_id = di.dispatch_id AND dr.product_id = di.product_id
                WHERE dr.driver_id = ? AND dr.return_date = ? AND dr.dispatch_id = ?
                ORDER BY dr.recorded_at DESC
            ";
            $returnsStmt = executeQuery($returnsQuery, [$driverId, $recordDate, $dispatchId]);
            $returns = $returnsStmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to load activity data: " . $e->getMessage());
        }
    }
    
    echo json_encode(['activity' => $activity, 'returns' => $returns]);
    exit();
}

if ($driver_id && $record_date) {
    try {
        $stmt = executeQuery(
                        "SELECT d.id, d.dispatch_number, d.expected_amount, d.dispatch_date, d.dispatcher_name, COALESCE(d.sales_status, '') AS sales_status
             FROM dispatches d
             WHERE (d.dispatcher_id = ? OR LOWER(TRIM(d.dispatcher_name)) = LOWER(TRIM(?)))
               AND DATE(d.dispatch_date) = ?
                             AND COALESCE(d.status, '') <> 'cancelled'
             ORDER BY d.dispatch_number",
            [$driver_id, $selected_driver_name, $record_date]
        );
        $dispatches = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to load dispatches: " . $e->getMessage());
        $dispatches = [];
    }
}

if ($dispatch_id) {
    try {
        $stmt = executeQuery(
            "SELECT id, dispatch_number, expected_amount, dispatch_date, dispatcher_name, COALESCE(sales_status, '') AS sales_status
             FROM dispatches
             WHERE id = ? AND COALESCE(status, '') <> 'cancelled'
             LIMIT 1",
            [$dispatch_id]
        );
        $selected_dispatch = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Failed to load selected dispatch: " . $e->getMessage());
        $selected_dispatch = null;
    }
}

// Existing page logic continues below...

// Get products for returns based on the selected dispatch, or all dispatches for the driver if no trip selected
$products = [];
if ($dispatch_id && $selected_dispatch) {
    try {
        $stmt = executeQuery(
            "SELECT di.product_id as id, p.name, di.quantity, di.quantity_sold, di.unit_price, di.discount_amount, p.cost_price
             FROM dispatch_items di
             JOIN products p ON di.product_id = p.id
             WHERE di.dispatch_id = ?
             ORDER BY p.name",
            [$dispatch_id]
        );
        $products = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to load products for dispatch: " . $e->getMessage());
        $products = [];
    }
} elseif ($driver_id && $selected_driver_name) {
    try {
        $stmt = executeQuery(
            "SELECT DISTINCT p.id, p.name, p.cost_price
             FROM products p
             JOIN dispatch_items di ON p.id = di.product_id
             JOIN dispatches d ON di.dispatch_id = d.id
             WHERE (d.dispatcher_id = ? OR LOWER(TRIM(d.dispatcher_name)) = LOWER(TRIM(?)))
               AND DATE(d.dispatch_date) = ?
                             AND COALESCE(d.status, '') <> 'cancelled'
             ORDER BY p.name",
            [$driver_id, $selected_driver_name, $record_date]
        );
        $products = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to load products for driver: " . $e->getMessage());
        $products = [];
    }
}

$dailyMutationSubmitted = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['edit_activity']) || isset($_POST['record_activity']) || isset($_POST['record_activity_expense'])
        || isset($_POST['edit_activity_expense']) || isset($_POST['delete_return']) || isset($_POST['update_return']));
$csrfValid = true;
if ($dailyMutationSubmitted) {
    if (!$can_edit) {
        error_log('Unauthorized daily activity mutation by user ' . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        if (isset($_POST['delete_return']) || isset($_POST['update_return'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'You do not have permission to edit daily returns.']);
            exit();
        }
        exit('You do not have permission to edit daily activity.');
    }
    $postedCsrfToken = $_POST['csrf_token'] ?? null;
    $csrfValid = is_string($postedCsrfToken) && SecurityUtils::consumeCSRFToken($postedCsrfToken);
    if (!$csrfValid) {
        error_log('Rejected daily activity mutation: invalid CSRF token for user ' . ($_SESSION['user_id'] ?? 'unknown'));
        if (isset($_POST['delete_return']) || isset($_POST['update_return'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Your form has expired. Refresh the page and try again.',
                'csrf_token' => SecurityUtils::generateCSRFToken()
            ]);
            exit();
        }
        $error = 'Your form has expired. Please refresh the page and try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_activity_expense']) && $can_edit && $csrfValid && empty($error)) {
    $pdo = null;
    try {
        $expense = parseDailyActivityExpense(
            $_POST['expenses_amount'] ?? '',
            $_POST['expense_type'] ?? '',
            $_POST['expenses_note'] ?? ''
        );
        if ($expense['amount'] <= 0) {
            throw new InvalidArgumentException('Enter a positive expense amount.');
        }

        $driverId = filter_var($_POST['driver_id'] ?? null, FILTER_VALIDATE_INT);
        $dispatchId = filter_var($_POST['dispatch_id'] ?? null, FILTER_VALIDATE_INT);
        $expenseDate = is_string($_POST['record_date'] ?? null) ? $_POST['record_date'] : '';
        if (!$driverId || !$dispatchId || !isValidActivityDate($expenseDate)) {
            throw new InvalidArgumentException('Select a valid dispatcher, dispatch, and expense date.');
        }

        $pdo = getDBConnection();
        $pdo->beginTransaction();
        $driverStmt = $pdo->prepare('SELECT id, name FROM drivers WHERE id = ? FOR UPDATE');
        if (!$driverStmt->execute([$driverId])) {
            throw new RuntimeException('Expense dispatcher validation failed.');
        }
        $driver = $driverStmt->fetch(PDO::FETCH_ASSOC);
        if (!$driver) {
            throw new InvalidArgumentException('Select a valid dispatcher.');
        }
        assertActivityExpenseOwnership($pdo, $driverId, $driver['name'], $dispatchId, $expenseDate);

        $activityStmt = $pdo->prepare(
            'SELECT id FROM daily_activities
             WHERE driver_id = ? AND activity_date = ? AND dispatch_id = ?
             LIMIT 1 FOR UPDATE'
        );
        if (!$activityStmt->execute([$driverId, $expenseDate, $dispatchId])) {
            throw new RuntimeException('Existing activity expense lookup failed.');
        }
        $activityId = (int) $activityStmt->fetchColumn();
        if ($activityId > 0) {
            $activityWrite = $pdo->prepare(
                'UPDATE daily_activities
                 SET expenses_amount = ?, expenses_note = ?, recorded_by = ?, recorded_at = NOW()
                 WHERE id = ?'
            );
            $saved = $activityWrite->execute([$expense['amount'], $expense['description'], $_SESSION['user_id'], $activityId]);
        } else {
            $activityWrite = $pdo->prepare(
                'INSERT INTO daily_activities
                 (driver_id, dispatch_id, driver_name, activity_date, expenses_amount, expenses_note, recorded_by, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $saved = $activityWrite->execute([
                $driverId,
                $dispatchId,
                $driver['name'],
                $expenseDate,
                $expense['amount'],
                $expense['description'],
                $_SESSION['user_id']
            ]);
            $activityId = (int) $pdo->lastInsertId();
        }
        if (!$saved || $activityId < 1) {
            throw new RuntimeException('Activity expense could not be saved.');
        }

        reconcileDailyActivityExpense($pdo, $activityId, $dispatchId, $driver['name'], $expenseDate, $expense);
        $pdo->commit();

        cache_clear();
        $_SESSION['success_message'] = 'Trip expense recorded successfully.';
        header('Location: record.php?driver_id=' . $driverId . '&dispatch_id=' . $dispatchId . '&record_date=' . urlencode($expenseDate));
        exit();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Daily activity expense record failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to record the trip expense. No changes were saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_activity_expense']) && $can_edit && $csrfValid && empty($error)) {
    $pdo = null;
    try {
        $expense = parseDailyActivityExpense(
            $_POST['expenses_amount'] ?? '',
            $_POST['expense_type'] ?? '',
            $_POST['expenses_note'] ?? ''
        );
        if (!canRecordFinancials() || !hasRole(['admin', 'cashier', 'dispatcher'])) {
            throw new InvalidArgumentException('You are not permitted to record trip expenses.');
        }

        $pdo = getDBConnection();
        $pdo->beginTransaction();
        $activityStmt = $pdo->prepare(
            'SELECT id, driver_id, driver_name, activity_date, dispatch_id
             FROM daily_activities WHERE id = ? FOR UPDATE'
        );
        if (!$activityStmt->execute([(int) ($_POST['activity_id'] ?? 0)])) {
            throw new RuntimeException('Activity expense validation failed.');
        }
        $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);
        if (!$activity || !isValidActivityDate($activity['activity_date'])) {
            throw new InvalidArgumentException('The selected activity is invalid.');
        }
        if (isDispatcher()) {
            assertAssignedDispatch($pdo, $activity['dispatch_id'], $activity['driver_id'], $activity['activity_date']);
        }
        if ($expense['amount'] > 0) {
            assertActivityExpenseOwnership(
                $pdo,
                $activity['driver_id'],
                $activity['driver_name'],
                $activity['dispatch_id'],
                $activity['activity_date']
            );
        }

        $activityWrite = $pdo->prepare(
            'UPDATE daily_activities
             SET expenses_amount = ?, expenses_note = ?, recorded_by = ?, recorded_at = NOW()
             WHERE id = ?'
        );
        if (!$activityWrite->execute([
            $expense['amount'],
            $expense['description'],
            $_SESSION['user_id'],
            $activity['id']
        ])) {
            throw new RuntimeException('Activity expense update failed.');
        }
        reconcileDailyActivityExpense(
            $pdo,
            $activity['id'],
            $activity['dispatch_id'],
            $activity['driver_name'],
            $activity['activity_date'],
            $expense
        );
        $pdo->commit();

        cache_clear();
        $_SESSION['success_message'] = 'Trip expense updated successfully.';
        header('Location: record.php?driver_id=' . (int) $activity['driver_id'] . '&dispatch_id=' . (int) $activity['dispatch_id'] . '&record_date=' . urlencode($activity['activity_date']));
        exit();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Daily activity expense edit failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to update the trip expense. No changes were saved.';
    }
}

// Handle edit submission - only admin and cashier can edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_activity']) && !isset($_POST['edit_activity_expense']) && $can_edit && $csrfValid && empty($error)) {
    try {
        $pdo = getDBConnection();
        $pdo->beginTransaction();
        recordDailyTripActivity(
            $pdo,
            (int) ($_POST['driver_id'] ?? 0),
            (int) ($_POST['dispatch_id'] ?? 0),
            (string) ($_POST['record_date'] ?? date('Y-m-d')),
            (int) ($_POST['activity_id'] ?? 0)
        );
        $pdo->commit();
        cache_clear();
        $hasCreditSale = postedArrayHasNonZeroAmount($_POST['credit_amounts'] ?? []);
        $_SESSION['success_message'] = $hasCreditSale
            ? 'Sales allocation updated successfully.'
            : 'Trip activity updated successfully.';
        header('Location: record.php?driver_id=' . (int) $_POST['driver_id'] . '&dispatch_id=' . (int) $_POST['dispatch_id'] . '&record_date=' . urlencode((string) $_POST['record_date']));
        exit();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Controlled dispatch activity edit failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Activity sales recording could not be completed. No changes were saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_activity']) && !isset($_POST['edit_activity_expense']) && $can_edit && empty($error)) {
    $activity_id = intval($_POST['activity_id'] ?? 0);
    $cash_collected = floatval($_POST['cash_collected'] ?? 0);
    $transfer_amount = floatval($_POST['transfer_amount'] ?? 0);
    $credit_amounts = isset($_POST['credit_amounts']) && is_array($_POST['credit_amounts']) ? array_map('floatval', $_POST['credit_amounts']) : [];
    $credit_names = isset($_POST['credit_customer_names']) && is_array($_POST['credit_customer_names']) ? $_POST['credit_customer_names'] : [];
    $credit_amount = array_sum($credit_amounts);
    // Settlements are no longer submitted from this form; they are handled in Credit Dashboard.
    // Ensure credit_settlement is defined to avoid PHP notices and header issues
    $credit_settlement = floatval($_POST['credit_settlement'] ?? 0);
    $credit_customer_name = '';
    foreach ($credit_names as $cn) {
        if (trim($cn) !== '') {
            $credit_customer_name = trim($cn);
            break;
        }
    }
    $activityExpense = ['amount' => 0.0, 'type' => '', 'description' => ''];
    try {
        $activityExpense = parseDailyActivityExpense(
            $_POST['expenses_amount'] ?? '',
            $_POST['expense_type'] ?? '',
            $_POST['expenses_note'] ?? ''
        );
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
    $expenses_amount = $activityExpense['amount'];
    $expenses_note = $activityExpense['description'];
    $damaged_product_id = intval($_POST['damaged_product_id'] ?? 0);
    $damagedProductQuantityValue = $_POST['damaged_product_quantity'] ?? 0;
    $damaged_product_quantity = is_scalar($damagedProductQuantityValue) && is_numeric($damagedProductQuantityValue)
        ? (float) $damagedProductQuantityValue
        : -1;
    $damaged_products = floatval($_POST['damaged_products'] ?? 0);
    $damaged_products_note = trim($_POST['damaged_products_note'] ?? '');
    $new_return_product_ids = isset($_POST['return_product_id']) && is_array($_POST['return_product_id']) ? $_POST['return_product_id'] : [];
    $new_return_quantities = isset($_POST['return_quantity']) && is_array($_POST['return_quantity']) ? $_POST['return_quantity'] : [];

    if (empty($activity_id)) {
        $error = 'Invalid activity record';
    } elseif ($credit_amount > 0 && empty($credit_customer_name)) {
        $error = 'Customer name is required when recording credit sales';
    } else {
        // No settlement validation here; settlements are recorded through the Credit Dashboard.
    }

    if (empty($error)) {
        if (!is_finite($damaged_product_quantity) || $damaged_product_quantity < 0 || $damaged_product_quantity > 999999.99) {
            $error = 'Damaged quantity must be finite and within the permitted maximum.';
        }
    }

    if (empty($error)) {
        if ($damaged_product_quantity > 0 && empty($damaged_product_id)) {
            $error = 'Please select the damaged product for the damaged quantity entered.';
        }
    }

    if (empty($error)) {
        error_log("Validation passed, attempting to save activity");
        error_log("Values: driver_id=$driver_id, dispatch_id=$dispatch_id, record_date=$record_date, cash_collected=$cash_collected, transfer_amount=$transfer_amount, credit_amount=$credit_amount");
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            if ($damaged_product_quantity > 0) {
                validateDispatchedDamage($pdo, $dispatch_id, $damaged_product_id, $damaged_product_quantity, $activity_id);
            }

            // Get the old activity values before updating
            $oldStmt = $pdo->prepare("SELECT driver_id, driver_name, activity_date, dispatch_id, credit_amount as old_credit_amount, cash_collected, transfer_amount, credit_settlement, credit_customer_name, expenses_amount FROM daily_activities WHERE id = ? FOR UPDATE");
            $oldStmt->execute([$activity_id]);
            $oldActivity = $oldStmt->fetch();

            if (!$oldActivity) {
                throw new Exception('Activity record not found');
            }
            if ($activityExpense['amount'] > 0 || (float) ($oldActivity['expenses_amount'] ?? 0) > 0) {
                if (!canRecordFinancials() || !hasRole(['admin', 'cashier', 'dispatcher'])) {
                    throw new InvalidArgumentException('You are not permitted to record trip expenses.');
                }
                if ($activityExpense['amount'] > 0) {
                    assertActivityExpenseOwnership(
                        $pdo,
                        $oldActivity['driver_id'],
                        $oldActivity['driver_name'],
                        $oldActivity['dispatch_id'],
                        $oldActivity['activity_date']
                    );
                }
            }

            if ($damaged_product_id > 0 && $damaged_product_quantity > 0) {
                $priceStmt = $pdo->prepare(
                    "SELECT di.unit_price, di.discount_amount, di.quantity AS dispatched_quantity, p.cost_price, p.cost_price, p.name
                     FROM dispatch_items di
                     JOIN products p ON di.product_id = p.id
                     WHERE di.dispatch_id = ? AND di.product_id = ?
                     LIMIT 1"
                );
                $priceStmt->execute([$dispatch_id, $damaged_product_id]);
                $priceRow = $priceStmt->fetch();
                $price = null;
                if ($priceRow) {
                    // Use full unit price (without discount) - discounts are tracked separately for account balancing
                    $price = floatval($priceRow['unit_price'] ?? 0);
                }
                if ($price === null || $price <= 0) {
                    $productPriceStmt = $pdo->prepare("SELECT cost_price, name FROM products WHERE id = ? LIMIT 1");
                    $productPriceStmt->execute([$damaged_product_id]);
                    $productPriceRow = $productPriceStmt->fetch();
                    if ($productPriceRow) {
                        $price = max(0, floatval($productPriceRow['cost_price'] ?? 0));
                        if ($price <= 0) {
                            $price = max(0, floatval($productPriceRow['cost_price'] ?? 0));
                        }
                    }
                }
                if ($price === null || $price <= 0) {
                    throw new Exception('Unable to determine unit value for the selected damaged product.');
                }
                $damaged_products = round($damaged_product_quantity * $price, 2);
                if (empty($damaged_products_note)) {
                    $productName = trim($priceRow['name'] ?? ($productPriceRow['name'] ?? 'Selected product'));
                    $damaged_products_note = sprintf('Damaged %.2f units of %s.', $damaged_product_quantity, $productName);
                }
            }

            $oldCreditsStmt = $pdo->prepare("SELECT customer_name, amount FROM daily_activity_credits WHERE daily_activity_id = ?");
            $oldCreditsStmt->execute([$activity_id]);
            $oldCredits = $oldCreditsStmt->fetchAll();

            $oldCreditAmount = 0;
            foreach ($oldCredits as $oc) {
                $oldCreditAmount += floatval($oc['amount']);
            }
            if ($oldCreditAmount <= 0) {
                $oldCreditAmount = floatval($oldActivity['old_credit_amount'] ?? 0);
            }

            $oldCreditSettlement = floatval($oldActivity['credit_settlement'] ?? 0);
            $oldCreditCustomerName = trim($oldActivity['credit_customer_name'] ?? '');

            // Reverse old credit ledger entries
            foreach ($oldCredits as $oc) {
                $ocName = trim($oc['customer_name'] ?? '');
                $ocAmount = floatval($oc['amount']);
                if ($ocAmount > 0 && $ocName !== '') {
                    $reverseStmt = $pdo->prepare("SELECT id, credit_amount, amount_paid, balance FROM creditors WHERE creditor_name = ? AND driver_id = ? LIMIT 1");
                    $reverseStmt->execute([$ocName, $oldActivity['driver_id']]);
                    $oldCreditor = $reverseStmt->fetch();
                    if ($oldCreditor) {
                        $new_credit_amount = max(0, $oldCreditor['credit_amount'] - $ocAmount);
                        $new_balance = max(0, $oldCreditor['balance'] - $ocAmount);
                        $pdo->prepare("UPDATE creditors SET credit_amount = ?, balance = ?, updated_at = NOW() WHERE id = ?")->execute([$new_credit_amount, $new_balance, $oldCreditor['id']]);
                    }
                }
            }

            // Update daily activity record
            $stmt = $pdo->prepare("UPDATE daily_activities SET cash_collected = ?, transfer_amount = ?, credit_amount = ?, credit_settlement = ?, credit_customer_name = ?, expenses_amount = ?, expenses_note = ?, damaged_products = ?, damaged_products_note = ? WHERE id = ?");
            $stmt->execute([
                $cash_collected,
                $transfer_amount,
                $credit_amount,
                $credit_settlement,
                $credit_customer_name,
                $expenses_amount,
                $expenses_note,
                $damaged_products,
                $damaged_products_note,
                $activity_id
            ]);

            reconcileDailyActivityExpense(
                $pdo,
                $activity_id,
                $oldActivity['dispatch_id'],
                $oldActivity['driver_name'],
                $oldActivity['activity_date'],
                $activityExpense
            );

            // Remove old per-credit rows and insert the updated breakdown
            $pdo->prepare("DELETE FROM daily_activity_credits WHERE daily_activity_id = ?")->execute([$activity_id]);
            foreach ($credit_amounts as $idx => $amt) {
                $amt = floatval($amt);
                $cname = isset($credit_names[$idx]) ? trim($credit_names[$idx]) : '';
                if ($amt > 0 && $cname !== '') {
                    $pdo->prepare("INSERT INTO daily_activity_credits (daily_activity_id, customer_name, amount, created_at) VALUES (?, ?, ?, NOW())")->execute([$activity_id, $cname, $amt]);

                    $checkStmt = $pdo->prepare("SELECT id, credit_amount, amount_paid, balance FROM creditors WHERE creditor_name = ? AND driver_id = ? AND status = 'active' LIMIT 1");
                    $checkStmt->execute([$cname, $oldActivity['driver_id']]);
                    $existingCreditor = $checkStmt->fetch();

                    if ($existingCreditor) {
                        $new_credit_amount = $existingCreditor['credit_amount'] + $amt;
                        $new_balance = $existingCreditor['balance'] + $amt;
                        $pdo->prepare("UPDATE creditors SET credit_amount = ?, balance = ?, updated_at = NOW() WHERE id = ?")->execute([$new_credit_amount, $new_balance, $existingCreditor['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO creditors (creditor_name, credit_amount, amount_paid, balance, credit_date, driver_id, driver_name, status, created_by, created_at) VALUES (?, ?, 0, ?, ?, ?, ?, 'active', ?, NOW())")->execute([$cname, $amt, $amt, $oldActivity['activity_date'], $oldActivity['driver_id'], $oldActivity['driver_name'], $_SESSION['user_id']]);
                    }
                }
            }

            $activity = $oldActivity;
            $tripTotalDifference = ($cash_collected + $transfer_amount + $credit_amount) - (floatval($activity['cash_collected'] ?? 0) + floatval($activity['transfer_amount'] ?? 0) + $oldCreditAmount);

            if ($tripTotalDifference != 0 && !empty($dispatch_id)) {
                $dispatchUpdateStmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount + ? WHERE id = ?");
                $dispatchUpdateStmt->execute([$tripTotalDifference, $dispatch_id]);
            }

            // Persist any new returns added while editing the activity
            $returnDispatchId = $dispatch_id;
            
            // Validate dispatch_id exists before using it
            $validDispatchId = null;
            if ($returnDispatchId) {
                $checkDispatch = $pdo->prepare("SELECT id FROM dispatches WHERE id = ? AND COALESCE(status, '') <> 'cancelled' LIMIT 1");
                $checkDispatch->execute([$returnDispatchId]);
                if ($checkDispatch->fetch()) {
                    $validDispatchId = $returnDispatchId;
                }
            }
            
            foreach ($new_return_product_ids as $index => $product_id) {
                $rawQuantity = $new_return_quantities[$index] ?? '';
                $hasProduct = is_scalar($product_id) && trim((string) $product_id) !== '';
                $hasQuantity = is_scalar($rawQuantity) && trim((string) $rawQuantity) !== '';
                if (!$hasProduct && !$hasQuantity) {
                    continue;
                }
                if (!$hasProduct && (!$hasQuantity || (is_numeric($rawQuantity) && (float) $rawQuantity <= 0))) {
                    continue;
                }
                if (!$hasProduct) {
                    throw new InvalidArgumentException('Please select a return product for the quantity entered.');
                }
                $product_id = parseReturnProductId($product_id);
                $qty = parseReturnQuantity($rawQuantity);
                if (!$validDispatchId) {
                    throw new InvalidArgumentException('A valid dispatch is required for returns.');
                }
                $dispatchItem = validateDispatchedReturn($pdo, $validDispatchId, $product_id, $qty);
                $return_amount = 0;
                // Use full unit price (without discount) - discounts are tracked separately for account balancing
                $return_amount = $qty * $dispatchItem['unit_price'];

                $insertReturnStmt = $pdo->prepare("INSERT INTO daily_returns (driver_id, driver_name, product_id, dispatch_id, quantity, return_date, recorded_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                if (!$insertReturnStmt->execute([
                    $activity['driver_id'],
                    $activity['driver_name'],
                    $product_id,
                    $validDispatchId,
                    $qty,
                    $activity['activity_date'],
                    $_SESSION['user_id']
                ]) || $insertReturnStmt->rowCount() !== 1) {
                    throw new RuntimeException('Trip return could not be recorded.');
                }
                $return_record_id = $pdo->lastInsertId();
                if (!$return_record_id || !recordReturnMovement(
                    $return_record_id,
                    $product_id,
                    $qty,
                    'manual',
                    $_SESSION['user_id'],
                    $activity['activity_date']
                )) {
                    throw new RuntimeException('Trip return stock movement could not be recorded.');
                }

                if ($return_amount > 0) {
                    $dispatchReturnUpdateStmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount - ? WHERE id = ?");
                    if (!$dispatchReturnUpdateStmt->execute([$return_amount, $validDispatchId])) {
                        throw new RuntimeException('Dispatch return amount could not be updated.');
                    }
                }
            }

            // Credit settlement reversal and new settlement recording removed — handled in Credit Dashboard

            $successMsg = 'Record updated successfully';
            if ($credit_amount > 0 && !empty($credit_customer_name)) {
                $successMsg .= ' • Credit entries auto-created/updated for the selected customer(s)';
            }
            $pdo->commit();
            cache_clear();
            $_SESSION['success_message'] = $successMsg;
            header('Location: record.php?driver_id=' . $driver_id . '&dispatch_id=' . $dispatch_id . '&record_date=' . urlencode($record_date));
            exit();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($activityExpense['amount'] > 0 || (float) ($oldActivity['expenses_amount'] ?? 0) > 0) {
                error_log('Daily activity expense update failed: ' . $e->getMessage());
                $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to update the trip expense. No changes were saved.';
            } else {
                error_log('Daily activity update failed: ' . $e->getMessage());
                $error = $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : 'Activity update could not be completed. No changes were saved.';
            }
        }
    }
}

// Handle delete return via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_return']) && $can_edit && $csrfValid) {
    $return_id = intval($_POST['return_id'] ?? 0);
    
    if ($return_id) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();
            
            // Get return details before deleting
            $stmt = $pdo->prepare("SELECT driver_id, product_id, quantity, dispatch_id, return_date FROM daily_returns WHERE id = ? FOR UPDATE");
            if (!$stmt->execute([$return_id])) {
                throw new RuntimeException('Return record lookup failed.');
            }
            $return_record = $stmt->fetch();
            
            if ($return_record) {
                if (isDispatcher()) {
                    assertAssignedDispatch($pdo, $return_record['dispatch_id'], $return_record['driver_id'], $return_record['return_date']);
                }
                // Delete the return record
                $deleteStmt = $pdo->prepare("DELETE FROM daily_returns WHERE id = ?");
                if (!$deleteStmt->execute([$return_id]) || $deleteStmt->rowCount() !== 1) {
                    throw new RuntimeException('Return record could not be deleted.');
                }
                
                // Restore stock movement
                if (!recordReturnMovement(
                    $return_id,
                    $return_record['product_id'],
                    -$return_record['quantity'],
                    'manual',
                    $_SESSION['user_id'],
                    $return_record['return_date']
                )) {
                    throw new RuntimeException('Return stock movement could not be reversed.');
                }
                
                // Update dispatch actual_sales_amount to add back the return amount
                if ($return_record['dispatch_id']) {
                    // Calculate return amount
                    $priceStmt = $pdo->prepare("SELECT unit_price, discount_amount, quantity FROM dispatch_items WHERE dispatch_id = ? AND product_id = ? LIMIT 1");
                    if (!$priceStmt->execute([$return_record['dispatch_id'], $return_record['product_id']])) {
                        throw new RuntimeException('Dispatch return price lookup failed.');
                    }
                    $dispatchItem = $priceStmt->fetch();
                    if ($dispatchItem) {
                        // Use full unit price (without discount) - discounts are tracked separately for account balancing
                        $unitPrice = $dispatchItem['unit_price'];
                        $return_amount = $return_record['quantity'] * $unitPrice;
                        $updateStmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount + ? WHERE id = ?");
                        if (!$updateStmt->execute([$return_amount, $return_record['dispatch_id']])) {
                            throw new RuntimeException('Dispatch return amount could not be updated.');
                        }
                    }
                }
            }
            
            $pdo->commit();
            cache_clear();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'csrf_token' => SecurityUtils::generateCSRFToken()]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Daily return deletion failed: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unable to delete the return. No changes were saved.', 'csrf_token' => SecurityUtils::generateCSRFToken()]);
        }
    }
    exit();
}

// Handle update return quantity via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_return']) && $can_edit && $csrfValid) {
    $return_id = intval($_POST['return_id'] ?? 0);
    $new_quantity = floatval($_POST['quantity'] ?? 0);
    
    if ($return_id && $new_quantity >= 0) {
        try {
            $pdo = getDBConnection();
            $pdo->beginTransaction();
            
            // Get old return details
            $stmt = $pdo->prepare("SELECT driver_id, product_id, quantity, dispatch_id, return_date FROM daily_returns WHERE id = ? FOR UPDATE");
            if (!$stmt->execute([$return_id])) {
                throw new RuntimeException('Return record lookup failed.');
            }
            $return_record = $stmt->fetch();
            
            if ($return_record) {
                if (isDispatcher()) {
                    assertAssignedDispatch($pdo, $return_record['dispatch_id'], $return_record['driver_id'], $return_record['return_date']);
                }
                if ($return_record['dispatch_id']) {
                    validateDispatchedReturn(
                        $pdo,
                        $return_record['dispatch_id'],
                        $return_record['product_id'],
                        $new_quantity,
                        $return_id
                    );
                }
                $quantity_difference = $new_quantity - $return_record['quantity'];
                
                // Update the return record
                $updateStmt = $pdo->prepare("UPDATE daily_returns SET quantity = ? WHERE id = ?");
                if (!$updateStmt->execute([$new_quantity, $return_id]) || $updateStmt->rowCount() !== 1) {
                    throw new RuntimeException('Return quantity could not be updated.');
                }
                
                // Adjust stock movement for the difference
                if ($quantity_difference != 0) {
                    if (!recordReturnMovement(
                        $return_id,
                        $return_record['product_id'],
                        $quantity_difference,
                        'manual',
                        $_SESSION['user_id'],
                        $return_record['return_date']
                    )) {
                        throw new RuntimeException('Return stock movement could not be adjusted.');
                    }
                }
                
                // Update dispatch actual_sales_amount
                if ($return_record['dispatch_id']) {
                    $priceStmt = $pdo->prepare("SELECT unit_price, discount_amount, quantity FROM dispatch_items WHERE dispatch_id = ? AND product_id = ? LIMIT 1");
                    if (!$priceStmt->execute([$return_record['dispatch_id'], $return_record['product_id']])) {
                        throw new RuntimeException('Dispatch return price lookup failed.');
                    }
                    $dispatchItem = $priceStmt->fetch();
                    if ($dispatchItem) {
                        // Use full unit price (without discount) - discounts are tracked separately for account balancing
                        $unitPrice = $dispatchItem['unit_price'];
                        $amount_difference = $quantity_difference * $unitPrice;
                        if ($amount_difference != 0) {
                            $updateStmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount - ? WHERE id = ?");
                            if (!$updateStmt->execute([$amount_difference, $return_record['dispatch_id']])) {
                                throw new RuntimeException('Dispatch return amount could not be updated.');
                            }
                        }
                    }
                }
            }
            
            $pdo->commit();
            cache_clear();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'csrf_token' => SecurityUtils::generateCSRFToken()]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Daily return update failed: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unable to update the return. No changes were saved.', 'csrf_token' => SecurityUtils::generateCSRFToken()]);
        }
    }
    exit();
}

// Handle form submission - only admin and cashier can submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_activity']) && !isset($_POST['record_activity_expense']) && $can_edit && $csrfValid && empty($error)) {
    try {
        $pdo = getDBConnection();
        $pdo->beginTransaction();
        recordDailyTripActivity(
            $pdo,
            (int) ($_POST['driver_id'] ?? 0),
            (int) ($_POST['dispatch_id'] ?? 0),
            (string) ($_POST['record_date'] ?? date('Y-m-d'))
        );
        $pdo->commit();
        cache_clear();
        $_SESSION['success_message'] = postedArrayHasNonZeroAmount($_POST['credit_amounts'] ?? [])
            ? 'Sales allocation recorded successfully.'
            : 'Trip activity recorded successfully.';
        header('Location: record.php?driver_id=' . (int) $_POST['driver_id'] . '&dispatch_id=' . (int) $_POST['dispatch_id'] . '&record_date=' . urlencode((string) $_POST['record_date']));
        exit();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Controlled dispatch activity create failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Activity sales recording could not be completed. No changes were saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_activity']) && !isset($_POST['record_activity_expense']) && $can_edit && empty($error)) {

    $driver_id = intval($_POST['driver_id'] ?? 0);
    $dispatch_id = intval($_POST['dispatch_id'] ?? 0);
    $record_date = $_POST['record_date'] ?? date('Y-m-d');

    // Returns data
    $return_product_ids = isset($_POST['return_product_id']) && is_array($_POST['return_product_id']) ? $_POST['return_product_id'] : [];
    $return_quantities = isset($_POST['return_quantity']) && is_array($_POST['return_quantity']) ? $_POST['return_quantity'] : [];

    // Financial data (support up to 5 credit entries)
    $cash_collected = floatval($_POST['cash_collected'] ?? 0);
    $transfer_amount = floatval($_POST['transfer_amount'] ?? 0);
    $credit_amounts = isset($_POST['credit_amounts']) && is_array($_POST['credit_amounts']) ? array_map('floatval', $_POST['credit_amounts']) : [];
    $credit_names = isset($_POST['credit_customer_names']) && is_array($_POST['credit_customer_names']) ? $_POST['credit_customer_names'] : [];
    $credit_amount = array_sum($credit_amounts);
    // Credit settlements are handled in the Credit Dashboard and no longer processed here.
    $credit_settlement = 0; // Initialize to 0; settlements are managed separately
    $credit_customer_name = '';
    foreach ($credit_names as $cn) { if (trim($cn) !== '') { $credit_customer_name = trim($cn); break; } }
    $activityExpense = ['amount' => 0.0, 'type' => '', 'description' => ''];
    try {
        $activityExpense = parseDailyActivityExpense(
            $_POST['expenses_amount'] ?? '',
            $_POST['expense_type'] ?? '',
            $_POST['expenses_note'] ?? ''
        );
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
    $expenses_amount = $activityExpense['amount'];
    $expenses_note = $activityExpense['description'];
    $damaged_product_id = intval($_POST['damaged_product_id'] ?? 0);
    $damagedProductQuantityValue = $_POST['damaged_product_quantity'] ?? 0;
    $damaged_product_quantity = is_scalar($damagedProductQuantityValue) && is_numeric($damagedProductQuantityValue)
        ? (float) $damagedProductQuantityValue
        : -1;
    $damaged_products = floatval($_POST['damaged_products'] ?? 0);
    $damaged_products_note = trim($_POST['damaged_products_note'] ?? '');
    
    if (empty($driver_id)) {
        $error = 'Please select a driver';
    } elseif (empty($dispatch_id)) {
        $error = 'Please select a trip (dispatch) for this activity';
    } elseif ($activityExpense['amount'] > 0 && !isValidActivityDate($record_date)) {
        $error = 'Enter a valid activity date.';
    } elseif ($activityExpense['amount'] > 0 && (!canRecordFinancials() || !hasRole(['admin', 'cashier', 'dispatcher']))) {
        $error = 'You are not permitted to record trip expenses.';
    } elseif ($credit_amount > 0 && empty($credit_customer_name)) {
        $error = 'Customer name is required when recording credit sales';
    } else {
        // Settlements are recorded in the Credit Dashboard; no per-row settlement validation here.
    }

    if (empty($error)) {
        if (!is_finite($damaged_product_quantity) || $damaged_product_quantity < 0 || $damaged_product_quantity > 999999.99) {
            $error = 'Damaged quantity must be finite and within the permitted maximum.';
        }
    }

    if (empty($error)) {
        if ($damaged_product_quantity > 0 && empty($damaged_product_id)) {
            $error = 'Please select the damaged product for the damaged quantity entered.';
        }
    }

    if (empty($error) && $damaged_product_id > 0 && $damaged_product_quantity > 0) {
        try {
            $priceStmt = executeQuery(
                "SELECT di.unit_price, di.discount_amount, di.quantity AS dispatched_quantity, p.cost_price, p.cost_price, p.name
                 FROM dispatch_items di
                 JOIN products p ON di.product_id = p.id
                 WHERE di.dispatch_id = ? AND di.product_id = ?
                 LIMIT 1",
                [$dispatch_id, $damaged_product_id]
            );
            $priceRow = $priceStmt->fetch();
            $price = null;
            if ($priceRow) {
                $price = floatval($priceRow['unit_price'] ?? 0);
                $discountAmount = floatval($priceRow['discount_amount'] ?? 0);
                $dispatchQty = max(1, floatval($priceRow['dispatched_quantity'] ?? 1));
                $price = max(0, $price - ($discountAmount / $dispatchQty));
            }
            if ($price === null || $price <= 0) {
                $productPriceStmt = executeQuery("SELECT cost_price, name FROM products WHERE id = ? LIMIT 1", [$damaged_product_id]);
                $productPriceRow = $productPriceStmt->fetch();
                if ($productPriceRow) {
                    $price = max(0, floatval($productPriceRow['cost_price'] ?? 0));
                    if ($price <= 0) {
                        $price = max(0, floatval($productPriceRow['cost_price'] ?? 0));
                    }
                }
            }
            if ($price === null || $price <= 0) {
                $error = 'Unable to determine unit value for the selected damaged product.';
            } else {
                $damaged_products = round($damaged_product_quantity * $price, 2);
                if (empty($damaged_products_note)) {
                    $productName = trim($priceRow['name'] ?? ($productPriceRow['name'] ?? 'Selected product'));
                    $damaged_products_note = sprintf('Damaged %.2f units of %s.', $damaged_product_quantity, $productName);
                }
            }
        } catch (PDOException $e) {
            error_log('Damaged product value calculation failed: ' . $e->getMessage());
            $error = 'Unable to calculate damaged product value.';
        }
    }

    if (empty($error)) {
        try {
            // Get driver name
            $stmt = executeQuery("SELECT name FROM drivers WHERE id = ?", [$driver_id]);
            $driver = $stmt->fetch();
            if (!$driver) {
                throw new Exception('Invalid driver selected');
            }

            // Get selected dispatch information
            $stmt = executeQuery("SELECT id, dispatch_number, dispatcher_name, dispatch_date FROM dispatches WHERE id = ?", [$dispatch_id]);
            $dispatch = $stmt->fetch();
            if (!$dispatch) {
                throw new Exception('Invalid trip selected');
            }
            if ($dispatch['dispatcher_name'] !== $driver['name'] || date('Y-m-d', strtotime($dispatch['dispatch_date'])) !== $record_date) {
                throw new Exception('Selected trip does not match the chosen driver and date');
            }
            
            $pdo = getDBConnection();
            $pdo->beginTransaction();

            if ($activityExpense['amount'] > 0) {
                assertActivityExpenseOwnership($pdo, $driver_id, $driver['name'], $dispatch_id, $record_date);
            }

            if ($damaged_product_quantity > 0) {
                validateDispatchedDamage($pdo, $dispatch_id, $damaged_product_id, $damaged_product_quantity);
            }

            // Get the OLD daily_activity values BEFORE updating (needed to calculate dispatch difference).
            // Use dispatch-specific driver/date since a dispatcher can have multiple trips on the same day.
            if ($dispatch_id) {
                $oldActivityStmt = $pdo->prepare(
                    "SELECT id, dispatch_id, cash_collected, transfer_amount, credit_amount, credit_settlement, credit_customer_name " .
                    "FROM daily_activities WHERE driver_id = ? AND activity_date = ? AND dispatch_id = ? LIMIT 1 FOR UPDATE"
                );
                $oldActivityStmt->execute([$driver_id, $record_date, $dispatch_id]);
            } else {
                $oldActivityStmt = $pdo->prepare(
                    "SELECT id, dispatch_id, cash_collected, transfer_amount, credit_amount, credit_settlement, credit_customer_name " .
                    "FROM daily_activities WHERE driver_id = ? AND activity_date = ? LIMIT 1 FOR UPDATE"
                );
                $oldActivityStmt->execute([$driver_id, $record_date]);
            }
            $oldActivity = $oldActivityStmt->fetch();

            $oldCash = $oldActivity ? floatval($oldActivity['cash_collected'] ?? 0) : 0;
            $oldTransfer = $oldActivity ? floatval($oldActivity['transfer_amount'] ?? 0) : 0;
            $oldCredit = $oldActivity ? floatval($oldActivity['credit_amount'] ?? 0) : 0;
            $oldDispatchId = $oldActivity ? intval($oldActivity['dispatch_id'] ?? 0) : 0;
            if ($oldActivity && $oldDispatchId !== $dispatch_id) {
                error_log(sprintf(
                    'daily_activities driver/date reuse: existing activity id=%d for driver_id=%d activity_date=%s has dispatch_id=%d, current selected dispatch_id=%d',
                    intval($oldActivity['id']),
                    $driver_id,
                    $record_date,
                    $oldDispatchId,
                    $dispatch_id
                ));
            }

            if ($oldActivity && !empty($oldActivity['id'])) {
                $activityRecordId = intval($oldActivity['id']);
                $stmt = $pdo->prepare("
                    UPDATE daily_activities SET
                        dispatch_id = ?,
                        cash_collected = ?,
                        transfer_amount = ?,
                        credit_amount = ?,
                        credit_settlement = ?,
                        credit_customer_name = ?,
                        expenses_amount = ?,
                        expenses_note = ?,
                        damaged_products = ?,
                        damaged_products_note = ?,
                        recorded_by = ?,
                        recorded_at = NOW()
                    WHERE id = ?
                ");
                if (!$stmt->execute([
                    $dispatch_id,
                    $cash_collected,
                    $transfer_amount,
                    $credit_amount,
                    $credit_settlement,
                    $credit_customer_name,
                    $expenses_amount,
                    $expenses_note,
                    $damaged_products,
                    $damaged_products_note,
                    $_SESSION['user_id'],
                    $activityRecordId
                ])) {
                    throw new RuntimeException('Daily activity update failed.');
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO daily_activities
                    (driver_id, driver_name, activity_date, dispatch_id, cash_collected, transfer_amount, credit_amount, credit_settlement, credit_customer_name, expenses_amount, expenses_note, damaged_products, damaged_products_note, recorded_by, recorded_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                if (!$stmt->execute([$driver_id, $driver['name'], $record_date, $dispatch_id, $cash_collected, $transfer_amount, $credit_amount, $credit_settlement, $credit_customer_name, $expenses_amount, $expenses_note, $damaged_products, $damaged_products_note, $_SESSION['user_id']])) {
                    throw new RuntimeException('Daily activity record failed.');
                }
                $activityRecordId = $pdo->lastInsertId();
            }

            reconcileDailyActivityExpense(
                $pdo,
                $activityRecordId,
                $dispatch_id,
                $driver['name'],
                $record_date,
                $activityExpense
            );

            // Persist the per-credit breakdown rows

            if ($activityRecordId > 0) {
                // Reverse previous credit entries before inserting updated values
                $oldCreditRowsStmt = $pdo->prepare("SELECT customer_name, amount FROM daily_activity_credits WHERE daily_activity_id = ?");
                $oldCreditRowsStmt->execute([$activityRecordId]);
                $oldCreditRows = $oldCreditRowsStmt->fetchAll();

                if (!empty($oldCreditRows)) {
                    foreach ($oldCreditRows as $oldCreditRow) {
                        $oldCustomer = trim($oldCreditRow['customer_name'] ?? '');
                        $oldAmount = floatval($oldCreditRow['amount'] ?? 0);
                        if ($oldAmount > 0 && $oldCustomer !== '') {
                            $reverseStmt = $pdo->prepare("SELECT id, credit_amount, amount_paid, balance FROM creditors WHERE creditor_name = ? AND driver_id = ? LIMIT 1");
                            $reverseStmt->execute([$oldCustomer, $driver_id]);
                            $existingCreditor = $reverseStmt->fetch();
                            if ($existingCreditor) {
                                $new_credit_amount = max(0, $existingCreditor['credit_amount'] - $oldAmount);
                                $new_balance = max(0, $existingCreditor['balance'] - $oldAmount);
                                $pdo->prepare("UPDATE creditors SET credit_amount = ?, balance = ?, updated_at = NOW() WHERE id = ?")->execute([$new_credit_amount, $new_balance, $existingCreditor['id']]);
                            }
                        }
                    }
                } elseif ($oldCredit > 0 && !empty(trim($oldActivity['credit_customer_name'] ?? ''))) {
                    $reverseStmt = $pdo->prepare("SELECT id, credit_amount, amount_paid, balance FROM creditors WHERE creditor_name = ? AND driver_id = ? LIMIT 1");
                    $reverseStmt->execute([trim($oldActivity['credit_customer_name']), $driver_id]);
                    $existingCreditor = $reverseStmt->fetch();
                    if ($existingCreditor) {
                        $new_credit_amount = max(0, $existingCreditor['credit_amount'] - $oldCredit);
                        $new_balance = max(0, $existingCreditor['balance'] - $oldCredit);
                        $pdo->prepare("UPDATE creditors SET credit_amount = ?, balance = ?, updated_at = NOW() WHERE id = ?")->execute([$new_credit_amount, $new_balance, $existingCreditor['id']]);
                    }
                }

                $pdo->prepare("DELETE FROM daily_activity_credits WHERE daily_activity_id = ?")->execute([$activityRecordId]);
                foreach ($credit_amounts as $idx => $amt) {
                    $amt = floatval($amt);
                    $cname = isset($credit_names[$idx]) ? trim($credit_names[$idx]) : '';
                    if ($amt > 0 && $cname !== '') {
                        $pdo->prepare("INSERT INTO daily_activity_credits (daily_activity_id, customer_name, amount, created_at) VALUES (?, ?, ?, NOW())")->execute([$activityRecordId, $cname, $amt]);

                        $checkStmt = $pdo->prepare("SELECT id, credit_amount, amount_paid, balance FROM creditors WHERE creditor_name = ? AND driver_id = ? AND status = 'active' LIMIT 1");
                        $checkStmt->execute([$cname, $driver_id]);
                        $existingCreditor = $checkStmt->fetch();

                        if ($existingCreditor) {
                            $new_credit_amount = $existingCreditor['credit_amount'] + $amt;
                            $new_balance = $existingCreditor['balance'] + $amt;
                            $pdo->prepare("UPDATE creditors SET credit_amount = ?, balance = ?, updated_at = NOW() WHERE id = ?")->execute([$new_credit_amount, $new_balance, $existingCreditor['id']]);
                        } else {
                            $pdo->prepare("INSERT INTO creditors (creditor_name, credit_amount, amount_paid, balance, credit_date, driver_id, driver_name, status, created_by, created_at) VALUES (?, ?, 0, ?, ?, ?, ?, 'active', ?, NOW())")->execute([$cname, $amt, $amt, $record_date, $driver_id, $driver['name'], $_SESSION['user_id']]);
                        }
                    }
                }
            }

            // Update the selected dispatch actual_sales_amount based on the difference
            // Note: credit_settlement is debt repayment, NOT new sales - do not include in trip total
            $oldTripTotal = $oldCash + $oldTransfer + $oldCredit;
            $newTripTotal = $cash_collected + $transfer_amount + $credit_amount;
            $tripDifference = $newTripTotal - $oldTripTotal;

            if ($tripDifference != 0) {
                $dispatchUpdateStmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount + ? WHERE id = ?");
                $dispatchUpdateStmt->execute([$tripDifference, $dispatch_id]);
            }

            // Credit settlement handling removed from this form; manage settlements via Credit Dashboard instead.
            
            // Validate dispatch_id exists before using it
            $validDispatchId = null;
            if ($dispatch_id) {
                $checkDispatch = $pdo->prepare("SELECT id FROM dispatches WHERE id = ? AND COALESCE(status, '') <> 'cancelled' LIMIT 1");
                $checkDispatch->execute([$dispatch_id]);
                if ($checkDispatch->fetch()) {
                    $validDispatchId = $dispatch_id;
                }
            }
            
            // Record returns
            foreach ($return_product_ids as $index => $product_id) {
                $rawQuantity = $return_quantities[$index] ?? '';
                $hasProduct = is_scalar($product_id) && trim((string) $product_id) !== '';
                $hasQuantity = is_scalar($rawQuantity) && trim((string) $rawQuantity) !== '';
                if (!$hasProduct && !$hasQuantity) {
                    continue;
                }
                if (!$hasProduct && (!$hasQuantity || (is_numeric($rawQuantity) && (float) $rawQuantity <= 0))) {
                    continue;
                }
                if (!$hasProduct) {
                    throw new InvalidArgumentException('Please select a return product for the quantity entered.');
                }
                $product_id = parseReturnProductId($product_id);
                $qty = parseReturnQuantity($rawQuantity);
                if (!$validDispatchId) {
                    throw new InvalidArgumentException('A valid dispatch is required for returns.');
                }
                $dispatchItem = validateDispatchedReturn($pdo, $validDispatchId, $product_id, $qty);
                // Use the dispatch price less its per-unit discount for the sales adjustment.
                $return_amount = $qty * ((float) $dispatchItem['unit_price'] - ((float) $dispatchItem['discount_amount'] / max(1, (float) $dispatchItem['quantity'])));

                    $stmt = $pdo->prepare("
                        INSERT INTO daily_returns (driver_id, driver_name, product_id, dispatch_id, quantity, return_date, recorded_by, recorded_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    if (!$stmt->execute([$driver_id, $driver['name'], $product_id, $validDispatchId, $qty, $record_date, $_SESSION['user_id']])
                        || $stmt->rowCount() !== 1) {
                        throw new RuntimeException('Trip return could not be recorded.');
                    }
                    $return_record_id = $pdo->lastInsertId();
                    if (!$return_record_id) {
                        throw new RuntimeException('Trip return record ID was not generated.');
                    }

                    // Restore stock using centralized stock management (assuming good condition for daily returns)
                    // Daily returns are typically products returned unsold, so they should be in good condition
                    if (!recordReturnMovement(
                        $return_record_id,
                        $product_id,
                        $qty,
                        'manual',
                        $_SESSION['user_id'],
                        $record_date
                    )) {
                        throw new RuntimeException('Trip return stock movement could not be recorded.');
                    }

                    if ($return_amount > 0) {
                        $returnUpdateStmt = $pdo->prepare("UPDATE dispatches SET actual_sales_amount = actual_sales_amount - ? WHERE id = ?");
                        if (!$returnUpdateStmt->execute([$return_amount, $dispatch_id])) {
                            throw new RuntimeException('Dispatch return amount could not be updated.');
                        }
                    }
                }

            // Dispatched-trip damage is already included in the dispatch deduction.
            // Keep its quantity in the activity note for reconciliation; do not deduct stock again.

            // Record trip-specific customer payments for cash, transfer and credit settlement
            if ($cash_collected > 0) {
                $paymentStmt = $pdo->prepare("INSERT INTO customer_payments (dispatcher_name, payment_date, payment_method, amount, notes, recorded_by, created_at) VALUES (?, CURDATE(), 'cash', ?, CONCAT('Cash collected for trip #', ?), ?, NOW())");
                $paymentStmt->execute([$driver['name'], $cash_collected, $dispatch['dispatch_number'], $_SESSION['user_id']]);
            }
            if ($transfer_amount > 0) {
                $paymentStmt = $pdo->prepare("INSERT INTO customer_payments (dispatcher_name, payment_date, payment_method, amount, notes, recorded_by, created_at) VALUES (?, CURDATE(), 'transfer', ?, CONCAT('Transfer collected for trip #', ?), ?, NOW())");
                $paymentStmt->execute([$driver['name'], $transfer_amount, $dispatch['dispatch_number'], $_SESSION['user_id']]);
            }
            // Credit settlement processing removed from this handler; record settlements in Credit Dashboard instead.

            $successMsg = 'Activity recorded successfully';
            if ($credit_amount > 0 && !empty($credit_customer_name)) {
                $successMsg .= ' • Credit entries auto-created/updated for the selected customer(s)';
            }
            $pdo->commit();
            cache_clear();
            $_SESSION['success_message'] = $successMsg;
            header('Location: record.php?driver_id=' . $driver_id . '&dispatch_id=' . $dispatch_id . '&record_date=' . urlencode($record_date));
            exit();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($activityExpense['amount'] > 0) {
                error_log('Daily activity expense record failed: ' . $e->getMessage());
                $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to record the trip expense. No changes were saved.';
            } else {
                error_log('Daily activity record failed: ' . $e->getMessage());
                $error = $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : 'Activity recording could not be completed. No changes were saved.';
            }
        }
    }
}

// Get today's summary for selected driver/trip
$activity_csrf_token = SecurityUtils::generateCSRFToken();

$today_summary = null;
if ($driver_id && $dispatch_id) {
    try {
        $query = "
            SELECT
                id,
                dispatch_id,
                activity_date,
                cash_collected,
                transfer_amount,
                credit_amount,
                credit_settlement,
                credit_customer_name,
                expenses_amount,
                expenses_note,
                discounts,
                damaged_products,
                damaged_products_note,
                (SELECT de.expense_type FROM dispatcher_expenses de
                 WHERE de.notes = CONCAT('[daily_activity_id:', daily_activities.id, ']') LIMIT 1) AS expense_type
            FROM daily_activities
            WHERE driver_id = ? AND activity_date = ? AND dispatch_id = ?
            LIMIT 1
        ";
        $stmt = executeQuery($query, [$driver_id, $record_date, $dispatch_id]);
        $today_summary = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Failed to load today's summary: " . $e->getMessage());
        $today_summary = null;
    }
}

// Load any per-activity credit breakdowns (up to N entries)
$today_credits = [];
$edit_credits = [];
$today_settlements = [];
if ($edit_activity && !empty($edit_activity['id'])) {
    try {
        $stmt = executeQuery("SELECT customer_name, amount FROM daily_activity_credits WHERE daily_activity_id = ? ORDER BY id LIMIT 5", [$edit_activity['id']]);
        $edit_credits = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to load edit activity credits: " . $e->getMessage());
        $edit_credits = [];
    }
}
if ($today_summary && !empty($today_summary['id'])) {
    try {
        $stmt = executeQuery("SELECT customer_name, amount FROM daily_activity_credits WHERE daily_activity_id = ? ORDER BY id LIMIT 5", [$today_summary['id']]);
        $today_credits = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to load today's credits: " . $e->getMessage());
        $today_credits = [];
    }
    try {
        // Check if dispatch_id column exists in credit_settlements table
        $columnCheck = executeQuery("SHOW COLUMNS FROM credit_settlements LIKE 'dispatch_id'");
        $hasDispatchId = $columnCheck->fetch() !== false;
        
        if ($hasDispatchId) {
            $settlementStmt = executeQuery(
                "SELECT customer_name, amount_collected FROM credit_settlements WHERE dispatch_id = ? AND settlement_date = ? AND payment_method = 'cash' AND (reference_number = '' OR reference_number IS NULL) ORDER BY id LIMIT 5",
                [$today_summary['dispatch_id'], $today_summary['activity_date']]
            );
            $today_settlements = $settlementStmt->fetchAll();
        } else {
            // Fallback: load settlements without dispatch_id filter
            $settlementStmt = executeQuery(
                "SELECT customer_name, amount_collected FROM credit_settlements WHERE settlement_date = ? AND payment_method = 'cash' AND (reference_number = '' OR reference_number IS NULL) ORDER BY id LIMIT 5",
                [$today_summary['activity_date']]
            );
            $today_settlements = $settlementStmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log("Failed to load today's settlements: " . $e->getMessage());
        $today_settlements = [];
    }
}

// Get today's returns for selected driver/trip
$today_returns = [];
$edit_returns = [];
if ($edit_activity && !empty($edit_activity['id'])) {
    try {
        $query = "
            SELECT dr.*, p.name as product_name, 
                   COALESCE(di.unit_price, p.cost_price, p.cost_price) as unit_price
            FROM daily_returns dr
            JOIN products p ON dr.product_id = p.id
            LEFT JOIN dispatch_items di ON dr.dispatch_id = di.dispatch_id AND dr.product_id = di.product_id
            WHERE dr.driver_id = ? AND dr.return_date = ?";
        $params = [$edit_activity['driver_id'], $edit_activity['activity_date']];
        if (!empty($edit_activity['dispatch_id'])) {
            $query .= " AND dr.dispatch_id = ?";
            $params[] = $edit_activity['dispatch_id'];
        }
        $query .= " ORDER BY dr.recorded_at DESC";
        $stmt = executeQuery($query, $params);
        $edit_returns = $stmt->fetchAll();
        error_log("Loaded " . count($edit_returns) . " returns for activity_id=" . $edit_activity['id'] . ", driver_id=" . $edit_activity['driver_id'] . ", date=" . $edit_activity['activity_date']);
    } catch (PDOException $e) {
        error_log("Failed to load edit activity returns: " . $e->getMessage());
        $edit_returns = [];
    }
}
if ($driver_id && $dispatch_id) {
    try {
        $query = "
            SELECT dr.*, p.name as product_name,
                   COALESCE(di.unit_price, p.cost_price, p.cost_price) as unit_price
            FROM daily_returns dr
            JOIN products p ON dr.product_id = p.id
            LEFT JOIN dispatch_items di ON dr.dispatch_id = di.dispatch_id AND dr.product_id = di.product_id
            WHERE dr.driver_id = ? AND dr.return_date = ?
        ";
        $params = [$driver_id, $record_date];
        if ($dispatch_id) {
            $query .= " AND dr.dispatch_id = ?";
            $params[] = $dispatch_id;
        }
        $query .= " ORDER BY dr.recorded_at DESC";

        $stmt = executeQuery($query, $params);
        $today_returns = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to load today's returns: " . $e->getMessage());
        $today_returns = [];
    }
}

// Get trip discount total from dispatch items
$trip_discount_total = 0;
if ($dispatch_id) {
    try {
        $stmt = executeQuery(
            "SELECT COALESCE(SUM(discount_amount), 0) as total_discount 
             FROM dispatch_items 
             WHERE dispatch_id = ?",
            [$dispatch_id]
        );
        $result = $stmt->fetch();
        $trip_discount_total = floatval($result['total_discount'] ?? 0);
    } catch (PDOException $e) {
        error_log("Failed to load trip discount total: " . $e->getMessage());
        $trip_discount_total = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Daily dispatch activity recording for Merrylay Enterprises">
    <meta name="author" content="Merrylay Enterprises">
    <title>Daily Dispatch Record - Merrylay Enterprises</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../../assets/css/unified-design-system.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    
    <style>
        /* Modern Design System */
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --secondary: #64748b;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 12px;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        body {
            background: var(--bg);
            font-family: 'Inter', sans-serif;
        }

        /* Page Header */
        .page-header {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        
        .page-header h4 {
            font-weight: 700;
            font-size: 1.75rem;
            color: var(--text);
            margin-bottom: 0.25rem;
            letter-spacing: -0.025em;
        }
        
        .page-header p {
            color: var(--text-muted);
            margin: 0;
            font-size: 0.95rem;
        }

        /* Section Cards */
        .section-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: box-shadow 0.2s ease;
        }

        .section-card:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 1.25rem;
            padding-bottom: 0.875rem;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        
        .section-title i {
            color: var(--primary);
            font-size: 1.125rem;
        }

        /* Form Elements */
        .form-label {
            font-weight: 500;
            color: var(--text);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
        
        .form-control, .form-select {
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            background: var(--card);
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
            outline: none;
        }

        .input-group-text {
            background: #f1f5f9;
            font-weight: 500;
            border: 1.5px solid var(--border);
            border-right: none;
            color: var(--text);
            padding: 0.75rem 1rem;
        }

        .input-group .form-control {
            border-left: none;
        }

        /* Buttons */
        .btn-record {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 0.875rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
            transition: all 0.2s ease;
        }
        
        .btn-record:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 12px -2px rgba(16, 185, 129, 0.25);
            color: white;
        }

        .btn-outline-custom {
            border: 1.5px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-outline-custom:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(16, 185, 129, 0.05);
        }

        /* Summary Box */
        .summary-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1.5px solid #86efac;
            border-radius: var(--radius);
            padding: 1.5rem;
        }
        
        .summary-box .section-title {
            border-bottom-color: #86efac;
            color: #065f46;
        }

        /* Return Rows */
        .return-row {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.75rem;
            border: 1.5px solid var(--border);
            transition: border-color 0.2s ease;
        }

        .return-row:hover {
            border-color: var(--primary);
        }

        /* Trip Info Card */
        .trip-info-card {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1.5px solid #93c5fd;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
        }

        /* Stats Grid */
        .stat-item {
            text-align: center;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border: 1.5px solid var(--border);
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 0.25rem;
        }

        /* Alerts */
        .alert-modern {
            border-radius: var(--radius);
            border: none;
            font-size: 0.95rem;
            padding: 1rem 1.25rem;
        }
        
        .alert-success-modern {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #065f46;
            border: 1.5px solid #86efac;
        }
        
        .alert-danger-modern {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #991b1b;
            border: 1.5px solid #fecaca;
        }

        .alert-info-modern {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: #1e40af;
            border: 1.5px solid #93c5fd;
        }

        /* View Only Notice */
        .view-only-notice {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1.5px solid #fcd34d;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.875rem;
        }
        
        .view-only-notice i {
            color: #d97706;
            font-size: 1.25rem;
        }

        /* Badges */
        .badge-custom {
            background: #f1f5f9;
            border: 1.5px solid var(--border);
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 6px;
        }

        /* Loading State */
        .btn-loading {
            position: relative;
            color: transparent !important;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            width: 1rem;
            height: 1rem;
            top: 50%;
            left: 50%;
            margin-left: -0.5rem;
            margin-top: -0.5rem;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Section Grid Layout */
        .sections-grid {
            display: grid;
            gap: 1.25rem;
        }

        @media (min-width: 992px) {
            .sections-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .sections-grid .section-card:has(.return-row) {
                grid-column: 1 / -1;
            }
        }

        /* Form Section Styling */
        .input-section {
            background: white;
            border-radius: 8px;
            padding: 1.25rem;
            border: 1.5px solid var(--border);
        }

        .input-section-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="../../dashboard/index.php" class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <span class="logo-text">Merrylay Enterprises</span>
                </a>
                <button class="toggle-btn" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>

                        <?php
            $dispatcherSidebarPath = __DIR__ . '/components/dispatcher-sidebar.php';
            $searchDir = __DIR__;
            while (!file_exists($dispatcherSidebarPath) && dirname($searchDir) !== $searchDir) {
                $searchDir = dirname($searchDir);
                $dispatcherSidebarPath = $searchDir . '/components/dispatcher-sidebar.php';
            }
            include $dispatcherSidebarPath;
            ?>
        </aside>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="d-flex align-items-center gap-3">
                    <button class="icon-btn d-md-none mobile-menu-toggle" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search...">
                    </div>
                </div>

                <div class="top-bar-actions">
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-menu dropdown">
                            <button class="btn p-0 border-0 bg-transparent d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User', 0, 2)); ?>
                                </div>
                                <div class="user-info d-none d-lg-block">
                                    <div class="user-name"><?php echo htmlspecialchars(ucwords($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')); ?></div>
                                    <div class="user-role"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item" href="../../auth/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="../../auth/settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4><i class="fas fa-clipboard-list me-2 text-primary"></i>Daily Dispatch Record</h4>
                            <p class="mb-0">Record returns, collections, credit sales, and expenses for driver trips</p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <a href="summary.php" class="btn btn-outline-custom">
                                <i class="fas fa-chart-pie me-2"></i>View Summary
                            </a>
                        </div>
                    </div>
                </div>

                <!-- View Only Notice -->
                <?php if (!$can_edit): ?>
                <div class="view-only-notice">
                    <i class="fas fa-eye"></i>
                    <div>
                        <strong>View Mode Only</strong>
                        <p class="mb-0">You can view records but cannot edit or create them. Contact an administrator for changes.</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Alerts -->
                <div class="row justify-content-center">
                    <div class="col-lg-10 col-xl-8">
                        <?php if ($error): ?>
                            <div class="alert alert-danger-modern d-flex align-items-center mb-4">
                                <i class="fas fa-exclamation-circle me-3 fs-5"></i>
                                <div><?php echo htmlspecialchars($error); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success-modern d-flex align-items-center mb-4">
                                <i class="fas fa-check-circle me-3 fs-5"></i>
                                <div><?php echo htmlspecialchars($success); ?></div>
                            </div>
                        <?php endif; ?>

                <form method="POST" id="activityForm" action="record.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($activity_csrf_token); ?>">
                    <?php if (!$today_summary && !$edit_activity): ?>
                    <input type="hidden" name="record_activity" value="1">
                    <?php endif; ?>
                    
                    <!-- NoScript Warning -->
                    <noscript>
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>JavaScript is disabled.</strong> Some features may not work properly. Please enable JavaScript for the best experience.
                        </div>
                    </noscript>
                    
                    <!-- Selection Panel -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-filter me-2"></i><?php echo $is_shop_attendant ? 'Select Date & Trip' : 'Select Driver, Date & Trip'; ?>
                        </div>
                        
                        <div class="row g-3 mb-4">
                            <?php if ($is_shop_attendant): ?>
                                <!-- Shop attendant: Show static Shop Sales display -->
                                <div class="col-md-6">
                                    <label class="form-label" for="shopDispatcherDisplay">Dispatcher</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-store text-muted"></i></span>
                                        <input type="text" class="form-control" id="shopDispatcherDisplay" value="Shop Sales" readonly style="background-color: #f1f5f9; font-weight: 600;">
                                        <input type="hidden" name="driver_id" value="<?php echo $shop_driver_id; ?>">
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Other users: Show driver dropdown -->
                                <div class="col-md-6">
                                    <label class="form-label" for="driverSelect">Driver <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-user-tie text-muted"></i></span>
                                        <select name="driver_id" class="form-select" id="driverSelect" required>
                                            <option value="">Choose Driver</option>
                                            <?php foreach ($drivers as $d): ?>
                                                <option value="<?php echo $d['id']; ?>" <?php echo $driver_id == $d['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($d['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <label class="form-label" for="dateInput">Date <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-calendar text-muted"></i></span>
                                    <input type="date" name="record_date" class="form-control" id="dateInput" value="<?php echo htmlspecialchars($record_date); ?>" required>
                                </div>
                            </div>

                        </div>

                        <!-- Loading indicator for auto-loaded trips -->
                        <div id="tripsLoadingIndicator" class="alert alert-info-modern mb-3" style="display: none;">
                            <i class="fas fa-spinner fa-spin me-2"></i>Loading trips...
                        </div>

                        <div class="row">
                            <div class="col-12">
                                <label class="form-label" for="dispatchSelect">Trip <span class="text-danger">*</span></label>
                                <select name="dispatch_id" class="form-select form-select-lg" id="dispatchSelect" required>
                                    <option value="">Select a trip to record activity...</option>
                                    <?php if (!empty($dispatches)): ?>
                                        <?php foreach ($dispatches as $dispatch): ?>
                                            <option value="<?php echo $dispatch['id']; ?>" <?php echo $dispatch_id == $dispatch['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dispatch['dispatch_number']); ?> — Expected: ₦<?php echo number_format($dispatch['expected_amount'], 2); ?>
                                                <?php echo !empty($dispatch['sales_status']) ? '[' . htmlspecialchars($dispatch['sales_status']) . ']' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <?php if ($driver_id && empty($dispatches)): ?>
                                    <div class="alert alert-info-modern mt-3 mb-0">
                                        <i class="fas fa-info-circle me-2"></i>No trips found for this driver on the selected date.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($selected_dispatch): ?>
                        <div class="mt-4">
                            <div class="trip-info-card">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-truck me-2 text-primary fs-5"></i>
                                            <span class="fw-bold fs-5"><?php echo htmlspecialchars($selected_dispatch['dispatch_number']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                                        <div class="d-flex justify-content-md-end gap-4">
                                            <div>
                                                <div class="text-muted small">Expected</div>
                                                <div class="fw-bold fs-5 text-primary">₦<?php echo number_format($selected_dispatch['expected_amount'] ?? 0, 2); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="financialInputSection">
                    
                    <!-- Selection Prompt -->
                    <div id="selectDispatchAlert" class="alert alert-info-modern d-flex align-items-center mb-4" style="display: <?php echo $dispatch_id ? 'none' : 'flex'; ?>;">
                        <i class="fas fa-mouse-pointer me-3 fs-4"></i>
                        <div>
                            <strong class="d-block mb-1">Select a Trip to Begin</strong>
                            <span class="text-muted">Choose a driver and date, then load and select a trip to access the recording form.</span>
                        </div>
                    </div>

                    <!-- Financial Form (Hidden until trip selected) -->
                    <div id="financialForm" style="display: <?php echo $dispatch_id ? 'block' : 'none'; ?>;">
                    
                    <!-- Today's Summary -->
                    <?php if ($today_summary || $edit_activity || !empty($today_returns)): ?>
                    <div class="section-card summary-box">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-history me-2 text-success fs-4"></i>
                                <span class="fw-bold fs-5"><?php echo $edit_activity ? 'Activity Record' : 'Today\'s Recorded Activity'; ?></span>
                            </div>
                            <div class="btn-group">
                                <?php if ($can_edit && ($today_summary || $edit_activity)): ?>
                                <button type="button" class="btn btn-outline-custom btn-sm" data-bs-toggle="modal" data-bs-target="#editModal" onclick="populateEditModal()">
                                    <i class="fas fa-edit me-2"></i>Edit Record
                                </button>
                                <?php endif; ?>
                                <?php if (hasRole(['admin']) && $today_summary): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteCurrentActivity()">
                                    <i class="fas fa-trash-alt me-2"></i>Delete Record
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php $summary = $edit_activity ?? $today_summary; ?>
                        <?php if ($summary): ?>
                        <div class="row g-3 mb-4">
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="stat-item">
                                    <div class="stat-value text-success" id="summary_cash_collected">₦<?php echo number_format($summary['cash_collected'], 0); ?></div>
                                    <div class="stat-label">Cash</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="stat-item">
                                    <div class="stat-value text-primary" id="summary_transfer_amount">₦<?php echo number_format($summary['transfer_amount'], 0); ?></div>
                                    <div class="stat-label">Transfer</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="stat-item">
                                    <div class="stat-value text-warning" id="summary_credit_amount">₦<?php echo number_format($summary['credit_amount'], 0); ?></div>
                                    <div class="stat-label">New Credit</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="stat-item">
                                    <div class="stat-value text-info" id="summary_credit_settlement">₦<?php echo number_format($summary['credit_settlement'] ?? 0, 0); ?></div>
                                    <div class="stat-label">Settlement</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="stat-item">
                                    <div class="stat-value text-danger" id="summary_expenses_amount">₦<?php echo number_format($summary['expenses_amount'], 0); ?></div>
                                    <div class="stat-label">Expenses</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="stat-item">
                                    <div class="stat-value text-danger" id="summary_trip_discount">₦<?php echo number_format($trip_discount_total, 0); ?></div>
                                    <div class="stat-label">Trip Discount</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="stat-item">
                                    <div class="stat-value text-secondary" id="summary_credit_balance">₦<?php echo number_format(($summary['credit_amount'] ?? 0) - ($summary['credit_settlement'] ?? 0), 0); ?></div>
                                    <div class="stat-label">Credit Balance</div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($summary['credit_customer_name']) && $summary['credit_amount'] > 0): ?>
                        <div class="p-3 bg-white rounded border-start border-4 border-warning">
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <span class="text-muted">Credit Customer:</span>
                                <span class="badge-custom bg-warning text-dark"><?php echo htmlspecialchars($summary['credit_customer_name']); ?></span>
                                <span class="badge-custom bg-success text-white ms-auto">
                                    <i class="fas fa-check-circle me-1"></i>Auto-synced to Creditors
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($today_settlements)): ?>
                        <div class="mt-3 pt-3 border-top">
                            <span class="text-muted small fw-medium mb-2 d-block">Settlement breakdown</span>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($today_settlements as $settlement): ?>
                                <span class="badge-custom bg-success text-dark"><?php echo htmlspecialchars($settlement['customer_name']); ?>: ₦<?php echo number_format($settlement['amount_collected'], 0); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($today_credits)): ?>
                        <div class="mt-3 pt-3 border-top">
                            <span class="text-muted small fw-medium mb-2 d-block">Credit breakdown</span>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($today_credits as $credit): ?>
                                <span class="badge-custom bg-warning text-dark">
                                    <?php echo htmlspecialchars($credit['customer_name']); ?>: ₦<?php echo number_format($credit['amount'], 2); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($today_returns)): ?>
                        <div class="mt-3 pt-3 border-top">
                            <span class="text-muted small fw-medium mb-2 d-block">Products Returned:</span>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($today_returns as $ret): ?>
                                <span class="badge-custom">
                                    <i class="fas fa-undo-alt me-1 text-primary"></i>
                                    <?php echo htmlspecialchars($ret['product_name']); ?>: <?php echo $ret['quantity']; ?> units
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Sold Quantities Section -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-boxes me-2"></i>Quantities Sold
                        </div>
                        <div id="salesQuantities" class="row g-3">
                            <div class="col-12 text-muted">Select a trip to load its products.</div>
                        </div>
                    </div>

                    <!-- Returns Section -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-undo-alt me-2"></i>Products Returned to Stock
                            <small class="text-muted ms-2 fw-normal">Products returned unsold from this trip</small>
                        </div>
                        <div id="returnsList">
                            <div class="return-row row align-items-center">
                                <div class="col-md-7 mb-2 mb-md-0">
                                                    <label class="form-label d-md-none" for="activity-return-product-0">Product</label>
                                                    <select id="activity-return-product-0" name="return_product_id[]" class="form-select">
                                        <option value="">Select Product...</option>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-2 mb-md-0">
                                                    <label class="form-label d-md-none" for="activity-return-quantity-0">Quantity</label>
                                    <div class="input-group">
                                                        <input type="number" id="activity-return-quantity-0" name="return_quantity[]" class="form-control" placeholder="Qty" min="0.25" step="0.25">
                                        <span class="input-group-text">units</span>
                                    </div>
                                </div>
                                <div class="col-md-2 text-md-end">
                                    <button type="button" class="btn btn-outline-custom btn-sm w-100" onclick="this.closest('.return-row').remove()">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-custom mt-3" onclick="addReturnRow()">
                            <i class="fas fa-plus-circle me-2"></i>Add Another Product
                        </button>
                    </div>

                    <!-- Collections Section -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-money-bill-wave me-2"></i>Collections & Payments
                        </div>

                        <!-- Stack payment received above credit transactions to provide more vertical space -->
                        <div class="row g-4">
                            <div class="col-12">
                                <div class="input-section">
                                    <div class="input-section-title"><i class="fas fa-wallet me-2"></i>Payment Received</div>
                                    <div class="row g-3">
                                        <div class="col-12 col-lg-6">
                                            <label class="form-label" for="cashCollected">Cash Collected</label>
                                            <div class="input-group input-group-lg">
                                                <span class="input-group-text">₦</span>
                                                <input type="number" name="cash_collected" id="cashCollected" class="form-control" step="0.01" min="0" value="<?php echo isset($_POST['cash_collected']) ? htmlspecialchars($_POST['cash_collected']) : ($today_summary ? htmlspecialchars($today_summary['cash_collected']) : '0'); ?>" placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="col-12 col-lg-6">
                                            <label class="form-label" for="bankTransferAmount">Bank Transfer</label>
                                            <div class="input-group input-group-lg">
                                                <span class="input-group-text">₦</span>
                                                <input type="number" name="transfer_amount" id="bankTransferAmount" class="form-control" step="0.01" min="0" value="<?php echo isset($_POST['transfer_amount']) ? htmlspecialchars($_POST['transfer_amount']) : ($today_summary ? htmlspecialchars($today_summary['transfer_amount']) : '0'); ?>" placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="input-section">
                                    <div class="input-section-title"><i class="fas fa-hand-holding-usd me-2"></i>Credit Transactions</div>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <div class="form-label">New Credit Entries</div>
                                            <div id="creditEntriesContainer" class="row gy-3">
                                                <?php
                                                    $entryAmount0 = isset($_POST['credit_amounts'][0]) ? htmlspecialchars($_POST['credit_amounts'][0]) : (isset($today_credits[0]['amount']) ? htmlspecialchars($today_credits[0]['amount']) : '');
                                                    $entryName0 = isset($_POST['credit_customer_names'][0]) ? htmlspecialchars($_POST['credit_customer_names'][0]) : (isset($today_credits[0]['customer_name']) ? htmlspecialchars($today_credits[0]['customer_name']) : '');
                                                ?>
                                                <div class="row g-2 align-items-end credit-entry-row">
                                                    <div class="col-md-4 col-lg-3">
                                                        <div class="input-group">
                                                            <span class="input-group-text">₦</span>
                                                            <label class="visually-hidden" for="activity-credit-amount-0">Credit amount</label>
                                                            <input type="number" id="activity-credit-amount-0" name="credit_amounts[]" class="form-control" step="0.01" min="0" value="<?php echo $entryAmount0; ?>" placeholder="Amount">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6 col-lg-7">
                                                        <label class="visually-hidden" for="activity-credit-customer-0">Credit customer name</label>
                                                        <input type="text" id="activity-credit-customer-0" name="credit_customer_names[]" class="form-control" placeholder="Customer name" value="<?php echo $entryName0; ?>">
                                                    </div>
                                                    <div class="col-md-2 col-lg-2 text-end">
                                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.credit-entry-row').remove()">Remove</button>
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-outline-custom btn-sm mt-3" onclick="addCreditEntry()">
                                                <i class="fas fa-plus-circle me-2"></i>Add Credit Entry
                                            </button>
                                            <small class="text-muted d-block mt-2">Start with one entry; add more as needed.</small>
                                        </div>
                                        <div class="col-12">
                                            <div class="alert alert-info d-flex align-items-center justify-content-between mb-0">
                                                <div>
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    <strong>Credit settlements</strong> are now managed in the Credit Dashboard.
                                                </div>
                                                <a href="../credit/by-driver.php<?php echo !empty($selected_driver_name) ? '?driver=' . urlencode($selected_driver_name) : ''; ?>" class="btn btn-sm btn-info text-white" style="white-space: nowrap;">
                                                    <i class="fas fa-arrow-right me-1"></i>Go to Dashboard
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Damaged Products Section -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-box-open me-2"></i>Damaged or Unsaleable Products
                        </div>
                        <div class="row g-4 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label" for="damaged_product_id">Damaged Product</label>
                                <select name="damaged_product_id" id="damaged_product_id" class="form-select">
                                    <option value="">Select damaged product</option>
                                    <?php foreach ($products as $prod): 
                                        $effectivePrice = floatval($prod['cost_price'] ?? 0);
                                        if (isset($prod['unit_price'])) {
                                            $price = floatval($prod['unit_price']);
                                            $discountAmount = floatval($prod['discount_amount'] ?? 0);
                                            $dispatchQty = max(1, floatval($prod['quantity'] ?? 1));
                                            $effectivePrice = max(0, $price - ($discountAmount / $dispatchQty));
                                        }
                                    ?>
                                        <option value="<?php echo $prod['id']; ?>" data-price="<?php echo number_format($effectivePrice, 2, '.', ''); ?>" <?php echo isset($_POST['damaged_product_id']) && intval($_POST['damaged_product_id']) === intval($prod['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($prod['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="damaged_product_quantity">Damaged Quantity</label>
                                <input type="number" name="damaged_product_quantity" id="damaged_product_quantity" class="form-control" step="0.01" min="0" value="<?php echo isset($_POST['damaged_product_quantity']) ? htmlspecialchars($_POST['damaged_product_quantity']) : ($today_summary ? '0' : '0'); ?>" placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="damaged_products">Value Loss</label>
                                <div class="input-group">
                                    <span class="input-group-text">₦</span>
                                    <input type="number" name="damaged_products" id="damaged_products" class="form-control" step="0.01" min="0" value="<?php echo isset($_POST['damaged_products']) ? htmlspecialchars($_POST['damaged_products']) : ($today_summary ? htmlspecialchars($today_summary['damaged_products']) : '0'); ?>" placeholder="0.00">
                                </div>
                                <div class="form-text text-muted">Selecting a product and quantity will calculate the damaged value automatically.</div>
                            </div>
                        </div>
                        <div class="row g-4 align-items-end mt-3">
                            <div class="col-md-12">
                                <label class="form-label" for="damaged_products_note">Description <small class="text-muted">(What was damaged and why)</small></label>
                                <input type="text" id="damaged_products_note" name="damaged_products_note" class="form-control" placeholder="e.g., 5 crates broken during transport, 3 units expired" value="<?php echo isset($_POST['damaged_products_note']) ? htmlspecialchars($_POST['damaged_products_note']) : ($today_summary ? htmlspecialchars($today_summary['damaged_products_note']) : ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Expenses Section -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-receipt me-2"></i>Trip Expenses
                        </div>
                        <div class="row g-4 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label" for="expense_type">Category</label>
                                <select id="expense_type" name="expense_type" class="form-select">
                                    <option value="">Select category</option>
                                    <?php foreach (['fuel' => 'Fuel', 'repair' => 'Repair', 'maintenance' => 'Maintenance', 'toll' => 'Toll', 'parking' => 'Parking', 'food' => 'Food', 'accommodation' => 'Accommodation', 'other' => 'Other'] as $expenseType => $expenseLabel): ?>
                                        <option value="<?php echo $expenseType; ?>" <?php echo ((is_scalar($_POST['expense_type'] ?? null) ? (string) $_POST['expense_type'] : ($today_summary['expense_type'] ?? '')) === $expenseType) ? 'selected' : ''; ?>><?php echo $expenseLabel; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="expenses_amount">Amount Spent</label>
                                <div class="input-group">
                                    <span class="input-group-text">₦</span>
                                    <input type="number" id="expenses_amount" name="expenses_amount" class="form-control" step="0.01" min="0" value="<?php echo is_scalar($_POST['expenses_amount'] ?? null) ? htmlspecialchars((string) $_POST['expenses_amount'], ENT_QUOTES, 'UTF-8') : ($today_summary ? htmlspecialchars((string) $today_summary['expenses_amount'], ENT_QUOTES, 'UTF-8') : '0'); ?>" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="expenses_note">Expense Details <small class="text-muted">(What was purchased)</small></label>
                                <input type="text" id="expenses_note" name="expenses_note" class="form-control" placeholder="e.g., Fuel for generator, vehicle maintenance, driver lunch" value="<?php echo is_scalar($_POST['expenses_note'] ?? null) ? htmlspecialchars((string) $_POST['expenses_note'], ENT_QUOTES, 'UTF-8') : ($today_summary ? htmlspecialchars((string) $today_summary['expenses_note'], ENT_QUOTES, 'UTF-8') : ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Discounts Section -->
                    <div class="section-card">
                        <div class="section-title">
                            <i class="fas fa-percent me-2"></i>Discounts & Concessions
                        </div>
                        <div class="row g-4 align-items-end">
                            <div class="col-md-12">
                                <label class="form-label" for="tripDiscountAmount">Discount Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">₦</span>
                                    <input type="text" id="tripDiscountAmount" class="form-control" value="<?php echo number_format($trip_discount_total, 2); ?>" readonly>
                                </div>
                                <div class="form-text">This discount amount is sourced from the selected trip details and dispatch item discounts.</div>
                            </div>
                        </div>
                    </div>
                    
                    </div><!-- End financialForm -->
                    </div><!-- End financialInputSection -->

                    <!-- Action Buttons -->
                    <div class="section-card py-4 mt-4" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                            <a href="index.php" class="btn btn-outline-custom order-2 order-md-1">
                                <i class="fas fa-arrow-left me-2"></i>Back to Daily Sales
                            </a>
                            
                            <?php if ($can_edit && !$today_summary && !$edit_activity): ?>
                            <button type="submit" name="record_activity_expense" value="1" class="btn btn-outline-warning order-1 order-md-2">
                                <i class="fas fa-receipt me-2"></i>Save Expense Only
                            </button>
                            <button id="recordSubmitButton" type="submit" name="record_activity" value="1" class="btn btn-record order-1 order-md-2 px-5">
                                <i class="fas fa-save me-2"></i>Save Activity Record
                            </button>
                            <?php elseif (!$can_edit): ?>
                            <button type="button" class="btn btn-secondary order-1 order-md-2" disabled>
                                <i class="fas fa-lock me-2"></i>View Only Mode
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/clean-dashboard.js?v=<?php echo time(); ?>"></script>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
                <div class="modal-header border-0 pb-0" style="padding: 1.5rem 1.5rem 0.5rem;">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 rounded-circle p-2 me-3">
                            <i class="fas fa-edit text-primary fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold mb-0" id="editModalLabel">Edit Activity Record</h5>
                            <small class="text-muted">Update recorded values for this trip</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($activity_csrf_token); ?>">
                    <div class="modal-body" style="padding: 1.5rem;">
                        <input type="hidden" name="edit_activity" value="1">
                        <input type="hidden" name="activity_id" id="edit_activity_id">

                        <div class="mb-4">
                            <h6 class="fw-bold text-muted text-uppercase small mb-3">
                                <i class="fas fa-boxes me-2"></i>Quantities Sold
                            </h6>
                            <div id="editSalesQuantities" class="row g-3">
                                <div class="col-12 text-muted">Select a trip to load its products.</div>
                            </div>
                        </div>

                        <!-- Returns Section (Editable) -->
                        <div class="mb-4" id="edit_returns_section">
                            <h6 class="fw-bold text-muted text-uppercase small mb-3" style="letter-spacing: 0.05em;">
                                <i class="fas fa-undo-alt me-2"></i>Products Returned
                            </h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover" style="font-size: 0.9rem;">
                                    <thead>
                                        <tr style="background: #f8fafc;">
                                            <th>Product</th>
                                            <th style="width: 100px;">Quantity</th>
                                            <th style="width: 120px;">Value (₦)</th>
                                            <th style="width: 80px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="edit_returns_table">
                                        <!-- Existing returns will be populated here via JavaScript -->
                                    </tbody>
                                    <tbody id="edit_new_returns_table">
                                        <!-- New return rows will be added here -->
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-outline-custom btn-sm mt-3" onclick="addEditReturnRow()">
                                <i class="fas fa-plus-circle me-2"></i>Add Returned Product
                            </button>
                        </div>

                        <!-- Collections Section -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-muted text-uppercase small mb-3" style="letter-spacing: 0.05em;">
                                <i class="fas fa-money-bill-wave me-2"></i>Collections
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small" for="edit_cash_collected">Cash Collected</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="cash_collected" id="edit_cash_collected" class="form-control" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small" for="edit_transfer_amount">Transfer Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="transfer_amount" id="edit_transfer_amount" class="form-control" step="0.01" min="0">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Credit Section -->
                        <div class="mb-4">
                            <h6 class="fw-bold text-muted text-uppercase small mb-3" style="letter-spacing: 0.05em;">
                                <i class="fas fa-hand-holding-usd me-2"></i>Credit
                            </h6>
                            <div class="row g-3">
                                <div class="col-12">
                                    <div id="editCreditEntriesContainer">
                                        <?php
                                            $editAmount0 = isset($today_credits[0]['amount']) ? htmlspecialchars($today_credits[0]['amount']) : '';
                                            $editName0 = isset($today_credits[0]['customer_name']) ? htmlspecialchars($today_credits[0]['customer_name']) : '';
                                        ?>
                                        <div class="row g-2 align-items-end edit-credit-entry-row">
                                            <div class="col-md-5">
                                                <label class="form-label small" for="edit-credit-amount-0">Amount</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₦</span>
                                                    <input type="number" id="edit-credit-amount-0" name="credit_amounts[]" class="form-control" step="0.01" min="0" value="<?php echo $editAmount0; ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label small" for="edit-credit-customer-0">Customer Name</label>
                                                <input type="text" id="edit-credit-customer-0" name="credit_customer_names[]" class="form-control" value="<?php echo $editName0; ?>">
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.edit-credit-entry-row').remove()">Remove</button>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-outline-custom btn-sm mt-3" onclick="addEditCreditEntry()">
                                        <i class="fas fa-plus-circle me-2"></i>Add Credit Entry
                                    </button>
                                </div>
                                <div class="col-12 mt-3">
                                    <div class="alert alert-info mb-0">
                                        Credit settlements are now managed in the <a href="../record_credit_settlements.php">Credit Dashboard</a>. Record payments there.
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted">Start with one entry each; add more when necessary.</small>
                        </div>

                        <!-- Expenses & Damaged -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <h6 class="fw-bold text-muted text-uppercase small mb-3" style="letter-spacing: 0.05em;">
                                    <i class="fas fa-receipt me-2"></i>Expenses
                                </h6>
                                <div class="mb-3">
                                    <label class="form-label small" for="edit_expense_type">Category</label>
                                    <select name="expense_type" id="edit_expense_type" class="form-select">
                                        <option value="">Select category</option>
                                        <option value="fuel">Fuel</option>
                                        <option value="repair">Repair</option>
                                        <option value="maintenance">Maintenance</option>
                                        <option value="toll">Toll</option>
                                        <option value="parking">Parking</option>
                                        <option value="food">Food</option>
                                        <option value="accommodation">Accommodation</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small" for="edit_expenses_amount">Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="expenses_amount" id="edit_expenses_amount" class="form-control" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small" for="edit_expenses_note">Note</label>
                                    <input type="text" name="expenses_note" id="edit_expenses_note" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="fw-bold text-muted text-uppercase small mb-3" style="letter-spacing: 0.05em;">
                                    <i class="fas fa-box-open me-2"></i>Damaged Products
                                </h6>
                                <div class="mb-3">
                                    <label class="form-label small" for="edit_damaged_product_id">Damaged Product</label>
                                    <select name="damaged_product_id" id="edit_damaged_product_id" class="form-select">
                                        <option value="">Select damaged product</option>
                                        <?php foreach ($products as $prod): 
                                            $effectivePrice = floatval($prod['cost_price'] ?? 0);
                                            if (isset($prod['unit_price'])) {
                                                $price = floatval($prod['unit_price']);
                                                $discountAmount = floatval($prod['discount_amount'] ?? 0);
                                                $dispatchQty = max(1, floatval($prod['quantity'] ?? 1));
                                                $effectivePrice = max(0, $price - ($discountAmount / $dispatchQty));
                                            }
                                        ?>
                                            <option value="<?php echo $prod['id']; ?>" data-price="<?php echo number_format($effectivePrice, 2, '.', ''); ?>">
                                                <?php echo htmlspecialchars($prod['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small" for="edit_damaged_product_quantity">Damaged Quantity</label>
                                    <input type="number" name="damaged_product_quantity" id="edit_damaged_product_quantity" class="form-control" step="0.01" min="0" value="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small" for="edit_damaged_products">Value</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₦</span>
                                        <input type="number" name="damaged_products" id="edit_damaged_products" class="form-control" step="0.01" min="0">
                                    </div>
                                    <div class="form-text text-muted">Selecting a product and quantity calculates the damaged value automatically.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small" for="edit_damaged_products_note">Details</label>
                                    <input type="text" name="damaged_products_note" id="edit_damaged_products_note" class="form-control">
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- END OLD CODE -->
                        </div>
                    </div>
                    <div class="modal-footer border-0" style="padding: 1rem 1.5rem 1.5rem;">
                        <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_activity_expense" value="1" class="btn btn-outline-warning">
                            <i class="fas fa-receipt me-2"></i>Save Expense
                        </button>
                        <button type="submit" class="btn btn-record">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Settlement handling moved to Credit Dashboard; remove local settlement helpers.
        let activityCsrfToken = <?php echo json_encode($activity_csrf_token); ?>;

        function rotateActivityCsrfToken(token) {
            if (typeof token !== 'string' || token === '') return;
            activityCsrfToken = token;
            document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
                input.value = token;
            });
        }

        function loadDispatches(event, isAutoLoad = false) {
            const driverSelect = document.getElementById('driverSelect');
            const driverIdInput = document.querySelector('input[name="driver_id"]');
            const driverId = driverSelect ? driverSelect.value : (driverIdInput ? driverIdInput.value : null);
            const recordDate = document.getElementById('dateInput').value;
            const dispatchSelect = document.getElementById('dispatchSelect');
            const loadingIndicator = document.getElementById('tripsLoadingIndicator');

            if (!driverId || !recordDate) {
                if (!isAutoLoad) {
                    alert('Please select both a driver and date first.');
                }
                return;
            }

            // Clear any stale activity state when loading a new driver/date
            clearActivitySummary();
            clearDispatchForm();
            clearCreditEntries();
            clearReturnsTable();
            const selectDispatchAlert = document.getElementById('selectDispatchAlert');
            const financialForm = document.getElementById('financialForm');
            if (financialForm) {
                financialForm.style.display = 'none';
            }
            if (selectDispatchAlert) {
                selectDispatchAlert.style.display = 'flex';
            }

            // Show loading indicator
            if (loadingIndicator) {
                loadingIndicator.style.display = 'block';
            }

            // Make AJAX request to get dispatches
            const formData = new FormData();
            formData.append('driver_id', driverId);
            formData.append('record_date', recordDate);
            formData.append('load_dispatches', '1');

            fetch('record.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Dispatches loaded:', data);
                
                // Hide loading indicator
                if (loadingIndicator) {
                    loadingIndicator.style.display = 'none';
                }

                // Clear existing options
                dispatchSelect.innerHTML = '<option value="">Choose Trip</option>';

                if (data.dispatches && data.dispatches.length > 0) {
                    data.dispatches.forEach(dispatch => {
                        const option = document.createElement('option');
                        option.value = dispatch.id;
                        option.textContent = `${dispatch.dispatch_number} - ₦${parseFloat(dispatch.expected_amount).toLocaleString()}`;
                        // Auto-select if this matches the selected dispatch_id from URL
                        <?php if ($dispatch_id): ?>
                        if (dispatch.id == <?php echo (int)$dispatch_id; ?>) {
                            option.selected = true;
                        }
                        <?php endif; ?>
                        dispatchSelect.appendChild(option);
                    });
                    <?php if ($dispatch_id): ?>
                    if (dispatchSelect.value) {
                        dispatchSelect.dispatchEvent(new Event('change'));
                        loadProducts(dispatchSelect.value);
                    }
                    <?php endif; ?>
                } else {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'No trips found';
                    dispatchSelect.appendChild(option);
                }

                // Show financial input section
                document.getElementById('financialInputSection').style.display = 'block';
                if (dispatchSelect.value) {
                    loadProducts(dispatchSelect.value);
                }
            })
            .catch(error => {
                console.error('Error loading dispatches:', error);
                // Hide loading indicator
                if (loadingIndicator) {
                    loadingIndicator.style.display = 'none';
                }
                dispatchSelect.innerHTML = '<option value="">Error loading trips</option>';
                if (!isAutoLoad) {
                    alert('Failed to load trips: ' + error.message + '. Please try again.');
                }
            });
        }

        // Cache for loaded products to avoid repeated AJAX calls
        const productsCache = {};

        function loadProducts(dispatchId) {
            if (!dispatchId) {
                return;
            }

            // Check if products are already cached
            if (productsCache[dispatchId]) {
                updateProductSelects(productsCache[dispatchId]);
                return;
            }

            const productFormData = new FormData();
            productFormData.append('dispatch_id', dispatchId);
            productFormData.append('load_products', '1');

            // Show loading state
            const productRows = document.querySelectorAll('#returnsList .return-row');
            productRows.forEach(row => {
                const select = row.querySelector('select[name="return_product_id[]"]');
                if (select) {
                    select.innerHTML = '<option value="">Loading products...</option>';
                    select.disabled = true;
                }
            });

            fetch('record.php', {
                method: 'POST',
                body: productFormData
            })
            .then(response => response.json())
            .then(data => {
                // Cache the products
                productsCache[dispatchId] = data.products || [];
                // Update the selects
                updateProductSelects(data.products || []);
            })
            .catch(error => {
                productRows.forEach(row => {
                    const select = row.querySelector('select[name="return_product_id[]"]');
                    if (select) {
                        select.innerHTML = '<option value="">Error loading products</option>';
                        select.disabled = false;
                    }
                });
            });
        }

        function updateProductSelects(products) {
            const productRows = document.querySelectorAll('#returnsList .return-row');
            productRows.forEach(row => {
                const select = row.querySelector('select[name="return_product_id[]"]');
                if (!select) {
                    return;
                }
                const currentValue = select.value;
                select.innerHTML = '<option value="">Select Product</option>';
                if (products && products.length > 0) {
                    products.forEach(product => {
                        const option = document.createElement('option');
                        option.value = product.id;
                        option.textContent = product.name;
                        if (String(product.id) === String(currentValue)) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    });
                }
                select.disabled = false;
            });
            updateDamagedProductSelects(products);
            renderSoldQuantityInputs(products, 'salesQuantities');
            renderSoldQuantityInputs(products, 'editSalesQuantities');
        }

        function renderSoldQuantityInputs(products, containerId) {
            const container = document.getElementById(containerId);
            if (!container) {
                return;
            }

            container.replaceChildren();
            if (!products || products.length === 0) {
                const emptyState = document.createElement('div');
                emptyState.className = 'col-12 text-muted';
                emptyState.textContent = 'No products are available for this trip.';
                container.appendChild(emptyState);
                return;
            }

            products.forEach(product => {
                const column = document.createElement('div');
                column.className = 'col-12 col-md-6';

                const label = document.createElement('label');
                label.className = 'form-label';
                label.textContent = `${product.name} sold (of ${product.dispatched_quantity})`;

                const input = document.createElement('input');
                input.type = 'number';
                input.name = `quantity_sold[${product.id}]`;
                input.className = 'form-control';
                input.min = '0';
                input.max = product.dispatched_quantity;
                input.step = '0.01';
                input.required = true;
                input.value = product.quantity_sold ?? '0';

                label.appendChild(input);
                column.appendChild(label);
                container.appendChild(column);
            });
        }

        function updateDamagedProductSelects(products) {
            ['damaged_product_id', 'edit_damaged_product_id'].forEach(selectId => {
                const select = document.getElementById(selectId);
                if (!select) {
                    return;
                }
                const currentValue = select.value;
                select.innerHTML = '<option value="">Select damaged product</option>';
                if (products && products.length > 0) {
                    products.forEach(product => {
                        const option = document.createElement('option');
                        option.value = product.id;
                        option.textContent = product.name;
                        option.dataset.price = product.price || 0;
                        if (String(product.id) === String(currentValue)) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    });
                }
            });
        }

        // Auto-load dispatches on page load if driver and date are already selected
        document.addEventListener('DOMContentLoaded', function() {
            const driverSelect = document.getElementById('driverSelect');
            const driverIdInput = document.querySelector('input[name="driver_id"]');
            const dateInput = document.getElementById('dateInput');

            if (!dateInput) {
                return;
            }

            // Get driver ID from either select or hidden input (for shop attendants)
            const driverId = driverSelect ? driverSelect.value : (driverIdInput ? driverIdInput.value : null);
            const recordDate = dateInput.value;
            
            // Auto-load dispatches if driver and date are available
            if (driverId && recordDate) {
                loadDispatches(null, true);
            }

            // Auto-load dispatches when driver or date is selected (only for non-shop attendants)
            if (driverSelect) {
                driverSelect.addEventListener('change', function() {
                    const driverId = this.value;
                    const recordDate = dateInput.value;
                    if (driverId && recordDate) {
                        loadDispatches(null, true);
                    }
                });
            }

            dateInput.addEventListener('change', function() {
                const driverId = driverSelect ? driverSelect.value : (driverIdInput ? driverIdInput.value : null);
                const recordDate = this.value;
                if (driverId && recordDate) {
                    loadDispatches(null, true);
                }
            });

            // Show financial input section when dispatch is selected
            document.getElementById('dispatchSelect').addEventListener('change', function() {
                const alertDiv = document.getElementById('selectDispatchAlert');
                const formDiv = document.getElementById('financialForm');
                if (this.value) {
                    alertDiv.style.display = 'none';
                    formDiv.style.display = 'block';
                    loadProducts(this.value);
                    
                    // Load activity data for the selected dispatch via AJAX
                    loadActivityForDispatch(this.value);
                } else {
                    alertDiv.style.display = 'flex';
                    formDiv.style.display = 'none';
                }
            });

            const activityForm = document.getElementById('activityForm');
            const recordSubmitButton = document.getElementById('recordSubmitButton');
            if (activityForm && recordSubmitButton) {
                activityForm.addEventListener('submit', function(e) {
                    // Validate that a trip is selected
                    const dispatchSelect = document.getElementById('dispatchSelect');
                    if (!dispatchSelect.value) {
                        e.preventDefault();
                        alert('Please select a trip before saving.');
                        return false;
                    }
                    
                    recordSubmitButton.disabled = true;
                    recordSubmitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                });
            }

            function watchDamageInputs(selectId, quantityId, targetId) {
                const productSelect = document.getElementById(selectId);
                const quantityInput = document.getElementById(quantityId);
                const totalInput = document.getElementById(targetId);
                if (!productSelect || !quantityInput || !totalInput) {
                    return;
                }

                const updateValue = () => {
                    const selectedOption = productSelect.selectedOptions[0];
                    const qty = parseFloat(quantityInput.value) || 0;
                    const price = parseFloat(selectedOption?.dataset?.price || 0) || 0;
                    if (productSelect.value && qty > 0 && price > 0) {
                        totalInput.value = (qty * price).toFixed(2);
                    }
                };

                productSelect.addEventListener('change', updateValue);
                quantityInput.addEventListener('input', updateValue);
                updateValue();
            }

            watchDamageInputs('damaged_product_id', 'damaged_product_quantity', 'damaged_products');
            watchDamageInputs('edit_damaged_product_id', 'edit_damaged_product_quantity', 'edit_damaged_products');
            
            // Auto-show form if dispatch is selected on page load
            const dispatchSelect = document.getElementById('dispatchSelect');
            const financialForm = document.getElementById('financialForm');
            const selectDispatchAlert = document.getElementById('selectDispatchAlert');
            
            if (dispatchSelect && dispatchSelect.value && financialForm) {
                financialForm.style.display = 'block';
                if (selectDispatchAlert) selectDispatchAlert.style.display = 'none';
            }
        });

        let nextActivityFieldId = 1;

        function addReturnRow() {
            const row = document.querySelector('.return-row').cloneNode(true);
            const rowId = nextActivityFieldId++;
            const productSelect = row.querySelector('select');
            const quantityInput = row.querySelector('input');
            const labels = row.querySelectorAll('.form-label');
            productSelect.id = `activity-return-product-${rowId}`;
            quantityInput.id = `activity-return-quantity-${rowId}`;
            labels[0].htmlFor = productSelect.id;
            labels[1].htmlFor = quantityInput.id;
            row.querySelector('select').value = '';
            row.querySelector('input').value = '';
            // Reset all labels for mobile view
            row.querySelectorAll('.form-label').forEach(label => {
                label.classList.add('d-md-none');
            });
            document.getElementById('returnsList').appendChild(row);
        }

        const editReturnProducts = <?php echo json_encode($products); ?>;

        function createEditReturnOptions(selectedValue = '') {
            let options = '<option value="">Select Product...</option>';
            editReturnProducts.forEach(product => {
                const selected = String(product.id) === String(selectedValue) ? ' selected' : '';
                options += `<option value="${product.id}"${selected}>${product.name}</option>`;
            });
            return options;
        }

        function addEditReturnRow(productId = '', quantity = '') {
            const newReturnsTable = document.getElementById('edit_new_returns_table');
            if (!newReturnsTable) {
                return;
            }
            const rowId = nextActivityFieldId++;
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <label class="visually-hidden" for="edit-return-product-${rowId}">Returned product</label>
                    <select id="edit-return-product-${rowId}" name="return_product_id[]" class="form-select form-select-sm">
                        ${createEditReturnOptions(productId)}
                    </select>
                </td>
                <td>
                    <label class="visually-hidden" for="edit-return-quantity-${rowId}">Returned quantity</label>
                    <input type="number" id="edit-return-quantity-${rowId}" name="return_quantity[]" class="form-control form-control-sm" step="0.25" min="0.25" value="${quantity}" placeholder="Qty">
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            `;
            newReturnsTable.appendChild(row);
        }

        function addCreditEntry(amount = '', name = '') {
            const container = document.getElementById('creditEntriesContainer');
            if (!container) return;
            const rowId = nextActivityFieldId++;
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-end credit-entry-row';
            row.innerHTML = `
                <div class="col-md-4 col-lg-3">
                    <div class="input-group">
                        <span class="input-group-text">₦</span>
                        <label class="visually-hidden" for="activity-credit-amount-${rowId}">Credit amount</label>
                        <input type="number" id="activity-credit-amount-${rowId}" name="credit_amounts[]" class="form-control" step="0.01" min="0" value="${amount}" placeholder="Amount">
                    </div>
                </div>
                <div class="col-md-6 col-lg-7">
                    <label class="visually-hidden" for="activity-credit-customer-${rowId}">Credit customer name</label>
                    <input type="text" id="activity-credit-customer-${rowId}" name="credit_customer_names[]" class="form-control" placeholder="Customer name" value="${name}">
                </div>
                <div class="col-md-2 col-lg-2 text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.credit-entry-row').remove()">Remove</button>
                </div>
            `;
            container.appendChild(row);
        }

        // addSettlementEntry removed (settlements handled in Credit Dashboard)

        function addEditCreditEntry(amount = '', name = '') {
            const container = document.getElementById('editCreditEntriesContainer');
            if (!container) return;
            const rowId = nextActivityFieldId++;
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-end edit-credit-entry-row';
            row.innerHTML = `
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text">₦</span>
                        <label class="visually-hidden" for="edit-credit-amount-${rowId}">Credit amount</label>
                        <input type="number" id="edit-credit-amount-${rowId}" name="credit_amounts[]" class="form-control" step="0.01" min="0" value="${amount}">
                    </div>
                </div>
                <div class="col-md-5">
                    <label class="visually-hidden" for="edit-credit-customer-${rowId}">Credit customer name</label>
                    <input type="text" id="edit-credit-customer-${rowId}" name="credit_customer_names[]" class="form-control" value="${name}">
                </div>
                <div class="col-md-2 text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.edit-credit-entry-row').remove()">Remove</button>
                </div>
            `;
            container.appendChild(row);
        }

        // addEditSettlementEntry removed (settlements handled in Credit Dashboard)

        function loadActivityForDispatch(dispatchId) {
            const recordDate = document.getElementById('dateInput').value;
            const driverId = document.getElementById('driverSelect')?.value || document.querySelector('input[name="driver_id"]')?.value;
            
            if (!dispatchId || !recordDate || !driverId) {
                return;
            }

            // Make AJAX request to load activity data for the selected dispatch
            const formData = new FormData();
            formData.append('dispatch_id', dispatchId);
            formData.append('driver_id', driverId);
            formData.append('record_date', recordDate);
            formData.append('load_activity', '1');

            fetch('record.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Activity data loaded:', data);
                if (data.activity) {
                    updateActivitySummary(data.activity);
                    populateDispatchForm(data.activity);
                    clearReturnsTable();
                    updateReturnsTable(data.returns || []);
                } else {
                    clearActivitySummary();
                    clearDispatchForm();
                    clearCreditEntries();
                    clearReturnsTable();
                }
            })
            .catch(error => {
                console.error('Error loading activity data:', error);
                clearActivitySummary();
                clearDispatchForm();
                clearCreditEntries();
                clearReturnsTable();
            });
        }

        function clearActivitySummary() {
            const fields = ['cash_collected', 'transfer_amount', 'credit_amount', 'credit_settlement', 'expenses_amount'];
            fields.forEach(key => {
                const element = document.getElementById(`summary_${key}`);
                if (element) {
                    element.textContent = '₦0';
                }
            });
            const balanceElement = document.getElementById('summary_credit_balance');
            if (balanceElement) {
                balanceElement.textContent = '₦0';
            }
        }

        function updateActivitySummary(activity) {
            const summaryElements = {
                'cash_collected': activity.cash_collected || 0,
                'transfer_amount': activity.transfer_amount || 0,
                'credit_amount': activity.credit_amount || 0,
                'credit_settlement': activity.credit_settlement || 0,
                'expenses_amount': activity.expenses_amount || 0
            };

            for (const [key, value] of Object.entries(summaryElements)) {
                const element = document.getElementById(`summary_${key}`);
                if (element) {
                    element.textContent = '₦' + parseFloat(value).toLocaleString('en-NG', {minimumFractionDigits: 0});
                }
            }

            const creditBalance = (activity.credit_amount || 0) - (activity.credit_settlement || 0);
            const balanceElement = document.getElementById('summary_credit_balance');
            if (balanceElement) {
                balanceElement.textContent = '₦' + creditBalance.toLocaleString('en-NG', {minimumFractionDigits: 0});
            }
        }

        function populateDispatchForm(activity) {
            const cashInput = document.querySelector('input[name="cash_collected"]');
            const transferInput = document.querySelector('input[name="transfer_amount"]');
            const expensesInput = document.querySelector('input[name="expenses_amount"]');
            const expenseTypeInput = document.querySelector('select[name="expense_type"]');
            const expensesNoteInput = document.querySelector('input[name="expenses_note"]');
            const damagedQuantityInput = document.getElementById('damaged_product_quantity');
            const damagedValueInput = document.getElementById('damaged_products');
            const damagedNoteInput = document.querySelector('input[name="damaged_products_note"]');

            if (cashInput) cashInput.value = parseFloat(activity.cash_collected || 0).toFixed(2);
            if (transferInput) transferInput.value = parseFloat(activity.transfer_amount || 0).toFixed(2);
            if (expensesInput) expensesInput.value = parseFloat(activity.expenses_amount || 0).toFixed(2);
            if (expenseTypeInput) expenseTypeInput.value = activity.expense_type || '';
            if (expensesNoteInput) expensesNoteInput.value = activity.expenses_note || '';
            if (damagedQuantityInput) damagedQuantityInput.value = parseFloat(activity.damaged_product_quantity || 0).toFixed(2);
            if (damagedValueInput) damagedValueInput.value = parseFloat(activity.damaged_products || 0).toFixed(2);
            if (damagedNoteInput) damagedNoteInput.value = activity.damaged_products_note || '';

            clearCreditEntries();
        }

        function clearDispatchForm() {
            const fields = [
                'input[name="cash_collected"]',
                'input[name="transfer_amount"]',
                'input[name="expenses_amount"]',
                'input[name="expenses_note"]',
                '#damaged_product_quantity',
                '#damaged_products',
                'input[name="damaged_products_note"]'
            ];

            fields.forEach(selector => {
                const element = document.querySelector(selector);
                if (element) {
                    element.value = selector.startsWith('input[name="expenses_note"]') || selector.startsWith('input[name="damaged_products_note"]') ? '' : '0';
                }
            });

            const expenseTypeInput = document.querySelector('select[name="expense_type"]');
            if (expenseTypeInput) expenseTypeInput.value = '';

            const damagedSelect = document.getElementById('damaged_product_id');
            if (damagedSelect) {
                damagedSelect.value = '';
            }
            clearCreditEntries();
        }

        function clearCreditEntries() {
            const container = document.getElementById('creditEntriesContainer');
            if (!container) return;
            container.innerHTML = `
                <div class="row g-2 align-items-end credit-entry-row">
                    <div class="col-md-4 col-lg-3">
                        <div class="input-group">
                            <span class="input-group-text">₦</span>
                            <input type="number" name="credit_amounts[]" class="form-control" step="0.01" min="0" value="" placeholder="Amount">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-7">
                        <input type="text" name="credit_customer_names[]" class="form-control" placeholder="Customer name" value="">
                    </div>
                    <div class="col-md-2 col-lg-2 text-end">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.credit-entry-row').remove()">Remove</button>
                    </div>
                </div>
            `;
        }

        function updateReturnsTable(returns) {
            const returnsTable = document.getElementById('edit_returns_table');
            if (!returnsTable) return;

            returnsTable.innerHTML = '';
            returns.forEach(ret => {
                const row = document.createElement('tr');
                const returnValue = (ret.quantity * (ret.unit_price || 0)).toFixed(2);
                row.innerHTML = `
                    <td style="vertical-align: middle;">${ret.product_name}</td>
                    <td>
                        <input type="number" class="form-control form-control-sm return-qty-input" 
                               data-return-id="${ret.id}" value="${ret.quantity}" 
                               step="0.25" min="0.25" style="width: 100%;">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm return-value-input" 
                               data-return-id="${ret.id}" value="${returnValue}" 
                               step="0.01" min="0" style="width: 100%;">
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                onclick="deleteReturn(${ret.id}, '${ret.product_name}')">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                `;
                returnsTable.appendChild(row);
            });

            // Re-attach event listeners
            document.querySelectorAll('.return-qty-input').forEach(input => {
                input.addEventListener('blur', function() {
                    const returnId = this.dataset.returnId;
                    const newQuantity = parseFloat(this.value);
                    if (newQuantity > 0) {
                        updateReturnQuantity(returnId, newQuantity);
                    }
                });
            });

            document.querySelectorAll('.return-value-input').forEach(input => {
                input.addEventListener('blur', function() {
                    const returnId = this.dataset.returnId;
                    const newValue = parseFloat(this.value);
                    if (newValue >= 0) {
                        updateReturnValue(returnId, newValue);
                    }
                });
            });
        }

        function clearReturnsTable() {
            const returnsTable = document.getElementById('edit_returns_table');
            if (!returnsTable) return;
            returnsTable.innerHTML = '';
        }

        function populateEditModal() {
            // Set activity data based on whether editing or viewing today's summary
            <?php if ($edit_activity): ?>
            const activityId = <?php echo $edit_activity['id']; ?>;
            const cashCollected = <?php echo $edit_activity['cash_collected']; ?>;
            const transferAmount = <?php echo $edit_activity['transfer_amount']; ?>;
            const expensesAmount = <?php echo $edit_activity['expenses_amount']; ?>;
            const expensesCategory = <?php echo json_encode($edit_activity['expense_type'] ?? ''); ?>;
            const expensesNote = <?php echo json_encode($edit_activity['expenses_note'] ?? ''); ?>;
            const damagedProducts = <?php echo $edit_activity['damaged_products']; ?>;
            const damagedProductsNote = <?php echo json_encode($edit_activity['damaged_products_note'] ?? ''); ?>;
            const editCreditAmounts = <?php echo json_encode(array_column($edit_credits ?? [], 'amount')); ?> || [];
            const editCreditNames = <?php echo json_encode(array_column($edit_credits ?? [], 'customer_name')); ?> || [];
            const returnsData = <?php echo json_encode($edit_returns ?? []); ?>;
            const isEditActivity = true;
            <?php elseif ($today_summary): ?>
            const activityId = <?php echo $today_summary['id']; ?>;
            const cashCollected = <?php echo $today_summary['cash_collected']; ?>;
            const transferAmount = <?php echo $today_summary['transfer_amount']; ?>;
            const expensesAmount = <?php echo $today_summary['expenses_amount']; ?>;
            const expensesCategory = <?php echo json_encode($today_summary['expense_type'] ?? ''); ?>;
            const expensesNote = <?php echo json_encode($today_summary['expenses_note'] ?? ''); ?>;
            const damagedProducts = <?php echo $today_summary['damaged_products']; ?>;
            const damagedProductsNote = <?php echo json_encode($today_summary['damaged_products_note'] ?? ''); ?>;
            const editCreditAmounts = <?php echo json_encode(array_column($today_credits, 'amount')); ?> || [];
            const editCreditNames = <?php echo json_encode(array_column($today_credits, 'customer_name')); ?> || [];
            const returnsData = <?php echo json_encode($today_returns); ?>;
            const isEditActivity = false;
            <?php else: ?>
            const activityId = 0;
            const cashCollected = 0;
            const transferAmount = 0;
            const expensesAmount = 0;
            const expensesCategory = '';
            const expensesNote = '';
            const damagedProducts = 0;
            const damagedProductsNote = '';
            const editCreditAmounts = [];
            const editCreditNames = [];
            const returnsData = [];
            const isEditActivity = false;
            <?php endif; ?>

            // Populate basic fields
            document.getElementById('edit_activity_id').value = activityId;
            document.getElementById('edit_cash_collected').value = cashCollected;
            document.getElementById('edit_transfer_amount').value = transferAmount;
            document.getElementById('edit_expenses_amount').value = expensesAmount;
            document.getElementById('edit_expense_type').value = expensesCategory;
            document.getElementById('edit_expenses_note').value = expensesNote;
            document.getElementById('edit_damaged_products').value = damagedProducts;
            document.getElementById('edit_damaged_products_note').value = damagedProductsNote;

            // Clear existing dynamic containers
            const creditContainer = document.getElementById('editCreditEntriesContainer');
            if (creditContainer) creditContainer.innerHTML = '';

            // Populate credit entries
            if (editCreditAmounts.length > 0) {
                for (let i = 0; i < editCreditAmounts.length; i++) {
                    addEditCreditEntry(editCreditAmounts[i] || '', editCreditNames[i] || '');
                }
            } else {
                addEditCreditEntry();
            }

            // Populate damaged product selection and quantity from note if available
            <?php if ($edit_activity && !empty($edit_activity['damaged_products_note'])): ?>
            const damagedNote = <?php echo json_encode($edit_activity['damaged_products_note']); ?>;
            const damagedQtyMatch = damagedNote.match(/Damaged\s+([\d.]+)\s+units?\s+of\s+(.+?)[.]?$/i);
            if (damagedQtyMatch) {
                const damagedQty = parseFloat(damagedQtyMatch[1]);
                const damagedProdName = damagedQtyMatch[2].trim();
                document.getElementById('edit_damaged_product_quantity').value = damagedQty;
                
                // Try to find and select the product by name
                const productSelect = document.getElementById('edit_damaged_product_id');
                if (productSelect) {
                    for (let i = 0; i < productSelect.options.length; i++) {
                        if (productSelect.options[i].text.trim() === damagedProdName) {
                            productSelect.selectedIndex = i;
                            break;
                        }
                    }
                }
            }
            <?php elseif ($today_summary && !empty($today_summary['damaged_products_note'])): ?>
            const damagedNote = <?php echo json_encode($today_summary['damaged_products_note']); ?>;
            const damagedQtyMatch = damagedNote.match(/Damaged\s+([\d.]+)\s+units?\s+of\s+(.+?)[.]?$/i);
            if (damagedQtyMatch) {
                const damagedQty = parseFloat(damagedQtyMatch[1]);
                const damagedProdName = damagedQtyMatch[2].trim();
                document.getElementById('edit_damaged_product_quantity').value = damagedQty;
                
                // Try to find and select the product by name
                const productSelect = document.getElementById('edit_damaged_product_id');
                if (productSelect) {
                    for (let i = 0; i < productSelect.options.length; i++) {
                        if (productSelect.options[i].text.trim() === damagedProdName) {
                            productSelect.selectedIndex = i;
                            break;
                        }
                    }
                }
            }
            <?php endif; ?>
            
            // Populate returns if any
            console.log('Returns data:', returnsData);
            const returnsTable = document.getElementById('edit_returns_table');
            const newReturnsTable = document.getElementById('edit_new_returns_table');
            const returnsSection = document.getElementById('edit_returns_section');
            if (returnsTable) {
                returnsTable.innerHTML = '';
            }
            if (newReturnsTable) {
                newReturnsTable.innerHTML = '';
            }

            if (returnsData && returnsData.length > 0) {
                console.log('Populating returns table with', returnsData.length, 'items');
                returnsTable.innerHTML = '';
                returnsData.forEach(ret => {
                    const row = document.createElement('tr');
                    // Calculate return value based on quantity and product price
                    // Use unit_price if available, otherwise use a default calculation
                    const unitPrice = parseFloat(ret.unit_price) || 0;
                    const quantity = parseFloat(ret.quantity) || 0;
                    const returnValue = (quantity * unitPrice).toFixed(2);
                    console.log(`Return: ${ret.product_name}, Qty: ${quantity}, Unit Price: ${unitPrice}, Value: ${returnValue}`);
                    row.innerHTML = `
                        <td style="vertical-align: middle;">${ret.product_name}</td>
                        <td>
                            <input type="number" class="form-control form-control-sm return-qty-input" 
                                   data-return-id="${ret.id}" value="${quantity}" 
                                   step="0.25" min="0.25" style="width: 100%;">
                        </td>
                        <td>
                            <input type="number" class="form-control form-control-sm return-value-input" 
                                   data-return-id="${ret.id}" value="${returnValue}" 
                                   step="0.01" min="0" style="width: 100%;">
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                    onclick="deleteReturn(${ret.id}, '${ret.product_name}')">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    `;
                    returnsTable.appendChild(row);
                });
                
                // Add event listeners for quantity changes
                document.querySelectorAll('.return-qty-input').forEach(input => {
                    input.addEventListener('blur', function() {
                        const returnId = this.dataset.returnId;
                        const newQuantity = parseFloat(this.value);
                        if (newQuantity > 0) {
                            updateReturnQuantity(returnId, newQuantity);
                        }
                    });
                });
                
                // Add event listeners for value changes
                document.querySelectorAll('.return-value-input').forEach(input => {
                    input.addEventListener('blur', function() {
                        const returnId = this.dataset.returnId;
                        const newValue = parseFloat(this.value);
                        if (newValue >= 0) {
                            updateReturnValue(returnId, newValue);
                        }
                    });
                });
            }

            // Always show the returns section when editing a record
            returnsSection.style.display = 'block';
        }
        
        function updateReturnQuantity(returnId, newQuantity) {
            fetch('record.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'update_return=1&return_id=' + returnId + '&quantity=' + newQuantity + '&csrf_token=' + encodeURIComponent(activityCsrfToken)
            })
            .then(response => response.json())
            .then(data => {
                rotateActivityCsrfToken(data.csrf_token);
                if (data.success) {
                    // Show success message (optional)
                    const input = document.querySelector('[data-return-id="' + returnId + '"]');
                    input.style.borderColor = '#22c55e';
                    setTimeout(() => {
                        input.style.borderColor = '';
                    }, 1000);
                } else {
                    alert('Error updating return: ' + (data.error || 'Unknown error'));
                    // Reload the modal
                    populateEditModal();
                }
            })
            .catch(error => {
                alert('Failed to update return');
            });
        }
        
        function updateReturnValue(returnId, newValue) {
            // Get the unit price from the row data
            const row = document.querySelector('[data-return-id="' + returnId + '"]').closest('tr');
            const qtyInput = row.querySelector('.return-qty-input');
            const currentQty = parseFloat(qtyInput.value);
            
            // Calculate the unit price from current value and quantity
            const currentValue = parseFloat(row.querySelector('.return-value-input').value);
            const unitPrice = currentQty > 0 ? currentValue / currentQty : 0;
            
            // Calculate new quantity based on new value and unit price
            const newQuantity = unitPrice > 0 ? newValue / unitPrice : currentQty;
            
            if (newQuantity > 0) {
                // Update the quantity input
                qtyInput.value = newQuantity.toFixed(2);
                
                // Call updateReturnQuantity to update the database
                updateReturnQuantity(returnId, newQuantity);
            } else {
                alert('Invalid return value. Please enter a value greater than 0.');
                // Reset to original value
                row.querySelector('.return-value-input').value = currentValue;
            }
        }
        
        function deleteReturn(returnId, productName) {
            if (confirm('Remove return for ' + productName + '?\n\nThis will restore the stock and recalculate the dispatch sales amount.')) {
                fetch('record.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'delete_return=1&return_id=' + returnId + '&csrf_token=' + encodeURIComponent(activityCsrfToken)
                })
                .then(response => response.json())
                .then(data => {
                    rotateActivityCsrfToken(data.csrf_token);
                    if (data.success) {
                        // Remove the row from the table
                        const row = document.querySelector('[data-return-id="' + returnId + '"]').closest('tr');
                        row.style.opacity = '0.5';
                        setTimeout(() => {
                            row.remove();
                            // If no more returns, hide the section
                            const table = document.getElementById('edit_returns_table');
                            if (table.children.length === 0) {
                                document.getElementById('edit_returns_section').style.display = 'none';
                            }
                        }, 300);
                    } else {
                        alert('Error deleting return: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Failed to delete return');
                });
            }
        }

        function deleteCurrentActivity() {
            <?php if ($today_summary): ?>
            if (confirm('Are you sure you want to delete this activity record?\n\nThis will:\n- Delete the activity record\n- Remove associated returns and restore stock\n- Update dispatch sales amount\n- Adjust creditor records\n- Remove related customer payments\n\nThis action cannot be undone.')) {
                if (confirm('Please confirm again to delete this record permanently.')) {
                    const deleteForm = document.getElementById('deleteCurrentActivityForm');
                    if (deleteForm) {
                        deleteForm.requestSubmit();
                    }
                }
            }
            <?php endif; ?>
        }

        // Auto-load dispatches for shop attendants on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($is_shop_attendant && $shop_driver_id): ?>
                // Auto-load dispatches for shop attendants
                loadDispatches(null, true);
            <?php else: ?>
                // For other users, add event listener to driver select
                const driverSelect = document.getElementById('driverSelect');
                if (driverSelect) {
                    driverSelect.addEventListener('change', function(e) {
                        loadDispatches(e);
                    });
                }
            <?php endif; ?>

            // Add event listener to date input for all users
            const dateInput = document.getElementById('dateInput');
            if (dateInput) {
                dateInput.addEventListener('change', function(e) {
                    <?php if ($is_shop_attendant): ?>
                        loadDispatches(e, true);
                    <?php else: ?>
                        const driverSelect = document.getElementById('driverSelect');
                        if (driverSelect && driverSelect.value) {
                            loadDispatches(e);
                        }
                    <?php endif; ?>
                });
            }

            // Check if we should auto-open the edit modal
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('open_edit_modal') === '1') {
                // Wait a moment for the page to fully load
                setTimeout(function() {
                    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
                    if (typeof populateEditModal === 'function') {
                        populateEditModal();
                    }
                    editModal.show();
                }, 500);
            }

            // Add event listener to dispatch select
            const dispatchSelect = document.getElementById('dispatchSelect');
            if (dispatchSelect) {
                dispatchSelect.addEventListener('change', function(e) {
                    if (e.target.value) {
                        loadProducts(e.target.value);
                    }
                });
            }
        });
    </script>
    <?php if (hasRole(['admin']) && $today_summary): ?>
    <form id="deleteCurrentActivityForm" method="POST" action="delete_activity.php" hidden>
        <input type="hidden" name="id" value="<?php echo (int) $today_summary['id']; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($deleteActivityCsrfToken); ?>">
    </form>
    <?php endif; ?>
</body>
</html>
