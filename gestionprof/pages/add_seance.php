<?php
require_once("../config/database.php");
require_once("../config/api_response.php"); // définit respond(), bufferise la sortie, capture toute erreur PHP

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Méthode non autorisée.');
}

$date_seance = trim($_POST['date_seance'] ?? '');
$heure_debut = trim($_POST['heure_debut'] ?? '');
$heure_fin   = trim($_POST['heure_fin'] ?? '');
$chapitre    = trim($_POST['chapitre'] ?? '');
$id_groupe   = isset($_POST['id_groupe']) ? (int)$_POST['id_groupe'] : 0;

if ($date_seance === '' || $heure_debut === '' || $heure_fin === '' || $chapitre === '' || $id_groupe <= 0) {
    respond(false, 'Veuillez remplir tous les champs obligatoires.');
}

if ($heure_fin <= $heure_debut) {
    respond(false, "L'heure de fin doit être postérieure à l'heure de début.");
}

try {
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO seance (id_groupe, date_seance, heure_debut, heure_fin, chapitre) VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        respond(false, 'Erreur de préparation de la requête : ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'issss', $id_groupe, $date_seance, $heure_debut, $heure_fin, $chapitre);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    respond(true, '✅ Séance planifiée avec succès.');
} catch (\Throwable $e) {
    error_log('add_seance.php: ' . $e->getMessage());
    respond(false, 'Erreur lors de la planification de la séance : ' . $e->getMessage());
}