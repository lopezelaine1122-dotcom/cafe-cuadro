# Deploy checklist (Apache / cPanel / Hostinger / any PHP 8+ host)

1. Upload the contents of this folder (e.g. to `public_html/` or `public_html/unified/`). No config edit for the URL is needed - BASE_URL is auto-detected.
2. In the host's phpMyAdmin: create a database + user, then **Import** `database/unified_cafe_system.sql` (it has no CREATE DATABASE / USE line, so it works with prefixed DB names).
3. Copy `config/db.local.example.php` to `config/db.local.php` and fill in host, name, user, password. (Or set DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables.)
4. Make sure `uploads/` is writable by PHP (chmod 755, or 775 if uploads fail).
5. Optional mail: set HRMS_MAIL_ENABLED=1, HRMS_SMTP_USERNAME, HRMS_SMTP_PASSWORD (see config/mail.php).
6. Log in and CHANGE the demo passwords (README section 1) and the placeholder business address/TIN in config/constants.php.

Requirements: PHP 8.0+ with pdo_mysql, mbstring, gd/fileinfo; Apache with mod_rewrite and AllowOverride All (needed for .htaccess clean URLs).
Debugging a 500/blank page: set APP_DEBUG=1 temporarily, or read the host's PHP error log. Remove it afterwards.
