<?php
// scarica_registro.php — include prima di qualsiasi output
require __DIR__ . '/init.php';             // sessione, headers e $pdo
require __DIR__ . '/libs/vendor/autoload.php';  // Composer (FPDF, FPDI)

// — namespace per FPDI
use setasign\Fpdi\Fpdi;

// 1) Percorsi
$templatePath = realpath(__DIR__ . '/resources/templates/registro_template.docx');
$tmpDocxDir   = __DIR__ . '/tmp_docx/';
$tmpPdfDir    = __DIR__ . '/tmp_pdf/';
$outputPdf    = __DIR__ . '/reports/registro_unificato.pdf';

// crea le cartelle se mancano
foreach ([$tmpDocxDir, $tmpPdfDir, dirname($outputPdf)] as $d) {
    if (! is_dir($d)) mkdir($d, 0777, true);
}

// 2) Prendo l'ID e recupero dati
$id = $_GET['id'] ?? exit('ID mancante');
$stmt = $pdo->prepare("
  SELECT a.id AS IDCorso, a.luogo AS Sede, c.titolo AS Corso
  FROM attivita a
  JOIN corso c ON c.id = a.corso_id
  WHERE a.id = :id
");
$stmt->execute(['id'=>$id]);
$att = $stmt->fetch() ?: exit('Attività non trovata');

// elenco dipendenti (max 35)
$stmt = $pdo->prepare("
  SELECT d.nome, d.cognome,
         COALESCE(d.luogonascita,'') AS natoA,
         COALESCE(DATE_FORMAT(d.datanascita,'%Y-%m-%d'), '') AS natoIl,
         COALESCE(d.codice_fiscale,'') AS CF,
         COALESCE(az.ragionesociale,'') AS Azienda
  FROM attivita_dipendente ad
  JOIN dipendente d ON d.id = ad.dipendente_id
  LEFT JOIN dipendente_sede ds ON ds.dipendente_id = d.id
  LEFT JOIN sede s           ON s.id          = ds.sede_id
  LEFT JOIN azienda az       ON az.id         = s.azienda_id
  WHERE ad.attivita_id = :id
  ORDER BY d.cognome, d.nome
  LIMIT 35
");
$stmt->execute(['id'=>$id]);
$dipendenti = $stmt->fetchAll();

// elenco date distinte
$stmt = $pdo->prepare("
  SELECT DISTINCT DATE(data) AS Data
  FROM datalezione
  WHERE incarico_id IN (SELECT id FROM incarico WHERE attivita_id = :id)
  ORDER BY Data
");
$stmt->execute(['id'=>$id]);
$dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 3) Avvio Word via COM
$word = new COM("Word.Application") or exit("Cannot start Word via COM");
$word->Visible = false;

// 4) Loop su ciascuna data
$pdfFiles = [];
foreach ($dates as $i => $data) {
    // 4a) apri il template fresco
    $doc = $word->Documents->Open($templatePath);

    // 4b) valori da sostituire
    $values = [
      'IDCorso' => $att['IDCorso'],
      'Data'    => $data,
      'Sede'    => $att['Sede'],
      'Corso'   => $att['Corso'],
    ];
    // blocchi dipendente 1..35
    for ($j = 1; $j <= 35; $j++) {
        if (isset($dipendenti[$j-1])) {
            $d = $dipendenti[$j-1];
            $values["Nome$j"]    = $d['nome'];
            $values["Cognome$j"] = $d['cognome'];
            $values["natoA$j"]   = $d['natoA'];
            $values["natoIl$j"]  = $d['natoIl'];
            $values["CF$j"]      = $d['CF'];
            $values["Azienda$j"] = $d['Azienda'];
        } else {
            // campi vuoti
            foreach (['Nome','Cognome','natoA','natoIl','CF','Azienda'] as $f) {
                $values["{$f}$j"] = '';
            }
        }
    }

    // 4c) Find&Replace
    foreach ($values as $key => $val) {
        $findText        = '${' . $key . '}';
        $doc->Content->Find->Text        = $findText;
        $doc->Content->Find->Replacement->Text = $val;
        // wdReplaceAll = 2
        $doc->Content->Find->Execute(
          false,false,false,false,
          false,false,true,1,true,$val,2
        );
    }

    // 4d) salva .docx e .pdf
    $docxFile = "{$tmpDocxDir}registro_{$i}.docx";
    $pdfFile  = "{$tmpPdfDir}registro_{$i}.pdf";

    // wdFormatDocumentDefault = 16
    $doc->SaveAs($docxFile, 16);
    // wdExportFormatPDF = 17
    $doc->ExportAsFixedFormat($pdfFile, 17);

    // chiudi questo documento
    $doc->Close(false);
    $pdfFiles[] = $pdfFile;
}

// 5) Chiudo Word
$word->Quit();

// 6) Unisco i PDF con FPDI
$merged = new Fpdi();
foreach ($pdfFiles as $file) {
    $pages = $merged->setSourceFile($file);
    for ($p = 1; $p <= $pages; $p++) {
        $tpl = $merged->importPage($p);
        $s   = $merged->getTemplateSize($tpl);
        $merged->AddPage($s['orientation'], [$s['width'],$s['height']]);
        $merged->useTemplate($tpl);
    }
}
$merged->Output($outputPdf, 'F');

// 7) Mando in download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="registro_unificato.pdf"');
readfile($outputPdf);

// 8) Pulizia
foreach (array_merge($pdfFiles, glob($tmpDocxDir.'*.docx')) as $f) {
    @unlink($f);
}
exit;
