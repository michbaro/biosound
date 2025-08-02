<?php
// aggiungi_operatore.php — form per aggiungere un nuovo operatore
include 'init.php';

// 1) Controllo ruolo: solo admin e dev possono accedere
if (($_SESSION['role'] ?? 'utente') === 'utente') {
    header('Location: /biosound/index.php?unauthorized=1');
    exit;
}

$added          = false;
$errorDuplicate = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2) Raccogli e pulisci dati
    $nome     = trim($_POST['nome']    ?? '');
    $cognome  = trim($_POST['cognome'] ?? '');

    if ($nome === '' || $cognome === '') {
        $error = 'Inserisci nome e cognome.';
    } else {
        // 3) Verifica duplicato (stesso nome e cognome)
        $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM operatore WHERE nome = ? AND cognome = ?');
        $dupStmt->execute([$nome, $cognome]);
        if ($dupStmt->fetchColumn() > 0) {
            $errorDuplicate = true;
        } else {
            // 4) Inserisci nuovo operatore
            $id = bin2hex(random_bytes(16));
            $insert = $pdo->prepare('INSERT INTO operatore (id, nome, cognome) VALUES (?, ?, ?)');
            $insert->execute([$id, $nome, $cognome]);

            $added = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Aggiungi Operatore</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg: #f0f2f5; --fg: #2e3a45; --radius: 8px;
      --shadow: rgba(0,0,0,0.08); --font: 'Segoe UI',sans-serif;
      --pri: #66bb6a; --err: #d9534f;
      --green-soft: #e8f5e9; --green-dark: #2e7d32;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: var(--bg); color: var(--fg); font-family: var(--font); }
    .container { max-width: 700px; margin: 2rem auto; padding: 0 1rem; }
    h1 { text-align: center; margin-bottom: 1rem; }

    .alert-success, .alert-danger {
      padding: .75rem 1rem;
      border-radius: var(--radius);
      margin-bottom: 1rem;
      box-shadow: 0 2px 6px var(--shadow);
      text-align: center;
    }
    .alert-success { background: #d4edda; color: #155724; }
    .alert-danger  { background: #f8d7da; color: #721c24; }

    form { display: flex; flex-direction: column; gap: 1.5rem; }
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    .form-group { display: flex; flex-direction: column; }
    label { margin-bottom: .5rem; font-weight: 500; }
    input[type="text"] {
      padding: .5rem .75rem;
      border: 1px solid #ccc;
      border-radius: var(--radius);
      font-size: 1rem;
    }

    .actions {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 3rem;
      margin-top: 2rem;
    }
    .btn {
      padding: .6rem 1.2rem;
      border: none;
      border-radius: var(--radius);
      cursor: pointer;
      color: #fff;
      font-size: 1rem;
      font-weight: bold;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      min-height: 2.75rem;
      gap: .75rem;
      transition: transform .15s, background .2s;
    }
    .btn-secondary {
      background: #6c757d;
    }
    .btn-secondary:hover {
      background: #5a6268;
      transform: translateY(-2px);
    }
    .btn-primary {
      background: var(--pri);
    }
    .btn-primary:hover {
      background: #5aad5c;
      transform: translateY(-2px);
    }
    .btn-primary:active {
      background: #4b8950;
    }
  </style>
</head>
<body>
<?php
  // navbar identica ad aggiungi_docente.php
  $role = $_SESSION['role'] ?? 'utente';
  switch ($role) {
    case 'admin':
      include 'navbar_a.php';
      break;
    case 'dev':
      include 'navbar_d.php';
      break;
    default:
      include 'navbar.php';
  }
?>
  <div class="container">
    <h1>Aggiungi Operatore</h1>

    <?php if ($added): ?>
      <div class="alert-success">Operatore aggiunto con successo!</div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="alert-danger"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <?php if ($errorDuplicate): ?>
      <div class="alert-danger">Operatore già presente.</div>
    <?php endif; ?>

    <form method="post" action="aggiungi_operatore.php">
      <div class="form-grid">
        <div class="form-group">
          <label for="nome">Nome</label>
          <input id="nome"
                 name="nome"
                 type="text"
                 value="<?= htmlspecialchars($_POST['nome'] ?? '', ENT_QUOTES) ?>"
                 required>
        </div>
        <div class="form-group">
          <label for="cognome">Cognome</label>
          <input id="cognome"
                 name="cognome"
                 type="text"
                 value="<?= htmlspecialchars($_POST['cognome'] ?? '', ENT_QUOTES) ?>"
                 required>
        </div>
      </div>

      <div class="actions">
        <a href="/biosound/operatori.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Indietro
        </a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva Operatore
        </button>
      </div>
    </form>
  </div>
</body>
</html>
