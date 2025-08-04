<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// usando ZipArchive per sostituire i placeholder, poi soffice+gs.

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/init.php';          // connessione $pdo, sessione, ecc.

// 1) Parametro ID
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// 2) Carica attività + corso
$stmt = $pdo->prepare("
  SELECT a.*, c.titolo AS corso_titolo
    FROM attivita a
    LEFT JOIN corso c ON c.id = a.corso_id
   WHERE a.id = ?
");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC)
    or exit("Attività con ID {$id} non trovata");

// 3) Preleva discenti (max 35)
$dipStmt = $pdo->prepare(<<<'SQL'
  SELECT d.nome,d.cognome,d.codice_fiscale AS cf,
         d.datanascita,d.luogonascita,az.ragionesociale AS azienda
    FROM dipendente d
    JOIN attivita_dipendente ad ON ad.dipendente_id = d.id
    LEFT JOIN dipendente_sede ds ON ds.dipendente_id = d.id
    LEFT JOIN sede s           ON s.id = ds.sede_id
    LEFT JOIN azienda az       ON az.id = s.azienda_id
   WHERE ad.attivita_id = ?
   LIMIT 35
SQL
);
$dipStmt->execute([$id]);
$discenti = $dipStmt->fetchAll(PDO::FETCH_ASSOC);

// 4) Preleva date lezioni
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

// 5) Prepara cartella temp
$work = __DIR__ . '/resources/templates/temp';
if (!is_dir($work) && !mkdir($work,0777,true)) {
    exit("Impossibile creare la cartella di lavoro: {$work}");
}

// 6) Rileva binari soffice e gs
function findBin($name, $cands=[]) {
    if (stripos(PHP_OS,'WIN')===0) {
        foreach ($cands as $p) if (file_exists($p)) return $p;
        return $name;
    }
    $w = trim(shell_exec("which {$name} 2>/dev/null"));
    return $w!==''? $w : $name;
}
$soffice = findBin('soffice', [
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'
]);
$gs      = findBin('gs', [
    'C:\\Program Files\\gs\\gs\\bin\\gswin64c.exe',
    'C:\\Program Files\\gs\\gs\\bin\\gswin32c.exe'
]);

// Controlla che esistano
foreach ([$soffice,$gs] as $bin) {
    $chk = stripos(PHP_OS,'WIN')===0
        ? shell_exec("where \"{$bin}\" 2>NUL")
        : shell_exec("which \"{$bin}\" 2>/dev/null");
    if (trim($chk)==='') exit("Binario non trovato: {$bin}");
}

// 7) Loop per ogni data: sostituisci placeholder e genera PDF
$pdfFiles = [];
$template = __DIR__ . '/resources/templates/registro_template.docx';

foreach ($dateList as $dataLezione) {
    // 7.1) Crea una copia del template
    $docxPath = "{$work}/registro_{$id}_" . uniqid() . ".docx";
    if (!copy($template, $docxPath)) {
        exit("Impossibile copiare il template");
    }

    // 7.2) Apri con ZipArchive
    $zip = new ZipArchive();
    if ($zip->open($docxPath)!==true) {
        exit("Errore: non posso aprire $docxPath come ZIP");
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml===false) {
        exit("Errore: word/document.xml non trovato");
    }

    // 7.3) Prepara mappa sostituzioni
    $map = [];
    // fissi
    $map['${IDCorso}'] = htmlspecialchars($att['id'],    ENT_XML1);
    $map['${Corso}']   = htmlspecialchars($att['corso_titolo'],ENT_XML1);
    $map['${Sede}']    = htmlspecialchars($att['luogo'], ENT_XML1);
    $map['${Data}']    = date('d/m/Y', strtotime($dataLezione));

    // docenti per data
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
    $names = array_map(fn($r)=>"{$r['cognome']} {$r['nome']}", $docs);
    $map['${Docente}'] = htmlspecialchars(implode(', ',$names), ENT_XML1);

    // discenti
    foreach ($discenti as $i=>$u) {
        $n = $i+1;
        $map["\${Nome{$n}}"]    = htmlspecialchars($u['nome'], ENT_XML1);
        $map["\${Cognome{$n}}"] = htmlspecialchars($u['cognome'], ENT_XML1);
        $map["\${natoA{$n}}"]   = htmlspecialchars($u['luogonascita'], ENT_XML1);
        $map["\${natoIl{$n}}"]  = $u['datanascita']
            ? date('d/m/Y',strtotime($u['datanascita']))
            : '';
        $map["\${CF{$n}}"]      = htmlspecialchars($u['cf'], ENT_XML1);
        $map["\${Azienda{$n}}"] = htmlspecialchars($u['azienda'], ENT_XML1);
    }
    // svuota i rimanenti fino a 35
    for ($j=count($discenti)+1; $j<=35; $j++) {
        foreach (['Nome','Cognome','natoA','natoIl','CF','Azienda'] as $fld) {
            $map["\${{$fld}{$j}}"] = '';
        }
    }

    // 7.4) Applica le sostituzioni
    $xml = str_replace(
        array_keys($map),
        array_values($map),
        $xml
    );
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();

    // 7.5) Converte in PDF usando LibreOffice headless
    $profile = "{$work}/lo_profile_" . uniqid();
    @mkdir($profile,0777,true);

    // prepara comando
    $cmdParts = [
        $soffice,
        '--headless',
        "-env:UserInstallation=file://{$profile}",
        '--convert-to','pdf',
        '--outdir',$work,
        $docxPath
    ];
    // escape
    $cmd = stripos(PHP_OS,'WIN')===0
         ? '"' . implode('" "', $cmdParts) . '" 2>&1'
         : implode(' ', array_map('escapeshellarg', $cmdParts)) . ' 2>&1';

    exec($cmd, $out, $ret);
    // rimuovi profilo
    if (stripos(PHP_OS,'WIN')===0) {
        exec("rmdir /S /Q " . escapeshellarg($profile));
    } else {
        exec("rm -rf " . escapeshellarg($profile));
    }
    if ($ret!==0) {
        exit("Errore conversione PDF (LibreOffice):\n" . implode("\n",$out));
    }

    // raccogli PDF e cancella DOCX
    $pdf = preg_replace('/\.docx$/i','.pdf',$docxPath);
    if (!file_exists($pdf)) exit("PDF non trovato");
    $pdfFiles[] = $pdf;
    @unlink($docxPath);
}

// 8) Unisci PDF con Ghostscript
$final = "{$work}/registro_{$id}_" . time() . ".pdf";
$parts = array_merge(
    [$gs,'-dNOPAUSE','-dBATCH','-q','-sDEVICE=pdfwrite',"-sOutputFile={$final}"],
    $pdfFiles
);
$cmd = stripos(PHP_OS,'WIN')===0
     ? '"' . implode('" "', $parts) . '" 2>&1'
     : implode(' ', array_map('escapeshellarg',$parts)) . ' 2>&1';
exec($cmd, $ogs, $rgs);
if ($rgs!==0 || !file_exists($final)) {
    exit("Errore fusione PDF (Ghostscript):\n".implode("\n",$ogs));
}
// pulisci intermedi
foreach ($pdfFiles as $f) @unlink($f);

// 9) Invia il PDF finale
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_' . $id . '.pdf"');
readfile($final);
@unlink($final);
exit;
