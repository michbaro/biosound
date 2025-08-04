<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// preservando formattazione e sostituendo i tag TBS [var.xxx] in Word,
// poi converte con LibreOffice e unisce i PDF con Ghostscript.

ini_set('display_errors',1);
error_reporting(E_ALL);

require __DIR__ . '/init.php';             // fornisce $pdo
require __DIR__ . '/vendor/autoload.php';  // Composer

// carica il plugin OpenTBS
require_once __DIR__ . '/vendor/tinybutstrong/opentbs/tbs_plugin_opentbs.php';

use clsTinyButStrong;
use tbs_plugin_opentbs;

// ---- 1) Parametro ID ----
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// ---- 2) Carica dati attività + corso ----
$stmt = $pdo->prepare("
    SELECT a.*, c.titolo AS corso_titolo
      FROM attivita a
 LEFT JOIN corso   c ON c.id = a.corso_id
     WHERE a.id = ?
");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC)
    or exit("Attività con ID {$id} non trovata");

// ---- 3) Discenti (max 35) ----
$dipStmt = $pdo->prepare(<<<'SQL'
  SELECT d.nome,d.cognome,d.codice_fiscale AS cf,
         d.datanascita,d.luogonascita,az.ragionesociale AS azienda
    FROM dipendente d
    JOIN attivita_dipendente ad ON ad.dipendente_id=d.id
    LEFT JOIN dipendente_sede ds ON ds.dipendente_id=d.id
    LEFT JOIN sede s ON s.id=ds.sede_id
    LEFT JOIN azienda az ON az.id=s.azienda_id
   WHERE ad.attivita_id=?
   LIMIT 35
SQL
);
$dipStmt->execute([$id]);
$discenti = $dipStmt->fetchAll(PDO::FETCH_ASSOC);

// ---- 4) Date lezioni ----
$dateStmt = $pdo->prepare(<<<'SQL'
  SELECT DISTINCT dl.data
    FROM datalezione dl
    JOIN incarico i ON i.id = dl.incarico_id
   WHERE i.attivita_id = ?
   ORDER BY dl.data
SQL
);
$dateStmt->execute([$id]);
$dateList = $dateStmt->fetchAll(PDO::FETCH_COLUMN);

// ---- 5) Prepara temp ----
$work = __DIR__ . '/resources/templates/temp';
if (!is_dir($work) && !mkdir($work, 0777, true)) {
    exit("Impossibile creare cartella: {$work}");
}

// ---- 6) Trova soffice e gs ----
function isWindows(): bool {
    return strtoupper(substr(PHP_OS,0,3))==='WIN';
}
function findBin(string $name, array $cands=[]): string {
    if (isWindows()) {
        foreach($cands as $p) if(file_exists($p)) return $p;
        return $name;
    }
    $w = trim(shell_exec("which {$name} 2>/dev/null"));
    return $w!==''? $w : $name;
}
$soffice = findBin('soffice', [
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
]);
$gs = findBin('gs', [
    'C:\\Program Files\\gs\\gs\\bin\\gswin64c.exe',
    'C:\\Program Files\\gs\\gs\\bin\\gswin32c.exe',
]);
// verifica
foreach([$soffice,$gs] as $b) {
    $chk = isWindows()
        ? shell_exec("where ".escapeshellarg($b)." 2>NUL")
        : shell_exec("which ".escapeshellarg($b)." 2>/dev/null");
    if (trim($chk)==='') exit("Binario non trovato: {$b}");
}

// ---- 7) Init OpenTBS ----
$TBS = new clsTinyButStrong;
$TBS->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);

// ---- 8) Loop sulle date ----
$pdfFiles = [];
$template = __DIR__ . '/resources/templates/registro_template.docx';

foreach ($dateList as $d) {
    // 8.1) Carica template
    $TBS->LoadTemplate($template, OPENTBS_ALREADY_UTF8);

    // 8.2) Merge variabili fisse
    $TBS->MergeField('IDCorso', $att['id']);
    $TBS->MergeField('Corso',   $att['corso_titolo']);
    $TBS->MergeField('Sede',    $att['luogo']);
    $TBS->MergeField('Data',    date('d/m/Y', strtotime($d)));

    // 8.3) Merge Docente
    $docStmt = $pdo->prepare(<<<'SQL'
      SELECT DISTINCT d.nome,d.cognome
        FROM datalezione dl
        JOIN incarico i ON i.id=dl.incarico_id
        JOIN docenteincarico di ON di.incarico_id=i.id
        JOIN docente d ON d.id=di.docente_id
       WHERE i.attivita_id=? AND dl.data=?
SQL
    );
    $docStmt->execute([$id,$d]);
    $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($r)=>"{$r['cognome']} {$r['nome']}", $docs);
    $TBS->MergeField('Docente', implode(', ', $names));

    // 8.4) Merge discenti reali
    foreach ($discenti as $i=>$u) {
        $n = $i+1;
        $TBS->MergeField("Nome{$n}",    $u['nome']);
        $TBS->MergeField("Cognome{$n}", $u['cognome']);
        $TBS->MergeField("natoA{$n}",   $u['luogonascita']);
        $TBS->MergeField("natoIl{$n}",  $u['datanascita']
                                        ? date('d/m/Y',strtotime($u['datanascita']))
                                        : '');
        $TBS->MergeField("CF{$n}",      $u['cf']);
        $TBS->MergeField("Azienda{$n}", $u['azienda']);
    }
    // 8.5) Azzera da n+1 a 35
    $cnt = count($discenti);
    for ($i=$cnt+1; $i<=35; $i++) {
        $TBS->MergeField("Nome{$i}",    '');
        $TBS->MergeField("Cognome{$i}", '');
        $TBS->MergeField("natoA{$i}",   '');
        $TBS->MergeField("natoIl{$i}",  '');
        $TBS->MergeField("CF{$i}",      '');
        $TBS->MergeField("Azienda{$i}", '');
    }

    // 8.6) Salva doc temporaneo
    $docx = "$work/registro_{$id}_" . uniqid() . ".docx";
    $TBS->Show(OPENTBS_FILE, $docx);

    // 8.7) Converte in PDF con profilo dedicato
    $profile = "$work/lo_profile_" . uniqid();
    @mkdir($profile, 0777, true);
    $parts = [
        $soffice, '--headless',
        "-env:UserInstallation=file://{$profile}",
        '--convert-to','pdf',
        '--outdir',$work,
        $docx
    ];
    $cmd = isWindows()
        ? '"'.implode('" "',$parts).'" 2>&1'
        : implode(' ', array_map('escapeshellarg',$parts)).' 2>&1';
    exec($cmd,$out,$r);
    // rimuovi profilo
    if (isWindows()) {
        exec("rmdir /S /Q ".escapeshellarg($profile));
    } else {
        exec("rm -rf ".escapeshellarg($profile));
    }
    if ($r!==0) {
        exit("Errore conversione PDF:\n".implode("\n",$out));
    }

    // 8.8) Raccogli PDF e cancella DOCX
    $pdf = preg_replace('/\.docx$/i','.pdf',$docx);
    $pdfFiles[] = $pdf;
    @unlink($docx);
}

// ---- 9) Unisci PDF in uno solo ----
$final = "$work/registro_{$id}_" . time() . ".pdf";
$parts = array_merge(
    [$gs,'-dNOPAUSE','-dBATCH','-q','-sDEVICE=pdfwrite',"-sOutputFile={$final}"],
    $pdfFiles
);
$cmd = isWindows()
    ? '"'.implode('" "',$parts).'" 2>&1'
    : implode(' ', array_map('escapeshellarg',$parts)).' 2>&1';
exec($cmd,$ogs,$rg);
if ($rg!==0 || !file_exists($final)) {
    exit("Errore fusione PDF:\n".implode("\n",$ogs));
}
// pulisci intermedi
foreach($pdfFiles as $f) @unlink($f);

// ---- 10) Restituisci il PDF ----
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_'.$id.'.pdf"');
readfile($final);
@unlink($final);
exit;
