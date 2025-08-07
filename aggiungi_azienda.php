<?php
// aggiungi_azienda.php — form per aggiungere azienda + sede legale
require_once __DIR__ . '/init.php';

$added          = false;
$errorDuplicate = false;
$errorDb        = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) Raccogli e pulisci dati
    $ragioneSociale       = trim($_POST['ragionesociale'] ?? '');
    $piva                 = trim($_POST['piva'] ?? '');
    $ateco                = trim($_POST['ateco'] ?? '');
    $email                = trim($_POST['email'] ?? '');
    $sdi                  = trim($_POST['sdi'] ?? '');
    $legaleRappresentante = trim($_POST['legalerappresentante'] ?? '');
    $nomeReferente        = trim($_POST['nomereferente'] ?? '');
    $emailReferente       = trim($_POST['emailreferente'] ?? '');
    $numeroReferente      = trim($_POST['numeroreferente'] ?? '');
    $indirizzoLegale      = trim($_POST['indirizzo_legale'] ?? '');

    // 2) Verifica P.IVA duplicata
    $stmtDup = $pdo->prepare('SELECT COUNT(*) FROM azienda WHERE piva = ?');
    $stmtDup->execute([$piva]);
    if ((int)$stmtDup->fetchColumn() > 0) {
        $errorDuplicate = true;
    } else {
        // 3) Transazione: inserisco azienda + sede legale
        try {
            $pdo->beginTransaction();

            // Genero ID univoci
            $aziendaId    = bin2hex(random_bytes(16));
            $sedeLegaleId = bin2hex(random_bytes(16));

            // 3a) Inserisco in azienda (includo sedelegale_id, sdi, emailreferente, numeroreferente)
            $stmtA = $pdo->prepare(<<<'SQL'
INSERT INTO azienda
  (id, sedelegale_id, ragionesociale, piva, ateco, email, sdi,
   legalerappresentante, nomereferente, emailreferente, numeroreferente)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL
            );
            $stmtA->execute([
                $aziendaId,
                $sedeLegaleId,
                $ragioneSociale,
                $piva,
                $ateco  ?: null,
                $email  ?: null,
                $sdi    ?: null,
                $legaleRappresentante ?: null,
                $nomeReferente        ?: null,
                $emailReferente       ?: null,
                $numeroReferente      ?: null,
            ]);

            // 3b) Inserisco la sede legale
            $stmtS = $pdo->prepare(<<<'SQL'
INSERT INTO sede
  (id, azienda_id, nome, indirizzo, is_legale)
VALUES (?, ?, 'LEGALE', ?, 1)
SQL
            );
            $stmtS->execute([
                $sedeLegaleId,
                $aziendaId,
                $indirizzoLegale ?: null,
            ]);

            $pdo->commit();
            $added = true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $errorDb = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Aggiungi Azienda</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg: #f0f2f5; --fg: #2e3a45; --radius: 8px;
      --shadow: rgba(0,0,0,0.08); --font: 'Segoe UI', sans-serif;
      --pri: #66bb6a; --err: #d9534f;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { background:var(--bg); color:var(--fg); font-family:var(--font); }
    .container { max-width:700px; margin:2rem auto; padding:0 1rem; }
    h1 { text-align:center; margin-bottom:1rem; }
    .alert {
      position: fixed; bottom: 1rem; left: 1rem;
      padding:.75rem 1.25rem; border-radius:var(--radius);
      box-shadow:0 2px 6px var(--shadow); color:#fff;
      font-family:var(--font); opacity:1;
      transition:opacity .5s ease-out; z-index:1000;
    }
    .alert-success { background:var(--pri); }
    .alert-danger  { background:var(--err); }
    form { display:flex; flex-direction:column; gap:1rem; }
    .form-group { display:flex; flex-direction:column; }
    label { margin-bottom:.5rem; font-weight:500; }
    input[type="text"],
    input[type="email"] {
      padding:.5rem .75rem;
      border:1px solid #ccc;
      border-radius:var(--radius);
      font-size:1rem;
      width:100%;
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
  $role = $_SESSION['role'] ?? 'utente';
  switch ($role) {
    case 'admin': include 'navbar_a.php'; break;
    case 'dev':   include 'navbar_d.php'; break;
    default:      include 'navbar.php';
  }
?>
  <div class="container">
    <h1>Aggiungi Azienda</h1>

    <?php if ($added): ?>
      <div class="alert alert-success" id="toast">
        Azienda e sede legale aggiunte con successo!
      </div>
    <?php elseif ($errorDuplicate): ?>
      <div class="alert alert-danger" id="toast">
        Partita IVA già esistente!
      </div>
    <?php elseif ($errorDb): ?>
      <div class="alert alert-danger" id="toast">
        Errore durante il salvataggio. Riprova più tardi.
      </div>
    <?php endif; ?>

    <?php if ($added || $errorDuplicate || $errorDb): ?>
      <script>
        setTimeout(() => document.getElementById('toast').style.opacity = '0', 2000);
      </script>
    <?php endif; ?>

    <form method="post" action="aggiungi_azienda.php">
      <div class="form-group">
        <label for="ragionesociale">Ragione Sociale *</label>
        <input id="ragionesociale" name="ragionesociale" type="text" required
               value="<?= htmlspecialchars($_POST['ragionesociale'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="piva">Partita IVA *</label>
        <input id="piva" name="piva" type="text" maxlength="11" required
               pattern="\d{11}" title="Inserisci 11 cifre numeriche"
               value="<?= htmlspecialchars($_POST['piva'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="ateco">Codice ATECO</label>
        <input id="ateco" name="ateco" type="text"
               value="<?= htmlspecialchars($_POST['ateco'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input id="email" name="email" type="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="sdi">SDI/PEC</label>
        <input id="sdi" name="sdi" type="text"
               value="<?= htmlspecialchars($_POST['sdi'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="legalerappresentante">Legale Rappresentante</label>
        <input id="legalerappresentante" name="legalerappresentante" type="text"
               value="<?= htmlspecialchars($_POST['legalerappresentante'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="nomereferente">Nome Referente</label>
        <input id="nomereferente" name="nomereferente" type="text"
               value="<?= htmlspecialchars($_POST['nomereferente'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="emailreferente">Email Referente</label>
        <input id="emailreferente" name="emailreferente" type="email"
               value="<?= htmlspecialchars($_POST['emailreferente'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="numeroreferente">Numero Referente</label>
        <input id="numeroreferente" name="numeroreferente" type="text"
               value="<?= htmlspecialchars($_POST['numeroreferente'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="indirizzo_legale">Indirizzo Sede Legale *</label>
        <input id="indirizzo_legale" name="indirizzo_legale" type="text" required
               value="<?= htmlspecialchars($_POST['indirizzo_legale'] ?? '') ?>">
      </div>
      <div class="actions">
        <a href="/biosound/aziende.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Annulla
        </a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva Azienda
        </button>
      </div>
    </form>
  </div>
</body>
</html>
