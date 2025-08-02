<?php
// azienda.php — visualizzazione e modifica di una singola azienda
include 'init.php';

// 2) Recupera ID o redirect
$id = $_GET['id'] ?? '';
if (!$id) {
    header('Location: /biosound/aziende.php');
    exit;
}

// 3) Carica dati azienda
$stmt = $pdo->prepare('
    SELECT ragionesociale, piva, ateco, email,
           legalerappresentante, nomereferente, contattoreferente
      FROM azienda
     WHERE id = ?
');
$stmt->execute([$id]);
$azienda = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$azienda) {
    header('Location: /biosound/aziende.php');
    exit;
}

$updated          = isset($_GET['updated']);
$errorDuplicate   = false;
$errorHasEmployees = false;

// 4) Gestione POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // update
    if (isset($_POST['update'])) {
        $rs   = trim($_POST['ragionesociale'] ?? '');
        $piva = trim($_POST['piva'] ?? '');
        $ateco= trim($_POST['ateco'] ?? '') ?: null;
        $email= trim($_POST['email'] ?? '') ?: null;
        $leg  = trim($_POST['legalerappresentante'] ?? '') ?: null;
        $nome = trim($_POST['nomereferente'] ?? '') ?: null;
        $cont = trim($_POST['contattoreferente'] ?? '') ?: null;

        // verifica duplicato P.IVA (escludo l'azienda corrente)
        $dup = $pdo->prepare(
            'SELECT COUNT(*) FROM azienda WHERE piva = ? AND id <> ?'
        );
        $dup->execute([$piva, $id]);
        if ((int)$dup->fetchColumn() > 0) {
            $errorDuplicate = true;
        } else {
            $upd = $pdo->prepare('
              UPDATE azienda SET
                ragionesociale=?, piva=?, ateco=?, email=?,
                legalerappresentante=?, nomereferente=?, contattoreferente=?
              WHERE id=?
            ');
            $upd->execute([
                $rs, $piva, $ateco, $email,
                $leg, $nome, $cont,
                $id
            ]);
            header('Location: /biosound/azienda.php?id='
                   . urlencode($id) . '&updated=1');
            exit;
        }
    }

    // delete
    if (isset($_POST['delete'])) {
        // controllo sedi con dipendenti
        $check = $pdo->prepare(<<<'SQL'
SELECT COUNT(*) 
  FROM sede s
  JOIN dipendente_sede ds ON ds.sede_id = s.id
 WHERE s.azienda_id = ?
SQL
        );
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
            $errorHasEmployees = true;
        } else {
            // elimina azienda
            $pdo->prepare('DELETE FROM azienda WHERE id = ?')
                ->execute([$id]);
            header('Location: /biosound/aziende.php?deleted=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Modifica Azienda</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg: #f0f2f5; --fg: #2e3a45; --radius: 8px;
      --shadow: rgba(0,0,0,0.08); --font: 'Segoe UI', sans-serif;
      --pri: #28a745; /* verde pieno */
      --err: #dc3545; /* rosso pieno */
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { background:var(--bg); color:var(--fg); font-family:var(--font); }
    .container { max-width:700px; margin:2rem auto; padding:0 1rem; }
    h1 { text-align:center; margin-bottom:1rem; }

    .alert {
      position: fixed;
      bottom: 1rem;
      left: 1rem;
      padding: .75rem 1.25rem;
      border-radius: var(--radius);
      box-shadow: 0 2px 6px var(--shadow);
      color: #fff;
      font-family: var(--font);
      opacity: 1;
      transition: opacity .5s ease-out;
      z-index: 1000;
    }
    .alert-success { background: var(--pri); }
    .alert-danger  { background: var(--err); }

    form { display:flex; flex-direction:column; gap:1.25rem; }
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
    .btn-primary:hover { background:#218838; transform:translateY(-2px); }

    .btn-danger    { background:var(--err); }
    .btn-danger:hover { background:#c82333; transform:translateY(-2px); }
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
    <h1>Modifica Azienda</h1>

    <?php if ($updated): ?>
      <div class="alert alert-success" id="toast">
        Azienda aggiornata con successo!
      </div>
      <script>setTimeout(()=>document.getElementById('toast').style.opacity='0',2000);</script>
    <?php endif; ?>

    <?php if ($errorDuplicate): ?>
      <div class="alert alert-danger" id="toast">
        Partita IVA già esistente!
      </div>
      <script>setTimeout(()=>document.getElementById('toast').style.opacity='0',2000);</script>
    <?php endif; ?>

    <?php if ($errorHasEmployees): ?>
      <div class="alert alert-danger" id="toast">
        Impossibile eliminare: ci sono dipendenti iscritti a sedi di questa azienda.
      </div>
      <script>setTimeout(()=>document.getElementById('toast').style.opacity='0',2000);</script>
    <?php endif; ?>

    <form method="post" action="/biosound/azienda.php?id=<?=urlencode($id)?>">
      <div class="form-group">
        <label for="ragionesociale">Ragione Sociale *</label>
        <input id="ragionesociale" name="ragionesociale" type="text"
               required value="<?=htmlspecialchars($azienda['ragionesociale'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="piva">Partita IVA *</label>
        <input id="piva" name="piva" type="text" maxlength="11"
               required value="<?=htmlspecialchars($azienda['piva'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="ateco">Codice ATECO</label>
        <input id="ateco" name="ateco" type="text"
               value="<?=htmlspecialchars($azienda['ateco'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input id="email" name="email" type="email"
               value="<?=htmlspecialchars($azienda['email'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="legalerappresentante">Legale Rappresentante</label>
        <input id="legalerappresentante" name="legalerappresentante" type="text"
               value="<?=htmlspecialchars($azienda['legalerappresentante'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="nomereferente">Nome Referente</label>
        <input id="nomereferente" name="nomereferente" type="text"
               value="<?=htmlspecialchars($azienda['nomereferente'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="contattoreferente">Contatto Referente</label>
        <input id="contattoreferente" name="contattoreferente" type="text"
               value="<?=htmlspecialchars($azienda['contattoreferente'],ENT_QUOTES)?>">
      </div>

      <div class="actions">
        <a href="/biosound/aziende.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Annulla
        </a>
        <button type="submit" name="update" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva
        </button>
        <button type="submit" name="delete" class="btn btn-danger"
                onclick="return confirm('Eliminare questa azienda?');">
          <i class="bi bi-trash"></i> Elimina
        </button>
      </div>
    </form>
  </div>
</body>
</html>
