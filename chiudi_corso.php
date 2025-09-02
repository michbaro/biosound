<?php
// chiudi_corso.php — selezione presenze/esito, upload scheda corso (PDF) e generazione attestati (solo chi ha superato)
// Regole UI:
//  - "Tutti presenti" per singola data
//  - "Tutti superati" globale
//  - Auto-pass se minuti >= 75% della durata corso (override manuale possibile)
// Note: Presenze in minuti calcolate solo come (oraFine - oraInizio) per ogni data.
//       Attestato generato SOLO se "Ha superato" = 1 (anche sotto ore).

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/libs/vendor/autoload.php';

use setasign\Fpdi\Fpdi;

/* =======================
   Helpers
======================= */
function fmt_it_date(?string $val): string {
  if (!$val) return '';
  $ts = strtotime($val);
  return $ts ? date('d/m/Y', $ts) : '';
}
function clean_filename(string $s): string {
  $s = str_replace(' ', '_', $s);
  return preg_replace('/[^A-Za-z0-9_\-\.]/', '', $s) ?: 'file';
}
function pdf_text($s) {
  return iconv('UTF-8','CP1252//TRANSLIT//IGNORE',(string)$s);
}
function uuid16(): string { return bin2hex(random_bytes(16)); }
function expiry_from_today_years(int $years): string {
  $today = new DateTime('today');
  $targetYear = (int)$today->format('Y') + max(0, $years);
  $m = (int)$today->format('n');
  $d = (int)$today->format('j');
  $eom = (new DateTime())->setDate($targetYear, $m, 1)->modify('last day of this month');
  $day = min($d, (int)$eom->format('j'));
  return (new DateTime())->setDate($targetYear, $m, $day)->setTime(0,0,0)->format('Y-m-d');
}

/* =======================
   Parametri
======================= */
$id = $_GET['id'] ?? '';
if ($id === '') {
  header('Location: /biosound/attivitae.php');
  exit;
}

/* =======================
   Dati attività/corso
======================= */
$attStmt = $pdo->prepare(<<<'SQL'
  SELECT
    a.id, a.corso_id, a.modalita, a.luogo, a.note, a.chiuso,
    c.titolo AS corso_titolo,
    COALESCE(c.durata,0) AS durata_ore,
    COALESCE(c.validita,0) AS validita_anni,
    COALESCE(c.normativa,'') AS normativa
  FROM attivita a
  JOIN corso c ON c.id = a.corso_id
  WHERE a.id = ?
SQL);
$attStmt->execute([$id]);
$att = $attStmt->fetch(PDO::FETCH_ASSOC);
if (!$att) {
  header('Location: /biosound/attivitae.php?notfound=1');
  exit;
}

/* Lezioni (durata in minuti = TIMESTAMPDIFF(end,start)) */
$lezStmt = $pdo->prepare(<<<'SQL'
  SELECT dl.id, dl.data,
         TIME_FORMAT(dl.oraInizio,'%H:%i') AS start,
         TIME_FORMAT(dl.oraFine  ,'%H:%i') AS end,
         GREATEST(TIMESTAMPDIFF(MINUTE, dl.oraInizio, dl.oraFine),0) AS minuti
  FROM incarico i
  JOIN datalezione dl ON dl.incarico_id = i.id
  WHERE i.attivita_id = ?
  ORDER BY dl.data, dl.oraInizio
SQL);
$lezStmt->execute([$id]);
$lezioni = $lezStmt->fetchAll(PDO::FETCH_ASSOC);

/* Partecipanti */
$partStmt = $pdo->prepare(<<<'SQL'
  SELECT d.id,d.nome,d.cognome,d.codice_fiscale,d.datanascita,d.luogonascita
  FROM attivita_dipendente ad
  JOIN dipendente d ON d.id = ad.dipendente_id
  WHERE ad.attivita_id = ?
  ORDER BY d.cognome, d.nome
SQL);
$partStmt->execute([$id]);
$partecipanti = $partStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$partecipanti) {
  header('Location: /biosound/attivitae.php?closed=0&msg=nopartecipanti');
  exit;
}

/* =======================
   Template PDF
======================= */
$templatePdf = __DIR__ . '/resources/templates/template_attestato.pdf';
if (!is_file($templatePdf)) {
  http_response_code(500);
  echo 'Template PDF non trovato: '.htmlspecialchars($templatePdf);
  exit;
}

/* =======================
   GET: form
======================= */
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
if (!$isPost) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
  ?>
  <!DOCTYPE html>
  <html lang="it">
  <head>
    <meta charset="UTF-8">
    <title>Chiudi attività <?= htmlspecialchars($id,ENT_QUOTES) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      :root{--bg:#f6f7fb;--fg:#2e3a45;--pri:#2e7d32;--warn:#f0ad4e;--err:#d9534f;--ok:#198754;--radius:10px;--card:#fff;--muted:#6c757d}
      *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--fg);font:14px system-ui,-apple-system,Segoe UI,Roboto}
      .wrap{max-width:1100px;margin:5vh auto;padding:1rem}
      .card{background:var(--card);border-radius:var(--radius);box-shadow:0 6px 24px rgba(0,0,0,.06);padding:1rem 1.25rem;margin-bottom:1rem}
      h1{margin:0 0 .5rem} .muted{color:var(--muted)}
      .bar{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin:.5rem 0 1rem}
      .pill{background:#eef3ff;border-radius:999px;padding:.2rem .6rem;font-weight:600}
      table{border-collapse:collapse;width:100%;background:#fff;border-radius:8px;overflow:hidden}
      th,td{border:1px solid #e8e8e8;padding:.5rem;text-align:center;vertical-align:top}
      th.sticky{position:sticky;top:0;background:#f9fafb;z-index:2}
      th.left,td.left{text-align:left}
      .row-total{font-weight:700}
      .state-min{color:var(--ok)} .state-over{color:var(--warn)} .state-low{color:var(--err)}
      .actions{display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem;flex-wrap:wrap}
      .btn{display:inline-flex;align-items:center;gap:.4rem;border:0;padding:.6rem 1rem;border-radius:8px;color:#fff;cursor:pointer;font-weight:600}
      .btn-sec{background:#6c757d} .btn-pri{background:var(--pri)}
      .legend{display:flex;gap:1rem;align-items:center;margin:.5rem 0;flex-wrap:wrap}
      .legend span{display:inline-flex;align-items:center;gap:.4rem}
      .chip{width:.8rem;height:.8rem;border-radius:2px;display:inline-block}
      .c-low{background:var(--err)} .c-over{background:var(--warn)} .c-min{background:var(--ok)}
      .note{color:#555;font-size:.9rem}
      .alert{padding:.6rem .8rem;border-radius:8px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;margin:.5rem 0}
      small.muted{display:block;color:#6c757d}
      .mini-btn{margin-top:.35rem;border:0;border-radius:6px;padding:.25rem .5rem;font-size:.75rem;cursor:pointer;color:#fff;background:#2e7d32}
      .mini-btn:hover{opacity:.9}
      .upload{margin:1rem 0 .25rem}
      .help{color:#6c757d;font-size:.9rem;margin-top:.25rem}

      /* Uploader moderno compatto — identico a aggiungi_attestato.php */
.dz{
  position:relative;border:2px dashed #cfd8dc;background:#fff;
  border-radius:8px;padding:1rem;text-align:center;
  transition:all .15s;box-shadow:0 2px 6px rgba(0,0,0,0.08);
  min-height:100px;cursor:pointer; margin-top:1rem; /* un po' di spazio sopra */
}
.dz:hover{border-color:var(--pri, #66bb6a);}
.dz.dragover{border-color:var(--pri, #66bb6a);background:#eef8f0;}
.dz input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;}
.dz .dz-inner{pointer-events:none;}
.dz .dz-inner i{font-size:1.8rem;color:var(--pri, #66bb6a);margin-bottom:.25rem;display:block;}
.dz .dz-title{font-weight:600;font-size:.95rem;}
.dz .dz-hint{color:#90a4ae;font-size:.8rem;margin-top:.15rem;}

#scheda-files-list{
  list-style:none;margin:.75rem 0 0;padding:0;
}
#scheda-files-list li{
  display:grid;grid-template-columns:auto 1fr auto;
  gap:.75rem;align-items:center;padding:.5rem .75rem;
  border:1px solid #e0e0e0;border-radius:8px;background:#fff;
  box-shadow:0 1px 3px rgba(0,0,0,0.08);margin-bottom:.5rem;
}
#scheda-files-list .thumb{
  width:42px;height:42px;border-radius:6px;overflow:hidden;
  display:flex;align-items:center;justify-content:center;
  background:#f5f7f8;font-size:1.25rem;color:#78909c;
}
#scheda-files-list .meta{display:flex;flex-direction:column;}
#scheda-files-list .meta strong{font-size:.98rem;}
#scheda-files-list .meta .sub{
  color:#607d8b;font-size:.85rem;display:flex;gap:.5rem;align-items:center;
}
#scheda-files-list .badge{
  display:inline-flex;align-items:center;font-size:.75rem;
  border-radius:999px;padding:.15rem .5rem;border:1px solid #cfd8dc;
  color:#455a64;background:#f5f7f8;
}
#scheda-files-list .remove{
  background:none;border:none;color:#ef5350;font-size:1.1rem;cursor:pointer;
}

    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="card">
        <h1>Chiudi attività <code><?= htmlspecialchars($id,ENT_QUOTES) ?></code></h1>
        <div class="bar">
          <div class="pill"><?= htmlspecialchars($att['corso_titolo'],ENT_QUOTES) ?></div>
          <div class="muted">Durata corso: <strong><?= (int)$att['durata_ore'] ?></strong> ore</div>
          <?php if (!$lezioni): ?>
            <div class="alert">Questa attività non ha lezioni pianificate. Puoi indicare chi ha superato il corso.</div>
          <?php endif; ?>
        </div>

        <!-- IMPORTANTE: enctype per upload PDF scheda -->
        <form id="close-form" method="post" action="?id=<?= urlencode($id) ?>" enctype="multipart/form-data">
          <input type="hidden" name="confirm" value="1">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES) ?>">

          <div class="legend">
            <span><span class="chip c-min"></span> raggiunge minimo</span>
            <span><span class="chip c-over"></span> supera minimo (avviso)</span>
            <span><span class="chip c-low"></span> sotto minimo</span>
            <span style="margin-left:auto" class="muted">Regola auto-pass: ≥ <strong>75%</strong> ore (modificabile)</span>
            <button type="button" id="btn-all-pass" class="btn btn-pri">
              <i class="bi bi-check2-all"></i> Tutti superati
            </button>
          </div>

          <div style="overflow:auto">
          <table>
            <thead>
              <tr>
                <th class="sticky left">Partecipante</th>
                <?php foreach ($lezioni as $dl): ?>
                  <th class="sticky">
                    <?= fmt_it_date($dl['data']) ?><br>
                    <small class="muted"><?= htmlspecialchars($dl['start'].'-'.$dl['end'],ENT_QUOTES) ?><br>(<?= (int)$dl['minuti'] ?>′)</small>
                    <div>
                      <button type="button" class="mini-btn btn-all-present" data-dl="<?= htmlspecialchars($dl['id'],ENT_QUOTES) ?>">
                        Tutti presenti
                      </button>
                    </div>
                  </th>
                <?php endforeach; ?>
                <th class="sticky">Totale (h)</th>
                <th class="sticky">Ha superato</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($partecipanti as $p): ?>
                <tr data-dip="<?= htmlspecialchars($p['id'],ENT_QUOTES) ?>">
                  <td class="left">
                    <strong><?= htmlspecialchars($p['cognome'].' '.$p['nome'],ENT_QUOTES) ?></strong>
                    <small class="muted"><?= htmlspecialchars($p['codice_fiscale'],ENT_QUOTES) ?></small>
                  </td>
                  <?php foreach ($lezioni as $dl): ?>
                    <td>
                      <label style="display:flex;gap:.4rem;justify-content:center;align-items:center">
                        <input type="checkbox"
                               name="pres[<?= $dl['id'] ?>][<?= $p['id'] ?>]"
                               value="1"
                               class="pres-cb"
                               data-min="<?= (int)$dl['minuti'] ?>"
                               data-dl="<?= htmlspecialchars($dl['id'],ENT_QUOTES) ?>">
                      </label>
                    </td>
                  <?php endforeach; ?>
                  <td class="row-total">0.0</td>
                  <td>
                    <input type="checkbox" name="pass[<?= $p['id'] ?>]" value="1" class="pass-cb">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>

          <!-- Upload scheda corso (PDF) -->
<div class="form-group">
  <label for="scheda_pdf">Scheda corso (PDF)</label>
  <div id="scheda-dropzone" class="dz" title="Clicca o trascina qui il PDF">
    <input type="file" id="scheda_pdf" name="scheda_pdf" accept=".pdf">
    <div class="dz-inner">
      <i class="bi bi-cloud-arrow-up"></i>
      <div class="dz-title">Trascina qui il PDF o clicca</div>
      <div class="dz-hint">Solo PDF — Max 20 MB</div>
    </div>
  </div>
  <ul id="scheda-files-list"></ul>
</div>
<script>
  (function(){
    const dropzone  = document.getElementById('scheda-dropzone');
    const input     = document.getElementById('scheda_pdf');
    const listEl    = document.getElementById('scheda-files-list');
    const MAX_SIZE  = 20*1024*1024; // 20MB

    function renderList(file){
      listEl.innerHTML = '';
      if(!file) return;

      const li   = document.createElement('li');
      const th   = document.createElement('div'); th.className='thumb'; th.innerHTML='<i class="bi bi-file-earmark-pdf"></i>';
      const meta = document.createElement('div'); meta.className='meta';
      const name = document.createElement('strong'); name.textContent = file.name;
      const sub  = document.createElement('div'); sub.className='sub';
      const badge= document.createElement('span'); badge.className='badge'; badge.textContent='PDF';
      const size = document.createElement('span'); size.textContent = prettySize(file.size);
      sub.append(badge, size); meta.append(name, sub);

      const rm   = document.createElement('button'); rm.type='button'; rm.className='remove'; rm.innerHTML='<i class="bi bi-x-circle"></i>';
      rm.onclick = () => { input.value=''; listEl.innerHTML=''; };

      li.append(th, meta, rm);
      listEl.appendChild(li);
    }

    function prettySize(b){
      if(b<1024) return b+' B';
      if(b<1048576) return (b/1024).toFixed(1)+' KB';
      return (b/1048576).toFixed(1)+' MB';
    }

    function acceptSinglePdf(fileList){
      const f = fileList?.[0];
      if(!f) return;
      const ext = (f.name.split('.').pop() || '').toLowerCase();
      if(ext !== 'pdf'){ alert('Seleziona un file PDF.'); return; }
      if(f.size > MAX_SIZE){ alert('Il file supera 20 MB.'); return; }
      // imposta singolo file nell’input
      const dt = new DataTransfer();
      dt.items.add(f);
      input.files = dt.files;
      renderList(f);
    }

    // Click/sfoglia
    input.addEventListener('change', ()=> acceptSinglePdf(input.files));

    // Drag & drop
    ['dragenter','dragover'].forEach(ev=>dropzone.addEventListener(ev, e=>{
      e.preventDefault(); dropzone.classList.add('dragover');
    }));
    ['dragleave','drop'].forEach(ev=>dropzone.addEventListener(ev, e=>{
      e.preventDefault(); dropzone.classList.remove('dragover');
    }));
    dropzone.addEventListener('drop', e=>{
      acceptSinglePdf(e.dataTransfer?.files);
    });
  })();
</script>


          <p class="note">Le ore sono calcolate automaticamente dalla differenza tra orario di inizio e di fine per ogni data spuntata.</p>

          <div class="actions">
            <a href="/biosound/attivitae.php" class="btn btn-sec"><i class="bi bi-arrow-left"></i> Annulla</a>
            <button type="submit" class="btn btn-pri"><i class="bi bi-check2-circle"></i> Conferma presenze e chiudi</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      const DURATA_MIN = <?= (int)$att['durata_ore'] ?> * 60;      // minuti teorici corso
      const PASS_RATIO = 0.75;                                     // soglia 75%
      const rows = document.querySelectorAll('tbody tr');
      const form = document.getElementById('close-form');

      function getRowMinutes(tr){
        return Array.from(tr.querySelectorAll('.pres-cb:checked'))
          .reduce((acc, cb)=> acc + parseInt(cb.getAttribute('data-min')||'0',10), 0);
      }

      function setOverride(tr){
        const dipId = tr.getAttribute('data-dip');
        if (!dipId) return;
        if (!tr.querySelector('input[name="pass_override['+dipId+']"]')) {
          const h = document.createElement('input');
          h.type = 'hidden';
          h.name = 'pass_override['+dipId+']';
          h.value = '1';
          form.appendChild(h);
        }
        tr.dataset.override = '1';
      }

      function updateRow(tr){
        const mins = getRowMinutes(tr);
        const tdTotal = tr.querySelector('.row-total');
        const passCb  = tr.querySelector('.pass-cb');

        tdTotal.textContent = (mins/60).toFixed(1);
        tdTotal.classList.remove('state-min','state-over','state-low');
        if (DURATA_MIN > 0) {
          if      (mins === DURATA_MIN) tdTotal.classList.add('state-min');
          else if (mins >  DURATA_MIN)  tdTotal.classList.add('state-over');
          else                          tdTotal.classList.add('state-low');
        }

        if (DURATA_MIN > 0 && tr.dataset.override !== '1') {
          const meets = mins >= Math.ceil(DURATA_MIN * PASS_RATIO);
          passCb.checked = meets;
        }
      }

      rows.forEach(tr=>{
        tr.querySelectorAll('.pres-cb').forEach(cb=>{
          cb.addEventListener('change', ()=> updateRow(tr));
        });
        const passCb = tr.querySelector('.pass-cb');
        passCb.addEventListener('change', ()=> setOverride(tr));
        updateRow(tr);
      });

      document.querySelectorAll('.btn-all-present').forEach(btn => {
        btn.addEventListener('click', () => {
          const dlId = btn.getAttribute('data-dl');
          document.querySelectorAll('.pres-cb[data-dl="'+dlId+'"]').forEach(cb => {
            if (!cb.checked) {
              cb.checked = true;
              cb.dispatchEvent(new Event('change', { bubbles: true }));
            }
          });
          rows.forEach(updateRow);
        });
      });

      document.getElementById('btn-all-pass')?.addEventListener('click', () => {
        rows.forEach(tr => {
          const passCb = tr.querySelector('.pass-cb');
          if (passCb && !passCb.checked) passCb.checked = true;
          setOverride(tr);
        });
      });
    </script>
  </body>
  </html>
  <?php
  exit;
}

/* =======================
   POST: salva presenze/esito, gestisci upload scheda, genera attestati, chiudi
======================= */
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
  http_response_code(403); exit('CSRF non valido');
}
unset($_SESSION['csrf']);

$pres = $_POST['pres'] ?? [];          // [dlId][dipId] => 1
$pass = $_POST['pass'] ?? [];          // [dipId] => 1
$override = $_POST['pass_override'] ?? []; // [dipId] => 1

// Mappa minuti per lezione
$durMap = [];
foreach ($lezioni as $dl) $durMap[$dl['id']] = (int)$dl['minuti'];
$requiredMin = max(0,(int)$att['durata_ore']*60);
$warn = [];
$savedScheda = null;

try {
  $pdo->beginTransaction();

  // Lock attività per evitare doppie chiusure simultanee
  $lock = $pdo->prepare('SELECT chiuso FROM attivita WHERE id=? FOR UPDATE');
  $lock->execute([$id]);
  if ((int)$lock->fetchColumn() === 1) {
    $pdo->rollBack();
    header('Location: /biosound/attivitae_chiuse.php?closed=1&already=1');
    exit;
  }

  // Pulisci presenze/esito pre-esistenti
  $pdo->prepare('DELETE FROM lezione_presenza WHERE attivita_id=?')->execute([$id]);
  $pdo->prepare('DELETE FROM attivita_esito    WHERE attivita_id=?')->execute([$id]);

  // Inserisci presenze (minuti = durMap) ed esito
  foreach ($partecipanti as $p) {
    $dipId = $p['id'];
    $sum = 0;

    foreach ($lezioni as $dl) {
      $dlId = $dl['id'];
      if (isset($pres[$dlId][$dipId])) {
        $m = $durMap[$dlId] ?? 0;
        if ($m > 0) {
          $pdo->prepare('INSERT INTO lezione_presenza (id, attivita_id, datalezione_id, dipendente_id, presente, minuti)
                         VALUES (?,?,?,?,1,?)')
              ->execute([uuid16(), $id, $dlId, $dipId, $m]);
          $sum += $m;
        }
      }
    }

    // regola 75% con override
    $hasOverride = isset($override[$dipId]);
    if ($hasOverride) {
      $superato = isset($pass[$dipId]) ? 1 : 0;
    } else {
      $meets75 = ($requiredMin > 0) ? ($sum >= ceil($requiredMin * 0.75)) : false;
      $superato = isset($pass[$dipId]) ? 1 : ($meets75 ? 1 : 0);
    }

    $pdo->prepare('INSERT INTO attivita_esito (attivita_id, dipendente_id, superato, minuti_totali)
                   VALUES (?,?,?,?)')
        ->execute([$id, $dipId, $superato, $sum]);

    if ($requiredMin > 0 && $sum > $requiredMin) {
      $warn[] = "{$p['cognome']} {$p['nome']}: " . number_format($sum/60,1,',','.') . " h > corso " . number_format($requiredMin/60,1,',','.') . " h";
    }
  }

  // ===== Upload PDF scheda corso (opzionale) =====
  if (!empty($_FILES['scheda']) && is_array($_FILES['scheda']) && $_FILES['scheda']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['scheda'];
    if ($f['error'] === UPLOAD_ERR_OK && $f['size'] > 0) {
      // limite soft 20MB
      if ($f['size'] > 20 * 1024 * 1024) {
        throw new RuntimeException('Il PDF della scheda supera i 20 MB.');
      }
      // controllo MIME reale
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = $finfo->file($f['tmp_name']) ?: '';
      $extOk = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) === 'pdf';
      if ($mime !== 'application/pdf' || !$extOk) {
        throw new RuntimeException('La scheda corso deve essere un PDF valido.');
      }
      // path destinazione
      $dir = __DIR__ . '/resources/scheda/' . $id;
      if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
          throw new RuntimeException('Impossibile creare la cartella scheda corso.');
        }
      }
      $ts = date('Ymd_His');
      $base = clean_filename(pathinfo($f['name'], PATHINFO_FILENAME));
      $dest = $dir . '/scheda_corso-' . $ts . '-' . $base . '.pdf';
      if (!move_uploaded_file($f['tmp_name'], $dest)) {
        throw new RuntimeException('Salvataggio del PDF scheda corso fallito.');
      }
      $savedScheda = $dest; // solo informativo; nessun salvataggio DB richiesto
    } else {
      // errori noti
      if ($f['error'] !== UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Errore upload scheda corso (codice '.$f['error'].').');
      }
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo "<pre>Errore salvataggio presenze/esito o upload scheda:\n".htmlspecialchars($e->getMessage(),ENT_QUOTES)."</pre>";
  exit;
}

/* =======================
   Generazione attestati (SOLO superato)
======================= */
$oggiIT = date('d/m/Y');

/* intervallo attività per attestato */
$dfStmt = $pdo->prepare(<<<'SQL'
  SELECT MIN(dl.data) AS data_inizio, MAX(dl.data) AS data_fine
  FROM incarico i
  JOIN datalezione dl ON dl.incarico_id = i.id
  WHERE i.attivita_id = ?
SQL);
$dfStmt->execute([$id]);
$df = $dfStmt->fetch(PDO::FETCH_ASSOC);

$written = [];
try {
  // esiti + anagrafiche
  $esStmt = $pdo->prepare(<<<'SQL'
    SELECT ae.dipendente_id, ae.superato, ae.minuti_totali,
           d.nome, d.cognome, d.codice_fiscale, d.datanascita, d.luogonascita
    FROM attivita_esito ae
    JOIN dipendente d ON d.id=ae.dipendente_id
    WHERE ae.attivita_id=?
SQL);
  $esStmt->execute([$id]);
  $esiti = [];
  while ($r = $esStmt->fetch(PDO::FETCH_ASSOC)) $esiti[$r['dipendente_id']] = $r;

  foreach ($partecipanti as $p) {
    $dipId = $p['id'];
    $es = $esiti[$dipId] ?? null;
    if (!$es) continue;
    if ((int)$es['superato'] !== 1) continue;

    // evita doppioni
    $exists = $pdo->prepare('SELECT id FROM attestato WHERE attivita_id = ? AND dipendente_id = ? LIMIT 1');
    $exists->execute([$id, $dipId]);
    if ($exists->fetchColumn()) continue;

    // scadenza: giorno/mese oggi, anno = oggi + validita
    $validitaAnni = (int)($att['validita_anni'] ?? 0);
    $scadenzaYmd  = $validitaAnni > 0 ? expiry_from_today_years($validitaAnni) : null;
    $scadenzaIT   = $scadenzaYmd ? fmt_it_date($scadenzaYmd) : '—';

    $values = [
      'titolo_corso'   => $att['corso_titolo'] ?? '',
      'normativa'      => $att['normativa'] ?? '',
      'nominativo'     => trim(($p['cognome'] ?? '').' '.(($p['nome'] ?? ''))),
      'codice_fiscale' => $p['codice_fiscale'] ?? '',
      'luogonascita'   => $p['luogonascita'] ?? '',
      'datanascita'    => fmt_it_date($p['datanascita'] ?? null),
      'durata'         => (int)$att['durata_ore'].' ore',
      'modalita'       => $att['modalita'] ?? '',
      'sede'           => $att['luogo'] ?? '',
      'datainizio'     => fmt_it_date($df['data_inizio'] ?? null),
      'datafine'       => fmt_it_date($df['data_fine'] ?? null),
      'datarilascio'   => $scadenzaYmd
                          ? "Rilasciato il: $oggiIT — Scadenza: $scadenzaIT"
                          : "Rilasciato il: $oggiIT",
    ];

    // coordinate del template
    $coords = [
      'titolo_corso'   => [62, 249],
      'normativa'      => [62, 270],
      'nominativo'     => [198, 344],
      'codice_fiscale' => [198, 368],
      'luogonascita'   => [198, 389],
      'datanascita'    => [198, 410],
      'durata'         => [148, 453],
      'modalita'       => [136, 475],
      'sede'           => [109, 497],
      'datainizio'     => [145, 518],
      'datafine'       => [134, 540],
      'datarilascio'   => [200, 740],
    ];

    // PDF
    $pdf = new Fpdi('P','pt','A4');
    $pdf->AddPage();
    $tpl = $pdf->setSourceFile($templatePdf);
    $pageId = $pdf->importPage(1);
    $pdf->useTemplate($pageId, 0, 0, 595.28, 841.89, true);
    $pdf->SetTextColor(0,0,0);

    foreach ($coords as $key => [$x,$y]) {
      $val = $values[$key] ?? '';
      if ($val === '') continue;
      if     ($key === 'titolo_corso') $pdf->SetFont('Helvetica','B',20);
      elseif ($key === 'nominativo')   $pdf->SetFont('Helvetica','B',16);
      else                             $pdf->SetFont('Helvetica','',11);
      $pdf->SetXY($x,$y);
      $pdf->Cell(0,12,pdf_text($val),0,0,'L');
    }

    $pdfBytes = $pdf->Output('S');
    $attestatoId = uuid16();
    $filename = clean_filename($att['id']).'_'.clean_filename($p['codice_fiscale']).'_'.clean_filename($p['cognome']).'_'.clean_filename($p['nome']).'.pdf';
    $dir = __DIR__.'/resources/attestati/'.$attestatoId;
    if (!is_dir($dir)) mkdir($dir,0775,true);
    $pdfPath = $dir.'/'.$filename;
    file_put_contents($pdfPath,$pdfBytes);

    $filesMeta = [[
      'original' => "Attestato - {$att['corso_titolo']} - {$p['cognome']} {$p['nome']}.pdf",
      'stored'   => $filename,
      'size'     => strlen($pdfBytes),
      'mime'     => 'application/pdf',
    ]];

    $ins = $pdo->prepare("INSERT INTO attestato (id,dipendente_id,corso_id,attivita_id,data_emissione,data_scadenza,note,allegati)
                          VALUES (?,?,?,?,?,?,?,?)");
    $ins->execute([$attestatoId,$dipId,$att['corso_id'],$att['id'],date('Y-m-d'),$scadenzaYmd,null,json_encode($filesMeta,JSON_UNESCAPED_UNICODE)]);
  }

  // marca attività chiusa
  $pdo->prepare('UPDATE attivita SET chiuso=1 WHERE id=?')->execute([$id]);

} catch (Throwable $e) {
  foreach ($written as $f) { if (is_file($f)) @unlink($f); }
  http_response_code(500);
  echo "<pre>Errore generazione attestati:\n".htmlspecialchars($e->getMessage(),ENT_QUOTES)."</pre>";
  exit;
}

/* =======================
   Redirect + avvisi
======================= */
if (!empty($warn)) {
  $_SESSION['__chiusura_warn__'] = $warn;
}
if ($savedScheda) {
  $_SESSION['__scheda_saved__'] = basename($savedScheda);
}
header('Location: /biosound/attivitae_chiuse.php?closed=1');
exit;
