<?php
// dipendente.php — visualizzazione e modifica di un singolo dipendente
include 'init.php';

// 2) Recupera ID dipendente o redirect
$dipId = $_GET['id'] ?? '';
if (!$dipId) {
    header('Location: /biosound/dipendenti.php');
    exit;
}

// 3) Carica dati anagrafici
$stmt = $pdo->prepare('SELECT * FROM dipendente WHERE id = ?');
$stmt->execute([$dipId]);
$dip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dip) {
    header('Location: /biosound/dipendenti.php');
    exit;
}

// 4) Carica associazione sede → azienda
$asStmt = $pdo->prepare(<<<'SQL'
  SELECT s.id AS sede_id, s.nome AS sede_nome,
         a.id AS azienda_id, a.ragionesociale
    FROM dipendente_sede ds
    JOIN sede s       ON ds.sede_id = s.id
    JOIN azienda a    ON s.azienda_id = a.id
   WHERE ds.dipendente_id = ?
   LIMIT 1
SQL
);
$asStmt->execute([$dipId]);
$asso = $asStmt->fetch(PDO::FETCH_ASSOC);
if (!$asso) {
    // nessuna sede associata → redirect
    header('Location: /biosound/dipendenti.php?error=no_sede');
    exit;
}
$currentSedeId         = $asso['sede_id'];
$currentSedeNome       = $asso['sede_nome'];
$currentAziendaId      = $asso['azienda_id'];
$currentAziendaRag      = $asso['ragionesociale'];

// 5) Prepara lista delle sedi per questa azienda
$sedeStmt = $pdo->prepare('
  SELECT id, nome FROM sede
   WHERE azienda_id = ?
   ORDER BY nome
');
$sedeStmt->execute([$currentAziendaId]);
$sediList = $sedeStmt->fetchAll(PDO::FETCH_ASSOC);

$updated = isset($_GET['updated']);
$deleted = isset($_GET['deleted']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update'])) {
        // 6a) Aggiorna campi anagrafici
        $pdo->prepare(<<<'SQL'
UPDATE dipendente SET
  nome=?, cognome=?, codice_fiscale=?,
  datanascita=?, luogonascita=?,
  comuneresidenza=?, viaresidenza=?, mansione=?
 WHERE id=?
SQL
        )->execute([
            trim($_POST['nome']),
            trim($_POST['cognome']),
            strtoupper(trim($_POST['codice_fiscale'])),
            $_POST['datanascita'] ?: null,
            trim($_POST['luogonascita']),
            trim($_POST['comuneresidenza']),
            trim($_POST['viaresidenza']),
            trim($_POST['mansione']),
            $dipId
        ]);

        // 6b) Aggiorna associazione sede
        $newSede = $_POST['sede_id'] ?? $currentSedeId;
        // elimina tutte le precedenti
        $pdo->prepare('DELETE FROM dipendente_sede WHERE dipendente_id = ?')
            ->execute([$dipId]);
        // inserisce la nuova
        $pdo->prepare('
          INSERT INTO dipendente_sede (dipendente_id, sede_id)
          VALUES (?, ?)
        ')->execute([$dipId, $newSede]);

        header("Location: /biosound/dipendente.php?id={$dipId}&updated=1");
        exit;
    }

    if (isset($_POST['delete'])) {
        // 6c) Elimina dipendente + associazioni
        $pdo->prepare('DELETE FROM dipendente_sede WHERE dipendente_id = ?')
            ->execute([$dipId]);
        $pdo->prepare('DELETE FROM dipendente WHERE id = ?')
            ->execute([$dipId]);
        header('Location: /biosound/dipendenti.php?deleted=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Modifica Dipendente</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg:#f0f2f5; --fg:#2e3a45; --radius:8px;
      --shadow:rgba(0,0,0,0.08); --font:'Segoe UI',sans-serif;
      --pri:#28a745; --err:#dc3545;
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{background:var(--bg);color:var(--fg);font-family:var(--font);}
    .container{max-width:700px;margin:2rem auto;padding:0 1rem;}
    h1{text-align:center;margin-bottom:1rem;}
    .toast {
      position:fixed;bottom:1rem;left:1rem;
      padding:.75rem 1.25rem;border-radius:var(--radius);
      color:#fff;box-shadow:0 2px 6px var(--shadow);
      opacity:1;transition:opacity .5s ease-out;z-index:1000;
    }
    .toast-success { background: var(--pri); }
    .toast-error   { background: var(--err); }
    form{display:flex;flex-direction:column;gap:1.5rem;}
    .form-group{display:flex;flex-direction:column;}
    label{margin-bottom:.5rem;font-weight:500;}
    input,select,textarea{
      width:100%;padding:.5rem .75rem;
      border:1px solid #ccc;border-radius:var(--radius);
      font-size:1rem;
    }
    textarea{resize:vertical;}
    .actions{
      display:flex;justify-content:center;align-items:center;
      gap:2rem;margin-top:2rem;
    }
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      gap:.5rem;padding:.6rem 1.2rem;font-size:1rem;font-weight:bold;
      color:#fff;border:none;border-radius:var(--radius);
      cursor:pointer;text-decoration:none;transition:opacity .2s;
    }
    .btn-secondary{background:#6c757d;}
    .btn-secondary:hover{opacity:.9;}
    .btn-primary{background:var(--pri);}
    .btn-primary:hover{opacity:.9;}
    .btn-danger{background:var(--err);}
    .btn-danger:hover{opacity:.9;}
  </style>
</head>
<body>
<?php
  // navbar dinamica
  $role = $_SESSION['role'] ?? 'utente';
  switch($role){
    case 'admin': include 'navbar_a.php'; break;
    case 'dev':   include 'navbar_d.php'; break;
    default:      include 'navbar.php';
  }
?>
<div class="container">
  <h1>Modifica Dipendente</h1>

  <?php if ($updated): ?>
    <div class="toast toast-success">Dipendente aggiornato con successo.</div>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <div class="toast toast-error">Dipendente eliminato.</div>
  <?php endif; ?>

  <form method="post">
    <!-- Azienda (non modificabile) -->
    <div class="form-group">
      <label>Azienda</label>
      <input type="text" value="<?= htmlspecialchars($currentAziendaRag,ENT_QUOTES) ?>" disabled>
    </div>

    <!-- Sede (modificabile) -->
    <div class="form-group">
      <label for="sede_id">Sede</label>
      <select id="sede_id" name="sede_id" required>
        <?php foreach($sediList as $s): ?>
          <option value="<?= $s['id'] ?>"
            <?= $s['id'] === $currentSedeId ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['nome'],ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Campi anagrafici -->
    <div class="form-group">
      <label for="nome">Nome</label>
      <input id="nome" name="nome" type="text" required
             value="<?= htmlspecialchars($dip['nome'], ENT_QUOTES) ?>">
    </div>
    <div class="form-group">
      <label for="cognome">Cognome</label>
      <input id="cognome" name="cognome" type="text" required
             value="<?= htmlspecialchars($dip['cognome'], ENT_QUOTES) ?>">
    </div>
    <div class="form-group">
      <label for="codice_fiscale">Codice Fiscale</label>
      <input id="codice_fiscale" name="codice_fiscale"
             type="text" maxlength="16" required
             value="<?= htmlspecialchars($dip['codice_fiscale'], ENT_QUOTES) ?>">
    </div>
    <div class="form-group">
      <label for="datanascita">Data di nascita</label>
      <input id="datanascita" name="datanascita" type="date"
             value="<?= substr($dip['datanascita'] ?? '',0,10) ?>">
    </div>
    <div class="form-group">
      <label for="luogonascita">Luogo di nascita</label>
      <input id="luogonascita" name="luogonascita" type="text"
             value="<?= htmlspecialchars($dip['luogonascita'], ENT_QUOTES) ?>">
    </div>
    <div class="form-group">
      <label for="comuneresidenza">Comune di residenza</label>
      <input id="comuneresidenza" name="comuneresidenza" type="text"
             value="<?= htmlspecialchars($dip['comuneresidenza'], ENT_QUOTES) ?>">
    </div>
    <div class="form-group">
      <label for="viaresidenza">Via di residenza</label>
      <input id="viaresidenza" name="viaresidenza" type="text"
             value="<?= htmlspecialchars($dip['viaresidenza'], ENT_QUOTES) ?>">
    </div>
    <div class="form-group">
      <label for="mansione">Mansione</label>
      <input id="mansione" name="mansione" type="text"
             value="<?= htmlspecialchars($dip['mansione'], ENT_QUOTES) ?>">
    </div>

    <!-- Azioni -->
    <div class="actions">
      <a href="/biosound/dipendenti.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Indietro
      </a>
      <button type="submit" name="update" class="btn btn-primary">
        <i class="bi bi-pencil"></i> Salva Modifiche
      </button>
      <button type="submit" name="delete" class="btn btn-danger"
              onclick="return confirm('Eliminare questo dipendente?');">
        <i class="bi bi-trash"></i> Elimina
      </button>
    </div>
  </form>
</div>

<script>
  window.addEventListener('load', () => {
    document.querySelectorAll('.toast').forEach(t => {
      setTimeout(() => t.style.opacity = '0', 2000);
    });
  });
</script>
</body>
</html>
