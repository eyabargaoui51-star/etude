<?php
require_once("../config/database.php");
require_once("../config/api_response.php"); // définit respond(), bufferise la sortie, capture toute erreur PHP

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
$montant_paye_raw = trim($_POST['montant_paye'] ?? '');

if ($nom === '' || $prenom === '' || $date_inscription === '' || $id_groupe <= 0) {
    respond(false, 'Veuillez remplir tous les champs obligatoires.');
}

// Numéro de téléphone tunisien : 8 chiffres, préfixes valides 2/3/4/5/7/9
$telephone = preg_replace('/[\s\-\.]/', '', $telephone);
$telephone = preg_replace('/^(\+216|00216)/', '', $telephone);
if ($telephone === '' || !preg_match('/^[234579]\d{7}$/', $telephone)) {
    respond(false, 'Numéro de téléphone invalide. Un numéro tunisien contient 8 chiffres (ex : 20 123 456).');
}

$d = DateTime::createFromFormat('Y-m-d', $date_inscription);
if (!$d || $d->format('Y-m-d') !== $date_inscription) {
    respond(false, "Date d'inscription invalide.");
}

// Empêche deux élèves d'avoir le même numéro de téléphone
$checkTel = mysqli_prepare($conn, "SELECT id_eleve FROM eleve WHERE telephone = ? LIMIT 1");
mysqli_stmt_bind_param($checkTel, 's', $telephone);
mysqli_stmt_execute($checkTel);
mysqli_stmt_store_result($checkTel);
$telephoneDejaUtilise = mysqli_stmt_num_rows($checkTel) > 0;
mysqli_stmt_close($checkTel);
if ($telephoneDejaUtilise) {
    respond(false, 'Ce numéro de téléphone est déjà utilisé par un autre élève.');
}

$statuts_valides = ['En attente', 'Payé'];
if (!in_array($statut_paiement, $statuts_valides, true)) {
    respond(false, 'Statut de paiement invalide.');
}

// Le montant payé n'est exigé (et pris en compte) que si le statut est "Payé".
if ($statut_paiement === 'Payé') {
    if ($montant_paye_raw === '' || !is_numeric($montant_paye_raw) || (float)$montant_paye_raw <= 0) {
        respond(false, 'Veuillez saisir le montant payé.');
    }
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
    // - Si "Payé"      : montant_a_payer = montant réellement saisi, date_paiement = maintenant (NOW()).
    // - Si "En attente" / autre : montant_a_payer = 0, date_paiement = NULL (pas encore payé).
    if ($statut_paiement === 'Payé') {
        $montant_a_payer = (float)$montant_paye_raw;
        $date_paiement   = date('Y-m-d H:i:s'); // équivalent de NOW()
    } else {
        $montant_a_payer = 0;
        $date_paiement   = null;
    }

    $stmtPaiement = mysqli_prepare(
        $conn,
        "INSERT INTO paiement (id_eleve, statut, montant_a_payer, date_paiement) VALUES (?, ?, ?, ?)"
    );
    if (!$stmtPaiement) {
        respond(false, 'Erreur de préparation de la requête : ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmtPaiement, 'isds', $id_eleve, $statut_paiement, $montant_a_payer, $date_paiement);
    mysqli_stmt_execute($stmtPaiement);
    mysqli_stmt_close($stmtPaiement);

    mysqli_commit($conn);
    respond(true, "✅ {$prenom} {$nom} a été ajouté(e) avec succès.");
} catch (\Throwable $e) {
    mysqli_rollback($conn);
    error_log('add_eleve.php: ' . $e->getMessage());
    respond(false, "Erreur lors de l'ajout de l'élève : " . $e->getMessage());
}