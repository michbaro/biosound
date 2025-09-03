<?php
// importa_dipendenti.php — Import massivo dipendenti da XLSX con anteprima e conferma
// Obbligatori (riga 1): Nome | Cognome | Codice Fiscale | Azienda | Sede
// Opzionali (riga 1):  Comune Residenza | Via Residenza | Mansione
// Requisiti: phpoffice/phpspreadsheet installato e autoloadabile

require_once __DIR__ . '/init.php';

/* ---------- Utils ---------- */
function safe_redirect(string $url) {
  if (!headers_sent()) header('Location: '.$url);
  else {
    echo '<script>location.replace('.json_encode($url).');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url='.htmlspecialchars($url,ENT_QUOTES).'"></noscript>';
  }
  exit;
}
function cf_is_valid(string $cf): bool {
  $cf = strtoupper($cf);
  if (!preg_match('/^[A-Z]{6}\d{2}[A-EHLMPRST]\d{2}[A-Z]\d{3}[A-Z]$/', $cf)) return false;
  $odd = ['0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,
          'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,
          'K'=>2,'L'=>4,'M'=>18,'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,
          'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23];
  $even = ['0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,
           'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,'I'=>8,'J'=>9,
           'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,'Q'=>16,'R'=>17,'S'=>18,'T'=>19,
           'U'=>20,'V'=>21,'W'=>22,'X'=>23,'Y'=>24,'Z'=>25];
  $sum=0; for($i=0;$i<15;$i++){ $c=$cf[$i]; $sum += ($i%2===0)?$odd[$c]:$even[$c]; }
  return $cf[15] === chr(65 + ($sum % 26));
}
function cf_parse_birth(string $cf): array {
  $cf = strtoupper($cf);
  $yy = intval(substr($cf,6,2));
  $mmMap=['A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'H'=>6,'L'=>7,'M'=>8,'P'=>9,'R'=>10,'S'=>11,'T'=>12];
  $mm = $mmMap[$cf[8]] ?? null;
  $gg = intval(substr($cf,9,2)); if ($gg>40) $gg-=40;
  $curYY=intval(date('y')); $yyyy=($yy<=$curYY)?2000+$yy:1900+$yy;
  $birth = ($mm && $gg>=1 && $gg<=31) ? sprintf('%04d-%02d-%02d', $yyyy,$mm,$gg) : null;

  $belfiore = substr($cf,11,4);
  $loc=''; try {
    global $pdo;
    $st=$pdo->prepare("SELECT denominazione_ita,sigla_provincia FROM gi_comuni_nazioni_cf WHERE codice_belfiore=? LIMIT 1");
    $st->execute([$belfiore]);
    if ($row=$st->fetch(PDO::FETCH_ASSOC)) $loc=trim($row['denominazione_ita'].' ('.$row['sigla_provincia'].')');
  } catch(Throwable $e){}
  return [$birth,$loc];
}
function to_title(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  // gestione rapida apostrofi/composti: "d'angelo" -> "D'Angelo", "mario-rossi" -> "Mario-Rossi"
  $s = preg_replace_callback('/\b[\p{L}\']+\b/u', function($m){
    return mb_strtoupper(mb_substr($m[0],0,1,'UTF-8'),'UTF-8') . mb_substr($m[0],1,null,'UTF-8');
  }, $s);
  return $s;
}

/* ---------- Libreria ---------- */
$LIB_OK = false;
try {
  require_once __DIR__ . '/libs/vendor/autoload.php';
  $LIB_OK = class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class);
} catch (Throwable $e) { $LIB_OK = false; }

/* ---------- Lookup aziende/sedi (anche mappe case-insensitive) ---------- */
$aziendeByExact = [];           // "Ragione Sociale" => id
$aziendeByLower = [];           // strtolower("Ragione Sociale") => id
$sediByAziendaExact = [];       // [azienda_id]["Nome sede"] => sede_id
$sediByAziendaLower = [];       // [azienda_id][strtolower("Nome sede")] => sede_id
try {
  $rowsA = $pdo->query("SELECT id, ragionesociale FROM azienda")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rowsA as $a) {
    $aziendeByExact[$a['ragionesociale']] = $a['id'];
    $aziendeByLower[mb_strtolower($a['ragionesociale'],'UTF-8')] = $a['id'];
  }
  $rowsS = $pdo->query("SELECT id, nome, azienda_id FROM sede")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rowsS as $s) {
    $sid = $s['id']; $aid = $s['azienda_id']; $name = $s['nome'];
    $sediByAziendaExact[$aid][$name] = $sid;
    $sediByAziendaLower[$aid][mb_strtolower($name,'UTF-8')] = $sid;
  }
} catch (Throwable $e) {}

/* ---------- POST: conferma inserimento ---------- */
if (($_POST['stage'] ?? '') === 'confirm') {
  $payload = $_POST['data'] ?? '';
  try {
    $items = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($items) || empty($items)) throw new RuntimeException('Nessun dato da inserire.');

    $pdo->beginTransaction();
    $insDip = $pdo->prepare("
      INSERT INTO dipendente
        (id,nome,cognome,codice_fiscale,datanascita,luogonascita,comuneresidenza,viaresidenza,mansione)
      VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $insLink = $pdo->prepare("INSERT INTO dipendente_sede (dipendente_id, sede_id) VALUES (?,?)");

    foreach ($items as $r) {
      // ricontrollo duplicati CF per azienda
      $chk = $pdo->prepare("
        SELECT COUNT(*) FROM dipendente d
        JOIN dipendente_sede ds ON d.id=ds.dipendente_id
        JOIN sede s ON s.id=ds.sede_id
        WHERE d.codice_fiscale=? AND s.azienda_id=?
      ");
      $chk->execute([$r['codice_fiscale'], $r['azienda_id']]);
      if ($chk->fetchColumn() > 0) throw new RuntimeException('Duplicato CF per azienda: '.$r['codice_fiscale']);

      $id = bin2hex(random_bytes(16));
      $insDip->execute([
        $id,
        to_title($r['nome']),
        to_title($r['cognome']),
        $r['codice_fiscale'],
        $r['datanascita'] ?: null,
        $r['luogonascita'],
        $r['comuneresidenza'] ?? '',
        $r['viaresidenza'] ?? '',
        $r['mansione'] ?? ''
      ]);
      $insLink->execute([$id, $r['sede_id']]);
    }

    $pdo->commit();
    safe_redirect('/biosound/dipendenti.php?bulk=ok');

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = (!$LIB_OK || $e instanceof Error) ? 'Errore tecnico: contatta l’amministrazione' : $e->getMessage();
    $ERROR_MSG = $msg; $STAGE = 'error';
  }
}

/* ---------- POST: parse xlsx ---------- */
$preview = [];
$errors  = [];
if (($_POST['stage'] ?? '') === 'parse') {
  try {
    if (!$LIB_OK) throw new Exception('LIB_MISSING');

    if (!isset($_FILES['xlsx']) || $_FILES['xlsx']['error'] === UPLOAD_ERR_NO_FILE) {
      throw new RuntimeException('Seleziona un file XLSX.');
    }
    if ($_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Caricamento non riuscito (codice '.$_FILES['xlsx']['error'].').');
    }
    $ext = strtolower(pathinfo($_FILES['xlsx']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') throw new RuntimeException('Il file deve essere in formato .xlsx');

    $tmp = $_FILES['xlsx']['tmp_name'];
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $sheet = $reader->load($tmp)->getActiveSheet();

    // Intestazioni obbligatorie precise (riga 1)
    $expected = ['Nome','Cognome','Codice Fiscale','Azienda','Sede'];
    $hA = trim((string)$sheet->getCell('A1')->getValue());
    $hB = trim((string)$sheet->getCell('B1')->getValue());
    $hC = trim((string)$sheet->getCell('C1')->getValue());
    $hD = trim((string)$sheet->getCell('D1')->getValue());
    $hE = trim((string)$sheet->getCell('E1')->getValue());
    if ($hA!==$expected[0] || $hB!==$expected[1] || $hC!==$expected[2] || $hD!==$expected[3] || $hE!==$expected[4]) {
      throw new RuntimeException('Intestazioni non valide. Attese: "Nome, Cognome, Codice Fiscale, Azienda, Sede" nella riga 1.');
    }

    // Opzionali
    $hasF = strcasecmp(trim((string)$sheet->getCell('F1')->getValue()), 'Comune Residenza')===0;
    $hasG = strcasecmp(trim((string)$sheet->getCell('G1')->getValue()), 'Via Residenza')===0;
    $hasH = strcasecmp(trim((string)$sheet->getCell('H1')->getValue()), 'Mansione')===0;

    $maxRow = $sheet->getHighestDataRow();
    if ($maxRow < 2) throw new RuntimeException('Nessun dato da importare (solo intestazione).');

    for ($r=2; $r<=$maxRow; $r++) {
      $nome  = to_title((string)$sheet->getCell('A'.$r)->getValue());
      $cogn  = to_title((string)$sheet->getCell('B'.$r)->getValue());
      $cf    = strtoupper(trim((string)$sheet->getCell('C'.$r)->getValue()));
      $azNom = trim((string)$sheet->getCell('D'.$r)->getValue());
      $sdNom = trim((string)$sheet->getCell('E'.$r)->getValue());

      $comRes = $hasF ? trim((string)$sheet->getCell('F'.$r)->getValue()) : '';
      $viaRes = $hasG ? trim((string)$sheet->getCell('G'.$r)->getValue()) : '';
      $mans   = $hasH ? trim((string)$sheet->getCell('H'.$r)->getValue()) : '';

      if ($nome==='' && $cogn==='' && $cf==='' && $azNom==='' && $sdNom==='' && $comRes==='' && $viaRes==='' && $mans==='') {
        continue; // riga vuota
      }

      // Obbligatori
      if ($nome==='' || $cogn==='' || $cf==='' || $azNom==='' || $sdNom==='') {
        $errors[] = "Riga $r: campi obbligatori mancanti.";
        continue;
      }
      if (!cf_is_valid($cf)) {
        $errors[] = "Riga $r: Codice Fiscale non valido ($cf).";
        continue;
      }

      // Match case-insensitive azienda
      $azKey = mb_strtolower($azNom, 'UTF-8');
      if (!isset($aziendeByLower[$azKey])) {
        $errors[] = "Riga $r: Azienda '$azNom' non trovata (match non sensibile al maiuscolo/minuscolo).";
        continue;
      }
      $azienda_id = $aziendeByLower[$azKey];

      // Match case-insensitive sede per quell'azienda
      $sdKey = mb_strtolower($sdNom, 'UTF-8');
      if (!isset($sediByAziendaLower[$azienda_id][$sdKey])) {
        $errors[] = "Riga $r: Sede '$sdNom' non trovata per azienda '$azNom' (match non sensibile al maiuscolo/minuscolo).";
        continue;
      }
      $sede_id = $sediByAziendaLower[$azienda_id][$sdKey];

      // Duplicati CF per azienda
      $chk = $pdo->prepare("
        SELECT COUNT(*) FROM dipendente d
        JOIN dipendente_sede ds ON d.id = ds.dipendente_id
        JOIN sede s ON s.id = ds.sede_id
        WHERE d.codice_fiscale = ? AND s.azienda_id = ?
      ");
      $chk->execute([$cf, $azienda_id]);
      if ((int)$chk->fetchColumn() > 0) {
        $errors[] = "Riga $r: Codice Fiscale già presente per l’azienda ($cf).";
        continue;
      }

      [$dob,$loc] = cf_parse_birth($cf);

      $preview[] = [
        'riga' => $r,
        'nome' => $nome,
        'cognome' => $cognome = $cogn,
        'codice_fiscale' => $cf,
        'azienda' => $azNom,
        'sede' => $sdNom,
        'azienda_id' => $azienda_id,
        'sede_id' => $sede_id,
        'datanascita' => $dob,
        'luogonascita' => $loc,
        'comuneresidenza' => $comRes,
        'viaresidenza' => $viaRes,
        'mansione' => $mans,
      ];
    }

    if (!empty($errors)) {
      throw new RuntimeException(implode("\n", $errors));
    }
    if (empty($preview)) throw new RuntimeException('Nessuna riga valida da importare.');

    $STAGE = 'preview';

  } catch (Throwable $e) {
    if (!$LIB_OK || $e instanceof Error) $ERROR_MSG = 'Errore tecnico: contatta l’amministrazione';
    else $ERROR_MSG = $e->getMessage();
    $STAGE = 'error';
  }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Importa Dipendenti (massivo)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f0f2f5; --fg:#2e3a45; --card:#fff; --mut:#6c757d;
      --radius:12px; --pri:#66bb6a; --err:#d9534f;
      --shadow:0 10px 30px rgba(0,0,0,.08);
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--fg);font:14px system-ui,-apple-system,Segoe UI,Roboto}
    .container{max-width:1200px;margin:32px auto;padding:0 16px}
    .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;margin-bottom:16px}
    h1{margin:.2rem 0 1rem}
    .topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;gap:.5rem;border:0;border-radius:10px;padding:.6rem 1rem;color:#fff;font-weight:600;cursor:pointer;text-decoration:none}
    .btn-pri{background:var(--pri)}
    .btn-sec{background:#6c757d}
    .btn:hover{opacity:.95}
    .dz{position:relative;border:2px dashed #cfd8dc;background:#fff;border-radius:12px;padding:24px;text-align:center;transition:all .15s;box-shadow:0 2px 6px rgba(0,0,0,.08);min-height:160px;cursor:pointer}
    .dz:hover{border-color:var(--pri)}
    .dz.dragover{border-color:var(--pri);background:#eef8f0}
    .dz input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
    .dz .dz-inner{pointer-events:none}
    .dz .dz-inner i{font-size:2rem;color:var(--pri);margin-bottom:.25rem;display:block}
    .dz .dz-title{font-weight:700}
    .dz .dz-hint{color:#90a4ae;font-size:.9rem;margin-top:.25rem}

    table{border-collapse:collapse;width:100%;overflow:hidden;border-radius:10px;font-size:0.95rem}
    th,td{border:1px solid #e8e8e8;padding:.6rem .7rem;text-align:left;vertical-align:top}
    thead th{background:#f7faf8;position:sticky;top:0}
    .alert{padding:.8rem 1rem;border-radius:10px}
    .alert-err{background:#fdecea;color:#b11c1c;border:1px solid #f5c2c7;white-space:pre-line}
    .actions{display:flex;gap:.6rem;flex-wrap:wrap}
  </style>
</head>
<body>

<div class="container">

  <div class="card topbar">
    <h1>Importa dipendenti (massivo)</h1>
    <a class="btn btn-sec" href="/biosound/resources/templates/dipendenti_massivo.xlsx" download>
      <i class="bi bi-download"></i> Scarica template
    </a>
  </div>

  <?php if (($STAGE ?? '') === 'error'): ?>
    <div class="card alert alert-err">
      <?= htmlspecialchars($ERROR_MSG ?? 'Errore', ENT_QUOTES) ?>
    </div>
  <?php endif; ?>

  <?php if (($STAGE ?? '') !== 'preview'): ?>
    <!-- STEP Upload (auto-anteprima su selezione/drag&drop) -->
    <div class="card">
      <form id="form-parse" method="post" enctype="multipart/form-data">
        <input type="hidden" name="stage" value="parse">
        <div id="dropzone" class="dz" title="Clicca o trascina qui il file XLSX">
          <input type="file" id="xlsx" name="xlsx" accept=".xlsx">
          <div class="dz-inner">
            <i class="bi bi-cloud-arrow-up"></i>
            <div class="dz-title">Trascina qui il file XLSX o clicca</div>
            <div class="dz-hint">
              Riga 1: Nome | Cognome | Codice Fiscale | Azienda | Sede (opzionali: Comune Residenza | Via Residenza | Mansione)
            </div>
          </div>
        </div>
      </form>
    </div>
  <?php else: ?>
    <!-- STEP Anteprima + Conferma -->
    <div class="card">
      <div class="topbar" style="margin-bottom:8px">
        <h2 style="margin:0">Anteprima importazione (<?= count($preview) ?> record)</h2>
        <div class="actions">
          <form method="post" id="form-confirm">
            <input type="hidden" name="stage" value="confirm">
            <input type="hidden" name="data" value='<?= htmlspecialchars(json_encode($preview, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
            <button class="btn btn-pri" type="submit"><i class="bi bi-check2-circle"></i> Conferma inserimento</button>
          </form>
          <form method="get">
            <button class="btn btn-sec" type="submit"><i class="bi bi-arrow-repeat"></i> Scegli nuovo file</button>
          </form>
        </div>
      </div>

      <div style="overflow:auto;max-height:70vh">
        <table>
          <thead>
            <tr>
              <th>#Riga</th>
              <th>Nome</th>
              <th>Cognome</th>
              <th>Codice Fiscale</th>
              <th>Azienda</th>
              <th>Sede</th>
              <th>Data nascita</th>
              <th>Luogo nascita</th>
              <th>Comune residenza</th>
              <th>Via residenza</th>
              <th>Mansione</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $row): ?>
              <tr>
                <td><?= (int)$row['riga'] ?></td>
                <td><?= htmlspecialchars(to_title($row['nome']), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars(to_title($row['cognome']), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['codice_fiscale'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['azienda'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['sede'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['datanascita'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['luogonascita'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['comuneresidenza'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['viaresidenza'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($row['mansione'] ?? '', ENT_QUOTES) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="actions" style="margin-top:12px">
        <form method="post">
          <input type="hidden" name="stage" value="confirm">
          <input type="hidden" name="data" value='<?= htmlspecialchars(json_encode($preview, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
          <button class="btn btn-pri" type="submit"><i class="bi bi-check2-circle"></i> Conferma inserimento</button>
        </form>
        <form method="get">
          <button class="btn btn-sec" type="submit"><i class="bi bi-arrow-repeat"></i> Scegli nuovo file</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
// Drag & drop con auto-avvio anteprima (nessun pulsante)
(function(){
  const dz = document.getElementById('dropzone');
  if (!dz) return;
  const input = document.getElementById('xlsx');
  const form  = document.getElementById('form-parse');

  function submitIfValid() {
    if (input.files && input.files.length === 1) {
      const f = input.files[0];
      if (f.name.toLowerCase().endsWith('.xlsx')) form.submit();
      else { alert('Il file deve essere un .xlsx'); input.value=''; }
    }
  }
  input.addEventListener('change', submitIfValid);

  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev, e=>{ e.preventDefault(); dz.classList.add('dragover'); }));
  ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev, e=>{ e.preventDefault(); dz.classList.remove('dragover'); }));
  dz.addEventListener('drop', e=>{
    const fl = e.dataTransfer?.files;
    if (fl && fl.length) {
      const dt = new DataTransfer();
      dt.items.add(fl[0]);
      input.files = dt.files;
      submitIfValid();
    }
  });
})();
</script>
</body>
</html>
