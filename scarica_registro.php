<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// preservando formattazione con OpenTBS, su Linux e Windows.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/init.php';            // connessione $pdo
require_once __DIR__ . '/vendor/autoload.php'; // Composer autoload
// Carica manuale del plugin per definire OPENTBS_PLUGIN
require_once __DIR__ . '/vendor/tinybutstrong/opentbs/tbs_plugin_opentbs.php';

use clsTinyButStrong;
use tbs_plugin_opentbs;

/** Rileva Windows */
function isWindows(): bool {
    return strtoupper(substr(PHP_OS,0,3)) === 'WIN';
}
/** Escape per CMD Windows */
function escapeForWindows(string $arg): string {
    return '"' . str_replace('"','""',$arg) . '"';
}
/** Costruisce comando shell con quoting giusto */
function buildCommand(array $parts): string {
    $quoted = isWindows()
        ? array_map('escapeForWindows', $parts)
        : array_map('escapeshellarg', $parts);
    return implode(' ', $quoted) . ' 2>&1';
}
/** Trova binario in PATH o candidati Windows */
function findBinary(string $name, array $cands=[]): string {
    if (isWindows()) {
        foreach ($cands as $p) if (file_exists($p)) return $p;
        return $name;
    }
    $which = trim(shell_exec("which {$name} 2>/dev/null"));
    return $which!=='' ? $which : $name;
}

// 1) ID attività
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// 2) Carica dati attività + corso
$stmt = $pdo->prepare("
  SELECT a.*, c.titolo AS corso_titolo
    FROM attivita a
  LEFT JOIN corso c ON c.id=a.corso_id
   WHERE a.id=?
");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC)
    or exit("Attività con ID {$id} non trovata");

// 3) Discenti (max 35)
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

// 4) Date lezioni
$dateStmt = $pdo->prepare(<<<'SQL'
  SELECT DISTINCT dl.data
    FROM datalezione dl
    JOIN incarico i ON i.id=dl.incarico_id
   WHERE i.attivita_id=?
   ORDER BY dl.data
SQL
);
$dateStmt->execute([$id]);
$dateList = $dateStmt->fetchAll(PDO::FETCH_COLUMN);

// 5) Cartella temp
$work = __DIR__ . '/resources/templates/temp';
if (!is_dir($work) && !mkdir($work,0777,true)) {
    exit("Impossibile creare cartella: {$work}");
}

// 6) Binari
$soffice = findBinary('soffice', [
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'
]);
$gs      = findBinary('gs', [
    'C:\\Program Files\\gs\\gs\\bin\\gswin64c.exe',
    'C:\\Program Files\\gs\\gs\\bin\\gswin32c.exe'
]);
// Verifica esistenza
foreach ([$soffice,$gs] as $b) {
    $chk = isWindows()
        ? shell_exec("where ".escapeForWindows($b)." 2>NUL")
        : shell_exec("which ".escapeshellarg($b)." 2>/dev/null");
    if (trim($chk)==='') exit("Binario non trovato: {$b}");
}

// 7) Inizializza OpenTBS
$TBS = new clsTinyButStrong;
$TBS->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);

// 8) Loop per ogni data
$pdfFiles = [];
$template = __DIR__ . '/resources/templates/registro_template.docx';

foreach ($dateList as $d) {
    // Carica & merge
    $TBS->LoadTemplate($template, OPENTBS_ALREADY_UTF8);
    $TBS->MergeField('IDCorso', $att['id']);
    $TBS->MergeField('Corso',   $att['corso_titolo']);
    $TBS->MergeField('Sede',    $att['luogo']);
    $TBS->MergeField('Data',    date('d/m/Y',strtotime($d)));

    // Docenti
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
    $names = array_map(fn($r)=>"{$r['cognome']} {$r['nome']}",$docs);
    $TBS->MergeField('Docente', implode(', ',$names));

    // Discenti
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
    for ($j=count($discenti)+1; $j<=35; $j++) {
        $TBS->MergeField("Nome{$j}",'');
        $TBS->MergeField("Cognome{$j}",'');
        $TBS->MergeField("natoA{$j}",'');
        $TBS->MergeField("natoIl{$j}",'');
        $TBS->MergeField("CF{$j}",'');
        $TBS->MergeField("Azienda{$j}",'');
    }

    // Salva docx
    $docx = "{$work}/registro_{$id}_" . uniqid() . ".docx";
    $TBS->Show(OPENTBS_FILE, $docx);

    // Profile LO
    $profile = "{$work}/lo_profile_" . uniqid();
    @mkdir($profile,0777,true);

    // Converti in PDF
    $cmd = buildCommand([
        $soffice,
        '--headless',
        "-env:UserInstallation=file://{$profile}",
        '--convert-to','pdf',
        '--outdir',$work,
        $docx
    ]);
    exec($cmd,$out,$ret);

    // elimina profilo
    if (isWindows()) {
        exec(buildCommand(['rmdir','/S','/Q',$profile]));
    } else {
        exec(buildCommand(['rm','-rf',$profile]));
    }

    if ($ret!==0) exit("LibreOffice errore:\n".implode("\n",$out));

    // Raccogli PDF e cancella docx
    $pdf = preg_replace('/\.docx$/i','.pdf',$docx);
    if (!file_exists($pdf)) exit("PDF non trovato");
    $pdfFiles[]=$pdf;
    @unlink($docx);
}

// 9) Fusione PDF
$final = "{$work}/registro_{$id}_" . time() . ".pdf";
$parts = array_merge(
    [$gs,'-dNOPAUSE','-dBATCH','-q','-sDEVICE=pdfwrite',"-sOutputFile={$final}"],
    $pdfFiles
);
exec(buildCommand($parts), $og, $rg);
if ($rg!==0 || !file_exists($final)) {
    exit("Ghostscript errore:\n".implode("\n",$og));
}

// cancella intermedi
foreach ($pdfFiles as $f) @unlink($f);

// 10) Download finale
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_'.$id.'.pdf"');
readfile($final);
@unlink($final);
exit;
