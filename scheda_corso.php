<?php
// scheda_corso.php — generazione PDF con tutor + richiedente
require __DIR__ . '/libs/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
include __DIR__ . '/init.php';

$id = $_GET['id'] ?? '';
if (!$id) {
    header('Location:/biosound/attivitae.php');
    exit;
}

// 1) Prendo attività + corso + richiedente + tutor
$sql = "
  SELECT
    a.*,
    c.titolo   AS corso_titolo,
    o.nome     AS rich_nome,
    o.cognome  AS rich_cognome,
    t.nome     AS tutor_nome,
    t.cognome  AS tutor_cognome
  FROM attivita a
  JOIN corso   c ON c.id   = a.corso_id
  LEFT JOIN operatore o ON o.id = a.richiedente_id
  LEFT JOIN operatore t ON t.id = a.tutor_id
  WHERE a.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$act = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$act) {
    header('Location:/biosound/attivitae.php');
    exit;
}

// 2) Programma lezioni (escludo l'E-Learning)
$rows = '';
if ($act['modalita'] !== 'E-Learning (FAD Asincrona)') {
    $lSql = "
      SELECT dl.data, dl.oraInizio, dl.oraFine, d.nome, d.cognome
        FROM datalezione dl
        JOIN incarico i          ON i.id = dl.incarico_id
        JOIN docenteincarico dc  ON dc.incarico_id = i.id
        JOIN docente d           ON d.id = dc.docente_id
       WHERE i.attivita_id = ?
       ORDER BY dl.data
    ";
    $lStmt = $pdo->prepare($lSql);
    $lStmt->execute([$id]);
    $lezioni = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($lezioni as $l) {
        $d   = date('d/m/Y', strtotime($l['data']));
        $s   = date('H:i',   strtotime($l['oraInizio']));
        $e   = date('H:i',   strtotime($l['oraFine']));
        $doc = htmlspecialchars("{$l['cognome']} {$l['nome']}");
        $rows .= "<tr>
          <td>{$d}</td>
          <td>{$s}</td>
          <td>{$e}</td>
          <td>{$doc}</td>
          <td><span class=\"checkbox\"></span></td>
          <td><span class=\"checkbox\"></span></td>
          <td></td>
        </tr>";
    }
}

$logoPath = __DIR__ . '/logo.png';
$logoData = '';
if (file_exists($logoPath)) {
    $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

// 3) Sostituisco i placeholder
$map = [
        '{{logo_data}}'       => $logoData,
    '{{id}}'              => htmlspecialchars($act['id']),
    '{{corso_titolo}}'    => htmlspecialchars($act['corso_titolo']),
    '{{n_partecipanti}}'  => $act['n_partecipanti'] > 0
                              ? $act['n_partecipanti']
                              : '____',
    '{{richiedente}}'     => !empty($act['rich_nome'])
                              ? htmlspecialchars("{$act['rich_cognome']} {$act['rich_nome']}")
                              : '—',
    '{{tutor}}'           => !empty($act['tutor_nome'])
                              ? htmlspecialchars("{$act['tutor_cognome']} {$act['tutor_nome']}")
                              : '—',
    '{{azienda}}'         => htmlspecialchars($act['azienda'] ?: '—'),
    '{{finanziato}}'      => $act['corsoFinanziato'] ? 'Sì' : 'No',
    '{{fondo}}'           => htmlspecialchars($act['fondo'] ?: '—'),
    '{{modalita}}'        => htmlspecialchars($act['modalita']),
    '{{sede}}'            => htmlspecialchars($act['luogo'] ?: '—'),
    '{{note}}'            => nl2br(htmlspecialchars($act['note'])),
    '{{#if has_lezioni}}' => $rows ? '' : '<!--',
    '{{/if}}'             => $rows ? '' : '-->',
    '{{lezioni_rows}}'    => $rows,
];

$template = file_get_contents(__DIR__ . '/template_scheda.html');
$html     = strtr($template, $map);

// 4) Genero PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4','portrait');
$dompdf->render();
$dompdf->stream("scheda_{$act['id']}.pdf", ['Attachment'=>false]);
exit;
