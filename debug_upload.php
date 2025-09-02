<?php
// debug_upload.php — Diagnostica completa upload PDF per scheda corso
// Salva questo file nella root del progetto (stesso livello di /resources)

// === CONFIG DI DEFAULT ===
$DEFAULT_ACTIVITY_ID = 'DEBUG-ATT-001';
$TARGETS = [
  __DIR__ . '/resources/scheda',  // singolare
  __DIR__ . '/resources/schede',  // plurale (nel caso il progetto usi questo)
];

function bytes_to_human($val){
  $val = trim((string)$val);
  if ($val === '') return '';
  // Gestisce valori php.ini tipo "20M", "2G", "512K"
  $last = strtolower(substr($val, -1));
  $num  = (float)$val;
  switch ($last) {
    case 'g': $num *= 1024;
    case 'm': $num *= 1024;
    case 'k': $num *= 1024;
  }
  if ($num < 1024) return $num . ' B';
  if ($num < 1048576) return round($num / 1024, 1) . ' KB';
  if ($num < 1073741824) return round($num / 1048576, 1) . ' MB';
  return round($num / 1073741824, 2) . ' GB';
}
function ini_bytes($key){
  $val = ini_get($key);
  $val = trim((string)$val);
  $last = strtolower(substr($val, -1));
  $num  = (float)$val;
  switch ($last) {
    case 'g': $num *= 1024;
    case 'm': $num *= 1024;
    case 'k': $num *= 1024;
  }
  return (int)$num;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$logs = [];
function logx($m){ global $logs; $logs[] = '['.date('H:i:s')."] $m"; }

// === INFO AMBIENTE ===
$info = [
  'PHP_VERSION'         => PHP_VERSION,
  'SAPI'                => php_sapi_name(),
  'DOCUMENT_ROOT'       => $_SERVER['DOCUMENT_ROOT'] ?? '',
  '__DIR__'             => __DIR__,
  'USER_ID'             => function_exists('posix_geteuid') ? posix_geteuid() : 'n/a',
  'USER_NAME'           => function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'n/a') : 'n/a',
  'upload_max_filesize' => ini_get('upload_max_filesize'),
  'post_max_size'       => ini_get('post_max_size'),
  'max_file_uploads'    => ini_get('max_file_uploads'),
  'file_uploads'        => ini_get('file_uploads'),
  'upload_tmp_dir'      => ini_get('upload_tmp_dir'),
];

// === CHECK PERCORSI ===
$pathsCheck = [];
foreach ($TARGETS as $p) {
  $pathsCheck[] = [
    'path'   => $p,
    'exists' => is_dir($p),
    'read'   => is_readable($p),
    'write'  => is_writable($p),
  ];
}

// === HANDLE POST ===
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $attId = trim($_POST['attivita_id'] ?? $DEFAULT_ACTIVITY_ID);
  $chosenBase = $_POST['target_dir'] ?? $TARGETS[0];
  $chosenBase = rtrim($chosenBase, '/');

  // Check file presente?
  if (!isset($_FILES['scheda_pdf'])) {
    $result = ['ok'=>false,'msg'=>'Campo file mancante (scheda_pdf)'];
  } else {
    $f = $_FILES['scheda_pdf'];
    $err = (int)($f['error'] ?? 4);
    $size= (int)($f['size'] ?? 0);
    $name= (string)($f['name'] ?? '');
    $tmp = (string)($f['tmp_name'] ?? '');

    logx("Upload ricevuto: error=$err size=$size name=$name tmp=$tmp");

    if ($err !== UPLOAD_ERR_OK) {
      $codes = [
        1=>'UPLOAD_ERR_INI_SIZE',
        2=>'UPLOAD_ERR_FORM_SIZE',
        3=>'UPLOAD_ERR_PARTIAL',
        4=>'UPLOAD_ERR_NO_FILE',
        6=>'UPLOAD_ERR_NO_TMP_DIR',
        7=>'UPLOAD_ERR_CANT_WRITE',
        8=>'UPLOAD_ERR_EXTENSION',
      ];
      $result = ['ok'=>false,'msg'=>"Errore di upload ($err: ".($codes[$err]??'').")"];
    } elseif ($size <= 0) {
      $result = ['ok'=>false,'msg'=>'File vuoto'];
    } else {
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if ($ext !== 'pdf') {
        $result = ['ok'=>false,'msg'=>'Estensione non valida: carica un PDF'];
      } elseif (!is_uploaded_file($tmp)) {
        logx("is_uploaded_file=false per tmp=$tmp");
        $result = ['ok'=>false,'msg'=>'is_uploaded_file() ha fallito (tmp non valido)'];
      } else {
        // crea cartella destinazione
        $destDir = $chosenBase . '/' . $attId;
        if (!is_dir($destDir)) {
          logx("Provo mkdir $destDir");
          if (!@mkdir($destDir, 0775, true)) {
            $result = ['ok'=>false,'msg'=>"mkdir fallita: $destDir"];
          }
        }
        if (!$result) {
          $safeBase = preg_replace('/[^a-zA-Z0-9._-]/','_', pathinfo($name, PATHINFO_FILENAME));
          $stored   = $safeBase.'-'.bin2hex(random_bytes(4)).'.pdf';
          $dest     = $destDir . '/' . $stored;

          logx("move_uploaded_file($tmp -> $dest)");
          if (!@move_uploaded_file($tmp, $dest)) {
            $result = ['ok'=>false,'msg'=>"move_uploaded_file fallito (permessi? path?)"];
          } else {
            @chmod($dest, 0664);
            $urlRel = str_replace(__DIR__, '', $dest);
            $urlRel = '/'.ltrim($urlRel,'/'); // prova a renderlo relativo al sito
            $result = ['ok'=>true,'msg'=>'File salvato correttamente','path'=>$dest,'url'=>$urlRel];
            logx("Salvato: $dest");
          }
        }
      }
    }
  }
}

?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Debug Upload — scheda PDF</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root{--bg:#f6f7fb;--fg:#2e3a45;--muted:#6c757d;--ok:#198754;--err:#d9534f;--pri:#66bb6a;--radius:12px;--card:#fff;--shadow:0 10px 30px rgba(0,0,0,.08)}
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--fg);font:14px system-ui,-apple-system,Segoe UI,Roboto}
  .wrap{max-width:900px;margin:32px auto;padding:0 16px}
  .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;margin-bottom:16px}
  h1{margin:.2rem 0 1rem} .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .row{display:grid;grid-template-columns:240px 1fr;gap:8px;margin:.25rem 0}
  .label{color:var(--muted)} code{background:#eef2f7;padding:2px 6px;border-radius:6px}
  .ok{color:var(--ok)} .err{color:var(--err)}
  .dz{position:relative;border:2px dashed #cfd8dc;background:#fff;border-radius:10px;padding:1rem;text-align:center;transition:all .15s;box-shadow:0 2px 6px rgba(0,0,0,.06);min-height:110px;cursor:pointer;margin-top:.5rem}
  .dz:hover{border-color:var(--pri)} .dz.dragover{border-color:var(--pri);background:#eef8f0}
  .dz input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
  .dz .dz-inner{pointer-events:none}
  .dz .dz-inner i{font-size:1.8rem;color:var(--pri);margin-bottom:.25rem;display:block}
  .btn{display:inline-flex;align-items:center;gap:.5rem;border:0;padding:.6rem 1rem;border-radius:10px;color:#fff;cursor:pointer;font-weight:600}
  .btn-pri{background:#198754} .btn-sec{background:#6c757d}
  .muted{color:var(--muted)}
  pre{background:#0b1020;color:#e5e7eb;padding:12px;border-radius:10px;overflow:auto;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Debug Upload — scheda PDF</h1>
    <div class="grid">
      <div>
        <div class="row"><div class="label">PHP</div><div><?=h($info['PHP_VERSION'])?> (<?=h($info['SAPI'])?>)</div></div>
        <div class="row"><div class="label">User web</div><div><?=h($info['USER_NAME'])?> (uid <?=h($info['USER_ID'])?>)</div></div>
        <div class="row"><div class="label">Document root</div><div><code><?=h($info['DOCUMENT_ROOT'])?></code></div></div>
        <div class="row"><div class="label">Script dir</div><div><code><?=h($info['__DIR__'])?></code></div></div>
      </div>
      <div>
        <div class="row"><div class="label">upload_max_filesize</div><div><?=h($info['upload_max_filesize'])?> (<?=bytes_to_human(ini_bytes('upload_max_filesize'))?>)</div></div>
        <div class="row"><div class="label">post_max_size</div><div><?=h($info['post_max_size'])?> (<?=bytes_to_human(ini_bytes('post_max_size'))?>)</div></div>
        <div class="row"><div class="label">max_file_uploads</div><div><?=h($info['max_file_uploads'])?></div></div>
        <div class="row"><div class="label">file_uploads</div><div><?=h($info['file_uploads'])?></div></div>
        <div class="row"><div class="label">upload_tmp_dir</div><div><code><?=h($info['upload_tmp_dir'] ?: '(default)')?></code></div></div>
      </div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Controllo cartelle destinazione</h3>
    <?php foreach ($pathsCheck as $pc): ?>
      <div class="row">
        <div class="label"><?=h($pc['path'])?></div>
        <div>
          <?php if(!$pc['exists']): ?>
            <span class="err">Non esiste</span>
          <?php else: ?>
            <span class="ok">Esiste</span> —
            <?= $pc['read']  ? '<span class="ok">leggibile</span>'  : '<span class="err">non leggibile</span>' ?>
            ,
            <?= $pc['write'] ? '<span class="ok">scrivibile</span>' : '<span class="err">non scrivibile</span>' ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="muted">Suggerimento permessi (Ubuntu/Apache): <code>sudo chown -R www-data:www-data /var/www/formazione/biosound/resources/scheda && sudo chmod -R 775 /var/www/formazione/biosound/resources/scheda</code></p>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Test upload</h3>
    <form method="post" enctype="multipart/form-data">
      <div class="row">
        <div class="label">Attività ID</div>
        <div><input name="attivita_id" value="<?=h($DEFAULT_ACTIVITY_ID)?>" style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:8px"></div>
      </div>
      <div class="row">
        <div class="label">Cartella base</div>
        <div>
          <select name="target_dir" style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:8px">
            <?php foreach($TARGETS as $t): ?>
              <option value="<?=h($t)?>"><?=h($t)?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="dz" id="dz">
        <input type="file" name="scheda_pdf" id="scheda_pdf" accept=".pdf">
        <div class="dz-inner">
          <i class="bi bi-cloud-arrow-up"></i>
          <div>Trascina qui il PDF o clicca</div>
          <small class="muted">Max <?=h($info['upload_max_filesize'])?> (attenzione anche a <code>post_max_size</code>)</small>
        </div>
      </div>

      <div style="margin-top:12px;display:flex;gap:.5rem;justify-content:flex-end">
        <a class="btn btn-sec" href="?"><i class="bi bi-arrow-clockwise"></i> Reset</a>
        <button class="btn btn-pri" type="submit"><i class="bi bi-upload"></i> Carica</button>
      </div>
    </form>
  </div>

  <?php if ($result !== null): ?>
  <div class="card">
    <h3 style="margin-top:0">Risultato</h3>
    <?php if ($result['ok']): ?>
      <p class="ok"><strong>OK:</strong> <?=h($result['msg'])?></p>
      <?php if (!empty($result['path'])): ?>
        <p>Path: <code><?=h($result['path'])?></code></p>
        <p>URL (relativo): <code><?=h($result['url'])?></code></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="err"><strong>ERRORE:</strong> <?=h($result['msg'])?></p>
    <?php endif; ?>
    <h4>Log</h4>
    <pre><?php foreach($logs as $l) echo h($l)."\n"; ?></pre>
    <details>
      <summary>$_FILES</summary>
      <pre><?php echo h(print_r($_FILES, true)); ?></pre>
    </details>
    <details>
      <summary>$_POST</summary>
      <pre><?php echo h(print_r($_POST, true)); ?></pre>
    </details>
  </div>
  <?php endif; ?>
</div>

<script>
  const dz = document.getElementById('dz');
  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.add('dragover');}));
  ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('dragover');}));
</script>
</body>
</html>
