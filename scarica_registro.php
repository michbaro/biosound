<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// funzionante sia su Linux che su Windows, salvando tutto in resources/templates/temp

// 0) Debug PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Determina se siamo su Windows.
 */
function isWindows(): bool {
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

/**
 * Escape di un argomento per CMD Windows.
 */
function escapeForWindows(string $arg): string {
    return '"' . str_replace('"', '""', $arg) . '"';
}

/**
 * Costruisce la stringa di comando da parti, con quoting corretto su Windows o POSIX.
 */
function buildCommand(array $parts): string {
    $quoted = isWindows()
        ? array_map('escapeForWindows', $parts)
        : array_map('escapeshellarg', $parts);
    return implode(' ', $quoted) . ' 2>&1';
}

/**
 * Trova il binario di LibreOffice (soffice).
 */
function findSoffice(): string {
    if (isWindows()) {
        $cands = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'
        ];
        foreach ($cands as $p) {
            if (file_exists($p)) return $p;
        }
        return 'soffice';
    } else {
        $w = trim(shell_exec('which soffice 2>/dev/null'));
        return $w !== '' ? $w : 'soffice';
    }
}

/**
 * Trova il binario di Ghostscript (gs).
 */
function findGs(): string {
    if (isWindows()) {
        $base = 'C:\\Program Files\\gs\\';
        foreach (glob($base . '*\\bin\\gswin64c.exe') as $p) return $p;
        foreach (glob($base . '*\\bin\\gswin32c.exe') as $p) return $p;
        return 'gswin64c.exe';
    } else {
        $w = trim(shell_exec('which gs 2>/dev/null'));
        return $w !== '' ? $w : 'gs';
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
// 2) Carica dati attività + corso
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
// 3) Carica fino a 35 discenti
// --------------------------------------------------
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
if (!is_dir($workingDir) && !mkdir($workingDir, 0777, true)) {
    exit("Impossibile creare la cartella di lavoro: {$workingDir}");
}

// --------------------------------------------------
// 6) Individua i binari esterni
// --------------------------------------------------
$soffice = findSoffice();
$gs      = findGs();
// Verifica che siano trovati
foreach ([$soffice, $gs] as $bin) {
    $check = isWindows()
        ? shell_exec("where " . escapeshellarg($bin) . " 2>NUL")
        : shell_exec("which " . escapeshellarg($bin) . " 2>/dev/null");
    if (trim($check) === '') exit("Errore: binario non trovato: {$bin}");
}

// --------------------------------------------------
// 7) Genera DOCX → PDF per ogni data
// --------------------------------------------------
$pdfFiles = [];
$template = __DIR__ . '/resources/templates/registro_template.docx';

foreach ($dateList as $dataLezione) {
    // 7.1) Compila il template
    $tpl = new TemplateProcessor($template);
    $tpl->setValue('IDCorso', $attivita['id']);
    $tpl->setValue('Corso',   $attivita['corso_titolo']);
    $tpl->setValue('Sede',    $attivita['luogo']);
    $tpl->setValue('Data',    date('d/m/Y', strtotime($dataLezione)));

    // Docenti per questa data
    $docStmt = $pdo->prepare(<<<'SQL'
      SELECT DISTINCT d.nome,d.cognome
        FROM datalezione dl
        JOIN incarico i ON i.id = dl.incarico_id
        JOIN docenteincarico di ON di.incarico_id = i.id
        JOIN docente d ON d.id = di.docente_id
       WHERE i.attivita_id = ? AND dl.data = ?
       ORDER BY d.cognome,d.nome
SQL
    );
    $docStmt->execute([$id, $dataLezione]);
    $listaDoc = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    $nomiDoc  = array_map(fn($d) => "{$d['cognome']} {$d['nome']}", $listaDoc);
    $tpl->setValue('Docente', implode(', ', $nomiDoc));

    // Partecipa­ti: Nome1…Azienda35
    foreach ($discenti as $i => $u) {
        $n = $i + 1;
        $tpl->setValue("Nome$n",    $u['nome']);
        $tpl->setValue("Cognome$n", $u['cognome']);
        $tpl->setValue("natoA$n",   $u['luogonascita']);
        $tpl->setValue("natoIl$n",  $u['datanascita'] ? date('d/m/Y', strtotime($u['datanascita'])) : '');
        $tpl->setValue("CF$n",      $u['cf']);
        $tpl->setValue("Azienda$n", $u['azienda']);
    }
    for ($j = count($discenti) + 1; $j <= 35; $j++) {
        $tpl->setValue("Nome$j",    '');
        $tpl->setValue("Cognome$j", '');
        $tpl->setValue("natoA$j",   '');
        $tpl->setValue("natoIl$j",  '');
        $tpl->setValue("CF$j",      '');
        $tpl->setValue("Azienda$j", '');
    }

    // 7.2) Salva DOCX
    $docxPath = "{$workingDir}/registro_{$id}_" . uniqid() . ".docx";
    $tpl->saveAs($docxPath);

    // 7.3) Prepara profilo user per LibreOffice
    $profileDir = "{$workingDir}/lo_profile_" . uniqid();
    @mkdir($profileDir, 0777, true);

    // 7.4) Converte in PDF con profilo dedicato
    $cmd = buildCommand([
        $soffice,
        '--headless',
        "-env:UserInstallation=file://{$profileDir}",
        '--convert-to', 'pdf',
        '--outdir', $workingDir,
        $docxPath
    ]);
    exec($cmd, $out, $ret);

    // eliminate il profilo
    $cleanup = isWindows()
        ? buildCommand(['rmdir','/S','/Q',$profileDir])
        : buildCommand(['rm','-rf',$profileDir]);
    exec($cleanup);

    if ($ret !== 0) {
        exit("Errore conversione PDF (LibreOffice):\n" . implode("\n", $out));
    }

    // 7.5) Raccogli PDF e rimuovi DOCX
    $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath);
    if (!file_exists($pdfPath)) {
        exit("PDF non trovato dopo conversione");
    }
    $pdfFiles[] = $pdfPath;
    @unlink($docxPath);
}

// --------------------------------------------------
// 8) Unisci tutti i PDF in uno solo
// --------------------------------------------------
$finalPdf = "{$workingDir}/registro_{$id}_" . time() . ".pdf";
$parts    = array_merge(
    [$gs, '-dNOPAUSE', '-dBATCH', '-q', '-sDEVICE=pdfwrite', "-sOutputFile={$finalPdf}"],
    $pdfFiles
);
$cmd = buildCommand($parts);
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

// 11) Rimuovi finale
@unlink($finalPdf);
exit;
