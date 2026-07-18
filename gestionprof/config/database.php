<?php
/* ============================================================
   config/database.php — Connexion base de données
   ------------------------------------------------------------
   Détecte automatiquement l'environnement (local XAMPP vs
   hébergement réel) pour ne JAMAIS afficher d'erreurs PHP en
   production, tout en gardant l'affichage utile en local.

   - En local (XAMPP), l'hôte HTTP est "localhost" ou une IP
     privée (127.0.0.1) → on considère qu'on est en développement.
   - Partout ailleurs (ton vrai nom de domaine) → on considère
     qu'on est en production : les erreurs ne s'affichent jamais
     à l'écran, elles partent uniquement dans un fichier log.
   ============================================================ */

$host_actuel = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (
    $host_actuel === '' ||
    strpos($host_actuel, 'localhost') !== false ||
    strpos($host_actuel, '127.0.0.1') !== false
);

define('APP_ENV', $isLocal ? 'local' : 'production');

// Dossier de logs applicatif (séparé des logs Apache/PHP globaux du serveur).
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/php-error.log');

if (APP_ENV === 'local') {
    // En local : les erreurs s'affichent pour faciliter le débogage.
    ini_set('display_errors', '1');
} else {
    // En production : JAMAIS d'affichage d'erreur à l'écran
    // (une erreur affichée peut révéler des chemins serveur,
    // casser un JSON, ou corrompre un PDF généré).
    ini_set('display_errors', '0');
}

/* ------------------------------------------------------------
   Paramètres de connexion
   - En local : valeurs XAMPP par défaut.
   - En production : remplace les 3 valeurs "À_REMPLACER" par
     celles fournies par ton hébergeur. Si ton hébergeur supporte
     les variables d'environnement, utilise-les plutôt que de
     coder les identifiants en dur ici.
   ------------------------------------------------------------ */
if (APP_ENV === 'local') {
    $host     = "localhost";
    $user     = "root";
    $password = "";
    $database = "gestion_etude";
} else {
    // 🔧 À COMPLÉTER avant la mise en ligne.
    $host     = getenv('DB_HOST')     ?: "localhost";
    $user     = getenv('DB_USER')     ?: "À_REMPLACER";
    $password = getenv('DB_PASSWORD') ?: "À_REMPLACER";
    $database = getenv('DB_NAME')     ?: "À_REMPLACER";
}

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    // On ne révèle jamais le détail de l'erreur de connexion à l'écran
    // en production ; le détail complet part dans le log applicatif.
    error_log("Erreur de connexion MySQL : " . $conn->connect_error);
    if (APP_ENV === 'local') {
        die("Erreur de connexion : " . $conn->connect_error);
    } else {
        http_response_code(500);
        die("Le service est momentanément indisponible. Veuillez réessayer plus tard.");
    }
}

$conn->set_charset("utf8mb4");