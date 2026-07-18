<?php
require_once("../config/auth.php");
require_once("../config/database.php");
header('Content-Type: application/json; charset=utf-8');

/* -------------------------------------------------------------------------
   Cas 1 : ?id_groupe=X — utilisé par groups.php (modale "Modifier le groupe")
   pour récupérer les infos d'UN SEUL groupe (nom, capacité, filière) et
   préremplir le formulaire de modification.
   ------------------------------------------------------------------------- */
if (isset($_GET['id_groupe'])) {
    $id_groupe = (int)$_GET['id_groupe'];

    if ($id_groupe <= 0) {
        echo json_encode(['success' => false, 'message' => 'Groupe invalide.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT id_groupe, nom_groupe, capacite, id_filiere FROM groupe WHERE id_groupe = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Erreur de préparation de la requête : ' . mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    mysqli_stmt_bind_param($stmt, 'i', $id_groupe);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $groupe = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$groupe) {
        echo json_encode(['success' => false, 'message' => 'Groupe introuvable.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'id_groupe'  => (int)$groupe['id_groupe'],
        'nom_groupe' => $groupe['nom_groupe'],
        'capacite'   => $groupe['capacite'] !== null ? (int)$groupe['capacite'] : null,
        'id_filiere' => (int)$groupe['id_filiere'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* -------------------------------------------------------------------------
   Cas 2 : ?id_filiere=X — utilisé par dashboard.php pour lister TOUS les
   groupes d'une filière (ex : select "Groupe" qui dépend du select "Filière").
   ------------------------------------------------------------------------- */
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