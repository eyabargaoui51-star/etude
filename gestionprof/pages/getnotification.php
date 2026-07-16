<?php
/* ============================================================
   getnotification.php — Renvoie la liste des notifications
   (les plus récentes en premier, non-lues d'abord) ainsi que
   le nombre de notifications non lues.

   Utilisé par groups.php : fetch('getnotification.php') en GET.

   NOTE : ce fichier contenait auparavant une copie collée de
   marknotification.php (il ne renvoyait donc jamais de liste,
   et exigeait à tort une requête POST). Le panneau de
   notifications ne pouvait donc jamais se charger. Ce fichier
   reprend la même définition de table que celle déjà utilisée
   dans paiment.php pour rester cohérent avec le reste du projet.
   ============================================================ */

require_once("../config/auth.php");
require_once("../config/database.php");
header('Content-Type: application/json; charset=UTF-8');

$response = ['success' => false];

// Crée la table si elle n'existe pas encore (même définition que paiment.php)
$conn->query(
    "CREATE TABLE IF NOT EXISTS notification (
        id_notification INT AUTO_INCREMENT PRIMARY KEY,
        type_notif VARCHAR(20) NOT NULL,
        ref_id INT NOT NULL,
        couleur VARCHAR(20) NOT NULL,
        icone TEXT NOT NULL,
        titre VARCHAR(150) NOT NULL,
        texte VARCHAR(255) NOT NULL,
        temps VARCHAR(50) NOT NULL DEFAULT '',
        lu TINYINT(1) NOT NULL DEFAULT 0,
        date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_notif (type_notif, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$stmt = $conn->prepare(
    "SELECT id_notification AS id, type_notif AS type, titre,
            texte AS description, date_creation AS date, lu
     FROM notification
     ORDER BY lu ASC, date_creation DESC, id_notification DESC
     LIMIT 30"
);

if (!$stmt) {
    $response['message'] = "Erreur de préparation de la requête : " . mysqli_error($conn);
} else {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $notifications = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['lu'] = (int)$row['lu'];
        $notifications[] = $row;
    }
    mysqli_stmt_close($stmt);

    $unreadStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM notification WHERE lu = 0");
    mysqli_stmt_execute($unreadStmt);
    $unreadRow = mysqli_fetch_assoc(mysqli_stmt_get_result($unreadStmt));
    mysqli_stmt_close($unreadStmt);

    $response['success']       = true;
    $response['notifications'] = $notifications;
    $response['unread']        = (int)($unreadRow['total'] ?? 0);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
mysqli_close($conn);