<?php
/* ============================================================
   delete_group.php — Supprime un groupe si et seulement si
   aucun élève n'y est encore rattaché.
   ============================================================ */

require_once("../config/auth.php");
require_once("../config/database.php");
header('Content-Type: application/json; charset=UTF-8');

$response = ['success' => false];

$idGroupe = (int)($_POST['id_groupe'] ?? 0);

if ($idGroupe <= 0) {
    $response['message'] = "Identifiant de groupe invalide.";
} else {
    // Vérifie si des élèves appartiennent encore à ce groupe
    $checkStmt = $conn->prepare("SELECT COUNT(*) AS nb FROM eleve WHERE id_groupe = ?");
    $checkStmt->bind_param("i", $idGroupe);
    $checkStmt->execute();
    $nbEleves = (int)$checkStmt->get_result()->fetch_assoc()['nb'];
    $checkStmt->close();

    if ($nbEleves > 0) {
        $response['message'] = "Impossible de supprimer ce groupe car il contient encore des élèves.";
    } else {
        $stmt = $conn->prepare("DELETE FROM groupe WHERE id_groupe = ?");
        $stmt->bind_param("i", $idGroupe);

        if ($stmt->execute()) {
            $response['success']   = true;
            $response['id_groupe'] = $idGroupe;
        } else {
            $response['message'] = "Erreur lors de la suppression : " . $stmt->error;
        }

        $stmt->close();
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
$conn->close();