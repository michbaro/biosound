<?php
// attestati.php — elenco attestati con filtri combinabili
require_once __DIR__ . '/init.php';

$added    = isset($_GET['added']);
$updated  = isset($_GET['updated']);
$deleted  = isset($_GET['deleted']);

$corsiList    = $pdo->query('SELECT id, titolo FROM corso ORDER BY titolo')->fetchAll(PDO::FETCH_ASSOC);
$aziendeList  = $pdo->query('SELECT id, ragionesociale FROM azienda ORDER BY ragionesociale')->fetchAll(PDO::FETCH_ASSOC);
$sediList     = $pdo->query('SELECT s.id, s.nome, s.azienda_id FROM sede s ORDER BY s.nome')->fetchAll(PDO::FETCH_ASSOC);

/*
 Recupero attestati:
 - dipendente (nome, cognome, CF)
 - corso (titolo)
 - tutte le aziende/sedi associate al dipendente (per filtri client-side),
   e una preview "principale" (prima per nome azienda e poi sede) da mostrare.
*/
$sql = <<<SQL
SELECT
  a.id,
  a.dipendente_id,
  d.nome       AS dip_nome,
  d.cognome    AS dip_cognome,
  d.codice_fiscale,
  a.corso_id,
  c.titolo     AS corso_titolo,
  a.data_emissione,
  a.data_scadenza,
  a.note,
  -- liste per filtri client-side
  GROUP_CONCAT(DISTINCT az.id ORDER BY az.ragionesociale SEPARATOR ',')   AS azienda_ids,
  GROUP_CONCAT(DISTINCT s.id  ORDER BY s.nome          SEPARATOR ',')     AS sede_ids,
  -- preview leggibile
  SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT az.ragionesociale ORDER BY az.ragionesociale SEPARATOR '||'),'||',1) AS azienda_nome,
  SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT s.nome           ORDER BY s.nome           SEPARATOR '||'),'||',1)   AS sede_nome
FROM attestato a
JOIN dipendente d         ON d.id = a.dipendente_id
JOIN corso c              ON c.id = a.corso_id
LEFT JOIN dipendente_sede ds ON ds.dipendente_id = d.id
LEFT JOIN sede s              ON s.id = ds.sede_id
LEFT JOIN azienda az          ON az.id = s.azienda_id
GROUP BY a.id, a.dipendente_id, d.nome, d.cognome, d.codice_fiscale, a.corso_id, c.titolo, a.data_emissione, a.data_scadenza, a.note
ORDER BY a.data_emissione DESC, a.id DESC
SQL;

$attestati = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Elenco Attestati</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
  :root{--bg:#f0f2f5;--fg:#2e3a45;--radius:8px;--shadow:rgba(0,0,0,0.08);--font:'Segoe UI',sans-serif;--pri:#66bb6a;--err:#d9534f;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{background:var(--bg);color:var(--fg);font-family:var(--font);}
  .container{max-width:1000px;margin:2rem auto;padding:0 1rem;}
  h1{text-align:center;margin-bottom:1rem;}
  #toast{position:fixed;bottom:1rem;left:1rem;background:var(--pri);color:#fff;padding:.75rem 1.25rem;border-radius:var(--radius);box-shadow:0 2px 6px var(--shadow);opacity:1;transition:opacity .5s ease-out;z-index:1000;}
  #toast.alert-danger{background:var(--err)!important;color:#fff!important;}
  .add-container{text-align:center;margin-bottom:1rem;}
  .add-btn{display:inline-flex;align-items:center;gap:.5rem;background:var(--pri);color:#fff;padding:.5rem 1rem;border-radius:var(--radius);text-decoration:none;transition:background .2s,transform .2s;}
  .add-btn:hover{background:#5aad5c;transform:translateY(-2px);}

  .filter-area{display:flex;flex-direction:column;gap:.5rem;margin-bottom:1rem;}
  .filter-row{display:flex;gap:.5rem;flex-wrap:wrap}
  .filter-select,.filter-input{flex:1 1 0;min-width:0;height:2.5rem;padding:.5rem .75rem;border:1px solid #ccc;border-radius:var(--radius);font-size:.95rem;}
  .date-row{display:flex;gap:.5rem;flex-wrap:wrap}
  .date-row .filter-input{flex:1 1 14rem}

  .item{background:#fff;border-radius:var(--radius);box-shadow:0 2px 6px var(--shadow);padding:1rem;margin-bottom:.75rem;display:flex;justify-content:space-between;align-items:center;transition:transform .15s,box-shadow .15s;}
  .item:hover{transform:translateY(-2px);box-shadow:0 4px 12px var(--shadow);}
  .info{display:flex;flex-direction:column;gap:.15rem}
  .topline{display:flex;gap:1rem;flex-wrap:wrap;align-items:baseline}
  .dip{font-weight:600}
  .cf{color:#666;font-size:.9rem}
  .corso{font-size:.95rem}
  .az{margin-top:.15rem}
  .az small{display:block;color:#666;font-size:.85rem}
  .dates{font-size:.9rem;color:#333}
  .actions{display:flex;align-items:center;gap:.5rem;}
  .icon-btn{background:none;border:none;color:var(--pri);font-size:1.25rem;cursor:pointer;text-decoration:none}
  .icon-btn:hover{color:#5aad5c;}
  .nores{margin-top:1rem;text-align:center;color:#666}
  /* --- Fix altezza campi data --- */
.filter-input[type="date"]{
  height: 2.5rem !important;   /* come prima */
  min-height: 2.5rem !important;
  line-height: 2.5rem;
  padding: .25rem .75rem;       /* leggermente più contenuto */
}

/* evita che il flex della riga li faccia crescere in verticale */
.date-row .filter-input{
  flex: 0 0 auto;
}

/* i contenitori delle due date occupano metà riga ciascuno */
.date-row > div{
  flex: 1 1 0;
  min-width: 14rem;
}

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
  <h1>Elenco Attestati</h1>

  <?php if ($added): ?>
    <div id="toast" class="alert alert-success">Attestato aggiunto con successo!</div>
  <?php endif; ?>
  <?php if ($updated): ?>
    <div id="toast" class="alert alert-success">Attestato aggiornato con successo!</div>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <div id="toast" class="alert-danger">Attestato eliminato con successo!</div>
  <?php endif; ?>

  <div class="add-container">
    <a href="/biosound/aggiungi_attestato.php" class="add-btn">
      <i class="bi bi-plus-lg"></i> Aggiungi Attestato
    </a>
  </div>

  <!-- FILTRI -->
  <div class="filter-area">
    <input id="search-dip" class="filter-input" type="text" placeholder="Cerca dipendente: nome, cognome, nome cognome o CF…">
    <div class="filter-row">
      <select id="filter-azienda" class="filter-select">
        <option value="all">Tutte le aziende</option>
        <?php foreach ($aziendeList as $a): ?>
          <option value="<?=htmlspecialchars($a['id'],ENT_QUOTES)?>"><?=htmlspecialchars($a['ragionesociale'],ENT_QUOTES)?></option>
        <?php endforeach; ?>
      </select>
      <select id="filter-sede" class="filter-select" disabled>
        <option value="all">Tutte le sedi</option>
        <?php foreach ($sediList as $s): ?>
          <option value="<?=htmlspecialchars($s['id'],ENT_QUOTES)?>" data-az="<?=htmlspecialchars($s['azienda_id'],ENT_QUOTES)?>">
            <?=htmlspecialchars($s['nome'],ENT_QUOTES)?>
          </option>
        <?php endforeach; ?>
      </select>
      <select id="filter-corso" class="filter-select">
        <option value="all">Tutti i corsi</option>
        <?php foreach ($corsiList as $c): ?>
          <option value="<?=htmlspecialchars($c['id'],ENT_QUOTES)?>"><?=htmlspecialchars($c['titolo'],ENT_QUOTES)?></option>
        <?php endforeach; ?>
      </select>
    </div>

<div class="date-row">
  <div style="flex:1; display:flex; flex-direction:column;">
    <label for="emis-date" style="font-size:.85rem; margin-bottom:.25rem;">Data di emissione</label>
    <input id="emis-date" class="filter-input" type="date">
  </div>
  <div style="flex:1; display:flex; flex-direction:column;">
    <label for="scad-date" style="font-size:.85rem; margin-bottom:.25rem;">Data di scadenza</label>
    <input id="scad-date" class="filter-input" type="date">
  </div>
</div>


  <?php if (empty($attestati)): ?>
    <p class="nores">Nessun risultato.</p>
  <?php else: ?>
    <?php foreach ($attestati as $r): ?>
<div class="item">
  <div class="rowline">
    <strong><?=htmlspecialchars($r['dip_cognome'].' '.$r['dip_nome'])?></strong>
    &nbsp;<?=htmlspecialchars($r['codice_fiscale'])?>
    &nbsp;<?=htmlspecialchars($r['corso_titolo'])?>
    &nbsp;<?=htmlspecialchars($r['azienda_nome'])?> <small>(<?=htmlspecialchars($r['sede_nome'])?>)</small>
    &nbsp;Emesso: <?=htmlspecialchars($r['data_emissione'])?>
    &nbsp;•&nbsp;Scadenza: <?=htmlspecialchars($r['data_scadenza'] ?? '—')?>
  </div>
  <div class="actions">
    <a class="icon-btn" href="/biosound/attestato.php?id=<?=urlencode($r['id'])?>" title="Modifica">
      <i class="bi bi-pencil"></i>
    </a>
  </div>
</div>

    <?php endforeach; ?>
    <p id="no-filter-results" class="nores" style="display:none;">Nessun risultato.</p>
  <?php endif; ?>
</div>

<script>
  // toast fade
  window.addEventListener('load', () => {
    const t = document.getElementById('toast');
    if (t) setTimeout(()=> t.style.opacity = '0', 2000);
  });

  const inputDip  = document.getElementById('search-dip');
  const fAz       = document.getElementById('filter-azienda');
  const fSede     = document.getElementById('filter-sede');
  const fCorso    = document.getElementById('filter-corso');
const emisDate  = document.getElementById('emis-date');
const scadDate  = document.getElementById('scad-date');

  const items     = Array.from(document.querySelectorAll('.item'));
  const noMsg     = document.getElementById('no-filter-results');

  function withinRange(dateStr, fromStr, toStr) {
    if (!dateStr) return false;
    const d = dateStr;
    if (fromStr && d < fromStr) return false;
    if (toStr   && d > toStr)   return false;
    return true;
  }

function applyFilters() {
  const q   = inputDip.value.trim().toLowerCase();
  const az  = fAz.value;
  const sd  = fSede.value;
  const cs  = fCorso.value;
  const ed  = emisDate.value; // data di emissione (singola)
  const sdt = scadDate.value; // data di scadenza (singola)

  let any = false;

  items.forEach(it => {
    let vis = true;

    // Ricerca dipendente: nome/cognome/CF (concatenati in data-dip)
    if (q) {
      const hay = it.dataset.dip || '';
      if (!hay.includes(q)) vis = false;
    }

    // Filtro azienda (dataset.aziende contiene lista di ID separati da ',')
    if (vis && az !== 'all') {
      const azids = (it.dataset.aziende || '').split(',').filter(Boolean);
      if (!azids.includes(az)) vis = false;
    }

    // Filtro sede (dataset.sedi contiene lista di ID separati da ',')
    if (vis && sd !== 'all') {
      const sids = (it.dataset.sedi || '').split(',').filter(Boolean);
      if (!sids.includes(sd)) vis = false;
    }

    // Filtro corso
    if (vis && cs !== 'all' && it.dataset.corso !== cs) {
      vis = false;
    }

    // Data di emissione esatta
    if (vis && ed) {
      if (it.dataset.emis !== ed) vis = false;
    }

    // Data di scadenza esatta
    if (vis && sdt) {
      const scad = it.dataset.scad || '';
      if (scad !== sdt) vis = false;
    }

    it.style.display = vis ? 'flex' : 'none';
    if (vis) any = true;
  });

  noMsg.style.display = any ? 'none' : 'block';
}


  // sede dipende dall'azienda selezionata
  function updateSediOptions() {
    const az = fAz.value;
    const opts = Array.from(fSede.querySelectorAll('option'));
    let hasEnabled = false;
    opts.forEach((o, i) => {
      if (i === 0) return; // "Tutte le sedi"
      const match = (az === 'all') || (o.getAttribute('data-az') === az);
      o.style.display = match ? '' : 'none';
      if (!match && o.selected) o.selected = false;
      if (match) hasEnabled = true;
    });
    fSede.disabled = (az === 'all') || !hasEnabled;
    if (fSede.disabled) fSede.value = 'all';
  }

[inputDip, fAz, fSede, fCorso, emisDate, scadDate].forEach(el => {
  el.addEventListener('input', () => { if (el === fAz) updateSediOptions(); applyFilters(); });
  el.addEventListener('change', () => { if (el === fAz) updateSediOptions(); applyFilters(); });
});


  // init
  updateSediOptions();
  applyFilters();
</script>
</body>
</html>
