<?php
// scarica_registro.php
// Genera un unico PDF con i registri delle lezioni di un'attività,
// usando OpenTBS per preservare la formattazione del template .docx
// Funziona sia su Linux che Windows, mette tutto in resources/templates/temp

// 0) Debug PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/init.php';              // connessione $pdo, sessione, ecc.
require_once __DIR__ . '/vendor/autoload.php';   // autoloader Composer

// Carica manualmente il plugin OpenTBS, che definisce OPENTBS_PLUGIN
require_once __DIR__ . '/vendor/tinybutstrong/opentbs/tbs_plugin_opentbs.php';

use clsTinyButStrong;
use tbs_plugin_opentbs;

// --------------------------------------------------
// 1) ID attività
// --------------------------------------------------
$id = $_GET['id'] ?? null;
if (!$id) {
    http_response_code(400);
    exit('Parametro "id" mancante');
}

// --------------------------------------------------
// 2) Dati attività + corso
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
// 3) Dati discenti (max 35)
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
// 4) Date distinte delle lezioni
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
// 5) Cartella di lavoro interna
// --------------------------------------------------
$work = __DIR__ . '/resources/templates/temp';
if (!is_dir($work) && !mkdir($work, 0777, true)) {
    exit("Impossibile creare la cartella di lavoro: {$work}");
}

// --------------------------------------------------
// 6) Individua binari soffice e gs
// --------------------------------------------------
function findBinary(string $name, array $candidates = []): string {
    if (stripos(PHP_OS, 'WIN') === 0) {
        foreach ($candidates as $p) if (file_exists($p)) return $p;
        return $name;
    } else {
        $which = trim(shell_exec("which {$name} 2>/dev/null"));
        return $which !== '' ? $which : $name;
    }
}
$soffice = findBinary('soffice', [
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'
]);
$gs      = findBinary('gs', [
    'C:\\Program Files\\gs\\gs\\bin\\gswin64c.exe',
    'C:\\Program Files\\gs\\gs\\bin\\gswin32c.exe'
]);

// Controller di presenza
foreach ([$soffice, $gs] as $bin) {
    $check = stripos(PHP_OS, 'WIN')===0
        ? shell_exec("where \"{$bin}\" 2>NUL")
        : shell_exec("which \"{$bin}\" 2>/dev/null");
    if (trim($check) === '') {
        exit("Errore: binario non trovato: {$bin}");
    }
}

// --------------------------------------------------
// 7) Inizializza OpenTBS
// --------------------------------------------------
$TBS = new clsTinyButStrong;
$TBS->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);

// --------------------------------------------------
// 8) Loop su ogni data: genera DOCX e converte in PDF
// --------------------------------------------------
$pdfFiles = [];
$template = __DIR__ . '/resources/templates/registro_template.docx';

foreach ($dateList as $dataLezione) {
    // 8.1) Carica e merge template
    $TBS->LoadTemplate($template, OPENTBS_ALREADY_UTF8);
    $TBS->MergeField('IDCorso', $attivita['id']);
    $TBS->MergeField('Corso',   $attivita['corso_titolo']);
    $TBS->MergeField('Sede',    $attivita['luogo']);
    $TBS->MergeField('Data',    date('d/m/Y', strtotime($dataLezione)));

    // Docenti per data
    $docStmt = $pdo->prepare(<<<'SQL'
      SELECT DISTINCT d.nome,d.cognome
        FROM datalezione dl
        JOIN incarico i ON i.id = dl.incarico_id
        JOIN docenteincarico di ON di.incarico_id = i.id
        JOIN docente d ON d.id = di.docente_id
       WHERE i.attivita_id = ? AND dl.data = ?
SQL
    );
    $docStmt->execute([$id, $dataLezione]);
    $listaDoc = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    $nomiDoc  = array_map(fn($d) => "{$d['cognome']} {$d['nome']}", $listaDoc);
    $TBS->MergeField('Docente', implode(', ', $nomiDoc));

    // Partecipanti (Nome1…Azienda35)
    foreach ($discenti as $i => $u) {
        $n = $i + 1;
        $TBS->MergeField("Nome{$n}",    $u['nome']);
        $TBS->MergeField("Cognome{$n}", $u['cognome']);
        $TBS->MergeField("natoA{$n}",   $u['luogonascita']);
        $TBS->MergeField("natoIl{$n}",  $u['datanascita']
                                        ? date('d/m/Y', strtotime($u['datanascita']))
                                        : '');
        $TBS->MergeField("CF{$n}",      $u['cf']);
        $TBS->MergeField("Azienda{$n}", $u['azienda']);
    }
    for ($j = count($discenti) + 1; $j <= 35; $j++) {
        $TBS->MergeField("Nome{$j}",    '');
        $TBS->MergeField("Cognome{$j}", '');
        $TBS->MergeField("natoA{$j}",   '');
        $TBS->MergeField("natoIl{$j}",  '');
        $TBS->MergeField("CF{$j}",      '');
        $TBS->MergeField("Azienda{$j}", '');
    }

    // 8.2) Salva DOCX temporaneo
    $docxPath = "{$work}/registro_{$id}_" . uniqid() . ".docx";
    $TBS->Show(OPENTBS_FILE, $docxPath);

    // 8.3) Crea profilo LibreOffice in temp
    $profile = "{$work}/lo_profile_" . uniqid();
    @mkdir($profile, 0777, true);

    // 8.4) Converte in PDF
    $cmd = buildCommand([
        $soffice,
        '--headless',
        "-env:UserInstallation=file://{$profile}",
        '--convert-to', 'pdf',
        '--outdir', $work,
        $docxPath
    ]);
    exec($cmd, $out, $ret);

    // rimuovi profilo
    if (stripos(PHP_OS,'WIN')===0) {
        exec("rmdir /S /Q " . escapeshellarg($profile));
    } else {
        exec("rm -rf " . escapeshellarg($profile));
    }

    if ($ret !== 0) {
        exit("Errore conversione PDF (LibreOffice):\n" . implode("\n", $out));
    }

    // 8.5) Aggiungi PDF e rimuovi DOCX
    $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath);
    if (!file_exists($pdfPath)) {
        exit("PDF non trovato dopo conversione");
    }
    $pdfFiles[] = $pdfPath;
    @unlink($docxPath);
}

// --------------------------------------------------
// 9) Unisci i PDF con Ghostscript
// --------------------------------------------------
$finalPdf = "{$work}/registro_{$id}_" . time() . ".pdf";
$parts    = array_merge(
    [$gs, '-dNOPAUSE', '-dBATCH', '-q', '-sDEVICE=pdfwrite', "-sOutputFile={$finalPdf}"],
    $pdfFiles
);
$cmd = buildCommand($parts);
exec($cmd, $ogs, $rgs);
if ($rgs !== 0 || !file_exists($finalPdf)) {
    exit("Errore fusione PDF (Ghostscript):\n" . implode("\n", $ogs));
}

// cancella intermedi
foreach ($pdfFiles as $f) {
    @unlink($f);
}

// --------------------------------------------------
// 10) Invia il PDF finale all’utente
// --------------------------------------------------
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_' . $id . '.pdf"');
readfile($finalPdf);
@unlink($finalPdf);
exit;
