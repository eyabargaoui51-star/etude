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

if ($nom_groupe === '' || $id_filiere <= 0) {
    respond(false, 'Veuillez remplir tous les champs obligatoires.');
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