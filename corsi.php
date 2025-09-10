  <?php
// corsi.php — elenco + add/edit/delete in un unico file
// "Programma" è un TEXT (textbox) + bottone "Scarica programma" che crea PDF da template_programma.html
include 'init.php';
if (session_status()===PHP_SESSION_NONE) session_start();

/* =================== CSRF =================== */
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function csrf_ok(){return hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'');}

/* ============== Helpers / Mapping ============== */
function sanitize_id($s){ $s=strtoupper(trim($s)); return preg_replace('/[^A-Z0-9]/','',$s); }
function flags_to_modalita($aula, $fad){
  if ($aula && $fad) return '2';
  if ($aula)         return '1';
  return '0';
}
function modalita_to_flags($m){
  $m=(string)$m;
  return ['aula'=>($m==='1'||$m==='2'), 'fad'=>($m==='0'||$m==='2')];
}
//
/* =================== DOWNLOAD PROGRAMMA (PDF) ===================
   Usa template_programma.html + dompdf se disponibile.
   Accetta POST con action=download_programma + dati correnti del form.
================================================================= */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='download_programma') {
  if (!csrf_ok()) { http_response_code(403); echo 'CSRF non valido'; exit; }

  $titolo    = trim($_POST['titolo']??'');
  $nomeabbr  = trim($_POST['nomeabbreviato']??'programma');
  $categoria = trim($_POST['categoria']??'');
  $tipologia = trim($_POST['tipologia']??'');
  $durata    = trim($_POST['durata']??'');
  $normativa = trim($_POST['normativa']??'');
  $testoProg = trim($_POST['programma_text']??'');

  $tplPath = __DIR__ . '/template_programma.html';
  if (!is_file($tplPath)) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Template non trovato: template_programma.html\nCrea il file nella root del progetto.";
    exit;
  }
  $html = file_get_contents($tplPath);

  // Sostituzioni semplici (se presenti nel template)
  $rep = [
    '[[TITOLO]]'          => htmlspecialchars($titolo, ENT_QUOTES, 'UTF-8'),
    '[[NOME_ABBR]]'       => htmlspecialchars($nomeabbr, ENT_QUOTES, 'UTF-8'),
    '[[CATEGORIA]]'       => htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8'),
    '[[TIPOLOGIA]]'       => htmlspecialchars($tipologia, ENT_QUOTES, 'UTF-8'),
    '[[DURATA]]'          => htmlspecialchars($durata, ENT_QUOTES, 'UTF-8'),
    '[[NORMATIVA]]'       => nl2br(htmlspecialchars($normativa, ENT_QUOTES, 'UTF-8')),
    '[[PROGRAMMA_TESTO]]' => nl2br(htmlspecialchars($testoProg, ENT_QUOTES, 'UTF-8')),
    '[[OGGI]]'            => date('d/m/Y'),
  ];
  $html = strtr($html, $rep);

  // dompdf
  @require_once __DIR__ . '/libs/vendor/autoload.php';
  if (!class_exists('\Dompdf\Dompdf')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Libreria PDF non disponibile.\nInstalla dompdf: composer require dompdf/dompdf";
    exit;
  }
  $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true,'isHtml5ParserEnabled'=>true]);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  $filenameSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nomeabbr ?: 'programma');
  $outName = "programma_{$filenameSafe}.pdf";
  $dompdf->stream($outName, ['Attachment'=>true]);
  exit;
}

/* =================== AJAX ENDPOINTS (get/save/delete) =================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax'])) {
  header('Content-Type: application/json');

  if ($_POST['ajax']==='get_course') {
    $id = sanitize_id($_POST['id']??'');
    $st = $pdo->prepare("SELECT id,titolo,nomeabbreviato,durata,modalita,categoria,tipologia,validita,maxpartecipanti,programma,normativa FROM corso WHERE id=?");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($c) {
      $flags = modalita_to_flags($c['modalita']);
      $c['aula'] = $flags['aula']?1:0;
      $c['fad']  = $flags['fad']?1:0;
    }
    echo json_encode(['ok'=>true,'data'=>$c]); exit;
  }

  if ($_POST['ajax']==='save_course') {
    if(!csrf_ok()){ echo json_encode(['ok'=>false,'msg'=>'CSRF non valido']); exit; }

    $orig_id = sanitize_id($_POST['orig_id']??''); // vuoto = nuovo
    $id      = sanitize_id($_POST['id']??'');
    $titolo  = trim($_POST['titolo']??'');
    $nomeabbr= trim($_POST['nomeabbreviato']??'');
    $durata  = trim($_POST['durata']??'');
    $validita= ($_POST['validita']==='' ? null : (int)$_POST['validita']);
    $maxp    = (int)($_POST['maxpartecipanti']??0);
    $categoria = trim($_POST['categoria']??'');
    $tipologia = trim($_POST['tipologia']??'');
    $normativa = trim($_POST['normativa']??'');
    $programma_text = trim($_POST['programma_text']??''); // testo libero del programma
    $aula = isset($_POST['aula']);
    $fad  = isset($_POST['fad']);
    $modalita = flags_to_modalita($aula,$fad);

    if ($id==='' || $titolo==='' || $nomeabbr==='') {
      echo json_encode(['ok'=>false,'msg'=>'Compila ID, Titolo e Nome abbreviato.']); exit;
    }

    // duplicato se cambio ID
    if ($orig_id==='' || $id !== $orig_id) {
      $stmtDup = $pdo->prepare('SELECT COUNT(*) FROM corso WHERE UPPER(id)=?');
      $stmtDup->execute([$id]);
      if ((int)$stmtDup->fetchColumn() > 0) {
        echo json_encode(['ok'=>false,'msg'=>'ID già esistente. Scegli un altro ID.']); exit;
      }
    }

    try{
      if ($orig_id==='') {
        $st = $pdo->prepare(<<<'SQL'
          INSERT INTO corso
            (id,titolo,nomeabbreviato,durata,modalita,categoria,tipologia,normativa,programma,maxpartecipanti,validita)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)
SQL);
        $st->execute([$id,$titolo,$nomeabbr,$durata,$modalita,$categoria,$tipologia,$normativa,$programma_text,$maxp,$validita]);
      } else {
        $st = $pdo->prepare(<<<'SQL'
          UPDATE corso
             SET id=?, titolo=?, nomeabbreviato=?, durata=?, modalita=?, categoria=?, tipologia=?, normativa=?, programma=?, maxpartecipanti=?, validita=?
           WHERE id=?
SQL);
        $st->execute([$id,$titolo,$nomeabbr,$durata,$modalita,$categoria,$tipologia,$normativa,$programma_text,$maxp,$validita,$orig_id]);
      }
      echo json_encode(['ok'=>true]);
    } catch(Throwable $e){
      echo json_encode(['ok'=>false,'msg'=>'Errore salvataggio: '.$e->getMessage()]);
    }
    exit;
  }

  if ($_POST['ajax']==='delete_course') {
    if(!csrf_ok()){ echo json_encode(['ok'=>false,'msg'=>'CSRF non valido']); exit; }
    $id = sanitize_id($_POST['id']??'');
    try{
      $st = $pdo->prepare('DELETE FROM corso WHERE id=?');
      $st->execute([$id]);
      echo json_encode(['ok'=>true]);
    }catch(Throwable $e){
      echo json_encode(['ok'=>false,'msg'=>'Impossibile eliminare: '.$e->getMessage()]);
    }
    exit;
  }

  echo json_encode(['ok'=>false,'msg'=>'Azione non valida']); exit;
}

/* =================== QUERY ELENCO =================== */
$corsi = $pdo->query('SELECT id,titolo,nomeabbreviato FROM corso ORDER BY titolo')->fetchAll(PDO::FETCH_ASSOC);

/* =================== NAVBAR =================== */
$role = $_SESSION['role'] ?? 'utente';
if ($role==='admin')    include 'navbar_a.php';
elseif ($role==='dev')  include 'navbar_d.php';
else                    include 'navbar.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Corsi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --green:#66bb6a; --green-d:#5aad5c;
      --bg:#f0f2f5; --fg:#2e3a45; --muted:#6b7280;
      --radius:12px; --shadow:0 10px 30px rgba(0,0,0,.08);
      --err:#d9534f;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--fg);font:16px system-ui,-apple-system,Segoe UI,Roboto}
    .container{max-width:960px;margin:28px auto;padding:0 14px}
    h1{text-align:center;margin:.2rem 0}
    .toolbar{display:flex;align-items:center;justify-content:center;gap:.6rem;margin:.8rem 0;flex-wrap:wrap}
    #search{width:320px}

    input,select,textarea{
      height:40px; padding:.55rem .8rem; border:1px solid #d7dde3; background:#fff;
      border-radius:14px; font-size:1rem; outline:none; transition: box-shadow .15s, border-color .15s;
    }
    textarea{height:auto; resize:vertical; min-height:120px; line-height:1.45; padding:.7rem .9rem}
    input:focus,select:focus,textarea:focus{ box-shadow:0 0 0 3px rgba(102,187,106,.15); border-color:#b9e2bd }

    .btn{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem 1rem;border:0;border-radius:999px;color:#fff;font-weight:700;cursor:pointer;white-space:nowrap}
    .btn-green{background:var(--green)} .btn-green:hover{background:var(--green-d)}
    .btn-grey{background:#6c757d} .btn-grey:hover{opacity:.92}
    .btn-red{background:#dc3545} .btn-red:hover{filter:brightness(.95)}
    .btn-outline{background:#fff;border:1px solid #d7dde3;color:#2e3a45}

    .list{display:flex;flex-direction:column;gap:.55rem}
    .item{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:.65rem .9rem;display:flex;align-items:center;justify-content:space-between;min-height:48px}
    .left{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
    .title{font-weight:800}
    .badge{background:#eef2f7;border-radius:999px;padding:.12rem .6rem;color:#667}
    .icon-btn{background:none;border:0;color:#4caf50;font-size:1.1rem;cursor:pointer}
    .icon-btn:hover{opacity:.85}
    .empty{color:#7a8691;text-align:center;padding:1.2rem}

    /* Toast */
    .toast{position:fixed;left:16px;bottom:16px;background:#66bb6a;color:#fff;padding:.6rem .85rem;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.2);display:none;align-items:center;gap:.5rem;z-index:3000}

    /* Modal */
    .overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:2000}
    .overlay.open{display:flex}
    .modal{background:#fff;width:min(920px,96vw);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);padding:16px}
    .modal .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem}
    .modal .close{background:none;border:0;font-size:1.4rem;color:#888;cursor:pointer}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}
    .form-row{display:flex;flex-direction:column;gap:.25rem}
    .actions{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.6rem;flex-wrap:wrap}

    /* Piccolo ? tooltip via title */
    .help{display:inline-flex;align-items:center;justify-content:center;width:1.25rem;height:1.25rem;border-radius:999px;background:#eef2f7;color:#556;cursor:help;font-size:.9rem}
    .label-row{display:flex;align-items:center;gap:.4rem}

    /* Programma toolbar */
    .prog-bar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap; margin-top:12px;} /* più spazio sopra */
  </style>
</head>
<body>
<div class="container">
  <h1>Corsi</h1>

  <div class="toolbar">
    <input id="search" type="text" placeholder="Cerca titolo o ID…">
    <button class="btn btn-green" id="btn-add"><i class="bi bi-plus-lg"></i> Aggiungi Corso</button>
  </div>

  <div id="list" class="list">
    <?php if (empty($corsi)): ?>
      <div class="empty">Nessun corso registrato.</div>
    <?php else: foreach($corsi as $c): ?>
      <div class="item" data-id="<?=htmlspecialchars($c['id'],ENT_QUOTES)?>" data-text="<?=strtolower($c['titolo'].' '.$c['id'])?>">
        <div class="left">
          <span class="title"><?=htmlspecialchars($c['titolo'],ENT_QUOTES)?></span>
          <span class="badge">ID: <?=htmlspecialchars($c['id'],ENT_QUOTES)?></span>
          <?php if(!empty($c['nomeabbreviato'])): ?>
            <span class="badge">abbr: <?=htmlspecialchars($c['nomeabbreviato'],ENT_QUOTES)?></span>
          <?php endif; ?>
        </div>
        <button class="icon-btn edit" title="Modifica"><i class="bi bi-pencil"></i></button>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Toast -->
<div id="toast" class="toast"><i class="bi bi-check-circle"></i><span>Operazione completata</span></div>

<!-- Modal add/edit -->
<div class="overlay" id="modal">
  <div class="modal">
    <div class="head">
      <h3 id="mod-title">Aggiungi corso</h3>
      <button class="close" data-close>&times;</button>
    </div>

    <form id="course-form">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>">
      <input type="hidden" name="orig_id" id="f-orig">

      <div class="form-grid">
        <div class="form-row">
          <div class="label-row">
            <label for="f-id">ID *</label>
          </div>
          <input id="f-id" name="id" maxlength="2" required oninput="this.value=this.value.toUpperCase()">
        </div>
        <div class="form-row">
          <div class="label-row">
            <label for="f-titolo">Titolo *</label>
          </div>
          <input id="f-titolo" name="titolo" required>
        </div>
      </div>

      <div class="form-row">
        <div class="label-row">
          <label for="f-nomeabbr">Nome abbreviato per attestato *</label>
          <span class="help" title="Verrà usato per nominare i PDF dell’attestato. Esempio: se scrivi “formgen”, il file sarà formgen_Rossi_Mario.pdf">?</span>
        </div>
        <input id="f-nomeabbr" name="nomeabbreviato" required>
      </div>

      <div class="form-grid">
        <div class="form-row">
          <label for="f-durata">Durata (ore) *</label>
          <input id="f-durata" name="durata" required>
        </div>
        <div class="form-row">
          <label>Modalità</label>
          <div style="display:flex;gap:1rem;align-items:center;">
            <label><input type="checkbox" name="aula" id="f-aula"> Aula</label>
            <label><input type="checkbox" name="fad"  id="f-fad"> FAD</label>
          </div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-row">
          <label for="f-validita">Validità (anni)</label>
          <input id="f-validita" name="validita" type="number" min="0" step="1" placeholder="Lascia vuoto per senza scadenza">
        </div>
        <div class="form-row">
          <label for="f-maxp">Max partecipanti *</label>
          <input id="f-maxp" name="maxpartecipanti" type="number" min="0" required>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-row">
          <label for="f-categoria">Categoria *</label>
          <select id="f-categoria" name="categoria" required>
            <option value="" disabled selected>Seleziona</option>
            <?php foreach (['HACCP','Sicurezza','Antincendio','Primo Soccorso','Macchine Operatrici'] as $cat): ?>
              <option value="<?=$cat?>"><?=$cat?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label for="f-tipologia">Tipologia *</label>
          <select id="f-tipologia" name="tipologia" required>
            <option value="" disabled selected>Seleziona</option>
            <?php foreach (['Primo Rilascio','Aggiornamento'] as $tip): ?>
              <option value="<?=$tip?>"><?=$tip?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <label for="f-normativa">Normativa</label>
        <textarea id="f-normativa" name="normativa" rows="4" placeholder="Normativa di riferimento…"></textarea>
      </div>

      <!-- Programma: TEXTAREA + Scarica PDF da template -->
      <div class="form-row">
        <div class="prog-bar" style="justify-content:space-between">
          <label for="f-programma-text" style="font-weight:600">Programma (testo libero)</label>
          <button type="button" id="btn-prog-pdf" class="btn btn-green"><i class="bi bi-download"></i> Scarica programma</button>
        </div>
        <textarea id="f-programma-text" name="programma_text" rows="8" placeholder="Scrivi qui il programma del corso…"></textarea>
      </div>

      <div class="actions">
        <button type="button" class="btn btn-grey" data-close>Annulla</button>
        <button type="button" class="btn btn-red" id="btn-del" style="display:none"><i class="bi bi-trash"></i> Elimina</button>
        <button class="btn btn-green" type="submit"><i class="bi bi-save"></i> Salva</button>
      </div>
    </form>

    <!-- Hidden form per download PDF in nuova scheda -->
    <form id="download-form" method="post" target="_blank" style="display:none">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>">
      <input type="hidden" name="action" value="download_programma">
      <input type="hidden" name="titolo">
      <input type="hidden" name="nomeabbreviato">
      <input type="hidden" name="categoria">
      <input type="hidden" name="tipologia">
      <input type="hidden" name="durata">
      <input type="hidden" name="normativa">
      <input type="hidden" name="programma_text">
    </form>
  </div>
</div>

<script>

</script>
</body>
</html>
