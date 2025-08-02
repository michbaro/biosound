<?php
// dipendenti.php — lista dei dipendenti con ricerca, filtri e contesto dinamico
include 'init.php';

// 1) Determina contesto e titolo
$azienda_id = $_GET['azienda_id'] ?? null;
$sede_id    = $_GET['sede_id']    ?? null;

if ($sede_id) {
    // contesto sede: prendi nome sede e ragione sociale azienda
    $stmt = $pdo->prepare("
      SELECT s.nome        AS sede_nome,
             a.ragionesociale AS azienda_nome
        FROM sede s
        JOIN azienda a ON a.id = s.azienda_id
       WHERE s.id = ?
    ");
    $stmt->execute([$sede_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        header('Location: /biosound/aziende.php');
        exit;
    }
    $contextName = "{$row['azienda_nome']} ({$row['sede_nome']})";
} elseif ($azienda_id) {
    // contesto azienda
    $stmt = $pdo->prepare("SELECT ragionesociale FROM azienda WHERE id = ?");
    $stmt->execute([$azienda_id]);
    $nome = $stmt->fetchColumn();
    if (!$nome) {
        header('Location: /biosound/aziende.php');
        exit;
    }
    $contextName = $nome;
} else {
    $contextName = 'Tutti i dipendenti';
}

// 2) Costruisci query principale
$params = [];
$sql = <<<SQL
  SELECT d.id, d.nome, d.cognome, d.codice_fiscale,
         a.ragionesociale AS azienda, s.nome AS sede
    FROM dipendente d
    LEFT JOIN dipendente_sede ds ON ds.dipendente_id = d.id
    LEFT JOIN sede s ON s.id = ds.sede_id
    LEFT JOIN azienda a ON a.id = s.azienda_id
SQL;

if ($sede_id) {
    $sql .= " WHERE s.id = ?";
    $params[] = $sede_id;
} elseif ($azienda_id) {
    $sql .= " WHERE a.id = ?";
    $params[] = $azienda_id;
}

$stmt = $pdo->prepare($sql . " ORDER BY d.cognome, d.nome");
$stmt->execute($params);
$dipendenti = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3) Dati per filtri
$aziendeList = $pdo->query("SELECT id, ragionesociale FROM azienda ORDER BY ragionesociale")
                   ->fetchAll(PDO::FETCH_ASSOC);
$sediAll     = $pdo->query("SELECT id, nome, azienda_id FROM sede ORDER BY nome")
                   ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Dipendenti</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg:#f0f2f5; --fg:#2e3a45; --radius:8px;
      --shadow:rgba(0,0,0,0.08); --font:'Segoe UI',sans-serif;
      --pri:#66bb6a; --err:#d9534f;
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{background:var(--bg);color:var(--fg);font-family:var(--font);}
    .container{max-width:900px;margin:2rem auto;padding:0 1rem;}
    h1{text-align:center;margin-bottom:1rem;}
    .context-title{font-size:1.2rem;text-align:center;margin-bottom:1rem;}
    .filter-area{display:flex;gap:1rem;margin-bottom:1rem;}
    .filter-area > div{flex:1;}
    .filter-area input, .filter-area select {
      width:100%;padding:.5rem .75rem;
      border:1px solid #ccc;border-radius:var(--radius);
      font-size:1rem;
    }
    .add-container{text-align:center;margin:1rem 0;}
    .add-btn{
      display:inline-flex;align-items:center;gap:.5rem;
      background:var(--pri);color:#fff;
      padding:.5rem 1rem;border-radius:var(--radius);
      text-decoration:none;transition:background .2s;
    }
    .add-btn:hover{background:#5aad5c;}
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
    .info .name{font-weight:bold;}
    .info span{color:#666;font-size:.9rem;}
    .actions a{
      background:none;border:none;color:var(--pri);
      font-size:1.2rem;cursor:pointer;text-decoration:none;
      transition:color .2s;
    }
    .actions a:hover{color:#5aad5c;}
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
    <h1>Dipendenti</h1>

    <?php if (count($dipendenti) > 0): ?>
      <div class="context-title"><?= htmlspecialchars($contextName) ?></div>
    <?php endif; ?>

    <div class="filter-area">
      <div>
        <input id="search-text" type="text" placeholder="Cerca CF, nome, cognome…">
      </div>
      <div style="display:flex;gap:.5rem;">
        <select id="filter-azienda">
          <option value="all">Tutte le aziende</option>
          <?php foreach($aziendeList as $a): ?>
            <option value="<?= $a['id'] ?>">
              <?= htmlspecialchars($a['ragionesociale'],ENT_QUOTES) ?>
            </option>
          <?php endforeach;?>
        </select>
        <select id="filter-sede">
          <option value="all">Tutte le sedi</option>
        </select>
      </div>
    </div>

    <div class="add-container">
      <?php if ($sede_id): ?>
        <a href="/biosound/aggiungi_dipendente.php?sede_id=<?= urlencode($sede_id) ?>"
           class="add-btn"><i class="bi bi-plus-lg"></i> Aggiungi Dipendente</a>
      <?php elseif ($azienda_id): ?>
        <a href="/biosound/aggiungi_dipendente.php?azienda_id=<?= urlencode($azienda_id) ?>"
           class="add-btn"><i class="bi bi-plus-lg"></i> Aggiungi Dipendente</a>
      <?php else: ?>
        <a href="/biosound/aggiungi_dipendente.php" class="add-btn">
          <i class="bi bi-plus-lg"></i> Aggiungi Dipendente
        </a>
      <?php endif; ?>
    </div>

    <?php if (empty($dipendenti)): ?>
      <p style="text-align:center;color:#666;">Nessun dipendente registrato.</p>
    <?php else: ?>
      <?php foreach($dipendenti as $d): ?>
        <div class="item"
             data-cf="<?= strtolower(htmlspecialchars($d['codice_fiscale'],ENT_QUOTES)) ?>"
             data-azi="<?= htmlspecialchars($d['azienda'],ENT_QUOTES) ?>"
             data-sed="<?= htmlspecialchars($d['sede'],ENT_QUOTES) ?>"
             data-text="<?= strtolower(htmlspecialchars($d['nome'].' '.$d['cognome'],ENT_QUOTES)) ?>">
          <div class="info">
            <span class="name"><?= htmlspecialchars("{$d['nome']} {$d['cognome']}",ENT_QUOTES) ?></span>
            <span><?= htmlspecialchars($d['codice_fiscale'],ENT_QUOTES) ?></span>
            <span><?= htmlspecialchars($d['azienda']   ?: '—',ENT_QUOTES) ?></span>
            <span><?= htmlspecialchars($d['sede']      ?: '—',ENT_QUOTES) ?></span>
          </div>
          <div class="actions">
            <a href="/biosound/dipendente.php?id=<?= urlencode($d['id']) ?>"
               title="Modifica"><i class="bi bi-pencil"></i></a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <script>
    const allSedi = <?= json_encode($sediAll, JSON_HEX_TAG) ?>;
    const aziFilter = document.getElementById('filter-azienda');
    const sedFilter = document.getElementById('filter-sede');
    const searchIn  = document.getElementById('search-text');
    const items     = document.querySelectorAll('.item');

    // popola sedi al cambio azienda
    aziFilter.addEventListener('change', () => {
      const azi = aziFilter.value;
      sedFilter.innerHTML = '<option value="all">Tutte le sedi</option>';
      allSedi.forEach(s => {
        if (azi === 'all' || s.azienda_id === azi) {
          const o = document.createElement('option');
          o.value = s.id;
          o.textContent = s.nome;
          sedFilter.appendChild(o);
        }
      });
      applyFilters();
    });

    // filtri
    function applyFilters() {
      const txt = searchIn.value.trim().toLowerCase();
      const azi = aziFilter.value, sed = sedFilter.value;
      items.forEach(it => {
        let ok = true;
        if (txt && !it.dataset.text.includes(txt) && !it.dataset.cf.includes(txt))
          ok = false;
        if (azi !== 'all' && it.dataset.azi !== azi)
          ok = false;
        if (sed !== 'all' && it.dataset.sed !== sed)
          ok = false;
        it.style.display = ok ? 'flex' : 'none';
      });
    }
    [searchIn, aziFilter, sedFilter].forEach(el =>
      el.addEventListener('input', applyFilters)
    );
  </script>
</body>
</html>
