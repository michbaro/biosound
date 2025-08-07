<?php
// azienda.php — visualizzazione, modifica, eliminazione azienda + upload allegati
include 'init.php';

// 1) AJAX single‐file delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteSingle'])) {
    header('Content-Type: application/json');
    $id      = $_GET['id'] ?? '';
    $row = $pdo->prepare('SELECT allegati FROM azienda WHERE id = ?');
    $row->execute([$id]);
    $allegati = json_decode($row->fetchColumn(), true) ?: [];
    $allegati = array_values(array_diff($allegati, [$_POST['deleteSingle']]));
    $pdo->prepare('UPDATE azienda SET allegati = ? WHERE id = ?')
         ->execute([ json_encode($allegati), $id ]);
    $full = __DIR__ . '/' . $_POST['deleteSingle'];
    if (file_exists($full)) unlink($full);
    echo json_encode(['ok'=>true]);
    exit;
}

// 2) Recupera ID o redirect
$id = $_GET['id'] ?? '';
if (!$id) {
    header('Location: /biosound/aziende.php');
    exit;
}

// 3) Carica dati azienda (includendo allegati e i nuovi campi)
$stmt = $pdo->prepare('
    SELECT
      ragionesociale,
      piva,
      ateco,
      email,
      legalerappresentante,
      nomereferente,
      emailreferente,
      numeroreferente,
      sdi,
      allegati
    FROM azienda
    WHERE id = ?
');
$stmt->execute([$id]);
$azienda = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$azienda) {
    header('Location: /biosound/aziende.php');
    exit;
}
$allegati = json_decode($azienda['allegati'], true) ?: [];

// 4) Verifica dipendenti collegati per delete
$check = $pdo->prepare(<<<'SQL'
SELECT COUNT(*) 
  FROM sede s
  JOIN dipendente_sede ds ON ds.sede_id = s.id
 WHERE s.azienda_id = ?
SQL
);
$check->execute([$id]);
$hasEmployees = ((int)$check->fetchColumn() > 0);

// 5) Gestione POST
$updated        = false;
$errorDuplicate = false;
$errorHasEmp    = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // DELETE
    if (isset($_POST['delete'])) {
        if ($hasEmployees) {
            $errorHasEmp = true;
        } else {
            // elimina cartella allegati
            $dir = __DIR__ . "/resources/aziende/{$id}/";
            if (is_dir($dir)) {
                $it = new RecursiveIteratorIterator(
                  new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                  RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($dir);
            }
            $pdo->prepare('DELETE FROM azienda WHERE id = ?')->execute([$id]);
            header('Location: /biosound/aziende.php?deleted=1');
            exit;
        }
    }

    // UPDATE azienda + allegati + nuovi campi referente
    if (isset($_POST['update'])) {
        $rs   = trim($_POST['ragionesociale'] ?? '');
        $piva = trim($_POST['piva'] ?? '');
        $ateco= trim($_POST['ateco'] ?? '')               ?: null;
        $email= trim($_POST['email'] ?? '')               ?: null;
        $sdi  = trim($_POST['sdi'] ?? '')                 ?: null;
        $leg  = trim($_POST['legalerappresentante'] ?? '')?: null;
        $nome = trim($_POST['nomereferente'] ?? '')       ?: null;
        $eref = trim($_POST['emailreferente'] ?? '')      ?: null;
        $nref = trim($_POST['numeroreferente'] ?? '')     ?: null;

        // verifica duplicato P.IVA
        $dup = $pdo->prepare('SELECT COUNT(*) FROM azienda WHERE piva = ? AND id <> ?');
        $dup->execute([$piva, $id]);
        if ((int)$dup->fetchColumn() > 0) {
            $errorDuplicate = true;
        } else {
            // folder per allegati
            $dir = __DIR__ . "/resources/aziende/{$id}/";
            $web = "resources/aziende/{$id}/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // replace esistenti
            if (!empty($_FILES['replace']['name'])) {
                foreach ($_FILES['replace']['name'] as $i => $name) {
                    if ($_FILES['replace']['error'][$i] === UPLOAD_ERR_OK) {
                        $old = __DIR__ . '/' . $allegati[$i];
                        if (file_exists($old)) unlink($old);
                        move_uploaded_file($_FILES['replace']['tmp_name'][$i], $dir . basename($name));
                        $allegati[$i] = $web . basename($name);
                    }
                }
            }
            // nuovi upload
            if (!empty($_FILES['newfile']['name'][0])) {
                foreach ($_FILES['newfile']['name'] as $j => $name) {
                    if ($_FILES['newfile']['error'][$j] === UPLOAD_ERR_OK) {
                        move_uploaded_file($_FILES['newfile']['tmp_name'][$j], $dir . basename($name));
                        $allegati[] = $web . basename($name);
                    }
                }
            }

            // salva su DB
            $pdo->prepare('
              UPDATE azienda SET
                ragionesociale       = ?,
                piva                 = ?,
                ateco                = ?,
                email                = ?,
                legalerappresentante = ?,
                nomereferente        = ?,
                emailreferente       = ?,
                numeroreferente      = ?,
                sdi                  = ?,
                allegati             = ?
              WHERE id = ?
            ')->execute([
                $rs,$piva,$ateco,$email,
                $leg,$nome,$eref,$nref,
                $sdi,json_encode($allegati),$id
            ]);

            $updated = true;
            header("Location: /biosound/azienda.php?id=".urlencode($id)."&updated=1");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Modifica Azienda</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root {
      --bg: #f0f2f5; --fg: #2e3a45; --radius: 8px;
      --shadow: rgba(0,0,0,0.08); --font: 'Segoe UI', sans-serif;
      --pri: #28a745; --err: #dc3545;
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{background:var(--bg);color:var(--fg);font-family:var(--font);}
    .container{max-width:700px;margin:2rem auto;padding:0 1rem;}
    h1{text-align:center;margin-bottom:1rem;}
    .alert {
      position:fixed;bottom:1rem;left:1rem;
      padding:.75rem 1.25rem;border-radius:var(--radius);
      box-shadow:0 2px 6px var(--shadow);color:#fff;
      opacity:1;transition:opacity .5s ease-out;z-index:1000;
    }
    .alert-success{background:var(--pri);}
    .alert-danger{background:var(--err);}

    form{display:flex;flex-direction:column;gap:1.25rem;}
    .form-group{display:flex;flex-direction:column;}
    label{margin-bottom:.5rem;font-weight:500;}
    input[type="text"],
    input[type="email"]{
      padding:.5rem .75rem;border:1px solid #ccc;
      border-radius:var(--radius);font-size:1rem;width:100%;
    }

    .actions{display:flex;justify-content:center;gap:2rem;margin-top:1.5rem;}
    .btn{padding:.6rem 1.2rem;border:none;border-radius:var(--radius);
      cursor:pointer;color:#fff;font-size:1rem;font-weight:bold;
      display:inline-flex;align-items:center;gap:.75rem;
      transition:background .2s,transform .15s;}
    .btn-secondary{background:#6c757d;}
    .btn-secondary:hover{background:#5a6268;transform:translateY(-2px);}
    .btn-primary{background:var(--pri);}
    .btn-primary:hover{background:#218838;transform:translateY(-2px);}
    .btn-danger{background:var(--err);}
    .btn-danger:hover{background:#c82333;transform:translateY(-2px);}

    /* stile upload preso da corso.php */
    :root{--green-soft:#e8f5e9;--green-dark:#2e7d32;}
    .pdf-action-item.upload{
      display:inline-flex;align-items:center;gap:.75rem;
      background:var(--green-soft);border-radius:6px;
      padding:.6rem 1rem;cursor:pointer;
      transition:background .2s,transform .1s;margin-top:1rem;
    }
    .pdf-action-item.upload input{display:none;}
    .pdf-action-item.upload label{
      display:inline-flex;align-items:center;gap:.75rem;
      font-weight:600;color:var(--green-dark);cursor:pointer;
    }
    .file-name{
      margin-top:4px;font-size:.85rem;color:#555;
      font-style:italic;
    }

    /* lista allegati */
    .allegati{list-style:none;padding:0;margin:0;}
    .allegati li{background:#fff;border-radius:var(--radius);
      box-shadow:0 2px 6px var(--shadow);padding:.75rem 1rem;
      display:flex;justify-content:space-between;align-items:center;
      margin-bottom:.5rem;}
    .allegati .icons i,
    .allegati .icons label{cursor:pointer;font-size:1.2rem;margin-left:.5rem;color:var(--pri);}
    .allegati .icons .delete{color:var(--err);}
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
    <h1>Modifica Azienda</h1>

    <?php if ($updated): ?>
      <div class="alert alert-success" id="toast">
        Azienda aggiornata con successo!
      </div>
      <script>setTimeout(()=>document.getElementById('toast').style.opacity='0',2000);</script>
    <?php endif; ?>

    <?php if ($errorDuplicate): ?>
      <div class="alert alert-danger" id="toast">
        Partita IVA già esistente!
      </div>
      <script>setTimeout(()=>document.getElementById('toast').style.opacity='0',2000);</script>
    <?php endif; ?>

    <?php if ($errorHasEmp): ?>
      <div class="alert alert-danger" id="toast">
        Impossibile eliminare: ci sono dipendenti iscritti a sedi di questa azienda.
      </div>
      <script>setTimeout(()=>document.getElementById('toast').style.opacity='0',2000);</script>
    <?php endif; ?>

    <form method="post" action="/biosound/azienda.php?id=<?=urlencode($id)?>"
          enctype="multipart/form-data">
      <input type="hidden" name="MAX_FILE_SIZE" value="10485760">

      <div class="form-group">
        <label for="ragionesociale">Ragione Sociale *</label>
        <input id="ragionesociale" name="ragionesociale" type="text" required
               value="<?=htmlspecialchars($azienda['ragionesociale'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="piva">Partita IVA *</label>
        <input id="piva" name="piva" type="text" maxlength="11" required
               value="<?=htmlspecialchars($azienda['piva'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="ateco">Codice ATECO</label>
        <input id="ateco" name="ateco" type="text"
               value="<?=htmlspecialchars($azienda['ateco'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input id="email" name="email" type="email"
               value="<?=htmlspecialchars($azienda['email'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="sdi">SDI/PEC</label>
        <input id="sdi" name="sdi" type="text"
               value="<?=htmlspecialchars($azienda['sdi'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="legalerappresentante">Legale Rappresentante</label>
        <input id="legalerappresentante" name="legalerappresentante" type="text"
               value="<?=htmlspecialchars($azienda['legalerappresentante'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="nomereferente">Nome Referente</label>
        <input id="nomereferente" name="nomereferente" type="text"
               value="<?=htmlspecialchars($azienda['nomereferente'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="emailreferente">Email Referente</label>
        <input id="emailreferente" name="emailreferente" type="email"
               value="<?=htmlspecialchars($azienda['emailreferente'],ENT_QUOTES)?>">
      </div>
      <div class="form-group">
        <label for="numeroreferente">Numero Telefonico Referente</label>
        <input id="numeroreferente" name="numeroreferente" type="text"
               value="<?=htmlspecialchars($azienda['numeroreferente'],ENT_QUOTES)?>">
      </div>

      <h2>Allegati</h2>

      <!-- upload stile corso.php -->
      <div class="pdf-action-item upload">
        <input id="uploadDoc" name="newfile[]" type="file" accept="application/pdf">
        <label for="uploadDoc"><i class="bi bi-upload"></i> Aggiungi Documento</label>
        <div id="uploadDoc-filename" class="file-name"></div>
      </div>

      <?php if (count($allegati) > 0): ?>
      <ul class="allegati" id="allegatiList">
        <?php foreach($allegati as $i=>$path):
          $name = basename($path);
        ?>
        <li data-index="<?=$i?>">
          <span class="file-name">
            <a href="/biosound/<?=$path?>" target="_blank"><?=$name?></a>
          </span>
          <span class="icons">
            <label for="rep<?=$i?>"><i class="bi bi-pencil-fill"></i></label>
            <input type="file" name="replace[<?=$i?>]" id="rep<?=$i?>"
                   style="display:none" accept="application/pdf">
            <i class="bi bi-trash-fill delete"
               onclick="markDelete('<?=$path?>',<?=$i?>)"></i>
          </span>
        </li>
        <?php endforeach;?>
      </ul>
      <?php endif; ?>

      <div class="actions">
        <a href="/biosound/aziende.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Annulla
        </a>
        <button type="submit" name="update" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva
        </button>
        <button type="submit" name="delete" class="btn btn-danger"
                onclick="return confirm('Eliminare questa azienda?');">
          <i class="bi bi-trash-fill"></i> Elimina
        </button>
      </div>
    </form>
  </div>

  <script>
    // AJAX single‐file delete
    function markDelete(path,i){
      if(!confirm('Confermi eliminazione di questo file?')) return;
      fetch(location.href,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'deleteSingle='+encodeURIComponent(path)
      })
      .then(r=>r.json())
      .then(res=>{
        if(res.ok){
          document.querySelector(`#allegatiList li[data-index="${i}"]`).remove();
        }
      });
    }

    // mostra nome del file selezionato
    const uploadInput = document.getElementById('uploadDoc');
    const uploadNameDiv = document.getElementById('uploadDoc-filename');
    uploadInput.addEventListener('change', () => {
      const f = uploadInput.files[0];
      uploadNameDiv.textContent = f ? f.name : '';
    });

    // fade out toast
    document.addEventListener('DOMContentLoaded', () => {
      const t = document.getElementById('toast');
      if (t) setTimeout(() => t.style.opacity = '0', 2000);
    });
  </script>
</body>
</html>
