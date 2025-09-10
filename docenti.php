<?php
// docenti.php — elenco docenti con filtro categorie (HACCP, Sicurezza, Antincendio, Primo Soccorso, Macchine Operatrici)
// Stile identico a dipendenti.php (CSS fornito sotto)
include 'init.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$added   = isset($_GET['added']);
$updated = isset($_GET['updated']);

// navbar coerente con il ruolo
$role = $_SESSION['role'] ?? 'utente';
ob_start();
if ($role === 'admin')      include 'navbar_a.php';
elseif ($role === 'dev')    include 'navbar_d.php';
else                        include 'navbar.php';
$navbar = ob_get_clean();

$allowedCategories = ['HACCP','Sicurezza','Antincendio','Primo Soccorso','Macchine Operatrici'];

// Elenco docenti + categorie collegate
$sql = <<<SQL
  SELECT d.id, d.nome, d.cognome,
         GROUP_CONCAT(DISTINCT dc.categoria ORDER BY dc.categoria SEPARATOR '||') AS cats
    FROM docente d
    LEFT JOIN docentecategoria dc ON dc.docente_id = d.id
GROUP BY d.id, d.nome, d.cognome
ORDER BY d.cognome, d.nome
SQL;
$docenti = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <title>Elenco Docenti</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <!-- ====== STILE IDENTICO A dipendenti.php ====== -->
  <style>
/* Variabili di pagina (non tocco la navbar) */
body {
  --page-bg: #f0f2f5;
  --page-fg: #2e3a45;
  --page-muted: #6b7280;
  --page-pri: #66bb6a;
  --page-pri-d: #5aad5c;
  --page-dark: #5b6670;
  --page-radius: 8px;
  --page-shadow: 0 10px 30px rgba(0,0,0,.08);
  margin: 0;
  background: var(--page-bg);
  color: var(--page-fg);
  font: 16px system-ui, -apple-system, Segoe UI, Roboto;
}
* { box-sizing: border-box; }

.container{max-width:1100px;margin:28px auto;padding:0 14px}
h1{text-align:center;margin:.2rem 0}
.context{color:#6b7280;text-align:center;margin-bottom:10px}

.toolbar{ display:flex; align-items:center; justify-content:center; gap:.6rem; margin-bottom:.8rem; flex-wrap:wrap; }
.toolbar .right{display:flex;gap:.6rem}

/* LARGHEZZE FISSE per i filtri */
#search{ width:280px; }
#f-az{ width:210px; }
#f-se{ width:210px; }

input,select{ height:36px; padding:.4rem .6rem; border:1px solid #d7dde3; background:#fff; border-radius:9px; }

/* buttons */
.btn{ display:inline-flex; align-items:center; gap:.45rem; padding:.5rem .95rem; border:0; border-radius:999px; color:#fff; font-weight:600; cursor:pointer; white-space:nowrap; }
.btn-green{background:#66bb6a} .btn-green:hover{background:#5aad5c}
.btn-grey{background:#6c757d} .btn-grey:hover{opacity:.92}
.btn-red{background:#dc3545} .btn-red:hover{filter:brightness(.95)}
.chkline{display:inline-flex;align-items:center;gap:.4rem}

/* dropdown */
.dropdown{position:relative}
.dd-btn{background:#6c757d}
.dd-menu{position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e6e8eb;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.12);min-width:220px;display:none;z-index:50}
.dropdown.open .dd-menu{display:block}
.dd-item{padding:.55rem .8rem;display:flex;gap:.5rem;align-items:center;cursor:pointer}
.dd-item:hover{background:#f3f6f8}

/* list */
.list{display:flex;flex-direction:column;gap:.55rem}
.item{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:.55rem .9rem;display:flex;align-items:center;justify-content:space-between;min-height:44px}
.item .left{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
.name{font-weight:700}
.badge{background:#eef2f7;border-radius:999px;padding:.1rem .5rem;color:#667}
.icon-btn{background:none;border:0;color:#4caf50;font-size:1.05rem;cursor:pointer}
.icon-btn:hover{opacity:.8}
.empty{color:#7a8691;text-align:center;padding:1.2rem}

/* modals */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:2000}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:min(820px,96vw);padding:16px}
.modal .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem}
.modal .close{background:none;border:0;font-size:1.4rem;color:#888;cursor:pointer}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.form-row{display:flex;flex-direction:column;gap:.25rem}
.actions{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.6rem}

.modal-iframe .content{height:min(80vh,760px)}
/* Import modal specific */
.imp-card{background:#fff;border:1px solid #e6e8eb;border-radius:12px;box-shadow:0 2px 6px rgba(0,0,0,.08);padding:12px}
.dz{position:relative;border:2px dashed #cfd8dc;background:#fff;border-radius:12px;padding:24px;text-align:center;transition:all .15s;min-height:160px;cursor:pointer}
.dz:hover{border-color:#66bb6a}
.dz.dragover{border-color:#66bb6a;background:#eef8f0}
.dz input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.dz .dz-inner{pointer-events:none}
.dz .dz-inner i{font-size:2rem;color:#66bb6a;margin-bottom:.25rem;display:block}
.dz .dz-title{font-weight:700}
.dz .dz-hint{color:#90a4ae;font-size:.9rem;margin-top:.25rem}

.preview-wrap{max-height:70vh;overflow:auto;margin-top:8px}
table.preview{border-collapse:collapse;width:100%;font-size:.95rem;border-radius:10px;overflow:hidden}
.preview th,.preview td{border:1px solid #e8e8e8;padding:.6rem .7rem;text-align:left;vertical-align:top}
.preview thead th{background:#f7faf8;position:sticky;top:0}

.alert-ok{background:#e6f4ea;border:1px solid #b7e1c0;color:#1e7a35;padding:.6rem .8rem;border-radius:10px;margin:8px 0}
.alert-err{background:#fdecea;border:1px solid #f5c2c7;color:#b11c1c;padding:.6rem .8rem;border-radius:10px;white-space:pre-line;margin:8px 0}

/* Toast bottom-left */
.toast{
  position:fixed; left:16px; bottom:16px; z-index:3000;
  background:#66bb6a; color:#fff; padding:.6rem .85rem; border-radius:10px;
  box-shadow:0 10px 30px rgba(0,0,0,.2); display:none; align-items:center; gap:.5rem;
}
  </style>
</head>
<body>
  <?=$navbar?>

  <div class="container">
    <h1>Elenco Docenti</h1>

    <!-- Toolbar (cerca + filtro categorie + aggiungi) -->
    <div class="toolbar">
      <input id="search" type="text" placeholder="Cerca docenti…" />
      <div class="right">
        <button class="btn btn-grey" id="open-filter"><i class="bi bi-filter"></i> Filtra</button>
        <a class="btn btn-green" href="./aggiungi_docente.php"><i class="bi bi-plus-lg"></i> Aggiungi Docente</a>
      </div>
    </div>

    <!-- Chips filtri attivi -->
    <div id="chips" class="toolbar" style="justify-content:flex-start; display:none;"></div>

    <?php if ($added): ?>
      <div class="toast" id="toast-add"><i class="bi bi-check2-circle"></i> Docente aggiunto con successo!</div>
    <?php endif; ?>
    <?php if ($updated): ?>
      <div class="toast" id="toast-upd"><i class="bi bi-check2-circle"></i> Docente aggiornato con successo!</div>
    <?php endif; ?>

    <?php if (empty($docenti)): ?>
      <div class="empty">Non ci sono docenti registrati.</div>
    <?php else: ?>
      <div class="list" id="list">
        <?php foreach ($docenti as $d):
          $cats = $d['cats'] ? explode('||', $d['cats']) : [];
          $dataCats = htmlspecialchars(implode(',', $cats), ENT_QUOTES);
        ?>
        <div class="item" data-name="<?= strtolower(htmlspecialchars($d['cognome'].' '.$d['nome'], ENT_QUOTES)) ?>"
             data-cats="<?=$dataCats?>">
          <div class="left">
            <span class="name"><?= htmlspecialchars($d['cognome'].' '.$d['nome'], ENT_QUOTES) ?></span>
            <?php if (!empty($cats)): ?>
              <?php foreach ($cats as $c): ?>
                <span class="badge"><?= htmlspecialchars($c) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <a class="icon-btn" title="Vedi dettagli" href="./docente.php?id=<?= urlencode($d['id']) ?>">
            <i class="bi bi-search"></i>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- MODAL filtro categorie -->
  <div class="overlay" id="ovl">
    <div class="modal">
      <div class="head">
        <h3 style="margin:0">Filtra per categorie</h3>
        <button class="close" id="x">&times;</button>
      </div>
      <div class="form-grid" style="grid-template-columns:1fr;">
        <div class="form-row">
          <div class="imp-card" style="padding:10px;">
            <?php foreach ($allowedCategories as $cat): ?>
              <label class="chkline" style="margin:.2rem 0;">
                <input type="checkbox" class="cat-check" value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>" />
                <span><?= htmlspecialchars($cat) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="actions">
        <button class="btn btn-grey" id="cancel">Annulla</button>
        <button class="btn btn-green" id="apply">Applica</button>
      </div>
    </div>
  </div>

  <script>
    // Toasts
    window.addEventListener('load', () => {
      ['toast-add','toast-upd'].forEach(id=>{
        const el = document.getElementById(id);
        if (el){ el.style.display='inline-flex'; setTimeout(()=>{ el.style.display='none'; }, 2200); }
      });
    });

    // Ricerca + filtri categorie
    const qEl = document.getElementById('search');
    const listItems = () => Array.from(document.querySelectorAll('#list .item'));
    const chipsBox = document.getElementById('chips');
    let activeCats = []; // array di stringhe

    function renderChips(){
      chipsBox.innerHTML = '';
      if (activeCats.length === 0){ chipsBox.style.display='none'; return; }
      activeCats.forEach(cat=>{
        const chip = document.createElement('div');
        chip.className = 'badge';
        chip.style.display = 'inline-flex';
        chip.style.alignItems = 'center';
        chip.style.gap = '.35rem';
        chip.innerHTML = `<span>${cat}</span><button title="Rimuovi" style="border:0;background:none;cursor:pointer;color:#444;padding:0 .1rem;">&times;</button>`;
        chip.querySelector('button').addEventListener('click', ()=>{
          activeCats = activeCats.filter(c => c !== cat);
          const cb = document.querySelector('.cat-check[value="'+CSS.escape(cat)+'"]');
          if (cb) cb.checked = false;
          renderChips(); applyFilters();
        });
        chipsBox.appendChild(chip);
      });
      chipsBox.style.display='flex';
    }

    function applyFilters(){
      const q = (qEl.value || '').toLowerCase().trim();
      listItems().forEach(it=>{
        const matchesText = (it.dataset.name || '').includes(q);
        let matchesCats = true;
        if (activeCats.length){
          const myCats = (it.dataset.cats || '').split(',').filter(Boolean);
          matchesCats = activeCats.some(c => myCats.includes(c)); // OR logica
        }
        it.style.display = (matchesText && matchesCats) ? 'flex' : 'none';
      });
    }
    qEl.addEventListener('input', applyFilters);

    // Modal handling
    const ovl = document.getElementById('ovl');
    const openBtn = document.getElementById('open-filter');
    const closeBtns = [document.getElementById('x'), document.getElementById('cancel')];
    openBtn.addEventListener('click', ()=>{ 
      // pre-check in base agli attivi
      document.querySelectorAll('.cat-check').forEach(cb=> cb.checked = activeCats.includes(cb.value));
      ovl.classList.add('open');
    });
    closeBtns.forEach(b=> b.addEventListener('click', ()=> ovl.classList.remove('open') ));
    ovl.addEventListener('click', (e)=>{ if(e.target===ovl) ovl.classList.remove('open'); });

    // Applica selezione
    document.getElementById('apply').addEventListener('click', ()=>{
      activeCats = Array.from(document.querySelectorAll('.cat-check:checked')).map(x=>x.value);
      renderChips(); applyFilters();
      ovl.classList.remove('open');
    });

    // Prima applicazione filtri
    applyFilters();
  </script>
</body>
</html>
