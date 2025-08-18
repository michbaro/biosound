<?php
// navbar.php — include dentro <body> di ogni pagina protetta
$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<style>
  :root {
    --nav-bg: #fff;
    --nav-text: #333;
    --nav-accent: #28a745;
    --nav-shadow: rgba(0,0,0,0.1);
    --radius: 8px;
    --padding-vertical: 0.5rem;
    --padding-horizontal: 2rem;
    --font: 'Segoe UI', sans-serif;
    --logo-height: 40px;
    --gap-left: 2rem;
    --gap-center: 2rem;
    --gap-right: 1rem;
    --gap-user: 0.25rem;
    --icon-size: 1.25rem;
  }

  nav.app-nav {
    position: sticky;
    top: 0;
    z-index: 1000;
    width: 100%;
    background: var(--nav-bg);
    padding: var(--padding-vertical) var(--padding-horizontal);
    display: flex;
    align-items: center;
    box-shadow: 0 2px 8px var(--nav-shadow);
    font-family: var(--font);
    margin-bottom: 2rem;
    border-radius: var(--radius) var(--radius) 0 0;
  }

  /* LEFT: logo + Registrazioni */
  .nav-left {
    display: flex;
    align-items: center;
    gap: var(--gap-left);
  }
  .nav-left .nav-logo img {
    height: var(--logo-height);
    width: auto;
    display: block;
  }
  .nav-left .nav-link {
    color: var(--nav-text);
    text-decoration: none;
    padding: .5rem 1rem;
    border-radius: var(--radius);
    transition: background .2s, color .2s;
    white-space: nowrap;
    font-weight: 500;
  }
  .nav-left .nav-link:hover {
    background: var(--nav-accent);
    color: #fff;
  }

  /* CENTER: main links */
  .nav-center {
    flex: 1;
    display: flex;
    justify-content: center;
    gap: var(--gap-center);
    overflow-x: auto;
  }
  .nav-center a {
    color: var(--nav-text);
    text-decoration: none;
    padding: .5rem 1rem;
    border-radius: var(--radius);
    transition: background .2s, color .2s;
    white-space: nowrap;
    font-weight: 500;
  }
  .nav-center a:hover {
    background: var(--nav-accent);
    color: #fff;
  }

  /* RIGHT: operatori, user-area, logout */
  .nav-right {
    display: flex;
    align-items: center;
    gap: var(--gap-right);
  }
  .nav-right a {
    color: var(--nav-text);
    text-decoration: none;
    padding: .5rem 1rem;
    border-radius: var(--radius);
    transition: background .2s, color .2s;
    white-space: nowrap;
    font-weight: 500;
  }
  .nav-right a:hover {
    background: var(--nav-accent);
    color: #fff;
  }

  .user-area {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--gap-user);
  }
  .user-area .user-name {
    color: var(--nav-text);
    font-weight: 600;
  }
  .user-area .user-role {
    color: var(--nav-accent);
    font-weight: bold;
    font-size: 0.85rem;
  }

  .nav-right form {
    margin: 0;
  }
  .nav-right button {
    background: none;
    border: none;
    cursor: pointer;
    padding: .25rem;
    font-size: var(--icon-size);
    line-height: 1;
  }
  .nav-right button i {
    vertical-align: middle;
    color: var(--nav-text);
    transition: color .2s;
  }
  .nav-right button:hover i {
    color: var(--nav-accent);
  }
</style>

<nav class="app-nav" role="navigation">
  <div class="nav-left">
    <a href="/biosound/attivitae.php" class="nav-logo">
      <img src="/biosound/logo.png" alt="Biosound Logo">
    </a>
    <a href="/biosound/log/registrazione.php" class="nav-link">Registrazioni</a>
  </div>

  <div class="nav-center">
    <a href="/biosound/docenti.php">Docenti</a>
    <a href="/biosound/attivitae.php">Attività</a>
    <a href="/biosound/corsi.php">Corsi</a>
    <a href="/biosound/aziende.php">Aziende</a>
    <a href="/biosound/dipendenti.php">Dipendenti</a>
    <a href="/biosound/operatori.php">Operatori</a>
    <a href="/biosound/attestati.php">Attestati</a>
  </div>

  <div class="nav-right">
    <div class="user-area">
      <span class="user-name"><?= $username ?></span>
      <span class="user-role">DEVELOPER</span>
    </div>

    <form action="/biosound/log/logout.php" method="post">
      <button type="submit" aria-label="Logout">
        <i class="bi bi-box-arrow-right"></i>
      </button>
    </form>
  </div>
</nav>
