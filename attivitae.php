<?php
// attivitae.php — elenco attività con ricerca, filtri, PiP e link a attività chiuse
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
    c.id       AS corso_id,
    c.titolo   AS corso_titolo,
    c.categoria,
    c.tipologia,
    GROUP_CONCAT(DISTINCT dic.docente_id) AS docenti_ids
  FROM attivita a
  JOIN corso c           ON c.id = a.corso_id
  LEFT JOIN incarico i   ON i.attivita_id = a.id
  LEFT JOIN docenteincarico dic ON dic.incarico_id = i.id
  WHERE a.chiuso = 0
  GROUP BY a.id, a.modalita, c.id, c.titolo, c.categoria, c.tipologia
  ORDER BY CAST(SUBSTRING_INDEX(a.id,'-',-1) AS UNSIGNED) DESC
SQL;
$attivita = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Elenco Attività</title>
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
  --ink:#2f3a46;
  --muted:#6b7b86;
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
#toast.alert-danger { background-color: var(--err) !important; color: #fff !important; }

/* Toolbar superiore con due bottoni */
.toolbar {
  display:flex;
  justify-content:center;
  gap:.5rem;
  margin-bottom:1rem;
  flex-wrap:wrap;
}
.toolbar .btn{
  display:inline-flex;align-items:center;gap:.5rem;
  background:var(--pri);color:#fff;
  padding:.55rem 1rem;border-radius:var(--radius);
  text-decoration:none;transition:background .2s,transform .2s;
  box-shadow:0 2px 6px var(--shadow);
}
.toolbar .btn:hover{background:#5aad5c;transform:translateY(-2px);}
.toolbar .btn-secondary{background:#6c757d;}
.toolbar .btn-secondary:hover{background:#5f666c;}

/* Filtri */
.filter-area { display:flex; flex-direction:column; gap:.5rem; margin-bottom:1rem; }
.filter-area input {
  width:100%; height:2.5rem; padding:.5rem .75rem;
  border:1px solid #ccc; border-radius:var(--radius); font-size:1rem;
}
.filter-row { display:flex; gap:.5rem; flex-wrap:wrap; }
.filter-select {
  flex: 1 1 0; min-width: 0; height:2.5rem;
  padding:.5rem .75rem; border:1px solid #ccc; border-radius:var(--radius);
  font-size:.9rem;
}

/* List items */
.item{
  background:#fff;border-radius:var(--radius);
  box-shadow:0 2px 6px var(--shadow);
  padding:1rem;margin-bottom:.75rem;
  display:flex;justify-content:space-between;align-items:center;
  transition:transform .15s,box-shadow .15s;
}
.item:hover{ transform:translateY(-2px); box-shadow:0 4px 12px var(--shadow); }
.info{display:flex;gap:1rem;align-items:center;flex-wrap:wrap;}
.info .id{font-weight:bold;color:var(--ink);}
.info span{color:#666;font-size:.9rem;}
.actions { display:flex; align-items:center; gap:.35rem; }

/* Icon buttons */
.icon-btn{
  background:none;border:none;color:var(--pri);
  font-size:1.2rem;cursor:pointer;transition:color .2s;
  text-decoration:none; padding:.25rem; border-radius:6px;
}
.icon-btn:hover{color:#5aad5c; background:rgba(102,187,106,0.08);}

/* PiP menu */
.menu { position:relative; }
.menu-btn{
  background:none;border:none;cursor:pointer;
  font-size:1.25rem;color:var(--muted);
  display:inline-flex;align-items:center;justify-content:center;
  width:2rem;height:2rem;border-radius:8px;
}
.menu-btn:hover{ background:#f3f5f7; color:#26323a; }
.menu-list{
  position:absolute; right:0; top:calc(100% + .35rem);
  background:#fff; border:1px solid #e6ebef; border-radius:10px;
  box-shadow:0 12px 28px rgba(0,0,0,.12), 0 2px 6px rgba(0,0,0,.06);
  min-width:230px; padding:.35rem; display:none; z-index:20;
}
.menu.open .menu-list{ display:block; }
.menu a{
  display:flex; align-items:center; gap:.6rem;
  padding:.55rem .6rem; color:#22313a; text-decoration:none;
  border-radius:8px; transition:background .15s;
  font-size:.95rem;
}
.menu a i{ font-size:1.05rem; color:#4a5a65; }
.menu a:hover{ background:#f5f8fa; }

/* Responsive: stack info/actions su mobile */
@media (max-width:600px){
  .item{flex-direction:column;align-items:stretch;gap:.75rem;}
  .actions{justify-content:flex-end;}
}
  </style>
</head>
<body>
<?php
  $role = $_SESSION['role'] ?? 'utente';
  switch ($role) {
    case 'admin': include 'navbar_a.php'; break;
    case 'dev'  : include 'navbar_d.php'; break;
    default     : include 'navbar.php';
  }
?>
  <div class="container">
    <h1>Elenco Attività</h1>

    <?php if ($added): ?>
      <div id="toast" class="alert alert-success">Attività aggiunta con successo!</div>
    <?php endif; ?>
    <?php if ($deleted): ?>
      <div id="toast" class="alert alert-danger">Attività eliminata con successo!</div>
    <?php endif; ?>
    <?php if ($pdfgenerated): ?>
      <div id="toast" class="alert alert-success">PDF generato con successo!</div>
    <?php endif; ?>
    <?php if (isset($_GET['opened'])): ?>
      <div id="toast" class="alert alert-success">Attività riaperta con successo!</div>
    <?php endif; ?>

    <!-- Toolbar con Aggiungi + Attività Chiuse -->
    <div class="toolbar">
      <a href="/biosound/aggiungi_attivita.php" class="btn">
        <i class="bi bi-plus-lg"></i> Aggiungi Attività
      </a>
      <a href="/biosound/attivitae_chiuse.php" class="btn btn-secondary" title="Vedi attività chiuse">
        <i class="bi bi-archive"></i> Attività chiuse
      </a>
    </div>

    <!-- FILTRI -->
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
            <option value="<?=htmlspecialchars($c['id'],ENT_QUOTES)?>">
              <?=htmlspecialchars($c['titolo'],ENT_QUOTES)?>
            </option>
          <?php endforeach; ?>
        </select>
        <select id="filter-docente" class="filter-select">
          <option value="all">Tutti i docenti</option>
          <?php foreach ($docentiList as $d): ?>
            <option value="<?=htmlspecialchars($d['id'],ENT_QUOTES)?>">
              <?=htmlspecialchars("{$d['cognome']} {$d['nome']}",ENT_QUOTES)?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if (empty($attivita)): ?>
      <p style="text-align:center;color:#666;">Nessuna attività registrata.</p>
    <?php else: foreach ($attivita as $a): ?>
      <div class="item"
           data-id="<?=htmlspecialchars($a['id'],ENT_QUOTES)?>"
           data-modalita="<?=htmlspecialchars($a['modalita'],ENT_QUOTES)?>"
           data-categoria="<?=htmlspecialchars($a['categoria'],ENT_QUOTES)?>"
           data-tipologia="<?=htmlspecialchars($a['tipologia'],ENT_QUOTES)?>"
           data-corso-id="<?=htmlspecialchars($a['corso_id'],ENT_QUOTES)?>"
           data-docenti="<?=htmlspecialchars($a['docenti_ids'] ?? '',ENT_QUOTES)?>">

        <div class="info">
          <span class="id"><?=htmlspecialchars($a['id'],ENT_QUOTES)?></span>
          <span><?=htmlspecialchars($a['corso_titolo'],ENT_QUOTES)?></span>
          <span><?=htmlspecialchars($a['modalita'],ENT_QUOTES)?></span>
          <span><?=htmlspecialchars($a['categoria'],ENT_QUOTES)?></span>
          <span><?=htmlspecialchars($a['tipologia'],ENT_QUOTES)?></span>
        </div>

        <div class="actions">
          <!-- Chiudi corso -->
          <a href="/biosound/chiudi_corso.php?id=<?=urlencode($a['id'])?>"
             class="icon-btn" title="Chiudi corso">
            <i class="bi bi-lock"></i>
          </a>

          <!-- Modifica -->
          <a href="/biosound/attivita.php?id=<?=urlencode($a['id'])?>"
             class="icon-btn" title="Modifica">
            <i class="bi bi-pencil"></i>
          </a>

          <!-- PiP menu -->
          <div class="menu">
            <button class="menu-btn" type="button" aria-haspopup="true" aria-expanded="false" title="Altro">
              <i class="bi bi-three-dots-vertical"></i>
            </button>
            <div class="menu-list" role="menu">
              <a role="menuitem" href="/biosound/scarica_registro.php?id=<?=urlencode($a['id'])?>">
                <i class="bi bi-journal-text"></i> Registro (PDF)
              </a>
              <a role="menuitem" href="/biosound/scheda_corso.php?id=<?=urlencode($a['id'])?>">
                <i class="bi bi-file-earmark-text"></i> Scheda corso
              </a>
              <a role="menuitem" href="/biosound/iscrizione.php?id=<?=urlencode($a['id'])?>">
                <i class="bi bi-person-plus"></i> Iscrizioni
              </a>
            </div>
          </div>
        </div>

      </div>
    <?php endforeach; endif; ?>
  </div>

  <script>
    // Toast fade
    window.addEventListener('load', () => {
      const t = document.getElementById('toast');
      if (t) setTimeout(() => t.style.opacity = '0', 2000);
    });

    // Filtri
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
              corId = item.dataset.corsoId,
              docs  = (item.dataset.docenti||'').split(',');

        if (idVal && !id.includes(idVal)) vis = false;
        if (modVal !== 'all' && mod !== modVal) vis = false;
        if (catVal !== 'all' && cat !== catVal) vis = false;
        if (tipVal !== 'all' && tip !== tipVal) vis = false;
        if (corVal !== 'all' && corId !== corVal) vis = false;
        if (docVal !== 'all' && !docs.includes(docVal)) vis = false;

        item.style.display = vis ? 'flex' : 'none';
      });
    }
    [inputId,fMod,fCat,fTipo,fCorso,fDoc].forEach(el => {
      el.addEventListener('input', applyFilters);
      el.addEventListener('change', applyFilters);
    });

    // PiP menu: toggle e chiusura esterna / Esc
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.menu-btn');
      const menus = document.querySelectorAll('.menu');
      if (btn) {
        const m = btn.closest('.menu');
        menus.forEach(x => { if (x !== m) x.classList.remove('open'); });
        m.classList.toggle('open');
      } else {
        // click fuori chiude tutti
        if (!e.target.closest('.menu')) {
          menus.forEach(x => x.classList.remove('open'));
        }
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        document.querySelectorAll('.menu').forEach(x => x.classList.remove('open'));
      }
    });
  </script>
</body>
</html>
