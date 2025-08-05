<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','1');

// 1) DB + autoload corretto
require_once __DIR__ . '/init.php';                  // inizializza $pdo
require_once __DIR__ . '/libs/vendor/autoload.php';   // <-- qui!

use setasign\Fpdi\Fpdi;

// 2) ID attività  
$idAttivita = $_GET['id'] ?? null;
if (!$idAttivita) {
    header('Location: attivitae.php');
    exit;
}

// 3) Dati corso
$stmt = $pdo->prepare("
  SELECT a.id, c.titolo, c.durata
    FROM attivita a
    JOIN corso c ON a.corso_id = c.id
   WHERE a.id = ?
");
$stmt->execute([$idAttivita]);
$act = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$act) {
    die('Attività non trovata.');
}

// 4) Prima data di lezione
$stmt = $pdo->prepare("
  SELECT MIN(dl.data) AS data_inizio
    FROM incarico i
    JOIN datalezione dl ON dl.incarico_id = i.id
   WHERE i.attivita_id = ?
");
$stmt->execute([$idAttivita]);
$dataInizio = $stmt->fetchColumn() ?: '';

// 5) Dati azienda
$stmt = $pdo->prepare("
  SELECT az.ragionesociale,
         az.piva,
         s.indirizzo    AS indirizzo_sede,
         s.nome         AS sede_legale,
         az.ateco
    FROM attivita a
    JOIN azienda az   ON az.id           = a.azienda
    JOIN sede    s    ON s.id            = az.sedelegale_id
   WHERE a.id = ?
");
$stmt->execute([$idAttivita]);
$az = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'ragionesociale'=>'','piva'=>'',
    'indirizzo_sede'=>'','sede_legale'=>'','ateco'=>''
];

// 6) Partecipanti
$stmt = $pdo->prepare("
  SELECT d.nome,
         d.cognome,
         d.codice_fiscale,
         d.datanascita,
         d.luogonascita,
         d.viaresidenza,
         d.comuneresidenza
    FROM dipendente d
    JOIN attivita_dipendente ad ON ad.dipendente_id = d.id
   WHERE ad.attivita_id = ?
");
$stmt->execute([$idAttivita]);
$partecipanti = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Se non ci sono partecipanti, scarica solo il PDF di template
if (empty($partecipanti)) {
    $template = __DIR__ . '/resources/templates/iscrizione.pdf';
    if (!file_exists($template)) {
        die('Template non trovato.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="iscrizione_template.pdf"');
    header('Content-Length: ' . filesize($template));
    readfile($template);
    exit;
}

// 7) Prepara FPDI
$pdf    = new Fpdi('P','pt');
$tplIdx = $pdf->setSourceFile(__DIR__ . '/resources/templates/iscrizione.pdf');
$tpl    = $pdf->importPage(1);
$size   = $pdf->getTemplateSize($tpl);

$pdf->SetFont('Helvetica','',10);
$pdf->SetTextColor(0,0,0);

// 8) Genera una pagina per ogni partecipante, Y originale +8
foreach ($partecipanti as $p) {
    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
    $pdf->useTemplate($tpl);

    // coordinate con offset Y = +8
    $pdf->Text(164, 181,  $act['titolo']);                       // 173 + 8
    $pdf->Text(138, 206,  $act['durata']);                       // 198 + 8
    $pdf->Text(302, 207,  date('d/m/Y', strtotime($dataInizio))); // 199 + 8
    $pdf->Text(156, 258,  "{$p['nome']} {$p['cognome']}");       // 250 + 8
    $pdf->Text(361, 258,  $p['codice_fiscale']);                 // 250 + 8
    $pdf->Text(156, 278,  date('d/m/Y', strtotime($p['datanascita']))); // 270 + 8
    $pdf->Text(370, 278,  $p['luogonascita']);                   // 270 + 8
    $pdf->Text(189, 299,  $p['viaresidenza']);                   // 291 + 8
    $pdf->Text(98,  322,  $p['comuneresidenza']);                // 314 + 8

    $pdf->Text(162, 406,  $az['ragionesociale']); // 398 + 8
    $pdf->Text(386, 406,  $az['piva']);          // 398 + 8
    $pdf->Text(194, 425,  $az['indirizzo_sede']); // 417 + 8
    $pdf->Text(101, 446,  $az['sede_legale']);   // 438 + 8
    $pdf->Text(142, 470,  $az['ateco']);         // 462 + 8
}

// 9) Download
$pdf->Output('D', "schede_iscrizione_{$idAttivita}.pdf");
exit;
