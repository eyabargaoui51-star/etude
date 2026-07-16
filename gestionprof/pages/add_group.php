<?php
require_once("../config/database.php");
require_once("../config/api_response.php"); // définit respond(), bufferise la sortie, capture toute erreur PHP

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Méthode non autorisée.');
}

// TODO: remplacer par $_SESSION['id_professeur'] une fois le système de login mis en place.
$id_professeur_actuel = 1;

$nom_groupe = trim($_POST['nom_groupe'] ?? '');
$id_filiere = isset($_POST['id_filiere']) ? (int)$_POST['id_filiere'] : 0;
$capacite   = isset($_POST['capacite']) && $_POST['capacite'] !== '' ? (int)$_POST['capacite'] : null;

if ($nom_groupe === '') {
    respond(false, 'Le nom du groupe est obligatoire.');
}
if ($id_filiere <= 0) {
    respond(false, 'La filière est obligatoire.');
}

// Empêche deux groupes portant le même nom dans la même filière.
$checkDoublon = mysqli_prepare($conn, "SELECT id_groupe FROM groupe WHERE nom_groupe = ? AND id_filiere = ? LIMIT 1");
mysqli_stmt_bind_param($checkDoublon, 'si', $nom_groupe, $id_filiere);
mysqli_stmt_execute($checkDoublon);
mysqli_stmt_store_result($checkDoublon);
$doublonExiste = mysqli_stmt_num_rows($checkDoublon) > 0;
mysqli_stmt_close($checkDoublon);
if ($doublonExiste) {
    respond(false, 'Un groupe portant ce nom existe déjà dans cette filière.');
}

try {
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO groupe (nom_groupe, capacite, id_filiere, id_professeur) VALUES (?, ?, ?, ?)"
    );
    if (!$stmt) {
        respond(false, 'Erreur de préparation de la requête : ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'siii', $nom_groupe, $capacite, $id_filiere, $id_professeur_actuel);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    respond(true, "✅ Groupe « {$nom_groupe} » créé avec succès.");
} catch (\Throwable $e) {
    error_log('add_group.php: ' . $e->getMessage());
    respond(false, 'Erreur lors de la création du groupe : ' . $e->getMessage());
}