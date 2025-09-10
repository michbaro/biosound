<?php
include 'init.php';
if (session_status()===PHP_SESSION_NONE) session_start();

/* =======================
   CSRF + Helpers
======================= */
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function csrf_ok(){return hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'');}
function titlecase($s){ $s=mb_strtolower(trim($s),'UTF-8'); return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8'); }
function null_if_empty($s){ $s=trim((string)$s); return $s!==''?$s:null; }

/* =======================
   AJAX: get azienda (per edit)
======================= */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='get_az'){
  header('Content-Type: application/json');
  $id=$_POST['id']??'';
  $st=$pdo->prepare("SELECT id,ragionesociale,piva,ateco,email,sdi,legalerappresentante,nomereferente,emailreferente,numeroreferente,sedelegale_id FROM azienda WHERE id=?");
  $st->execute([$id]);
  $az=$st->fetch(PDO::FETCH_ASSOC) ?: [];
  if($az){
    // indirizzo sede legale (se c'è)
    $addr='';
    if(!empty($az['sedelegale_id'])){
      $q=$pdo->prepare("SELECT indirizzo FROM sede WHERE id=? LIMIT 1");
      $q->execute([$az['sedelegale_id']]);
      $addr=(string)$q->fetchColumn();
    } else {
      $q=$pdo->prepare("SELECT indirizzo FROM sede WHERE azienda_id=? AND is_legale=1 LIMIT 1");
      $q->execute([$id]);
      $addr=(string)$q->fetchColumn();
    }
    $az['indirizzo_legale']=$addr;
  }
  echo json_encode($az);
  exit;
}

/* =======================
   AJAX: save azienda (add/edit)
======================= */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='save_az'){
  header('Content-Type: application/json');
  if(!csrf_ok()){echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit;}

  $id=trim($_POST['id']??''); // vuoto = insert
  $rag=titlecase($_POST['ragionesociale']??'');
  $piva=preg_replace('/\D/','', $_POST['piva']??'');
  $ateco=trim($_POST['ateco']??'');
  $email=trim($_POST['email']??'');
  $sdi=trim($_POST['sdi']??'');
  $lr=titlecase($_POST['legalerappresentante']??'');
  $nref=titlecase($_POST['nomereferente']??'');
  $eref=trim($_POST['emailreferente']??'');
  $nrefnum=trim($_POST['numeroreferente']??'');
  $addr_leg=trim($_POST['indirizzo_legale']??'');

  if($rag==='' || !preg_match('/^\d{11}$/',$piva) || $addr_leg===''){
    echo json_encode(['ok'=>false,'msg'=>'Compila correttamente i campi obbligatori (Ragione sociale, P.IVA 11 cifre, Indirizzo sede legale).']);
    exit;
  }
  // duplicato P.IVA (escludi se stessa)
  $chk=$pdo->prepare('SELECT COUNT(*) FROM azienda WHERE piva=?'.($id?' AND id<>?':''));
  $args=$id?[$piva,$id]:[$piva];
  $chk->execute($args);
  if((int)$chk->fetchColumn()>0){ echo json_encode(['ok'=>false,'msg'=>'Partita IVA già esistente.']); exit; }

  try{
    $pdo->beginTransaction();

    if($id){
      // UPDATE azienda
      $pdo->prepare(<<<'SQL'
        UPDATE azienda
           SET ragionesociale=?, piva=?, ateco=?, email=?, sdi=?,
               legalerappresentante=?, nomereferente=?, emailreferente=?, numeroreferente=?
         WHERE id=?
      SQL)->execute([
        $rag,$piva, null_if_empty($ateco), null_if_empty($email), null_if_empty($sdi),
        null_if_empty($lr), null_if_empty($nref), null_if_empty($eref), null_if_empty($nrefnum),
        $id
      ]);

      // assicura sede legale esista e aggiorna indirizzo
      $sel=$pdo->prepare("SELECT sedelegale_id FROM azienda WHERE id=?");
      $sel->execute([$id]);
      $sid=$sel->fetchColumn();
      if($sid){
        $pdo->prepare("UPDATE sede SET nome='LEGALE', indirizzo=?, is_legale=1 WHERE id=?")->execute([$addr_leg,$sid]);
      }else{
        // crea sede legale se mancava
        $sid=bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE azienda SET sedelegale_id=? WHERE id=?")->execute([$sid,$id]);
        $pdo->prepare("INSERT INTO sede(id,azienda_id,nome,indirizzo,is_legale) VALUES(?,?,?,?,1)")
            ->execute([$sid,$id,'LEGALE',$addr_leg]);
      }

      $pdo->commit();
      echo json_encode(['ok'=>true,'redirect'=>'?ok=edited']); exit;

    }else{
      // INSERT azienda + sede legale
      $id=bin2hex(random_bytes(16));
      $sid=bin2hex(random_bytes(16));
      $pdo->prepare(<<<'SQL'
        INSERT INTO azienda(id,sedelegale_id,ragionesociale,piva,ateco,email,sdi,legalerappresentante,nomereferente,emailreferente,numeroreferente)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
      SQL)->execute([
        $id,$sid,$rag,$piva, null_if_empty($ateco), null_if_empty($email), null_if_empty($sdi),
        null_if_empty($lr), null_if_empty($nref), null_if_empty($eref), null_if_empty($nrefnum)
      ]);
      $pdo->prepare("INSERT INTO sede(id,azienda_id,nome,indirizzo,is_legale) VALUES(?,?,?,?,1)")
          ->execute([$sid,$id,'LEGALE',$addr_leg]);

      $pdo->commit();
      echo json_encode(['ok'=>true,'redirect'=>'?ok=added']); exit;
    }

  }catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    echo json_encode(['ok'=>false,'msg'=>'Errore tecnico: contatta l’amministrazione']);
  }
  exit;
}

/* =======================
   AJAX: delete azienda
======================= */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='delete_az'){
  header('Content-Type: application/json');
  if(!csrf_ok()){echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit;}
  $id=$_POST['id']??'';
  if(!$id){ echo json_encode(['ok'=>false,'msg'=>'ID mancante']); exit; }
  try{
    $pdo->beginTransaction();
    // prendi sedi dell'azienda
    $sedi=$pdo->prepare("SELECT id FROM sede WHERE azienda_id=?");
    $sedi->execute([$id]);
    $sidList=$sedi->fetchAll(PDO::FETCH_COLUMN);

    if($sidList){
      $in=implode(',',array_fill(0,count($sidList),'?'));
      // rimuovi link dipendenti-sedi
      $pdo->prepare("DELETE FROM dipendente_sede WHERE sede_id IN ($in)")->execute($sidList);
      // elimina sedi
      $pdo->prepare("DELETE FROM sede WHERE id IN ($in)")->execute($sidList);
    }
    // elimina azienda
    $pdo->prepare("DELETE FROM azienda WHERE id=?")->execute([$id]);

    $pdo->commit();
    echo json_encode(['ok'=>true,'redirect'=>'?ok=deleted']); 
  }catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    echo json_encode(['ok'=>false,'msg'=>'Impossibile eliminare questa azienda (verifica relazioni esistenti).']);
  }
  exit;
}

/* =======================
   Query elenco
======================= */
$aziende=$pdo->query("SELECT id,ragionesociale,piva FROM azienda ORDER BY ragionesociale")->fetchAll(PDO::FETCH_ASSOC);

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
<title>Aziende</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* Stile allineato a dipendenti.php */
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
* { box-sizing: border-box; }

.container{max-width:980px;margin:28px auto;padding:0 14px}
h1{text-align:center;margin:.2rem 0}
.context{color:#6b7280;text-align:center;margin-bottom:10px}

.toolbar{ display:flex; align-items:center; justify-content:center; gap:.6rem; margin-bottom:.8rem; flex-wrap:wrap; }
#search{ width:360px; }
input,select,textarea{ height:36px; padding:.4rem .6rem; border:1px solid #d7dde3; background:#fff; border-radius:9px; font: inherit; }

/* buttons */
.btn{ display:inline-flex; align-items:center; gap:.45rem; padding:.5rem .95rem; border:0; border-radius:999px; color:#fff; font-weight:600; cursor:pointer; white-space:nowrap; }
.btn-green{background:#66bb6a} .btn-green:hover{background:#5aad5c}
.btn-grey{background:#6c757d} .btn-grey:hover{opacity:.92}
.btn-red{background:#dc3545} .btn-red:hover{filter:brightness(.95)}
.icon-btn{background:none;border:0;color:#4caf50;font-size:1.05rem;cursor:pointer}
.icon-btn:hover{opacity:.8}
.badge{background:#eef2f7;border-radius:999px;padding:.1rem .5rem;color:#667}

/* list */
.list{display:flex;flex-direction:column;gap:.55rem}
.item{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.06);padding:.55rem .9rem;display:flex;align-items:center;justify-content:space-between;min-height:44px}
.item .left{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
.name{font-weight:700}
.empty{color:#7a8691;text-align:center;padding:1.2rem}

/* modals */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:2000}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:min(780px,96vw);padding:16px}
.modal .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem}
.modal .close{background:none;border:0;font-size:1.4rem;color:#888;cursor:pointer}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.form-row{display:flex;flex-direction:column;gap:.25rem}
.actions{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.6rem}
textarea{min-height:90px;}

/* Toast bottom-left */
.toast{
  position:fixed; left:16px; bottom:16px; z-index:3000;
  background:#66bb6a; color:#fff; padding:.6rem .85rem; border-radius:10px;
  box-shadow:0 10px 30px rgba(0,0,0,.2); display:none; align-items:center; gap:.5rem;
}
</style>
</head>
<body>
<div class="container">
  <h1>Aziende</h1>

  <div class="toolbar">
    <input id="search" type="text" placeholder="Cerca per ragione sociale o P.IVA…">
    <div class="right">
      <button class="btn btn-green" id="btn-add"><i class="bi bi-building-add"></i> Aggiungi Azienda</button>
    </div>
  </div>

  <div id="list" class="list">
    <?php if (empty($aziende)): ?>
      <div class="empty">Nessun risultato.</div>
    <?php else: foreach($aziende as $a): ?>
      <div class="item" data-id="<?=$a['id']?>" data-text="<?=strtolower($a['ragionesociale'].' '.$a['piva'])?>">
        <div class="left">
          <span class="name"><?=htmlspecialchars($a['ragionesociale'],ENT_QUOTES)?></span>
          <?php if(!empty($a['piva'])): ?><span class="badge">P.IVA <?=htmlspecialchars($a['piva'],ENT_QUOTES)?></span><?php endif; ?>
        </div>
        <div class="right" style="display:flex;gap:.35rem;align-items:center">
          <!-- Sedi: richiesta specifica → ./sedi.php?id=AZIENDA -->
          <a class="icon-btn" title="Sedi" href="./sedi.php?azienda_id=<?=urlencode($a['id'])?>"><i class="bi bi-geo-alt"></i></a>
          <!-- Modifica -->
          <button class="icon-btn edit" title="Modifica"><i class="bi bi-pencil"></i></button>
                    <a class="icon-btn" title="Scadenziario" href="./scadenziario.php?id=<?=urlencode($a['id'])?>"><i class="bi bi-calendar-check"></i></a>

        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Toast -->
<div id="toast" class="toast"><i class="bi bi-check-circle"></i><span>Operazione completata</span></div>

<!-- Modal add/edit -->
<div class="overlay" id="modal">
  <div class="modal">
    <div class="head"><h3 id="mod-title">Aggiungi azienda</h3><button class="close" data-close>&times;</button></div>
    <form id="az-form">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>">
      <input type="hidden" name="id" id="f-id">
      <div class="form-grid">
        <div class="form-row"><label>Ragione sociale *</label><input id="f-rag" name="ragionesociale" required></div>
        <div class="form-row"><label>Partita IVA (11 cifre) *</label><input id="f-piva" name="piva" maxlength="11" required pattern="\d{11}" title="11 cifre numeriche"></div>

        <div class="form-row"><label>Indirizzo sede legale *</label><input id="f-leg" name="indirizzo_legale" required></div>
        <div class="form-row"><label>SDI/PEC</label><input id="f-sdi" name="sdi"></div>

        <div class="form-row"><label>Email</label><input id="f-email" name="email" type="email"></div>
        <div class="form-row"><label>Codice ATECO</label><input id="f-ateco" name="ateco"></div>

        <div class="form-row"><label>Legale rappresentante</label><input id="f-lr" name="legalerappresentante"></div>
        <div class="form-row"><label>Nome referente</label><input id="f-nref" name="nomereferente"></div>

        <div class="form-row"><label>Email referente</label><input id="f-eref" name="emailreferente" type="email"></div>
        <div class="form-row"><label>Numero referente</label><input id="f-nrnum" name="numeroreferente"></div>
      </div>
      <div class="actions">
        <button type="button" class="btn btn-grey" data-close>Annulla</button>
        <button type="button" class="btn btn-red" id="btn-del" style="display:none"><i class="bi bi-trash"></i> Elimina</button>
        <button class="btn btn-green" type="submit"><i class="bi bi-save"></i> Salva</button>
      </div>
    </form>
  </div>
</div>

<script>
const list = document.getElementById('list');
const rows = [...document.querySelectorAll('.item')];
const search = document.getElementById('search');

search.addEventListener('input', ()=>{
  const q=search.value.trim().toLowerCase();
  let visible=0;
  rows.forEach(r=>{
    const ok=!q || (r.dataset.text||'').includes(q);
    r.style.display = ok?'flex':'none';
    if(ok) visible++;
  });
  let empty=document.querySelector('.empty');
  if(visible===0){ if(!empty){empty=document.createElement('div'); empty.className='empty'; empty.textContent='Nessun risultato.'; list.appendChild(empty);} }
  else if(empty) empty.remove();
});

/* ---------- Modal helpers ---------- */
const modal=document.getElementById('modal');
function openModal(){ modal.classList.add('open'); }
function closeModal(){ modal.classList.remove('open'); }
[modal].forEach(ov=> ov.addEventListener('click', e=>{ if(e.target===ov || e.target.dataset.close!==undefined) closeModal(); }));

/* ---------- Add ---------- */
document.getElementById('btn-add').addEventListener('click', ()=>{
  document.getElementById('mod-title').textContent='Aggiungi azienda';
  document.getElementById('az-form').reset();
  document.getElementById('f-id').value='';
  document.getElementById('btn-del').style.display='none';
  openModal();
});

/* ---------- Edit ---------- */
document.querySelectorAll('.edit').forEach(b=>{
  b.addEventListener('click', async e=>{
    const id=e.currentTarget.closest('.item').dataset.id;
    const fd=new FormData(); fd.append('ajax','get_az'); fd.append('id',id);
    const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
    document.getElementById('mod-title').textContent='Modifica azienda';
    const map={'id':'f-id','ragionesociale':'f-rag','piva':'f-piva','ateco':'f-ateco','email':'f-email','sdi':'f-sdi','legalerappresentante':'f-lr','nomereferente':'f-nref','emailreferente':'f-eref','numeroreferente':'f-nrnum'};
    Object.keys(map).forEach(k=>{ document.getElementById(map[k]).value=j?.[k]||''; });
    document.getElementById('f-leg').value=j?.indirizzo_legale||'';
    document.getElementById('btn-del').style.display='inline-flex';
    openModal();
  });
});

/* ---------- Save (add/edit) ---------- */
document.getElementById('az-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const f=e.target;
  // normalizza
  f.ragionesociale.value = (f.ragionesociale.value||'').trim();
  f.piva.value = (f.piva.value||'').replace(/\D+/g,'');
  if(!/^\d{11}$/.test(f.piva.value)){ alert('Inserisci una P.IVA valida (11 cifre)'); return; }
  if(!f.indirizzo_legale.value.trim()){ alert('Inserisci indirizzo della sede legale'); return; }

  const fd=new FormData(f); fd.append('ajax','save_az');
  const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
  if(!j.ok){ alert(j.msg||'Errore tecnico: contatta l’amministrazione'); return; }
  window.location.href = j.redirect || '?ok=edited';
});

/* ---------- Delete ---------- */
document.getElementById('btn-del').addEventListener('click', async ()=>{
  if(!confirm('Eliminare definitivamente questa azienda? Verranno rimosse anche le sue sedi e i collegamenti con i dipendenti.')) return;
  const fd=new FormData(); 
  fd.append('ajax','delete_az'); 
  fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>'); 
  fd.append('id', document.getElementById('f-id').value);
  const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
  if(!j.ok){ alert(j.msg||'Errore tecnico: contatta l’amministrazione'); return; }
  window.location.href = j.redirect || '?ok=deleted';
});

/* ---------- Toast auto ---------- */
(function(){
  const params=new URLSearchParams(location.search);
  const ok=params.get('ok');
  if(ok){
    const t=document.getElementById('toast');
    t.querySelector('span').textContent =
      ok==='added'  ? 'Azienda creata con successo'
    : ok==='edited' ? 'Azienda modificata con successo'
    : ok==='deleted'? 'Azienda eliminata con successo'
    : 'Operazione completata';
    t.style.display='flex';
    setTimeout(()=>{ t.style.transition='opacity .4s'; t.style.opacity='0'; }, 2400);
    setTimeout(()=>{ t.style.display='none'; }, 3000);
  }
})();
</script>
</body>
</html>
