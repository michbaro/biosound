<?php
// aggiungi_dipendente.php — form per aggiungere un nuovo dipendente con CF prefill,
// validazione CF e controllo unicità CF per azienda via AJAX
include 'init.php';

// funzione di validazione CF (controllo ultimo carattere)
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
    $check = chr(65 + ($sum % 26));
    return $cf[15] === $check;
}

// AJAX endpoint per check CF esistenza/validità
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'check_cf') {
    header('Content-Type: application/json');
    $cf = strtoupper(trim($_POST['codice_fiscale'] ?? ''));
    $azienda = $_POST['azienda_id'] ?? '';
    $valid = isValidCF($cf);
    $exists = false;
    if ($valid && $azienda) {
        $chk = $pdo->prepare("
          SELECT COUNT(*) FROM dipendente d
          JOIN dipendente_sede ds ON d.id = ds.dipendente_id
          JOIN sede s ON ds.sede_id = s.id
          WHERE d.codice_fiscale = ? AND s.azienda_id = ?
        ");
        $chk->execute([$cf, $azienda]);
        $exists = ((int)$chk->fetchColumn() > 0);
    }
    echo json_encode(['valid' => $valid, 'exists' => $exists]);
    exit;
}

// prepara form-values
$form = array_fill_keys([
  'nome','cognome','codice_fiscale','datanascita',
  'luogonascita','comuneresidenza','viaresidenza','mansione'
], '');

// contesto da GET e POST
$fixedAzienda = $_GET['azienda_id'] ?? null;
$fixedSede    = $_GET['sede_id']    ?? null;
$postAzienda  = $_POST['azienda_id'] ?? null;
$postSede     = $_POST['sede_id']    ?? null;
$ctxAzienda   = $fixedAzienda ?: $postAzienda;
$ctxSede      = $fixedSede    ?: $postSede;

// liste aziende e sedi
$aziendeList = $pdo->query('SELECT id, ragionesociale FROM azienda ORDER BY ragionesociale')
                   ->fetchAll(PDO::FETCH_ASSOC);
$sediAll     = $pdo->query('SELECT id,nome,azienda_id FROM sede ORDER BY nome')
                   ->fetchAll(PDO::FETCH_ASSOC);

// nome azienda/sede e sediList filtrata
$aziendaNome = $sedeNome = '';
$sediList    = [];
if ($ctxSede) {
    $stmt = $pdo->prepare("
      SELECT s.nome AS sede_nome, s.azienda_id, a.ragionesociale
        FROM sede s JOIN azienda a ON a.id = s.azienda_id
       WHERE s.id = ?
    ");
    $stmt->execute([$ctxSede]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      $sedeNome    = $r['sede_nome'];
      $ctxAzienda  = $r['azienda_id'];
      $aziendaNome = $r['ragionesociale'];
      $sediList    = [['id'=>$ctxSede,'nome'=>$sedeNome]];
    }
}
elseif ($ctxAzienda) {
    $stmt = $pdo->prepare("SELECT ragionesociale FROM azienda WHERE id = ?");
    $stmt->execute([$ctxAzienda]);
    $aziendaNome = $stmt->fetchColumn() ?: '';
    $sStmt = $pdo->prepare("SELECT id,nome FROM sede WHERE azienda_id = ? ORDER BY nome");
    $sStmt->execute([$ctxAzienda]);
    $sediList = $sStmt->fetchAll(PDO::FETCH_ASSOC);
}

// mappa comuni per CF→luogo
$comuniStmt = $pdo->query("
  SELECT codice_belfiore, denominazione_ita, sigla_provincia
    FROM gi_comuni_nazioni_cf
   WHERE (data_inizio_validita IS NULL OR data_inizio_validita <= CURDATE())
     AND (data_fine_validita   IS NULL OR data_fine_validita   >= CURDATE())
");
$comuniMap = [];
while ($r = $comuniStmt->fetch(PDO::FETCH_ASSOC)) {
    $comuniMap[$r['codice_belfiore']] = [
      'den'=>$r['denominazione_ita'],
      'prov'=>$r['sigla_provincia']
    ];
}

// inserimento server‐side (in caso di bypass JS)
$errorServer = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    foreach (array_keys($form) as $f) {
        $form[$f] = trim($_POST[$f] ?? '');
    }
    if (!isValidCF($form['codice_fiscale'])) {
        $errorServer = 'Codice Fiscale non valido';
    } else {
        $chk = $pdo->prepare("
          SELECT COUNT(*) FROM dipendente d
          JOIN dipendente_sede ds ON d.id=ds.dipendente_id
          JOIN sede s ON ds.sede_id=s.id
          WHERE d.codice_fiscale = ? AND s.azienda_id = ?
        ");
        $chk->execute([$form['codice_fiscale'], $ctxAzienda]);
        if ($chk->fetchColumn() > 0) {
            $errorServer = 'Codice Fiscale già presente per questa azienda';
        }
    }
    if ($errorServer === '') {
        $dipId = bin2hex(random_bytes(16));
        $ins = $pdo->prepare(<<<'SQL'
INSERT INTO dipendente
  (id,nome,cognome,codice_fiscale,datanascita,luogonascita,
   comuneresidenza,viaresidenza,mansione)
VALUES (?,?,?,?,?,?,?,?,?)
SQL
        );
        $ins->execute([
          $dipId,
          $form['nome'],
          $form['cognome'],
          $form['codice_fiscale'],
          $form['datanascita'] ?: null,
          $form['luogonascita'],
          $form['comuneresidenza'],
          $form['viaresidenza'],
          $form['mansione']
        ]);
        if ($ctxSede) {
            $pdo->prepare('INSERT INTO dipendente_sede(dipendente_id,sede_id) VALUES(?,?)')
                ->execute([$dipId, $ctxSede]);
        }
        $target = $ctxSede
          ? "dipendenti.php?sede_id=" . urlencode($ctxSede)
          : "dipendenti.php?azienda_id=" . urlencode($ctxAzienda);
        header("Location:/biosound/{$target}&added=1");
        exit;
    }
}

// include navbar
$role = $_SESSION['role'] ?? 'utente';
if ($role==='admin')    include 'navbar_a.php';
elseif ($role==='dev')  include 'navbar_d.php';
else                    include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Aggiungi Dipendente</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    :root{--bg:#f0f2f5;--fg:#2e3a45;--radius:8px;--shadow:rgba(0,0,0,0.08);
      --font:'Segoe UI',sans-serif;--pri:#66bb6a;--err:#d9534f}
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:var(--bg);color:var(--fg);font-family:var(--font)}
    .container{max-width:700px;margin:2rem auto;padding:0 1rem}
    h1{text-align:center;margin-bottom:1rem}
    .alert-danger{padding:.75rem 1rem;background:#f8d7da;color:#721c24;
      border-radius:var(--radius);margin-bottom:1rem;max-width:400px;
      margin:0 auto;box-shadow:0 2px 6px var(--shadow);text-align:center}
    .form-group{margin-bottom:1rem}
    label{display:block;margin-bottom:.5rem;font-weight:500}
    input,select{width:100%;padding:.5rem .75rem;border:1px solid #ccc;
      border-radius:var(--radius);font-size:1rem}
    .actions{display:flex;justify-content:center;gap:3rem;margin-top:2rem}
    .btn{display:inline-flex;align-items:center;gap:.75rem;
      padding:.6rem 1.2rem;font-size:1rem;font-weight:bold;color:#fff;
      border:none;border-radius:var(--radius);text-decoration:none;cursor:pointer;
      transition:background .2s,transform .15s}
    .btn-secondary{background:#6c757d}
    .btn-secondary:hover{background:#5a6268;transform:translateY(-2px)}
    .btn-primary{background:var(--pri)}
    .btn-primary:hover{background:#5aad5c;transform:translateY(-2px)}
  </style>
</head>
<body>
  <div class="container">
    <h1>Aggiungi Dipendente</h1>

    <?php if ($errorServer): ?>
      <div class="alert-danger"><?= htmlspecialchars($errorServer) ?></div>
    <?php endif; ?>

    <form id="dipForm" method="post">
      <?php if ($fixedSede): ?>
        <div class="form-group">
          <label>Sede</label>
          <input type="text" value="<?=htmlspecialchars($sedeNome)?>" disabled>
          <input type="hidden" name="sede_id" value="<?=htmlspecialchars($ctxSede)?>">
        </div>
      <?php elseif ($fixedAzienda): ?>
        <div class="form-group">
          <label>Azienda</label>
          <input type="text" value="<?=htmlspecialchars($aziendaNome)?>" disabled>
          <input type="hidden" name="azienda_id" value="<?=htmlspecialchars($ctxAzienda)?>">
        </div>
        <div class="form-group">
          <label for="sede_id">Sede</label>
          <select id="sede_id" name="sede_id" required>
            <option value="" disabled <?= $ctxSede?'':'selected' ?>>Seleziona sede</option>
            <?php foreach($sediList as $s): ?>
              <option value="<?=$s['id']?>" <?= $ctxSede==$s['id']?'selected':''?>>
                <?=htmlspecialchars($s['nome'],ENT_QUOTES)?>
              </option>
            <?php endforeach;?>
          </select>
        </div>
      <?php else: ?>
        <div class="form-group">
          <label for="azienda_id">Azienda</label>
          <select id="azienda_id" name="azienda_id" required>
            <option value="" disabled <?= $ctxAzienda?'':'selected'?>>Seleziona azienda</option>
            <?php foreach($aziendeList as $a): ?>
              <option value="<?=$a['id']?>" <?= $ctxAzienda==$a['id']?'selected':''?>>
                <?=htmlspecialchars($a['ragionesociale'],ENT_QUOTES)?>
              </option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="form-group">
          <label for="sede_id">Sede</label>
          <select id="sede_id" name="sede_id" required>
            <?php if ($ctxAzienda): ?>
              <option value="" disabled <?= $ctxSede?'':'selected'?>>Seleziona sede</option>
              <?php foreach($sediList as $s): ?>
                <option value="<?=$s['id']?>" <?= $ctxSede==$s['id']?'selected':''?>>
                  <?=htmlspecialchars($s['nome'],ENT_QUOTES)?>
                </option>
              <?php endforeach;?>
            <?php else: ?>
              <option value="" disabled selected>Prima scegli l’azienda…</option>
            <?php endif;?>
          </select>
        </div>
      <?php endif; ?>

      <!-- campi anagrafici -->
      <div class="form-group"><label for="nome">Nome</label>
        <input id="nome" name="nome" required value="<?=htmlspecialchars($form['nome'])?>">
      </div>
      <div class="form-group"><label for="cognome">Cognome</label>
        <input id="cognome" name="cognome" required value="<?=htmlspecialchars($form['cognome'])?>">
      </div>
      <div class="form-group"><label for="codice_fiscale">Codice Fiscale</label>
        <input id="codice_fiscale" name="codice_fiscale" maxlength="16" required
               value="<?=htmlspecialchars($form['codice_fiscale'])?>">
      </div>
      <div class="form-group"><label for="datanascita">Data di nascita</label>
        <input id="datanascita" name="datanascita" type="date"
               value="<?=htmlspecialchars($form['datanascita'])?>">
      </div>
      <div class="form-group"><label for="luogonascita">Luogo di nascita</label>
        <input id="luogonascita" name="luogonascita"
               value="<?=htmlspecialchars($form['luogonascita'])?>">
      </div>
      <div class="form-group"><label for="comuneresidenza">Comune di residenza</label>  
        <input id="comuneresidenza" name="comuneresidenza"
               value="<?=htmlspecialchars($form['comuneresidenza'])?>">
      </div>
      <div class="form-group"><label for="viaresidenza">Via di residenza</label>
        <input id="viaresidenza" name="viaresidenza"
               value="<?=htmlspecialchars($form['viaresidenza'])?>">
      </div>
      <div class="form-group"><label for="mansione">Mansione</label>
        <input id="mansione" name="mansione"
               value="<?=htmlspecialchars($form['mansione'])?>">
      </div>

      <div class="actions">
        <a href="/biosound/<?= $ctxSede
            ? "dipendenti.php?sede_id=".urlencode($ctxSede)
            : ($ctxAzienda
                ? "dipendenti.php?azienda_id=".urlencode($ctxAzienda)
                : "dipendenti.php") ?>"
           class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Indietro
        </a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva Dipendente
        </button>
      </div>
    </form>
  </div>

  <script>
    // popola sedi al cambio azienda
    const allSedi   = <?= json_encode($sediAll, JSON_HEX_TAG) ?>;
    const aziSelect = document.getElementById('azienda_id');
    const sedSelect = document.getElementById('sede_id');
    if (aziSelect) {
      aziSelect.addEventListener('change', () => {
        sedSelect.innerHTML = '<option value="" disabled selected>Seleziona sede</option>';
        allSedi.forEach(s => {
          if (String(s.azienda_id) === aziSelect.value) {
            const o = document.createElement('option');
            o.value = s.id; o.textContent = s.nome;
            sedSelect.appendChild(o);
          }
        });
      });
    }

    // CF → dataNascita & luogoNascita
    const MONTH_MAP = {A:'01',B:'02',C:'03',D:'04',E:'05',H:'06',
      L:'07',M:'08',P:'09',R:'10',S:'11',T:'12'};
    const comuniMap = <?= json_encode($comuniMap, JSON_HEX_TAG) ?>;
    const cfInput   = document.getElementById('codice_fiscale');
    const dobInput  = document.getElementById('datanascita');
    const locInput  = document.getElementById('luogonascita');

    cfInput.addEventListener('input', () => {
      const cf = cfInput.value.trim().toUpperCase();
      if (!/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/.test(cf)) {
        dobInput.value = ''; locInput.value = ''; return;
      }
      const yy   = parseInt(cf.substr(6,2),10),
            cur  = new Date().getFullYear()%100,
            year = yy <= cur ? 2000+yy : 1900+yy,
            mon  = MONTH_MAP[cf.charAt(8)],
            raw  = parseInt(cf.substr(9,2),10),
            day  = String(raw>40?raw-40:raw).padStart(2,'0'),
            code = cf.substr(11,4),
            info = comuniMap[code] || {};
      dobInput.value = `${year}-${mon}-${day}`;
      locInput.value = info.den ? `${info.den} (${info.prov})` : '';
    });

    // validazione CF client-side (pattern + checksum)
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
      let sum = 0;
      for (let i = 0; i < 15; i++) {
        const c = cf[i];
        sum += (i % 2 === 0 ? oddMap[c] : evenMap[c]);
      }
      const check = String.fromCharCode(65 + (sum % 26));
      return cf[15] === check;
    }

    // intercept submit per AJAX check duplicati/validità
    document.getElementById('dipForm').addEventListener('submit', e => {
      e.preventDefault();
      const cf  = cfInput.value.trim().toUpperCase();
      const azi = document.querySelector('input[name="azienda_id"]')?.value
                || document.getElementById('azienda_id')?.value;
      if (!validateCF(cf)) {
        alert('Codice Fiscale non valido');
        return;
      }
      fetch(location.href, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax=check_cf'
           + '&codice_fiscale=' + encodeURIComponent(cf)
           + (azi ? '&azienda_id=' + encodeURIComponent(azi) : '')
      })
      .then(r => r.json())
      .then(json => {
        if (!json.valid) {
          alert('Codice Fiscale non valido');
        } else if (json.exists) {
          alert('Codice Fiscale già presente per questa azienda');
        } else {
          e.target.submit();
        }
      });
    });
  </script>
</body>
</html>
