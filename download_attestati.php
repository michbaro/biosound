<?php
// download_attestati.php — genera uno ZIP con gli attestati dei partecipanti che hanno superato il corso
// Nuova regola nomi nel pacchetto: nomeabbreviato_Nome_Cognome_univoco.pdf
// Percorsi attesi dei PDF: ./resources/attestati/<attestato_id>/<stored>

require_once __DIR__ . '/init.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/* =========================
   Helpers
========================= */
function clean_token_for_filename(string $s): string {
  // Sostituisce spazi con underscore, rimuove caratteri non validi per filename,
  // riduce underscore ripetuti, toglie underscore ai bordi.
  $s = str_replace(['/', '\\'], ' ', (string)$s);
  $s = preg_replace('/\s+/u', '_', $s);                // spazi -> _
  $s = preg_replace('/[^\p{L}\p{N}\.\-_]/u', '', $s);   // lascia solo lettere/numeri . - _
  $s = preg_replace('/_+/', '_', $s);                   // collassa ___
  $s = trim($s, '_');
  return $s !== '' ? $s : 'file';
}

$id = $_GET['id'] ?? '';
if ($id === '') {
  http_response_code(302);
  header('Location: ./attivitae_chiuse.php');
  exit;
}

/* =========================
   Attività + corso (nome abbreviato)
========================= */
$actStmt = $pdo->prepare('
  SELECT a.id, a.chiuso,
         c.titolo AS corso_titolo,
         COALESCE(c.nomeabbreviato, "") AS nomeabbr
  FROM attivita a
  JOIN corso c ON c.id = a.corso_id
  WHERE a.id = ?
');
$actStmt->execute([$id]);
$act = $actStmt->fetch(PDO::FETCH_ASSOC);
if (!$act) {
  http_response_code(404);
  echo 'Attività non trovata.';
  exit;
}
if ((int)$act['chiuso'] !== 1) {
  http_response_code(400);
  echo 'L’attività non è chiusa: impossibile scaricare gli attestati.';
  exit;
}

$nomeAbbr = clean_token_for_filename($act['nomeabbr'] ?? '');
if ($nomeAbbr === '' || $nomeAbbr === 'file') {
  // fallback prudente se non valorizzato
  $nomeAbbr = 'corso';
}

/* =========================
   Partecipanti che hanno superato + attestati
========================= */
$partStmt = $pdo->prepare(<<<'SQL'
  SELECT
    d.id, d.nome, d.cognome, d.codice_fiscale,
    ae.superato,
    at.id          AS attestato_id,
    at.allegati    AS attestato_allegati_json,
    at.univoco     AS attestato_univoco
  FROM attivita_dipendente ad
  JOIN dipendente d       ON d.id = ad.dipendente_id
  JOIN attivita_esito ae  ON ae.attivita_id = ad.attivita_id AND ae.dipendente_id = d.id
  LEFT JOIN attestato at  ON at.attivita_id = ad.attivita_id AND at.dipendente_id = d.id
  WHERE ad.attivita_id = ? AND ae.superato = 1
  ORDER BY d.cognome, d.nome
SQL);
$partStmt->execute([$id]);
$rows = $partStmt->fetchAll(PDO::FETCH_ASSOC);

$files = [];
foreach ($rows as $r) {
  if (empty($r['attestato_id']) || empty($r['attestato_allegati_json'])) continue;
  $arr = json_decode($r['attestato_allegati_json'], true);
  if (!is_array($arr) || empty($arr[0]['stored'])) continue;

  $stored = $arr[0]['stored'];
  $abs = __DIR__ . '/resources/attestati/' . $r['attestato_id'] . '/' . $stored;
  if (!is_file($abs)) continue;

  $nome  = clean_token_for_filename($r['nome'] ?? '');
  $cogn  = clean_token_for_filename($r['cognome'] ?? '');
  $univ  = clean_token_for_filename($r['attestato_univoco'] ?? '');

  if ($nome === 'file') $nome = 'Nome';
  if ($cogn === 'file') $cogn = 'Cognome';
  if ($univ === 'file' || $univ === '') $univ = 'UNV'; // fallback nel raro caso di attestati storici

  $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION)) ?: 'pdf';

  // nomeabbreviato_Nome_Cognome_univoco.pdf
  $zipName = $nomeAbbr . '_' . $nome . '_' . $cogn . '_' . $univ . '.' . $ext;

  $files[] = ['abs' => $abs, 'zip' => $zipName];
}

if (empty($files)) {
  http_response_code(404);
  echo 'Nessun attestato disponibile per il download.';
  exit;
}

/* =========================
   Crea ZIP temporaneo
========================= */
$zipBase = 'attestati-' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $id);
$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipBase . '-' . bin2hex(random_bytes(6)) . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
  http_response_code(500);
  echo 'Impossibile creare l’archivio ZIP.';
  exit;
}

foreach ($files as $f) {
  $zip->addFile($f['abs'], $f['zip']);
}

$zip->close();

/* =========================
   Download
========================= */
$displayName = $zipBase . '.zip';
header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $displayName . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$fh = fopen($tmpZip, 'rb');
if ($fh) {
  while (!feof($fh)) {
    echo fread($fh, 8192);
  }
  fclose($fh);
}
@unlink($tmpZip);
exit;
