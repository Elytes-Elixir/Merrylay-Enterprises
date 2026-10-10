# Dispatch Shortage Reconciliation

## Current behavior

Report calculations remain unchanged and are display-only. Report GETs do not create, update, clear, or settle shortage creditors.

- `dispatch/daily/account-summary.php` displays a whole-day monetary variance as `total_accounted_for - net_dispatch_value`. Its per-trip monetary variance is `cash + transfer + return value + credit settlement + discounts + damaged value + expenses + credit sales - expected amount`. The removed report-load sync created a trip shortage creditor from the negative value, updated its amount and balance, or cleared it when the variance was no longer negative. Credit sales were included in that persisted variance, so it was not a safe shortage algorithm.
- `dispatch/daily-accounting.php` had two report-load writers. The selected-dispatcher path calculated `(expected - expenses) - (deposits + outstanding amount)` and inserted a creditor for a positive shortage. The all-driver path calculated `cash + transfer + credit sales + expenses + discounts + damaged value + credit settlements - expected`, omitted returns, and inserted creditors for negative values. Both paths have been removed; the existing selected-dispatcher variance and banner remain visible.
- `dispatch/dispatch_balance_report.php` displays each dispatch's `adjusted actual - adjusted expected`, where adjusted actual is actual sales less returns plus credit settlements and adjusted expected is expected less returns. The return terms cancel in the displayed net balance. This page had no shortage-credit writes.
- `admin/end-of-day.php` displays a company-wide monetary variance and dispatcher rows using accounted amounts versus net expected less outstanding credit. It had no shortage-credit writes. Its separate explicit stock-close POST is not a shortage reconciliation and was not changed; GET/report rendering does not invoke it.
- `dispatch/daily/record.php` had two activity-submit paths that calculated a per-trip monetary variance from cash, transfer, returns, settlements, discounts, damage value, expenses, and credit sales. Each path automatically created, updated, or cleared dispatcher-shortage creditor rows. Those implicit shortage sync blocks have been removed. The surrounding activity, sales, payment, return, and damage handling remains unchanged.
- One-off shortage-specific scripts remain under `scripts/migrations/`: `add_missing_may27_shortages.php` and `add_tope_500_shortage.php` can insert/update shortage creditors; `remove_shop_sales_may27_credit.php` and `remove_tope_may27_credit.php` can delete selected historical shortage creditors. They are not report handlers, were not run, and were not modified in this phase. Other migration scripts also update/delete generic creditor rows but are not shortage-specific.

## Quantity reconciliation rule

Shortage quantity must be calculated per dispatch item, independently of money:

`shortage quantity = dispatched quantity - sold quantity - good returned quantity - damaged quantity`

Equivalently:

`dispatched = sold + good returned + damaged + shortage quantity`

Do not add credit sales, credit settlements, expenses, or returned goods to the shortage quantity. Good returned and damaged quantities are separate equation terms, not shortages. Monetary shortage value, if later needed, is a separate calculation from shortage quantity and the applicable unit price; it must not replace or be mixed into the unit equation.

## Schema limitation

The current schema has no dedicated shortage/reconciliation table or idempotency/audit record. `dispatch_items.quantity_sold` stores sold quantity; `daily_returns` stores return quantity without a condition; `dispatch_returns` has a condition field; and `daily_activities.damaged_products` stores a monetary value rather than a damage quantity. The return paths also update sales quantities differently. Combining these sources into one persisted reconciliation without first defining a canonical, non-duplicating quantity source could overstate or understate shortage quantity.

No schema change or historical-creditor correction was made. Until a safe reconciliation table and canonical quantity source are approved, reports show their existing calculated monetary variances only; no shortage creditor is silently persisted. There is no explicit shortage reconciliation action in the inspected pages.
