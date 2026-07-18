<?php
/* ============================================================
   pages/index.php — Point d'entrée du site.
   Placé dans pages/, au même niveau que dashboard.php, login.php...
   Redirige vers le dashboard si connecté, sinon vers login.php.
   (auth.php gère déjà lui-même la redirection vers login.php
   si la session n'est pas valide.)
   ============================================================ */
require_once("../config/auth.php");
header('Location: dashboard.php');
exit;