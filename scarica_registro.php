<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// preservando la formattazione del template .docx usando ZipArchive,
// e unendo i PDF via Ghostscript.
// Funziona sia su Linux che Windows, tutto in resources/templates/temp

// 0) Debug PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Includi init e autoload (assume $pdo da init.php)
require_once __DIR__ . '/init.php';

// 2) Parametro ID attività
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// 3) Carica dati attività + titolo corso
$stmt = $pdo->prepare("
    SELECT a.*, c.titolo AS corso_titolo
      FROM attivita a
 LEFT JOIN corso c ON c.id = a.corso_id
     WHERE a.id = ?
");
$stmt->execute([$id]);
$attivita = $stmt->fetch(PDO::FETCH_ASSOC)
    or exit("Attività con ID {$id} non trovata");

// 4) Carica fino a 35 discenti
$dipStmt = $pdo->prepare(<<<'SQL'
  SELECT d.nome, d.cognome, d.codice_fiscale AS cf,
         d.datanascita, d.luogonascita, az.ragionesociale AS azienda
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

// 5) Carica date distinte delle lezioni
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

// 6) Prepara cartella di lavoro
$work = __DIR__ . '/resources/templates/temp';
if (!is_dir($work) && !mkdir($work, 0777, true)) {
    exit("Impossibile creare la cartella di lavoro: {$work}");
}

// 7) Helper per trovare i binari
function isWindows(): bool {
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}
function findBinary(string $name, array $cands = []): string {
    if (isWindows()) {
        foreach ($cands as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }
        return $name;
    } else {
        $which = trim(shell_exec("which {$name} 2>/dev/null"));
        return $which !== '' ? $which : $name;
    }
}
$soffice = findBinary('soffice', [
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
]);
$gs = findBinary('gs', [
    'C:\\Program Files\\gs\\gs\\bin\\gswin64c.exe',
    'C:\\Program Files\\gs\\gs\\bin\\gswin32c.exe',
]);

// 8) Verifica che i binari esistano
foreach ([$soffice, $gs] as $bin) {
    $check = isWindows()
        ? shell_exec("where " . escapeshellarg($bin) . " 2>NUL")
        : shell_exec("which " . escapeshellarg($bin) . " 2>/dev/null");
    if (trim($check) === '') {
        exit("Errore: binario non trovato: {$bin}");
    }
}

// 9) Inizia il ciclo per ogni data
$pdfFiles = [];
$template = __DIR__ . '/resources/templates/registro_template.docx';

foreach ($dateList as $dataLezione) {
    // 9.1) Copia il template
    $docxPath = "{$work}/registro_{$id}_" . uniqid() . ".docx";
    if (!copy($template, $docxPath)) {
        exit("Impossibile copiare il template");
    }

    // 9.2) Apri con ZipArchive
    $zip = new ZipArchive();
    if ($zip->open($docxPath) !== true) {
        exit("Errore: non posso aprire $docxPath come ZIP");
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        exit("Errore: word/document.xml non trovato");
    }

    // 9.3) Costruisci mappa di sostituzione
    $map = [];
    // segnaposti fissi
    $map['${IDCorso}'] = htmlspecialchars($attivita['id'],    ENT_XML1);
    $map['${Corso}']   = htmlspecialchars($attivita['corso_titolo'], ENT_XML1);
    $map['${Sede}']    = htmlspecialchars($attivita['luogo'], ENT_XML1);
    $map['${Data}']    = date('d/m/Y', strtotime($dataLezione));

    // segnaposto Docente
    $docStmt = $pdo->prepare(<<<'SQL'
      SELECT DISTINCT d.nome, d.cognome
        FROM datalezione dl
        JOIN incarico i ON i.id = dl.incarico_id
        JOIN docenteincarico di ON di.incarico_id = i.id
        JOIN docente d ON d.id = di.docente_id
       WHERE i.attivita_id = ? AND dl.data = ?
SQL
    );
    $docStmt->execute([$id, $dataLezione]);
    $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($r) => "{$r['cognome']} {$r['nome']}", $docs);
    $map['${Docente}'] = htmlspecialchars(implode(', ', $names), ENT_XML1);

    // segnaposti discenti reali
    foreach ($discenti as $i => $u) {
        $n = $i + 1;
        $map["\${Nome{$n}}"]    = htmlspecialchars($u['nome'], ENT_XML1);
        $map["\${Cognome{$n}}"] = htmlspecialchars($u['cognome'], ENT_XML1);
        $map["\${natoA{$n}}"]   = htmlspecialchars($u['luogonascita'], ENT_XML1);
        $map["\${natoIl{$n}}"]  = $u['datanascita']
            ? date('d/m/Y', strtotime($u['datanascita']))
            : '';
        $map["\${CF{$n}}"]      = htmlspecialchars($u['cf'], ENT_XML1);
        $map["\${Azienda{$n}}"] = htmlspecialchars($u['azienda'], ENT_XML1);
    }

    // 9.4) Svuota i segnaposti residui da (n+1) a 35
    $count = count($discenti);
    for ($i = $count + 1; $i <= 35; $i++) {
        foreach (['Nome','Cognome','natoA','natoIl','CF','Azienda'] as $fld) {
            $map["\${{$fld}{$i}}"] = '';
        }
    }

    // 9.5) Applica le sostituzioni
    $newXml = str_replace(
        array_keys($map),
        array_values($map),
        $xml
    );
    $zip->addFromString('word/document.xml', $newXml);
    $zip->close();

    // 9.6) Converte in PDF con LibreOffice headless e profilo temp
    $profile = "{$work}/lo_profile_" . uniqid();
    @mkdir($profile, 0777, true);

    $cmdParts = [
        $soffice,
        '--headless',
        "-env:UserInstallation=file://{$profile}",
        '--convert-to', 'pdf',
        '--outdir', $work,
        $docxPath
    ];
    if (isWindows()) {
        // quoting per Windows
        $cmd = '"' . implode('" "', $cmdParts) . '" 2>&1';
    } else {
        $cmd = implode(' ', array_map('escapeshellarg', $cmdParts)) . ' 2>&1';
    }
    exec($cmd, $out, $ret);

    // rimuovi profilo LibreOffice
    if (isWindows()) {
        exec("rmdir /S /Q " . escapeshellarg($profile));
    } else {
        exec("rm -rf " . escapeshellarg($profile));
    }

    if ($ret !== 0) {
        exit("Errore conversione PDF (LibreOffice):\n" . implode("\n", $out));
    }

    // 9.7) Raccogli PDF e cancella DOCX
    $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath);
    if (!file_exists($pdfPath)) {
        exit("PDF non trovato dopo conversione");
    }
    $pdfFiles[] = $pdfPath;
    @unlink($docxPath);
}

// 10) Unisci tutti i PDF in un unico file con Ghostscript
$finalPdf = "{$work}/registro_{$id}_" . time() . ".pdf";
$parts = array_merge(
    [$gs, '-dNOPAUSE', '-dBATCH', '-q', '-sDEVICE=pdfwrite', "-sOutputFile={$finalPdf}"],
    $pdfFiles
);
if (isWindows()) {
    $cmd = '"' . implode('" "', $parts) . '" 2>&1';
} else {
    $cmd = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
}
exec($cmd, $ogs, $rgs);
if ($rgs !== 0 || !file_exists($finalPdf)) {
    exit("Errore fusione PDF (Ghostscript):\n" . implode("\n", $ogs));
}

// 11) Cancella PDF intermedi
foreach ($pdfFiles as $f) {
    @unlink($f);
}

// 12) Invia il PDF finale all'utente
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_' . $id . '.pdf"');
readfile($finalPdf);
@unlink($finalPdf);
exit;
