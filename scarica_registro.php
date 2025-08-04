<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// sostituendo manualmente i placeholder nel document.xml
// e unendo i PDF via Ghostscript.
// Funziona su Linux e Windows, mette tutto in resources/templates/temp

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/init.php'; // fornisce $pdo, sessione, ecc.

// 1) ID attività
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// 2) Dati attività + corso
$stmt = $pdo->prepare("
    SELECT a.*, c.titolo AS corso_titolo
      FROM attivita a
 LEFT JOIN corso c ON c.id = a.corso_id
     WHERE a.id = ?
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
    JOIN incarico i ON i.id = dl.incarico_id
   WHERE i.attivita_id = ?
   ORDER BY dl.data
SQL
);
$dateStmt->execute([$id]);
$dateList = $dateStmt->fetchAll(PDO::FETCH_COLUMN);

// 5) Cartella temp
$work = __DIR__ . '/resources/templates/temp';
if (!is_dir($work) && !mkdir($work,0777,true)) {
    exit("Impossibile creare cartella di lavoro: {$work}");
}

// 6) Funzioni helper
function isWindows(): bool {
    return strtoupper(substr(PHP_OS,0,3))==='WIN';
}
function findBinary(string $name, array $cands=[]): string {
    if (isWindows()) {
        foreach($cands as $p) if(file_exists($p)) return $p;
        return $name;
    }
    $which = trim(shell_exec("which {$name} 2>/dev/null"));
    return $which!==''? $which:$name;
}
$soffice = findBinary('soffice', [
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
]);
$gs = findBinary('gs', [
    'C:\\Program Files\\gs\\gs\\bin\\gswin64c.exe',
    'C:\\Program Files\\gs\\gs\\bin\\gswin32c.exe',
]);
// verifica
foreach([$soffice,$gs] as $bin) {
    $chk = isWindows()
        ? shell_exec("where " . escapeshellarg($bin) ." 2>NUL")
        : shell_exec("which " . escapeshellarg($bin) ." 2>/dev/null");
    if(trim($chk)==='') exit("Binario non trovato: {$bin}");
}

// 7) Loop sulle date -> genera DOCX, converte in PDF
$pdfFiles = [];
$template = __DIR__ . '/resources/templates/registro_template.docx';

foreach($dateList as $dataLezione) {
    // 7.1) copia template
    $docxPath = "$work/registro_{$id}_" . uniqid() . ".docx";
    if(!copy($template,$docxPath)) {
        exit("Impossibile copiare template");
    }

    // 7.2) apri ZIP e leggi document.xml
    $zip = new ZipArchive();
    if($zip->open($docxPath)!==true) {
        exit("Errore ZIP su $docxPath");
    }
    $xml = $zip->getFromName('word/document.xml');
    if($xml===false) {
        exit("document.xml non trovato");
    }

    // 7.3) mappa sostituzioni
    $map = [];
    // fissi
    $map['${IDCorso}'] = $att['id'];
    $map['${Corso}']   = $att['corso_titolo'];
    $map['${Sede}']    = $att['luogo'];
    $map['${Data}']    = date('d/m/Y',strtotime($dataLezione));
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
    $docStmt->execute([$id,$dataLezione]);
    $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($r)=>"{$r['cognome']} {$r['nome']}",$docs);
    $map['${Docente}'] = implode(', ',$names);
    // discenti reali
    foreach($discenti as $i=>$u) {
        $n = $i+1;
        $map["\${Nome{$n}}"]    = $u['nome'];
        $map["\${Cognome{$n}}"] = $u['cognome'];
        $map["\${natoA{$n}}"]   = $u['luogonascita'];
        $map["\${natoIl{$n}}"]  = $u['datanascita']
            ? date('d/m/Y',strtotime($u['datanascita']))
            : '';
        $map["\${CF{$n}}"]      = $u['cf'];
        $map["\${Azienda{$n}}"] = $u['azienda'];
    }
    // pulisci i restanti
    $count = count($discenti);
    for($i=$count+1;$i<=35;$i++){
        foreach(['Nome','Cognome','natoA','natoIl','CF','Azienda'] as $fld){
            $map['${'.$fld.$i.'}'] = '';
        }
    }

    // 7.4) sostituisci nel XML
    $newXml = str_replace(
        array_keys($map),
        array_values($map),
        $xml
    );
    $zip->addFromString('word/document.xml',$newXml);
    $zip->close();

    // 7.5) converti in PDF con soffice headless
    $profile = "$work/lo_profile_".uniqid();
    @mkdir($profile,0777,true);
    $cmdParts = [
        $soffice,
        '--headless',
        "-env:UserInstallation=file://{$profile}",
        '--convert-to','pdf',
        '--outdir',$work,
        $docxPath
    ];
    if(isWindows()){
        $cmd = '"'.implode('" "',$cmdParts).'" 2>&1';
    } else {
        $cmd = implode(' ',array_map('escapeshellarg',$cmdParts)).' 2>&1';
    }
    exec($cmd,$out,$ret);
    // elimina profilo
    if(isWindows()){
        exec("rmdir /S /Q ".escapeshellarg($profile));
    } else {
        exec("rm -rf ".escapeshellarg($profile));
    }
    if($ret!==0){
        exit("Errore LibreOffice:\n".implode("\n",$out));
    }

    // raccogli PDF e cancella DOCX
    $pdf = preg_replace('/\.docx$/i','.pdf',$docxPath);
    if(!file_exists($pdf)){
        exit("PDF non generato");
    }
    $pdfFiles[] = $pdf;
    @unlink($docxPath);
}

// 8) unisci PDF con Ghostscript
$final = "$work/registro_{$id}_".time().".pdf";
$parts = array_merge(
    [$gs,'-dNOPAUSE','-dBATCH','-q','-sDEVICE=pdfwrite',"-sOutputFile={$final}"],
    $pdfFiles
);
if(isWindows()){
    $cmd = '"'.implode('" "',$parts).'" 2>&1';
} else {
    $cmd = implode(' ',array_map('escapeshellarg',$parts)).' 2>&1';
}
exec($cmd,$ogs,$rgs);
if($rgs!==0||!file_exists($final)){
    exit("Errore fusione PDF:\n".implode("\n",$ogs));
}
foreach($pdfFiles as $f) @unlink($f);

// 9) invia all’utente
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_'.$id.'.pdf"');
readfile($final);
@unlink($final);
exit;
