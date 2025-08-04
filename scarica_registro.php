<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// sostituendo i tag [var.X] tramite OpenTBS e unendo i PDF via Ghostscript.
// Funziona sia su Linux che Windows, usa resources/templates/temp per i file temporanei.

// 0) Debug
ini_set('display_errors',1);
error_reporting(E_ALL);

// 1) Init
require __DIR__ . '/init.php';             // fornisce $pdo
require __DIR__ . '/vendor/autoload.php';  // Composer autoload
require_once __DIR__ . '/vendor/tinybutstrong/opentbs/tbs_plugin_opentbs.php';

use clsTinyButStrong;
use tbs_plugin_opentbs;

// 2) Parametro ID
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// 3) Dati attività + corso
$stmt = $pdo->prepare("
    SELECT a.*, c.titolo AS corso_titolo
      FROM attivita a
 LEFT JOIN corso c ON c.id = a.corso_id
     WHERE a.id = ?
");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC) 
    or exit("Attività con ID {$id} non trovata");

// 4) Discenti (max 35)
$dipStmt = $pdo->prepare(<<<'SQL'
  SELECT d.nome,d.cognome,d.codice_fiscale AS cf,
         d.datanascita,d.luogonascita,az.ragionesociale AS azienda
    FROM dipendente d
    JOIN attivita_dipendente ad ON ad.dipendente_id=d.id
    LEFT JOIN dipendente_sede ds ON ds.dipendente_id=d.id
    LEFT JOIN sede s ON s.id=ds.sede_id
    LEFT JOIN azienda az ON az.id=s.azienda_id
   WHERE ad.attivita_id = ?
   LIMIT 35
SQL
);
$dipStmt->execute([$id]);
$discenti = $dipStmt->fetchAll(PDO::FETCH_ASSOC);

// 5) Date lezioni
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

// 6) Cartella temp
$work = __DIR__ . '/resources/templates/temp';
if (!is_dir($work) && !mkdir($work,0777,true)) {
    exit("Impossibile creare: {$work}");
}

// 7) Trova soffice e gs
function isWindows(): bool {
    return strtoupper(substr(PHP_OS,0,3))==='WIN';
}
function findBin(string $n, array $c=[]): string {
    if (isWindows()) {
        foreach($c as $p) if(file_exists($p)) return $p;
        return $n;
    }
    $w=trim(shell_exec("which {$n} 2>/dev/null"));
    return $w!==''?$w:$n;
}
$soffice = findBin('soffice', [
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
]);
$gs      = findBin('gs', [
    'C:\\Program Files\\gs\\gs\\bin\\gswin64c.exe',
    'C:\\Program Files\\gs\\gs\\bin\\gswin32c.exe',
]);
foreach([$soffice,$gs] as $b){
    $chk = isWindows()
        ? shell_exec("where ".escapeshellarg($b)." 2>NUL")
        : shell_exec("which ".escapeshellarg($b)." 2>/dev/null");
    if(trim($chk)==='') exit("Binario non trovato: {$b}");
}

// 8) Inizializza OpenTBS
$TBS = new clsTinyButStrong;
$TBS->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);

// 9) Loop per ogni data
$pdfFiles = [];
$template = __DIR__ . '/resources/templates/registro_template.docx';

foreach ($dateList as $d) {
    // 9.1) Carica template .docx
    $TBS->LoadTemplate($template, OPENTBS_ALREADY_UTF8);

    // 9.2) Popola VarRef con i valori fissi
    $TBS->VarRef['IDCorso'] = $att['id'];
    $TBS->VarRef['Corso']   = $att['corso_titolo'];
    $TBS->VarRef['Sede']    = $att['luogo'];
    $TBS->VarRef['Data']    = date('d/m/Y', strtotime($d));

    // 9.3) Calcola Docente per data
    $docSt = $pdo->prepare(<<<'SQL'
      SELECT DISTINCT d.nome,d.cognome
        FROM datalezione dl
        JOIN incarico i ON i.id=dl.incarico_id
        JOIN docenteincarico di ON di.incarico_id=i.id
        JOIN docente d ON d.id=di.docente_id
       WHERE i.attivita_id=? AND dl.data=?
SQL
    );
    $docSt->execute([$id,$d]);
    $docs = $docSt->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($r)=>"{$r['cognome']} {$r['nome']}", $docs);
    $TBS->VarRef['Docente'] = implode(', ', $names);

    // 9.4) Popola discenti reali
    foreach ($discenti as $i=>$u) {
        $n = $i+1;
        $TBS->VarRef["Nome{$n}"]    = $u['nome'];
        $TBS->VarRef["Cognome{$n}"] = $u['cognome'];
        $TBS->VarRef["natoA{$n}"]   = $u['luogonascita'];
        $TBS->VarRef["natoIl{$n}"]  = $u['datanascita']
            ? date('d/m/Y',strtotime($u['datanascita']))
            : '';
        $TBS->VarRef["CF{$n}"]      = $u['cf'];
        $TBS->VarRef["Azienda{$n}"] = $u['azienda'];
    }
    // 9.5) Azzera segnaposti da n+1 a 35
    $cnt = count($discenti);
    for ($i=$cnt+1; $i<=35; $i++) {
        foreach (['Nome','Cognome','natoA','natoIl','CF','Azienda'] as $fld) {
            $TBS->VarRef["{$fld}{$i}"] = '';
        }
    }

    // 9.6) Salva il .docx compilato
    $docx = "{$work}/registro_{$id}_" . uniqid() . ".docx";
    $TBS->Show(OPENTBS_FILE, $docx);

    // 9.7) Converte in PDF con profilo dedicato
    $profile = "{$work}/lo_profile_" . uniqid();
    @mkdir($profile,0777,true);
// dopo: forziamo il filtro writer_pdf_Export
$parts = [
    $soffice, '--headless',
    "-env:UserInstallation=file://{$profile}",
    '--convert-to', 'pdf:writer_pdf_Export',
    '--outdir', $work,
    $docx
];
    $cmd = isWindows()
         ? '"'.implode('" "',$parts).'" 2>&1'
         : implode(' ', array_map('escapeshellarg',$parts)).' 2>&1';
    exec($cmd,$out,$r);
    // cancella profilo
    if (isWindows()) {
        exec("rmdir /S /Q ".escapeshellarg($profile));
    } else {
        exec("rm -rf ".escapeshellarg($profile));
    }
    if ($r!==0) {
        exit("Errore conversione PDF:\n".implode("\n",$out));
    }

    // 9.8) Raccogli PDF e rimuovi .docx
    $pdf = preg_replace('/\.docx$/i','.pdf',$docx);
    if (!file_exists($pdf)) exit("PDF non generato");
    $pdfFiles[] = $pdf;
    @unlink($docx);
}

// 10) Unisci tutti i PDF in uno solo
$final = "{$work}/registro_{$id}_" . time() . ".pdf";
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
foreach ($pdfFiles as $f) @unlink($f);

// 11) Invia il PDF finale
header('Content-Type: application/pdf');
// modifica – mostra inline nel browser
header('Content-Disposition: inline; filename="registro_' . $id . '.pdf"');
readfile($final);
@unlink($final);
exit;
