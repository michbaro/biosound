<?php
// sedi.php — elenco sedi di una data azienda
include 'init.php';

// 2) Recupera azienda_id o redirect
$azienda_id = $_GET['azienda_id'] ?? '';
if (!$azienda_id) {
    header('Location: /biosound/aziende.php');
    exit;
}

// 3) Carica ragione sociale azienda
$stmtA = $pdo->prepare('SELECT ragionesociale FROM azienda WHERE id = ?');
$stmtA->execute([$azienda_id]);
$ragione = $stmtA->fetchColumn() ?: '';

// 4) Carica elenco sedi
$stmt = $pdo->prepare('
    SELECT id, nome, indirizzo
      FROM sede
     WHERE azienda_id = ?
     ORDER BY nome
');
$stmt->execute([$azienda_id]);
$sedi = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Sedi di <?= htmlspecialchars($ragione,ENT_QUOTES) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg:#f0f2f5; --fg:#2e3a45; --radius:8px;
      --shadow:rgba(0,0,0,0.08); --font:'Segoe UI',sans-serif;
      --pri:#66bb6a;
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{background:var(--bg);color:var(--fg);font-family:var(--font);}
    .container{max-width:900px;margin:2rem auto;padding:0 1rem;}
    h1{text-align:center;margin-bottom:1rem;}
    .add-container{text-align:center;margin-bottom:1rem;}
    .add-btn{
      display:inline-flex;align-items:center;gap:.5rem;
      background:var(--pri);color:#fff;
      padding:.5rem 1rem;border-radius:var(--radius);
      text-decoration:none;transition:background .2s,transform .2s;
    }
    .add-btn:hover{background:#5aad5c;transform:translateY(-2px);}
    .filter-area { margin-bottom:1rem; }
    .filter-area input {
      width:100%; height:2.5rem;
      padding:.5rem .75rem;
      border:1px solid #ccc;border-radius:var(--radius);
      font-size:1rem;
    }
    .item{
      background:#fff;border-radius:var(--radius);
      box-shadow:0 2px 6px var(--shadow);
      padding:1rem;margin-bottom:.75rem;
      display:flex;justify-content:space-between;align-items:center;
      transition:transform .15s,box-shadow .15s;
    }
    .item:hover{
      transform:translateY(-2px);
      box-shadow:0 4px 12px var(--shadow);
    }
    .info{display:flex;gap:1rem;align-items:center;flex-wrap:wrap;}
    .info .nome{font-weight:bold;}
    .info .indirizzo { color:#666; font-size:.9rem; }
    .actions{display:flex;gap:.5rem;}
    .icon-btn{
      background:none;border:none;color:var(--pri);
      font-size:1.2rem;cursor:pointer;transition:color .2s;
      text-decoration:none;
    }
    .icon-btn:hover{color:#5aad5c;}
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
    <h1>Sedi di “<?= htmlspecialchars($ragione,ENT_QUOTES) ?>”</h1>

    <div class="add-container">
      <a href="/biosound/aggiungi_sede.php?azienda_id=<?= urlencode($azienda_id) ?>"
         class="add-btn">
        <i class="bi bi-plus-lg"></i> Aggiungi Sede
      </a>
    </div>

    <div class="filter-area">
      <input id="search-sede" type="text" placeholder="Cerca per nome sede…">
    </div>

    <?php if (empty($sedi)): ?>
      <p style="text-align:center;color:#666;">Nessuna sede registrata per questa azienda.</p>
    <?php else: foreach ($sedi as $s): ?>
      <div class="item"
           data-name="<?= strtolower(htmlspecialchars($s['nome'],ENT_QUOTES)) ?>">
        <div class="info">
          <span class="nome"><?= htmlspecialchars($s['nome'],ENT_QUOTES) ?></span>
          <span class="indirizzo"><?= htmlspecialchars($s['indirizzo'],ENT_QUOTES) ?></span>
        </div>
        <div class="actions">
          <!-- dipendenti -->
          <a href="/biosound/dipendenti.php?sede_id=<?= urlencode($s['id']) ?>"
             class="icon-btn" title="Dipendenti">
            <i class="bi bi-people"></i>
          </a>
          <!-- modifica -->
          <a href="/biosound/sede.php?id=<?= urlencode($s['id']) ?>"
             class="icon-btn" title="Modifica">
            <i class="bi bi-pencil"></i>
          </a>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <script>
    const input = document.getElementById('search-sede');
    const items = document.querySelectorAll('.item');
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      items.forEach(item => {
        item.style.display = item.dataset.name.includes(q) ? 'flex' : 'none';
      });
    });
  </script>
</body>
</html>
