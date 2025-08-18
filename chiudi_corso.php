<?php
// chiudi_corso.php — chiude un'attività e genera gli attestati (PDF) da template PDF (FPDI)
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/libs/vendor/autoload.php';

use setasign\Fpdi\Fpdi;

// ============ Util ============
function fmt_it_date_or_dt(?string $val): string {
  if (!$val) return '';
  $ts = strtotime($val);
  if (!$ts) return '';
  return date('d/m/Y', $ts);
}
function clean_filename(string $s): string {
  $s = str_replace(' ', '_', $s);
  return preg_replace('/[^A-Za-z0-9_\-]/', '', $s);
}
function text($s) {
  return utf8_decode((string)$s);
}

// ============ Parametri ============
$id = $_GET['id'] ?? '';
if ($id === '') {
  header('Location: /biosound/attivitae.php');
  exit;
}

// ============ Pagina conferma ============
if (!isset($_GET['confirm'])) {
  ?>
  <!DOCTYPE html>
  <html lang="it">
  <head>
    <meta charset="UTF-8">
    <title>Chiudi attività</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      :root{--bg:#f0f2f5;--fg:#2e3a45;--pri:#66bb6a;--err:#d9534f;--radius:10px;--shadow:0 10px 30px rgba(0,0,0,.08)}
      body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
      .wrap{max-width:680px;margin:10vh auto;padding:2rem;background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);text-align:center}
      .actions{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap}
      a.btn{display:inline-flex;align-items:center;gap:.5rem;border:0;padding:.7rem 1.1rem;border-radius:9px;text-decoration:none;color:#fff;cursor:pointer;font-weight:600}
      .btn-primary{background:var(--pri)} .btn-secondary{background:#6c757d}
    </style>
  </head>
  <body>
    <div class="wrap">
      <h1>Chiudere l’attività <code><?= htmlspecialchars($id,ENT_QUOTES) ?></code>?</h1>
      <p>Verranno generati gli <strong>attestati PDF</strong> per tutti i partecipanti.</p>
      <div class="actions">
        <a class="btn btn-secondary" href="/biosound/attivitae.php"><i class="bi bi-arrow-left"></i> Annulla</a>
        <a class="btn btn-primary" href="/biosound/chiudi_corso.php?id=<?= urlencode($id) ?>&confirm=1">
          <i class="bi bi-check2-circle"></i> Sì, chiudi e genera attestati
        </a>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// ============ Carica dati attività + corso ============
$attStmt = $pdo->prepare(<<<'SQL'
  SELECT
    a.id,
    a.corso_id,
    a.modalita,
    a.luogo,
    a.note,
    c.titolo AS corso_titolo,
    COALESCE(c.durata,0) AS durata,
    COALESCE(c.validita,0) AS validita_anni,
    COALESCE(c.normativa,'') AS normativa,
    MIN(dl.data) AS data_inizio,
    MAX(dl.data) AS data_fine
  FROM attivita a
  JOIN corso c ON c.id = a.corso_id
  LEFT JOIN incarico i ON i.attivita_id = a.id
  LEFT JOIN datalezione dl ON dl.incarico_id = i.id
  WHERE a.id = ?
  GROUP BY a.id, a.corso_id, a.modalita, a.luogo, a.note, c.titolo, c.durata, c.validita, c.normativa
SQL);
$attStmt->execute([$id]);
$att = $attStmt->fetch(PDO::FETCH_ASSOC);
if (!$att) {
  header('Location: /biosound/attivitae.php?notfound=1');
  exit;
}

// ============ Partecipanti ============
$partStmt = $pdo->prepare(<<<'SQL'
  SELECT d.id,d.nome,d.cognome,d.codice_fiscale,d.datanascita,d.luogonascita
  FROM attivita_dipendente ad
  JOIN dipendente d ON d.id = ad.dipendente_id
  WHERE ad.attivita_id = ?
  ORDER BY d.cognome, d.nome
SQL);
$partStmt->execute([$id]);
$partecipanti = $partStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$partecipanti) {
  header('Location: /biosound/attivitae.php?closed=0&msg=nopartecipanti');
  exit;
}

// ============ Template PDF ============
$templatePdf = __DIR__ . '/resources/templates/template_attestato.pdf';
if (!is_file($templatePdf)) {
  die('Template PDF non trovato: ' . htmlspecialchars($templatePdf));
}

// ============ Coordinate ============
$coords = [
  'titolo_corso'   => [62, 249],
  'normativa'      => [62, 270],
  'nominativo'     => [198, 344],
  'codice_fiscale' => [198, 368],
  'luogonascita'   => [198, 389],
  'datanascita'    => [198, 410],
  'durata'         => [148, 453],
  'modalita'       => [136, 475],
  'sede'           => [109, 497],
  'datainizio'     => [145, 518],
  'datafine'       => [134, 540],
  'datarilascio'   => [200, 740],
];

// ============ Generazione ============
$pdo->beginTransaction();
try {
  foreach ($partecipanti as $p) {
    $exists = $pdo->prepare('SELECT id FROM attestato WHERE attivita_id = ? AND dipendente_id = ? LIMIT 1');
    $exists->execute([$att['id'], $p['id']]);
    if ($exists->fetchColumn()) continue;

    $oggi = date('d/m/Y');
    $emissioneYmd = date('Y-m-d');
    $validitaAnni = (int)($att['validita_anni'] ?? 0);
    $baseScadYmd  = $att['data_fine'] ?: $emissioneYmd;
    $scadenzaYmd  = $validitaAnni > 0 ? date('Y-m-d', strtotime("+{$validitaAnni} years", strtotime($baseScadYmd))) : null;
    $scadenzaIT   = $scadenzaYmd ? fmt_it_date_or_dt($scadenzaYmd) : '—';

    $values = [
      'titolo_corso'   => $att['corso_titolo'] ?? '',
      'normativa'      => $att['normativa'] ?? '',
      'nominativo'     => trim(($p['nome'] ?? '').' '.($p['cognome'] ?? '')),
      'codice_fiscale' => $p['codice_fiscale'] ?? '',
      'luogonascita'   => $p['luogonascita'] ?? '',
      'datanascita'    => fmt_it_date_or_dt($p['datanascita'] ?? null),
      'durata'         => ($att['durata'] ?? 0).' ore',
      'modalita'       => $att['modalita'] ?? '',
      'sede'           => $att['luogo'] ?? '',
      'datainizio'     => fmt_it_date_or_dt($att['data_inizio'] ?? null),
      'datafine'       => fmt_it_date_or_dt($att['data_fine'] ?? null),
      'datarilascio'   => "Data rilascio: $oggi"
                         . ($validitaAnni>0 ? ", validità {$validitaAnni} anni; Scadenza: $scadenzaIT" : ''),
    ];

    $pdf = new Fpdi('P','pt','A4');
    $pdf->AddPage();
    $tpl = $pdf->setSourceFile($templatePdf);
    $pageId = $pdf->importPage(1);
    $pdf->useTemplate($pageId, 0, 0, 595.28, 841.89, true);

    $pdf->SetTextColor(0,0,0);

    foreach ($coords as $key => [$x,$y]) {
      $val = $values[$key] ?? '';
      if ($val === '') continue;

      if ($key === 'titolo_corso') { $pdf->SetFont('Helvetica','B',20); }
      elseif ($key === 'nominativo') { $pdf->SetFont('Helvetica','B',16); }
      else { $pdf->SetFont('Helvetica','',11); }

      $pdf->SetXY($x,$y);
      $pdf->Cell(0,12,text($val),0,0,'L');
    }

    $pdfBytes = $pdf->Output('S');
    $attestatoId = bin2hex(random_bytes(16));
    $filename = clean_filename($att['id']).'_'.clean_filename($p['codice_fiscale']).'_'.clean_filename($p['nome']).'_'.clean_filename($p['cognome']).'.pdf';
    $dir = __DIR__.'/resources/attestati/'.$attestatoId;
    if (!is_dir($dir)) mkdir($dir,0775,true);
    $pdfPath = $dir.'/'.$filename;
    file_put_contents($pdfPath,$pdfBytes);

    $filesMeta = [[
      'original' => "Attestato - {$att['corso_titolo']} - {$p['cognome']} {$p['nome']}.pdf",
      'stored'   => $filename,
      'size'     => strlen($pdfBytes),
      'mime'     => 'application/pdf',
    ]];

    $ins = $pdo->prepare("INSERT INTO attestato (id,dipendente_id,corso_id,attivita_id,data_emissione,data_scadenza,note,allegati) VALUES (?,?,?,?,?,?,?,?)");
    $ins->execute([$attestatoId,$p['id'],$att['corso_id'],$att['id'],$emissioneYmd,$scadenzaYmd,null,json_encode($filesMeta,JSON_UNESCAPED_UNICODE)]);
  }

  $pdo->prepare('UPDATE attivita SET chiuso=1 WHERE id=?')->execute([$att['id']]);
  $pdo->commit();
  header('Location: /biosound/attivitae_chiuse.php?closed=1');
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "<pre>Errore durante la chiusura:\n".htmlspecialchars($e->getMessage(),ENT_QUOTES)."</pre>";
  exit;
}
