<?php
/**
 * Record Remittance/Payment
 * 
 * Record payment from creditor and update their balance.
 */

if (!defined('MERRYLAY_SYSTEM')) {
    define('MERRYLAY_SYSTEM', true);
}
require_once '../../config/security.php';
require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

require_once '../../config/auth.php';

if (!hasRole(['admin', 'manager', 'cashier'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('Unauthorized creditor remittance by user ' . ($_SESSION['user_id'] ?? 'unknown'));
    }
    http_response_code(403);
    exit('You are not authorized to record creditor remittances.');
}

require_once '../../config/db.php';

$error = '';
$success = !empty($_SESSION['creditor_remit_success']) ? 'Remittance recorded successfully.' : '';
unset($_SESSION['creditor_remit_success']);
$creditorIdInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['creditor_id'] ?? '')
    : ($_GET['creditor_id'] ?? '');
$creditor_id = is_scalar($creditorIdInput) && ctype_digit((string) $creditorIdInput)
    ? (int) $creditorIdInput
    : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $postedCsrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedCsrfToken) || !SecurityUtils::consumeCSRFToken($postedCsrfToken)) {
        $error = 'This form has expired. Refresh the page and try again.';
    } else {
        $rawAmount = $_POST['amount'] ?? null;
        $amount = is_scalar($rawAmount) && is_numeric(trim((string) $rawAmount))
            ? (float) $rawAmount
            : NAN;
        $paymentDate = $_POST['payment_date'] ?? '';
        $paymentMethod = $_POST['payment_method'] ?? '';
        $rawNotes = $_POST['notes'] ?? '';

        if ($creditor_id <= 0) {
            $error = 'Please select a valid creditor.';
        } elseif (!is_finite($amount) || $amount <= 0 || $amount > 9999999999.99
            || abs($amount - round($amount, 2)) > 0.00001) {
            $error = 'Enter a valid positive collection amount with no more than two decimal places.';
        } elseif (!is_scalar($paymentDate)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $paymentDate)
            || !checkdate((int) substr((string) $paymentDate, 5, 2), (int) substr((string) $paymentDate, 8, 2), (int) substr((string) $paymentDate, 0, 4))) {
            $error = 'Enter a valid payment date.';
        } elseif (!is_string($paymentMethod) || !in_array($paymentMethod, ['cash', 'transfer', 'other'], true)) {
            $error = 'Select a valid payment method.';
        } elseif (!is_scalar($rawNotes) || strlen(trim((string) $rawNotes)) > 1000) {
            $error = 'Payment reference or notes are invalid.';
        } else {
            $notes = trim((string) $rawNotes);
            if (($paymentMethod === 'transfer' || $paymentMethod === 'other') && $notes === '') {
                $error = 'A payment reference or note is required for this method.';
            }
        }

        if ($error === '') {
            try {
                $pdo = getDBConnection();
                $pdo->beginTransaction();

                $creditorStmt = $pdo->prepare(
                    'SELECT id, creditor_name, credit_amount, amount_paid, balance, status
                     FROM creditors WHERE id = ? FOR UPDATE'
                );
                $creditorStmt->execute([$creditor_id]);
                $creditor = $creditorStmt->fetch(PDO::FETCH_ASSOC);
                if (!$creditor) {
                    throw new InvalidArgumentException('The selected creditor is unavailable.');
                }

                $currentBalance = (float) $creditor['balance'];
                $currentPaid = (float) $creditor['amount_paid'];
                if (!is_numeric($creditor['balance']) || !is_numeric($creditor['amount_paid'])
                    || !is_finite($currentBalance) || !is_finite($currentPaid)
                    || $currentBalance <= 0 || $currentPaid < 0) {
                    throw new InvalidArgumentException('This creditor has no balance available for collection.');
                }
                if ($amount > round($currentBalance, 2)) {
                    throw new InvalidArgumentException('The collection amount exceeds the current creditor balance.');
                }

                $newPaid = round($currentPaid + $amount, 2);
                $newBalance = round($currentBalance - $amount, 2);
                if ($newBalance < 0 && $newBalance > -0.009) {
                    $newBalance = 0.0;
                }
                if ($newBalance < 0) {
                    throw new InvalidArgumentException('The collection amount exceeds the current creditor balance.');
                }
                $newStatus = $newBalance === 0.0 ? 'cleared' : 'active';

                $remittanceStmt = $pdo->prepare(
                    'INSERT INTO creditor_remittances
                     (creditor_id, creditor_name, amount, payment_date, payment_method, notes, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$remittanceStmt->execute([
                    $creditor_id,
                    $creditor['creditor_name'],
                    $amount,
                    $paymentDate,
                    $paymentMethod,
                    $notes,
                    (int) $_SESSION['user_id']
                ])) {
                    throw new RuntimeException('Remittance insert failed.');
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE creditors
                     SET amount_paid = ?, balance = ?, status = ?, cleared_at = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                if (!$updateStmt->execute([
                    $newPaid,
                    $newBalance,
                    $newStatus,
                    $newStatus === 'cleared' ? date('Y-m-d H:i:s') : null,
                    $creditor_id
                ]) || $updateStmt->rowCount() !== 1) {
                    throw new RuntimeException('Creditor balance update failed.');
                }

                $pdo->commit();
                $_SESSION['creditor_remit_success'] = true;
                header('Location: remit.php?creditor_id=' . $creditor_id, true, 303);
                exit();
            } catch (Throwable $e) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (Throwable $rollbackError) {
                        error_log('Creditor remittance rollback failed (' . get_class($rollbackError) . '): ' . $rollbackError->getMessage());
                    }
                }
                if ($e instanceof InvalidArgumentException) {
                    $error = $e->getMessage();
                } else {
                    error_log('Creditor remittance failed (' . get_class($e) . '): ' . $e->getMessage());
                    $error = 'The remittance could not be recorded. No changes were saved.';
                }
            }
        }
    }
}

// Get active creditors
$creditors = [];
try {
    $stmt = executeQuery("SELECT id, creditor_name, balance FROM creditors WHERE status = 'active' ORDER BY creditor_name");
    $creditors = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Failed to load creditors';
}

// Get selected creditor details
$selected_creditor = null;
if ($creditor_id) {
    try {
        $stmt = executeQuery("SELECT * FROM creditors WHERE id = ? AND status = 'active'", [$creditor_id]);
        $selected_creditor = $stmt->fetch();
    } catch (PDOException $e) {
        $error = 'Failed to load creditor details';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payment - Merrylay Enterprises</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/clean-dashboard.css" rel="stylesheet">
    <style>
        .form-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: var(--space-8);
            box-shadow: var(--shadow-md);
        }
        .form-label {
            font-weight: 500;
            color: var(--primary-700);
            margin-bottom: var(--space-2);
        }
        .form-control, .form-select {
            border: 1px solid var(--border-medium);
            border-radius: var(--radius-lg);
            padding: var(--space-3) var(--space-4);
            font-size: 0.875rem;
            transition: all var(--transition-fast);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgb(59 130 246 / 0.1);
            outline: none;
        }
        .input-group-text {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-medium);
            color: var(--primary-700);
        }
        .btn-submit {
            background-color: var(--blue);
            border-color: var(--blue);
            color: white;
        }
        .btn-submit:hover {
            background-color: var(--blue-600);
            border-color: var(--blue-600);
            color: white;
        }
        .creditor-info {
            background: linear-gradient(135deg, rgb(139 92 246 / 0.05) 0%, rgb(139 92 246 / 0.02) 100%);
            border: 1px solid rgb(139 92 246 / 0.2);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            margin-bottom: var(--space-4);
        }
        .balance-box {
            background: linear-gradient(135deg, rgb(239 68 68 / 0.05) 0%, rgb(239 68 68 / 0.02) 100%);
            border: 1px solid rgb(239 68 68 / 0.2);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            text-align: center;
        }
        .balance-amount {
            color: var(--red);
            font-size: 1.5rem;
            font-weight: 700;
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

        <main class="main-content" id="mainContent">
            <div class="dashboard-content">
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <h1><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h1>
                    <p>Record remittance from creditor</p>
                </div>

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <?php if ($error): ?>
                            <div class="alert alert-danger" style="background-color: rgb(239 68 68 / 0.1); border: 1px solid rgb(239 68 68 / 0.2); color: var(--red);"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success" style="background-color: rgb(16 185 129 / 0.1); border: 1px solid rgb(16 185 129 / 0.2); color: var(--green);"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <div class="form-card">
                            <form method="POST">
                                <input type="hidden" name="record_payment" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityUtils::generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label" for="creditor_id">Select Creditor *</label>
                                    <select name="creditor_id" id="creditor_id" class="form-select" required onchange="window.location.href='?creditor_id='+this.value">
                                        <option value="">Choose Creditor</option>
                                        <?php foreach ($creditors as $c): ?>
                                            <option value="<?php echo $c['id']; ?>" <?php echo $creditor_id == $c['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['creditor_name']); ?> 
                                                (Balance: ₦<?php echo number_format($c['balance'], 2); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <?php if ($selected_creditor): ?>
                                    <div class="creditor-info">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong><?php echo htmlspecialchars($selected_creditor['creditor_name']); ?></strong>
                                                <div class="text-muted small">
                                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($selected_creditor['driver_name']); ?><br>
                                                    <i class="fas fa-calendar me-1"></i>Credited: <?php echo date('M j, Y', strtotime($selected_creditor['credit_date'])); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="balance-box">
                                                    <small class="text-muted">Balance Due</small>
                                                    <div class="balance-amount">₦<?php echo number_format($selected_creditor['balance'], 2); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Payment Amount *</label>
                                            <div class="input-group">
                                                <span class="input-group-text">₦</span>
                                                <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="<?php echo $selected_creditor['balance']; ?>" required>
                                            </div>
                                            <small class="text-muted">Max: ₦<?php echo number_format($selected_creditor['balance'], 2); ?></small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Payment Date *</label>
                                            <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                            <label class="form-label" for="payment_method">Payment Method *</label>
                                            <select name="payment_method" id="payment_method" class="form-select" required>
                                            <option value="cash">Cash</option>
                                            <option value="transfer">Bank Transfer</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label" for="payment_notes">Reference / Notes <span id="paymentNotesRequired" class="text-danger" hidden>*</span></label>
                                        <textarea name="notes" id="payment_notes" class="form-control" rows="2" maxlength="1000" placeholder="Reference or note"></textarea>
                                        <div id="paymentNotesPrompt" class="form-text" aria-live="polite">Optional for cash payments.</div>
                                    </div>

                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" class="btn btn-submit">
                                            <i class="fas fa-save me-2"></i>Record Payment
                                        </button>
                                        <a href="by-driver.php" class="btn">View All Credits</a>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-hand-holding-usd"></i>
                                        </div>
                                        <h6>Select a creditor</h6>
                                        <p>Select a creditor to record payment</p>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="../../assets/js/clean-dashboard.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const paymentForm = document.querySelector('.form-card form');
            const paymentMethod = document.getElementById('payment_method');
            const paymentNotes = document.getElementById('payment_notes');
            const notesRequired = document.getElementById('paymentNotesRequired');
            const notesPrompt = document.getElementById('paymentNotesPrompt');

            if (!paymentForm || !paymentMethod || !paymentNotes || !notesRequired || !notesPrompt) {
                return;
            }

            const updateNotesRequirement = () => {
                const required = paymentMethod.value === 'transfer' || paymentMethod.value === 'other';
                paymentNotes.required = required;
                notesRequired.hidden = !required;
                notesPrompt.classList.remove('text-danger');
                notesPrompt.textContent = required
                    ? 'A reference or note is required for bank transfers and other payment methods.'
                    : 'Optional for cash payments.';
            };

            paymentMethod.addEventListener('change', updateNotesRequirement);
            paymentNotes.addEventListener('input', () => {
                if (paymentNotes.value.trim()) {
                    updateNotesRequirement();
                }
            });
            paymentNotes.addEventListener('invalid', () => {
                notesPrompt.textContent = 'Enter a reference or note before recording this payment.';
                notesPrompt.classList.add('text-danger');
            });
            paymentForm.addEventListener('submit', (event) => {
                const required = paymentMethod.value === 'transfer' || paymentMethod.value === 'other';
                if (required && !paymentNotes.value.trim()) {
                    event.preventDefault();
                    notesPrompt.textContent = 'Enter a reference or note before recording this payment.';
                    notesPrompt.classList.add('text-danger');
                    paymentNotes.focus();
                }
            });

            updateNotesRequirement();
        });
    </script>
</body>
</html>

