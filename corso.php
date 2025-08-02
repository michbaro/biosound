<?php
// corso.php — visualizza/modifica + elimina corso, con campo maxpartecipanti
include 'init.php';

$errorDuplicate   = false;
$errorNotFound    = false;
$maxPartecipanti  = 0;

// 1) Recupero ID originale (GET o hidden POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origId = strtoupper(trim($_POST['orig_id'] ?? ''));
} else {
    $origId = strtoupper(trim($_GET['id'] ?? ''));
}
if ($origId === '') {
    header('Location: /biosound/corsi.php');
    exit;
}

// 2) Gestione cancellazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $stmtDel = $pdo->prepare('DELETE FROM corso WHERE id = ?');
    $stmtDel->execute([$origId]);
    header('Location: /biosound/corsi.php?deleted=1');
    exit;
}

// variabili per il form
$id            = '';
$titolo        = '';
$durata        = '';
$aula          = false;
$fad           = false;
$categoria     = '';
$tipologia     = '';
$programmaPath = '';

// 3) GET: carico dati esistenti
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM corso WHERE id = ?');
    $stmt->execute([$origId]);
    if (!$c = $stmt->fetch()) {
        $errorNotFound = true;
    } else {
        $id               = $c['id'];
        $titolo           = $c['titolo'];
        $durata           = $c['durata'];
        $modalita         = (int)$c['modalita'];
        $aula             = $modalita === 1 || $modalita === 2;
        $fad              = $modalita === 2 || $modalita === 0;
        $categoria        = $c['categoria'];
        $tipologia        = $c['tipologia'];
        $programmaPath    = $c['programma'];
        $maxPartecipanti  = (int)$c['maxpartecipanti'];
    }
}

// 4) POST: salvataggio modifiche (escludendo delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete'])) {
    // raccolgo dati da POST
    $id               = strtoupper(trim($_POST['id'] ?? ''));
    $titolo           = trim($_POST['titolo'] ?? '');
    $durata           = trim($_POST['durata'] ?? '');
    $maxPartecipanti  = intval($_POST['maxpartecipanti'] ?? 0);
    $aula             = isset($_POST['aula']);
    $fad              = isset($_POST['fad']);
    $modalita         = $aula && $fad ? 2 : ($aula ? 1 : 0);
    $categoria        = $_POST['categoria'] ?? '';
    $tipologia        = $_POST['tipologia'] ?? '';

    // duplicato se cambio ID
    if ($id !== $origId) {
        $stmtDup = $pdo->prepare('SELECT COUNT(*) FROM corso WHERE UPPER(id)=?');
        $stmtDup->execute([$id]);
        if ((int)$stmtDup->fetchColumn() > 0) {
            $errorDuplicate = true;
        }
    }

    if (!$errorDuplicate) {
        // recupero vecchio percorso PDF
        $stmtOld = $pdo->prepare('SELECT programma FROM corso WHERE id = ?');
        $stmtOld->execute([$origId]);
        if (!$old = $stmtOld->fetch()) {
            $errorNotFound = true;
        } else {
            $programmaPath = $old['programma'];
            // upload nuovo PDF (facoltativo)
            if (!empty($_FILES['programma']['name'])
                && $_FILES['programma']['error'] !== UPLOAD_ERR_NO_FILE
            ) {
                $folder    = preg_replace('/[^A-Za-z0-9_-]+/', '_', $titolo);
                $uploadDir = __DIR__ . "/resources/corsi/{$folder}/";
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $file = $_FILES['programma'];
                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $mime = mime_content_type($file['tmp_name']);
                if ($file['error'] === UPLOAD_ERR_OK
                    && $ext === 'pdf'
                    && $mime === 'application/pdf'
                ) {
                    $name = 'programma_' . time() . '.pdf';
                    move_uploaded_file($file['tmp_name'], $uploadDir . $name);
                    $programmaPath = "resources/corsi/{$folder}/{$name}";
                }
            }

            // update compreso maxpartecipanti
            $stmtUp = $pdo->prepare(<<<'SQL'
UPDATE corso
   SET id = ?, titolo = ?, durata = ?, modalita = ?, categoria = ?, 
       tipologia = ?, programma = ?, maxpartecipanti = ?
 WHERE id = ?
SQL
            );
            $stmtUp->execute([
                $id,
                $titolo,
                $durata,
                $modalita,
                $categoria,
                $tipologia,
                $programmaPath,
                $maxPartecipanti,
                $origId
            ]);

            header('Location: /biosound/corsi.php?edited=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Modifica Corso</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg:#f0f2f5; --fg:#2e3a45; --radius:8px;
      --shadow:rgba(0,0,0,0.08); --font:'Segoe UI',sans-serif;
      --pri:#66bb6a; --green-soft:#e8f5e9; --green-dark:#2e7d32;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { background:var(--bg); color:var(--fg); font-family:var(--font); }
    .container { max-width:700px; margin:2rem auto; padding:0 1rem; }
    h1 { text-align:center; margin-bottom:1rem; }

    .alert-success, .alert-danger {
      padding:.75rem 1rem; border-radius:var(--radius);
      margin-bottom:1rem; box-shadow:0 2px 6px var(--shadow);
      text-align:center;
    }
    .alert-success { background:#d4edda; color:#155724; }
    .alert-danger  { background:#f8d7da; color:#721c24; }

    form { display:flex; flex-direction:column; gap:1.5rem; }
    .id-title-row { display:flex; gap:1rem; }
    .id-group { flex:1; } .title-group { flex:4; }
    input, select { width:100%; padding:.5rem .75rem;
      border:1px solid #ccc; border-radius:var(--radius); font-size:1rem; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .pdf-actions { display:flex; justify-content:center; margin:1.5rem 0; }
    .pdf-action-item {
      display:inline-flex; align-items:center; gap:.75rem;
      background:var(--green-soft); border-radius:6px;
      padding:.6rem 1rem; cursor:pointer; transition:background .2s,transform .1s;
    }
    .pdf-action-item.upload label {
      font-weight:600; color:var(--green-dark);
      display:inline-flex; align-items:center; gap:.75rem;
      cursor:pointer;
    }
    .pdf-action-item.upload input { display:none; }
    .file-name {
      margin-top:4px; font-size:.85rem; color:#555; font-style:italic;
    }
    .actions {
      display:flex; justify-content:center; gap:3rem; margin-top:2rem;
    }
    .btn {
      display:inline-flex; align-items:center; gap:.75rem;
      padding:.6rem 1.2rem; font-size:1rem; font-weight:bold;
      color:#fff; border:none; border-radius:var(--radius);
      text-decoration:none; cursor:pointer; transition:background .2s,transform .15s;
    }
    .btn-secondary { background:#6c757d; }
    .btn-secondary:hover { background:#5a6268; transform:translateY(-2px); }
    .btn-primary { background:var(--pri); }
    .btn-primary:hover { background:#5aad5c; transform:translateY(-2px); }
    .btn-primary:active { background:#4b8950; }

    .btn-danger {
  display: inline-flex;
  align-items: center;
  gap: .75rem;
  padding: .6rem 1.2rem;
  font-size: 1rem;
  font-weight: bold;
  color: #fff;
  background: #dc3545;
  border: none;
  border-radius: var(--radius);
  text-decoration: none;
  cursor: pointer;
  transition: background .2s, transform .15s;
}

.btn-danger:hover {
  background: #c82333;
  transform: translateY(-2px);
}

.btn-danger:active {
  background: #bd2130;
}

.btn-danger:focus {
  outline: none;
  box-shadow: 0 0 0 .2rem rgba(220, 53, 69, 0.5);
}  </style>
</head>
<body>

<?php
  // navbar
  $role = $_SESSION['role'] ?? 'utente';
  if ($role==='admin')    include 'navbar_a.php';
  elseif ($role==='dev')  include 'navbar_d.php';
  else                    include 'navbar.php';
?>

<div class="container">
  <h1>Modifica Corso</h1>

  <?php if ($errorNotFound): ?>
    <div class="alert-danger">
      Corso non trovato. <a href="/biosound/corsi.php">Torna all’elenco</a>
    </div>
    <?php exit; ?>
  <?php endif; ?>

  <?php if ($errorDuplicate): ?>
    <div class="alert-danger">ID già esistente. Scegli un altro ID.</div>
  <?php endif; ?>

  <form method="post" action="corso.php" enctype="multipart/form-data">
    <input type="hidden" name="orig_id" value="<?= htmlspecialchars($origId, ENT_QUOTES) ?>">

    <!-- ID + Titolo -->
    <div class="id-title-row">
      <div class="id-group form-group">
        <label for="id">ID</label>
        <input id="id" name="id" type="text" maxlength="2" required
               value="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
               oninput="this.value = this.value.toUpperCase()">
      </div>
      <div class="title-group form-group">
        <label for="titolo">Titolo</label>
        <input id="titolo" name="titolo" type="text" required
               value="<?= htmlspecialchars($titolo, ENT_QUOTES) ?>">
      </div>
    </div>

    <!-- Durata + Modalità -->
    <div class="form-grid">
      <!-- Durata -->
      <div class="form-group">
        <label for="durata">Durata (ore)</label>
        <input id="durata" name="durata" type="text" required
               value="<?= htmlspecialchars($durata, ENT_QUOTES) ?>">
      </div>
      <!-- Modalità -->
      <div class="form-group">
        <label>Modalità</label>
        <div style="display:flex; gap:1rem;">
          <label><input type="checkbox" name="aula" <?= $aula ? 'checked' : '' ?>> Aula</label>
          <label><input type="checkbox" name="fad"  <?= $fad  ? 'checked' : '' ?>> FAD</label>
        </div>
      </div>
    </div>

    <!-- Categoria + Tipologia -->
    <div class="form-grid">
      <div class="form-group">
        <label for="categoria">Categoria</label>
        <select id="categoria" name="categoria" required>
          <option disabled>Seleziona</option>
          <?php foreach (['HACCP','Sicurezza','Antincendio','Primo Soccorso','Macchine Operatrici'] as $cat): ?>
            <option <?= $categoria === $cat ? 'selected' : '' ?>><?= $cat ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="tipologia">Tipologia</label>
        <select id="tipologia" name="tipologia" required>
          <option disabled>Seleziona</option>
          <?php foreach (['Primo Rilascio','Aggiornamento'] as $tip): ?>
            <option <?= $tipologia === $tip ? 'selected' : '' ?>><?= $tip ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Max Partecipanti -->
    <div class="form-group">
      <label for="maxpartecipanti">Max partecipanti</label>
      <input id="maxpartecipanti" name="maxpartecipanti" type="number" min="0" required
             value="<?= htmlspecialchars($maxPartecipanti, ENT_QUOTES) ?>">
    </div>

    <!-- Programma PDF -->
    <div class="form-group">
      <label>Programma:</label>
      <div style="display:flex; align-items:center; gap:1rem;">
        <?php if ($programmaPath): ?>
          <a href="<?=htmlspecialchars($programmaPath,ENT_QUOTES)?>" target="_blank">
            <i class="bi bi-eye-fill" style="font-size:1.5rem; color:#2e7d32;"></i>
          </a>
          <span class="file-name"><?= basename($programmaPath) ?></span>
        <?php else: ?>
          <span class="text-muted">Nessun programma caricato</span>
        <?php endif; ?>

        <div class="pdf-action-item upload" style="margin-left:auto;">
          <input id="programma" name="programma" type="file" accept="application/pdf">
          <label for="programma">
            <i class="bi bi-upload"></i> Sostituisci PDF
          </label>
          <div id="programma-filename" class="file-name"></div>
        </div>
      </div>
    </div>

    <!-- Azioni -->
    <div class="actions">
      <a href="/biosound/corsi.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Annulla
      </a>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-save"></i> Salva Modifiche
      </button>
      <button type="submit" name="delete" class="btn btn-danger"
              onclick="return confirm('Sei sicuro di voler eliminare questo corso?');">
        <i class="bi bi-trash"></i> Elimina Corso
      </button>
    </div>
  </form>
</div>

<script>
  // mostra nome del file PDF scelto
  const progInput   = document.getElementById('programma');
  const progNameDiv = document.getElementById('programma-filename');
  progInput.addEventListener('change', () => {
    const file = progInput.files[0];
    progNameDiv.textContent = file ? file.name : '';
  });
</script>
</body>
</html>
