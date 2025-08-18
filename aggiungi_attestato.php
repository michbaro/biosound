<?php
// aggiungi_attestato.php — crea nuovo attestato con selezione dipendente e upload allegati
require_once __DIR__ . '/init.php';

/* =======================
   Lookup per form e modal
   ======================= */
$corsiList = $pdo->query('
  SELECT id, titolo, COALESCE(validita,0) AS validita
  FROM corso
  ORDER BY titolo
')->fetchAll(PDO::FETCH_ASSOC);

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

$dipRaw = $pdo->query(<<<'SQL'
  SELECT d.id, d.nome, d.cognome, d.codice_fiscale, ds.sede_id, s.azienda_id
    FROM dipendente d
    JOIN dipendente_sede ds ON d.id = ds.dipendente_id
    JOIN sede s            ON ds.sede_id   = s.id
   ORDER BY d.cognome, d.nome
SQL)->fetchAll(PDO::FETCH_ASSOC);

$attivitaList = $pdo->query('
  SELECT a.id, a.modalita, c.titolo AS corso
  FROM attivita a
  JOIN corso c ON c.id = a.corso_id
  ORDER BY a.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

/* =======================
   Config upload
   ======================= */
$UPLOAD_DIR_BASE = __DIR__ . '/resources/attestati';
$MAX_SIZE_BYTES  = 20 * 1024 * 1024; // 20 MB
$ALLOWED_EXT     = ['pdf','jpg','jpeg','png','webp'];
$MIME_MAP        = [
  'pdf'  => 'application/pdf',
  'jpg'  => 'image/jpeg',
  'jpeg' => 'image/jpeg',
  'png'  => 'image/png',
  'webp' => 'image/webp',
];

$errorMsg = '';

/* =======================
   POST: Salvataggio completo
   ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $dipendente_id  = $_POST['dipendente_id']  ?? '';
  $corso_id       = $_POST['corso_id']       ?? '';
  $data_emissione = $_POST['data_emissione'] ?? '';
  $data_scadenza  = $_POST['data_scadenza']  ?? '';
  $note           = trim($_POST['note'] ?? '');

  // Validazioni base
  if ($dipendente_id === '' || $corso_id === '' || $data_emissione === '') {
    $errorMsg = 'Compila i campi obbligatori: Dipendente, Corso e Data di emissione.';
  } else {
    // Validità corso (anni) per calcolo server-side (fallback)
    $stmtV = $pdo->prepare('SELECT COALESCE(validita,0) FROM corso WHERE id = ?');
    $stmtV->execute([$corso_id]);
    $validita = (int)$stmtV->fetchColumn();

    if ($data_scadenza === '' || $data_scadenza === null) {
      if ($validita > 0) {
        $data_scadenza = date('Y-m-d', strtotime($data_emissione . " +{$validita} years"));
      } else {
        $data_scadenza = null;
      }
    } else {
      $ts = strtotime($data_scadenza);
      $data_scadenza = $ts ? date('Y-m-d', $ts) : null;
    }

    // Genero ID attestato (usiamo 32 hex per semplicità)
    $newId = bin2hex(random_bytes(16));

    // Upload allegati
    $filesMeta = [];
    if (!empty($_FILES['allegati']) && is_array($_FILES['allegati']['name'])) {
      $destDir = $UPLOAD_DIR_BASE . '/' . $newId;
      if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) {
        $errorMsg = 'Impossibile creare la cartella allegati.';
      } else {
        $count = count($_FILES['allegati']['name']);
        for ($i = 0; $i < $count; $i++) {
          $err  = $_FILES['allegati']['error'][$i];
          $size = (int)$_FILES['allegati']['size'][$i];
          $name = $_FILES['allegati']['name'][$i];
          $tmp  = $_FILES['allegati']['tmp_name'][$i];

          if ($err === UPLOAD_ERR_NO_FILE) continue;
          if ($err !== UPLOAD_ERR_OK) { $errorMsg = 'Errore durante l’upload di un file.'; break; }
          if ($size <= 0 || $size > $MAX_SIZE_BYTES) { $errorMsg = 'Un file supera i 20 MB.'; break; }

          $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
          if (!in_array($ext, $ALLOWED_EXT, true)) { $errorMsg = 'Estensione non consentita: .' . htmlspecialchars($ext); break; }

          // opzionale: controllo MIME via finfo
          if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp) ?: '';
            finfo_close($finfo);
            // tolleranza: per immagini alcuni server possono dare mime varianti
            $allowedMime = array_values($MIME_MAP);
            if ($ext === 'jpg' || $ext === 'jpeg') { $allowedMime[] = 'image/pjpeg'; }
            if ($ext === 'png') { $allowedMime[] = 'image/x-png'; }
            if ($mime && !in_array($mime, $allowedMime, true) && $mime !== 'application/octet-stream') {
              $errorMsg = 'Formato file non valido.';
              break;
            }
          }

          $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME));
          $stored   = $safeBase . '-' . bin2hex(random_bytes(4)) . '.' . $ext;

          if (!@move_uploaded_file($tmp, $destDir . '/' . $stored)) {
            $errorMsg = 'Impossibile salvare un file caricato.';
            break;
          }

          $filesMeta[] = [
            'original' => $name,
            'stored'   => $stored,
            'size'     => $size,
            'mime'     => $MIME_MAP[$ext] ?? 'application/octet-stream',
          ];
        }
      }
    }

    if (!$errorMsg) {
$stmt = $pdo->prepare(<<<'SQL'
  INSERT INTO attestato
    (id, dipendente_id, corso_id, attivita_id, data_emissione, data_scadenza, note, allegati)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
SQL
);
$stmt->execute([
  $newId,
  $dipendente_id,
  $corso_id,
  $_POST['attivita_id'],
  $data_emissione,
  $data_scadenza,
  $note,
  json_encode($filesMeta, JSON_UNESCAPED_UNICODE)
]);


      header('Location: /biosound/attestati.php?added=1');
      exit;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <title>Aggiungi Attestato</title>
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
  .btn-secondary{background:#6c757d;} .btn-primary{background:var(--pri);}
  .btn-secondary:hover,.btn-primary:hover{opacity:.9;}

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

  /* Uploader moderno compatto */
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
  
  input,select,textarea {
  width:100%;
  padding:.5rem .75rem;
  border:1px solid #ccc;
  border-radius:var(--radius);
  font-size:1rem;
}

/* Uniforma Select2 alle select standard */
.select2-container .select2-selection--single {
  height: 2.5rem;
  border: 1px solid #ccc;
  border-radius: var(--radius);
  padding: .25rem .5rem;
  font-size: 1rem;
  display: flex;
  align-items: center;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
  color: var(--fg);
  line-height: 1.5;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
  height: 100%;
}


</style>
</head>
<body>
<?php
  // navbar coerente con il resto del progetto
  $role = $_SESSION['role'] ?? 'utente';
  if ($role==='admin')    include 'navbar_a.php';
  elseif ($role==='dev')  include 'navbar_d.php';
  else                    include 'navbar.php';
?>
  <div class="container">
    <h1>Aggiungi Attestato</h1>

    <?php if ($errorMsg): ?>
      <div id="toast" class="toast-error"><?= htmlspecialchars($errorMsg,ENT_QUOTES) ?></div>
      <script>setTimeout(()=>{const t=document.getElementById('toast'); if(t) t.style.opacity='0';},3000);</script>
    <?php endif; ?>

    <form method="post" id="form-att" enctype="multipart/form-data">
      <!-- Dipendente -->
      <div class="form-group">
        <label>Dipendente *</label>
        <button type="button" id="open-part-modal" class="btn btn-secondary">
          <i class="bi bi-person"></i> Seleziona dipendente
        </button>
        <ul id="selected-participant" style="list-style:none;padding:0;margin-top:.5rem;"></ul>
        <input type="hidden" name="dipendente_id" id="dipendente-hidden">
      </div>

      <!-- Corso -->
      <div class="form-group">
        <label for="corso_id">Corso *</label>
        <select id="corso_id" name="corso_id" required>
          <option value="" disabled selected>Seleziona corso</option>
          <?php foreach($corsiList as $c): ?>
            <option value="<?= htmlspecialchars($c['id'],ENT_QUOTES) ?>" data-validita="<?= (int)$c['validita'] ?>">
              <?= htmlspecialchars($c['titolo'],ENT_QUOTES) ?>
              <?= $c['validita'] ? " (validità {$c['validita']} anni)" : " (senza scadenza)" ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

<!-- Attività -->
<div class="form-group">
  <label for="attivita_id">Attività (collegata al corso) *</label>
  <select id="attivita_id" name="attivita_id" required>
    <option value="" disabled selected>Seleziona attività</option>
    <?php foreach($attivitaList as $a): ?>
      <option value="<?= htmlspecialchars($a['id'],ENT_QUOTES) ?>">
        <?= htmlspecialchars($a['id'].' — '.$a['corso'].' ('.$a['modalita'].')',ENT_QUOTES) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>



      <!-- Date -->
      <div class="form-grid-half">
        <div class="form-group">
          <label for="data_emissione">Data di emissione *</label>
          <input type="date" id="data_emissione" name="data_emissione" required>
        </div>
        <div class="form-group">
          <label for="data_scadenza">Data di scadenza</label>
          <input type="date" id="data_scadenza" name="data_scadenza">
        </div>
      </div>

      <!-- Allegati -->
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

      <!-- Note -->
      <div class="form-group">
        <label for="note">Note</label>
        <textarea id="note" name="note" rows="4"></textarea>
      </div>

      <!-- Azioni -->
      <div class="actions">
        <a href="/biosound/attestati.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Indietro
        </a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva
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

  const aziMap={}, sedMap={};
  aziendeList.forEach(a=>aziMap[a.id]=a.ragionesociale);
  sediAll.forEach(s=>sedMap[s.id]=s.nome);

  // ======== Calcolo scadenza automatico (modificabile) ========
  const corsoSel = document.getElementById('corso_id');
  const emisIn   = document.getElementById('data_emissione');
  const scadIn   = document.getElementById('data_scadenza');
  let scadenzaTouched = false;

  scadIn.addEventListener('input', () => { scadenzaTouched = true; });

  function calcScadenzaIfNeeded(){
    if (scadenzaTouched) return;
    const opt = corsoSel.options[corsoSel.selectedIndex];
    const anni = opt ? parseInt(opt.getAttribute('data-validita')||'0',10) : 0;
    const emis = emisIn.value;
    if (emis && anni > 0){
      const d = new Date(emis);
      d.setFullYear(d.getFullYear() + anni);
      // evita problemi di timezone
      const iso = new Date(d.getTime() - d.getTimezoneOffset()*60000).toISOString().slice(0,10);
      scadIn.value = iso;
    } else if (!scadenzaTouched) {
      scadIn.value = '';
    }
  }
  emisIn.addEventListener('change', calcScadenzaIfNeeded);
  emisIn.addEventListener('input',  calcScadenzaIfNeeded);
  corsoSel.addEventListener('change', calcScadenzaIfNeeded);

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
    fileInput.files = dt.files; // ⚠️ non azzerare mai fileInput.value
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
  $(document).ready(function() {
  $('#attivita_id').select2({
    placeholder: "Seleziona o cerca un'attività",
    allowClear: true,
    width: '100%'
  });
});

  </script>
</body>
</html>
