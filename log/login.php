<?php
// log/login.php — no init.php here, to avoid redirect loop
session_start();

// If already logged in, go to the app home
if (!empty($_SESSION['username'])) {
    header('Location: /biosound/attivitae.php');
    exit;
}

// DB connection
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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u === '' || $p === '') {
        $error = 'Inserisci username e password.';
    } else {
        // Recupera l’hash della password dal database
        $stmt = $pdo->prepare('SELECT username, password, role FROM user WHERE username = ?');
        $stmt->execute([$u]);
        $user = $stmt->fetch();

        // Verifica la password con password_verify()
        if ($user && password_verify($p, $user['password'])) {
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            session_regenerate_id(true);
            header('Location: /biosound/attivitae.php');
            exit;
        }
        $error = 'Credenziali non valide.';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login – Biosound</title>
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* Full‐screen blue gradient */
    body {
      margin: 0;
      padding: 0;
      background: linear-gradient(135deg, #0062E6, #33AEFF);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Segoe UI', sans-serif;
    }
    /* Card container */
    .login-box {
      background: #fff;
      border-radius: 1rem;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      padding: 2rem;
      width: 100%;
      max-width: 380px;
      text-align: center;
    }
    /* Logo */
    .login-box img.logo {
      height: 80px;
      margin-bottom: 1rem;
    }
    /* Heading */
    .login-box h3 {
      margin-bottom: 1.5rem;
      color: #333;
    }
    /* Rounded inputs */
    .login-box .form-control {
      border-radius: 50px;
      padding: 0.75rem 1.25rem;
    }
    /* Rounded button */
    .login-box .btn-primary {
      border-radius: 50px;
      padding: 0.75rem;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <div class="login-box">
    <img src="/biosound/logo.png" alt="Logo Biosound" class="logo">
    <h3>Accedi a Biosound</h3>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" novalidate>
      <div class="mb-3">
        <input
          type="text"
          name="username"
          class="form-control"
          placeholder="Username"
          value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES) ?>"
          required
        >
      </div>
      <div class="mb-4">
        <input
          type="password"
          name="password"
          class="form-control"
          placeholder="Password"
          required
        >
      </div>
      <button type="submit" class="btn btn-primary w-100">Accedi</button>
    </form>
  </div>

  <!-- Bootstrap JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
