<?php
// aggiungi_docente.php — form con campi su due colonne, categorie e autocompletamento CF,
// senza uploader. Alla creazione redirect immediato a docente.php?id=...
require_once __DIR__ . '/init.php';

// funzione di validazione CF (checksum)
function isValidCF(string $cf): bool {
    $cf = strtoupper($cf);
    if (!preg_match('/^[A-Z]{6}\d{2}[A-EHLMPRST]\d{2}[A-Z]\d{3}[A-Z]$/', $cf)) {
        return false;
    }
    $oddMap = [
      '0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,
      'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,
      'K'=>2,'L'=>4,'M'=>18,'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,
      'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23
    ];
    $evenMap = [
      '0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,
      'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,'I'=>8,'J'=>9,
      'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,'Q'=>16,'R'=>17,'S'=>18,'T'=>19,
      'U'=>20,'V'=>21,'W'=>22,'X'=>23,'Y'=>24,'Z'=>25
    ];
    $sum = 0;
    for ($i = 0; $i < 15; $i++) {
        $c = $cf[$i];
        $sum += ($i % 2 === 0 ? $oddMap[$c] : $evenMap[$c]);
    }
    return $cf[15] === chr(65 + ($sum % 26));
}

// AJAX endpoint per controllo CF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'check_cf') {
    header('Content-Type: application/json');
    $cf = strtoupper(trim($_POST['cf'] ?? ''));
    $valid = isValidCF($cf);
    $exists = false;
    if ($valid) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM docente WHERE cf = ?');
        $chk->execute([$cf]);
        $exists = ((int)$chk->fetchColumn() > 0);
    }
    echo json_encode(['valid' => $valid, 'exists' => $exists]);
    exit;
}

// 1) Pre‐carica mappa codice_belfiore → [denominazione, sigla]
$comuniStmt = $pdo->query(<<<'SQL'
  SELECT codice_belfiore, denominazione_ita, sigla_provincia
    FROM gi_comuni_nazioni_cf
   WHERE (data_inizio_validita IS NULL OR data_inizio_validita <= CURDATE())
     AND (data_fine_validita   IS NULL OR data_fine_validita   >= CURDATE())
SQL
);
$comuniMap = [];
while ($r = $comuniStmt->fetch(PDO::FETCH_ASSOC)) {
    $comuniMap[$r['codice_belfiore']] = [
      'den'  => $r['denominazione_ita'],
      'prov' => $r['sigla_provincia']
    ];
}

$allowedCategories = ['HACCP','Sicurezza','Antincendio','Primo Soccorso','Macchine Operatrici'];

$errorRequired = false;
$errorCfDup    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    // raccogli e pulisci dati
    $nome           = trim($_POST['nome'] ?? '');
    $cognome        = trim($_POST['cognome'] ?? '');
    $cf             = strtoupper(trim($_POST['cf'] ?? ''));
    $dataNascita    = $_POST['dataNascita']    ?: null;
    $luogoNascita   = trim($_POST['luogoNascita'] ?? '');
    $piva           = trim($_POST['piva'] ?? '');
    $cittaResidenza = trim($_POST['cittaResidenza'] ?? '');
    $viaResidenza   = trim($_POST['viaResidenza'] ?? '');
    $costoOrario    = trim($_POST['costoOrario'] ?? '');
    $categorie      = $_POST['categorie'] ?? [];

    // verifica obbligatorietà
    if ($nome === '' || $cognome === '' || $cf === '' || $costoOrario === '') {
        $errorRequired = true;
    } else {
        // verifica duplicato CF
        $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM docente WHERE cf = ?');
        $dupStmt->execute([$cf]);
        if ($dupStmt->fetchColumn() > 0) {
            $errorCfDup = true;
        } else {
            // inserisci docente
            $id = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO docente
  (id,nome,cognome,cf,dataNascita,luogoNascita,cittaResidenza,viaResidenza,piva,costoOrario)
VALUES (?,?,?,?,?,?,?,?,?,?)
SQL
            );
            $stmt->execute([
                $id, $nome, $cognome, $cf,
                $dataNascita, $luogoNascita,
                $cittaResidenza, $viaResidenza,
                $piva, $costoOrario
            ]);
            // inserisci categorie
            if (!empty($categorie) && is_array($categorie)) {
                $stmtCat = $pdo->prepare(
                  'INSERT INTO docentecategoria (id,docente_id,categoria) VALUES (?,?,?)'
                );
                foreach ($categorie as $cat) {
                    if (in_array($cat, $allowedCategories, true)) {
                        $stmtCat->execute([bin2hex(random_bytes(16)), $id, $cat]);
                    }
                }
            }
            // redirect immediato
            header("Location: /biosound/docente.php?id=" . urlencode($id));
            exit;
        }
    }
}

// navbar
$role = $_SESSION['role'] ?? 'utente';
if ($role === 'admin')    include 'navbar_a.php';
elseif ($role === 'dev')   include 'navbar_d.php';
else                       include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Aggiungi Docente</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    html, body { margin:0; padding:0; background:#f0f2f5; }
    nav.app-nav { margin-bottom:0!important; border-radius:0 0 8px 8px; }
    main.aggiungi-docente {
      padding:2rem 1rem 3rem;
      min-height:calc(100vh - 60px);
      font-family:'Segoe UI',sans-serif;
      background:#f0f2f5;
    }
    h1 { text-align:center; color:#2e3a45; margin-bottom:1.5rem; }
    .alert-success, .alert-danger {
      padding:.75rem 1rem; border-radius:8px;
      margin:1rem auto; max-width:400px;
      box-shadow:0 2px 6px rgba(0,0,0,0.08);
      text-align:center;
    }
    .alert-success { background:#d4edda; color:#155724; }
    .alert-danger  { background:#f8d7da; color:#721c24; }
    form {
      max-width:700px; margin:0 auto;
      display:flex; flex-direction:column; gap:1.5rem;
    }
    .form-grid {
      display:grid; grid-template-columns:1fr 1fr; gap:1rem;
    }
    .form-group { display:flex; flex-direction:column; }
    label { margin-bottom:.25rem; font-weight:500; color:#2e3a45; }
    label.required::after { content:" *"; color:#dc3545; }
    input[type="text"], input[type="date"], select {
      padding:.5rem .75rem; border:1px solid #ccc;
      border-radius:8px; font-size:1rem;
    }
    .checkbox-group { display:flex; flex-wrap:wrap; gap:.5rem; }
    #requiredList {
      margin:0 auto 1.5rem; max-width:700px; color:#2e3a45;
    }
    .actions {
      display:flex; justify-content:center; gap:2rem; margin-top:1rem;
    }
    .btn {
      padding:.6rem 1.2rem; border:none; border-radius:8px;
      cursor:pointer; color:#fff; font-size:1rem; font-weight:bold;
      display:inline-flex; align-items:center; gap:.5rem;
      transition:background .2s,transform .15s;
    }
    .btn-secondary { background:#6c757d; }
    .btn-secondary:hover {
      background:#5a6268; transform:translateY(-2px);
    }
    .btn-primary { background:#66bb6a; }
    .btn-primary:hover {
      background:#5aad5c; transform:translateY(-2px);
    }
  </style>
</head>
<body>
  <main class="aggiungi-docente">
    <h1>Aggiungi Docente</h1>

    <?php if ($errorRequired): ?>
      <div class="alert-danger">Compila tutti i campi obbligatori (*)</div>
    <?php elseif ($errorCfDup): ?>
      <div class="alert-danger">Codice fiscale già presente</div>
    <?php endif; ?>

    <form id="docForm" method="post">
      <div class="form-grid">
        <div class="form-group">
          <label for="nome" class="required">Nome</label>
          <input id="nome" name="nome" type="text" required
            value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="cognome" class="required">Cognome</label>
          <input id="cognome" name="cognome" type="text" required
            value="<?= htmlspecialchars($_POST['cognome'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="cf" class="required">Codice Fiscale</label>
          <input id="cf" name="cf" type="text" maxlength="16" required
            value="<?= htmlspecialchars($_POST['cf'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="dataNascita">Data di nascita</label>
          <input id="dataNascita" name="dataNascita" type="date"
            value="<?= htmlspecialchars($_POST['dataNascita'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="luogoNascita">Luogo di nascita</label>
          <input id="luogoNascita" name="luogoNascita" type="text"
            value="<?= htmlspecialchars($_POST['luogoNascita'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="piva">Partita IVA</label>
          <input id="piva" name="piva" type="text" maxlength="11"
            value="<?= htmlspecialchars($_POST['piva'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="cittaResidenza">Città di residenza</label>
          <input id="cittaResidenza" name="cittaResidenza" type="text"
            value="<?= htmlspecialchars($_POST['cittaResidenza'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="viaResidenza">Via di residenza</label>
          <input id="viaResidenza" name="viaResidenza" type="text"
            value="<?= htmlspecialchars($_POST['viaResidenza'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="costoOrario" class="required">Costo orario (€)</label>
          <input id="costoOrario" name="costoOrario" type="text" required
            value="<?= htmlspecialchars($_POST['costoOrario'] ?? '') ?>">
        </div>
        <div class="form-group span-two">
          <label>Categorie abilitate</label>
          <div class="checkbox-group">
            <?php foreach($allowedCategories as $cat): ?>
              <label>
                <input type="checkbox" name="categorie[]" value="<?= $cat ?>"
                  <?= in_array($cat, $_POST['categorie'] ?? [], true) ? 'checked' : '' ?>>
                <?= $cat ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div id="requiredList"></div>

      <div class="actions">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva Docente
        </button>
        <a href="/biosound/docenti.php" class="btn btn-secondary">
          <i class="bi bi-x-lg"></i> Annulla
        </a>
      </div>
    </form>
  </main>

  <script>
    // mappa comuni, allegati richiesti
    const MONTH_MAP = {
      A:'01',B:'02',C:'03',D:'04',E:'05',H:'06',
      L:'07',M:'08',P:'09',R:'10',S:'11',T:'12'
    };
    const comuniMap = <?= json_encode($comuniMap, JSON_HEX_TAG) ?>;
const attachMap = {
  'Sicurezza': { label: 'Autocertificazione 1C', file: 'MOD_1C.pdf' },
  'Antincendio': { label: 'Autocertificazione 1CA', file: 'MOD_1CA.pdf' },
  'Macchine Operatrici': { label: 'Autocertificazione 1D', file: 'MOD_1D.pdf' }
};


    // CF → data & luogo
    document.getElementById('cf').addEventListener('input', e => {
      const cf = e.target.value.trim().toUpperCase();
      const dob = document.getElementById('dataNascita');
      const loc = document.getElementById('luogoNascita');
      if (!/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/.test(cf)) {
        dob.value = ''; loc.value = ''; return;
      }
      const yy   = parseInt(cf.substr(6,2),10),
            cur  = new Date().getFullYear()%100,
            year = yy <= cur ? 2000+yy : 1900+yy,
            mon  = MONTH_MAP[cf.charAt(8)],
            raw  = parseInt(cf.substr(9,2),10),
            day  = raw>40?raw-40:raw;
      dob.value = `${year}-${mon}-${String(day).padStart(2,'0')}`;
      const info = comuniMap[cf.substr(11,4)]||{};
      loc.value = info.den ? `${info.den} (${info.prov})` : '';
    });

    // aggiorna lista richiesti
function updateRequired() {
  const checked = Array.from(
    document.querySelectorAll('input[name="categorie[]"]:checked')
  ).map(cb => cb.value);

  const baseItems = [
    { label: 'Curriculum Vitae' },
    { label: 'Documento di identità' }
  ];

  const dynamicItems = [];

  checked.forEach(cat => {
    const doc = attachMap[cat];
    if (doc) {
      dynamicItems.push({
        label: doc.label,
        file: `/biosound/resources/${doc.file}`
      });
    }
  });

  const allItems = [...baseItems, ...dynamicItems];

  const listHTML = `
    <p>Sarà possibile caricare i documenti una volta creato il docente.</p>
    <p><strong>Documenti richiesti:</strong></p>
    <ul style="margin:0;padding-left:1.25rem">
      ${allItems.map(item => {
        if (item.file) {
          return `<li>
            <a href="${item.file}" download style="color:#007bff;text-decoration:underline;cursor:pointer">
              ${item.label}
            </a>
          </li>`;
        } else {
          return `<li>${item.label}</li>`;
        }
      }).join('')}
    </ul>
  `;

  document.getElementById('requiredList').innerHTML = listHTML;
}


    document.querySelectorAll('input[name="categorie[]"]')
      .forEach(cb=>cb.addEventListener('change', updateRequired));
    updateRequired();

    // checksum + duplicate check via AJAX
    function validateCF(cf) {
      cf = cf.toUpperCase();
      const re = /^[A-Z]{6}\d{2}[A-EHLMPRST]\d{2}[A-Z]\d{3}[A-Z]$/;
      if (!re.test(cf)) return false;
      const oddMap = {
        '0':1,'1':0,'2':5,'3':7,'4':9,'5':13,'6':15,'7':17,'8':19,'9':21,
        'A':1,'B':0,'C':5,'D':7,'E':9,'F':13,'G':15,'H':17,'I':19,'J':21,
        'K':2,'L':4,'M':18,'N':20,'O':11,'P':3,'Q':6,'R':8,'S':12,'T':14,
        'U':16,'V':10,'W':22,'X':25,'Y':24,'Z':23
      };
      const evenMap = {
        '0':0,'1':1,'2':2,'3':3,'4':4,'5':5,'6':6,'7':7,'8':8,'9':9,
        'A':0,'B':1,'C':2,'D':3,'E':4,'F':5,'G':6,'H':7,'I':8,'J':9,
        'K':10,'L':11,'M':12,'N':13,'O':14,'P':15,'Q':16,'R':17,'S':18,'T':19,
        'U':20,'V':21,'W':22,'X':23,'Y':24,'Z':25
      };
      let sum=0;
      for(let i=0;i<15;i++){
        sum += (i%2 ? evenMap[cf[i]] : oddMap[cf[i]]);
      }
      return cf[15] === String.fromCharCode(65 + (sum % 26));
    }

    document.getElementById('docForm').addEventListener('submit', e => {
      e.preventDefault();
      const cf = document.getElementById('cf').value.trim().toUpperCase();
      if (!validateCF(cf)) {
        alert('Codice Fiscale non valido');
        return;
      }
      fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax=check_cf&cf='+encodeURIComponent(cf)
      })
      .then(r=>r.json())
      .then(json=>{
        if (!json.valid) {
          alert('Codice Fiscale non valido');
        } else if (json.exists) {
          alert('Codice fiscale già presente');
        } else {
          e.target.submit();
        }
      });
    });
  </script>
</body>
</html>
