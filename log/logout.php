<?php
// log/logout.php
session_start();
session_unset();
session_destroy();
header('Location: /biosound/log/login.php');
exit;
