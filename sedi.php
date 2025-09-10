<?php
// sedi.php — unico file: lista sedi per azienda + modali add/edit/delete
include 'init.php';
if (session_status()===PHP_SESSION_NONE) session_start();

if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function csrf_ok(){return hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'');}
function titlecase($s){ $s=mb_strtolower(trim($s),'UTF-8'); return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8'); }
function clean_str($s){ return trim($s??''); }

/* ---------- Parametri ---------- */
$azienda_id=$_GET['azienda_id']??'';
if($azienda_id===''){ header('Location: ./aziende.php'); exit; }

/* ---------- Dati azienda ---------- */
$st=$pdo->prepare('SELECT id, ragionesociale FROM azienda WHERE id=?');
$st->execute([$azienda_id]);
$azienda=$st->fetch(PDO::FETCH_ASSOC);
if(!$azienda){ header('Location: ./aziende.php'); exit; }
$azienda_nome=$azienda['ragionesociale'];

/* ---------- AJAX ---------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $ajax=$_POST['ajax']??'';

  if($ajax==='get_sede'){
    header('Content-Type: application/json');
    $id=$_POST['id']??'';
    $st=$pdo->prepare('SELECT id,nome,indirizzo,azienda_id,is_legale FROM sede WHERE id=?');
    $st->execute([$id]);
    echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: []);
    exit;
  }

  if($ajax==='save_sede'){
    header('Content-Type: application/json');
    if(!csrf_ok()){ echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit; }

    $id=clean_str($_POST['id']??'');
    $nome=titlecase($_POST['nome']??'');
    $indirizzo=clean_str($_POST['indirizzo']??'');
    $az_id=clean_str($_POST['azienda_id']??'');

    if($az_id==='' || $az_id!==$azienda_id || $nome==='' || $indirizzo===''){
      echo json_encode(['ok'=>false,'msg'=>'Compila correttamente i campi obbligatori.']); exit;
    }

    try{
      if($id){
        $pdo->prepare('UPDATE sede SET nome=?, indirizzo=? WHERE id=? AND azienda_id=?')
            ->execute([$nome,$indirizzo,$id,$azienda_id]);
      }else{
        $id=bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO sede(id,nome,indirizzo,azienda_id) VALUES(?,?,?,?)')
            ->execute([$id,$nome,$indirizzo,$azienda_id]);
      }
      echo json_encode(['ok'=>true]);
    }catch(Throwable $e){
      echo json_encode(['ok'=>false,'msg'=>'Errore tecnico']);
    }
    exit;
  }

  if($ajax==='delete_sede'){
    header('Content-Type: application/json');
    if(!csrf_ok()){ echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit; }
    $id=clean_str($_POST['id']??'');
    if($id===''){ echo json_encode(['ok'=>false,'msg'=>'ID mancante.']); exit; }

    try{
      $st=$pdo->prepare('SELECT is_legale FROM sede WHERE id=? AND azienda_id=?');
      $st->execute([$id,$azienda_id]);
      $is_legale=(int)$st->fetchColumn();
      if($is_legale===1){ echo json_encode(['ok'=>false,'msg'=>'Non è possibile eliminare la sede LEGALE.']); exit; }

      $pdo->prepare('DELETE FROM sede WHERE id=? AND azienda_id=?')->execute([$id,$azienda_id]);
      echo json_encode(['ok'=>true]);
    }catch(Throwable $e){
      echo json_encode(['ok'=>false,'msg'=>'Impossibile eliminare la sede perché è in uso.']);
    }
    exit;
  }
}

/* ---------- Query elenco sedi ---------- */
$st=$pdo->prepare('SELECT id,nome,indirizzo,is_legale FROM sede WHERE azienda_id=? ORDER BY nome');
$st->execute([$azienda_id]);
$sedi=$st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Navbar ---------- */
$role=$_SESSION['role']??'utente';
if($role==='admin')      include 'navbar_a.php';
elseif($role==='dev')    include 'navbar_d.php';
else                     include 'navbar.php';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Sedi — <?= htmlspecialchars($azienda_nome,ENT_QUOTES) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
  --bg: #f0f2f5;
  --fg: #2e3a45;
  --muted: #6b7280;
  --pri: #66bb6a;
  --pri-d: #5aad5c;
  --sec: #6c757d;
  --sec-d: #5a6268;
  --danger: #dc3545;
  --danger-d: #b52a37;
  --radius: 10px;
  --shadow: 0 6px 18px rgba(0,0,0,.08);
  margin: 0; background: var(--bg); color: var(--fg);
  font: 16px system-ui,-apple-system,Segoe UI,Roboto;
}
.container{max-width:900px;margin:28px auto;padding:0 14px}
h1{text-align:center;margin:.2rem 0}
.context{color:var(--muted);text-align:center;margin-bottom:12px}

/* toolbar */
.toolbar{display:flex;align-items:center;justify-content:center;gap:.6rem;margin-bottom:1rem;flex-wrap:wrap}
#search{width:360px;padding:.45rem .7rem;border:1px solid #d7dde3;border-radius:var(--radius)}

/* buttons */
.btn{
  display:inline-flex;align-items:center;gap:.45rem;
  padding:.55rem 1rem;border:0;border-radius:var(--radius);
  color:#fff;font-weight:600;cursor:pointer;white-space:nowrap;
  transition:background .2s,transform .15s;
}
.btn-green{background:var(--pri)} .btn-green:hover{background:var(--pri-d);transform:translateY(-2px)}
.btn-grey{background:var(--sec)} .btn-grey:hover{background:var(--sec-d);transform:translateY(-2px)}
.btn-red{background:var(--danger)} .btn-red:hover{background:var(--danger-d);transform:translateY(-2px)}

.icon-btn{background:none;border:0;color:var(--pri);font-size:1.1rem;cursor:pointer}
.icon-btn:hover{opacity:.8}
.badge{background:#eef2f7;border-radius:999px;padding:.15rem .6rem;color:#667;font-size:.8rem}

/* list */
.list{display:flex;flex-direction:column;gap:.55rem}
.item{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);padding:.65rem .9rem;display:flex;align-items:center;justify-content:space-between}
.item .left{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.name{font-weight:700}
.addr{color:#607d8b;font-size:.9rem}

/* modals */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:2000}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:var(--radius);box-shadow:0 20px 60px rgba(0,0,0,.25);width:min(480px,94vw);padding:20px}
.modal .head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem}
.modal .close{background:none;border:0;font-size:1.4rem;color:#888;cursor:pointer}
.form-row{display:flex;flex-direction:column;gap:.25rem;margin-bottom:.75rem}
.form-row label{font-weight:500;font-size:.95rem}
.form-row input{padding:.5rem .7rem;border:1px solid #ccc;border-radius:var(--radius);font:inherit;width:100%}
.actions{display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem}

/* Toast */
.toast{
  position:fixed;left:16px;bottom:16px;z-index:3000;
  background:var(--pri);color:#fff;padding:.6rem .9rem;border-radius:var(--radius);
  box-shadow:0 8px 24px rgba(0,0,0,.2);display:none;align-items:center;gap:.5rem
}
</style>
</head>
<body>
<div class="container">
  <h1>Sedi</h1>
  <div class="context"><?= htmlspecialchars($azienda_nome,ENT_QUOTES) ?></div>

  <div class="toolbar">
    <input id="search" type="text" placeholder="Cerca nome o indirizzo…">
    <div class="right">
      <button class="btn btn-green" id="btn-add"><i class="bi bi-geo-alt"></i> Aggiungi Sede</button>
    </div>
  </div>

  <div id="list" class="list">
    <?php if (empty($sedi)): ?>
      <div class="item"><div class="left"><span class="addr">Nessuna sede registrata.</span></div></div>
    <?php else: foreach($sedi as $s): ?>
      <div class="item"
           data-id="<?=$s['id']?>"
           data-text="<?=strtolower(($s['nome']??'').' '.($s['indirizzo']??''))?>">
        <div class="left">
          <span class="name"><?=htmlspecialchars($s['nome'],ENT_QUOTES)?></span>
          <?php if((int)$s['is_legale']===1): ?><span class="badge">LEGALE</span><?php endif; ?>
          <span class="addr"><?=htmlspecialchars($s['indirizzo']?:'—',ENT_QUOTES)?></span>
        </div>
        <div class="right" style="display:flex;gap:.4rem;align-items:center">
          <a class="icon-btn" title="Dipendenti della sede" href="./dipendenti.php?sede_id=<?=urlencode($s['id'])?>"><i class="bi bi-people"></i></a>
          <button class="icon-btn edit" title="Modifica"><i class="bi bi-pencil"></i></button>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div id="toast" class="toast"><i class="bi bi-check-circle"></i><span>Operazione completata</span></div>

<div class="overlay" id="modal">
  <div class="modal">
    <div class="head"><h3 id="mod-title">Aggiungi sede</h3><button class="close" data-close>&times;</button></div>
    <form id="sede-form">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>">
      <input type="hidden" name="id" id="f-id">
      <input type="hidden" name="azienda_id" value="<?=htmlspecialchars($azienda_id,ENT_QUOTES)?>">
      <div class="form-row">
        <label>Nome *</label>
        <input id="f-nome" name="nome" required>
      </div>
      <div class="form-row">
        <label>Indirizzo *</label>
        <input id="f-ind" name="indirizzo" required>
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
function applyFilters(){
  const txt = search.value.trim().toLowerCase();
  rows.forEach(r=>{
    const ok = !txt || (r.dataset.text||'').includes(txt);
    r.style.display = ok?'flex':'none';
  });
}
search.addEventListener('input', applyFilters);

const modal=document.getElementById('modal');
function openModal(){ modal.classList.add('open'); }
function closeModal(){ modal.classList.remove('open'); }
modal.addEventListener('click', e=>{ if(e.target===modal || e.target.dataset.close!==undefined) closeModal(); });

document.getElementById('btn-add').addEventListener('click', ()=>{
  document.getElementById('mod-title').textContent='Aggiungi sede';
  document.getElementById('sede-form').reset();
  document.getElementById('f-id').value='';
  document.getElementById('btn-del').style.display='none';
  openModal();
});

document.querySelectorAll('.edit').forEach(b=>{
  b.addEventListener('click', async e=>{
    const id=e.currentTarget.closest('.item').dataset.id;
    const fd=new FormData(); fd.append('ajax','get_sede'); fd.append('id',id);
    const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
    document.getElementById('mod-title').textContent='Modifica sede';
    document.getElementById('f-id').value=j.id||'';
    document.getElementById('f-nome').value=j.nome||'';
    document.getElementById('f-ind').value=j.indirizzo||'';
    const isLeg = String(j.is_legale||'0')==='1';
    document.getElementById('btn-del').style.display = isLeg ? 'none' : 'inline-flex';
    openModal();
  });
});

document.getElementById('sede-form').addEventListener('submit', async e=>{
  e.preventDefault();
  const f=e.target;
  const fd=new FormData(f); fd.append('ajax','save_sede');
  const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
  if(!j.ok){ alert(j.msg||'Errore'); return; }
  const u=new URL(location.href); u.searchParams.set('ok','1'); location.href=u.toString();
});

document.getElementById('btn-del').addEventListener('click', async ()=>{
  if(!confirm('Eliminare definitivamente questa sede?')) return;
  const fd=new FormData();
  fd.append('ajax','delete_sede');
  fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>');
  fd.append('id',document.getElementById('f-id').value);
  const j=await (await fetch(location.href,{method:'POST',body:fd})).json();
  if(!j.ok){ alert(j.msg||'Errore'); return; }
  const u=new URL(location.href); u.searchParams.set('ok','1'); location.href=u.toString();
});

(function(){
  const params=new URLSearchParams(location.search);
  if(params.get('ok')==='1'){
    const t=document.getElementById('toast');
    t.style.display='flex';
    setTimeout(()=>{ t.style.transition='opacity .4s'; t.style.opacity='0'; }, 2200);
    setTimeout(()=>{ t.style.display='none'; }, 2800);
    params.delete('ok');
    const u=new URL(location.href); u.search = params.toString();
    history.replaceState(null,'',u.toString());
  }
})();
</script>
</body>
</html>
