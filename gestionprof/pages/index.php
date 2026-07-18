<?php
/* ============================================================
   index.php — Racine du site (gestionprof\index.php)
   ------------------------------------------------------------
   Ce fichier n'a qu'un seul rôle : rediriger automatiquement
   vers pages/index.php dès qu'un visiteur arrive sur l'adresse
   du site sans préciser /pages/.

   Exemple : quelqu'un tape http://localhost/lansyyy_jaw/gestionprof/
   → il est envoyé vers pages/index.php
   → qui lui-même (via auth.php) l'envoie vers pages/login.php
     s'il n'est pas connecté, ou pages/dashboard.php s'il l'est déjà.
   ============================================================ */
header('Location: pages/index.php');
exit;