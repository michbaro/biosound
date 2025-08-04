<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// funzionante sia su Linux che Windows, usando resources/templates/temp

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Ritorna true se il sistema operativo è Windows.
 */
function isWindows(): bool {
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

/**
 * Quote di un singolo argomento per CMD Windows.
 */
function escapeForWindows(string $arg): string {
    // duplica le doppie virgolette, poi wrap
    return '"' . str_replace('"', '""', $arg) . '"';
}

/**
 * Costruisce la stringa di comando da un array di parti,
 * applicando quoting corretto per Windows o POSIX.
 */
function buildCommand(array $parts): string {
    if (isWindows()) {
        $quoted = array_map('escapeForWindows', $parts);
    } else {
        $quoted = array_map('escapeshellarg', $parts);
    }
    // redirige stderr in stdout per debug
    return implode(' ', $quoted) . ' 2>&1';
}

/**
 * Trova il binario di LibreOffice (soffice).
 * Su Windows cerca nei percorsi standard, altrimenti usa 'soffice'.
 */
function findSoffice(): string {
    if (isWindows()) {
        $candidates = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'
        ];
        foreach ($candidates as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }
        // fallback: proviamo a vedere se è nel PATH
        return 'soffice';
    } else {
        // Linux/Mac: cerchiamo con which
        $which = trim(shell_exec('which soffice 2>/dev/null'));
        return $which !== '' ? $which : 'soffice';
    }
}

/**
 * Trova il binario di Ghostscript.
 * Su Windows cerca gswin64c.exe o gswin32c.exe in C:\Program Files\gs\*\bin
 * altrimenti usa 'gs'.
 */
function findGs(): string {
    if (isWindows()) {
        $base = 'C:\\Program Files\\gs\\';
        foreach (glob($base . '*\\bin\\gswin64c.exe') as $p) {
            return $p;
        }
        foreach (glob($base . '*\\bin\\gswin32c.exe') as $p) {
            return $p;
        }
        return 'gswin64c.exe';
    } else {
        $which = trim(shell_exec('which gs 2>/dev/null'));
        return $which !== '' ? $which : 'gs';
    }
}

// --------------------------------------------------
// 1) ID attività
// --------------------------------------------------
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// --------------------------------------------------
// 2) Carica dati attività e corso
// --------------------------------------------------
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

// --------------------------------------------------
// 3) Carica fino a 35 discenti associati
// --------------------------------------------------
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

// --------------------------------------------------
// 4) Carica date distinte delle lezioni
// --------------------------------------------------
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

// --------------------------------------------------
// 5) Prepara cartella di lavoro interna
// --------------------------------------------------
$workingDir = __DIR__ . '/resources/templates/temp';
if (!is_dir($workingDir) && false === mkdir($workingDir, 0777, true)) {
    exit("Impossibile creare la cartella di lavoro: {$workingDir}");
}

// --------------------------------------------------
// 6) Individua i binari esterni
// --------------------------------------------------
$soffice = findSoffice();
$gs      = findGs();

// --------------------------------------------------
// 7) Generazione DOCX → PDF per ogni data
// --------------------------------------------------
$pdfFiles   = [];
$template   = __DIR__ . '/resources/templates/registro_template.docx';

foreach ($dateList as $dataLezione) {
    // 7.1) Compila il template
    $tpl = new TemplateProcessor($template);
    $tpl->setValue('IDCorso', $attivita['id']);
    $tpl->setValue('Corso',   $attivita['corso_titolo']);
    $tpl->setValue('Sede',    $attivita['luogo']);
    $tpl->setValue('Data',    date('d/m/Y', strtotime($dataLezione)));

    // 7.1.a) Docenti per data
    $docStmt = $pdo->prepare(<<<'SQL'
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
    $nomiDoc  = array_map(fn($d) => "{$d['cognome']} {$d['nome']}", $listaDoc);
    $tpl->setValue('Docente', implode(', ', $nomiDoc));

    // 7.1.b) Partecipanti (Nome1…Azienda35)
    foreach ($discenti as $i => $u) {
        $n = $i + 1;
        $tpl->setValue("Nome$n",    $u['nome']);
        $tpl->setValue("Cognome$n", $u['cognome']);
        $tpl->setValue("natoA$n",   $u['luogonascita']);
        $tpl->setValue("natoIl$n",  $u['datanascita'] ? date('d/m/Y', strtotime($u['datanascita'])) : '');
        $tpl->setValue("CF$n",      $u['cf']);
        $tpl->setValue("Azienda$n", $u['azienda']);
    }
    // pulisci placeholder non usati
    for ($j = count($discenti) + 1; $j <= 35; $j++) {
        $tpl->setValue("Nome$j",    '');
        $tpl->setValue("Cognome$j", '');
        $tpl->setValue("natoA$j",   '');
        $tpl->setValue("natoIl$j",  '');
        $tpl->setValue("CF$j",      '');
        $tpl->setValue("Azienda$j", '');
    }

    // 7.2) Salva DOCX temporaneo
    $docxPath = "$workingDir/registro_{$id}_" . uniqid() . ".docx";
    $tpl->saveAs($docxPath);

    // 7.3) Converte in PDF
    $cmd = buildCommand([
        $soffice,
        '--headless',
        '--convert-to', 'pdf',
        '--outdir', $workingDir,
        $docxPath
    ]);
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        exit("Errore conversione PDF (LibreOffice):\n" . implode("\n", $out));
    }

    // 7.4) Raccogli PDF
    $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath);
    if (!file_exists($pdfPath)) {
        exit("PDF non trovato dopo conversione");
    }
    $pdfFiles[] = $pdfPath;

    // 7.5) Rimuovi DOCX
    @unlink($docxPath);
}

// --------------------------------------------------
// 8) Unisci tutti i PDF in uno solo
// --------------------------------------------------
$finalPdf = "$workingDir/registro_{$id}_" . time() . ".pdf";
$parts    = array_merge(
    [$gs, '-dNOPAUSE', '-dBATCH', '-q', '-sDEVICE=pdfwrite', "-sOutputFile=$finalPdf"],
    $pdfFiles
);
$cmd = isWindows()
    ? buildCommand($parts)
    : buildCommand(array_merge([$gs, '-dNOPAUSE', '-dBATCH', '-q', '-sDEVICE=pdfwrite', "-sOutputFile=$finalPdf"], $pdfFiles));

exec($cmd, $outGs, $retGs);
if ($retGs !== 0 || !file_exists($finalPdf)) {
    exit("Errore fusione PDF (Ghostscript):\n" . implode("\n", $outGs));
}

// 9) Pulisci i PDF intermedi
foreach ($pdfFiles as $f) {
    @unlink($f);
}

// --------------------------------------------------
// 10) Restituisci il PDF finale all’utente
// --------------------------------------------------
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_' . $id . '.pdf"');
readfile($finalPdf);

// 11) Pulisci PDF finale
@unlink($finalPdf);
exit;
