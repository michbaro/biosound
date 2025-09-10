<?php
include 'init.php';
if (session_status()===PHP_SESSION_NONE) session_start();

if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function csrf_ok(){return hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'');}
function h($s){return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');}
function titlecase($s){ $s=mb_strtolower(trim($s),'UTF-8'); return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8'); }

/* =========================
   AJAX ENDPOINTS (prima di qualsiasi output!)
========================= */
// Replica utilità da attivita_chiusa.php per costruire URL attestato
function attestato_url(?string $attestatoId, ?string $json) : ?string {
  if (!$attestatoId || !$json) return null;
  $arr = json_decode($json, true);
  if (!is_array($arr) || empty($arr[0]['stored'])) return null;
  $stored = $arr[0]['stored'];
  return "./resources/attestati/{$attestatoId}/{$stored}";
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='attestati_by_dip'){
  header('Content-Type: application/json');
  $id=trim($_POST['id']??'');
  try{
    $st=$pdo->prepare("SELECT a.id, a.corso_id, c.titolo AS corso_titolo,
                              DATE_FORMAT(a.data_emissione,'%Y-%m-%d') data_emissione,
                              DATE_FORMAT(a.data_scadenza,'%Y-%m-%d') data_scadenza,
                              a.allegati AS allegati_json
                       FROM attestato a
                       JOIN corso c ON c.id=a.corso_id
                       WHERE a.dipendente_id=?");
    $st->execute([$id]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$r){
      $r['view_url'] = attestato_url($r['id'] ?? null, $r['allegati_json'] ?? null);
      unset($r['allegati_json']);
    }
    echo json_encode(['ok'=>true,'items'=>$rows]);
  }catch(Throwable $e){ echo json_encode(['ok'=>false,'msg'=>'TECH']); }
  exit;
}

/* =========================
   PARAMETRI E DATI PAGINA
========================= */
$azienda_id = $_GET['id'] ?? ($_GET['azienda_id'] ?? '');

/* ---- Dati base: elenco aziende e sedi (per filtri) ---- */
$aziende = $pdo->query("SELECT id, ragionesociale FROM azienda ORDER BY ragionesociale")->fetchAll(PDO::FETCH_ASSOC);
$sediAll = $pdo->query("SELECT id, nome, azienda_id, is_legale FROM sede ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

/* ---- Contesto intestazione ---- */
$contextName = 'Seleziona un\'azienda';
if ($azienda_id) {
  $st=$pdo->prepare("SELECT ragionesociale FROM azienda WHERE id=?");
  $st->execute([$azienda_id]);
  if($nome=$st->fetchColumn()) $contextName=$nome; else $azienda_id='';
}

/* ---- Query elenco corsi/attività per azienda ----
   Associazione: un'attività appartiene all'azienda se ha almeno un dipendente iscritto
   (via attivita_dipendente -> dipendente -> dipendente_sede -> sede.azienda_id)
*/
$attivita=[];
if ($azienda_id) {
  $sql = "
  SELECT a.id AS attivita_id, a.corso_id, c.titolo AS corso_titolo, a.modalita, a.note, a.chiuso,
         MIN(dl.data) AS data_inizio, MAX(dl.data) AS data_fine,
         COUNT(DISTINCT ad.dipendente_id) AS n_iscritti
  FROM attivita a
  JOIN attivita_dipendente ad ON ad.attivita_id = a.id
  JOIN dipendente d ON d.id = ad.dipendente_id
  JOIN dipendente_sede ds ON ds.dipendente_id = d.id
  JOIN sede s ON s.id = ds.sede_id
  JOIN corso c ON c.id = a.corso_id
  LEFT JOIN incarico i ON i.attivita_id = a.id
  LEFT JOIN datalezione dl ON dl.incarico_id = i.id
  WHERE s.azienda_id = ?
  GROUP BY a.id
  ORDER BY COALESCE(MIN(dl.data), '9999-12-31') ASC, a.id ASC";
  $st=$pdo->prepare($sql);
  $st->execute([$azienda_id]);
  $attivita=$st->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- Elenco dipendenti dell'azienda selezionata ---- */
$dipendenti=[];
if ($azienda_id) {
  $st=$pdo->prepare("SELECT d.id,d.nome,d.cognome,d.codice_fiscale, s.id sede_id, s.nome sede_nome
                     FROM dipendente d
                     JOIN dipendente_sede ds ON ds.dipendente_id=d.id
                     JOIN sede s ON s.id=ds.sede_id
                     WHERE s.azienda_id=?
                     ORDER BY d.cognome,d.nome");
  $st->execute([$azienda_id]);
  $dipendenti=$st->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- Navbar in base al ruolo (coerente con dipendenti.php) ---- */
$role=$_SESSION['role']??'utente';
if($role==='admin') include 'navbar_a.php';
elseif($role==='dev') include 'navbar_d.php';
else include 'navbar.php';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Scadenziario</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* Stile coerente con dipendenti.php */
body {
  --page-bg: #f0f2f5; --page-fg: #2e3a45; --page-muted:#6b7280; --page-pri:#66bb6a; --page-pri-d:#5aad5c;
  --page-dark:#5b6670; --page-radius:8px; --page-shadow:0 10px 30px rgba(0,0,0,.08);
  margin:0; background:var(--page-bg); color:var(--page-fg);
  font:16px system-ui, -apple-system, Segoe UI, Roboto;
}
*{box-sizing:border-box}
.container{max-width:1100px;margin:28px auto;padding:0 14px}
h1{text-align:center;margin:.2rem 0}
.context{color:#6b7280;text-align:center;margin-bottom:10px}

.section{margin-top:18px}
.section h2{font-size:1.2rem;margin:.2rem 0 .6rem}

.toolbar{ display:flex; align-items:center; gap:.6rem; margin-bottom:.8rem; flex-wrap:wrap; }
.toolbar .right{margin-left:auto; display:flex; gap:.6rem}

select,input{ height:36px; padding:.4rem .6rem; border:1px solid #d7dde3; background:#fff; border-radius:9px; }

.btn{ display:inline-flex; align-items:center; gap:.45rem; padding:.5rem .95rem; border:0; border-radius:999px; color:#fff; font-weight:600; cursor:pointer; white-space:nowrap; }
.btn-green{background:#66bb6a} .btn-green:hover{background:#5aad5c}
.btn-grey{background:#6c757d} .btn-grey:hover{opacity:.92}

.list{display:flex;flex-direction:column;gap:.55rem}
.card{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:.6rem .9rem}
.item{display:flex;align-items:center;justify-content:space-between}
.item .left{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
.badge{background:#eef2f7;border-radius:999px;padding:.1rem .5rem;color:#667}
.empty{color:#7a8691;text-align:center;padding:1.2rem}
.icon-btn{background:none;border:0;color:#4caf50;font-size:1.05rem;cursor:pointer}
.icon-btn:hover{opacity:.85}

.grid{display:grid; grid-template-columns: 1fr 1fr; gap:12px}
@media (max-width: 840px){ .grid{ grid-template-columns:1fr } }

/* Modal semplice per attestati */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:2000}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:min(820px,96vw);padding:16px}
.modal .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem}
.modal .close{background:none;border:0;font-size:1.4rem;color:#888;cursor:pointer}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border:1px solid #e8e8e8;padding:.5rem .6rem;text-align:left}
.table thead th{background:#f7faf8}

.filter-row{display:flex; gap:.5rem; flex-wrap:wrap}
</style>
</head>
<body>
<div class="container">
  <h1>Scadenziario</h1>
  <div class="context"><?= h($contextName) ?></div>

  <!-- Selettore azienda in alto (se arrivo senza ?id) -->
  <div class="toolbar" style="justify-content:center">
    <form method="get" action="" style="display:flex; gap:.5rem; flex-wrap:wrap">
      <select name="id" required>
        <option value="">Seleziona azienda…</option>
        <?php foreach($aziende as $a): ?>
          <option value="<?= h($a['id']) ?>" <?= $azienda_id && $azienda_id===$a['id']?'selected':'' ?>><?= h($a['ragionesociale']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-green" type="submit"><i class="bi bi-check2"></i> Conferma</button>
    </form>
  </div>

  <?php if ($azienda_id): ?>
  <div class="grid">
    <!-- Sezione CORSI/ATTIVITÀ -->
    <section class="section">
      <div class="card">
        <h2><i class="bi bi-mortarboard"></i> Corsi / Attività</h2>
        <div class="filter-row" style="margin:.4rem 0 .6rem">
          <select id="f-stato">
            <option value="">Aperte e chiuse</option>
            <option value="aperte">Solo aperte</option>
            <option value="chiuse">Solo chiuse</option>
          </select>
          <input type="text" id="f-corso" placeholder="Filtra per corso…">
          <label>Da <input type="date" id="f-from"></label>
          <label>A <input type="date" id="f-to"></label>
        </div>
        <div id="att-list" class="list">
          <?php if (empty($attivita)): ?>
            <div class="empty">Nessuna attività trovata per questa azienda.</div>
          <?php else: foreach($attivita as $a):
            $isClosed = (int)$a['chiuso']===1;
            $icon = $isClosed ? 'bi-lock-fill' : 'bi-unlock';
            $title = $isClosed ? 'Attività chiusa' : 'Attività aperta';
            $start = $a['data_inizio'] ?: '';
            $end   = $a['data_fine']   ?: '';
            $href  = $isClosed ? ('attivita_chiusa.php?id='.rawurlencode($a['attivita_id']))
                               : ('attivita.php?id='.rawurlencode($a['attivita_id']));
          ?>
          <div class="item att-row" data-stato="<?= $isClosed?'chiuse':'aperte' ?>" data-corso="<?= strtolower(h($a['corso_titolo'])) ?>" data-from="<?= h($start) ?>" data-to="<?= h($end) ?>">
            <div class="left">
              <i class="bi <?= $icon ?>" title="<?= h($title) ?>" aria-label="<?= h($title) ?>"></i>
              <a href="<?= h($href) ?>" style="font-weight:700; text-decoration:none; color:inherit">
                <?= h($a['corso_titolo']) ?> (<?= h($a['corso_id']) ?>)
              </a>
              <span class="badge">Iscritti: <?= (int)$a['n_iscritti'] ?></span>
              <span class="badge">Inizio: <?= h($start?:'—') ?></span>
              <span class="badge">Fine: <?= h($end?:'—') ?></span>
            </div>
            <div class="right">
              <a class="icon-btn" href="<?= h($href) ?>" title="Apri dettagli"><i class="bi bi-box-arrow-up-right"></i></a>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </section>

    <!-- Sezione DIPENDENTI -->
    <section class="section">
      <div class="card">
        <h2><i class="bi bi-people"></i> Dipendenti</h2>
        <div class="filter-row" style="margin:.4rem 0 .6rem">
          <select id="f-sede">
            <option value="">Tutte le sedi</option>
            <?php foreach($sediAll as $s){ if($s['azienda_id']===$azienda_id){ ?>
              <option value="<?= h($s['id']) ?>"><?= h($s['nome']) ?></option>
            <?php }} ?>
          </select>
          <input type="text" id="f-dip-q" placeholder="Cerca nome, cognome o CF…">
        </div>
        <div id="dip-list" class="list">
          <?php if (empty($dipendenti)): ?>
            <div class="empty">Nessun dipendente per questa azienda.</div>
          <?php else: foreach($dipendenti as $d): ?>
          <div class="item dip-row" data-se="<?= h($d['sede_id']) ?>" data-text="<?= strtolower(h($d['nome'].' '.$d['cognome'].' '.$d['codice_fiscale'])) ?>">
            <div class="left">
              <i class="bi bi-person-badge" title="Vedi attestati" aria-hidden="true"></i>
              <span style="font-weight:700"><?= h($d['cognome'].' '.$d['nome']) ?></span>
              <span class="badge"><?= h($d['codice_fiscale']) ?></span>
              <span class="badge"><?= h($d['sede_nome']) ?></span>
            </div>
            <button class="icon-btn btn-att" data-id="<?= h($d['id']) ?>" title="Mostra attestati"><i class="bi bi-journal-text"></i></button>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </section>
  </div>
  <?php endif; ?>
</div>

<!-- Modal attestati dipendente -->
<div class="overlay" id="att-modal">
  <div class="modal">
    <div class="head"><h3 id="att-title" style="margin:0">Attestati</h3><button class="close" data-close>&times;</button></div>
    <div id="att-body">
      <div class="empty">Caricamento…</div>
    </div>
  </div>
</div>

<script>
const aziendaId = <?= json_encode($azienda_id) ?>;
const attRows = [...document.querySelectorAll('.att-row')];
const dipRows = [...document.querySelectorAll('.dip-row')];

/* ---- Filtri Corsi/Attività ---- */
const fStato = document.getElementById('f-stato');
const fCorso = document.getElementById('f-corso');
const fFrom  = document.getElementById('f-from');
const fTo    = document.getElementById('f-to');

function applyAttFilters(){
  const st = (fStato?.value||'').toLowerCase();
  const q  = (fCorso?.value||'').trim().toLowerCase();
  const from = fFrom?.value || '';
  const to   = fTo?.value || '';
  attRows.forEach(r=>{
    let ok=true;
    if(st && r.dataset.stato!==st) ok=false;
    if(q && !(r.dataset.corso||'').includes(q)) ok=false;
    const rs = r.dataset.from || '';
    const re = r.dataset.to   || '';
    if(from && (!rs || rs < from)) ok=false; // inizio deve essere >= from
    if(to   && (!re || re > to)) ok=false;   // fine deve essere <= to
    r.style.display = ok ? 'flex' : 'none';
  });
  const list=document.getElementById('att-list');
  let empty=list.querySelector('.empty');
  const anyVisible = attRows.some(r=>r.style.display!=='none');
  if(!anyVisible){ if(!empty){ empty=document.createElement('div'); empty.className='empty'; empty.textContent='Nessun risultato.'; list.appendChild(empty);} }
  else if(empty) empty.remove();
}
[fStato,fCorso,fFrom,fTo].forEach(el=> el && el.addEventListener('input', applyAttFilters));

/* ---- Filtri Dipendenti ---- */
const fSede = document.getElementById('f-sede');
const fDipQ = document.getElementById('f-dip-q');

function applyDipFilters(){
  const sede=fSede?.value||''; const q=(fDipQ?.value||'').toLowerCase().trim();
  dipRows.forEach(r=>{
    let ok=true;
    if(sede && r.dataset.se!==sede) ok=false;
    if(q && !(r.dataset.text||'').includes(q)) ok=false;
    r.style.display=ok?'flex':'none';
  });
  const list=document.getElementById('dip-list');
  let empty=list.querySelector('.empty');
  const anyVisible = dipRows.some(r=>r.style.display!=='none');
  if(!anyVisible){ if(!empty){ empty=document.createElement('div'); empty.className='empty'; empty.textContent='Nessun risultato.'; list.appendChild(empty);} }
  else if(empty) empty.remove();
}
[fSede,fDipQ].forEach(el=> el && el.addEventListener('input', applyDipFilters));

/* ---- Modal attestati ---- */
const attModal=document.getElementById('att-modal');
function openModal(){ attModal.classList.add('open'); }
function closeModal(){ attModal.classList.remove('open'); }
attModal.addEventListener('click', e=>{ if(e.target===attModal || e.target.dataset.close!==undefined) closeModal(); });

async function loadAttestati(dipId){
  const fd=new FormData(); fd.append('ajax','attestati_by_dip'); fd.append('id',dipId);
  const res=await fetch(location.href,{method:'POST', body:fd});
  const ct=res.headers.get('content-type')||'';
  if(!ct.includes('application/json')){ throw new Error('Bad content-type'); }
  const j=await res.json();
  const body=document.getElementById('att-body');
  if(!j.ok || !Array.isArray(j.items) || j.items.length===0){ body.innerHTML='<div class="empty">Nessun attestato disponibile.</div>'; return; }
  const rows=j.items.map(a=>`
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:.5rem">
          <i class="bi bi-patch-check"></i>
          <div>
            <div style=\"font-weight:700\">${(a.corso_titolo||a.corso_id||'')}</div>
            <div style=\"color:#6b7280; font-size:.9rem\">${a.data_emissione? 'Emesso: '+a.data_emissione : ''}${a.data_scadenza? ' • Scadenza: '+a.data_scadenza : ''}</div>
          </div>
        </div>
      </td>
      <td style="white-space:nowrap; text-align:right">
        ${a.view_url? `<a class=\"btn btn-grey\" href=\"${a.view_url}\" target=\"_blank\"><i class=\"bi bi-box-arrow-up-right\"></i> Apri</a>` : ''}
      </td>
    </tr>`).join('');
  body.innerHTML = `
    <table class="table">
      <thead><tr><th>Attestato</th><th style="width:1%"></th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}

document.querySelectorAll('.btn-att').forEach(b=>{
  b.addEventListener('click', async e=>{
    const id=e.currentTarget.dataset.id;
    document.getElementById('att-title').textContent='Attestati';
    document.getElementById('att-body').innerHTML='<div class="empty">Caricamento…</div>';
    openModal();
    try{ await loadAttestati(id); }catch(err){ document.getElementById('att-body').innerHTML='<div class="empty">Errore di caricamento.</div>'; }
  });
});
</script>
</body>
</html>