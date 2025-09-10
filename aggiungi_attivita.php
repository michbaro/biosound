<?php
// aggiungi_attivita.php — form per aggiungere una nuova attività con selezione partecipanti PiP
require_once __DIR__ . '/init.php';

// Flag per errore superamento max partecipanti
$errorMaxParticipants = false;
$maxAllowed           = 0;

// 2) Mappa corsi → categoria & permessi
$corsiRaw = $pdo
  ->query('SELECT id, titolo, categoria, modalita, maxpartecipanti FROM corso ORDER BY titolo')
  ->fetchAll(PDO::FETCH_ASSOC);
$courseCategories = $coursePerm = [];
foreach ($corsiRaw as $c) {
    $courseCategories[$c['id']] = $c['categoria'];
    $coursePerm     [$c['id']] = $c['modalita'];
}

// 3) Dati docenti + categorie
$docRaw = $pdo->query(<<<'SQL'
  SELECT d.id, d.nome, d.cognome, dc.categoria
    FROM docente d
    LEFT JOIN docentecategoria dc ON dc.docente_id = d.id
SQL
)->fetchAll(PDO::FETCH_ASSOC);
$docMap = [];
foreach ($docRaw as $r) {
    if (!isset($docMap[$r['id']])) {
        $docMap[$r['id']] = [
            'id'         => $r['id'],
            'nome'       => $r['nome'],
            'cognome'    => $r['cognome'],
            'categories' => []
        ];
    }
    if ($r['categoria']) {
        $docMap[$r['id']]['categories'][] = $r['categoria'];
    }
}
$docentiData = array_values($docMap);

// 4) Dati operatori per richiedente/tutor
$operatoriList = $pdo
  ->query('SELECT id, nome, cognome FROM operatore ORDER BY cognome, nome')
  ->fetchAll(PDO::FETCH_ASSOC);

// 5) Dati aziende, sedi, dipendenti
$aziendeList = $pdo->query('SELECT id, ragionesociale FROM azienda ORDER BY ragionesociale')
                   ->fetchAll(PDO::FETCH_ASSOC);
$sediAll     = $pdo->query('SELECT id, nome, azienda_id FROM sede ORDER BY nome')
                   ->fetchAll(PDO::FETCH_ASSOC);
$dipRaw      = $pdo->query(<<<'SQL'
  SELECT d.id, d.nome, d.cognome, d.codice_fiscale, ds.sede_id, s.azienda_id
    FROM dipendente d
    JOIN dipendente_sede ds ON d.id = ds.dipendente_id
    JOIN sede s            ON ds.sede_id   = s.id
SQL
)->fetchAll(PDO::FETCH_ASSOC);

// 6) POST: crea nuova attività
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $corsoId       = $_POST['corso_id'] ?? '';
    $nPartecipanti = intval($_POST['n_partecipanti'] ?? 0);
    // … altri $_POST …

// Recupero da DB il maxpartecipanti per il corso selezionato
$stmtMax = $pdo->prepare('SELECT maxpartecipanti FROM corso WHERE id = ?');
$stmtMax->execute([$corsoId]);
$maxAllowed = (int)$stmtMax->fetchColumn();

// Se superi il massimo, segnalo e SALTO ogni INSERT
if ($nPartecipanti > $maxAllowed) {
    $errorMaxParticipants = true;
}


    $corso_id        = $_POST['corso_id'];
    $modalita        = $_POST['modalita'];
    $n_partecipanti  = (int)($_POST['n_partecipanti'] ?? 0);
    $azienda         = trim($_POST['azienda'] ?? '');
    $luogo           = trim($_POST['luogo'] ?? '');
    $note            = trim($_POST['note'] ?? '');
    $richiedente_id  = $_POST['richiedente_id'] ?? null;
    $tutor_id        = $_POST['tutor_id'] ?? null;
    $corsoFinanziato = isset($_POST['corsoFinanziato']) && $_POST['corsoFinanziato']==='1' ? 1 : 0;
    $avviso         = $corsoFinanziato ? trim($_POST['avviso'] ?? '') : null;
$cup            = $corsoFinanziato ? trim($_POST['cup'] ?? '')    : null;
$numero_azione  = $corsoFinanziato ? trim($_POST['numero_azione'] ?? '') : null;
    $fondo           = $corsoFinanziato ? ($_POST['fondo'] ?? null) : null;
    $dipendentiSel   = $_POST['dipendente_id'] ?? [];

    // genera ID
    $yy = date('y'); $mm = date('m');
    $mapV = [
      'Presenza fisica (Aula)'       => 'A',
      'Videochiamata (FAD Sincrona)' => 'F',
      'E-Learning (FAD Asincrona)'   => 'E',
      'Mista (Blended Learning)'     => 'B',
    ];
    $V = $mapV[$modalita] ?? 'X';
    $cData = $pdo->prepare('SELECT categoria, tipologia FROM corso WHERE id = ?');
    $cData->execute([$corso_id]);
    $cData = $cData->fetch(PDO::FETCH_ASSOC) ?: ['categoria'=>'','tipologia'=>''];
    $mapG = [
      'HACCP'               => 'H',
      'Sicurezza'           => 'S',
      'Antincendio'         => 'A',
      'Primo Soccorso'      => 'P',
      'Macchine Operatrici' => 'M',
    ];
    $G  = $mapG[$cData['categoria']] ?? 'X';
    $CC = $corso_id;
    $T  = $cData['tipologia']==='Primo Rilascio' ? '1' : '0';

    $pdo->beginTransaction();
    $seq    = $pdo->query('SELECT ultimo FROM attivita_sequence FOR UPDATE')->fetchColumn();
    $suffix = $seq + 1;
    $pdo->prepare('UPDATE attivita_sequence SET ultimo = ?')->execute([$suffix]);
    $pdo->commit();

    $newId = "{$yy}{$mm}{$V}{$G}{$CC}{$T}-{$suffix}";
if (! $errorMaxParticipants) {
    // inserisci attivita
$pdo->prepare(<<<'SQL'
  INSERT INTO attivita
    (id, corso_id, modalita, n_partecipanti,
     azienda, luogo, note, richiedente_id, tutor_id,
     corsoFinanziato, fondo, avviso, cup, numero_azione)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL
)->execute([
  $newId, $corso_id, $modalita, $n_partecipanti,
  $azienda, $luogo, $note, $richiedente_id, $tutor_id,
  $corsoFinanziato, $fondo, $avviso, $cup, $numero_azione
]);

    // associa partecipanti
    if (is_array($dipendentiSel)) {
      $insPart = $pdo->prepare('
        INSERT INTO attivita_dipendente (id, attivita_id, dipendente_id)
        VALUES (?, ?, ?)
      ');
      foreach ($dipendentiSel as $dId) {
        $insPart->execute([ bin2hex(random_bytes(16)), $newId, $dId ]);
      }
    }

    // incarichi + lezioni
    if ($modalita !== 'E-Learning (FAD Asincrona)') {
      $dates      = $_POST['date']       ?? [];
      $starts     = $_POST['start']      ?? [];
      $ends       = $_POST['end']        ?? [];
      $docentiSel = $_POST['docente_id'] ?? [];

      $incStmt = $pdo->prepare(
        'INSERT INTO incarico (id, attivita_id, dataStipula) VALUES (?, ?, CURDATE())'
      );
      $diStmt = $pdo->prepare(
        'INSERT INTO docenteincarico (id, docente_id, incarico_id, costoExtra) VALUES (?, ?, ?, 0)'
      );
      $dlStmt = $pdo->prepare(
        'INSERT INTO datalezione (id, incarico_id, data, oraInizio, oraFine, durata)
           VALUES (?, ?, ?, ?, ?, ?)'
      );

      foreach ($dates as $i => $d) {
        $t1  = $starts[$i]     ?? '';
        $t2  = $ends[$i]       ?? '';
        $doc = $docentiSel[$i] ?? '';
        if ($d && $t1 && $t2 && $doc) {
          $incId = bin2hex(random_bytes(16));
          $incStmt->execute([$incId, $newId]);
          $diStmt ->execute([bin2hex(random_bytes(16)), $doc, $incId]);
          $dur   = (strtotime("$d $t2") - strtotime("$d $t1"))/3600;
          $dlStmt->execute([
            bin2hex(random_bytes(16)), $incId, $d, $t1, $t2, $dur
          ]);
        }
      }
    }

    header("Location: ./attivita.php?id={$newId}&added=1");
    exit;
}
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Aggiungi Attività</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
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
  .alert{padding:.75rem 1rem;border-radius:var(--radius);
    margin-bottom:1rem;box-shadow:0 2px 6px var(--shadow);
    text-align:center;}
  .alert-success{background:#d4edda;color:#155724;}
  form{display:flex;flex-direction:column;gap:1.5rem;}
  .form-group{display:flex;flex-direction:column;}
  .form-group.radio-inline{flex-direction:row;align-items:center;gap:1rem;}
  label{margin-bottom:.5rem;font-weight:500;}
  input,select,textarea{width:100%;padding:.5rem .75rem;
    border:1px solid #ccc;border-radius:var(--radius);font-size:1rem;}
  textarea{resize:vertical;}
  .form-grid-2-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;}
  .form-grid-half{display:grid;grid-template-columns:1fr 1fr;gap:1rem;
    align-items:flex-end;margin-bottom:1.5rem;}
  .form-grid-1-1{display:grid;grid-template-columns:1fr 3fr;gap:1rem;
    align-items:flex-end;margin-bottom:1.5rem;}
  #compat-toast{position:fixed;bottom:1rem;left:1rem;
    background:var(--err);color:#fff;padding:.75rem 1.25rem;
    border-radius:var(--radius);box-shadow:0 2px 6px var(--shadow);
    opacity:1;transition:opacity .5s ease-out;z-index:1000;}
  /* lezioni */
  .lesson-row{display:flex;gap:1rem;align-items:center;margin-bottom:.5rem;}
  .lesson-row>*{flex:1;}
  .lesson-row>select{flex:2;}
  .remove-btn{width:2rem;height:2rem;font-size:1.25rem;
    background:var(--err);color:#fff;border:none;border-radius:4px;cursor:pointer;}
  .lesson-row:first-child .remove-btn{visibility:hidden;}
  .add-lesson{align-self:flex-start;background:var(--pri);
    color:#fff;border:none;border-radius:4px;padding:.5rem 1rem;cursor:pointer;}
  .add-lesson:hover{background:#5aad5c;}
/* lista selezioni per partecipanti e aziende */
#selected-participants,
#selected-companies {
  list-style: none;
  padding: 0;
  margin-top: .5rem;
}
#selected-participants li,
#selected-companies li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: .5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  margin-bottom: .5rem;
}
#selected-participants li .info,
#selected-companies li .info {
  display: flex;
  flex-direction: column;
}
#selected-participants li .info strong,
#selected-companies li .info strong {
  font-weight: 600;
}
#selected-participants li button,
#selected-companies li button {
  background: none;
  border: none;
  color: var(--err);
  font-size: 1.2rem;
  cursor: pointer;
}

  /* modal */
  .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;
    background:rgba(0,0,0,0.4);display:none;align-items:center;
    justify-content:center;z-index:1000;}
  .modal-overlay.open{display:flex;}
  .modal{background:#fff;padding:1rem;border-radius:var(--radius);
    max-width:600px;width:100%;max-height:80vh;overflow:auto;}
  .modal h2{margin-bottom:1rem;}
  .filters,.bulk-actions{display:flex;gap:1rem;margin-bottom:1rem;}
  .employee-list{max-height:40vh;overflow:auto;margin-bottom:1rem;}
  .employee-list ul{list-style:none;margin:0;padding:0;width:100%;}
  .employee-list li{display:grid;grid-template-columns:1fr auto;
    align-items:center;padding:.5rem;border-bottom:1px solid #ddd;}
  .employee-list li .info{display:flex;flex-direction:column;}
  .employee-list li .info strong{font-weight:600;}
  .employee-list li .info span{font-size:.9rem;color:#555;}
  .employee-list li input[type="checkbox"]{justify-self:end;
    transform:scale(1.2);margin:0;}
  .modal-actions{display:flex;justify-content:flex-end;gap:1rem;}
  .actions{display:flex;justify-content:center;gap:3rem;margin-top:2rem;}
  .btn{display:inline-flex;align-items:center;gap:.75rem;
    padding:.6rem 1.2rem;font-size:1rem;font-weight:bold;
    color:#fff;border:none;border-radius:var(--radius);cursor:pointer;
    text-decoration:none;transition:opacity .2s;}
  .btn-secondary{background:#6c757d;} .btn-primary{background:var(--pri);}
  .btn-danger{background:var(--err);}
  .btn-secondary:hover,.btn-primary:hover,.btn-danger:hover{opacity:.9;}
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
    <h1>Aggiungi Attività</h1>

    <?php if ($errorMaxParticipants): ?>
  <div id="toast" class="toast-error">
    Numero partecipanti massimo previsto per questo corso: <?= $maxAllowed ?>
  </div>
  <script>
    setTimeout(()=>{
      document.getElementById('toast').style.opacity = '0';
    }, 3000);
  </script>
<?php endif; ?>


    <form id="form-act" method="post">

      <!-- Richiedente & Tutor -->
      <div class="form-grid-2-2">
        <div class="form-group">
          <label for="richiedente_id">Richiedente *</label>
          <select id="richiedente_id" name="richiedente_id">
            <option value="" disabled selected>— seleziona —</option>
            <?php foreach($operatoriList as $op): ?>
            <option value="<?= $op['id'] ?>">
              <?= htmlspecialchars("{$op['cognome']} {$op['nome']}",ENT_QUOTES) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="tutor_id">Tutor *</label>
          <select id="tutor_id" name="tutor_id">
            <option value="" disabled selected>— seleziona —</option>
            <?php foreach($operatoriList as $op): ?>
            <option value="<?= $op['id'] ?>">
              <?= htmlspecialchars("{$op['cognome']} {$op['nome']}",ENT_QUOTES) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Corso & Modalità -->
      <div class="form-grid-half">
        <div class="form-group">
          <label for="corso_id">Corso *</label>
          <select id="corso_id" name="corso_id" required>
            <option value="" disabled selected>Seleziona corso</option>
            <?php foreach($corsiRaw as $c): ?>
            <option
  value="<?= htmlspecialchars($c['id'],ENT_QUOTES) ?>"
  data-max="<?= (int)$c['maxpartecipanti'] ?>"
>
  <?= htmlspecialchars($c['titolo'],ENT_QUOTES) ?>
</option>

            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="modalita">Modalità *</label>
          <select id="modalita" name="modalita" required>
            <option value="" disabled selected>Seleziona modalità</option>
            <?php foreach([
              'Presenza fisica (Aula)',
              'Videochiamata (FAD Sincrona)',
              'E-Learning (FAD Asincrona)',
              'Mista (Blended Learning)'
            ] as $opt): ?>
            <option value="<?= $opt ?>"><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Finanziamento & Fondo -->
      <div class="form-grid-1-1">
       <div class="form-group radio-inline">
  <label class="required">Corso finanziato?</label>
  <label><input type="radio" name="corsoFinanziato" value="1"> Sì</label>
  <label><input type="radio" name="corsoFinanziato" value="0" checked> No</label>
</div>

        <div class="form-group" id="fondo-section" style="display:none;">
          <label for="fondo">Fondo</label>
          <select id="fondo" name="fondo">
            <option value="" disabled selected>Seleziona fondo</option>
            <?php foreach([
              'Fondo For.Te','Fondimpresa','Fondoprofessioni',
              'Fondo Nuove Competenze','FONTER'
            ] as $f): ?>
            <option value="<?= $f ?>"><?= $f ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

<div id="fondo-extra" style="display:none; margin-top:1rem;">
  <h4 id="fondo-intestazione" style="margin-bottom:0.5rem;"></h4>
  <div class="form-group">
    <label for="avviso">Avviso</label>
    <input type="text" id="avviso" name="avviso" class="form-control">
  </div>
  <div class="form-group">
    <label for="cup">CUP</label>
    <input type="text" id="cup" name="cup" class="form-control">
  </div>
  <div class="form-group">
    <label for="numero_azione">Numero Azione</label>
    <input type="text" id="numero_azione" name="numero_azione" class="form-control">
  </div>
</div>

      <!-- Partecipanti & Info -->
      <div class="form-group">
        <label for="n_partecipanti">Numero partecipanti</label>
        <input id="n_partecipanti" name="n_partecipanti" type="number" min="0" value="0">
      </div>
<div class="form-group">
  <label>Azienda/e</label>
  <button type="button" id="open-company-modal" class="btn btn-secondary">
    <i class="bi bi-building"></i> Seleziona aziende
  </button>
  <ul id="selected-companies"></ul>
  <!-- campo nascosto dove concateniamo le scelte -->
  <input type="hidden" name="azienda" id="azienda-hidden">
</div>

      <div class="form-group">
        <label for="luogo">Luogo</label>
        <input id="luogo" name="luogo" type="text">
      </div>
      <div class="form-group">
        <label>Partecipanti</label>
        <button type="button" id="open-part-modal" class="btn btn-secondary">
          <i class="bi bi-people"></i> Seleziona partecipanti
        </button>
        <ul id="selected-participants"></ul>
      </div>

      <!-- Incarichi / Lezioni -->
      <div class="form-group" id="incarico-section">
        <label>Incarichi / Lezioni *</label>
        <div id="lessons">
          <div class="lesson-row">
            <select name="docente_id[]" required>
              <option value="" disabled selected>Docente</option>
            </select>
            <input name="date[]"  type="date" required>
            <input name="start[]" type="time" required>
            <input name="end[]"   type="time" required>
            <button type="button" class="remove-btn">–</button>
          </div>
        </div>
        <button type="button" class="add-lesson">Aggiungi incarico</button>
      </div>

      <!-- Note -->
      <div class="form-group">
        <label for="note">Note</label>
        <textarea id="note" name="note" rows="4"></textarea>
      </div>

      <!-- Azioni -->
      <div class="actions">
        <a href="./attivitae.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Indietro
        </a>
        <button type="submit" name="update" class="btn btn-primary">
          <i class="bi bi-pencil"></i> Salva modifiche
        </button>
      </div>
    </form>
  </div>

  <!-- Modal selezione partecipanti -->
  <div id="participant-modal" class="modal-overlay">
    <div class="modal">
      <h2>Seleziona Partecipanti</h2>
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
        <div class="filter-group">
          <label for="modal-search">Cerca</label>
          <input type="text" id="modal-search" placeholder="Nome, Cognome o CF">
        </div>
      </div>
      <div class="bulk-actions">
        <button id="select-all"     class="btn btn-secondary">Tutti</button>
        <button id="select-company" class="btn btn-secondary">Tutta azienda</button>
        <button id="select-sede"    class="btn btn-secondary">Tutta sede</button>
        <button id="clear-all"      class="btn btn-secondary">Nessuno</button>
      </div>
      <div class="employee-list">
        <ul id="modal-employee-list"></ul>
      </div>
      <div class="modal-actions">
        <button id="save-participants"   class="btn btn-primary">Salva e chiudi</button>
        <button id="cancel-participants" class="btn btn-secondary">Annulla</button>
      </div>
    </div>
  </div>

    <!-- Modal selezione aziende -->
  <div id="company-modal" class="modal-overlay">
    <div class="modal">
      <h2>Seleziona Aziende</h2>
      <div class="filters">
        <input type="text" id="company-search" placeholder="Cerca per nome…">
      </div>
      <div class="employee-list">
        <ul id="modal-company-list"></ul>
      </div>
      <div class="modal-actions">
        <button id="save-companies"   class="btn btn-primary">Aggiungi</button>
        <button id="cancel-companies" class="btn btn-secondary">Chiudi</button>
      </div>
    </div>
  </div>


  <script>
  const companiesRaw   = <?= json_encode($aziendeList, JSON_HEX_TAG) ?>;
  const selectedComps  = new Set();

  // toast rosso client‑side
  function showErrorToast(msg) {
    const t = document.createElement('div');
    t.className = 'toast-error';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.style.opacity = '0', 3000);
  }

  // apri/chiudi modal aziende
  document.getElementById('open-company-modal').onclick = () => {
    document.getElementById('company-modal').classList.add('open');
    renderCompanyList();
  };
  document.getElementById('cancel-companies').onclick = () => {
    document.getElementById('company-modal').classList.remove('open');
  };

  // PHP → JS
  const courseCategories = <?= json_encode($courseCategories, JSON_HEX_TAG) ?>;
  const coursePerm       = <?= json_encode($coursePerm,       JSON_HEX_TAG) ?>;
  const docentiData      = <?= json_encode($docentiData,      JSON_HEX_TAG) ?>;
  const aziendeList      = <?= json_encode($aziendeList,      JSON_HEX_TAG) ?>;
  const sediAll          = <?= json_encode($sediAll,          JSON_HEX_TAG) ?>;
  const dipRaw           = <?= json_encode($dipRaw,           JSON_HEX_TAG) ?>;

  // mappa rapida
  const aziMap = {}, sedMap = {};
  aziendeList.forEach(a=>aziMap[a.id]=a.ragionesociale);
  sediAll.forEach(s=>sedMap[s.id]=s.nome);

  // form & toggles
  const form       = document.getElementById('form-act');
  const coroSelect = document.getElementById('corso_id');
  const modaField  = document.getElementById('modalita');
  const secInc     = document.getElementById('incarico-section');
  const lessonsDiv = document.getElementById('lessons');
  const addBtn     = document.querySelector('.add-lesson');
  const radiosFin  = document.getElementsByName('corsoFinanziato');
  const fondoSection = document.getElementById('fondo-section');

  function updateDocentiOptions(){
    const cat = courseCategories[coroSelect.value]||null;
    document.querySelectorAll('select[name="docente_id[]"]').forEach(sel=>{
      sel.innerHTML = '<option value="" disabled selected>Docente</option>';
      if(!cat) return;
      docentiData.forEach(d=>{
        if(d.categories.includes(cat)){
          const o=document.createElement('option');
          o.value=d.id; o.textContent=`${d.cognome} ${d.nome}`;
          sel.appendChild(o);
        }
      });
    });
  }
  function toggleSection(){
    const hidden = modaField.value==='E-Learning (FAD Asincrona)';
    secInc.style.display = hidden?'none':'block';
    if(!hidden) updateDocentiOptions();
  }
  function toggleFondo(){
    const yes = [...radiosFin].find(r=>r.checked).value==='1';
    fondoSection.style.display = yes?'block':'none';
    fondoSection.querySelector('select').required = yes;
  }

  const fondoInput   = document.querySelector('select[name="fondo"]');
  const extraBox     = document.getElementById('fondo-extra');
  const intestazione = document.getElementById('fondo-intestazione');
  function toggleExtra() {
    const checked = document.querySelector('input[name="corsoFinanziato"]:checked');
    if (checked && checked.value === '1') {
      extraBox.style.display = 'block';
      intestazione.textContent = fondoInput.value ? `Fondo: ${fondoInput.value}` : '';
    } else {
      extraBox.style.display = 'none';
      intestazione.textContent = '';
      document.querySelector('input[name="avviso"]').value = '';
      document.querySelector('input[name="cup"]').value = '';
      document.querySelector('input[name="numero_azione"]').value = '';
    }
  }

  addBtn.addEventListener('click', ()=>{
    const tpl   = document.querySelector('.lesson-row'),
          clone = tpl.cloneNode(true);
    clone.querySelectorAll('input,select').forEach(i=>i.value='');
    lessonsDiv.appendChild(clone);
    updateDocentiOptions();
  });
  lessonsDiv.addEventListener('click', e=>{
    if(e.target.classList.contains('remove-btn')){
      const rows = lessonsDiv.querySelectorAll('.lesson-row');
      if(rows.length>1) e.target.closest('.lesson-row').remove();
    }
  });

  coroSelect.addEventListener('change', ()=>{ updateDocentiOptions(); toggleSection(); });
  modaField .addEventListener('change', toggleSection);
  radiosFin.forEach(r=>r.addEventListener('change', toggleFondo));

  document.addEventListener('DOMContentLoaded', ()=>{
    updateDocentiOptions();
    toggleSection();
    toggleFondo();
    toggleExtra();

    // **Integrazione: controllo max partecipanti prima del submit**
    const nParInput = document.getElementById('n_partecipanti');
    form.addEventListener('submit', e => {
      const opt = coroSelect.options[coroSelect.selectedIndex];
      const max = parseInt(opt.dataset.max || '0', 10);
      const val = parseInt(nParInput.value   || '0', 10);
      if (val > max) {
        e.preventDefault();
        showErrorToast(
          'Numero partecipanti massimo previsto per questo corso: ' + max
        );
        nParInput.focus();
      }
    });
  });

  // Eventi cambio radio e fondo
  document.querySelectorAll('input[name="corsoFinanziato"]').forEach(radio => {
    radio.addEventListener('change', toggleExtra);
  });
  fondoInput.addEventListener('change', () => {
    if (document.querySelector('input[name="corsoFinanziato"]:checked').value === '1') {
      intestazione.textContent = fondoInput.value ? `Fondo: ${fondoInput.value}` : '';
    }
  });

    // === PiP Partecipanti ===
    const modal       = document.getElementById('participant-modal');
    const aziFilter   = document.getElementById('modal-azienda');
    const sedFilter   = document.getElementById('modal-sede');
    const searchIn    = document.getElementById('modal-search');
    const empList     = document.getElementById('modal-employee-list');
    const selList     = document.getElementById('selected-participants');
    const participants = new Set();

    document.getElementById('open-part-modal').onclick = ()=>{
      modal.classList.add('open');
      renderFilters();
      renderEmployees();
    };
    document.getElementById('cancel-participants').onclick = ()=>{
      modal.classList.remove('open');
    };

    aziFilter.onchange = ()=>{ renderFilters(); renderEmployees(); };
    sedFilter.onchange = renderEmployees;
    searchIn.oninput   = renderEmployees;

    document.getElementById('select-all').onclick = ()=>{
      dipRaw.forEach(d=>participants.add(d.id));
      renderEmployees();
    };
    document.getElementById('select-company').onclick = ()=>{
      dipRaw.forEach(d=>{ if(!aziFilter.value||d.azienda_id===aziFilter.value) participants.add(d.id); });
      renderEmployees();
    };
    document.getElementById('select-sede').onclick = ()=>{
      dipRaw.forEach(d=>{ if(sedFilter.value&&d.sede_id===sedFilter.value) participants.add(d.id); });
      renderEmployees();
    };
    document.getElementById('clear-all').onclick = ()=>{
      participants.clear(); renderEmployees();
    };

    function renderFilters(){
      sedFilter.disabled = !aziFilter.value;
      sedFilter.innerHTML = '<option value="">Tutte le sedi</option>';
      sediAll.forEach(s=>{
        if(!aziFilter.value||s.azienda_id===aziFilter.value){
          const o=document.createElement('option');
          o.value=s.id; o.textContent=s.nome;
          sedFilter.appendChild(o);
        }
      });
    }
    function renderEmployees(){
      empList.innerHTML = '';
      const q = searchIn.value.trim().toLowerCase();
      dipRaw.forEach(d=>{
        if((aziFilter.value&&d.azienda_id!==aziFilter.value)
        || (sedFilter.value&&d.sede_id!==sedFilter.value)) return;
        const text = `${d.nome} ${d.cognome} ${d.codice_fiscale}`.toLowerCase();
        if(q&& !text.includes(q)) return;
        const li=document.createElement('li'),
              info=document.createElement('div'),
              cb=document.createElement('input');
        info.className='info';
        info.innerHTML = `<strong>${d.cognome} ${d.nome}</strong>
          <span>${aziMap[d.azienda_id]||''} (${sedMap[d.sede_id]||''})</span>`;
        cb.type='checkbox'; cb.value=d.id; cb.checked=participants.has(d.id);
        cb.onchange = ()=> cb.checked ? participants.add(d.id) : participants.delete(d.id);
        li.append(info, cb);
        empList.appendChild(li);
      });
    }
function renderCompanyList(){
  const ul = document.getElementById('modal-company-list');
  const q  = document.getElementById('company-search').value.trim().toLowerCase();
  ul.innerHTML = '';
  companiesRaw.forEach(a => {
    if (q && !a.ragionesociale.toLowerCase().includes(q)) return;
    const li   = document.createElement('li'),
          info = document.createElement('div'),
          cb   = document.createElement('input');
    info.textContent = a.ragionesociale;
    cb.type    = 'checkbox';
    cb.value   = a.id;
    cb.checked = selectedComps.has(a.id);
    cb.onchange = ()=> {
      cb.checked ? selectedComps.add(a.id) : selectedComps.delete(a.id);
    };
    li.append(info, cb);
    ul.appendChild(li);
  });
}
document.getElementById('company-search').oninput = renderCompanyList;

// 2️⃣ save-companies
document.getElementById('save-companies').onclick = () => {
  modal.classList.remove('open');
  const listUl   = document.getElementById('selected-companies');
  const hidInput = document.getElementById('azienda-hidden');
  listUl.innerHTML = '';
  // helper per aggiornare il campo hidden
  function updateHiddenCompanies() {
    hidInput.value = Array.from(selectedComps)
      .map(id => companiesRaw.find(a=>a.id===id)?.ragionesociale)
      .filter(Boolean)
      .join(', ');
  }
  // ricreo la lista
  selectedComps.forEach(id => {
    const a = companiesRaw.find(x=>x.id===id);
    if (!a) return;
    const li   = document.createElement('li');
    const info = document.createElement('div');
    info.className = 'info';
    info.innerHTML = `<strong>${a.ragionesociale}</strong>`;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = '×';
    btn.onclick = () => {
      selectedComps.delete(a.id);
      li.remove();
      updateHiddenCompanies();
    };
    li.append(info, btn);
    listUl.appendChild(li);
  });
  updateHiddenCompanies();
};



    document.getElementById('save-participants').onclick = ()=>{
      modal.classList.remove('open');
      selList.innerHTML = '';
      document.querySelectorAll('input[name="dipendente_id[]"]').forEach(i=>i.remove());
      participants.forEach(id=>{
        const d = dipRaw.find(x=>x.id===id);
        if(!d) return;
        const li=document.createElement('li'),
              info=document.createElement('div'),
              btn=document.createElement('button'),
              hidden=document.createElement('input');
        info.className='info';
        info.innerHTML=`<strong>${d.cognome} ${d.nome}</strong>
          <span>${aziMap[d.azienda_id]} (${sedMap[d.sede_id]})</span>`;
        btn.type='button'; btn.textContent='×';
        btn.onclick = ()=>{ participants.delete(d.id); btn.closest('li').remove(); };
        li.append(info, btn);
        selList.appendChild(li);
        hidden.type='hidden'; hidden.name='dipendente_id[]'; hidden.value=d.id;
        form.appendChild(hidden);
      });
    };

    
  </script>
</body>
</html>
