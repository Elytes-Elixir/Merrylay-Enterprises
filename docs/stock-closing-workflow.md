# End-of-Day Stock Closing

The active explicit close handler is `admin/end-of-day.php`, using
`StockManager::closeDailyStock()`.

The handler is authenticated and permission-checked, requires an explicit POST
submission with a valid CSRF token and current closing date, and keeps GET/report
views read-only. The manager locks existing `daily_stock` rows for the closing
and next day, calculates the canonical close, writes `daily_stock`, initializes
the next-day rows, and commits those writes as one transaction. Any failed write
or readback rolls the transaction back. It does not update `products.quantity`,
insert stock movements, or create shortage creditors.

The current schema has no permanent closed-status, close-audit, or idempotency
field. It only has the unique `(product_id, stock_date)` key, which prevents
duplicate daily-stock rows. It cannot distinguish an initialized row from an
already closed row, so repeat-close detection beyond the existing uniqueness
constraint remains a schema limitation. No schema change was made.