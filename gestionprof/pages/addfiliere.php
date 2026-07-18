<?php
require_once("../config/auth.php");
require_once("../config/database.php");
require_once("../config/api_response.php");
header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT id_filiere, nom_filiere FROM filiere ORDER BY nom_filiere ASC";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Erreur de préparation de la requête.', 'filieres' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$filieres = [];
while ($row = mysqli_fetch_assoc($res)) {
    $filieres[] = $row;
}
mysqli_stmt_close($stmt);

echo json_encode(['success' => true, 'filieres' => $filieres], JSON_UNESCAPED_UNICODE);