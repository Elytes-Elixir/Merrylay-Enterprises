# Merrylay desktop launcher (Windows)

The launcher starts the Apache and MySQL instances from XAMPP only when those
instances are not already running, then opens Merrylay's login page. It does
not install XAMPP or PHP, change the Windows firewall, or initialize or modify
the database.

## Requirements

- Windows PowerShell and a working XAMPP installation.
- The Merrylay project located beneath that XAMPP installation's `htdocs`
  folder to use the default application URL.

The XAMPP root defaults to `C:\xampp`. Configure a different location with the
`MERRYLAY_XAMPP_ROOT` environment variable, or pass it to the PowerShell
launcher with `-XamppRoot`. The application URL defaults to
`http://localhost/<project-folder>/auth/login.php` and can be changed with
`MERRYLAY_APP_URL` or the `-ApplicationUrl` parameter. For example:

```powershell
$env:MERRYLAY_XAMPP_ROOT = 'D:\tools\xampp'
$env:MERRYLAY_APP_URL = 'http://localhost/merrylay/auth/login.php'
.\Merrylay-Start.bat
```

You can also launch directly:

```powershell
.\Merrylay-Start.ps1 -XamppRoot 'D:\tools\xampp' `
  -ApplicationUrl 'http://localhost/merrylay/auth/login.php'
```

The launcher verifies the Apache/MySQL executables and XAMPP start scripts
before starting either service. It records the process IDs it starts in the
current user's Local AppData folder. `Merrylay-Stop.ps1` uses that record and
checks the process executable before requesting shutdown. If Apache rejects a
graceful shutdown, it stops only the verified process tree started by the
launcher. Services that were already running are not recorded or stopped. Use
the same XAMPP root setting when stopping that you used when starting.

## First-time setup

1. Install and configure XAMPP, and place Merrylay under its `htdocs` folder.
2. Create a new database and import `database/schema.sql`.
3. Import `database/reference_seed.sql` into that database.
4. Copy `.env.example` to `.env` and configure the database connection and
   application settings. Keep `.env` private.
5. From the project root, run
   `php scripts/create_admin.php --create-admin` in an interactive terminal
   and follow its prompts to create the first administrator.
6. Start the launcher and sign in using the administrator account you created.

The launcher does not run SQL, create or reset databases, create users, or
create the administrator.

## LAN access

To make Merrylay available to other devices on your local network, configure
Apache to listen on the appropriate network interface, determine the host
computer's LAN IP address, and set `MERRYLAY_APP_URL` to a URL reachable by
those devices (for example `http://192.168.1.20/merrylay/auth/login.php`).
Configure Windows Firewall manually according to your network's security
requirements. The launcher does not modify firewall rules. Do not expose the
development XAMPP setup directly to the public internet.
