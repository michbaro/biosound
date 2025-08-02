<?php
include 'init.php';    // must come before any HTML
$added = isset($_GET['added']);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Elenco Docenti</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --green:       #66bb6a;
      --bg:          #f0f2f5;
      --fg:          #2e3a45;
      --radius:      8px;
      --shadow:      rgba(0,0,0,0.08);
      --font:        'Segoe UI',sans-serif;
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{background:var(--bg);color:var(--fg);font-family:var(--font);}
    .container{max-width:600px;margin:2rem auto;padding:0 1rem;}
    h1{text-align:center;margin-bottom:1rem;}
    #search{width:100%;padding:.5rem .75rem;border:1px solid #ccc;
      border-radius:var(--radius);margin-bottom:1.5rem;font-size:1rem;}
    .add-btn{display:inline-flex;align-items:center;gap:.5rem;
      background:var(--green);color:#fff;padding:.5rem 1rem;border-radius:var(--radius);
      text-decoration:none;font-size:1rem;transition:background .2s,transform .2s;
      margin-bottom:1rem;border:none;}
    .add-btn:hover{background:#5aad5c;transform:translateY(-2px);}
    .item-list .item{background:#fff;border-radius:var(--radius);
      box-shadow:0 2px 6px var(--shadow);padding:.75rem 1rem;
      margin-bottom:.75rem;display:flex;justify-content:space-between;
      align-items:center;transition:transform .15s,box-shadow .15s;}
    .item-list .item:hover{transform:translateY(-2px);
      box-shadow:0 4px 12px var(--shadow);}
    .title{font-size:1rem;font-weight:500;}
    .icon-btn{color:var(--green);font-size:1.2rem;transition:color .2s,transform .2s;
      background:none;border:none;text-decoration:none;}
    .icon-btn:hover{color:#5aad5c;transform:scale(1.1);}
    .alert{text-align:center;margin-top:2rem;}
    /* Toast */
#toast {
  position: fixed;
  bottom: 1rem;
  left: 1rem;
  padding: .75rem 1.25rem;
  border-radius: var(--radius);
  box-shadow: 0 2px 6px var(--shadow);
  z-index: 1000;
}

.alert-success {
  background: #d4edda;
  color: #155724;
}

  </style>
</head>
<body>

<?php
  $role = $_SESSION['role'] ?? 'utente';
  switch ($role) {
    case 'admin':
      include 'navbar_a.php';
      break;
    case 'dev':
      include 'navbar_d.php';
      break;
    default:
      include 'navbar.php';
  }
?>

<?php if (isset($_GET['updated'])): ?>
  <div id="toast" class="alert alert-success">
    Docente aggiornato con successo!
  </div>
<?php endif; ?>

  <div class="container">
    <h1>Elenco Docenti</h1>

    <?php if ($added): ?>
      <div id="toast">Docente aggiunto con successo!</div>
    <?php endif; ?>

    <div style="text-align:center;">
      <a href="/biosound/aggiungi_docente.php" class="add-btn">
        <i class="bi bi-plus-lg"></i> Aggiungi Docente
      </a>
    </div>

    <input type="text" id="search" placeholder="Cerca docenti...">

    <?php
      $stmt    = $pdo->query('SELECT id, nome, cognome FROM docente ORDER BY cognome, nome');
      $docenti = $stmt->fetchAll();
    ?>

    <?php if (empty($docenti)): ?>
      <div class="alert">Non ci sono docenti registrati.</div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($docenti as $d): ?>
          <div class="item">
            <span class="title">
              <?= htmlspecialchars($d['cognome'], ENT_QUOTES) ?>
              <?= htmlspecialchars($d['nome'], ENT_QUOTES) ?>
            </span>
            <a href="/biosound/docente.php?id=<?= urlencode($d['id']) ?>"
               class="icon-btn" title="Vedi dettagli">
              <i class="bi bi-search"></i>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <script>
    // filtro client-side
    document.getElementById('search').addEventListener('input', function() {
      const f = this.value.toLowerCase();
      document.querySelectorAll('.item-list .item').forEach(item => {
        item.style.display =
          item.querySelector('.title').textContent.toLowerCase().includes(f)
            ? 'flex' : 'none';
      });
    });
    // toast fade-out
    window.addEventListener('load', () => {
      const t = document.getElementById('toast');
      if (t) setTimeout(() => t.style.opacity = '0', 2000);
    });
  </script>
</body>
</html>
