<?php
include 'init.php';
if (session_status()===PHP_SESSION_NONE) session_start();

if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function csrf_ok(){return hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'');}
function titlecase($s){ $s=mb_strtolower(trim($s),'UTF-8'); return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8'); }
function isValidCF(string $cf): bool {
  $cf=strtoupper($cf);
  if(!preg_match('/^[A-Z]{6}\d{2}[A-EHLMPRST]\d{2}[A-Z]\d{3}[A-Z]$/',$cf)) return false;
  $odd=['0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,'K'=>2,'L'=>4,'M'=>18,'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23];
  $even=['0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,'I'=>8,'J'=>9,'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,'Q'=>16,'R'=>17,'S'=>18,'T'=>19,'U'=>20,'V'=>21,'W'=>22,'X'=>23,'Y'=>24,'Z'=>25];
  $sum=0; for($i=0;$i<15;$i++){ $c=$cf[$i]; $sum+=$i%2===0?$odd[$c]:$even[$c]; }
  return $cf[15]===chr(65+($sum%26));
}

/* -------- AJAX: single -------- */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='get_dip'){
  header('Content-Type: application/json');
  $id=$_POST['id']??'';
  $st=$pdo->prepare("
    SELECT d.id,d.nome,d.cognome,d.codice_fiscale,d.datanascita,d.luogonascita,
           d.comuneresidenza,d.viaresidenza,d.mansione,
           s.id sede_id, a.id azienda_id
    FROM dipendente d
    LEFT JOIN dipendente_sede ds ON ds.dipendente_id=d.id
    LEFT JOIN sede s ON s.id=ds.sede_id
    LEFT JOIN azienda a ON a.id=s.azienda_id
    WHERE d.id=?");
  $st->execute([$id]);
  echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: []);
  exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='save_dip'){
  header('Content-Type: application/json');
  if(!csrf_ok()){echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit;}
  $id=trim($_POST['id']??'');
  $nome=titlecase($_POST['nome']??'');
  $cogn=titlecase($_POST['cognome']??'');
  $cf=strtoupper(trim($_POST['codice_fiscale']??''));
  $dob=$_POST['datanascita']?:null;
  $ln=trim($_POST['luogonascita']??'');
  $com=trim($_POST['comuneresidenza']??'');
  $via=trim($_POST['viaresidenza']??'');
  $mans=trim($_POST['mansione']??'');
  $azienda_id=$_POST['azienda_id']??'';
  $sede_id=$_POST['sede_id']??'';
  if($nome===''||$cogn===''||!isValidCF($cf)||!$azienda_id||!$sede_id){echo json_encode(['ok'=>false,'msg'=>'Compila correttamente i campi obbligatori.']);exit;}
  $chk=$pdo->prepare("SELECT COUNT(*) FROM dipendente d
    JOIN dipendente_sede ds ON ds.dipendente_id=d.id
    JOIN sede s ON s.id=ds.sede_id
    WHERE d.codice_fiscale=? AND s.azienda_id=?".($id?' AND d.id<>?':''));
  $args=[$cf,$azienda_id]; if($id)$args[]=$id; $chk->execute($args);
  if((int)$chk->fetchColumn()>0){echo json_encode(['ok'=>false,'msg'=>'Codice fiscale già presente per questa azienda.']);exit;}
  try{
    $pdo->beginTransaction();
    if($id){
      $pdo->prepare("UPDATE dipendente SET nome=?,cognome=?,codice_fiscale=?,datanascita=?,luogonascita=?,comuneresidenza=?,viaresidenza=?,mansione=? WHERE id=?")
          ->execute([$nome,$cogn,$cf,$dob,$ln,$com,$via,$mans,$id]);
      $pdo->prepare("DELETE FROM dipendente_sede WHERE dipendente_id=?")->execute([$id]);
      $pdo->prepare("INSERT INTO dipendente_sede(dipendente_id,sede_id) VALUES(?,?)")->execute([$id,$sede_id]);
    }else{
      $id=bin2hex(random_bytes(16));
      $pdo->prepare("INSERT INTO dipendente(id,nome,cognome,codice_fiscale,datanascita,luogonascita,comuneresidenza,viaresidenza,mansione) VALUES (?,?,?,?,?,?,?,?,?)")
          ->execute([$id,$nome,$cogn,$cf,$dob,$ln,$com,$via,$mans]);
      $pdo->prepare("INSERT INTO dipendente_sede(dipendente_id,sede_id) VALUES(?,?)")->execute([$id,$sede_id]);
    }
    $pdo->commit(); echo json_encode(['ok'=>true]);
  }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['ok'=>false,'msg'=>'Errore tecnico: contatta l’amministrazione']); }
  exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='delete_dip'){
  header('Content-Type: application/json');
  if(!csrf_ok()){echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit;}
  $id=$_POST['id']??'';
  try{
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM dipendente_sede WHERE dipendente_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM dipendente WHERE id=?')->execute([$id]);
    $pdo->commit(); echo json_encode(['ok'=>true]);
  }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['ok'=>false,'msg'=>'Errore tecnico: contatta l’amministrazione']); }
  exit;
}

/* -------- AJAX: bulk -------- */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='bulk'){
  header('Content-Type: application/json');
  if(!csrf_ok()){echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit;}
  $action=$_POST['action']??''; $ids=array_filter(array_map('trim', explode(',', $_POST['ids']??'')));
  if(!$ids){echo json_encode(['ok'=>false,'msg'=>'Nessun dipendente selezionato.']); exit;}
  try{
    $pdo->beginTransaction();
    if($action==='delete'){
      $in=implode(',',array_fill(0,count($ids),'?'));
      $pdo->prepare("DELETE FROM dipendente_sede WHERE dipendente_id IN ($in)")->execute($ids);
      $pdo->prepare("DELETE FROM dipendente WHERE id IN ($in)")->execute($ids);
      $pdo->commit(); echo json_encode(['ok'=>true]); exit;
    }
    if($action==='change_company'){
      $azienda_id=$_POST['azienda_id']??''; if(!$azienda_id) throw new RuntimeException('Seleziona un’azienda.');
      $st=$pdo->prepare("SELECT id FROM sede WHERE azienda_id=? AND is_legale=1 LIMIT 1");
      $st->execute([$azienda_id]); $sede_legale=$st->fetchColumn();
      if(!$sede_legale) throw new RuntimeException('L’azienda scelta non ha una sede legale.');
      foreach($ids as $d){ $pdo->prepare('DELETE FROM dipendente_sede WHERE dipendente_id=?')->execute([$d]);
        $pdo->prepare('INSERT INTO dipendente_sede(dipendente_id,sede_id) VALUES(?,?)')->execute([$d,$sede_legale]); }
      $pdo->commit(); echo json_encode(['ok'=>true]); exit;
    }
    if($action==='change_seat'){
      $azienda_id=$_POST['azienda_id']??''; $sede_id=$_POST['sede_id']??'';
      if(!$azienda_id||!$sede_id) throw new RuntimeException('Seleziona azienda e sede.');
      foreach($ids as $d){ $pdo->prepare('DELETE FROM dipendente_sede WHERE dipendente_id=?')->execute([$d]);
        $pdo->prepare('INSERT INTO dipendente_sede(dipendente_id,sede_id) VALUES(?,?)')->execute([$d,$sede_id]); }
      $pdo->commit(); echo json_encode(['ok'=>true]); exit;
    }
    throw new RuntimeException('Azione non valida.');
  }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack();
    $msg=$e instanceof RuntimeException?$e->getMessage():'Errore tecnico: contatta l’amministrazione';
    echo json_encode(['ok'=>false,'msg'=>$msg]); }
  exit;
}

/* -------- Query elenco + dati filtri -------- */
$azienda_id=$_GET['azienda_id']??null; $sede_id=$_GET['sede_id']??null;

if($sede_id){
  $stmt=$pdo->prepare("SELECT s.nome sede_nome, a.ragionesociale azienda_nome FROM sede s JOIN azienda a ON a.id=s.azienda_id WHERE s.id=?");
  $stmt->execute([$sede_id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
  if(!$row){ header('Location:/biosound/aziende.php'); exit; }
  $contextName="{$row['azienda_nome']} ({$row['sede_nome']})";
}elseif($azienda_id){
  $stmt=$pdo->prepare("SELECT ragionesociale FROM azienda WHERE id=?");
  $stmt->execute([$azienda_id]); $nome=$stmt->fetchColumn();
  if(!$nome){ header('Location:/biosound/aziende.php'); exit; }
  $contextName=$nome;
}else $contextName='Tutti i dipendenti';

$params=[]; $sql="SELECT d.id,d.nome,d.cognome,d.codice_fiscale,a.id azienda_id,a.ragionesociale azienda,s.id sede_id,s.nome sede
                  FROM dipendente d
                  LEFT JOIN dipendente_sede ds ON ds.dipendente_id=d.id
                  LEFT JOIN sede s ON s.id=ds.sede_id
                  LEFT JOIN azienda a ON a.id=s.azienda_id";
if($sede_id){$sql.=" WHERE s.id=?";$params[]=$sede_id;}
elseif($azienda_id){$sql.=" WHERE a.id=?";$params[]=$azienda_id;}
$stmt=$pdo->prepare($sql." ORDER BY d.cognome,d.nome");
$stmt->execute($params);
$dipendenti=$stmt->fetchAll(PDO::FETCH_ASSOC);

$aziendeList=$pdo->query("SELECT id,ragionesociale FROM azienda ORDER BY ragionesociale")->fetchAll(PDO::FETCH_ASSOC);
$sediAll=$pdo->query("SELECT id,nome,azienda_id,is_legale FROM sede ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

/* navbar */
$role=$_SESSION['role']??'utente';
if($role==='admin') include 'navbar_a.php';
elseif($role==='dev') include 'navbar_d.php';
else include 'navbar.php';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Dipendenti</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>

  
body {
  --page-bg: #f0f2f5;
  --page-fg: #2e3a45;
  --page-muted: #6b7280;
  --page-pri: #66bb6a;
  --page-pri-d: #5aad5c;
  --page-dark: #5b6670;
  --page-radius: 8px;
  --page-shadow: 0 10px 30px rgba(0,0,0,.08);

  margin: 0;
  background: var(--page-bg);
  color: var(--page-fg);
  font: 16px system-ui, -apple-system, Segoe UI, Roboto;
}

* {
  box-sizing: border-box;
}


.container{max-width:1100px;margin:28px auto;padding:0 14px}
h1{text-align:center;margin:.2rem 0}
.context{color:#6b7280;text-align:center;margin-bottom:10px}

/* toolbar in una riga, centrata */
.toolbar{
  display:flex;
  align-items:center;
  justify-content:center;  /* tutto al centro */
  gap:.6rem;
  margin-bottom:.8rem;
}
.toolbar .right{display:flex;gap:.6rem}

input,select{
  height:36px;
  padding:.4rem .6rem;
  border:1px solid #d7dde3;
  background:#fff;
  border-radius:9px;
}

/* buttons */
.btn{
  display:inline-flex;
  align-items:center;
  gap:.45rem;
  padding:.5rem .95rem;
  border:0;
  border-radius:999px;
  color:#fff;
  font-weight:600;
  cursor:pointer;
  white-space:nowrap;
}
.btn-green{background:#66bb6a}
.btn-green:hover{background:#5aad5c}
.btn-grey{background:#6c757d}
.btn-grey:hover{opacity:.92}
.chkline{display:inline-flex;align-items:center;gap:.4rem}

/* dropdown */
.dropdown{position:relative}
.dd-btn{background:#6c757d}
.dd-menu{
  position:absolute;
  right:0;
  top:calc(100% + 6px);
  background:#fff;
  border:1px solid #e6e8eb;
  border-radius:10px;
  box-shadow:0 10px 30px rgba(0,0,0,.12);
  min-width:220px;
  display:none;
  z-index:50;
}
.dropdown.open .dd-menu{display:block}
.dd-item{
  padding:.55rem .8rem;
  display:flex;
  gap:.5rem;
  align-items:center;
  cursor:pointer;
}
.dd-item:hover{background:#f3f6f8}

/* list compact, long rows */
.list{display:flex;flex-direction:column;gap:.55rem}
.item{
  background:#fff;
  border-radius:14px;
  box-shadow:0 2px 10px rgba(0,0,0,.06);
  padding:.55rem .9rem;
  display:flex;
  align-items:center;
  justify-content:space-between;
  min-height:44px;
}
.item .left{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
.name{font-weight:700}
.badge{background:#eef2f7;border-radius:999px;padding:.1rem .5rem;color:#667}
.icon-btn{background:none;border:0;color:#4caf50;font-size:1.05rem;cursor:pointer}
.icon-btn:hover{opacity:.8}
.empty{color:#7a8691;text-align:center;padding:1.2rem}

/* modal (add/edit + bulk + import) */
.overlay{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.4);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:2000;
}
.overlay.open{display:flex}
.modal{
  background:#fff;
  border-radius:16px;
  box-shadow:0 20px 60px rgba(0,0,0,.25);
  width:min(820px,96vw);
  padding:16px;
}
.modal .head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:.4rem;
}
.modal .close{
  background:none;
  border:0;
  font-size:1.4rem;
  color:#888;
  cursor:pointer;
}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.form-row{display:flex;flex-direction:column;gap:.25rem}
.actions{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.6rem}
.modal-iframe{width:min(1000px,96vw)}
.modal-iframe .content{height:min(80vh,760px)}
.modal-iframe iframe{
  width:100%;
  height:100%;
  border:0;
  border-radius:12px;
  background:#fff;
}

</style>
</head>
<body>
<div class="container">
  <h1>Dipendenti</h1>
  <?php if(count($dipendenti)>0): ?><div class="context"><?=htmlspecialchars($contextName)?></div><?php endif; ?>

  <div class="toolbar">
    <input id="search" type="text" placeholder="Cerca CF, nome, cognome…">
    <select id="f-az"><option value="">Tutte le aziende</option><?php foreach($aziendeList as $a): ?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['ragionesociale'],ENT_QUOTES)?></option><?php endforeach; ?></select>
    <select id="f-se" disabled><option value="">Tutte le sedi</option></select>
    <label class="chkline"><input type="checkbox" id="sel-vis"> Seleziona visibili</label>
    <div class="right">
      <button class="btn btn-green" id="btn-add"><i class="bi bi-person-plus"></i> Aggiungi Dipendente</button>
      <button class="btn btn-grey" id="btn-import"><i class="bi bi-file-earmark-arrow-up"></i> Importa massivo</button>
      <div class="dropdown" id="bulk-dd">
        <button class="btn dd-btn"><i class="bi bi-list-ul"></i> Azioni massive</button>
        <div class="dd-menu">
          <div class="dd-item" data-act="company"><i class="bi bi-building"></i> Cambia azienda</div>
          <div class="dd-item" data-act="seat"><i class="bi bi-geo-alt"></i> Cambia sede</div>
          <div class="dd-item" data-act="delete"><i class="bi bi-trash"></i> Elimina selezionati</div>
        </div>
      </div>
    </div>
  </div>

  <div id="list" class="list">
    <?php if (empty($dipendenti)): ?>
      <div class="empty">Nessun risultato.</div>
    <?php else: foreach($dipendenti as $d): ?>
      <div class="item" data-id="<?=$d['id']?>" data-cf="<?=strtolower($d['codice_fiscale'])?>" data-az="<?=$d['azienda_id']?>" data-se="<?=$d['sede_id']?>" data-text="<?=strtolower($d['nome'].' '.$d['cognome'])?>">
        <div class="left">
          <input type="checkbox" class="rowchk">
          <span class="name"><?=htmlspecialchars($d['cognome'].' '.$d['nome'],ENT_QUOTES)?></span>
          <span class="badge"><?=htmlspecialchars($d['codice_fiscale'],ENT_QUOTES)?></span>
          <span class="badge"><?=htmlspecialchars($d['azienda']?:'—',ENT_QUOTES)?></span>
          <span class="badge"><?=htmlspecialchars($d['sede']?:'—',ENT_QUOTES)?></span>
        </div>
        <button class="icon-btn edit" title="Modifica"><i class="bi bi-pencil"></i></button>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Modal add/edit -->
<div class="overlay" id="modal">
  <div class="modal">
    <div class="head"><h3 id="mod-title">Aggiungi dipendente</h3><button class="close" data-close>&times;</button></div>
    <form id="dip-form">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>">
      <input type="hidden" name="id" id="f-id">
      <div class="form-grid">
        <div class="form-row"><label>Azienda *</label><select id="f-az-sel" name="azienda_id" required><option value="">Seleziona</option><?php foreach($aziendeList as $a): ?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['ragionesociale'],ENT_QUOTES)?></option><?php endforeach; ?></select></div>
        <div class="form-row"><label>Sede *</label><select id="f-se-sel" name="sede_id" required><option value="">Seleziona</option></select></div>
        <div class="form-row"><label>Nome *</label><input id="f-nome" name="nome" required></div>
        <div class="form-row"><label>Cognome *</label><input id="f-cognome" name="cognome" required></div>
        <div class="form-row"><label>Codice Fiscale *</label><input id="f-cf" name="codice_fiscale" maxlength="16" required></div>
        <div class="form-row"><label>Data di nascita</label><input type="date" id="f-dob" name="datanascita"></div>
        <div class="form-row"><label>Luogo di nascita</label><input id="f-ln" name="luogonascita"></div>
        <div class="form-row"><label>Comune di residenza</label><input id="f-com" name="comuneresidenza"></div>
        <div class="form-row"><label>Via di residenza</label><input id="f-via" name="viaresidenza"></div>
        <div class="form-row"><label>Mansione</label><input id="f-mans" name="mansione"></div>
      </div>
      <div class="actions">
        <button type="button" class="btn btn-grey" id="btn-del" style="display:none"><i class="bi bi-trash"></i> Elimina</button>
        <button type="button" class="btn btn-grey" data-close>Annulla</button>
        <button class="btn btn-green" type="submit"><i class="bi bi-save"></i> Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Import -->
<div class="overlay" id="popup-import">
  <div class="modal modal-iframe">
    <div class="head"><h3>Importa dipendenti (XLSX)</h3><button class="close" data-close>&times;</button></div>
    <div class="content"><iframe src="/biosound/importa_dipendenti.php"></iframe></div>
  </div>
</div>

<!-- Modal Bulk (company/seat/delete) -->
<div class="overlay" id="bulk-modal">
  <div class="modal" style="width:min(680px,96vw)">
    <div class="head"><h3 id="bulk-title">Azione massiva</h3><button class="close" data-close>&times;</button></div>
    <form id="bulk-form">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>">
      <input type="hidden" name="ajax" value="bulk">
      <input type="hidden" name="action" id="b-action">
      <input type="hidden" name="ids" id="b-ids">
      <div id="bulk-fields" class="form-grid" style="grid-template-columns:1fr 1fr"></div>
      <div class="actions">
        <button type="button" class="btn btn-grey" data-close>Annulla</button>
        <button class="btn btn-green" type="submit"><i class="bi bi-check2"></i> Conferma</button>
      </div>
    </form>
  </div>
</div>

<script>
const sediAll = <?= json_encode($sediAll, JSON_HEX_TAG) ?>;
const list = document.getElementById('list');
const rows = [...document.querySelectorAll('.item')];
const search = document.getElementById('search');
const fAz = document.getElementById('f-az');
const fSe = document.getElementById('f-se');

function fillSediSelect(sel, azId, withAll=true){
  sel.innerHTML = withAll?'<option value="">Tutte le sedi</option>':'<option value="">Seleziona</option>';
  if(!azId){ sel.disabled=true; return; }
  sediAll.forEach(s=>{ if(String(s.azienda_id)===String(azId)){ const o=document.createElement('option'); o.value=s.id; o.textContent=s.nome; sel.appendChild(o); }});
  sel.disabled=false;
}
fAz.addEventListener('change', ()=>{ fillSediSelect(fSe, fAz.value, true); applyFilters(); });
fSe.addEventListener('change', applyFilters);
search.addEventListener('input', applyFilters);

function applyFilters(){
  const txt=search.value.trim().toLowerCase();
  const az=fAz.value||null, se=fSe.value||null;
  let visible=0;
  rows.forEach(r=>{
    let ok=true;
    if(txt && !((r.dataset.text||'').includes(txt) || (r.dataset.cf||'').includes(txt))) ok=false;
    if(az && r.dataset.az!==az) ok=false;
    if(se && r.dataset.se!==se) ok=false;
    r.style.display = ok?'flex':'none';
    if(ok) visible++;
  });
  let empty=document.querySelector('.empty');
  if(visible===0){ if(!empty){empty=document.createElement('div'); empty.className='empty'; empty.textContent='Nessun risultato.'; list.appendChild(empty);} }
  else if(empty) empty.remove();
}

document.getElementById('sel-vis').addEventListener('change', e=>{
  const on=e.target.checked;
  rows.forEach(r=>{ if(r.style.display!=='none') r.querySelector('.rowchk').checked=on; });
});

/* ---------- Add/Edit modal ---------- */
const modal=document.getElementById('modal');
const toTitle=s=> (s||'').toLowerCase().replace(/\b\p{L}/gu,m=>m.toUpperCase());
function openModal(el){ el.classList.add('open'); }
function closeModal(el){ el.classList.remove('open'); }
[modal, document.getElementById('popup-import'), document.getElementById('bulk-modal')].forEach(ov=>{
  ov.addEventListener('click', e=>{ if(e.target===ov || e.target.dataset.close!==undefined) closeModal(ov); });
});

document.getElementById('btn-add').addEventListener('click', ()=>{
  document.getElementById('mod-title').textContent='Aggiungi dipendente';
  document.getElementById('dip-form').reset();
  document.getElementById('f-id').value='';
  document.getElementById('btn-del').style.display='none';
  document.getElementById('f-az-sel').value='';
  document.getElementById('f-se-sel').innerHTML='<option value="">Seleziona</option>';
  openModal(modal);
});
document.querySelectorAll('.edit').forEach(b=>{
  b.addEventListener('click', async e=>{
    const id=e.currentTarget.closest('.item').dataset.id;
    const fd=new FormData(); fd.append('ajax','get_dip'); fd.append('id',id);
    const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
    document.getElementById('mod-title').textContent='Modifica dipendente';
    ['id','nome','cognome','codice_fiscale','datanascita','luogonascita','comuneresidenza','viaresidenza','mansione']
      .forEach(k=>{ const m={'id':'f-id','nome':'f-nome','cognome':'f-cognome','codice_fiscale':'f-cf','datanascita':'f-dob','luogonascita':'f-ln','comuneresidenza':'f-com','viaresidenza':'f-via','mansione':'f-mans'}; document.getElementById(m[k]).value=j[k]||''; });
    document.getElementById('f-az-sel').value=j.azienda_id||'';
    fillSediSelect(document.getElementById('f-se-sel'), j.azienda_id||'', false);
    document.getElementById('f-se-sel').value=j.sede_id||'';
    document.getElementById('btn-del').style.display='inline-flex';
    openModal(modal);
  });
});
document.getElementById('f-az-sel').addEventListener('change', e=>{
  const sel=document.getElementById('f-se-sel'); fillSediSelect(sel, e.target.value, false);
});
function validCF(cf){
  cf=(cf||'').toUpperCase();
  if(!/^[A-Z]{6}\d{2}[A-EHLMPRST]\d{2}[A-Z]\d{3}[A-Z]$/.test(cf)) return false;
  const odd={'0':1,'1':0,'2':5,'3':7,'4':9,'5':13,'6':15,'7':17,'8':19,'9':21,'A':1,'B':0,'C':5,'D':7,'E':9,'F':13,'G':15,'H':17,'I':19,'J':21,'K':2,'L':4,'M':18,'N':20,'O':11,'P':3,'Q':6,'R':8,'S':12,'T':14,'U':16,'V':10,'W':22,'X':25,'Y':24,'Z':23};
  const even={'0':0,'1':1,'2':2,'3':3,'4':4,'5':5,'6':6,'7':7,'8':8,'9':9,'A':0,'B':1,'C':2,'D':3,'E':4,'F':5,'G':6,'H':7,'I':8,'J':9,'K':10,'L':11,'M':12,'N':13,'O':14,'P':15,'Q':16,'R':17,'S':18,'T':19,'U':20,'V':21,'W':22,'X':23,'Y':24,'Z':25};
  let s=0; for(let i=0;i<15;i++){ const c=cf[i]; s += (i%2===0?odd[c]:even[c]); }
  return cf[15]===String.fromCharCode(65+(s%26));
}
document.getElementById('dip-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const f=e.target; f.nome.value=toTitle(f.nome.value); f.cognome.value=toTitle(f.cognome.value);
  if(!validCF(f.codice_fiscale.value)){ alert('Codice fiscale non valido'); return; }
  const fd=new FormData(f); fd.append('ajax','save_dip');
  const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
  if(!j.ok){ alert(j.msg||'Errore tecnico: contatta l’amministrazione'); return; }
  location.reload();
});
document.getElementById('btn-del').addEventListener('click', async ()=>{
  if(!confirm('Eliminare definitivamente questo dipendente?')) return;
  const fd=new FormData(); fd.append('ajax','delete_dip'); fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>'); fd.append('id',document.getElementById('f-id').value);
  const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
  if(!j.ok){ alert(j.msg||'Errore tecnico: contatta l’amministrazione'); return; }
  location.reload();
});

/* ---------- Import popup ---------- */
const imp=document.getElementById('popup-import');
document.getElementById('btn-import').addEventListener('click', ()=> openModal(imp));

/* ---------- Bulk actions: dropdown + popup ---------- */
const dd=document.getElementById('bulk-dd');
dd.querySelector('.dd-btn').addEventListener('click', ()=> dd.classList.toggle('open'));
document.addEventListener('click', e=>{ if(!dd.contains(e.target)) dd.classList.remove('open'); });
function selectedIds(){ return [...document.querySelectorAll('.item')].filter(r=>r.style.display!=='none' && r.querySelector('.rowchk').checked).map(r=>r.dataset.id); }

const bulkModal=document.getElementById('bulk-modal');
const bulkFields=document.getElementById('bulk-fields');
const bulkTitle=document.getElementById('bulk-title');
const bAction=document.getElementById('b-action');
const bIds=document.getElementById('b-ids');
function fillSedi(sel, az){ sel.innerHTML='<option value="">Seleziona sede</option>'; sediAll.forEach(s=>{ if(String(s.azienda_id)===String(az)){ const o=document.createElement('option'); o.value=s.id; o.textContent=s.nome; sel.appendChild(o);} }); }

dd.querySelectorAll('.dd-item').forEach(it=>{
  it.addEventListener('click', ()=>{
    dd.classList.remove('open');
    const ids=selectedIds();
    if(ids.length===0){ alert('Seleziona almeno un dipendente (puoi usare “Seleziona visibili”).'); return; }
    bulkFields.innerHTML=''; bIds.value=ids.join(',');

    if(it.dataset.act==='company'){
      bulkTitle.textContent='Cambia azienda (sede legale automatica)';
      bAction.value='change_company';
      const az=document.createElement('select'); az.name='azienda_id'; az.required=true;
      az.innerHTML='<option value="">Seleziona azienda</option>' + <?php
        $o=[]; foreach($aziendeList as $a){ $o[]='<option value="'.htmlspecialchars($a['id'],ENT_QUOTES).'">'.htmlspecialchars($a['ragionesociale'],ENT_QUOTES).'</option>'; }
        echo json_encode(implode('', $o));
      ?>;
      bulkFields.appendChild(Object.assign(document.createElement('div'),{className:'form-row',innerHTML:'<label>Azienda *</label>'})).appendChild(az);
      const info=document.createElement('div'); info.className='form-row'; info.innerHTML='<div style="color:#6b7280">Verrà impostata automaticamente la sede <b>LEGALE</b> della nuova azienda.</div>';
      bulkFields.appendChild(info);
    }

    if(it.dataset.act==='seat'){
      bulkTitle.textContent='Cambia sede';
      bAction.value='change_seat';
      const selRows=[...document.querySelectorAll('.item')].filter(r=>ids.includes(r.dataset.id));
      const setAz=new Set(selRows.map(r=>r.dataset.az)); const multi=setAz.size>1;
      const az=document.createElement('select'); az.name='azienda_id'; az.required=true;
      az.innerHTML='<option value="">Seleziona azienda</option>' + <?php echo json_encode(implode('', $o)); ?>;
      if(!multi) az.value=selRows[0].dataset.az;

      const se=document.createElement('select'); se.name='sede_id'; se.required=true;
      function fill(){ fillSedi(se, az.value); if(!multi) se.value=selRows[0].dataset.se; }
      az.addEventListener('change', fill); fill();

      const d1=Object.assign(document.createElement('div'),{className:'form-row',innerHTML:'<label>Azienda *</label>'});
      const d2=Object.assign(document.createElement('div'),{className:'form-row',innerHTML:'<label>Sede *</label>'});
      d1.appendChild(az); d2.appendChild(se); bulkFields.append(d1,d2);
      if(multi){ const info=document.createElement('div'); info.className='form-row'; info.style.gridColumn='1/-1'; info.innerHTML='<div style="color:#6b7280">Hai selezionato dipendenti di aziende diverse: scegli azienda e sede di destinazione.</div>'; bulkFields.appendChild(info); }
    }

    if(it.dataset.act==='delete'){
      bulkTitle.textContent='Elimina selezionati';
      bAction.value='delete';
      const d=Object.assign(document.createElement('div'),{className:'form-row',style:'grid-column:1/-1'});
      d.innerHTML='<div>Verranno eliminati <b>'+ids.length+'</b> dipendente/i. Confermare?</div>';
      bulkFields.appendChild(d);
    }

    openModal(bulkModal);
  });
});
document.getElementById('bulk-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const fd=new FormData(e.target);
  const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
  if(!j.ok){ alert(j.msg||'Errore tecnico: contatta l’amministrazione'); return; }
  location.reload();
});

/* import open */
document.getElementById('btn-import').addEventListener('click', ()=> openModal(document.getElementById('popup-import')));
</script>
</body>
</html>
