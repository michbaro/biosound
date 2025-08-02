<?php
// aggiungi_sede.php — form per aggiungere una nuova sede a un’azienda
include 'init.php';

// 2) Recupera azienda_id da GET o redirect
$azienda_id = $_GET['azienda_id'] ?? '';
if (!$azienda_id) {
    header('Location: /biosound/aziende.php');
    exit;
}

// 3) Carica ragione sociale per intestazione
$stmtA = $pdo->prepare('SELECT ragionesociale FROM azienda WHERE id = ?');
$stmtA->execute([$azienda_id]);
$ragione = $stmtA->fetchColumn();
if (!$ragione) {
    header('Location: /biosound/aziende.php');
    exit;
}

$errorMissing = false;
$nome        = '';
$indirizzo   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 4) Raccogli dati
    $nome      = trim($_POST['nome'] ?? '');
    $indirizzo = trim($_POST['indirizzo'] ?? '');

    // 5) Validazione
    if ($nome === '' || $indirizzo === '') {
        $errorMissing = true;
    } else {
        // 6) Inserisci sede
        $id = bin2hex(random_bytes(16));
        $insert = $pdo->prepare('
            INSERT INTO sede
              (id, nome, indirizzo, azienda_id)
            VALUES (?, ?, ?, ?)
        ');
        $insert->execute([$id, $nome, $indirizzo, $azienda_id]);

        header('Location: /biosound/sedi.php?azienda_id='
               . urlencode($azienda_id) . '&added=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Aggiungi Sede</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg: #f0f2f5; --fg: #2e3a45; --radius: 8px;
      --shadow: rgba(0,0,0,0.08); --font: 'Segoe UI', sans-serif;
      --pri: #66bb6a; --err: #dc3545;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { background:var(--bg); color:var(--fg); font-family:var(--font); }
    .container { max-width:700px; margin:2rem auto; padding:0 1rem; }
    h1 { text-align:center; margin-bottom:1rem; }

    .alert-danger {
      background: var(--err); color: #fff;
      padding:.75rem 1rem; border-radius:var(--radius);
      margin-bottom:1rem; box-shadow:0 2px 6px var(--shadow);
      text-align:center;
    }

    form { display:flex; flex-direction:column; gap:1rem; }
    .form-group { display:flex; flex-direction:column; }
    label { margin-bottom:.5rem; font-weight:500; }
    input[type="text"] {
      padding:.5rem .75rem;
      border:1px solid #ccc; border-radius:var(--radius);
      font-size:1rem; width:100%;
    }

    .actions {
      display:flex; justify-content:center; gap:2rem; margin-top:1.5rem;
    }
    .btn {
      padding:.6rem 1.2rem; border:none; border-radius:var(--radius);
      cursor:pointer; color:#fff; font-size:1rem; font-weight:bold;
      display:inline-flex; align-items:center; gap:.75rem;
      text-decoration:none; min-height:2.75rem;
      transition:background .2s, transform .15s;
    }
    .btn-secondary { background:#6c757d; }
    .btn-secondary:hover { background:#5a6268; transform:translateY(-2px); }
    .btn-primary   { background:var(--pri); }
    .btn-primary:hover { background:#5aad5c; transform:translateY(-2px); }
  </style>
</head>
<body>
<?php
  // navbar dinamica
  $role = $_SESSION['role'] ?? 'utente';
  switch ($role) {
    case 'admin': include 'navbar_a.php'; break;
    case 'dev':   include 'navbar_d.php'; break;
    default:      include 'navbar.php';
  }
?>
  <div class="container">
    <h1>Aggiungi Sede per “<?= htmlspecialchars($ragione,ENT_QUOTES) ?>”</h1>

    <?php if ($errorMissing): ?>
      <div class="alert-danger">
        Compila entrambi i campi Nome e Indirizzo.
      </div>
    <?php endif; ?>

    <form method="post" action="/biosound/aggiungi_sede.php?azienda_id=<?= urlencode($azienda_id) ?>">
      <div class="form-group">
        <label for="nome">Nome sede *</label>
        <input id="nome" name="nome" type="text" required
               value="<?= htmlspecialchars($nome,ENT_QUOTES) ?>">
      </div>
      <div class="form-group">
        <label for="indirizzo">Indirizzo *</label>
        <input id="indirizzo" name="indirizzo" type="text" required
               value="<?= htmlspecialchars($indirizzo,ENT_QUOTES) ?>">
      </div>

      <div class="actions">
        <a href="/biosound/sedi.php?azienda_id=<?= urlencode($azienda_id) ?>"
           class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Annulla
        </a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva Sede
        </button>
      </div>
    </form>
  </div>
</body>
</html>
