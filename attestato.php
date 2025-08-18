<?php
// attestato.php — modifica attestato (corso non modificabile) + uploader + gestione allegati esistenti
require_once __DIR__ . '/init.php';

/* =======================
   Parametri principali
   ======================= */
$id = $_GET['id'] ?? '';
if ($id === '') {
  header('Location: /biosound/attestati.php');
  exit;
}

/* =======================
   Lookup per form e modal
   ======================= */
$aziendeList = $pdo->query('
  SELECT id, ragionesociale
  FROM azienda
  ORDER BY ragionesociale
')->fetchAll(PDO::FETCH_ASSOC);

$sediAll = $pdo->query('
  SELECT id, nome, azienda_id
  FROM sede
  ORDER BY nome
')->fetchAll(PDO::FETCH_ASSOC);

$attivitaList = $pdo->query('
  SELECT a.id, a.modalita, c.titolo AS corso
  FROM attivita a
  JOIN corso c ON c.id = a.corso_id
  ORDER BY a.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

$dipRaw = $pdo->query(<<<'SQL'
  SELECT d.id, d.nome, d.cognome, d.codice_fiscale, ds.sede_id, s.azienda_id
    FROM dipendente d
    JOIN dipendente_sede ds ON d.id = ds.dipendente_id
    JOIN sede s            ON ds.sede_id   = s.id
   ORDER BY d.cognome, d.nome
SQL)->fetchAll(PDO::FETCH_ASSOC);

/* =======================
   Carica attestato
   ======================= */
$attStmt = $pdo->prepare(<<<'SQL'
  SELECT a.*,
         c.titolo AS corso_titolo,
         COALESCE(c.validita,0) AS corso_validita,
         d.nome AS dip_nome, d.cognome AS dip_cognome, d.codice_fiscale,
         s.id AS sede_id, s.nome AS sede_nome, az.id AS azienda_id, az.ragionesociale AS azienda_nome
    FROM attestato a
    JOIN corso c            ON c.id = a.corso_id
    JOIN dipendente d       ON d.id = a.dipendente_id
    JOIN dipendente_sede ds ON ds.dipendente_id = d.id
    JOIN sede s             ON s.id = ds.sede_id
    JOIN azienda az         ON az.id = s.azienda_id
   WHERE a.id = ?
SQL);
$attStmt->execute([$id]);
$att = $attStmt->fetch(PDO::FETCH_ASSOC);
if (!$att) {
  header('Location: /biosound/attestati.php?notfound=1');
  exit;
}

/* =======================
   Config upload
   ======================= */
$UPLOAD_DIR_BASE = __DIR__ . '/resources/attestati';
$MAX_SIZE_BYTES  = 20 * 1024 * 1024; // 20 MB
$ALLOWED_EXT     = ['pdf','jpg','jpeg','png','webp'];
$MIME_ALLOW      = ['application/pdf','image/jpeg','image/png','image/webp'];

$errorMsg = '';

/* =======================
   DELETE attestato
   ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
  $pdo->beginTransaction();
  $pdo->prepare('DELETE FROM attestato WHERE id = ?')->execute([$id]);
  $pdo->commit();
  // Cancella cartella allegati
  $dir = $UPLOAD_DIR_BASE . '/' . $id;
  if (is_dir($dir)) {
    $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) {
      $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
    }
    @rmdir($dir);
  }
  header('Location: /biosound/attestati.php?deleted=1');
  exit;
}

/* =======================
   POST: Aggiorna attestato
   ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete'])) {
  $dipendente_id  = $_POST['dipendente_id']  ?? $att['dipendente_id'];
  $corso_id       = $att['corso_id']; // non modificabile
  $data_emissione = $_POST['data_emissione'] ?? '';
  $data_scadenza  = $_POST['data_scadenza']  ?? '';
  $note           = trim($_POST['note'] ?? '');

  if ($dipendente_id === '' || $data_emissione === '') {
    $errorMsg = 'Compila i campi obbligatori: Dipendente e Data di emissione.';
  } else {
    // Calcolo scadenza lato server se non fornita (fallback)
    $validita = (int)($att['corso_validita'] ?? 0);
    if ($data_scadenza === '' || $data_scadenza === null) {
      $data_scadenza = ($validita > 0) ? date('Y-m-d', strtotime($data_emissione . " +{$validita} years")) : null;
    } else {
      $ts = strtotime($data_scadenza);
      $data_scadenza = $ts ? date('Y-m-d', $ts) : null;
    }

    // Allegati esistenti (array)
    $existing = json_decode($att['allegati'] ?: '[]', true);
    if (!is_array($existing)) $existing = [];

    // 1) Elimina quelli marcati
    $toDelete = $_POST['delete_files'] ?? [];
    if (is_array($toDelete) && $toDelete) {
      $dir = $UPLOAD_DIR_BASE . '/' . $id;
      $existing = array_values(array_filter($existing, function($file) use ($toDelete, $dir) {
        if (in_array($file['stored'], $toDelete, true)) {
          @unlink($dir . '/' . $file['stored']);
          return false;
        }
        return true;
      }));
    }

    // 2) Aggiungi nuovi file caricati
    if (isset($_FILES['allegati']) && is_array($_FILES['allegati']['name'])) {
      // verifica che ci sia almeno un nome valorizzato
      $allEmpty = true;
      foreach ($_FILES['allegati']['name'] as $n) { if ($n !== '') { $allEmpty = false; break; } }

      if (!$allEmpty) {
        $destDir = $UPLOAD_DIR_BASE . '/' . $id;
        if (!is_dir($destDir)) {
          if (!mkdir($destDir, 0775, true)) {
            $errorMsg = 'Impossibile creare la cartella allegati.';
          }
        }
        if (!$errorMsg) {
          $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;

          $count = count($_FILES['allegati']['name']);
          for ($i = 0; $i < $count; $i++) {
            $name = $_FILES['allegati']['name'][$i];
            $tmp  = $_FILES['allegati']['tmp_name'][$i];
            $err  = $_FILES['allegati']['error'][$i];
            $size = (int)$_FILES['allegati']['size'][$i];

            if ($name === '' || $err === UPLOAD_ERR_NO_FILE) continue;

            if ($err !== UPLOAD_ERR_OK) { $errorMsg = "Errore di upload su «{$name}» (codice {$err})."; break; }
            if ($size <= 0 || $size > $MAX_SIZE_BYTES) { $errorMsg = "«{$name}» supera i 20 MB."; break; }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $ALLOWED_EXT, true)) { $errorMsg = "Estensione non consentita: .{$ext} ({$name})."; break; }

            if (!is_uploaded_file($tmp)) { $errorMsg = "File temporaneo non valido per «{$name}»."; break; }

            $mime = $finfo ? finfo_file($finfo, $tmp) : mime_content_type($tmp);
            if ($mime === false) $mime = 'application/octet-stream';
            if (!in_array($mime, $MIME_ALLOW, true) && $mime !== 'application/octet-stream') {
              $errorMsg = "Tipo MIME non consentito per «{$name}»: {$mime}.";
              break;
            }

            $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME));
            $stored   = $safeBase . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target   = $destDir . '/' . $stored;

            if (!move_uploaded_file($tmp, $target)) { $errorMsg = "Impossibile salvare «{$name}»."; break; }
            @chmod($target, 0644);

            $existing[] = [
              'original' => $name,
              'stored'   => $stored,
              'size'     => $size,
              'mime'     => $mime,
            ];
          }
          if ($finfo) finfo_close($finfo);
        }
      }
    }

    // 3) Aggiorna record
    if (!$errorMsg) {
      $upd = $pdo->prepare('
        UPDATE attestato
           SET dipendente_id = ?, data_emissione = ?, data_scadenza = ?, note = ?, allegati = ?
         WHERE id = ?
      ');
      $upd->execute([
        $dipendente_id,
        $data_emissione,
        $data_scadenza,
        $note,
        json_encode(array_values($existing), JSON_UNESCAPED_UNICODE),
        $id
      ]);

      header('Location: /biosound/attestati.php?updated=1');
      exit;
    }
  }
}

/* =======================
   Ricarica dati per UI
   ======================= */
$attStmt->execute([$id]);
$att = $attStmt->fetch(PDO::FETCH_ASSOC);
$existingFiles = json_decode($att['allegati'] ?: '[]', true);
if (!is_array($existingFiles)) $existingFiles = [];

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Modifica Attestato</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
  .toast-error {
    position: fixed; bottom: 1rem; left: 1rem;
    background: #dc3545; color: #fff;
    padding: .75rem 1.25rem; border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    opacity: 1; transition: opacity .5s ease-out;
    z-index: 1000;
  }

  :root{--bg:#f0f2f5;--fg:#2e3a45;--radius:8px;--shadow:rgba(0,0,0,0.08);
        --font:'Segoe UI',sans-serif;--pri:#66bb6a;--err:#d9534f;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{background:var(--bg);color:var(--fg);font-family:var(--font);}
  .container{max-width:700px;margin:2rem auto;padding:0 1rem;}
  h1{text-align:center;margin-bottom:1rem;}
  form{display:flex;flex-direction:column;gap:1.5rem;}
  .form-group{display:flex;flex-direction:column;}
  label{margin-bottom:.5rem;font-weight:500;}
  input,select,textarea{width:100%;padding:.5rem .75rem;
    border:1px solid #ccc;border-radius:var(--radius);font-size:1rem;}
  textarea{resize:vertical;}
  .form-grid-half{display:grid;grid-template-columns:1fr 1fr;gap:1rem;
    align-items:flex-end;margin-bottom:1.5rem;}
  .actions{display:flex;justify-content:center;gap:1rem;margin-top:1rem;flex-wrap:wrap;}
  .btn{display:inline-flex;align-items:center;gap:.75rem;
    padding:.6rem 1.2rem;font-size:1rem;font-weight:bold;
    color:#fff;border:none;border-radius:var(--radius);cursor:pointer;
    text-decoration:none;transition:opacity .2s;}
  .btn-secondary{background:#6c757d;}
  .btn-primary{background:var(--pri);}
  .btn-danger{background:var(--err);}
  .btn-secondary:hover,.btn-primary:hover,.btn-danger:hover{opacity:.9;}

  /* Modal dipendente */
  .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;
    background:rgba(0,0,0,0.4);display:none;align-items:center;
    justify-content:center;z-index:1000;}
  .modal-overlay.open{display:flex;}
  .modal{background:#fff;padding:1rem;border-radius:var(--radius);
    max-width:600px;width:100%;max-height:80vh;overflow:auto;}
  .modal h2{margin-bottom:1rem;}
  .filters{display:flex;gap:1rem;margin-bottom:1rem;}
  .employee-list{max-height:40vh;overflow:auto;margin-bottom:1rem;}
  .employee-list ul{list-style:none;margin:0;padding:0;width:100%;}
  .employee-list li{display:grid;grid-template-columns:1fr auto;
    align-items:center;padding:.5rem;border-bottom:1px solid #ddd;}
  .employee-list li .info{display:flex;flex-direction:column;}
  .employee-list li .info strong{font-weight:600;}
  .employee-list li .info span{font-size:.9rem;color:#555;}
  .employee-list li input[type="radio"]{justify-self:end;
    transform:scale(1.2);margin:0;}
  .modal-actions{display:flex;justify-content:flex-end;gap:1rem;}

  /* Uploader moderno compatto (identico ad aggiungi_attestato.php) */
  .dz{
    position:relative;border:2px dashed #cfd8dc;background:#fff;
    border-radius:var(--radius);padding:1rem;text-align:center;
    transition:all .15s;box-shadow:0 2px 6px var(--shadow);
    min-height:100px;cursor:pointer;
  }
  .dz:hover{border-color:var(--pri);}
  .dz.dragover{border-color:var(--pri);background:#eef8f0;}
  .dz input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;}
  .dz .dz-inner{pointer-events:none;}
  .dz .dz-inner i{font-size:1.8rem;color:var(--pri);margin-bottom:.25rem;display:block;}
  .dz .dz-title{font-weight:600;font-size:.95rem;}
  .dz .dz-hint{color:#90a4ae;font-size:.8rem;margin-top:.15rem;}

  #files-list{list-style:none;margin:.75rem 0 0;padding:0;}
  #files-list li{display:grid;grid-template-columns:auto 1fr auto;gap:.75rem;align-items:center;padding:.5rem .75rem;border:1px solid #e0e0e0;border-radius:8px;background:#fff;box-shadow:0 1px 3px var(--shadow);margin-bottom:.5rem;}
  #files-list .thumb{width:42px;height:42px;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#f5f7f8;font-size:1.25rem;color:#78909c;}
  #files-list .thumb img{width:100%;height:100%;object-fit:cover;display:block;}
  #files-list .meta{display:flex;flex-direction:column;}
  #files-list .meta strong{font-size:.98rem;}
  #files-list .meta .sub{color:#607d8b;font-size:.85rem;display:flex;gap:.5rem;align-items:center;}
  #files-list .badge{display:inline-flex;align-items:center;font-size:.75rem;border-radius:999px;padding:.15rem .5rem;border:1px solid #cfd8dc;color:#455a64;background:#f5f7f8;}
  #files-list .remove{background:none;border:none;color:#ef5350;font-size:1.1rem;cursor:pointer;}

  /* Allegati esistenti (card cliccabile) */
  #existing-files{list-style:none;margin:.5rem 0 0;padding:0;}
  #existing-files li{
    display:flex;justify-content:space-between;align-items:center;gap:.5rem;
    padding:.55rem .7rem;border:1px solid #e0e0e0;border-radius:8px;
    background:#fff;box-shadow:0 1px 3px var(--shadow);margin-bottom:.45rem;
    cursor:pointer; transition: background .15s, box-shadow .15s;
  }
  #existing-files li:hover{background:#f9fbfb; box-shadow:0 2px 8px var(--shadow);}
  #existing-files .file-meta{display:flex;flex-direction:column;}
  #existing-files .file-sub{color:#607d8b;font-size:.85rem;}
  #existing-files .del{background:none;border:none;color:#ef5350;font-size:1.1rem;cursor:pointer;}
  </style>
</head>
<body>
<?php
  $role = $_SESSION['role'] ?? 'utente';
  if ($role==='admin')    include 'navbar_a.php';
  elseif ($role==='dev')  include 'navbar_d.php';
  else                    include 'navbar.php';
?>
  <div class="container">
    <h1>Modifica Attestato</h1>

    <?php if ($errorMsg): ?>
      <div id="toast" class="toast-error"><?= htmlspecialchars($errorMsg,ENT_QUOTES) ?></div>
      <script>setTimeout(()=>{const t=document.getElementById('toast'); if(t) t.style.opacity='0';},3500);</script>
    <?php endif; ?>

    <form method="post" id="form-att" enctype="multipart/form-data">
      <!-- Dipendente -->
      <div class="form-group">
        <label>Dipendente *</label>
        <button type="button" id="open-part-modal" class="btn btn-secondary">
          <i class="bi bi-person"></i> Seleziona dipendente
        </button>
        <ul id="selected-participant" style="list-style:none;padding:0;margin-top:.5rem;">
          <li style="display:flex;justify-content:space-between;align-items:center;padding:.5rem;border:1px solid #ddd;border-radius:4px;">
            <div class="info">
              <strong><?= htmlspecialchars($att['dip_cognome'].' '.$att['dip_nome'],ENT_QUOTES) ?></strong>
              <div style="color:#667;"><?= htmlspecialchars($att['azienda_nome'],ENT_QUOTES) ?> (<?= htmlspecialchars($att['sede_nome'],ENT_QUOTES) ?>) — CF: <?= htmlspecialchars($att['codice_fiscale'],ENT_QUOTES) ?></div>
            </div>
            <button type="button" id="clear-dip" style="background:none;border:none;color:var(--err);font-size:1.2rem;">×</button>
          </li>
        </ul>
        <input type="hidden" name="dipendente_id" id="dipendente-hidden" value="<?= htmlspecialchars($att['dipendente_id'],ENT_QUOTES) ?>">
      </div>

      <!-- Corso (non modificabile) -->
      <div class="form-group">
        <label>Corso</label>
        <input type="text" value="<?= htmlspecialchars($att['corso_titolo'],ENT_QUOTES) ?> (ID: <?= htmlspecialchars($att['corso_id'],ENT_QUOTES) ?>)" disabled>
        <input type="hidden" name="corso_id" value="<?= htmlspecialchars($att['corso_id'],ENT_QUOTES) ?>">
      </div>

      <!-- Attività -->
<div class="form-group">
  <label for="attivita_id">Attività collegata *</label>
  <select id="attivita_id" name="attivita_id" required>
    <option value="">Seleziona attività</option>
    <?php foreach($attivitaList as $a): ?>
      <option value="<?= htmlspecialchars($a['id'],ENT_QUOTES) ?>"
        <?= $att['attivita_id']==$a['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($a['id'].' — '.$a['corso'].' ('.$a['modalita'].')',ENT_QUOTES) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>

      <!-- Date -->
      <div class="form-grid-half">
        <div class="form-group">
          <label for="data_emissione">Data di emissione *</label>
          <input type="date" id="data_emissione" name="data_emissione" required value="<?= htmlspecialchars($att['data_emissione'],ENT_QUOTES) ?>">
        </div>
        <div class="form-group">
          <label for="data_scadenza">Data di scadenza</label>
          <input type="date" id="data_scadenza" name="data_scadenza" value="<?= htmlspecialchars($att['data_scadenza'] ?? '',ENT_QUOTES) ?>">
        </div>
      </div>

      <!-- Allegati: nuovi -->
      <div class="form-group">
        <label for="allegati">Allegati (PDF/JPG/PNG/WEBP)</label>
        <div id="dropzone" class="dz" title="Clicca o trascina qui i file">
          <input type="file" id="allegati" name="allegati[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
          <div class="dz-inner">
            <i class="bi bi-cloud-arrow-up"></i>
            <div class="dz-title">Trascina qui i file o clicca</div>
            <div class="dz-hint">Max 20 MB per file</div>
          </div>
        </div>
        <ul id="files-list"></ul>
      </div>

      <!-- Allegati esistenti -->
      <div class="form-group">
        <label>Allegati esistenti</label>
        <ul id="existing-files">
          <?php foreach ($existingFiles as $f): 
            $fileUrl = "resources/attestati/" . rawurlencode($id) . "/" . rawurlencode($f['stored']);
          ?>
          <li data-href="<?= htmlspecialchars($fileUrl,ENT_QUOTES) ?>">
            <div class="file-meta">
              <strong><?= htmlspecialchars($f['original'],ENT_QUOTES) ?></strong>
              <div class="file-sub">
                <?= strtoupper(pathinfo($f['stored'], PATHINFO_EXTENSION)) ?> • <?= number_format(($f['size'] ?? 0)/1048576, 2) ?> MB
              </div>
            </div>
            <button type="button" class="del" title="Rimuovi" onclick="markForDelete(event,'<?= htmlspecialchars($f['stored'],ENT_QUOTES) ?>', this)">
              <i class="bi bi-x-circle"></i>
            </button>
          </li>
          <?php endforeach; ?>
        </ul>
        <div id="delete-hidden"></div>
      </div>

      <!-- Note -->
      <div class="form-group">
        <label for="note">Note</label>
        <textarea id="note" name="note" rows="4"><?= htmlspecialchars($att['note'] ?? '',ENT_QUOTES) ?></textarea>
      </div>

      <!-- Azioni -->
      <div class="actions">
        <a href="/biosound/attestati.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Indietro
        </a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva
        </button>
        <button type="submit" name="delete" value="1" class="btn btn-danger" onclick="return confirm('Eliminare definitivamente questo attestato?');">
          <i class="bi bi-trash"></i> Elimina
        </button>
      </div>
    </form>
  </div>

  <!-- Modal selezione dipendente -->
  <div id="participant-modal" class="modal-overlay">
    <div class="modal">
      <h2>Seleziona Dipendente</h2>
      <div class="filters">
        <div class="filter-group">
          <label for="modal-azienda">Azienda</label>
          <select id="modal-azienda">
            <option value="">Tutte le aziende</option>
            <?php foreach($aziendeList as $a): ?>
              <option value="<?= $a['id'] ?>">
                <?= htmlspecialchars($a['ragionesociale'],ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label for="modal-sede">Sede</label>
          <select id="modal-sede" disabled>
            <option value="">Tutte le sedi</option>
          </select>
        </div>
        <div class="filter-group" style="flex:1;">
          <label for="modal-search">Cerca</label>
          <input type="text" id="modal-search" placeholder="Nome, Cognome o CF">
        </div>
      </div>
      <div class="employee-list">
        <ul id="modal-employee-list"></ul>
      </div>
      <div class="modal-actions">
        <button id="save-participants" class="btn btn-primary">Salva</button>
        <button id="cancel-participants" class="btn btn-secondary">Annulla</button>
      </div>
    </div>
  </div>

<script>
  // ======== PHP → JS ========
  const aziendeList = <?= json_encode($aziendeList, JSON_HEX_TAG) ?>;
  const sediAll     = <?= json_encode($sediAll,     JSON_HEX_TAG) ?>;
  const dipRaw      = <?= json_encode($dipRaw,      JSON_HEX_TAG) ?>;
  const corsoValidita = <?= (int)($att['corso_validita'] ?? 0) ?>;

  const aziMap={}, sedMap={};
  aziendeList.forEach(a=>aziMap[a.id]=a.ragionesociale);
  sediAll.forEach(s=>sedMap[s.id]=s.nome);

  // ======== Calcolo scadenza automatico (modificabile) ========
  const emisIn = document.getElementById('data_emissione');
  const scadIn = document.getElementById('data_scadenza');
  let scadenzaTouched = false;

  scadIn.addEventListener('input', () => { scadenzaTouched = true; });

  function calcScadenzaIfNeeded(){
    if (scadenzaTouched) return;
    const anni = parseInt(corsoValidita || 0, 10);
    const emis = emisIn.value;
    if (emis && anni > 0){
      const d = new Date(emis);
      d.setFullYear(d.getFullYear() + anni);
      const iso = new Date(d.getTime() - d.getTimezoneOffset()*60000).toISOString().slice(0,10);
      scadIn.value = iso;
    } else if (!scadenzaTouched) {
      scadIn.value = '';
    }
  }
  emisIn.addEventListener('change', calcScadenzaIfNeeded);
  emisIn.addEventListener('input',  calcScadenzaIfNeeded);

  // ======== Modal Dipendente (PiP singolo) ========
  const modal    = document.getElementById('participant-modal');
  const aziSel   = document.getElementById('modal-azienda');
  const sedeSel  = document.getElementById('modal-sede');
  const searchIn = document.getElementById('modal-search');
  const listEl   = document.getElementById('modal-employee-list');
  const picked   = new Set();

  document.getElementById('open-part-modal').onclick = ()=>{
    modal.classList.add('open');
    renderFilters();
    renderEmployees();
  };
  document.getElementById('cancel-participants').onclick = ()=> modal.classList.remove('open');

  function renderFilters(){
    sedeSel.disabled = !aziSel.value;
    sedeSel.innerHTML = '<option value="">Tutte le sedi</option>';
    sediAll.forEach(s=>{
      if(!aziSel.value || s.azienda_id===aziSel.value){
        const o=document.createElement('option');
        o.value=s.id; o.textContent=s.nome;
        sedeSel.appendChild(o);
      }
    });
  }
  function renderEmployees(){
    listEl.innerHTML='';
    const q=searchIn.value.trim().toLowerCase();
    dipRaw.forEach(d=>{
      if(aziSel.value && d.azienda_id!==aziSel.value) return;
      if(sedeSel.value && d.sede_id!==sedeSel.value)  return;
      const text=`${d.nome} ${d.cognome} ${d.codice_fiscale}`.toLowerCase();
      if(q && !text.includes(q)) return;

      const li=document.createElement('li');
      const info=document.createElement('div'); info.className='info';
      info.innerHTML=`<strong>${d.cognome} ${d.nome}</strong>
        <span>${aziMap[d.azienda_id]||''} (${sedMap[d.sede_id]||''}) — CF: ${d.codice_fiscale}</span>`;
      const rb=document.createElement('input'); rb.type='radio'; rb.name='dipSel'; rb.value=d.id; rb.checked=picked.has(d.id);
      rb.onchange=()=>{ picked.clear(); picked.add(d.id); };
      li.append(info, rb);
      listEl.appendChild(li);
    });
  }
  aziSel.onchange = ()=>{ renderFilters(); renderEmployees(); };
  sedeSel.onchange= renderEmployees;
  searchIn.oninput= renderEmployees;

  document.getElementById('save-participants').onclick = ()=>{
    if(picked.size===0){ alert('Seleziona un dipendente'); return; }
    const id=[...picked][0];
    const d=dipRaw.find(x=>x.id===id);
    const ul=document.getElementById('selected-participant');
    ul.innerHTML='';
    const li=document.createElement('li'), info=document.createElement('div'), btn=document.createElement('button');
    info.className='info';
    info.innerHTML=`<strong>${d.cognome} ${d.nome}</strong>
      <div style="color:#667;">${aziMap[d.azienda_id]||''} (${sedMap[d.sede_id]||''}) — CF: ${d.codice_fiscale}</div>`;
    btn.type='button'; btn.textContent='×';
    btn.style.background='none'; btn.style.border='none'; btn.style.color='var(--err)'; btn.style.fontSize='1.2rem';
    btn.onclick=()=>{ picked.clear(); li.remove(); document.getElementById('dipendente-hidden').value=''; };
    li.style.display='flex'; li.style.justifyContent='space-between'; li.style.alignItems='center';
    li.style.padding='.5rem'; li.style.border='1px solid #ddd'; li.style.borderRadius='4px';
    li.append(info, btn);
    ul.appendChild(li);
    document.getElementById('dipendente-hidden').value=id;
    modal.classList.remove('open');
  };

  // Clear dip iniziale
  const clearBtn = document.getElementById('clear-dip');
  if (clearBtn) clearBtn.onclick = () => {
    document.getElementById('dipendente-hidden').value='';
    document.getElementById('selected-participant').innerHTML='';
  };

  // ======== Uploader (drag&drop + click su tutta l'area) ========
  const fileInput = document.getElementById('allegati');
  const dropzone  = document.getElementById('dropzone');
  const filesList = document.getElementById('files-list');
  const form      = document.getElementById('form-att');

  const MAX_SIZE = 20*1024*1024;
  const ALLOWED_EXT = ['pdf','jpg','jpeg','png','webp'];
  let dt = new DataTransfer();

  function extOf(n){ return (n.split('.').pop()||'').toLowerCase(); }
  function prettySize(b){ if(b<1024)return b+' B'; if(b<1048576)return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(1)+' MB'; }
  function isImg(n){ return ['jpg','jpeg','png','webp'].includes(extOf(n)); }

  function addFilesToDT(list){
    for(const f of list){
      const e=extOf(f.name);
      if(!ALLOWED_EXT.includes(e)){ alert(`Estensione non consentita: .${e}`); continue; }
      if(f.size>MAX_SIZE){ alert(`${f.name}: supera i 20 MB`); continue; }
      dt.items.add(f);
    }
    fileInput.files = dt.files; // non azzerare
    renderList();
  }

  function renderList(){
    filesList.innerHTML='';
    for(let i=0;i<dt.files.length;i++){
      const f = dt.files[i];
      const li = document.createElement('li');

      const th = document.createElement('div'); th.className='thumb';
      if(isImg(f.name)){ const img=document.createElement('img'); img.src=URL.createObjectURL(f); img.onload=()=>URL.revokeObjectURL(img.src); th.appendChild(img); }
      else { th.innerHTML='<i class="bi bi-file-earmark-pdf"></i>'; }

      const meta = document.createElement('div'); meta.className='meta';
      const t = document.createElement('strong'); t.textContent = f.name;
      const sub = document.createElement('div'); sub.className='sub';
      const b = document.createElement('span'); b.className='badge'; b.textContent=extOf(f.name).toUpperCase();
      const s = document.createElement('span'); s.textContent=prettySize(f.size);
      sub.append(b,s); meta.append(t,sub);

      const rm = document.createElement('button'); rm.type='button'; rm.className='remove'; rm.innerHTML='<i class="bi bi-x-circle"></i>';
      rm.onclick = ()=>{
        const ndt=new DataTransfer();
        for(let j=0;j<dt.files.length;j++){ if(j!==i) ndt.items.add(dt.files[j]); }
        dt=ndt; fileInput.files=dt.files; renderList();
      };

      li.append(th, meta, rm);
      filesList.appendChild(li);
    }
  }

  // Click/sfoglia
  fileInput.addEventListener('change', ()=>{ addFilesToDT(fileInput.files); });

  // Drag & drop
  ['dragenter','dragover'].forEach(ev=>dropzone.addEventListener(ev, e=>{ e.preventDefault(); dropzone.classList.add('dragover'); }));
  ['dragleave','drop'].forEach(ev=>dropzone.addEventListener(ev, e=>{ e.preventDefault(); dropzone.classList.remove('dragover'); }));
  dropzone.addEventListener('drop', e=>{ if(e.dataTransfer?.files){ addFilesToDT(e.dataTransfer.files); } });

  // Prima del submit, riallinea l'input ai file accumulati
  form.addEventListener('submit', ()=>{ fileInput.files = dt.files; });

  // ======== Allegati esistenti: click apre in nuova scheda; X elimina ========
  function markForDelete(ev, filename, btn){
    ev.stopPropagation(); // non aprire il file
    const holder = document.getElementById('delete-hidden');
    const h = document.createElement('input');
    h.type = 'hidden'; h.name = 'delete_files[]'; h.value = filename;
    holder.appendChild(h);
    const li = btn.closest('li');
    li.style.opacity = .5;
    btn.disabled = true;
  }
  window.markForDelete = markForDelete;

  document.getElementById('existing-files').addEventListener('click', function(e){
    const li = e.target.closest('li');
    if (!li) return;
    if (e.target.closest('button')) return; // click sulla X
    const href = li.getAttribute('data-href');
    if (href) window.open(href, '_blank');
  });
</script>
</body>
</html>
