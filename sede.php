<?php
// sede.php — visualizzazione, modifica e gestione sede legale
include 'init.php';

// 1) Recupera sede_id o redirect
$sede_id = $_GET['id'] ?? '';
if (!$sede_id) {
    header('Location: /biosound/aziende.php');
    exit;
}

// 2) Carica dati sede + azienda
$stmt = $pdo->prepare('
    SELECT s.id, s.nome, s.indirizzo, s.azienda_id, s.is_legale, a.ragionesociale
      FROM sede s
      JOIN azienda a ON a.id = s.azienda_id
     WHERE s.id = ?
');
$stmt->execute([$sede_id]);
$sede = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sede) {
    header('Location: /biosound/aziende.php');
    exit;
}

// 3) Conta dipendenti
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM dipendente_sede WHERE sede_id = ?');
$countStmt->execute([$sede_id]);
$dipCount = (int)$countStmt->fetchColumn();

// 4) Prepara altre sedi per riassegnazione (escludi la corrente)
$otherSedi = [];
if ($sede['is_legale']) {
    $stmtO = $pdo->prepare('
        SELECT id, nome
          FROM sede
         WHERE azienda_id = ?
           AND id <> ?
         ORDER BY nome
    ');
    $stmtO->execute([$sede['azienda_id'], $sede_id]);
    $otherSedi = $stmtO->fetchAll(PDO::FETCH_ASSOC);
}

// 5) Flags notifiche
$updated      = isset($_GET['updated']);
$legalChanged = isset($_GET['legalChanged']);

// 6) POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 6a) Cambio sede legale (solo update flag, non elimino la vecchia)
    if (isset($_POST['change_legal']) && $sede['is_legale'] && !empty($otherSedi)) {
        $newId = $_POST['new_legal'] ?? '';
        if ($newId) {
            $pdo->beginTransaction();
            // 1) Togli flag dalla vecchia
            $pdo->prepare('UPDATE sede SET is_legale = 0 WHERE azienda_id = ? AND is_legale = 1')
                ->execute([$sede['azienda_id']]);
            // 2) Assegna flag alla nuova
            $pdo->prepare('UPDATE sede SET is_legale = 1 WHERE id = ?')
                ->execute([$newId]);
            $pdo->commit();
            header('Location: /biosound/sede.php?id=' . urlencode($newId) . '&legalChanged=1');
            exit;
        }
    }

    // 6b) Eliminazione sede legale (riassegna + elimina vecchia)
    if (isset($_POST['delete_legal']) && $sede['is_legale']) {
        // deve selezionare prima la nuova sede
        $reassign = $_POST['reassign_legal'] ?? '';
        if (!$reassign) {
            // non selezionata → errore
            header('Location: /biosound/sede.php?id=' . urlencode($sede_id) . '&error=hasEmployees');
            exit;
        }
        $pdo->beginTransaction();
        // togli flag dalla vecchia
        $pdo->prepare('UPDATE sede SET is_legale = 0 WHERE azienda_id = ? AND is_legale = 1')
            ->execute([$sede['azienda_id']]);
        // assegna flag alla nuova
        $pdo->prepare('UPDATE sede SET is_legale = 1 WHERE id = ?')
            ->execute([$reassign]);
        // elimina la vecchia sede legale
        $pdo->prepare('DELETE FROM sede WHERE id = ?')
            ->execute([$sede_id]);
        $pdo->commit();
        header('Location: /biosound/sede.php?id=' . urlencode($reassign) . '&legalChanged=1');
        exit;
    }

    // 6c) Modifica nome/indirizzo
    if (isset($_POST['update'])) {
        $nome      = trim($_POST['nome'] ?? '');
        $indirizzo = trim($_POST['indirizzo'] ?? '');
        if ($nome !== '' && $indirizzo !== '') {
            $pdo->prepare('UPDATE sede SET nome = ?, indirizzo = ? WHERE id = ?')
                ->execute([$nome, $indirizzo, $sede_id]);
            header('Location: /biosound/sede.php?id=' . urlencode($sede_id) . '&updated=1');
            exit;
        }
    }

    // 6d) Eliminazione sede non-legale
    if (isset($_POST['delete']) && !$sede['is_legale']) {
        if ($dipCount === 0) {
            $pdo->prepare('DELETE FROM sede WHERE id = ?')->execute([$sede_id]);
            header('Location: /biosound/sedi.php?azienda_id=' . urlencode($sede['azienda_id']) . '&deleted=1');
            exit;
        } else {
            header('Location: /biosound/sede.php?id=' . urlencode($sede_id) . '&error=hasEmployees');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Modifica Sede</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --bg:#f0f2f5; --fg:#2e3a45; --radius:8px;
      --shadow:rgba(0,0,0,0.08); --font:'Segoe UI',sans-serif;
      --pri:#66bb6a; --err:#dc3545; --sec:#6c757d;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { background:var(--bg); color:var(--fg); font-family:var(--font); }
    .container { max-width:700px; margin:2rem auto; padding:0 1rem; }
    h1 { text-align:center; margin-bottom:1rem; }

    .toast {
      position:fixed; bottom:1rem; left:1rem;
      padding:.75rem 1.25rem; border-radius:var(--radius);
      color:#fff; opacity:1; transition:opacity .5s ease-out;
      z-index:1000;
    }
    .toast.success { background:var(--pri); }
    .toast.error   { background:var(--err); }

    form { display:flex; flex-direction:column; gap:1rem; }
    .form-group { display:flex; flex-direction:column; }
    label { margin-bottom:.5rem; font-weight:500; }
    input[type="text"], select {
      padding:.5rem .75rem; border:1px solid #ccc;
      border-radius:var(--radius); font-size:1rem; width:100%;
    }
    .actions { display:flex; justify-content:center; gap:2rem; margin-top:1.5rem; }
    .btn {
      padding:.6rem 1.2rem; border:none; border-radius:var(--radius);
      cursor:pointer; color:#fff; font-size:1rem; font-weight:bold;
      display:inline-flex; align-items:center; gap:.5rem;
      transition:background .2s,transform .15s;
    }
    .btn-secondary { background:var(--sec); }
    .btn-secondary:hover { background:#5a6268; transform:translateY(-2px); }
    .btn-primary { background:var(--pri); }
    .btn-primary:hover { background:#5aad5c; transform:translateY(-2px); }
    .btn-danger { background:var(--err); }
    .btn-danger:hover { background:#c9302c; transform:translateY(-2px); }

    .legal-options {
      position:relative; margin:1rem 0; text-align:center;
    }
    .legal-menu {
      position:absolute; top:100%; left:50%;
      transform:translateX(-50%); background:#fff;
      border:1px solid #ccc; border-radius:var(--radius);
      box-shadow:0 2px 6px var(--shadow); display:none;
      min-width:200px; z-index:10;
    }
    .legal-menu button {
      width:100%; padding:.5rem 1rem; background:none;
      border:none; text-align:left; cursor:pointer; color:var(--fg);
    }
    .legal-menu button:hover { background:var(--bg); }
  </style>
</head>
<body>
<?php
  $role = $_SESSION['role'] ?? 'utente';
  if ($role === 'admin') include 'navbar_a.php';
  elseif ($role === 'dev') include 'navbar_d.php';
  else include 'navbar.php';
?>
<div class="container">
  <h1>Modifica Sede di “<?=htmlspecialchars($sede['ragionesociale'],ENT_QUOTES)?>”</h1>

  <?php if ($updated): ?>
    <div id="toast" class="toast success">Sede aggiornata con successo.</div>
    <script>setTimeout(()=>document.getElementById('toast').style.opacity=0,2000);</script>
  <?php endif; ?>

  <form method="post" action="/biosound/sede.php?id=<?=urlencode($sede_id)?>">
    <div class="form-group">
      <label for="nome">Nome sede</label>
      <input id="nome" name="nome" type="text" required
             value="<?=htmlspecialchars($sede['nome'],ENT_QUOTES)?>">
    </div>
    <div class="form-group">
      <label for="indirizzo">Indirizzo</label>
      <input id="indirizzo" name="indirizzo" type="text" required
             value="<?=htmlspecialchars($sede['indirizzo'],ENT_QUOTES)?>">
    </div>

    <?php if ($sede['is_legale'] && count($otherSedi)>0): ?>
      <div class="legal-options">
        <button type="button" id="legalMenuBtn" class="btn btn-secondary">
          <i class="bi bi-gear"></i> Opzioni Sede Legale
        </button>
        <div id="legalMenu" class="legal-menu">
          <button type="button" id="optChange">Cambia sede legale</button>
          <button type="button" id="optDelete">Elimina sede legale</button>
        </div>
      </div>
    <?php endif; ?>

    <div class="actions">
      <a href="/biosound/sedi.php?azienda_id=<?=urlencode($sede['azienda_id'])?>"
         class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Indietro
      </a>
      <button type="submit" name="update" class="btn btn-primary">
        <i class="bi bi-pencil"></i> Salva modifiche
      </button>
      <?php if ($dipCount===0 && !$sede['is_legale']): ?>
        <button type="submit" name="delete" class="btn btn-danger"
                onclick="return confirm('Eliminare questa sede?');">
          <i class="bi bi-trash"></i> Elimina sede
        </button>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($sede['is_legale'] && count($otherSedi)>0): ?>
  <!-- cambio sede legale -->
  <form id="changeLegalForm" method="post" style="display:none; margin-top:1rem;">
    <div class="form-group">
      <label for="newLegal">Nuova sede legale:</label>
      <select id="newLegal" name="new_legal" required>
        <option value="">— seleziona —</option>
        <?php foreach($otherSedi as $os): ?>
          <option value="<?=htmlspecialchars($os['id'],ENT_QUOTES)?>">
            <?=htmlspecialchars($os['nome'],ENT_QUOTES)?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions">
      <button type="submit" name="change_legal" class="btn btn-primary">
        <i class="bi bi-building"></i> Conferma cambio
      </button>
    </div>
  </form>

  <!-- elimina sede legale -->
  <form id="deleteLegalForm" method="post" style="display:none; margin-top:1rem;">
    <div class="form-group">
      <label for="reassignLegal">Riassegna prima di eliminare:</label>
      <select id="reassignLegal" name="reassign_legal" required>
        <option value="">— seleziona —</option>
        <?php foreach($otherSedi as $os): ?>
          <option value="<?=htmlspecialchars($os['id'],ENT_QUOTES)?>">
            <?=htmlspecialchars($os['nome'],ENT_QUOTES)?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions">
      <button type="submit" name="delete_legal" class="btn btn-danger">
        <i class="bi bi-trash"></i> Elimina sede legale
      </button>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
  function showErrorToast(msg) {
    const t = document.createElement('div');
    t.className = 'toast error'; t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(()=>t.style.opacity=0,2000);
  }

  const btn = document.getElementById('legalMenuBtn');
  const menu = document.getElementById('legalMenu');
  const hasOther = <?= json_encode(count($otherSedi)>0) ?>;

  if (btn && menu) {
    btn.addEventListener('click', ()=>{
      menu.style.display = menu.style.display==='block'?'none':'block';
    });
    document.addEventListener('click', e=>{
      if(!btn.contains(e.target)&&!menu.contains(e.target)){
        menu.style.display='none';
      }
    });
  }

  document.getElementById('optChange')?.addEventListener('click', ()=>{
    document.getElementById('changeLegalForm').style.display='block';
    document.getElementById('deleteLegalForm').style.display='none';
    menu.style.display='none';
  });
  document.getElementById('optDelete')?.addEventListener('click', ()=>{
    if(!hasOther){
      showErrorToast('Impossibile eliminare sede legale senza riassegnarla');
    } else {
      document.getElementById('deleteLegalForm').style.display='block';
      document.getElementById('changeLegalForm').style.display='none';
      menu.style.display='none';
    }
  });
</script>
</body>
</html>
