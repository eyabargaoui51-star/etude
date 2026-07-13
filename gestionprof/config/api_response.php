<?php
/**
 * api_response.php
 * A inclure APRÈS require_once("../config/database.php") dans chaque endpoint AJAX.
 *
 * Objectif : garantir que la réponse envoyée au navigateur est TOUJOURS
 * un JSON valide et RIEN D'AUTRE — même si un warning/notice PHP,
 * un espace, un BOM ou un echo de debug traîne quelque part (par exemple
 * dans config/database.php). C'est la cause la plus fréquente du bug
 * "l'insertion réussit mais le JS voit une erreur réseau" : le JSON
 * renvoyé est pollué par du texte parasite, donc res.json() plante côté
 * client alors que la requête SQL, elle, a bien été exécutée.
 */

// On désactive l'affichage des erreurs dans la réponse (elles cassent le JSON).
// Les erreurs sont toujours loguées côté serveur pour le debug.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// On démarre une mémoire tampon : tout ce qui serait accidentellement
// "echo"/"print"/warning avant notre json_encode() final sera capturé
// et jeté au lieu d'être envoyé au navigateur.
if (ob_get_level() === 0) {
    ob_start();
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Envoie une réponse JSON propre et arrête l'exécution.
 * Nettoie systématiquement tout ce qui aurait pu être écrit avant.
 */
function respond(bool $success, string $message, array $extra = []): void {
    // Jette tout ce qui a pu être bufferisé avant (warnings, BOM, espaces...)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(200); // le code HTTP reste 200 : succès/échec métier passe par "success"
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Convertit toute erreur PHP non fatale (warning/notice/deprecated) en
 * exception, pour qu'elle soit interceptée par nos catch(\Throwable) au
 * lieu d'être imprimée dans la réponse.
 */
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/**
 * Filet de sécurité pour les erreurs fatales (qui ne passent pas par les
 * exceptions) : si une erreur fatale survient malgré tout, on renvoie un
 * JSON propre plutôt que la page d'erreur HTML par défaut de PHP.
 */
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'message' => 'Erreur serveur inattendue. Veuillez réessayer.',
        ], JSON_UNESCAPED_UNICODE);
    }
});