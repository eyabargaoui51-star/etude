<?php
/* ============================================================
   update_group.php — Met à jour un groupe existant
   (nom, filière, capacité) via des prepared statements.
   ============================================================ */

require_once("../config/database.php");
header('Content-Type: application/json; charset=UTF-8');

$response = ['success' => false];

$idGroupe    = (int)($_POST['id_groupe'] ?? 0);
$nomGroupe   = trim($_POST['nom_groupe'] ?? '');
$idFiliere   = (int)($_POST['id_filiere'] ?? 0);
$capaciteRaw = trim($_POST['capacite'] ?? '');
$capacite    = ($capaciteRaw === '') ? null : (int)$capaciteRaw;

if ($idGroupe <= 0 || $nomGroupe === '' || $idFiliere <= 0) {
    $response['message'] = "Champs invalides.";
} else {
    // Vérifie que le groupe existe
    $checkGroupe = $conn->prepare("SELECT id_groupe FROM groupe WHERE id_groupe = ?");
    $checkGroupe->bind_param("i", $idGroupe);
    $checkGroupe->execute();
    $groupeExiste = $checkGroupe->get_result()->fetch_assoc();
    $checkGroupe->close();

    // Vérifie que la filière existe
    $checkFiliere = $conn->prepare("SELECT id_filiere FROM filiere WHERE id_filiere = ?");
    $checkFiliere->bind_param("i", $idFiliere);
    $checkFiliere->execute();
    $filiereExiste = $checkFiliere->get_result()->fetch_assoc();
    $checkFiliere->close();

    if (!$groupeExiste) {
        $response['message'] = "Groupe introuvable.";
    } elseif (!$filiereExiste) {
        $response['message'] = "Filière introuvable.";
    } else {
        $stmt = $conn->prepare("UPDATE groupe SET nom_groupe = ?, id_filiere = ?, capacite = ? WHERE id_groupe = ?");
        $stmt->bind_param("siii", $nomGroupe, $idFiliere, $capacite, $idGroupe);

        if ($stmt->execute()) {
            $response['success']    = true;
            $response['id_groupe']  = $idGroupe;
            $response['nom_groupe'] = $nomGroupe;
            $response['id_filiere'] = $idFiliere;
            $response['capacite']   = $capacite;
        } else {
            $response['message'] = "Erreur lors de la mise à jour : " . $stmt->error;
        }

        $stmt->close();
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
$conn->close();