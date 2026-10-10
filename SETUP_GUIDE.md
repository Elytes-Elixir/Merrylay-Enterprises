# Merrylay System - Fresh Setup Guide

This guide creates a fresh application installation. The schema and reference seed create no users, passwords, products, stock, or other business records.

## Fresh-install order

Do not import historical or live database dumps, or run repair, reset, migration, or maintenance scripts as part of a fresh installation.

1. **Copy the project.** Copy or clone the project files to the target computer.
2. **Configure the local environment.** Copy `.env.example` to `.env` or configure the ignored `config/local.php`. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` for the new installation. Use a database name other than the protected `merrylay_system`.
3. **Create an empty database.** In phpMyAdmin, create the database named by `DB_NAME` using `utf8mb4`.
4. **Import `database/schema.sql`.** Select the empty database and import the approved schema file.
5. **Import `database/reference_seed.sql`.** Import it into the same database. It contains permission definitions only; it does not contain business records.
6. **Create the administrator.** From the project directory, run `php scripts/create_admin.php --create-admin` in a terminal. Follow the prompts. The command creates only that administrator and requires a strong password.
7. **Log in and create other users.** Open the local application URL, log in as the administrator, configure organization settings, and create other user accounts through user management.
8. **Initialize stock through the approved stock initialization workflow.** Do not add stock or products through this bootstrap process.

The schema and reference seed contain no users, passwords, products, drivers, vehicles, customers, sales, dispatches, creditors, expenses, stock records, or historical business data. The reference seed is safe to rerun: it upserts the canonical permission definitions. It does not seed role grants, categories, or configurable system settings.

## XAMPP notes

- Start Apache and MySQL from the XAMPP Control Panel.
- The usual local application URL is `http://localhost/merrylay_system`.
- Configure the database credentials before importing or running the administrator command.
- `scripts/create_admin.php` is CLI-only and rejects the protected database name `merrylay_system`.

## Troubleshooting

### Database connection error

1. Verify MySQL is running.
2. Verify `DB_NAME` names the newly created installation database.
3. Check `.env` or `config/local.php` for the database host, port, user, and password.

### Missing tables after import

1. Confirm `database/schema.sql` was imported into the intended empty database.
2. Review the import tool's errors and warnings before continuing.
3. Import `database/reference_seed.sql` only after the schema import succeeds.

### Administrator command failure

1. Run the command from the project directory in an interactive terminal.
2. Confirm `DB_NAME` is explicitly set to the new database and is not `merrylay_system`.
3. Check the PHP error log for technical details; the command does not log credentials.
