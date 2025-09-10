<?php
// operatori.php — elenco + modali aggiungi/modifica/elimina in un unico file
require_once __DIR__ . '/init.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/* =======================
   Accesso: solo admin/dev
======================= */
if (($_SESSION['role'] ?? 'utente') === 'utente') {
  header('Location: ./index.php?unauthorized=1');
  exit;
}

/* =======================
   Toast via redirect
======================= */
$added   = isset($_GET['added']);
$updated = isset($_GET['updated']);
$deleted = isset($_GET['deleted']);

/* =======================
   CSRF
======================= */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/* =======================
   POST: create / update / delete
======================= */
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
      throw new RuntimeException('Token CSRF non valido.');
    }

    $action = $_POST['__action'] ?? '';
    if ($action === 'create') {
      $nome    = trim($_POST['nome'] ?? '');
      $cognome = trim($_POST['cognome'] ?? '');
      if ($nome === '' || $cognome === '') throw new RuntimeException('Compila Nome e Cognome.');

      $id = bin2hex(random_bytes(16));
      $st = $pdo->prepare('INSERT INTO operatore (id, nome, cognome) VALUES (?,?,?)');
      $st->execute([$id, $nome, $cognome]);

      header('Location: ./operatori.php?added=1');
      exit;
    }

    if ($action === 'update') {
      $id      = $_POST['id'] ?? '';
      $nome    = trim($_POST['nome'] ?? '');
      $cognome = trim($_POST['cognome'] ?? '');
      if ($id === '' || $nome === '' || $cognome === '') throw new RuntimeException('Dati mancanti.');

      $st = $pdo->prepare('UPDATE operatore SET nome=?, cognome=? WHERE id=?');
      $st->execute([$nome, $cognome, $id]);

      header('Location: ./operatori.php?updated=1');
      exit;
    }

    if ($action === 'delete') {
      $id = $_POST['id'] ?? '';
      if ($id === '') throw new RuntimeException('ID mancante.');
      $st = $pdo->prepare('DELETE FROM operatore WHERE id=?');
      $st->execute([$id]);

      header('Location: ./operatori.php?deleted=1');
      exit;
    }
  }
} catch (Throwable $e) {
  $_SESSION['__op_err__'] = $e->getMessage();
  header('Location: ./operatori.php?err=1');
  exit;
}

/* =======================
   Query elenco
======================= */
$operatori = $pdo
    ->query('SELECT id, nome, cognome FROM operatore ORDER BY cognome, nome')
    ->fetchAll(PDO::FETCH_ASSOC);

$flashErr = '';
if (!empty($_GET['err']) && !empty($_SESSION['__op_err__'])) {
  $flashErr = $_SESSION['__op_err__'];
  unset($_SESSION['__op_err__']);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Elenco Operatori</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f0f2f5; --fg:#2e3a45; --radius:10px; --shadow:rgba(0,0,0,.08);
      --font:'Segoe UI',system-ui,-apple-system,Roboto; --pri:#66bb6a; --muted:#6c757d; --err:#dc3545;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:var(--bg);color:var(--fg);font-family:var(--font)}
    .container{max-width:900px;margin:2rem auto;padding:0 1rem}
    h1{text-align:center;margin-bottom:1rem}

    /* Toast */
    #toast{
      position:fixed;bottom:1rem;left:1rem;background:var(--pri);color:#fff;
      padding:.75rem 1.25rem;border-radius:var(--radius);box-shadow:0 2px 6px var(--shadow);
      opacity:1;transition:opacity .5s ease-out;z-index:1000;
    }
    #toast.err{background:var(--err)}

    /* Buttons */
    .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.1rem;border:none;border-radius:var(--radius);
      color:#fff;background:var(--pri);cursor:pointer;text-decoration:none;font-weight:600;transition:transform .15s,opacity .2s;min-height:2.5rem}
    .btn:hover{transform:translateY(-2px);opacity:.95}
    .btn-secondary{background:#6c757d}
    .btn-danger{background:var(--err)}
    .btn-outline{background:#fff;color:var(--fg);border:1px solid #ccc}
    .btn-icon{background:none;border:none;color:var(--pri);font-size:1.25rem;cursor:pointer;text-decoration:none}
    .btn-icon:hover{color:#5aad5c}

    .add-container{text-align:center;margin-bottom:1rem}

    /* Cards */
    .item{background:#fff;border-radius:var(--radius);box-shadow:0 2px 6px var(--shadow);
      padding:1rem;margin-bottom:.75rem;display:flex;justify-content:space-between;align-items:center;transition:transform .15s,box-shadow .15s}
    .item:hover{transform:translateY(-2px);box-shadow:0 4px 12px var(--shadow)}
    .info{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}
    .muted{color:var(--muted);font-size:.9rem}
    .actions{display:flex;gap:.5rem;align-items:center}

    /* Modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:1100}
    .modal-overlay.open{display:flex}
    .modal{background:#fff;max-width:520px;width:100%;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15);padding:1rem 1rem 1.25rem}
    .modal header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem}
    .modal h2{font-size:1.25rem}
    .close-x{background:none;border:none;font-size:1.3rem;cursor:pointer;color:#444}

    form .form-group{display:flex;flex-direction:column;margin-bottom:1rem}
    form label{margin-bottom:.4rem;font-weight:600}
    form input[type="text"]{width:100%;padding:.6rem .75rem;border:1px solid #ccc;border-radius:10px;font-size:1rem;outline:none}
    .two-col{display:grid;grid-template-columns:1fr;gap:1rem} /* campi uno sotto l'altro come richiesto */
    .actions-bar{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.5rem;flex-wrap:wrap}
  </style>
</head>
<body>
<?php
  // navbar dinamica
  $role = $_SESSION['role'] ?? 'utente';
  if ($role === 'admin')      include 'navbar_a.php';
  elseif ($role === 'dev')    include 'navbar_d.php';
  else                        include 'navbar.php';
?>
<div class="container">
  <h1>Elenco Operatori</h1>

  <?php if ($added):   ?><div id="toast">Operatore aggiunto con successo!</div><?php endif; ?>
  <?php if ($updated): ?><div id="toast">Operatore aggiornato con successo!</div><?php endif; ?>
  <?php if ($deleted): ?><div id="toast">Operatore eliminato con successo!</div><?php endif; ?>
  <?php if ($flashErr):?><div id="toast" class="err"><?= htmlspecialchars($flashErr,ENT_QUOTES) ?></div><?php endif; ?>

  <div class="add-container">
    <button class="btn" id="open-add"><i class="bi bi-plus-lg"></i> Aggiungi Operatore</button>
  </div>

  <?php if (empty($operatori)): ?>
    <p style="text-align:center;color:#666;">Nessun operatore registrato.</p>
  <?php else: foreach ($operatori as $op): ?>
    <div class="item"
         data-id="<?= htmlspecialchars($op['id'],ENT_QUOTES) ?>"
         data-nome="<?= htmlspecialchars($op['nome'],ENT_QUOTES) ?>"
         data-cognome="<?= htmlspecialchars($op['cognome'],ENT_QUOTES) ?>">
      <div class="info">
        <strong><?= htmlspecialchars($op['cognome'] . ' ' . $op['nome'], ENT_QUOTES) ?></strong>
      </div>
      <div class="actions">
        <button class="btn-icon edit-op" title="Modifica"><i class="bi bi-pencil"></i></button>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<!-- MODAL: Aggiungi -->
<div class="modal-overlay" id="modal-add">
  <div class="modal">
    <header>
      <h2>Nuovo Operatore</h2>
      <button class="close-x" data-close="#modal-add">&times;</button>
    </header>
    <form method="post" id="form-add">
      <input type="hidden" name="__action" value="create">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf,ENT_QUOTES) ?>">
      <div class="two-col">
        <div class="form-group">
          <label for="add_nome">Nome *</label>
          <input type="text" id="add_nome" name="nome" required>
        </div>
        <div class="form-group">
          <label for="add_cognome">Cognome *</label>
          <input type="text" id="add_cognome" name="cognome" required>
        </div>
      </div>
      <div class="actions-bar">
        <button type="button" class="btn btn-secondary" data-close="#modal-add"><i class="bi bi-x"></i> Annulla</button>
        <button type="submit" class="btn"><i class="bi bi-save"></i> Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Modifica/Elimina -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal">
    <header>
      <h2>Modifica Operatore</h2>
      <button class="close-x" data-close="#modal-edit">&times;</button>
    </header>
    <form method="post" id="form-edit">
      <input type="hidden" name="__action" value="update">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf,ENT_QUOTES) ?>">
      <input type="hidden" name="id" id="edit_id">
      <div class="two-col">
        <div class="form-group">
          <label for="edit_nome">Nome *</label>
          <input type="text" id="edit_nome" name="nome" required>
        </div>
        <div class="form-group">
          <label for="edit_cognome">Cognome *</label>
          <input type="text" id="edit_cognome" name="cognome" required>
        </div>
      </div>
      <div class="actions-bar">
        <button type="button" class="btn btn-danger" id="btn-del"><i class="bi bi-trash"></i> Elimina</button>
        <button type="button" class="btn btn-secondary" data-close="#modal-edit"><i class="bi bi-x"></i> Annulla</button>
        <button type="submit" class="btn"><i class="bi bi-save"></i> Salva modifiche</button>
      </div>
    </form>
    <form method="post" id="form-delete" style="display:none">
      <input type="hidden" name="__action" value="delete">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf,ENT_QUOTES) ?>">
      <input type="hidden" name="id" id="del_id">
    </form>
  </div>
</div>

<script>
  // Toast auto-hide
  window.addEventListener('load',()=>{ const t=document.getElementById('toast'); if(t) setTimeout(()=>t.style.opacity='0',2000); });

  // Modal helpers
  function openModal(sel){ document.querySelector(sel).classList.add('open'); }
  function closeModal(sel){ document.querySelector(sel).classList.remove('open'); }
  document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=> closeModal(b.getAttribute('data-close'))));

  // Open add
  document.getElementById('open-add').addEventListener('click', ()=> {
    document.getElementById('add_nome').value='';
    document.getElementById('add_cognome').value='';
    openModal('#modal-add');
  });

  // Open edit with data
  document.querySelectorAll('.edit-op').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const it = btn.closest('.item');
      const id = it.dataset.id;
      const nome = it.dataset.nome || '';
      const cognome = it.dataset.cognome || '';
      document.getElementById('edit_id').value = id;
      document.getElementById('del_id').value  = id;
      document.getElementById('edit_nome').value = nome;
      document.getElementById('edit_cognome').value = cognome;
      openModal('#modal-edit');
    });
  });

  // Delete button
  document.getElementById('btn-del').addEventListener('click', ()=>{
    if (confirm('Eliminare definitivamente questo operatore?')) {
      document.getElementById('form-delete').submit();
    }
  });

  // Chiudi modal cliccando lo sfondo
  document.querySelectorAll('.modal-overlay').forEach(ov=>{
    ov.addEventListener('click', (e)=>{ if(e.target===ov) ov.classList.remove('open'); });
  });
</script>
</body>
</html>
