<?php
include 'init.php';
$added = isset($_GET['added']);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Elenco Corsi</title>
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
    .course-list .item{background:#fff;border-radius:var(--radius);
      box-shadow:0 2px 6px var(--shadow);padding:.75rem 1rem;margin-bottom:.75rem;
      display:flex;justify-content:space-between;align-items:center;
      transition:transform .15s,box-shadow .15s;}
    .course-list .item:hover{transform:translateY(-2px);
      box-shadow:0 4px 12px var(--shadow);}
    .title{font-size:1rem;font-weight:500;}
    .icon-btn{color:var(--green);font-size:1.2rem;transition:color .2s,transform .2s;
      background:none;border:none;text-decoration:none;}
    .icon-btn:hover{color:#5aad5c;transform:scale(1.1);}
    .alert{text-align:center;margin-top:2rem;}
    /* Toast */
    #toast{position:fixed;bottom:1rem;left:1rem;
      background:var(--green);color:#fff;padding:.75rem 1.25rem;
      border-radius:var(--radius);box-shadow:0 2px 6px var(--shadow);
      opacity:1;transition:opacity .5s ease-out;z-index:1000;}
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
  <div class="container">
    <h1>Elenco Corsi</h1>

    <?php if ($added): ?>
      <div id="toast">Corso aggiunto con successo!</div>
    <?php endif; ?>

    <div style="text-align:center;">
      <a href="/biosound/aggiungi_corso.php" class="add-btn">
        <i class="bi bi-plus-lg"></i> Aggiungi Corso
      </a>
    </div>

    <input type="text" id="search" placeholder="Cerca corsi...">

    <?php
      $stmt  = $pdo->query('SELECT id, titolo FROM Corso');
      $corsi = $stmt->fetchAll();
    ?>

    <?php if (empty($corsi)): ?>
      <div class="alert">Non ci sono corsi registrati.</div>
    <?php else: ?>
      <div class="course-list">
        <?php foreach ($corsi as $c): ?>
          <div class="item">
            <span class="title"><?= htmlspecialchars($c['titolo'], ENT_QUOTES) ?></span>
            <a href="/biosound/corso.php?id=<?= urlencode($c['id']) ?>"
               class="icon-btn" title="Vedi dettagli">
              <i class="bi bi-search"></i>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <script>
    document.getElementById('search').addEventListener('input', function() {
      const f = this.value.toLowerCase();
      document.querySelectorAll('.course-list .item').forEach(item => {
        item.style.display =
          item.querySelector('.title').textContent.toLowerCase().includes(f)
            ? 'flex' : 'none';
      });
    });
    window.addEventListener('load', () => {
      const t = document.getElementById('toast');
      if (t) setTimeout(() => t.style.opacity = '0', 2000);
    });
  </script>
</body>
</html>
