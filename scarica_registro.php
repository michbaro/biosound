<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// salvando TUTTI i file temporanei in resources/templates/temp/

require_once __DIR__ . '/init.php';            // connessione $pdo, sessione, ecc.
require_once __DIR__ . '/vendor/autoload.php'; // autoload Composer

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Escapes an argument for Windows CMD:
 * - Avvolge in doppie virgolette
 * - Duplica eventuali virgolette interne
 */
function escapeForWindows(string $arg): string {
    return '"' . str_replace('"', '""', $arg) . '"';
}

// 1) ID attività
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// 2) Carica dati attività + titolo corso
$stmt = $pdo->prepare("
    SELECT a.*, c.titolo AS corso_titolo
    FROM attivita a
    LEFT JOIN corso c ON c.id = a.corso_id
    WHERE a.id = ?
");
$stmt->execute([$id]);
$attivita = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attivita) {
    http_response_code(404);
    exit("Attività con ID {$id} non trovata");
}

// 3) Carica fino a 35 discenti
$dipStmt = $pdo->prepare(<<<SQL
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

// 4) Carica date delle lezioni
$dateStmt = $pdo->prepare(<<<SQL
    SELECT DISTINCT dl.data
    FROM datalezione dl
    JOIN incarico i ON i.id = dl.incarico_id
    WHERE i.attivita_id = ?
    ORDER BY dl.data
SQL
);
$dateStmt->execute([$id]);
$dateList = $dateStmt->fetchAll(PDO::FETCH_COLUMN);

// 5) Cartella di lavoro (TUTTI i file .docx e .pdf)
$workingDir = __DIR__
            . DIRECTORY_SEPARATOR
            . 'resources'
            . DIRECTORY_SEPARATOR
            . 'templates'
            . DIRECTORY_SEPARATOR
            . 'temp';

// crea la directory se non esiste
if (!is_dir($workingDir) && false === mkdir($workingDir, 0777, true)) {
    exit("Impossibile creare la cartella di lavoro: {$workingDir}");
}

// 6) Percorsi ai binari (adattali se necessario)
// LibreOffice
$sofficeBin = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';
// Ghostscript
$gsBin      = 'C:\\Program Files\\gs\\gs10.05.1\\bin\\gswin64.exe';

if (!file_exists($sofficeBin)) {
    exit("LibreOffice non trovato in: {$sofficeBin}");
}
if (!file_exists($gsBin)) {
    exit("Ghostscript non trovato in: {$gsBin}");
}

// 7) Generazione e conversione
$pdfFiles = [];
$templatePath = __DIR__
               . DIRECTORY_SEPARATOR
               . 'resources'
               . DIRECTORY_SEPARATOR
               . 'templates'
               . DIRECTORY_SEPARATOR
               . 'registro_template.docx';

foreach ($dateList as $dataLezione) {
    // 7.1) Compila il template .docx
    $tpl = new TemplateProcessor($templatePath);

    // Valori fissi
    $tpl->setValue('IDCorso', htmlspecialchars($attivita['id']));
    $tpl->setValue('Corso',   htmlspecialchars($attivita['corso_titolo'] ?? ''));
    $tpl->setValue('Sede',    htmlspecialchars($attivita['luogo'] ?? ''));
    $tpl->setValue('Data',    date('d/m/Y', strtotime($dataLezione)));

    // 7.1.a) Recupera i docenti per questa data
    $docStmt = $pdo->prepare(<<<SQL
        SELECT DISTINCT d.nome, d.cognome
        FROM datalezione dl
        JOIN incarico i ON i.id = dl.incarico_id
        JOIN docenteincarico di ON di.incarico_id = i.id
        JOIN docente d ON d.id = di.docente_id
        WHERE i.attivita_id = ? AND dl.data = ?
        ORDER BY d.cognome, d.nome
SQL
    );
    $docStmt->execute([$id, $dataLezione]);
    $listaDoc = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    $nomiDoc  = array_map(
        fn($d) => $d['cognome'] . ' ' . $d['nome'],
        $listaDoc
    );
    $tpl->setValue('Docente', htmlspecialchars(implode(', ', $nomiDoc)));

    // 7.1.b) Partecipa­ti: Nome1…Azienda35
    foreach ($discenti as $i => $d) {
        $n = $i + 1;
        $tpl->setValue("Nome$n",    htmlspecialchars($d['nome']));
        $tpl->setValue("Cognome$n", htmlspecialchars($d['cognome']));
        $tpl->setValue("natoA$n",   htmlspecialchars($d['luogonascita']));
        $tpl->setValue("natoIl$n",  $d['datanascita']
                                     ? date('d/m/Y', strtotime($d['datanascita']))
                                     : '');
        $tpl->setValue("CF$n",      htmlspecialchars($d['cf']));
        $tpl->setValue("Azienda$n", htmlspecialchars($d['azienda']));
    }
    // Svuota i placeholder non usati (da count($discenti)+1 fino a 35)
    for ($j = count($discenti) + 1; $j <= 35; $j++) {
        $tpl->setValue("Nome$j",    '');
        $tpl->setValue("Cognome$j", '');
        $tpl->setValue("natoA$j",   '');
        $tpl->setValue("natoIl$j",  '');
        $tpl->setValue("CF$j",      '');
        $tpl->setValue("Azienda$j", '');
    }

    // 7.2) Salva .docx in workingDir
    $docxPath = $workingDir
              . DIRECTORY_SEPARATOR
              . "registro_{$id}_" . uniqid() . ".docx";
    $tpl->saveAs($docxPath);

    // 7.3) Converte in PDF in workingDir
    $cmd = escapeForWindows($sofficeBin)
         . ' --headless --convert-to pdf --outdir '
         . escapeForWindows($workingDir)
         . ' '
         . escapeForWindows($docxPath)
         . ' 2>&1';
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        exit("Errore conversione PDF (LibreOffice):\n" . implode("\n", $out));
    }

    // 7.4) Aggiunge il PDF all'array
    $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath);
    if (!file_exists($pdfPath)) {
        exit("PDF non trovato dopo conversione");
    }
    $pdfFiles[] = $pdfPath;

    // 7.5) Rimuove il .docx
    @unlink($docxPath);
}

// 8) Fusione PDF con Ghostscript in workingDir
$finalPdf = $workingDir
          . DIRECTORY_SEPARATOR
          . "registro_{$id}_" . time() . ".pdf";
$pdfArgs = implode(' ', array_map('escapeForWindows', $pdfFiles));
$cmd = escapeForWindows($gsBin)
     . ' -dNOPAUSE -dBATCH -q -sDEVICE=pdfwrite'
     . ' -sOutputFile=' . escapeForWindows($finalPdf)
     . ' ' . $pdfArgs
     . ' 2>&1';
exec($cmd, $outGs, $retGs);
if ($retGs !== 0) {
    exit("Errore fusione PDF (Ghostscript):\n" . implode("\n", $outGs));
}

// 9) Cancella PDF intermedi
foreach ($pdfFiles as $f) {
    @unlink($f);
}

// 10) Invia il PDF finale all’utente
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_' . $id . '.pdf"');
readfile($finalPdf);

// 11) Rimuove il PDF finale dal server
@unlink($finalPdf);
exit;
