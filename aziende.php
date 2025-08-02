<?php
// aziende.php — lista delle aziende con filtro e pulsanti azioni
include 'init.php';

// 1) Recupera elenco aziende
$aziende = $pdo
    ->query('SELECT id, ragionesociale FROM azienda ORDER BY ragionesociale')
    ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Elenco Aziende</title>
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
    <h1>Elenco Aziende</h1>

    <div class="add-container">
      <a href="/biosound/aggiungi_azienda.php" class="add-btn">
        <i class="bi bi-plus-lg"></i> Aggiungi Azienda
      </a>
    </div>

    <div class="filter-area">
      <input id="search-azienda" type="text" placeholder="Cerca per ragione sociale…">
    </div>

    <?php if (empty($aziende)): ?>
      <p style="text-align:center;color:#666;">Nessuna azienda registrata.</p>
    <?php else: foreach ($aziende as $a): ?>
      <div class="item"
           data-name="<?= strtolower(htmlspecialchars($a['ragionesociale'], ENT_QUOTES)) ?>">
        <div class="info">
          <span class="nome"><?= htmlspecialchars($a['ragionesociale'], ENT_QUOTES) ?></span>
        </div>
        <div class="actions">
          <!-- sedi -->
          <a href="/biosound/sedi.php?azienda_id=<?= urlencode($a['id']) ?>"
             class="icon-btn" title="Visualizza sedi">
            <i class="bi bi-building"></i>
          </a>
          <!-- dipendenti -->
          <a href="/biosound/dipendenti.php?azienda_id=<?= urlencode($a['id']) ?>"
             class="icon-btn" title="Visualizza dipendenti">
            <i class="bi bi-people"></i>
          </a>
          <!-- modifica -->
          <a href="/biosound/azienda.php?id=<?= urlencode($a['id']) ?>"
             class="icon-btn" title="Modifica">
            <i class="bi bi-pencil"></i>
          </a>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <script>
    const input = document.getElementById('search-azienda');
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
