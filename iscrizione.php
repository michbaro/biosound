<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','1');

// 1) DB + autoload
require_once __DIR__ . '/init.php';                  
require_once __DIR__ . '/libs/vendor/autoload.php';   

use setasign\Fpdi\Fpdi;

// 2) ID attività  
$idAttivita = $_GET['id'] ?? null;
if (!$idAttivita) {
    header('Location: attivitae.php');
    exit;
}

// 3) Dati corso + flag corsoFinanziato
$stmt = $pdo->prepare("
  SELECT a.id, c.titolo, c.durata, a.corsoFinanziato
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

// 5) Partecipanti (+ azienda solo se corsoFinanziato = 0)
if ($act['corsoFinanziato']) {
    // No dati azienda
    $stmt = $pdo->prepare("
      SELECT
        d.nome,
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
} else {
    // Con dati azienda
    $stmt = $pdo->prepare("
      SELECT
        d.nome,
        d.cognome,
        d.codice_fiscale,
        d.datanascita,
        d.luogonascita,
        d.viaresidenza,
        d.comuneresidenza,
        az.ragionesociale,
        az.piva,
        COALESCE(s.indirizzo, '') AS indirizzo_sede,
        COALESCE(s.nome, '')      AS sede_legale,
        COALESCE(az.ateco, '')    AS ateco
      FROM dipendente d
      JOIN attivita_dipendente ad ON ad.dipendente_id = d.id
      LEFT JOIN dipendente_sede ds ON ds.dipendente_id = d.id
      LEFT JOIN sede s             ON s.id = ds.sede_id AND s.is_legale = 1
      LEFT JOIN azienda az         ON az.id = s.azienda_id
      WHERE ad.attivita_id = ?
    ");
}
$stmt->execute([$idAttivita]);
$partecipanti = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6) Scegli template PDF
$template = __DIR__ . '/resources/templates/' . (
    $act['corsoFinanziato'] ? 'isc_fondo.pdf' : 'isc_nofondo.pdf'
);

// Se non ci sono partecipanti, scarica solo il template
if (empty($partecipanti)) {
    if (!file_exists($template)) {
        die('Template non trovato: ' . basename($template));
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="iscrizione_template.pdf"');
    readfile($template);
    exit;
}

// 7) Prepara FPDI
$pdf    = new Fpdi('P','pt');
$pdf->SetAutoPageBreak(false);
$pdf->SetFont('Helvetica','',10);
$pdf->SetTextColor(0,0,0);

if (!file_exists($template)) {
    die('Template non trovato: ' . basename($template));
}

$tplIdx = $pdf->setSourceFile($template);
$tpl    = $pdf->importPage(1);
$size   = $pdf->getTemplateSize($tpl);

// 8) Una pagina per ogni partecipante
foreach ($partecipanti as $p) {
    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
    $pdf->useTemplate($tpl);

    // Dati corso e partecipante
    $pdf->Text(164, 181,  $act['titolo']);                             
    $pdf->Text(138, 206,  $act['durata']);                             
    $pdf->Text(302, 207,  date('d/m/Y', strtotime($dataInizio)));      
    $pdf->Text(156, 258,  "{$p['nome']} {$p['cognome']}");             
    $pdf->Text(361, 258,  $p['codice_fiscale']);                       
    $pdf->Text(156, 278,  date('d/m/Y', strtotime($p['datanascita']))); 
    $pdf->Text(370, 278,  $p['luogonascita']);                         
    $pdf->Text(189, 299,  $p['viaresidenza']);                         
    $pdf->Text(98,  322,  $p['comuneresidenza']);                      

    // Dati azienda SOLO se corso NON finanziato
    if (!$act['corsoFinanziato']) {
        $pdf->Text(162, 406,  $p['ragionesociale']);
        $pdf->Text(386, 406,  $p['piva']);
        $pdf->Text(194, 425,  $p['indirizzo_sede']);
        $pdf->Text(101, 446,  $p['sede_legale']);
        $pdf->Text(142, 470,  $p['ateco']);
    }
}

// 9) Output PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="schede_iscrizione_' . $idAttivita . '.pdf"');
$pdf->Output('I');
exit;
