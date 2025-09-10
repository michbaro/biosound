<?php
// docente.php — Modifica Docente con controlli CF AJAX e allegati infiniti
require_once __DIR__ . '/init.php';

// Funzione di validazione CF (checksum)
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

// 1) AJAX endpoint per check CF duplicato/validità
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'check_cf') {
    header('Content-Type: application/json');
    $cf = strtoupper(trim($_POST['cf'] ?? ''));
    $id = $_GET['id'] ?? '';
    $valid = isValidCF($cf);
    $exists = false;
    if ($valid && $id) {
        $chk = $pdo->prepare("
          SELECT COUNT(*) FROM docente
          WHERE cf = ? AND id != ?
        ");
        $chk->execute([$cf, $id]);
        $exists = ((int)$chk->fetchColumn() > 0);
    }
    echo json_encode(['valid' => $valid, 'exists' => $exists]);
    exit;
}

// Funzione per rimuovere ricorsivamente una directory
function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($dir);
}

// 2) Mappa comuni CF→luogo
$comuniStmt = $pdo->query("
  SELECT codice_belfiore, denominazione_ita, sigla_provincia
    FROM gi_comuni_nazioni_cf
   WHERE (data_inizio_validita IS NULL OR data_inizio_validita <= CURDATE())
     AND (data_fine_validita   IS NULL OR data_fine_validita   >= CURDATE())
");
$comuniMap = [];
while ($r = $comuniStmt->fetch(PDO::FETCH_ASSOC)) {
    $comuniMap[$r['codice_belfiore']] = [
      'den'  => $r['denominazione_ita'],
      'prov' => $r['sigla_provincia']
    ];
}

// 3) Categorie e documenti richiesti
$allowedCategories = ['HACCP','Sicurezza','Antincendio','Primo Soccorso','Macchine Operatrici'];
$attachMap = [
  'Sicurezza'           => 'Autodichiarazione 1C',
  'Antincendio'         => 'Autodichiarazione 1CA',
  'Macchine Operatrici' => 'Autodichiarazione 1D'
];

// 4) Recupera ID o redirect
$id = $_GET['id'] ?? '';
if (!$id) {
  header('Location:./docenti.php');
  exit;
}

// 5) Verifica incarichi
$stmtInc = $pdo->prepare('SELECT COUNT(*) FROM docenteincarico WHERE docente_id = ?');
$stmtInc->execute([$id]);
$hasIncarichi = ((int)$stmtInc->fetchColumn() > 0);

// 6) Carica docente
$stmt = $pdo->prepare("
  SELECT nome,cognome,cf,dataNascita,luogoNascita,
         cittaResidenza,viaResidenza,piva,costoOrario,allegati
    FROM docente WHERE id = ?
");
$stmt->execute([$id]);
$docente = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$docente) {
  header('Location:./docenti.php');
  exit;
}
$allegati = json_decode($docente['allegati'], true) ?: [];

// 7) Categorie correnti
$stmtC = $pdo->prepare('SELECT categoria FROM docentecategoria WHERE docente_id = ?');
$stmtC->execute([$id]);
$currentCats = $stmtC->fetchAll(PDO::FETCH_COLUMN);

// 8) AJAX single‐file delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteSingle'])) {
  $del = $_POST['deleteSingle'];
  $allegati = array_values(array_diff($allegati, [$del]));
  $pdo->prepare("UPDATE docente SET allegati = ? WHERE id = ?")->execute([json_encode($allegati), $id]);
  $full = __DIR__ . '/' . $del;
  if (file_exists($full)) unlink($full);
  header('Content-Type: application/json');
  echo json_encode(['ok' => true]);
  exit;
}

// 9) Elimina docente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
  if (!$hasIncarichi) {
    rrmdir(__DIR__ . "/resources/docenti/{$id}/");
    $pdo->prepare('DELETE FROM docentecategoria WHERE docente_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM docente WHERE id = ?')->execute([$id]);
    header('Location:./docenti.php');
    exit;
  }
}

// 10) Aggiorna docente (solo quando si clicca “Salva Modifiche”)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
   // a) pulizia campi
   $nome  = trim($_POST['nome']);
   $cogn  = trim($_POST['cognome']);
   $cf    = strtoupper(trim($_POST['cf']));
   $dataN = $_POST['dataNascita'] ?: null;
   $luogo = trim($_POST['luogoNascita']);
   $piva  = trim($_POST['piva']);
   $citt  = trim($_POST['cittaResidenza']);
   $via   = trim($_POST['viaResidenza']);
   $costo = trim($_POST['costoOrario']);
   $cats  = $_POST['categorie'] ?? [];

  // b) cartella univoca per ID
  $dir = __DIR__ . "/resources/docenti/{$id}/";
  $web = "resources/docenti/{$id}/";
  if (!is_dir($dir)) mkdir($dir, 0755, true);

  // d) replace esistenti
  if (!empty($_FILES['replace']['name'])) {
    foreach ($_FILES['replace']['name'] as $i => $name) {
      if ($_FILES['replace']['error'][$i] === UPLOAD_ERR_OK) {
        $old = __DIR__ . '/' . $allegati[$i];
        if (file_exists($old)) unlink($old);
        move_uploaded_file($_FILES['replace']['tmp_name'][$i], $dir . basename($name));
        $allegati[$i] = $web . basename($name);
      }
    }
  }

  // e) nuovi upload
  if (!empty($_FILES['newfile']['name'][0])) {
    foreach ($_FILES['newfile']['name'] as $j => $name) {
      if ($_FILES['newfile']['error'][$j] === UPLOAD_ERR_OK) {
        move_uploaded_file($_FILES['newfile']['tmp_name'][$j], $dir . basename($name));
        $allegati[] = $web . basename($name);
      }
    }
  }

  // f) salva DB
  $pdo->prepare("
    UPDATE docente SET
      nome=?,cognome=?,cf=?,dataNascita=?,luogoNascita=?,
      cittaResidenza=?,viaResidenza=?,piva=?,costoOrario=?,allegati=?
    WHERE id=?
  ")->execute([
    $nome,$cogn,$cf,$dataN,$luogo,
    $citt,$via,$piva,$costo,json_encode($allegati),$id
  ]);

  // sync categorie
  $pdo->prepare('DELETE FROM docentecategoria WHERE docente_id = ?')->execute([$id]);
  $stmtCat = $pdo->prepare('INSERT INTO docentecategoria(id,docente_id,categoria) VALUES(?,?,?)');
  foreach ($cats as $cat) {
    if (in_array($cat, $allowedCategories, true)) {
      $stmtCat->execute([bin2hex(random_bytes(8)), $id, $cat]);
    }
  }

  // dopo l’UPDATE, torniamo alla lista con toast di successo
  header('Location: ./docenti.php?updated=1');
  exit;
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
  <title>Modifica Docente</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    /* Stile identico ad aggiungi_docente.php */
    html,body{margin:0;padding:0;background:#f0f2f5}
    nav.app-nav{margin-bottom:0!important;border-radius:0 0 8px 8px}
    main{padding:2rem 1rem 3rem;font-family:'Segoe UI',sans-serif}
    h1{text-align:center;color:#2e3a45;margin-bottom:1.5rem}
    .alert-success,.alert-danger{padding:.75rem 1rem;border-radius:8px;
      margin:1rem auto;max-width:400px;box-shadow:0 2px 6px rgba(0,0,0,0.08);
      text-align:center}
    .alert-success{background:#d4edda;color:#155724}
    .alert-danger{background:#f8d7da;color:#721c24}
    form{max-width:700px;margin:0 auto;padding:0;display:flex;
      flex-direction:column;gap:1.5rem;background:transparent}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
    .form-group{display:flex;flex-direction:column}
    label{margin-bottom:.25rem;font-weight:500;color:#2e3a45}
    label.required::after{content:" *";color:#dc3545}
    input[type="text"],input[type="date"],select{padding:.5rem .75rem;
      border:1px solid #ccc;border-radius:8px;font-size:1rem}
    .checkbox-group{display:flex;flex-wrap:wrap;gap:.5rem}
    #requiredList{max-width:700px;margin:0 auto 1.5rem;color:#2e3a45}
    h2{text-align:center;color:#2e3a45;margin-top:2rem}
    .allegati{list-style:none;padding:0;margin:0 auto;
      max-width:700px;display:flex;flex-direction:column;gap:.5rem}
    .allegati li{background:#fff;padding:.5rem;border-radius:8px;
      display:flex;align-items:center;justify-content:space-between}
    .allegati .file-name a{color:#2e3a45;text-decoration:none}
    .allegati .icons{display:flex;align-items:center}
    .allegati .icons i{cursor:pointer;font-size:1.2rem;margin-left:.5rem}
    .allegati .icons .delete{color:#dc3545}
    .allegati .icons .plus{color:#66bb6a}
    .actions{display:flex;justify-content:center;gap:2rem;margin-top:1rem}
    .btn{padding:.6rem 1.2rem;border:none;border-radius:8px;
      cursor:pointer;color:#fff;font-size:1rem;font-weight:bold;
      display:inline-flex;align-items:center;gap:.5rem;
      transition:background .2s,transform .15s}
    .btn-secondary{background:#6c757d}
    .btn-secondary:hover{background:#5a6268;transform:translateY(-2px)}
    .btn-primary{background:#66bb6a}
    .btn-primary:hover{background:#5aad5c;transform:translateY(-2px)}
    .btn-danger{background:#dc3545}
    .btn-danger:hover{background:#a71d2a;transform:translateY(-2px)}
  </style>
</head>
<body>
  <main>
    <h1>Modifica Docente</h1>

    <form id="docForm" method="post" enctype="multipart/form-data">
      <input type="hidden" name="MAX_FILE_SIZE" value="41943040">

      <div class="form-grid">
        <div class="form-group">
          <label for="nome" class="required">Nome</label>
          <input id="nome" name="nome" type="text" required
                 value="<?=htmlspecialchars($docente['nome'])?>">
        </div>
        <div class="form-group">
          <label for="cognome" class="required">Cognome</label>
          <input id="cognome" name="cognome" type="text" required
                 value="<?=htmlspecialchars($docente['cognome'])?>">
        </div>
        <div class="form-group">
          <label for="cf" class="required">Codice Fiscale</label>
          <input id="cf" name="cf" type="text" maxlength="16" required
                 value="<?=htmlspecialchars($docente['cf'])?>">
        </div>
        <div class="form-group">
          <label for="dataNascita">Data di nascita</label>
          <input id="dataNascita" name="dataNascita" type="date"
                 value="<?=htmlspecialchars($docente['dataNascita'])?>">
        </div>
        <div class="form-group">
          <label for="luogoNascita">Luogo di nascita</label>
          <input id="luogoNascita" name="luogoNascita" type="text"
                 value="<?=htmlspecialchars($docente['luogoNascita'])?>">
        </div>
        <div class="form-group">
          <label for="piva">Partita IVA</label>
          <input id="piva" name="piva" type="text" maxlength="11"
                 value="<?=htmlspecialchars($docente['piva'] ?? '')?>">
        </div>
        <div class="form-group">
          <label for="cittaResidenza">Città di residenza</label>
          <input id="cittaResidenza" name="cittaResidenza" type="text"
                 value="<?=htmlspecialchars($docente['cittaResidenza'])?>">
        </div>
        <div class="form-group">
          <label for="viaResidenza">Via di residenza</label>
          <input id="viaResidenza" name="viaResidenza" type="text"
                 value="<?=htmlspecialchars($docente['viaResidenza'])?>">
        </div>
        <div class="form-group">
          <label for="costoOrario" class="required">Costo orario (€)</label>
          <input id="costoOrario" name="costoOrario" type="text" required
                 value="<?=htmlspecialchars($docente['costoOrario'])?>">
        </div>
        <div class="form-group span-two">
          <label>Categorie</label>
          <div class="checkbox-group">
            <?php foreach($allowedCategories as $cat): ?>
              <label>
                <input type="checkbox" name="categorie[]" value="<?=$cat?>"
                  <?=in_array($cat,$currentCats,true)?'checked':''?>>
                <?=$cat?>
              </label>
            <?php endforeach;?>
          </div>
        </div>
      </div>

      <div id="requiredList"></div>

      <h2>Allegati</h2>
      <ul class="allegati" id="allegatiList">
        <?php foreach($allegati as $i=>$path):
          $name = basename($path);
        ?>
        <li data-index="<?=$i?>">
          <span class="file-name">
            <a href="./<?=$path?>" target="_blank"><?=$name?></a>
          </span>
          <span class="icons">
            <label for="rep<?=$i?>"><i class="bi bi-pencil-fill"></i></label>
            <input type="file"
                   name="replace[<?=$i?>]"
                   id="rep<?=$i?>"
                   style="display:none"
                   accept="application/pdf">
            <i class="bi bi-trash-fill delete"
               onclick="markDelete('<?=$path?>',<?=$i?>)"></i>
            <i class="bi bi-plus-lg plus" onclick="addField()"></i>
          </span>
        </li>
        <?php endforeach;?>
      </ul>

      <ul class="allegati" id="newList"></ul>

      <div class="actions">
        <a href="./docenti.php" class="btn btn-secondary">
          <i class="bi bi-arrow-left"></i> Indietro
        </a>
        <button type="submit" name="update" class="btn btn-primary">
          <i class="bi bi-save"></i> Salva Modifiche
        </button>
        <?php if(!$hasIncarichi): ?>
        <button type="submit" name="delete" class="btn btn-danger"
                onclick="return confirm('Eliminare docente?')">
          <i class="bi bi-trash-fill"></i> Elimina Docente
        </button>
        <?php endif;?>
      </div>
    </form>
  </main>

  <script>
    // CF → data/luogo
    const MONTH_MAP = {A:'01',B:'02',C:'03',D:'04',E:'05',H:'06',
      L:'07',M:'08',P:'09',R:'10',S:'11',T:'12'};
    const comuniMap = <?=json_encode($comuniMap,JSON_HEX_TAG)?>;
    document.getElementById('cf').addEventListener('input', e=>{
      const v = e.target.value.toUpperCase(),
            dob = document.getElementById('dataNascita'),
            loc = document.getElementById('luogoNascita');
      if(!/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/.test(v)){
        dob.value=''; loc.value=''; return;
      }
      const yy   = parseInt(v.substr(6,2),10),
            cur  = new Date().getFullYear()%100,
            year = yy<=cur?2000+yy:1900+yy,
            mon  = MONTH_MAP[v.charAt(8)],
            raw  = parseInt(v.substr(9,2),10),
            day  = String(raw>40?raw-40:raw).padStart(2,'0'),
            code = v.substr(11,4),
            info = comuniMap[code]||{};
      dob.value = `${year}-${mon}-${day}`;
      loc.value = info.den?`${info.den} (${info.prov})`:'';  
    });

    // richiesti dinamici
    const attachMap = <?=json_encode($attachMap,JSON_HEX_TAG)?>;
    function updateRequired(){
      const sel = Array.from(
        document.querySelectorAll('input[name="categorie[]"]:checked')
      ).map(cb=>cb.value),
      items = ['Curriculum Vitae'];
      sel.forEach(c=>attachMap[c]&&items.push(attachMap[c]));
      document.getElementById('requiredList').innerHTML = `
        <p><strong>Documenti richiesti:</strong></p>
        <ul style="margin:0;padding-left:1.25rem">
          ${items.map(i=>`<li>${i}</li>`).join('')}
        </ul>`;
    }
    document.querySelectorAll('input[name="categorie[]"]')
      .forEach(cb=>cb.addEventListener('change', updateRequired));
    updateRequired();

    // AJAX single‐file delete + confirmation
    function markDelete(path,i){
      if(!confirm('Confermi eliminazione di questo file?')) return;
      fetch(location.href,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'deleteSingle='+encodeURIComponent(path)
      })
      .then(r=>r.json())
      .then(res=>{
        if(res.ok){
          document.querySelector(`#allegatiList li[data-index="${i}"]`).remove();
          if(document.querySelectorAll('#allegatiList li').length===0){
            addField();
          }
        }
      });
    }

    // add new file slot
    let newIdx=0;
    function addField(){
      const ul=document.getElementById('newList'),
            li=document.createElement('li'),
            inp=document.createElement('input'),
            icons=document.createElement('span'),
            nameSpan=document.createElement('span');
      li.dataset.index=`n${newIdx}`;
      nameSpan.className='file-name';
      nameSpan.textContent='Seleziona il file';
inp.type = 'file';
inp.name = 'newfile[]';
inp.id = `new${newIdx}`;
inp.accept = 'application/pdf';
inp.multiple = true; // ✅ permette di selezionare più file
inp.style.display = 'none';
      inp.style.display='none';
      icons.className='icons';
      const dash=document.createElement('i');
      dash.className='bi bi-dash-lg';
      dash.onclick=()=>li.remove();
      const lbl=document.createElement('label');
      lbl.htmlFor=`new${newIdx}`;
      lbl.innerHTML='<i class="bi bi-file-earmark-arrow-up"></i>';
      const plus=document.createElement('i');
      plus.className='bi bi-plus-lg plus'; plus.onclick=()=>addField();
      icons.append(dash,lbl,plus);
      inp.addEventListener('change',()=>{
        if(inp.files.length){
          const f=inp.files[0], url=URL.createObjectURL(f);
          nameSpan.innerHTML=`<a href="${url}" target="_blank">${f.name}</a>`;
          dash.className='bi bi-trash-fill delete';
        }
      });
      li.append(nameSpan,icons,inp);
      ul.appendChild(li);
      newIdx++;
    }
    document.addEventListener('DOMContentLoaded',()=>{
      if(<?=count($allegati)?>===0) addField();
    });

    // client‐side CF validation + AJAX duplicate check su UPDATE, bypass su DELETE
    const form = document.getElementById('docForm');
    form.addEventListener('submit', function handler(e){
      // se il submit viene dal bottone delete, lascio proseguire
      if (e.submitter && e.submitter.name === 'delete') {
        form.removeEventListener('submit', handler);
        return;
      }
      // altrimenti intercetto per validare CF
      e.preventDefault();
      const cfVal = document.getElementById('cf').value.trim().toUpperCase();
      const re = /^[A-Z]{6}\d{2}[A-EHLMPRST]\d{2}[A-Z]\d{3}[A-Z]$/;
      if (!re.test(cfVal)) {
        alert('Codice Fiscale non valido');
        return;
      }
      fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax=check_cf&cf='+encodeURIComponent(cfVal)
      })
      .then(r=>r.json())
      .then(json=>{
        if (!json.valid) {
          alert('Codice Fiscale non valido');
        } else if (json.exists) {
          alert('Codice Fiscale già presente');
        } else {
          form.removeEventListener('submit', handler);
          form.querySelector('button[name="update"]').click();
        }
      });
    });
  </script>
</body>
</html>
