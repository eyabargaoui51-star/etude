<?php
require_once("../config/database.php");
header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message): void {
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Méthode non autorisée.');
}

$nom              = trim($_POST['nom'] ?? '');
$prenom           = trim($_POST['prenom'] ?? '');
$telephone        = trim($_POST['telephone'] ?? '');
$date_inscription = trim($_POST['date_inscription'] ?? '');
$id_filiere       = isset($_POST['id_filiere']) ? (int)$_POST['id_filiere'] : 0;
$id_groupe        = isset($_POST['id_groupe']) ? (int)$_POST['id_groupe'] : 0;
$statut_paiement  = trim($_POST['statut_paiement'] ?? '');

if ($nom === '' || $prenom === '' || $telephone === '' || $date_inscription === '' || $id_groupe <= 0) {
    respond(false, 'Veuillez remplir tous les champs obligatoires.');
}

$d = DateTime::createFromFormat('Y-m-d', $date_inscription);
if (!$d || $d->format('Y-m-d') !== $date_inscription) {
    respond(false, "Date d'inscription invalide.");
}

$statuts_valides = ['En attente', 'Payé'];
if (!in_array($statut_paiement, $statuts_valides, true)) {
    respond(false, 'Statut de paiement invalide.');
}

// Vérifie que le groupe choisi appartient bien à la filière sélectionnée
if ($id_filiere > 0) {
    $checkStmt = mysqli_prepare($conn, "SELECT id_groupe FROM groupe WHERE id_groupe = ? AND id_filiere = ?");
    if (!$checkStmt) {
        respond(false, 'Erreur de préparation de la requête : ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($checkStmt, 'ii', $id_groupe, $id_filiere);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    $groupe_valide = mysqli_stmt_num_rows($checkStmt) > 0;
    mysqli_stmt_close($checkStmt);

    if (!$groupe_valide) {
        respond(false, "Le groupe sélectionné n'appartient pas à la filière choisie.");
    }
}

try {
    mysqli_begin_transaction($conn);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO eleve (nom, prenom, telephone, date_inscription, id_groupe) VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        respond(false, 'Erreur de préparation de la requête : ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'ssssi', $nom, $prenom, $telephone, $date_inscription, $id_groupe);
    mysqli_stmt_execute($stmt);
    $id_eleve = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // Création du paiement associé avec le statut choisi.
    // NB: montant_a_payer est initialisé à 0 par défaut ; à adapter si un
    // tarif est associé au groupe/à la filière dans votre schéma.
    $montant_a_payer = 0;
    $stmtPaiement = mysqli_prepare(
        $conn,
        "INSERT INTO paiement (id_eleve, statut, montant_a_payer) VALUES (?, ?, ?)"
    );
    if (!$stmtPaiement) {
        respond(false, 'Erreur de préparation de la requête : ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmtPaiement, 'isd', $id_eleve, $statut_paiement, $montant_a_payer);
    mysqli_stmt_execute($stmtPaiement);
    mysqli_stmt_close($stmtPaiement);

    mysqli_commit($conn);
    respond(true, "✅ {$prenom} {$nom} a été ajouté(e) avec succès.");
} catch (mysqli_sql_exception $e) {
    mysqli_rollback($conn);
    respond(false, "Erreur lors de l'ajout de l'élève : " . $e->getMessage());
}