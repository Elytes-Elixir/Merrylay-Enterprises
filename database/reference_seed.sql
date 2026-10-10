-- Non-sensitive permission definitions required by permission management.
-- Idempotent: rerunning this seed upserts the canonical definition for each key.
-- No users, role grants, system settings, categories, or business records are seeded.
INSERT INTO `permissions` (`permission_key`, `permission_name`, `description`, `category`) VALUES
('dispatch_view', 'View Dispatches', 'View dispatch records.', 'dispatch'),
('dispatch_create', 'Create Dispatches', 'Create dispatch records.', 'dispatch'),
('dispatch_edit', 'Edit Dispatches', 'Edit dispatch records.', 'dispatch'),
('dispatch_delete', 'Delete Dispatches', 'Delete dispatch records.', 'dispatch'),
('dispatch_view_all', 'View All Dispatches', 'View dispatches across users.', 'dispatch'),
('sales_view', 'View Sales', 'View sales records.', 'sales'),
('sales_create', 'Create Sales', 'Create sales records.', 'sales'),
('sales_edit', 'Edit Sales', 'Edit sales records.', 'sales'),
('sales_delete', 'Delete Sales', 'Delete sales records.', 'sales'),
('finance_view', 'View Finance', 'View financial records.', 'finance'),
('finance_create', 'Create Finance Records', 'Create financial records.', 'finance'),
('finance_edit', 'Edit Finance Records', 'Edit financial records.', 'finance'),
('finance_delete', 'Delete Finance Records', 'Delete financial records.', 'finance'),
('bank_deposits', 'Manage Bank Deposits', 'Manage bank deposit records.', 'finance'),
('products_view', 'View Products', 'View product records.', 'products'),
('products_create', 'Create Products', 'Create product records.', 'products'),
('products_edit', 'Edit Products', 'Edit product records.', 'products'),
('products_delete', 'Delete Products', 'Delete product records.', 'products'),
('stock_view', 'View Stock', 'View stock records.', 'stock'),
('stock_manage', 'Manage Stock', 'Manage stock records.', 'stock'),
('users_view', 'View Users', 'View user accounts.', 'users'),
('users_create', 'Create Users', 'Create user accounts.', 'users'),
('users_edit', 'Edit Users', 'Edit user accounts.', 'users'),
('users_delete', 'Delete Users', 'Delete user accounts.', 'users'),
('users_manage_permissions', 'Manage User Permissions', 'Grant or revoke individual user permissions.', 'users'),
('reports_view', 'View Reports', 'View reports.', 'reports'),
('reports_generate', 'Generate Reports', 'Generate reports.', 'reports'),
('reports_export', 'Export Reports', 'Export reports.', 'reports'),
('system_settings', 'Manage System Settings', 'Manage application settings.', 'system'),
('system_backup', 'Manage Backups', 'Manage system backups.', 'system'),
('system_audit', 'View System Audit', 'View system audit information.', 'system')
ON DUPLICATE KEY UPDATE
    `permission_name` = VALUES(`permission_name`),
    `description` = VALUES(`description`),
    `category` = VALUES(`category`);
