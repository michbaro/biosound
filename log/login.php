<?php
// /var/www/formazione/biosound/log/login.php

// 1) Include init.php (sessione, security headers, PDO e redirect logic)
require_once __DIR__ . '/../init.php';

// 2) Se sei già loggato, vai alla home
if (!empty($_SESSION['username'])) {
    header('Location: /attivitae.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';

    if ($u === '' || $p === '') {
        $error = 'Inserisci username e password.';
    } else {
        $stmt = $pdo->prepare('SELECT username, password, role FROM user WHERE username = ?');
        $stmt->execute([$u]);
        $user = $stmt->fetch();

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
      <div class="alert alert-danger py-2">
        <?= htmlspecialchars($error, ENT_QUOTES) ?>
      </div>
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