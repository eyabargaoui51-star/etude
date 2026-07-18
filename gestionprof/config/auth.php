<?php
/* ============================================================
   config/auth.php — Garde d'accès + gestion de session
   ------------------------------------------------------------
   À inclure EN TOUT PREMIER, avant toute sortie HTML, sur
   chaque page protégée :

       require_once __DIR__ . "/config/auth.php";

   Rôle :
   1) Démarre une session sécurisée (si pas déjà démarrée).
   2) Détruit la session si elle est inactive depuis plus de
      30 minutes, et redirige vers login.php avec un message.
   3) Si l'utilisateur n'est pas connecté, redirige vers
      login.php.
   4) Met à jour l'horodatage de dernière activité.

   Ce fichier ne modifie ni le design, ni le CSS, ni le HTML :
   il ne fait que vérifier la session avant que la page ne
   s'affiche.
   ============================================================ */

const SESSION_TIMEOUT_SECONDS = 30 * 60; // 30 minutes d'inactivité

if (session_status() === PHP_SESSION_NONE) {
    // Détecte automatiquement si la page est servie en HTTPS (cas normal
    // en production) pour n'envoyer le cookie de session que sur une
    // connexion chiffrée. En local (XAMPP en http://), reste désactivé
    // pour ne pas bloquer la connexion en développement.
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['SERVER_PORT'] ?? '') == 443) ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') // utile derrière un proxy/CDN
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,     // le cookie de session n'est pas accessible en JS
        'samesite' => 'Lax',    // limite les envois du cookie depuis des sites tiers
        'secure'   => $isHttps, // cookie envoyé uniquement en HTTPS quand disponible
    ]);
    session_start();
}

/* ---------- 1) Session expirée par inactivité ? ---------- */
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();

    header('Location: login.php?expired=1');
    exit;
}

/* ---------- 2) Utilisateur connecté ? ---------- */
if (empty($_SESSION['id_utilisateur'])) {
    header('Location: login.php');
    exit;
}

/* ---------- 3) On avance l'horloge d'inactivité ---------- */
$_SESSION['last_activity'] = time();