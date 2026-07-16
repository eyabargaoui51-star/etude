<?php
/* ============================================================
   config/reset_password.php — SCRIPT À USAGE UNIQUE
   ------------------------------------------------------------
   Sert à changer l'email et/ou le mot de passe d'un compte
   utilisateur DÉJÀ existant.

   1) Modifie $ancienEmail (l'email actuel du compte à modifier),
      $nouvelEmail et $nouveauMotDePasse ci-dessous.
   2) Ouvre ce fichier UNE FOIS dans le navigateur.
   3) SUPPRIME CE FICHIER DU SERVEUR IMMÉDIATEMENT APRÈS.
   ============================================================ */

require_once __DIR__ . "/database.php";

$ancienEmail      = "prof@smartteacher.tn"; // <-- email actuel du compte à modifier
$nouvelEmail      = "prof@smartteacher.tn"; // <-- nouvel email (peut rester le même)
$nouveauMotDePasse = "ChangeMoi123!"; // <-- choisis un mot de passe fort

$hash = password_hash($nouveauMotDePasse, PASSWORD_DEFAULT);

$stmt = mysqli_prepare($conn, "UPDATE utilisateur SET email = ?, mot_de_passe = ? WHERE email = ?");
if (!$stmt) {
    error_log("Erreur de préparation (reset_password) : " . mysqli_error($conn));
    die("Une erreur est survenue.");
}

mysqli_stmt_bind_param($stmt, "sss", $nouvelEmail, $hash, $ancienEmail);

if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
    echo "✅ Compte mis à jour avec succès. Nouvel email : " . htmlspecialchars($nouvelEmail, ENT_QUOTES, 'UTF-8') .
         "<br><strong>Supprime ce fichier (config/reset_password.php) du serveur maintenant.</strong>";
} else {
    echo "❌ Aucun compte trouvé avec l'email : " . htmlspecialchars($ancienEmail, ENT_QUOTES, 'UTF-8') .
         ". Vérifie la valeur de \$ancienEmail.";
}

mysqli_stmt_close($stmt);