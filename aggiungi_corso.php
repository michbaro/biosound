<?php
// aggiungi_corso.php — form per aggiungere un nuovo corso con ID in maiuscolo e validità (anni)
include 'init.php';

$errorDuplicate    = false;
$maxPartecipanti   = 0;
$validita          = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id               = strtoupper(trim($_POST['id'] ?? ''));
    $titolo           = trim($_POST['titolo'] ?? '');
    $durata           = trim($_POST['durata'] ?? '');
    $validita         = ($_POST['validita'] === '' ? null : (int)$_POST['validita']); // nuovo
    $maxPartecipanti  = intval($_POST['maxpartecipanti'] ?? 0);
    $aula             = isset($_POST['aula']);
    $fad              = isset($_POST['fad']);
    $modalita         = $aula && $fad ? 2 : ($aula ? 1 : 0);
    $categoria        = $_POST['categoria'] ?? '';
    $tipologia        = $_POST['tipologia'] ?? '';

    // duplicato
    $stmtDup = $pdo->prepare('SELECT COUNT(*) FROM corso WHERE UPPER(id) = ?');
    $stmtDup->execute([$id]);
    if ((int)$stmtDup->fetchColumn() > 0) {
        $errorDuplicate = true;
    } else {
        // upload programma
        $programmaPath = null;
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
                $name          = 'programma_' . time() . '.pdf';
                move_uploaded_file($file['tmp_name'], $uploadDir . $name);
                $programmaPath = "resources/corsi/{$folder}/{$name}";
            }
        }

        // inserisci nuovo
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO corso
  (id, titolo, durata, validita, modalita, categoria, tipologia, programma, maxpartecipanti)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL
        );
        $stmt->execute([
            $id,
            $titolo,
            $durata,
            $validita,
            $modalita,
            $categoria,
            $tipologia,
            $programmaPath,
            $maxPartecipanti
        ]);

        header('Location: /biosound/corsi.php?added=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Aggiungi Corso</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {--bg:#f0f2f5;--fg:#2e3a45;--radius:8px;--shadow:rgba(0,0,0,0.08);
           --font:'Segoe UI',sans-serif;--pri:#66bb6a;--green-soft:#e8f5e9;--green-dark:#2e7d32;}
    * { box-sizing:border-box; margin:0; padding:0; }
    body { background:var(--bg); color:var(--fg); font-family:var(--font); }
    .container { max-width:700px; margin:2rem auto; padding:0 1rem; }
    h1 { text-align:center; margin-bottom:1rem; }
    .alert-danger { background:#f8d7da; color:#721c24; padding:.75rem 1rem; border-radius:var(--radius);
                    margin-bottom:1rem; box-shadow:0 2px 6px var(--shadow); text-align:center;}
    form { display:flex; flex-direction:column; gap:1.5rem; }
    .id-title-row { display:flex; gap:1rem; }
    .id-group { flex:1; } .title-group { flex:4; }
    input, select { width:100%; padding:.5rem .75rem; border:1px solid #ccc; border-radius:var(--radius); font-size:1rem; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .form-group { display:flex; flex-direction:column; }
    label { margin-bottom:.5rem; font-weight:500; }
    input[type="file"] { display:none; }
    .pdf-actions { display:flex; justify-content:center; margin:1.5rem 0; }
    .pdf-action-item { display:inline-flex; align-items:center; gap:.75rem; background:var(--green-soft);
                       border-radius:6px; padding:.6rem 1rem; cursor:pointer; height:2.75rem;
                       transition:background .2s,transform .1s; }
    .pdf-action-item.upload label { font-weight:600; color:var(--green-dark); display:inline-flex; align-items:center; gap:.75rem; cursor:pointer;}
    .pdf-action-item.upload input { display:none; }
    .pdf-action-item:hover { background:#c8e6c9; transform:translateY(-1px);}
    .file-name { margin-top:4px; font-size:.85rem; color:#555; font-style:italic;}
    .actions { display:flex; justify-content:center; gap:3rem; margin-top:2rem; }
    .btn { display:inline-flex; align-items:center; gap:.75rem; padding:.6rem 1.2rem; font-size:1rem;
           font-weight:bold; color:#fff; border:none; border-radius:var(--radius); text-decoration:none; cursor:pointer; transition:background .2s,transform .15s;}
    .btn-secondary { background:#6c757d; } .btn-secondary:hover { background:#5a6268; transform:translateY(-2px); }
    .btn-primary { background:var(--pri); } .btn-primary:hover { background:#5aad5c; transform:translateY(-2px); }
  </style>
</head>
<body>
<?php
  $role = $_SESSION['role'] ?? 'utente';
  if ($role==='admin')    include 'navbar_a.php';
  elseif ($role==='dev')  include 'navbar_d.php';
  else                    include 'navbar.php';
?>
<div class="container">
  <h1>Aggiungi Corso</h1>
  <?php if ($errorDuplicate): ?>
    <div class="alert-danger">ID già esistente. Scegli un altro ID.</div>
  <?php endif; ?>
  <form method="post" action="aggiungi_corso.php" enctype="multipart/form-data">
    <!-- ID + Titolo -->
    <div class="id-title-row">
      <div class="form-group id-group">
        <label for="id">ID</label>
        <input id="id" name="id" type="text" maxlength="2" required oninput="this.value=this.value.toUpperCase()">
      </div>
      <div class="form-group title-group">
        <label for="titolo">Titolo</label>
        <input id="titolo" name="titolo" type="text" required>
      </div>
    </div>
    <!-- Durata + Modalità -->
    <div class="form-grid">
      <div class="form-group">
        <label for="durata">Durata (ore)</label>
        <input id="durata" name="durata" type="text" required>
      </div>
      <div class="form-group">
        <label>Modalità</label>
        <div style="display:flex; gap:1rem;">
          <label><input type="checkbox" name="aula"> Aula</label>
          <label><input type="checkbox" name="fad"> FAD</label>
        </div>
      </div>
    </div>
    <!-- Validità + Max Partecipanti -->
    <div class="form-grid">
      <div class="form-group">
        <label for="validita">Validità (anni)</label>
        <input id="validita" name="validita" type="number" min="0" step="1"
               placeholder="Es. 5 (vuoto = nessuna scadenza)">
      </div>
      <div class="form-group">
        <label for="maxpartecipanti">Max partecipanti</label>
        <input id="maxpartecipanti" name="maxpartecipanti" type="number" min="0" required value="<?= htmlspecialchars($maxPartecipanti, ENT_QUOTES) ?>">
      </div>
    </div>
    <!-- Categoria + Tipologia -->
    <div class="form-grid">
      <div class="form-group">
        <label for="categoria">Categoria</label>
        <select id="categoria" name="categoria" required>
          <option disabled selected>Seleziona</option>
          <option>HACCP</option>
          <option>Sicurezza</option>
          <option>Antincendio</option>
          <option>Primo Soccorso</option>
          <option>Macchine Operatrici</option>
        </select>
      </div>
      <div class="form-group">
        <label for="tipologia">Tipologia</label>
        <select id="tipologia" name="tipologia" required>
          <option disabled selected>Seleziona</option>
          <option>Primo Rilascio</option>
          <option>Aggiornamento</option>
        </select>
      </div>
    </div>
    <!-- Upload PDF -->
    <div class="pdf-actions">
      <div class="pdf-action-item upload">
        <input id="programma" name="programma" type="file" accept="application/pdf">
        <label for="programma"><i class="bi bi-upload"></i> Scegli PDF Programma</label>
        <div id="programma-filename" class="file-name"></div>
      </div>
    </div>
    <!-- Bottoni -->
    <div class="actions">
      <a href="/biosound/corsi.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Indietro</a>
      <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Salva Corso</button>
    </div>
  </form>
</div>
<script>
  const progInput=document.getElementById('programma');
  const progNameDiv=document.getElementById('programma-filename');
  progInput.addEventListener('change',()=>{const f=progInput.files[0]; progNameDiv.textContent=f?f.name:'';});
</script>
</body>
</html>
