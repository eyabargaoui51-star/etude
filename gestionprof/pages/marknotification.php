<?php
/* ============================================================
   mark_notification_read.php — Marque une notification (ou
   toutes les notifications) comme lue.

   Paramètres POST attendus :
   - id     : identifiant de la notification à marquer comme lue
   - action : si égal à "all", marque TOUTES les notifications
              non lues comme lues (utilisé par le bouton
              "Tout marquer comme lu").
   ============================================================ */

require_once("../config/auth.php");
require_once("../config/database.php");
header('Content-Type: application/json; charset=UTF-8');

$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = "Méthode non autorisée.";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? null;

if ($action === 'all') {
    /* ---- Marquer toutes les notifications comme lues ---- */
    $stmt = $conn->prepare("UPDATE notification SET lu = 1 WHERE lu = 0");
    if ($stmt && $stmt->execute()) {
        $response['success'] = true;
    } else {
        $response['message'] = "Erreur lors de la mise à jour : " . $conn->error;
    }
    if ($stmt) $stmt->close();
} else {
    /* ---- Marquer une seule notification comme lue ---- */
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        $response['message'] = "Identifiant de notification invalide.";
    } else {
        $stmt = $conn->prepare("UPDATE notification SET lu = 1 WHERE id_notification = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $response['success'] = true;
            } else {
                $response['message'] = "Erreur lors de la mise à jour : " . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['message'] = "Erreur de préparation de la requête.";
        }
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
$conn->close();