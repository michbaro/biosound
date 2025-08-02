<?php
// operatori.php — elenco operatori (senza filtri)
include 'init.php';

// 1) Se l’utente loggato NON è admin o dev, blocca l’accesso
if (($_SESSION['role'] ?? 'utente') === 'utente') {
    // 2) Redirect a index con flag per il toast
    header('Location: /biosound/index.php?unauthorized=1');
    exit;
}

$added   = isset($_GET['added']);
$deleted = isset($_GET['deleted']);

$operatori = $pdo
    ->query('SELECT id, nome, cognome FROM operatore ORDER BY cognome, nome')
    ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Elenco Operatori</title>
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
    #toast{
      position:fixed;bottom:1rem;left:1rem;
      background:var(--pri);color:#fff;
      padding:.75rem 1.25rem;border-radius:var(--radius);
      box-shadow:0 2px 6px var(--shadow);
      opacity:1;transition:opacity .5s ease-out;
      z-index:1000;
    }
    .add-container{text-align:center;margin-bottom:1rem;}
    .add-btn{
      display:inline-flex;align-items:center;gap:.5rem;
      background:var(--pri);color:#fff;
      padding:.5rem 1rem;border-radius:var(--radius);
      text-decoration:none;transition:background .2s,transform .2s;
    }
    .add-btn:hover{background:#5aad5c;transform:translateY(-2px);}

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
    .info .id{font-weight:bold;}
    .info span{color:#666;font-size:.9rem;}
    .actions {
      display:flex;gap:.5rem;align-items:center;
    }
    .icon-btn{
      background:none;border:none;color:var(--pri);
      font-size:1.2rem;cursor:pointer;transition:color .2s;
      text-decoration:none;
    }
    .icon-btn:hover{color:#5aad5c;}
    .icon-btn button {
      background:none;
      border:none;
      padding:0;
      font: inherit;
      color:inherit;
      cursor:pointer;
    }
  </style>
</head>
<body>
  <?php
    // include dinamico della navbar in base al ruolo
    $role = $_SESSION['role'] ?? 'utente';
    if ($role === 'admin') {
      include 'navbar_a.php';
    } elseif ($role === 'dev') {
      include 'navbar_d.php';
    } else {
      include 'navbar.php';
    }
  ?>
  <div class="container">
    <h1>Elenco Operatori</h1>

    <?php if ($added): ?>
      <div id="toast">Operatore aggiunto con successo!</div>
    <?php endif; ?>
    <?php if ($deleted): ?>
      <div id="toast">Operatore eliminato con successo!</div>
    <?php endif; ?>

    <div class="add-container">
      <a href="/biosound/aggiungi_operatore.php" class="add-btn">
        <i class="bi bi-plus-lg"></i> Aggiungi Operatore
      </a>
    </div>

    <?php if (empty($operatori)): ?>
      <p style="text-align:center;color:#666;">Nessun operatore registrato.</p>
    <?php else: ?>
      <?php foreach ($operatori as $op): ?>
        <div class="item">
          <div class="info">
            <span><?= htmlspecialchars($op['nome'] . ' ' . $op['cognome'], ENT_QUOTES) ?></span>
          </div>
          <div class="actions">
            <!-- Modifica -->
            <a href="/biosound/operatore.php?id=<?= urlencode($op['id']) ?>"
               class="icon-btn"
               title="Modifica Operatore">
              <i class="bi bi-pencil"></i>
            </a>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <script>
    window.addEventListener('load', () => {
      const t = document.getElementById('toast');
      if (t) setTimeout(() => t.style.opacity = '0', 2000);
    });
  </script>
</body>
</html>
