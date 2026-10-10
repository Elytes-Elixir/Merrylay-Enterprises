<?php

function shopCashReconciliationParseAmount($value): ?float
{
    if (!is_scalar($value)) {
        return null;
    }

    $amount = trim((string) $value);
    if (!preg_match('/\A\d+(?:\.\d{1,2})?\z/', $amount)) {
        return null;
    }

    $parsed = (float) $amount;
    if (!is_finite($parsed) || $parsed > 9999999999.99) {
        return null;
    }

    return round($parsed, 2);
}

function shopCashReconciliationParseNotes($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $notes = trim($value);
    return strlen($notes) <= 2000 ? $notes : null;
}

function shopCashReconciliationCalculateExpectedCash(
    float $cashSales,
    float $cashShopCollections,
    float $shopExpenses,
    float $acknowledgedReturnedCash
): float {
    return round($cashSales + $cashShopCollections - $shopExpenses + $acknowledgedReturnedCash, 2);
}

function shopCashReconciliationIsValidDate($date): bool
{
    if (!is_string($date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function shopCashReconciliationGetSnapshot(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM shop_sales
        WHERE sale_date = ?
    ");
    $stmt->execute([$date]);
    $totalShopSales = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT payment_method, SUM(payment_amount) AS total_amount
        FROM (
            SELECT ss.payment_method, ss.total_amount AS payment_amount
            FROM shop_sales ss
            WHERE ss.sale_date = ? AND ss.payment_split = 0

            UNION ALL

            SELECT ssp.payment_method, ssp.amount AS payment_amount
            FROM shop_sale_payments ssp
            JOIN shop_sales ss ON ss.id = ssp.shop_sale_id
            WHERE ss.sale_date = ? AND ss.payment_split = 1
        ) shop_payment_rows
        GROUP BY payment_method
    ");
    $stmt->execute([$date, $date]);

    $paymentTotals = ['cash' => 0.0, 'transfer' => 0.0, 'card' => 0.0, 'credit' => 0.0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        if (array_key_exists($payment['payment_method'], $paymentTotals)) {
            $paymentTotals[$payment['payment_method']] = (float) $payment['total_amount'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(cr.amount), 0)
        FROM creditor_remittances cr
        WHERE cr.payment_date = ?
          AND cr.payment_method = 'cash'
          AND EXISTS (
              SELECT 1
              FROM shop_sales ss
              WHERE ss.creditor_id = cr.creditor_id
          )
    ");
    $stmt->execute([$date]);
    $cashShopCollections = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(collection_amount), 0) AS shop_expenses,
            COALESCE(SUM(amount_acknowledged), 0) AS acknowledged_returned_cash
        FROM shop_collections
        WHERE collection_date = ?
    ");
    $stmt->execute([$date]);
    $expenseTotals = $stmt->fetch(PDO::FETCH_ASSOC);

    $shopExpenses = (float) ($expenseTotals['shop_expenses'] ?? 0);
    $acknowledgedReturnedCash = (float) ($expenseTotals['acknowledged_returned_cash'] ?? 0);
    $expectedCash = shopCashReconciliationCalculateExpectedCash(
        $paymentTotals['cash'],
        $cashShopCollections,
        $shopExpenses,
        $acknowledgedReturnedCash
    );

    return [
        'total_shop_sales' => $totalShopSales,
        'cash_sales_snapshot' => $paymentTotals['cash'],
        'transfer_sales' => $paymentTotals['transfer'],
        'card_sales' => $paymentTotals['card'],
        'credit_sales' => $paymentTotals['credit'],
        'cash_shop_collections_snapshot' => $cashShopCollections,
        'shop_expenses_snapshot' => $shopExpenses,
        'acknowledged_returned_cash_snapshot' => $acknowledgedReturnedCash,
        'expected_cash' => $expectedCash,
    ];
}

function shopCashReconciliationGetStatus(?float $variance): string
{
    if ($variance === null) {
        return 'Unsubmitted';
    }

    if (abs($variance) < 0.01) {
        return 'Balanced';
    }

    return $variance < 0 ? 'Shortage' : 'Surplus';
}