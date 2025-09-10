<?php
// apri_corso.php — riapre un'attività e rimuove gli attestati generati per essa
require_once __DIR__ . '/init.php';

// ================= Util =================
function rrmdir_safe(string $dir): void {
  if (!is_dir($dir)) return;
  $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
  $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $f) {
    if ($f->isDir()) { @rmdir($f->getRealPath()); }
    else { @unlink($f->getRealPath()); }
  }
  @rmdir($dir);
}

// ================= Parametri =================
$id = $_GET['id'] ?? '';
if ($id === '') {
  header('Location: ./attivitae.php');
  exit;
}

// ================= Pagina di conferma =================
if (!isset($_GET['confirm'])) {
  ?>
  <!DOCTYPE html>
  <html lang="it">
  <head>
    <meta charset="UTF-8">
    <title>Riapri attività</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      :root{--bg:#f0f2f5;--fg:#2e3a45;--pri:#66bb6a;--err:#d9534f;--radius:10px;--shadow:0 10px 30px rgba(0,0,0,.08)}
      *{box-sizing:border-box}
      body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
      .wrap{max-width:680px;margin:10vh auto;padding:2rem;background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);text-align:center}
      h1{margin:0 0 .75rem}
      p{margin:.25rem 0 1.25rem;color:#4b5b68}
      .note{background:#fff6f6;border:1px solid #ffd1d1;color:#7d2b2b;padding:.75rem 1rem;border-radius:9px;margin:1rem 0}
      .actions{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap}
      a.btn,button.btn{display:inline-flex;align-items:center;gap:.5rem;border:0;padding:.7rem 1.1rem;border-radius:9px;text-decoration:none;color:#fff;cursor:pointer;font-weight:600}
      .btn-primary{background:var(--err)}
      .btn-secondary{background:#6c757d}
      .btn-primary:hover,.btn-secondary:hover{filter:brightness(0.95)}
      code{background:#f6f8fa;padding:.1rem .4rem;border-radius:6px}
    </style>
  </head>
  <body>
    <div class="wrap">
      <h1>Riaprire l’attività <code><?= htmlspecialchars($id,ENT_QUOTES) ?></code>?</h1>
      <p>Se prosegui, l’attività verrà riaperta</p>
      <div class="note">
        Verranno anche <strong>eliminati tutti gli attestati</strong> già generati per questa attività,
        inclusi i relativi PDF salvati a disco.
      </div>
      <div class="actions">
        <a class="btn btn-secondary" href="./attivitae_chiuse.php">
          <i class="bi bi-arrow-left"></i> Annulla
        </a>
        <a class="btn btn-primary" href="./apri_corso.php?id=<?= urlencode($id) ?>&confirm=1">
          <i class="bi bi-unlock"></i> Sì, riapri ed elimina attestati
        </a>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// ================= Azione: riapertura + cancellazione attestati =================
$baseDir = __DIR__ . '/resources/attestati';

$pdo->beginTransaction();
try {
  // 1) Prendo tutti gli attestati legati a questa attività
  $attSel = $pdo->prepare('SELECT id FROM attestato WHERE attivita_id = ?');
  $attSel->execute([$id]);
  $attIds = $attSel->fetchAll(PDO::FETCH_COLUMN);

  // 2) Cancello i record attestato
  if (!empty($attIds)) {
    // elimina DB
    $placeholders = implode(',', array_fill(0, count($attIds), '?'));
    $del = $pdo->prepare("DELETE FROM attestato WHERE id IN ($placeholders)");
    $del->execute($attIds);
  }

  // 3) Riapro l’attività
  $upd = $pdo->prepare('UPDATE attivita SET chiuso = 0 WHERE id = ?');
  $upd->execute([$id]);

  $pdo->commit();

  // 4) Dopo il commit, cancello i PDF a disco (operazione I/O fuori transazione)
  if (!empty($attIds)) {
    foreach ($attIds as $attId) {
      $dir = $baseDir . '/' . $attId;
      rrmdir_safe($dir);
    }
  }

  header('Location: ./attivitae.php?opened=1');
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "<pre>Errore durante la riapertura attività e cancellazione attestati:\n" .
       htmlspecialchars($e->getMessage(), ENT_QUOTES) . "</pre>";
  exit;
}
