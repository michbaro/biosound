<?php
// attivitae_chiuse.php — elenco attività CHIUSE con ricerca, filtri e popup di visualizzazione
include 'init.php';

$added        = isset($_GET['added']);
$deleted      = isset($_GET['deleted']);
$pdfgenerated = isset($_GET['pdfgenerated']);

$corsiList   = $pdo->query('SELECT id, titolo FROM corso ORDER BY titolo')->fetchAll(PDO::FETCH_ASSOC);
$docentiList = $pdo->query('SELECT id, nome, cognome FROM docente ORDER BY cognome, nome')->fetchAll(PDO::FETCH_ASSOC);

$sql = <<<SQL
  SELECT
    a.id,
    a.modalita,
    a.note,
    c.id       AS corso_id,
    c.titolo   AS corso_titolo,
    c.categoria,
    c.tipologia,
    GROUP_CONCAT(DISTINCT dic.docente_id) AS docenti_ids
  FROM attivita a
  JOIN corso c           ON c.id = a.corso_id
  LEFT JOIN incarico i   ON i.attivita_id = a.id
  LEFT JOIN docenteincarico dic ON dic.incarico_id = i.id
  WHERE a.chiuso = 1
  GROUP BY a.id, a.modalita, a.note, c.id, c.titolo, c.categoria, c.tipologia
  ORDER BY CAST(SUBSTRING_INDEX(a.id,'-',-1) AS UNSIGNED) DESC
SQL;
$attivita = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Attività Chiuse</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
:root {
  --bg:#f0f2f5;
  --fg:#2e3a45;
  --radius:8px;
  --shadow:rgba(0,0,0,0.08);
  --font:'Segoe UI',sans-serif;
  --pri:#66bb6a;
  --err:#d9534f;
}

*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--fg);font-family:var(--font);}
.container{max-width:900px;margin:2rem auto;padding:0 1rem;}
h1{text-align:center;margin-bottom:1rem;}

#toast {
  position: fixed;
  bottom: 1rem;
  left: 1rem;
  background: var(--pri);
  color: #fff;
  padding: .75rem 1.25rem;
  border-radius: var(--radius);
  box-shadow: 0 2px 6px var(--shadow);
  opacity: 1;
  transition: opacity .5s ease-out;
  z-index: 1000;
}
#toast.alert-danger {
  background-color: var(--err) !important;
  color: #fff !important;
}

.add-container{text-align:center;margin-bottom:1rem;}
.add-btn{
  display:inline-flex;align-items:center;gap:.5rem;
  background:var(--pri);color:#fff;
  padding:.5rem 1rem;border-radius:var(--radius);
  text-decoration:none;transition:background .2s,transform .2s;
}
.add-btn:hover{background:#5aad5c;transform:translateY(-2px);}

.filter-area {
  display:flex; flex-direction:column; gap:.5rem;
  margin-bottom:1rem;
}
.filter-area input,
.filter-select {
  width:100%; height:2.5rem;
  padding:.5rem .75rem;
  border:1px solid #ccc; border-radius:var(--radius);
  font-size:1rem;
}
.filter-row {
  display:flex;
  gap:.5rem;
}
.filter-select {
  flex: 1 1 0;
  min-width: 0;
  font-size:.9rem;
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
.info .id{font-weight:bold;}
.info span{color:#666;font-size:.9rem;}
.actions {
  display:flex;
  align-items:center;
  gap:.5rem;
}
.icon-btn{
  background:none;border:none;color:var(--pri);
  font-size:1.2rem;cursor:pointer;transition:color .2s;
  text-decoration:none;
}
.icon-btn:hover{color:#5aad5c;}

/* Popup Modal */
.modal-overlay {
  display:none;
  position:fixed;
  top:0; left:0;
  width:100%; height:100%;
  background:rgba(0,0,0,0.5);
  justify-content:center;
  align-items:center;
  z-index:2000;
}
.modal {
  background:#fff;
  border-radius:var(--radius);
  box-shadow:0 4px 16px rgba(0,0,0,0.2);
  max-width:600px;
  width:90%;
  padding:1.5rem;
  position:relative;
}
.modal h2 {
  margin-bottom:1rem;
}
.modal .close-btn {
  position:absolute;
  top:.5rem;
  right:.5rem;
  background:none;
  border:none;
  font-size:1.5rem;
  cursor:pointer;
  color:#666;
}
.modal .field {
  margin-bottom:.75rem;
}
.modal .field label {
  font-weight:bold;
  display:block;
  margin-bottom:.25rem;
}
.modal .field span {
  display:block;
  padding:.5rem;
  background:#f5f5f5;
  border-radius:var(--radius);
  font-size:.9rem;
}
  </style>
</head>
<body>
<?php
  $role = $_SESSION['role'] ?? 'utente';
  switch ($role) {
    case 'admin': include 'navbar_a.php'; break;
    case 'dev': include 'navbar_d.php'; break;
    default: include 'navbar.php';
  }
?>  

<div class="container">
  <h1>Attività Chiuse</h1>

  <?php if ($added): ?><div id="toast" class="alert alert-success">Attività aggiunta con successo!</div><?php endif; ?>
  <?php if ($deleted): ?><div id="toast" class="alert-danger">Attività eliminata con successo!</div><?php endif; ?>
  <?php if ($pdfgenerated): ?><div id="toast" class="alert alert-success">PDF generato con successo!</div><?php endif; ?>

  <div class="filter-area">
    <input id="search-id" type="text" placeholder="Cerca per ID…">
    <div class="filter-row">
      <select id="filter-modalita"  class="filter-select">
        <option value="all">Tutte le modalità</option>
        <option>Presenza fisica (Aula)</option>
        <option>Videochiamata (FAD Sincrona)</option>
        <option>E-Learning (FAD Asincrona)</option>
        <option>Mista (Blended Learning)</option>
      </select>
      <select id="filter-categoria" class="filter-select">
        <option value="all">Tutte le categorie</option>
        <option>HACCP</option>
        <option>Sicurezza</option>
        <option>Antincendio</option>
        <option>Primo Soccorso</option>
        <option>Macchine Operatrici</option>
      </select>
      <select id="filter-tipologia" class="filter-select">
        <option value="all">Tutte le tipologie</option>
        <option>Primo Rilascio</option>
        <option>Aggiornamento</option>
      </select>
      <select id="filter-corso" class="filter-select">
        <option value="all">Tutti i corsi</option>
        <?php foreach ($corsiList as $c): ?>
          <option value="<?=htmlspecialchars($c['id'],ENT_QUOTES)?>"><?=htmlspecialchars($c['titolo'],ENT_QUOTES)?></option>
        <?php endforeach; ?>
      </select>
      <select id="filter-docente" class="filter-select">
        <option value="all">Tutti i docenti</option>
        <?php foreach ($docentiList as $d): ?>
          <option value="<?=htmlspecialchars($d['id'],ENT_QUOTES)?>"><?=htmlspecialchars("{$d['cognome']} {$d['nome']}",ENT_QUOTES)?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <?php if (empty($attivita)): ?>
    <p style="text-align:center;color:#666;">Nessuna attività chiusa.</p>
  <?php else: foreach ($attivita as $a): ?>
    <div class="item"
         data-id="<?=htmlspecialchars($a['id'],ENT_QUOTES)?>"
         data-modalita="<?=htmlspecialchars($a['modalita'],ENT_QUOTES)?>"
         data-categoria="<?=htmlspecialchars($a['categoria'],ENT_QUOTES)?>"
         data-tipologia="<?=htmlspecialchars($a['tipologia'],ENT_QUOTES)?>"
         data-corso="<?=htmlspecialchars($a['corso_titolo'],ENT_QUOTES)?>"
         data-note="<?=htmlspecialchars($a['note'] ?? '',ENT_QUOTES)?>"
         data-docenti="<?=htmlspecialchars($a['docenti_ids'] ?? '',ENT_QUOTES)?>">

      <div class="info">
        <span class="id"><?=htmlspecialchars($a['id'],ENT_QUOTES)?></span>
        <span><?=htmlspecialchars($a['corso_titolo'],ENT_QUOTES)?></span>
        <span><?=htmlspecialchars($a['modalita'],ENT_QUOTES)?></span>
        <span><?=htmlspecialchars($a['categoria'],ENT_QUOTES)?></span>
        <span><?=htmlspecialchars($a['tipologia'],ENT_QUOTES)?></span>
      </div>

      <div class="actions">
        <button class="icon-btn view-btn" title="Visualizza"><i class="bi bi-eye"></i></button>
        <a href="/biosound/apri_corso.php?id=<?=urlencode($a['id'])?>"
           class="icon-btn"
           title="Riapri corso"
           onclick="return confirm('Sei sicuro di voler riaprire questa attività?');">
           <i class="bi bi-unlock"></i>
        </a>
      </div>

    </div>
  <?php endforeach; endif; ?>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <button class="close-btn" onclick="closeModal()">&times;</button>
    <h2>Dettagli Attività</h2>
    <div class="field"><label>ID</label><span id="m-id"></span></div>
    <div class="field"><label>Corso</label><span id="m-corso"></span></div>
    <div class="field"><label>Modalità</label><span id="m-modalita"></span></div>
    <div class="field"><label>Categoria</label><span id="m-categoria"></span></div>
    <div class="field"><label>Tipologia</label><span id="m-tipologia"></span></div>
    <div class="field"><label>Note</label><span id="m-note"></span></div>
  </div>
</div>

<script>
window.addEventListener('load', () => {
  const t = document.getElementById('toast');
  if (t) setTimeout(() => t.style.opacity = '0', 2000);
});

const inputId = document.getElementById('search-id'),
      fMod    = document.getElementById('filter-modalita'),
      fCat    = document.getElementById('filter-categoria'),
      fTipo   = document.getElementById('filter-tipologia'),
      fCorso  = document.getElementById('filter-corso'),
      fDoc    = document.getElementById('filter-docente'),
      items   = document.querySelectorAll('.item');

function applyFilters() {
  const idVal  = inputId.value.trim().toLowerCase(),
        modVal = fMod.value, catVal = fCat.value,
        tipVal = fTipo.value, corVal = fCorso.value,
        docVal = fDoc.value;

  items.forEach(item => {
    let vis = true;
    const id    = item.dataset.id.toLowerCase(),
          mod   = item.dataset.modalita,
          cat   = item.dataset.categoria,
          tip   = item.dataset.tipologia,
          cor   = item.dataset.corso,
          docs  = (item.dataset.docenti||'').split(',');

    if (idVal && !id.includes(idVal)) vis = false;
    if (modVal !== 'all' && mod !== modVal) vis = false;
    if (catVal !== 'all' && cat !== catVal) vis = false;
    if (tipVal !== 'all' && tip !== tipVal) vis = false;
    if (corVal !== 'all' && cor !== corVal) vis = false;
    if (docVal !== 'all' && !docs.includes(docVal)) vis = false;

    item.style.display = vis ? 'flex' : 'none';
  });
}
[inputId,fMod,fCat,fTipo,fCorso,fDoc].forEach(el => {
  el.addEventListener('input', applyFilters);
  el.addEventListener('change', applyFilters);
});

// Modal
const overlay = document.getElementById('modalOverlay');
const spanId = document.getElementById('m-id');
const spanCorso = document.getElementById('m-corso');
const spanMod = document.getElementById('m-modalita');
const spanCat = document.getElementById('m-categoria');
const spanTip = document.getElementById('m-tipologia');
const spanNote = document.getElementById('m-note');

document.querySelectorAll('.view-btn').forEach(btn => {
  btn.addEventListener('click', e => {
    const item = e.target.closest('.item');
    spanId.textContent = item.dataset.id;
    spanCorso.textContent = item.dataset.corso;
    spanMod.textContent = item.dataset.modalita;
    spanCat.textContent = item.dataset.categoria;
    spanTip.textContent = item.dataset.tipologia;
    spanNote.textContent = item.dataset.note || '-';
    overlay.style.display = 'flex';
  });
});
function closeModal(){ overlay.style.display='none'; }
overlay.addEventListener('click', e => {
  if(e.target === overlay) closeModal();
});
</script>
</body>
</html>
