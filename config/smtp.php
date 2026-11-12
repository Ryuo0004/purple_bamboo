<?php
// config/smtp.php — SMTP Credentials File
// SECURITY: Keep this file OUT of version control.
// Add to .gitignore:  config/smtp.php
//
// To change credentials: edit only this file.

define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',       587);
define('SMTP_USERNAME',   'khunaguero355@gmail.com');   // Gmail address
define('SMTP_PASSWORD',   'djyuwxeyayezztsw');               // Gmail App Password
define('SMTP_FROM_EMAIL', 'khunaguero355@gmail.com');
define('SMTP_FROM_NAME',  'Purple Bamboo Resort');
define('SMTP_ENCRYPTION', 'tls');   // 'tls' = STARTTLS on port 587
