<?php
// attivita_chiusa.php — vista read-only di un'attività chiusa, con riapertura e link attestati + docenti per data
require_once __DIR__ . '/init.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$id = $_GET['id'] ?? null;
if (!$id) {
  header('Location: /biosound/attivitae_chiuse.php');
  exit;
}

/* =========================
   Dati attività + corso + operatori
========================= */
$actStmt = $pdo->prepare(<<<'SQL'
  SELECT a.*,
         c.titolo AS corso_titolo, c.durata AS corso_durata_ore,
         c.categoria AS corso_categoria, c.modalita AS corso_modalita_cod,
         c.validita AS corso_validita_anni,
         o1.nome AS rich_nome,  o1.cognome AS rich_cognome,
         o2.nome AS tutor_nome, o2.cognome AS tutor_cognome
  FROM attivita a
  JOIN corso c      ON c.id = a.corso_id
  LEFT JOIN operatore o1 ON o1.id = a.richiedente_id
  LEFT JOIN operatore o2 ON o2.id = a.tutor_id
  WHERE a.id = ?
SQL);
$actStmt->execute([$id]);
$act = $actStmt->fetch(PDO::FETCH_ASSOC);
if (!$act) {
  header('Location: /biosound/attivitae_chiuse.php?notfound=1');
  exit;
}

/* =========================
   Lezioni (minuti = end-start) + docenti per ciascuna lezione
   (docenti presi da docenteincarico per l'incarico della lezione)
========================= */
$lezStmt = $pdo->prepare(<<<'SQL'
  SELECT
    dl.id,
    dl.data,
    TIME_FORMAT(dl.oraInizio, '%H:%i') AS start,
    TIME_FORMAT(dl.oraFine,   '%H:%i') AS end,
    GREATEST(TIMESTAMPDIFF(MINUTE, dl.oraInizio, dl.oraFine), 0) AS minuti,
    COALESCE(
      GROUP_CONCAT(DISTINCT CONCAT(d.cognome,' ',d.nome) ORDER BY d.cognome, d.nome SEPARATOR ', '),
      ''
    ) AS docenti
  FROM incarico i
  JOIN datalezione dl       ON dl.incarico_id = i.id
  LEFT JOIN docenteincarico dic ON dic.incarico_id = i.id
  LEFT JOIN docente d            ON d.id = dic.docente_id
  WHERE i.attivita_id = ?
  GROUP BY dl.id, dl.data, dl.oraInizio, dl.oraFine
  ORDER BY dl.data, dl.oraInizio
SQL);
$lezStmt->execute([$id]);
$lezioni = $lezStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   Partecipanti con esito, minuti totali e (eventuale) attestato
========================= */
$partStmt = $pdo->prepare(<<<'SQL'
  SELECT d.id, d.nome, d.cognome, d.codice_fiscale,
         COALESCE(ae.superato, 0) AS superato,
         COALESCE(ae.minuti_totali, 0) AS minuti_totali,
         at.id   AS attestato_id,
         at.allegati AS attestato_allegati_json
  FROM attivita_dipendente ad
  JOIN dipendente d ON d.id = ad.dipendente_id
  LEFT JOIN attivita_esito ae
         ON ae.attivita_id = ad.attivita_id AND ae.dipendente_id = d.id
  LEFT JOIN attestato at
         ON at.attivita_id = ad.attivita_id AND at.dipendente_id = d.id
  WHERE ad.attivita_id = ?
  ORDER BY d.cognome, d.nome
SQL);
$partStmt->execute([$id]);
$partecipanti = $partStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   Presenze mappa [dipendente_id][datalezione_id] = true
========================= */
$presStmt = $pdo->prepare(<<<'SQL'
  SELECT dipendente_id, datalezione_id
  FROM lezione_presenza
  WHERE attivita_id = ?
SQL);
$presStmt->execute([$id]);
$presenze = [];
while ($r = $presStmt->fetch(PDO::FETCH_ASSOC)) {
  $presenze[$r['dipendente_id']][$r['datalezione_id']] = true;
}

/* =========================
   Helpers
========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDate($d){ if(!$d) return ''; $ts=strtotime($d); return $ts?date('d/m/Y',$ts):''; }
function labelModalita($m){ return $m; } // già descrittiva in attivita.modalita

// estrae percorso PDF dall'attestato: /resources/attestati/<attestato_id>/<stored>
function attestato_url(?string $attestatoId, ?string $json) : ?string {
  if (!$attestatoId || !$json) return null;
  $arr = json_decode($json, true);
  if (!is_array($arr) || empty($arr[0]['stored'])) return null;
  $stored = $arr[0]['stored'];
  return "/biosound/resources/attestati/{$attestatoId}/{$stored}";
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Attività <?= h($id) ?> (chiusa)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg:#f6f7fb; --fg:#2e3a45; --muted:#6c757d; --card:#fff; --radius:12px;
      --shadow:0 10px 30px rgba(0,0,0,.08); --ok:#198754; --err:#d9534f; --warn:#f0ad4e; --brand:#2e7d32;
      --btn:#2e7d32; --btn2:#6c757d;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--fg);font:14px system-ui,-apple-system,Segoe UI,Roboto}
    .container{max-width:1100px;margin:32px auto;padding:0 16px}
    .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;margin-bottom:16px}
    h1{margin:.2rem 0 1rem}
    .pill{display:inline-block;background:#eef3ff;border-radius:999px;padding:.25rem .6rem;font-weight:600;margin-right:.5rem}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .row{display:grid;grid-template-columns:200px 1fr;gap:12px;margin:.25rem 0}
    .label{color:var(--muted)}
    .muted{color:var(--muted)}
    .readonly{background:#f9fafb;border:1px dashed #e5e7eb;border-radius:8px;padding:10px}
    table{border-collapse:collapse;width:100%;border-radius:10px;overflow:hidden}
    th,td{border:1px solid #e8e8e8;padding:.6rem;text-align:center;vertical-align:top}
    th.left,td.left{text-align:left}
    .badge{display:inline-flex;align-items:center;gap:.35rem;border-radius:8px;padding:.2rem .5rem;font-weight:600}
    .badge-ok{background:#e9f7ef;color:#0a6b2b}
    .badge-no{background:#fdecea;color:#b11c1c}
    .small{font-size:.9rem}
    .smaller{font-size:.85rem}
    .actions{display:flex;gap:.6rem;justify-content:flex-end;margin-top:.5rem}
    .btn{display:inline-flex;align-items:center;gap:.5rem;border:0;padding:.55rem .9rem;border-radius:10px;color:#fff;cursor:pointer;text-decoration:none;font-weight:600}
    .btn-grey{background:var(--btn2)}
    .btn-green{background:var(--btn)}
    .header-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
    a.part-link{color:inherit;text-decoration:none;border-bottom:1px dotted transparent}
    a.part-link:hover{border-color:#aaa}
    .no-link{color:inherit;text-decoration:none;cursor:default}
  </style>
</head>
<body>
<?php
  $role = $_SESSION['role'] ?? 'utente';
  if ($role==='admin')    include 'navbar_a.php';
  elseif ($role==='dev')  include 'navbar_d.php';
  else                    include 'navbar.php';
?>
<div class="container">

  <!-- intestazione -->
  <div class="card">
    <div class="header-row">
      <div>
        <h1>Attività <?= h($id) ?> <span class="muted">(chiusa)</span></h1>
        <span class="pill"><?= h($act['corso_titolo']) ?></span>
        <span class="muted">Durata corso: <strong><?= (int)$act['corso_durata_ore'] ?></strong> ore</span>
      </div>
      <div class="actions">
        <a href="/biosound/attivitae_chiuse.php" class="btn btn-grey"><i class="bi bi-arrow-left"></i> Indietro</a>
        <a href="/biosound/apri_corso.php?id=<?= urlencode($id) ?>"
           class="btn btn-green"
           onclick="return confirm('Riaprire il corso? Verranno rimossi gli attestati e l’attività tornerà modificabile.');">
          <i class="bi bi-unlock"></i> Riapri corso
        </a>
      </div>
    </div>
  </div>

  <!-- dettagli attività (read-only) -->
  <div class="card">
    <h3 style="margin-top:0">Dettagli</h3>
    <div class="grid">
      <div class="readonly">
        <div class="row"><div class="label">Corso</div><div><?= h($act['corso_titolo']) ?></div></div>
        <div class="row"><div class="label">Modalità</div><div><?= h(labelModalita($act['modalita'])) ?></div></div>
        <div class="row"><div class="label">Richiedente</div><div><?= h(trim(($act['rich_cognome']??'').' '.($act['rich_nome']??''))) ?></div></div>
        <div class="row"><div class="label">Tutor</div><div><?= h(trim(($act['tutor_cognome']??'').' '.($act['tutor_nome']??''))) ?></div></div>
        <div class="row"><div class="label">Numero partecipanti</div><div><?= (int)$act['n_partecipanti'] ?></div></div>
      </div>
      <div class="readonly">
        <div class="row"><div class="label">Corso finanziato</div><div><?= $act['corsoFinanziato'] ? 'Sì' : 'No' ?></div></div>
        <?php if ($act['corsoFinanziato']): ?>
          <div class="row"><div class="label">Fondo</div><div><?= h($act['fondo'] ?? '') ?></div></div>
          <div class="row"><div class="label">Avviso</div><div><?= h($act['avviso'] ?? '') ?></div></div>
          <div class="row"><div class="label">CUP</div><div><?= h($act['cup'] ?? '') ?></div></div>
          <div class="row"><div class="label">Numero azione</div><div><?= h($act['numero_azione'] ?? '') ?></div></div>
        <?php endif; ?>
        <div class="row"><div class="label">Azienda/e</div><div><?= h($act['azienda'] ?? '') ?></div></div>
        <div class="row"><div class="label">Luogo</div><div><?= h($act['luogo'] ?? '') ?></div></div>
      </div>
    </div>
    <?php if (!empty($act['note'])): ?>
    <div class="readonly" style="margin-top:12px">
      <div class="label">Note</div>
      <div><?= nl2br(h($act['note'])) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- lezioni + docenti -->
  <div class="card">
    <h3 style="margin-top:0">Lezioni</h3>
    <?php if ($lezioni): ?>
      <table>
        <thead>
          <tr>
            <th class="left">Data</th>
            <th>Orario</th>
            <th>Durata</th>
            <th class="left">Docenti</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lezioni as $dl): ?>
            <tr>
              <td class="left"><?= fmtDate($dl['data']) ?></td>
              <td><?= h($dl['start'].' - '.$dl['end']) ?></td>
              <td><?= (int)$dl['minuti'] ?>′</td>
              <td class="left"><?= $dl['docenti'] ? h($dl['docenti']) : '<span class="muted smaller">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="muted">Nessuna lezione pianificata.</div>
    <?php endif; ?>
  </div>

  <!-- partecipanti + presenze + esito + link attestato -->
  <div class="card">
    <h3 style="margin-top:0">Partecipanti</h3>
    <div style="overflow:auto">
    <table>
      <thead>
        <tr>
          <th class="left">Partecipante</th>
          <?php foreach ($lezioni as $dl): ?>
            <th>
              <?= fmtDate($dl['data']) ?><br>
              <span class="small muted"><?= h($dl['start'].'-'.$dl['end']) ?><br>(<?= (int)$dl['minuti'] ?>′)</span>
              <?php if (!empty($dl['docenti'])): ?>
                <br><span class="small" style="display:inline-block;margin-top:.25rem"><?= h($dl['docenti']) ?></span>
              <?php endif; ?>
            </th>
          <?php endforeach; ?>
          <th>Totale (h)</th>
          <th>Ha superato</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($partecipanti as $p): ?>
          <?php
            $sumMin = (int)($p['minuti_totali'] ?? 0);
            $badge  = ((int)$p['superato'] === 1)
                      ? '<span class="badge badge-ok"><i class="bi bi-check2-circle"></i> Sì</span>'
                      : '<span class="badge badge-no"><i class="bi bi-x-circle"></i> No</span>';
            $attUrl = attestato_url($p['attestato_id'] ?? null, $p['attestato_allegati_json'] ?? null);
            $nameHtml = h($p['cognome'].' '.$p['nome']);
            if ($attUrl) {
              $nameHtml = '<a class="part-link" href="'.h($attUrl).'" target="_blank" title="Apri attestato">'.$nameHtml.' <i class="bi bi-filetype-pdf"></i></a>';
            }
          ?>
          <tr>
            <td class="left">
              <strong><?= $nameHtml ?></strong><br>
              <span class="muted small"><?= h($p['codice_fiscale']) ?></span>
            </td>
            <?php foreach ($lezioni as $dl): ?>
              <td>
                <?php if (!empty($presenze[$p['id']][$dl['id']])): ?>
                  <i class="bi bi-check2" title="Presente"></i>
                <?php else: ?>
                  <i class="bi bi-dash" title="Assente" style="color:#bbb"></i>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td><strong><?= number_format($sumMin/60, 1, ',', '.') ?></strong></td>
            <td><?= $badge ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="card" style="display:flex;justify-content:flex-end">
    <a href="/biosound/attivitae_chiuse.php" class="btn btn-grey">
      <i class="bi bi-arrow-left"></i> Torna all’elenco attività chiuse
    </a>
  </div>

</div>
</body>
</html>
