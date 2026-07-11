## Live Website
https://aichecker.ifree.page
> The online payment function uses the ToyyibPay development environment. Some functions may require an internet connection.

## About the System
AI Checker Assignment is a web-based platform developed to help users submit academic assignments, receive AI and similarity analysis results, manage payments, and verify generated certificates. The system provides separate modules for users and administrators. Users can register, log in, subscribe to a plan, upload assignments, view results, download reports, and submit feedback. Administrators can manage users, assignments, reviews, plans, vouchers, payments, testimonials, and contact messages.


## Test Accounts
### Administrator Account
Email: eda99@gmail.com  
Password: eda99_

### User Account
Email: sakinahcomel@gmail.com  
Password: sakinah12_


## Main Features
### User Module
- User registration, login, and logout
- User profile management
- Subscription plan selection
- Voucher application
- ToyyibPay payment processing
- Assignment upload
- Assignment submission history
- AI and plagiarism analysis results
- Report and receipt viewing
- Certificate generation and verification
- Testimonials and contact form
- User notifications
- Dark mode
- AI chatbot assistance

### Administrator Module
- Administrator login and dashboard
- User management
- Assignment management
- Review management
- AI analysis management
- Subscription plan management
- Voucher management
- Payment monitoring
- Testimonial management
- Contact message management
- Report generation
- Administrator notifications
- Search, filter, print, PDF, and Excel functions

## Technologies Used
- **Server-side language:** PHP 8.1 or above
- **Database:** MySQL / MariaDB
- **Local server:** XAMPP
- **Database tool:** phpMyAdmin
- **Front end:** HTML5, CSS3, JavaScript
- **CSS framework:** Bootstrap 5
- **Libraries:** Font Awesome, Chart.js, SweetAlert2, and AOS
- **PDF library:** Dompdf
- **Payment gateway:** ToyyibPay Development / Sandbox
- **Recommended browser:** Google Chrome

## System Requirements

### Hardware
- Computer or laptopng
- Minimum 4 GB RAM
- At least 1 GB of available storage
- Internet connection for CDN libraries, QR generation, avatars, and ToyyibPay

### Software
- Windows 10 or Windows 11
- XAMPP with PHP 8.1 or above
- MySQL or MariaDB
- Google Chrome
- Visual Studio Code or another code editor

## Academic Use
This project was developed for academic and learning purposes. It is not intended to replace professional plagiarism detection or academic integrity services. Analysis results should be reviewed carefully and should not be used as the only basis for academic decisions.


## NOTES
The .htaccess file used in the website contains Apache server configuration. It is optional for understanding the project source code and may not be included in the repository.

### INCLUDE
.htaccess
# ══════════════════════════════════════════════════════════════
# Raise PHP upload limits so the Premium plan's 100MB file uploads
# are not silently rejected by the server's default (often 2-8MB)
# before assignments.php even gets to run its own size checks.
#
# NOTE: This only works when PHP runs as an Apache module
# (mod_php / XAMPP). If your server uses PHP-FPM or these
# directives are not permitted (AllowOverride Off), set the
# same values in php.ini instead:
#   upload_max_filesize = 100M
#   post_max_size       = 105M
#   max_execution_time  = 300
#   memory_limit         = 256M
# ══════════════════════════════════════════════════════════════
<IfModule mod_php.c>
    php_value upload_max_filesize 100M
    php_value post_max_size 105M
    php_value max_execution_time 300
    php_value max_input_time 300
    php_value memory_limit 256M
</IfModule>

<IfModule mod_php7.c>
    php_value upload_max_filesize 100M
    php_value post_max_size 105M
    php_value max_execution_time 300
    php_value max_input_time 300
    php_value memory_limit 256M
</IfModule>

<IfModule mod_php8.c>
    php_value upload_max_filesize 100M
    php_value post_max_size 105M
    php_value max_execution_time 300
    php_value max_input_time 300
    php_value memory_limit 256M
</IfModule>

