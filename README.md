# NSTP Portal

A PHP-based NSTP portal and QR code attendance system for managing students, facilitators, sections, attendance records, registrations, grades, and system maintenance tasks.

## Features

- QR code attendance scanning and validation
- Student registration and public registration forms
- Admin and facilitator account management
- Section assignment and student-facilitator assignment
- Masterlist management
- Attendance archiving and downloads
- Excel import/export support
- Grade management
- Student dashboard and profile management
- Password reset email support
- System logs and maintenance tools
- Theme and landing page content management

## Requirements

- XAMPP or equivalent local PHP server
- PHP 8.2 or compatible version
- MariaDB/MySQL
- Composer
- Web browser

## Dependencies

This project uses Composer packages:

- `phpoffice/phpspreadsheet` for Excel import/export
- `phpmailer/phpmailer` for email sending

Install dependencies with:

```bash
composer install
```

If Composer is not installed globally, you can use the included Composer PHAR:

```bash
php composer.phar install
```

## Installation

1. Place the project folder inside your XAMPP `htdocs` directory.

   Example:

   ```text
   C:\xampp\htdocs\qr-code-attendance-system-main
   ```

2. Start Apache and MySQL from the XAMPP Control Panel.

3. Create a database in phpMyAdmin named:

   ```text
   qr_attendance_db
   ```

4. Import the database file:

   ```text
   db/qr_attendance_db.sql
   ```

5. Check the database connection settings in:

   ```text
   conn/conn.php
   ```

   Default local settings:

   ```php
   $host = 'localhost';
   $dbname = 'qr_attendance_db';
   $username = 'root';
   $password = '';
   ```

6. Install Composer dependencies:

   ```bash
   composer install
   ```

7. Open the system in your browser:

   ```text
   http://localhost/qr-code-attendance-system-main/
   ```

## Email Configuration

Password reset and email-related features use the mail settings in:

```text
config/mail.php
```

Update the SMTP host, email address, app password, port, encryption, and sender name based on your own email account.

For Gmail, use an app password instead of your normal Gmail password.

## Main Pages

- `index.php` - main portal page
- `login.php` - user login
- `register.php` - user registration
- `attendance.php` - attendance management
- `masterlist.php` - student masterlist
- `grades.php` - grade management
- `student-dashboard.php` - student dashboard
- `admin-management.php` - admin management
- `student-registrations.php` - registration management
- `system-maintenance.php` - maintenance tools

## Project Structure

```text
config/      Mail configuration
conn/        Database connection
db/          Database SQL dump
endpoint/    Backend request handlers
include/     Shared PHP includes, styles, logos, and helpers
uploads/     Uploaded profile/formal pictures
vendor/      Composer dependencies
```

## Uploading Updates to GitHub

After making changes, push updates with:

```bash
git status
git add .
git commit -m "Describe your changes"
git push
```

For the first upload to GitHub:

```bash
git init
git branch -M main
git remote add origin https://github.com/LyleZhyla/NSTP_Portal.git
git add .
git commit -m "Initial upload"
git push -u origin main
```

## Notes

- Do not upload real passwords, app passwords, or private credentials to a public repository.
- The `vendor/` folder can be regenerated using `composer install`.
- Uploaded user images inside `uploads/` may contain personal data. Review them before pushing to GitHub.
