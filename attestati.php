<?php
// attestati.php — elenco attestati con filtri combinabili
require_once __DIR__ . '/init.php';

$added    = isset($_GET['added']);
$updated  = isset($_GET['updated']);
$deleted  = isset($_GET['deleted']);

$corsiList    = $pdo->query('SELECT id, titolo FROM corso ORDER BY titolo')->fetchAll(PDO::FETCH_ASSOC);
$aziendeList  = $pdo->query('SELECT id, ragionesociale FROM azienda ORDER BY ragionesociale')->fetchAll(PDO::FETCH_ASSOC);
$sediList     = $pdo->query('SELECT s.id, s.nome, s.azienda_id FROM sede s ORDER BY s.nome')->fetchAll(PDO::FETCH_ASSOC);
$attivitaList = $pdo->query('
  SELECT a.id, c.titolo AS corso, a.modalita
  FROM attivita a
  JOIN corso c ON c.id = a.corso_id
  ORDER BY a.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

/*
 Recupero attestati:
 - dipendente (nome, cognome)
 - corso (titolo)
 - attività (id + modalità)
 - tutte le aziende/sedi associate al dipendente (per filtri client-side)
*/
$sql = <<<SQL
SELECT
  a.id,
  a.dipendente_id,
  d.nome       AS dip_nome,
  d.cognome    AS dip_cognome,
  a.attivita_id,
  at.modalita  AS attivita_modalita,
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
LEFT JOIN attivita at     ON at.id = a.attivita_id
LEFT JOIN dipendente_sede ds ON ds.dipendente_id = d.id
LEFT JOIN sede s              ON s.id = ds.sede_id
LEFT JOIN azienda az          ON az.id = s.azienda_id
GROUP BY a.id, a.dipendente_id, d.nome, d.cognome, a.corso_id,
         c.titolo, a.data_emissione, a.data_scadenza, a.note, a.attivita_id, at.modalita
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
  .rowline{display:flex;flex-wrap:wrap;gap:.5rem;align-items:baseline}
  .actions{display:flex;align-items:center;gap:.5rem;}
  .icon-btn{background:none;border:none;color:var(--pri);font-size:1.25rem;cursor:pointer;text-decoration:none}
  .icon-btn:hover{color:#5aad5c;}
  .nores{margin-top:1rem;text-align:center;color:#666}

  /* fix date inputs */
  .filter-input[type="date"]{height:2.5rem!important;min-height:2.5rem!important;line-height:2.5rem;padding:.25rem .75rem;}
  .date-row .filter-input{flex:0 0 auto;}
  .date-row > div{flex:1 1 0;min-width:14rem;}
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

  <?php if ($added): ?><div id="toast" class="alert alert-success">Attestato aggiunto con successo!</div><?php endif; ?>
  <?php if ($updated): ?><div id="toast" class="alert alert-success">Attestato aggiornato con successo!</div><?php endif; ?>
  <?php if ($deleted): ?><div id="toast" class="alert-danger">Attestato eliminato con successo!</div><?php endif; ?>

  <div class="add-container">
    <a href="./aggiungi_attestato.php" class="add-btn">
      <i class="bi bi-plus-lg"></i> Aggiungi Attestato
    </a>
  </div>

  <!-- FILTRI -->
  <div class="filter-area">
    <input id="search-dip" class="filter-input" type="text" placeholder="Cerca dipendente: nome, cognome o entrambi…">
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
      <select id="filter-attivita" class="filter-select">
        <option value="all">Tutte le attività</option>
        <?php foreach ($attivitaList as $a): ?>
          <option value="<?=htmlspecialchars($a['id'],ENT_QUOTES)?>">
            <?=htmlspecialchars($a['id'].' — '.$a['corso'].' ('.$a['modalita'].')',ENT_QUOTES)?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="date-row">
      <div style="flex:1;display:flex;flex-direction:column;">
        <label for="emis-date" style="font-size:.85rem;margin-bottom:.25rem;">Data di emissione</label>
        <input id="emis-date" class="filter-input" type="date">
      </div>
      <div style="flex:1;display:flex;flex-direction:column;">
        <label for="scad-date" style="font-size:.85rem;margin-bottom:.25rem;">Data di scadenza</label>
        <input id="scad-date" class="filter-input" type="date">
      </div>
    </div>
  </div>

  <?php if (empty($attestati)): ?>
    <p class="nores">Nessun risultato.</p>
  <?php else: foreach ($attestati as $r): 
    $emis_display = $r['data_emissione'] ? date('d/m/Y', strtotime($r['data_emissione'])) : '—';
    $scad_display = $r['data_scadenza'] ? date('d/m/Y', strtotime($r['data_scadenza'])) : '—';
  ?>
    <div class="item"
      data-dip="<?=strtolower(htmlspecialchars($r['dip_nome'].' '.$r['dip_cognome'],ENT_QUOTES))?>"
      data-aziende="<?=$r['azienda_ids']?>"
      data-sedi="<?=$r['sede_ids']?>"
      data-corso="<?=$r['corso_id']?>"
      data-attivita="<?=$r['attivita_id']?>"
      data-emis="<?=$r['data_emissione']?>"
      data-scad="<?=$r['data_scadenza']?>">
      <div class="rowline">
        <strong><?=htmlspecialchars($r['dip_cognome'].' '.$r['dip_nome'])?></strong>
        &nbsp;Attività: <?= $r['attivita_id']?>
        &nbsp;<?=htmlspecialchars($r['corso_titolo'])?>
        &nbsp;<?=htmlspecialchars($r['azienda_nome'])?> <small>(<?=htmlspecialchars($r['sede_nome'])?>)</small>
        &nbsp;E: <?=$emis_display?>
        &nbsp;•&nbsp;S: <?=$scad_display?>
      </div>
      <div class="actions">
        <a class="icon-btn" href="./attestato.php?id=<?=urlencode($r['id'])?>" title="Modifica">
          <i class="bi bi-pencil"></i>
        </a>
      </div>
    </div>
  <?php endforeach; ?>
    <p id="no-filter-results" class="nores" style="display:none;">Nessun risultato.</p>
  <?php endif; ?>
</div>

<script>
  window.addEventListener('load', () => {
    const t=document.getElementById('toast'); if(t) setTimeout(()=>t.style.opacity='0',2000);
  });

  const inputDip=document.getElementById('search-dip');
  const fAz=document.getElementById('filter-azienda');
  const fSede=document.getElementById('filter-sede');
  const fCorso=document.getElementById('filter-corso');
  const fAttivita=document.getElementById('filter-attivita');
  const emisDate=document.getElementById('emis-date');
  const scadDate=document.getElementById('scad-date');
  const items=Array.from(document.querySelectorAll('.item'));
  const noMsg=document.getElementById('no-filter-results');

  function applyFilters(){
    const q=inputDip.value.trim().toLowerCase();
    const az=fAz.value, sd=fSede.value, cs=fCorso.value, at=fAttivita.value;
    const ed=emisDate.value, sdt=scadDate.value;
    let any=false;

    items.forEach(it=>{
      let vis=true;
      if(q && !(it.dataset.dip||'').includes(q)) vis=false;
      if(vis && az!=='all' && !(it.dataset.aziende||'').split(',').includes(az)) vis=false;
      if(vis && sd!=='all' && !(it.dataset.sedi||'').split(',').includes(sd)) vis=false;
      if(vis && cs!=='all' && it.dataset.corso!==cs) vis=false;
      if(vis && at!=='all' && it.dataset.attivita!==at) vis=false;
      if(vis && ed && it.dataset.emis!==ed) vis=false;
      if(vis && sdt && (it.dataset.scad||'')!==sdt) vis=false;
      it.style.display=vis?'flex':'none'; if(vis) any=true;
    });
    noMsg.style.display=any?'none':'block';
  }

  function updateSediOptions(){
    const az=fAz.value; const opts=[...fSede.options];
    let has=false; opts.forEach((o,i)=>{
      if(i===0)return;
      const m=(az==='all')||(o.getAttribute('data-az')===az);
      o.style.display=m?'':'none'; if(!m&&o.selected)o.selected=false; if(m)has=true;
    });
    fSede.disabled=(az==='all'||!has); if(fSede.disabled)fSede.value='all';
  }

  [inputDip,fAz,fSede,fCorso,fAttivita,emisDate,scadDate].forEach(el=>{
    el.addEventListener('input',()=>{if(el===fAz)updateSediOptions();applyFilters();});
    el.addEventListener('change',()=>{if(el===fAz)updateSediOptions();applyFilters();});
  });

  updateSediOptions(); applyFilters();
</script>
</body>
</html>
