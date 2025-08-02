<?php
// init.php — include **prima** di qualunque output in tutte le pagine protette

session_start();

// 1) Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), fullscreen=(self)');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

// 2) PDO connection
define('DB_HOST', 'localhost');
define('DB_NAME', 'biosound');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
  http_response_code(500);
  exit('Errore di connessione al database.');
}

// 3) Redirect to login if not authenticated (and not already on login page)
$current = $_SERVER['REQUEST_URI'];
if (empty($_SESSION['username'])
    && strpos($current, '/biosound/log/login.php') === false
    && strpos($current, '/biosound/log/logout.php') === false
) {
    header('Location: /biosound/log/login.php');
    exit;
}
