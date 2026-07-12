<?php
require_once("../config/database.php");
header('Content-Type: application/json; charset=utf-8');

$id_filiere = isset($_GET['id_filiere']) ? (int)$_GET['id_filiere'] : 0;

if ($id_filiere <= 0) {
    echo json_encode(['success' => false, 'message' => 'Filière invalide.', 'groupes' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT id_groupe, nom_groupe FROM groupe WHERE id_filiere = ? ORDER BY nom_groupe ASC");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Erreur de préparation de la requête : ' . mysqli_error($conn), 'groupes' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_stmt_bind_param($stmt, 'i', $id_filiere);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$groupes = [];
while ($row = mysqli_fetch_assoc($res)) {
    $groupes[] = $row;
}
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'groupes' => $groupes], JSON_UNESCAPED_UNICODE);