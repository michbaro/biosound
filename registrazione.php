<?php
// ./registrazione.php — accessibile solo al ruolo “dev”
require_once __DIR__ . '/init.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'dev') {
    http_response_code(403);
    exit('Accesso negato.');
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = trim($_POST['user_id'] ?? '');
    $p  = $_POST['password'] ?? '';
    $r  = $_POST['role'] ?? '';

    if ($id !== '') {
        // UPDATE esistente
        if ($p === '' || $r === '') {
            $error = 'Compila tutti i campi.';
        } elseif (!in_array($r, ['dev','admin','utente'], true)) {
            $error = 'Ruolo non valido.';
        } else {
            $hash = password_hash($p, PASSWORD_BCRYPT);
            $upd  = $pdo->prepare('UPDATE user SET password = ?, role = ? WHERE id = ?');
            $upd->execute([$hash, $r, $id]);
            $success = 'Utente aggiornato con successo.';
        }
    } else {
        // CREATE nuovo
        $u = trim($_POST['username'] ?? '');
        if ($u === '' || $p === '' || $r === '') {
            $error = 'Compila tutti i campi.';
        } elseif (!in_array($r, ['dev','admin','utente'], true)) {
            $error = 'Ruolo non valido.';
        } else {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM user WHERE username = ?');
            $chk->execute([$u]);
            if ($chk->fetchColumn() > 0) {
                $error = 'Username già esistente.';
            } else {
                $hash = password_hash($p, PASSWORD_BCRYPT);
                $ins  = $pdo->prepare('INSERT INTO user (username,password,role) VALUES (?,?,?)');
                $ins->execute([$u, $hash, $r]);
                $success = 'Utente creato con successo.';
            }
        }
    }
}

// Preleva lista utenti
$stmt  = $pdo->query('SELECT id, username, role FROM user ORDER BY username');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestione Utenti – Biosound</title>
  <!-- Bootstrap CSS & Icons -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
    rel="stylesheet"
  >
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet"
  >
  <style>
    :root {
      --bg: #f0f2f5;
      --fg: #2e3a45;
      --pri: #66bb6a;
      --radius: 1rem;
      --shadow: rgba(0,0,0,0.08);
      --font: 'Segoe UI', sans-serif;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--fg);
      font-family: var(--font);
    }
    nav.app-nav {
      margin-bottom: 1rem !important;
      border-radius: 0 0 var(--radius) var(--radius);
    }
    .container {
      max-width: 900px;
      margin: 2rem auto;
      padding: 0 1rem;
    }
    h1 {
      text-align: center;
      margin-bottom: 1rem;
      font-weight: 500;
    }
    .add-container {
      text-align: center;
      margin-bottom: 1rem;
    }
    .add-btn {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      background: var(--pri);
      color: #fff;
      padding: .5rem 1rem;
      border-radius: var(--radius);
      border: none;
      cursor: pointer;
      transition: background .2s, transform .15s;
      font-size: 1rem;
      font-weight: 500;
    }
    .add-btn:hover {
      background: #5aad5c;
      transform: translateY(-2px);
    }
    .item {
      background: #fff;
      border-radius: var(--radius);
      box-shadow: 0 2px 6px var(--shadow);
      padding: 1rem;
      margin-bottom: .75rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: transform .15s, box-shadow .15s;
    }
    .item:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px var(--shadow);
    }
    .info {
      display: flex;
      gap: 1.5rem;
      align-items: center;
      flex-wrap: wrap;
    }
    .info .nome {
      font-weight: bold;
    }
    .info .role {
      color: var(--pri);
      font-weight: 500;
    }
    .actions {
      display: flex;
      gap: .5rem;
    }
    .icon-btn {
      background: none;
      border: none;
      color: var(--pri);
      font-size: 1.2rem;
      cursor: pointer;
      transition: color .2s;
    }
    .icon-btn:hover {
      color: #5aad5c;
    }
    /* PiP / Modal styling */
    .modal-content {
      border-radius: var(--radius);
      box-shadow: 0 4px 12px var(--shadow);
    }
    .form-control, .form-select {
      border-radius: var(--radius);
    }
    .btn-rounded {
      border-radius: var(--radius);
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar_d.php'; ?>

  <div class="container">
    <h1>Utenti Registrati</h1>

    <?php if ($error): ?>
      <div class="alert alert-danger rounded-pill text-center mb-3">
        <?= htmlspecialchars($error, ENT_QUOTES) ?>
      </div>
    <?php elseif ($success): ?>
      <div class="alert alert-success rounded-pill text-center mb-3">
        <?= htmlspecialchars($success, ENT_QUOTES) ?>
      </div>
    <?php endif; ?>

    <div class="add-container">
      <button
        class="add-btn"
        data-bs-toggle="modal"
        data-bs-target="#createUserModal"
      >
        <i class="bi bi-plus-lg"></i>
        Aggiungi Utente
      </button>
    </div>

    <?php if (empty($users)): ?>
      <p style="text-align:center;color:#666;">Nessun utente registrato.</p>
    <?php else: ?>
      <?php foreach ($users as $u): ?>
        <div
          class="item"
          data-name="<?= strtolower(htmlspecialchars($u['username'], ENT_QUOTES)) ?>"
        >
          <div class="info">
            <span class="nome"><?= htmlspecialchars($u['username'], ENT_QUOTES) ?></span>
            <span class="role"><?= htmlspecialchars($u['role'], ENT_QUOTES) ?></span>
          </div>
          <div class="actions">
            <button
              class="icon-btn"
              data-bs-toggle="modal"
              data-bs-target="#editUserModal"
              data-id="<?= htmlspecialchars($u['id'], ENT_QUOTES) ?>"
              data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
              data-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>"
              title="Modifica Utente"
            >
              <i class="bi bi-pencil"></i>
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Modal: Crea Utente -->
  <div
    class="modal fade"
    id="createUserModal"
    tabindex="-1"
    aria-hidden="true"
  >
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content p-4">
        <div class="modal-header border-0">
          <h5 class="modal-title">Nuovo Utente</h5>
          <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="Chiudi"
          ></button>
        </div>
        <form method="post" id="createForm" class="modal-body">
          <div class="mb-3">
            <label for="newUsername" class="form-label">Username</label>
            <input
              type="text"
              id="newUsername"
              name="username"
              class="form-control"
              required
            >
          </div>
          <div class="mb-3">
            <label for="newPassword" class="form-label">Password</label>
            <input
              type="password"
              id="newPassword"
              name="password"
              class="form-control"
              required
            >
          </div>
          <div class="mb-3">
            <label for="newRole" class="form-label">Ruolo</label>
            <select
              id="newRole"
              name="role"
              class="form-select"
              required
            >
              <option value="" disabled selected>Seleziona ruolo</option>
              <option value="dev">Dev</option>
              <option value="admin">Admin</option>
              <option value="utente">Utente</option>
            </select>
          </div>
        </form>
        <div class="modal-footer border-0">
          <button
            type="button"
            class="btn btn-secondary btn-rounded"
            data-bs-dismiss="modal"
          >
            <i class="bi bi-x-lg"></i> Annulla
          </button>
          <button
            type="submit"
            form="createForm"
            class="btn btn-primary btn-rounded"
          >
            <i class="bi bi-check-lg"></i> Registra
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal: Modifica Utente -->
  <div
    class="modal fade"
    id="editUserModal"
    tabindex="-1"
    aria-hidden="true"
  >
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content p-4">
        <div class="modal-header border-0">
          <h5 class="modal-title">Modifica Utente</h5>
          <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="Chiudi"
          ></button>
        </div>
        <form method="post" id="editForm" class="modal-body">
          <input type="hidden" name="user_id" id="editUserId">
          <div class="mb-3">
            <label for="editUsername" class="form-label">Username</label>
            <input
              type="text"
              id="editUsername"
              class="form-control"
              disabled
            >
          </div>
          <div class="mb-3">
            <label for="editPassword" class="form-label">Nuova Password</label>
            <input
              type="password"
              id="editPassword"
              name="password"
              class="form-control"
              required
            >
          </div>
          <div class="mb-3">
            <label for="editRole" class="form-label">Ruolo</label>
            <select
              id="editRole"
              name="role"
              class="form-select"
              required
            >
              <option value="dev">Dev</option>
              <option value="admin">Admin</option>
              <option value="utente">Utente</option>
            </select>
          </div>
        </form>
        <div class="modal-footer border-0">
          <button
            type="button"
            class="btn btn-secondary btn-rounded"
            data-bs-dismiss="modal"
          >
            <i class="bi bi-x-lg"></i> Annulla
          </button>
          <button
            type="submit"
            form="editForm"
            class="btn btn-primary btn-rounded"
          >
            <i class="bi bi-check-lg"></i> Aggiorna
          </button>
        </div>
      </div>
    </div>
  </div>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
  ></script>
  <script>
    var editModal = document.getElementById('editUserModal');
    editModal.addEventListener('show.bs.modal', function (e) {
      var btn      = e.relatedTarget;
      var id       = btn.getAttribute('data-id');
      var username = btn.getAttribute('data-username');
      var role     = btn.getAttribute('data-role');
      document.getElementById('editUserId').value    = id;
      document.getElementById('editUsername').value  = username;
      document.getElementById('editRole').value      = role;
    });
  </script>
</body>
</html>
