<?php
/* =========================================================================
   PAIMENT.PHP — Gestion des paiements (version dynamique)
   Connecté à la base "gestion_etude" via mysqli
   ========================================================================= */
require_once("../config/database.php");

/* -------------------------------------------------------------------------
   0-quinquies) Sécurité structurelle : s'assurer que la colonne
      "date_paiement" existe bien sur la table "paiement" et qu'elle
      accepte NULL (elle doit rester NULL tant que le paiement n'est pas
      soldé). Ne modifie rien si la colonne existe déjà.
   ------------------------------------------------------------------------- */
$colCheck = $conn->query("SHOW COLUMNS FROM paiement LIKE 'date_paiement'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE paiement ADD COLUMN date_paiement DATETIME NULL DEFAULT NULL");
}

/* -------------------------------------------------------------------------
   0-sexies) Récupère la date de paiement TELLE QU'ELLE EST RÉELLEMENT
      enregistrée en base, déjà formatée par MySQL (DATE_FORMAT).
      -> On évite volontairement de repasser par strtotime()/date() côté
         PHP : si le fuseau horaire du serveur MySQL et celui de PHP ne
         sont pas rigoureusement identiques, cette double conversion peut
         afficher une heure différente de celle réellement stockée en
         base. En demandant à MySQL de formater lui-même sa propre valeur,
         l'affichage correspond toujours exactement à ce qui est en base.
   ------------------------------------------------------------------------- */
function getDatePaiementAffichee(mysqli $conn, int $idPaiement): ?string {
    $stmt = $conn->prepare("SELECT DATE_FORMAT(date_paiement, '%d/%m/%Y %H:%i') AS dp FROM paiement WHERE id_paiement = ?");
    $stmt->bind_param("i", $idPaiement);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($r && $r['dp']) ? $r['dp'] : null;
}

/* -------------------------------------------------------------------------
   0-ter) Table de persistance des notifications (créée si absente)
      - "lu" est la seule colonne qui doit survivre aux rafraîchissements
      - clé unique (type_notif, ref_id) : permet de ré-générer le contenu
        à chaque chargement sans dupliquer la ligne ni réinitialiser "lu"
   ------------------------------------------------------------------------- */
$conn->query(
    "CREATE TABLE IF NOT EXISTS notification (
        id_notification INT AUTO_INCREMENT PRIMARY KEY,
        type_notif VARCHAR(20) NOT NULL,
        ref_id INT NOT NULL,
        couleur VARCHAR(20) NOT NULL,
        icone TEXT NOT NULL,
        titre VARCHAR(150) NOT NULL,
        texte VARCHAR(255) NOT NULL,
        temps VARCHAR(50) NOT NULL DEFAULT '',
        lu TINYINT(1) NOT NULL DEFAULT 0,
        date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_notif (type_notif, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

/* -------------------------------------------------------------------------
   0-quater) AJAX — Marquer toutes les notifications comme lues (persistant)
   ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'mark_all_notif_read') {
    header('Content-Type: application/json; charset=UTF-8');
    $response = ['success' => false];

    $stmt = $conn->prepare("UPDATE notification SET lu = 1 WHERE lu = 0");
    if ($stmt->execute()) {
        $stmt->close();
        $response['success'] = true;
        $response['unread']  = 0;
    } else {
        $response['message'] = "Erreur lors de la mise à jour : " . $stmt->error;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

/* -------------------------------------------------------------------------
   0) AJAX — Définir / modifier le montant à payer d'un paiement
      (le champ manquant : jusqu'ici rien ne permettait de fixer le montant
      dû par l'élève, qui restait donc à 0.00 depuis sa création).
   ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_montant') {
    header('Content-Type: application/json; charset=UTF-8');
    $response = ['success' => false];

    $idPaiement = (int)($_POST['id_paiement'] ?? 0);
    $montantRaw = str_replace(',', '.', trim($_POST['montant'] ?? ''));

    if ($idPaiement <= 0 || $montantRaw === '' || !is_numeric($montantRaw) || (float)$montantRaw < 0) {
        $response['message'] = "Montant invalide.";
    } else {
        $montant = (float)$montantRaw;

        $stmt = $conn->prepare("UPDATE paiement SET montant_a_payer = ? WHERE id_paiement = ?");
        $stmt->bind_param("di", $montant, $idPaiement);

        if ($stmt->execute()) {
            $stmt->close();

            // Recalcule le statut avec la même règle que dans la liste ci-dessous :
            // on ne peut recalculer qu'une fois qu'un montant a réellement été fixé.
            $stmt = $conn->prepare(
                "SELECT p.statut, p.date_paiement, COALESCE(SUM(v.montant), 0) AS total_verse
                 FROM paiement p
                 LEFT JOIN versement v ON v.id_paiement = p.id_paiement
                 WHERE p.id_paiement = ?
                 GROUP BY p.id_paiement"
            );
            $stmt->bind_param("i", $idPaiement);
            $stmt->execute();
            $row  = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $paye  = (float)($row['total_verse'] ?? 0);
            $reste = $montant - $paye;

            if ($montant > 0) {
                if ($reste <= 0)   { $statutCalcule = 'Payé'; }
                elseif ($paye > 0) { $statutCalcule = 'En cours'; }
                else               { $statutCalcule = 'En attente'; }
            } else {
                $statutCalcule = $row['statut'] ?? 'En attente';
            }

            // Le statut a changé, OU le paiement est déjà "Payé" mais la
            // date de paiement n'a encore jamais été enregistrée (cas du bug).
            $doitMettreAJour = $row && (
                $statutCalcule !== $row['statut'] ||
                ($statutCalcule === 'Payé' && empty($row['date_paiement']))
            );

            if ($doitMettreAJour) {
                if ($statutCalcule === 'Payé') {
                    // Statut "Payé" -> on horodate le paiement avec la date/heure actuelles.
                    $upd = $conn->prepare("UPDATE paiement SET statut = ?, date_paiement = NOW() WHERE id_paiement = ?");
                } else {
                    // Statut "En attente" / "En cours" -> aucune date de paiement ne doit être conservée.
                    $upd = $conn->prepare("UPDATE paiement SET statut = ?, date_paiement = NULL WHERE id_paiement = ?");
                }
                $upd->bind_param("si", $statutCalcule, $idPaiement);
                $upd->execute();
                $upd->close();
            }

            $statutAffiche = ($statutCalcule === 'Payé') ? 'Payé' : (($statutCalcule === 'En cours') ? 'Partiel' : 'Non payé');

            $response['success']  = true;
            $response['montant']  = $montant;
            $response['paye']     = $paye;
            $response['reste']    = $montant - $paye;
            $response['statut']   = $statutAffiche;
            // On relit toujours la date directement depuis MySQL (formatée par MySQL
            // lui-même) pour être certain que l'affichage corresponde exactement à
            // ce qui est réellement stocké en base, sans conversion de fuseau côté PHP.
            $response['date_paiement'] = getDatePaiementAffichee($conn, $idPaiement);
        } else {
            $response['message'] = "Erreur lors de la mise à jour : " . $stmt->error;
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

/* -------------------------------------------------------------------------
   0-bis) AJAX — Enregistrer un versement (l'élève règle tout ou une partie)
      -> insère dans "versement", puis recalcule le statut automatiquement
         avec la même règle que partout ailleurs dans ce fichier.
   ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_versement') {
    header('Content-Type: application/json; charset=UTF-8');
    $response = ['success' => false];

    $idPaiement = (int)($_POST['id_paiement'] ?? 0);
    $montantRaw = str_replace(',', '.', trim($_POST['montant'] ?? ''));

    if ($idPaiement <= 0 || $montantRaw === '' || !is_numeric($montantRaw) || (float)$montantRaw <= 0) {
        $response['message'] = "Montant invalide.";
    } else {
        $montantVerse = (float)$montantRaw;

        $stmt = $conn->prepare("INSERT INTO versement (id_paiement, montant, date_versement) VALUES (?, ?, NOW())");
        $stmt->bind_param("id", $idPaiement, $montantVerse);

        if ($stmt->execute()) {
            $stmt->close();

            // Recalcule le montant total versé + le statut du paiement
            $stmt = $conn->prepare(
                "SELECT p.montant_a_payer, p.statut, p.date_paiement, COALESCE(SUM(v.montant), 0) AS total_verse
                 FROM paiement p
                 LEFT JOIN versement v ON v.id_paiement = p.id_paiement
                 WHERE p.id_paiement = ?
                 GROUP BY p.id_paiement"
            );
            $stmt->bind_param("i", $idPaiement);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $montant = (float)($row['montant_a_payer'] ?? 0);
            $paye    = (float)($row['total_verse'] ?? 0);
            $reste   = $montant - $paye;

            if ($montant > 0) {
                if ($reste <= 0)    { $statutCalcule = 'Payé'; }
                elseif ($paye > 0)  { $statutCalcule = 'En cours'; }
                else                { $statutCalcule = 'En attente'; }
            } else {
                $statutCalcule = $row['statut'] ?? 'En attente';
            }

            // Le statut a changé, OU le paiement est déjà "Payé" mais la
            // date de paiement n'a encore jamais été enregistrée (cas du bug).
            $doitMettreAJour = $row && (
                $statutCalcule !== $row['statut'] ||
                ($statutCalcule === 'Payé' && empty($row['date_paiement']))
            );

            if ($doitMettreAJour) {
                if ($statutCalcule === 'Payé') {
                    // Statut "Payé" -> on horodate le paiement avec la date/heure actuelles.
                    $upd = $conn->prepare("UPDATE paiement SET statut = ?, date_paiement = NOW() WHERE id_paiement = ?");
                } else {
                    // Statut "En attente" / "En cours" -> aucune date de paiement ne doit être conservée.
                    $upd = $conn->prepare("UPDATE paiement SET statut = ?, date_paiement = NULL WHERE id_paiement = ?");
                }
                $upd->bind_param("si", $statutCalcule, $idPaiement);
                $upd->execute();
                $upd->close();
            }

            $statutAffiche = ($statutCalcule === 'Payé') ? 'Payé' : (($statutCalcule === 'En cours') ? 'Partiel' : 'Non payé');

            $response['success'] = true;
            $response['montant'] = $montant;
            $response['paye']    = $paye;
            $response['reste']   = $reste;
            $response['statut']  = $statutAffiche;
            // Relecture directe depuis MySQL (déjà formatée) pour garantir que
            // l'affichage colle exactement à la valeur réellement stockée.
            $response['date_paiement'] = getDatePaiementAffichee($conn, $idPaiement);
        } else {
            $response['message'] = "Erreur lors de l'enregistrement : " . $stmt->error;
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

/* -------------------------------------------------------------------------
   1) LISTES POUR LES FILTRES (filière / groupe) — depuis la base
   ------------------------------------------------------------------------- */
$filieresList = [];
$sql = "SELECT nom_filiere FROM filiere ORDER BY nom_filiere ASC";
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $filieresList[] = $row['nom_filiere'];
}

$groupesList = [];
$sql = "SELECT nom_groupe FROM groupe ORDER BY nom_groupe ASC";
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $groupesList[] = $row['nom_groupe'];
}

/* -------------------------------------------------------------------------
   2) LISTE COMPLETE DES PAIEMENTS
      - montant_a_payer vient de "paiement"
      - le montant réellement payé = somme des "versement" liés
      - le statut est recalculé et resynchronisé automatiquement en base
   ------------------------------------------------------------------------- */
$students = [];

$sql = "SELECT p.id_paiement, e.nom, e.prenom, g.nom_groupe, f.nom_filiere,
               p.montant_a_payer, p.statut, p.date_paiement,
               DATE_FORMAT(p.date_paiement, '%d/%m/%Y %H:%i') AS date_paiement_fmt,
               COALESCE(SUM(v.montant), 0) AS total_verse
        FROM paiement p
        INNER JOIN eleve e   ON e.id_eleve = p.id_eleve
        INNER JOIN groupe g  ON g.id_groupe = e.id_groupe
        INNER JOIN filiere f ON f.id_filiere = g.id_filiere
        LEFT JOIN versement v ON v.id_paiement = p.id_paiement
        GROUP BY p.id_paiement
        ORDER BY e.nom ASC";
$result = mysqli_query($conn, $sql);

while ($row = mysqli_fetch_assoc($result)) {
    $montant = (float)$row['montant_a_payer'];
    $paye    = (float)$row['total_verse'];
    $reste   = $montant - $paye;

    if ($montant > 0) {
        // Un montant à payer a réellement été fixé : on peut recalculer le
        // statut à partir des versements effectivement enregistrés.
        if ($reste <= 0)    { $statutCalcule = 'Payé'; }
        elseif ($paye > 0)  { $statutCalcule = 'En cours'; }   // paiement partiel
        else                { $statutCalcule = 'En attente'; } // rien de payé
    } else {
        // Aucun montant à payer n'a encore été défini (ex : élève tout juste
        // ajouté depuis la page Élèves, montant_a_payer = 0). 0 DT dû / 0 DT
        // versé ne signifie pas "soldé" : on conserve donc le statut existant
        // tel quel au lieu de l'écraser par "Payé".
        $statutCalcule = $row['statut'];
    }

    // Mise à jour automatique du statut en base si celui-ci est obsolète,
    // ou si le paiement est déjà "Payé" mais que la date de paiement n'a
    // encore jamais été enregistrée (cas du bug corrigé ici).
    $doitMettreAJour = ($statutCalcule !== $row['statut']) ||
        ($statutCalcule === 'Payé' && empty($row['date_paiement']));

    if ($doitMettreAJour) {
        if ($statutCalcule === 'Payé') {
            // Statut "Payé" -> on horodate le paiement avec la date/heure actuelles.
            $upd = mysqli_prepare($conn, "UPDATE paiement SET statut = ?, date_paiement = NOW() WHERE id_paiement = ?");
        } else {
            // Statut "En attente" / "En cours" -> aucune date de paiement ne doit être conservée.
            $upd = mysqli_prepare($conn, "UPDATE paiement SET statut = ?, date_paiement = NULL WHERE id_paiement = ?");
        }
        mysqli_stmt_bind_param($upd, "si", $statutCalcule, $row['id_paiement']);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }

    // Traduit le statut "base de données" (En attente / En cours / Payé) vers
    // le libellé utilisé par l'affichage (Non payé / Partiel / Payé).
    $statutAffiche = ($statutCalcule === 'Payé') ? 'Payé' : (($statutCalcule === 'En cours') ? 'Partiel' : 'Non payé');

    // Date de paiement pour l'affichage : si la ligne vient d'être mise à jour
    // ci-dessus, on relit la valeur exacte directement depuis MySQL (déjà
    // formatée par MySQL lui-même) plutôt que d'utiliser une approximation
    // calculée côté PHP, afin d'éviter tout décalage de fuseau horaire.
    // Sinon, on utilise directement la valeur déjà formatée par la requête
    // principale (DATE_FORMAT), qui correspond exactement à ce qui est en base.
    $datePaiementAffichee = $doitMettreAJour
        ? getDatePaiementAffichee($conn, $row['id_paiement'])
        : ($row['date_paiement_fmt'] ?? null);

    $students[] = [
        'id_paiement'    => (int)$row['id_paiement'],
        'nom'            => $row['nom'],
        'prenom'         => $row['prenom'],
        'groupe'         => $row['nom_groupe'],
        'filiere'        => $row['nom_filiere'],
        'montant'        => $montant,
        'paye'           => $paye,
        'statut'         => $statutAffiche,
        'date_paiement'  => $datePaiementAffichee,
    ];
}

/* -------------------------------------------------------------------------
   3) NOTIFICATIONS DYNAMIQUES (remplacent les 3 notifications statiques)
      - Paiements en retard   -> table paiement (statut = 'En attente')
      - Paiements reçus       -> table versement (dernier versement d'un paiement soldé)
      - Nouveaux élèves       -> table eleve (date_inscription la plus récente)
   ------------------------------------------------------------------------- */

// Convertit une date en libellé relatif simple ("Aujourd'hui", "Hier", "Il y a X jours")
function libelleTemps($dateStr) {
    if (!$dateStr) return '';
    $date  = new DateTime($dateStr);
    $today = new DateTime(date('Y-m-d'));
    $diff  = (int)$today->diff($date)->format('%r%a');
    if ($diff === 0)  return "Aujourd'hui";
    if ($diff === -1) return "Hier";
    if ($diff < 0)    return "Il y a " . abs($diff) . " jours";
    return "Dans " . $diff . " jour" . ($diff > 1 ? "s" : "");
}

/* Upsert (INSERT ... ON DUPLICATE KEY UPDATE) : ré-écrit le contenu affiché
   mais ne touche JAMAIS à "lu", pour que l'état lu/non-lu survive au
   rafraîchissement de la page. La clé (type_notif, ref_id) évite les doublons. */
function upsertNotification(mysqli $conn, string $type, int $refId, string $couleur, string $icone, string $titre, string $texte, string $temps): void {
    $stmt = $conn->prepare(
        "INSERT INTO notification (type_notif, ref_id, couleur, icone, titre, texte, temps)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            couleur = VALUES(couleur),
            icone   = VALUES(icone),
            titre   = VALUES(titre),
            texte   = VALUES(texte),
            temps   = VALUES(temps)"
    );
    $stmt->bind_param("sisssss", $type, $refId, $couleur, $icone, $titre, $texte, $temps);
    $stmt->execute();
    $stmt->close();
}

// a) Paiements en retard (statut = 'En attente')
$stmt = $conn->prepare(
    "SELECT p.id_paiement, e.nom, e.prenom
     FROM paiement p
     INNER JOIN eleve e ON e.id_eleve = p.id_eleve
     WHERE p.statut = 'En attente'
     ORDER BY p.id_paiement DESC
     LIMIT 2"
);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $texte = htmlspecialchars($row['prenom'] . ' ' . $row['nom']) . " n'a pas encore réglé son paiement.";
    upsertNotification($conn, 'retard', (int)$row['id_paiement'], 'orange', '<rect x="2.5" y="6" width="19" height="13" rx="2"/><path d="M2.5 10h19"/>', 'Paiement en retard', $texte, '');
}
$stmt->close();

// b) Paiements reçus récemment (statut = 'Payé', triés par date du dernier versement)
$stmt = $conn->prepare(
    "SELECT p.id_paiement, e.nom, e.prenom, MAX(v.date_versement) AS derniere_date
     FROM paiement p
     INNER JOIN eleve e   ON e.id_eleve = p.id_eleve
     INNER JOIN versement v ON v.id_paiement = p.id_paiement
     WHERE p.statut = 'Payé'
     GROUP BY p.id_paiement
     ORDER BY derniere_date DESC
     LIMIT 2"
);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $texte = htmlspecialchars($row['prenom'] . ' ' . $row['nom']) . " a réglé la totalité de son paiement.";
    upsertNotification($conn, 'recu', (int)$row['id_paiement'], 'green', '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.3 2.3L16 10"/>', 'Paiement reçu', $texte, libelleTemps($row['derniere_date']));
}
$stmt->close();

// c) Nouveaux élèves inscrits récemment
$stmt = $conn->prepare(
    "SELECT id_eleve, nom, prenom, date_inscription FROM eleve ORDER BY date_inscription DESC LIMIT 2"
);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $texte = htmlspecialchars($row['prenom'] . ' ' . $row['nom']) . " a rejoint SmartTeacher.";
    upsertNotification($conn, 'eleve', (int)$row['id_eleve'], 'purple', '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="8" r="2.3"/><path d="M2 20c0-3 3-5 7-5s7 2 7 5"/>', 'Nouvel élève inscrit', $texte, libelleTemps($row['date_inscription']));
}
$stmt->close();

/* Lecture finale depuis la base : c'est ELLE qui fait foi pour l'état lu/non-lu,
   pas la logique de génération ci-dessus. */
$notifications = [];
$stmt = $conn->prepare(
    "SELECT couleur AS color, icone AS icon, titre AS title, texte AS text, temps AS time, lu
     FROM notification
     ORDER BY lu ASC, date_creation DESC, id_notification DESC
     LIMIT 6"
);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['lu'] = (int)$row['lu'];
    $notifications[] = $row;
}
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notification WHERE lu = 0");
$stmt->execute();
$unreadCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartTeacher — Paiements</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --bg: #f4f6fb;
    --sidebar-bg: #10162b;
    --sidebar-bg2: #131a33;
    --purple: #6c4ff2;
    --purple-dark: #5738e0;
    --purple-soft: #efeaff;
    --text-dark: #16192b;
    --text-mid: #5a5f75;
    --text-light: #9aa0b4;
    --card-border: #e8eaf1;
    --green: #16a34a;
    --green-bg: #e8f8ee;
    --orange: #ea8c1e;
    --orange-bg: #fdf1e0;
    --red: #e34848;
    --red-bg: #fdeaea;
    --blue-chip: #eef1ff;
    --blue-chip-text: #4b5bd6;
    --math-chip: #eef7ff;
    --math-chip-text: #1f8fd6;
    --radius: 14px;
  }
  *{box-sizing:border-box; margin:0; padding:0;}
  body{
    font-family:'Inter', system-ui, sans-serif;
    background:var(--bg);
    color:var(--text-dark);
    display:flex;
    min-height:100vh;
    font-size:14px;
  }

  /* ---------- MAIN ---------- */
  .main{
    flex:1;
    min-width:0;
    display:flex;
    flex-direction:column;
  }
  .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:18px 32px;
    background:#fff;
    border-bottom:1px solid var(--card-border);
    gap:20px;
  }
  .topbar-left{ display:flex; align-items:center; gap:16px; }
  .menu-btn{
    background:none; border:none; cursor:pointer;
    color:var(--text-dark); display:flex; padding:6px;
  }
  .page-title{ font-size:21px; font-weight:700; letter-spacing:-0.3px; }
  .topbar-titles{ display:flex; flex-direction:column; gap:3px; }
  .breadcrumb{ display:flex; align-items:center; gap:6px; font-size:12.5px; }
  .breadcrumb-link{ color:var(--text-mid); text-decoration:none; font-weight:600; transition:color .15s ease; }
  .breadcrumb-link:hover{ color:var(--purple); }
  .breadcrumb-sep{ color:var(--text-light); }
  .breadcrumb-current{ color:var(--purple); font-weight:600; }

  .topbar-right{ display:flex; align-items:center; gap:16px; }
  .search-box{
    display:flex; align-items:center; gap:8px;
    background:#f2f3f8;
    border:1px solid transparent;
    border-radius:10px;
    padding:9px 14px;
    width:300px;
    transition:border-color .15s;
  }
  .search-box:focus-within{ border-color:var(--purple); background:#fff; }
  .search-box input{
    border:none; outline:none; background:transparent; flex:1;
    font-size:13.5px; color:var(--text-dark); font-family:inherit;
  }
  .search-box input::placeholder{ color:var(--text-light); }
  .icon-btn{
    position:relative;
    background:#f2f3f8;
    border:none;
    width:38px; height:38px;
    border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer;
    color:var(--text-mid);
  }
  .icon-btn .badge{
    position:absolute; top:-4px; right:-4px;
    background:var(--red);
    color:#fff;
    font-size:10px;
    font-weight:700;
    width:17px; height:17px;
    border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    border:2px solid #fff;
  }
  .date-box{
    display:flex; flex-direction:column;
    border:1px solid var(--card-border);
    border-radius:10px;
    padding:6px 14px;
    line-height:1.35;
  }
  .date-box .d1{ font-size:13.5px; font-weight:700; }
  .date-box .d2{ font-size:11.5px; color:var(--text-light); }

  /* ---------- DROPDOWNS ---------- */
  .dropdown-wrap{ position:relative; }
  .dropdown-panel{
    position:absolute;
    top:calc(100% + 12px);
    right:0;
    background:#fff;
    border:1px solid var(--card-border);
    border-radius:14px;
    box-shadow:0 16px 40px rgba(20,20,50,0.14);
    opacity:0;
    visibility:hidden;
    transform:translateY(-8px);
    transition:opacity .15s, transform .15s, visibility .15s;
    z-index:50;
  }
  .dropdown-panel.open{
    opacity:1;
    visibility:visible;
    transform:translateY(0);
  }
  .dropdown-panel::before{
    content:"";
    position:absolute;
    top:-6px; right:14px;
    width:12px; height:12px;
    background:#fff;
    border-left:1px solid var(--card-border);
    border-top:1px solid var(--card-border);
    transform:rotate(45deg);
  }

  .notif-panel{ width:340px; padding:6px 0 8px; }
  .dropdown-header{
    display:flex; align-items:center; justify-content:space-between;
    padding:14px 18px 12px;
    font-size:14.5px; font-weight:700;
    border-bottom:1px solid #f1f2f7;
  }
  .link-btn{
    background:none; border:none; cursor:pointer;
    color:var(--purple); font-family:inherit;
    font-size:12px; font-weight:600;
  }
  .link-btn:hover{ text-decoration:underline; }
  .notif-list{ max-height:340px; overflow-y:auto; }
  .notif-item{
    display:flex; gap:12px;
    padding:13px 18px;
    border-bottom:1px solid #f6f7fb;
    cursor:pointer;
    transition:background .12s;
  }
  .notif-item:last-child{ border-bottom:none; }
  .notif-item:hover{ background:#fafbff; }
  .notif-item.unread{ background:#f8f7ff; }
  .notif-item.unread:hover{ background:#f2f0ff; }
  .notif-icon{
    width:34px; height:34px;
    border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
  }
  .notif-icon.orange{ background:var(--orange-bg); color:var(--orange); }
  .notif-icon.green{ background:var(--green-bg); color:var(--green); }
  .notif-icon.purple{ background:var(--purple-soft); color:var(--purple); }
  .notif-title{ font-size:13px; font-weight:700; color:var(--text-dark); margin-bottom:2px; }
  .notif-text{ font-size:12px; color:var(--text-mid); line-height:1.4; margin-bottom:4px; }
  .notif-time{ font-size:11px; color:var(--text-light); }

  .cal-panel{ width:280px; padding:16px; }
  .cal-header{
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:14px;
    font-size:14px; font-weight:700;
  }
  .cal-nav{
    width:28px; height:28px;
    border-radius:8px;
    border:1px solid var(--card-border);
    background:#fff;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer;
    color:var(--text-mid);
  }
  .cal-nav:hover{ border-color:var(--purple); color:var(--purple); }
  .cal-weekdays{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    text-align:center;
    font-size:11px;
    font-weight:700;
    color:var(--text-light);
    margin-bottom:8px;
  }
  .cal-grid{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:3px;
  }
  .cal-day{
    aspect-ratio:1;
    display:flex; align-items:center; justify-content:center;
    font-size:12.5px;
    border-radius:8px;
    cursor:pointer;
    color:var(--text-dark);
  }
  .cal-day:hover{ background:var(--purple-soft); }
  .cal-day.muted{ color:var(--text-light); opacity:0.5; }
  .cal-day.today{ background:var(--purple); color:#fff; font-weight:700; }
  .cal-day.selected:not(.today){ background:var(--purple-soft); color:var(--purple); font-weight:700; }

  .content{
    padding:26px 32px 40px;
    flex:1;
  }

  /* ---------- FILTER BAR ---------- */
  .filter-bar{
    background:#fff;
    border:1px solid var(--card-border);
    border-radius:var(--radius);
    padding:20px 24px;
    display:grid;
    grid-template-columns: 1.1fr 1.1fr 1fr 1.3fr auto;
    gap:16px;
    align-items:end;
    margin-bottom:22px;
  }
  .field label{
    display:block;
    font-size:12.5px;
    font-weight:600;
    color:var(--text-mid);
    margin-bottom:8px;
  }
  .select-wrap{
    position:relative;
    display:flex;
    align-items:center;
    border:1px solid var(--card-border);
    border-radius:10px;
    padding:0 12px;
    height:42px;
    gap:8px;
    background:#fff;
  }
  .select-wrap svg.leading{ color:var(--purple); flex-shrink:0; }
  .select-wrap select{
    border:none; outline:none; background:transparent;
    font-family:inherit; font-size:13.5px; color:var(--text-dark);
    flex:1; appearance:none; cursor:pointer;
  }
  .select-wrap svg.chevron{ color:var(--text-light); flex-shrink:0; pointer-events:none; }
  .date-range{
    display:flex; align-items:center; gap:8px;
    border:1px solid var(--card-border);
    border-radius:10px;
    height:42px;
    padding:0 12px;
  }
  .date-range input{
    border:none; outline:none; font-family:inherit; font-size:13px;
    color:var(--text-dark); width:100%; background:transparent;
  }
  .date-range svg{ color:var(--text-light); flex-shrink:0; }
  .btn-filter{
    height:42px;
    background:var(--purple);
    color:#fff;
    border:none;
    border-radius:10px;
    padding:0 22px;
    font-family:inherit;
    font-weight:600;
    font-size:13.5px;
    display:flex; align-items:center; gap:8px;
    cursor:pointer;
    white-space:nowrap;
    transition:background .15s;
  }
  .btn-filter:hover{ background:var(--purple-dark); }

  /* ---------- STAT CARDS ---------- */
  .stats{
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:18px;
    margin-bottom:24px;
  }
  .stat-card{
    background:#fff;
    border:1px solid var(--card-border);
    border-radius:var(--radius);
    padding:20px;
    display:flex;
    align-items:flex-start;
    gap:14px;
  }
  .stat-icon{
    width:46px; height:46px;
    border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
  }
  .stat-icon.purple{ background:var(--purple-soft); color:var(--purple); }
  .stat-icon.green{ background:var(--green-bg); color:var(--green); }
  .stat-icon.orange{ background:var(--orange-bg); color:var(--orange); }
  .stat-icon.blue{ background:#e7f1ff; color:#2f7fe0; }
  .stat-label{ font-size:13px; color:var(--text-mid); font-weight:500; margin-bottom:6px;}
  .stat-value{ font-size:23px; font-weight:800; letter-spacing:-0.3px; margin-bottom:3px;}
  .stat-sub{ font-size:11.5px; color:var(--text-light); }

  /* ---------- TABLE ---------- */
  .table-card{
    background:#fff;
    border:1px solid var(--card-border);
    border-radius:var(--radius);
    padding:24px;
  }
  .table-title{ font-size:17px; font-weight:700; margin-bottom:18px; }
  table{ width:100%; border-collapse:collapse; }
  thead th{
    text-align:left;
    font-size:12px;
    font-weight:700;
    color:var(--text-light);
    text-transform:none;
    padding:10px 12px;
    border-bottom:1px solid var(--card-border);
    white-space:nowrap;
  }
  tbody td{
    padding:13px 12px;
    font-size:13.5px;
    border-bottom:1px solid #f1f2f7;
    white-space:nowrap;
  }
  tbody tr:hover{ background:#fafbff; }
  .chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:5px 10px;
    border-radius:8px;
    font-size:12.5px;
    font-weight:600;
  }
  .chip-groupe{ background:var(--blue-chip); color:var(--blue-chip-text); }
  .chip-fil{ background:#efeaff; color:var(--purple); }
  .chip-fil.math{ background:var(--math-chip); color:var(--math-chip-text); }
  .amount-reste{ color:var(--red); font-weight:600; }
  .amount-zero{ color:var(--text-dark); }
  .statut{
    display:inline-flex; align-items:center; gap:6px;
    padding:5px 12px; border-radius:999px;
    font-size:12px; font-weight:700;
  }
  .statut.paye{ background:var(--green-bg); color:var(--green); }
  .statut.partiel{ background:var(--orange-bg); color:var(--orange); }
  .statut.impaye{ background:var(--red-bg); color:var(--red); }
  .statut .dot{ width:6px; height:6px; border-radius:50%; background:currentColor; }

  .table-footer{
    display:flex; align-items:center; justify-content:space-between;
    margin-top:18px;
    flex-wrap:wrap; gap:14px;
  }
  .total-eleves{ font-size:13.5px; color:var(--text-mid); }
  .total-eleves b{ color:var(--purple); font-weight:700; }
  .pagination{ display:flex; align-items:center; gap:8px; }
  .per-page{
    display:flex; align-items:center; gap:8px;
    border:1px solid var(--card-border);
    border-radius:8px;
    padding:7px 12px;
    font-size:13px;
    color:var(--text-mid);
    cursor:pointer;
  }
  .per-page select{ border:none; background:transparent; font-family:inherit; font-size:13px; outline:none; cursor:pointer; }
  .page-btn{
    width:34px; height:34px;
    border-radius:8px;
    border:1px solid var(--card-border);
    background:#fff;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer;
    font-size:13px;
    font-weight:600;
    color:var(--text-mid);
  }
  .page-btn:hover{ border-color:var(--purple); color:var(--purple); }
  .page-btn.active{ background:var(--purple); border-color:var(--purple); color:#fff; }
  .page-btn:disabled{ opacity:0.4; cursor:not-allowed; }
  .page-btn:disabled:hover{ border-color:var(--card-border); color:var(--text-mid); }

  .empty-row td{ text-align:center; padding:40px 0; color:var(--text-light); font-size:14px; }

  .btn-edit-montant{
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 12px; border-radius:8px;
    border:1px solid var(--card-border);
    background:#fff; color:var(--purple);
    font-family:inherit; font-size:12.5px; font-weight:600;
    cursor:pointer;
  }
  .btn-edit-montant:hover{ border-color:var(--purple); background:var(--purple-soft); }

  .btn-actions{ display:flex; align-items:center; gap:8px; }
  .btn-payer{
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 12px; border-radius:8px;
    border:1px solid var(--green);
    background:var(--green-bg); color:var(--green);
    font-family:inherit; font-size:12.5px; font-weight:600;
    cursor:pointer;
  }
  .btn-payer:hover{ background:var(--green); color:#fff; }
  .btn-payer:disabled{ opacity:0.5; cursor:not-allowed; background:#f2f3f8; border-color:var(--card-border); color:var(--text-light); }

  /* ---- Modal "Montant à payer" ---- */
  .modal-overlay{
    display:none;
    position:fixed; inset:0;
    background:rgba(16,22,43,0.45);
    align-items:center; justify-content:center;
    z-index:100;
  }
  .modal-overlay.open{ display:flex; }
  .modal-box{
    background:#fff;
    border-radius:var(--radius);
    padding:28px;
    width:100%; max-width:380px;
    box-shadow:0 20px 50px rgba(16,22,43,0.25);
  }
  .modal-icon{
    width:42px; height:42px; border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    margin-bottom:14px;
  }
  .modal-icon.purple{ background:var(--purple-soft); color:var(--purple); }
  .modal-title{ font-size:17px; font-weight:700; color:var(--text-dark); margin-bottom:6px; }
  .modal-text{ font-size:13.5px; color:var(--text-mid); margin-bottom:18px; }
  .modal-field{ display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
  .modal-field label{ font-size:13px; font-weight:600; color:var(--text-dark); }
  .modal-field input{
    border:1px solid var(--card-border);
    border-radius:10px;
    padding:10px 12px;
    font-family:inherit; font-size:14px;
    outline:none;
  }
  .modal-field input:focus{ border-color:var(--purple); }
  .modal-error{ color:var(--red); font-size:12.5px; margin:-6px 0 12px; }
  .modal-actions{ display:flex; justify-content:flex-end; gap:10px; margin-top:6px; }
  .btn{
    padding:9px 18px; border-radius:10px;
    font-family:inherit; font-size:13.5px; font-weight:600;
    border:1px solid transparent; cursor:pointer;
  }
  .btn-ghost{ background:#fff; border-color:var(--card-border); color:var(--text-mid); }
  .btn-ghost:hover{ border-color:var(--purple); color:var(--purple); }
  .btn-primary{ background:var(--purple); color:#fff; }
  .btn-primary:hover{ background:var(--purple-dark); }
  .btn-primary:disabled{ opacity:0.6; cursor:not-allowed; }

  @media (max-width: 1200px){
    .filter-bar{ grid-template-columns:1fr 1fr; }
    .stats{ grid-template-columns:repeat(2,1fr); }
  }
  @media (max-width: 900px){
    .search-box{ width:180px; }
  }
</style>
</head>
<body>

<!-- ================= MAIN ================= -->
<div class="main">
  <header class="topbar">
    <div class="topbar-left">
    
      <div class="topbar-titles">
        <nav class="breadcrumb" aria-label="breadcrumb">
          <a href="dashboard.php" class="breadcrumb-link">Dashboard</a>
          <span class="breadcrumb-sep">&rsaquo;</span>
          <span class="breadcrumb-current">Paiements</span>
        </nav>
        <div class="page-title">Paiements</div>
      </div>
    </div>
    <div class="topbar-right">
      <div class="search-box">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9aa0b4" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" id="searchInput" placeholder="Rechercher un élève, groupe...">
      </div>
      <div class="dropdown-wrap" id="notifWrap">
        <button class="icon-btn" aria-label="notifications" id="notifBtn">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
          <span class="badge" id="notifBadge"<?php if ($unreadCount === 0) echo ' style="display:none;"'; ?>><?php echo $unreadCount; ?></span>
        </button>
        <div class="dropdown-panel notif-panel" id="notifPanel">
          <div class="dropdown-header">
            <span>Notifications</span>
            <button class="link-btn" id="markAllRead">Tout marquer comme lu</button>
          </div>
          <div class="notif-list" id="notifList">
            <?php if (empty($notifications)): ?>
            <div class="notif-item">
              <div class="notif-body">
                <div class="notif-text">Aucune notification pour le moment.</div>
              </div>
            </div>
            <?php else: foreach ($notifications as $n): ?>
            <div class="notif-item<?php echo $n['lu'] ? '' : ' unread'; ?>">
              <div class="notif-icon <?php echo $n['color']; ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $n['icon']; ?></svg>
              </div>
              <div class="notif-body">
                <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                <div class="notif-text"><?php echo $n['text']; ?></div>
                <?php if ($n['time'] !== ''): ?><div class="notif-time"><?php echo htmlspecialchars($n['time']); ?></div><?php endif; ?>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <div class="dropdown-wrap" id="calWrap">
        <button class="icon-btn" aria-label="calendrier" id="calBtn">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
        </button>
        <div class="dropdown-panel cal-panel" id="calPanel">
          <div class="cal-header">
            <button class="cal-nav" id="calPrev">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m14 17-5-5 5-5"/></svg>
            </button>
            <span id="calMonthLabel">Mai 2024</span>
            <button class="cal-nav" id="calNext">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m10 17 5-5-5-5"/></svg>
            </button>
          </div>
          <div class="cal-weekdays">
            <span>L</span><span>M</span><span>M</span><span>J</span><span>V</span><span>S</span><span>D</span>
          </div>
          <div class="cal-grid" id="calGrid"></div>
        </div>
      </div>
      <div class="date-box">
        <span class="d1" id="dateD1">15 Mai 2024</span>
        <span class="d2" id="dateD2">Mercredi</span>
      </div>
    </div>
  </header>

  <div class="content">

    <!-- FILTER BAR -->
    <div class="filter-bar">
      <div class="field">
        <label>Filière</label>
        <div class="select-wrap">
          <svg class="leading" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12.5V17c0 1.7 2.7 3 6 3s6-1.3 6-3v-4.5"/></svg>
          <select id="filterFiliere">
            <option value="">Toutes les filières</option>
            <?php foreach ($filieresList as $f): ?>
            <option value="<?php echo htmlspecialchars($f); ?>"><?php echo htmlspecialchars($f); ?></option>
            <?php endforeach; ?>
          </select>
          <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </div>
      </div>

      <div class="field">
        <label>Groupe</label>
        <div class="select-wrap">
          <svg class="leading" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="8" r="2.3"/><path d="M2 20c0-3 3-5 7-5s7 2 7 5"/><path d="M15.5 15.2c3 .3 5.5 2.1 5.5 4.8"/></svg>
          <select id="filterGroupe">
            <option value="">Tous les groupes</option>
            <?php foreach ($groupesList as $g): ?>
            <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
            <?php endforeach; ?>
          </select>
          <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </div>
      </div>

      <div class="field">
        <label>Statut</label>
        <div class="select-wrap">
          <svg class="leading" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M6 6v13a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6M10 6V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2"/></svg>
          <select id="filterStatut">
            <option value="">Tous les statuts</option>
            <option value="Payé">Payé</option>
            <option value="Partiel">Partiel</option>
            <option value="Non payé">Non payé</option>
          </select>
          <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </div>
      </div>

      <div class="field">
        <label>Période</label>
        <div class="date-range">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
          <input type="text" id="periodeInput" value="01/05/2024 - 31/05/2024">
        </div>
      </div>

      <button class="btn-filter" id="btnFiltrer">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.5V19l4 2v-8.5z"/></svg>
        Filtrer
      </button>
    </div>

    <!-- STAT CARDS -->
    <div class="stats">
      <div class="stat-card">
        <div class="stat-icon purple">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h15a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/><circle cx="16" cy="13" r="1.5"/></svg>
        </div>
        <div>
          <div class="stat-label">Total à encaisser</div>
          <div class="stat-value" id="statTotal">0 DT</div>
          <div class="stat-sub">Pour tous les élèves</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon green">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.3 2.3L16 10"/></svg>
        </div>
        <div>
          <div class="stat-label">Total encaissé</div>
          <div class="stat-value" id="statEncaisse">0 DT</div>
          <div class="stat-sub">Pour tous les élèves</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon orange">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>
        </div>
        <div>
          <div class="stat-label">Reste à payer</div>
          <div class="stat-value" id="statReste">0 DT</div>
          <div class="stat-sub">Pour tous les élèves</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon blue">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="8" r="2.3"/><path d="M2 20c0-3 3-5 7-5s7 2 7 5"/><path d="M15.5 15.2c3 .3 5.5 2.1 5.5 4.8"/></svg>
        </div>
        <div>
          <div class="stat-label">Total élèves</div>
          <div class="stat-value" id="statEleves">0</div>
          <div class="stat-sub">Tous groupes confondus</div>
        </div>
      </div>
    </div>

    <!-- TABLE -->
    <div class="table-card">
      <div class="table-title">Liste des paiements</div>
      <div style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th>N°</th>
            <th>Nom</th>
            <th>Prénom</th>
            <th>Groupe</th>
            <th>Filière</th>
            <th>Montant à payer (DT)</th>
            <th>Déjà payé (DT)</th>
            <th>Reste à payer (DT)</th>
            <th>Statut</th>
            <th>Date de paiement</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tableBody"></tbody>
      </table>
      </div>

      <div class="table-footer">
        <div class="total-eleves">Total : <b id="totalCount">0</b> élèves</div>
        <div style="display:flex; align-items:center; gap:16px;">
          <div class="per-page">
            <select id="perPageSelect">
              <option value="10">10 par page</option>
              <option value="20">20 par page</option>
              <option value="9999">Tout afficher</option>
            </select>
          </div>
          <div class="pagination" id="pagination"></div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal : définir / modifier le montant à payer -->
<div class="modal-overlay" id="montantModal">
  <div class="modal-box">
    <div class="modal-icon purple">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
    </div>
    <h3 class="modal-title">Montant à payer</h3>
    <p class="modal-text">Indiquez le montant total dû par cet élève pour ce mois.</p>

    <form id="montantForm">
      <div class="modal-field">
        <label for="montantInput">Montant (DT)</label>
        <input type="number" id="montantInput" name="montant" min="0" step="0.01" required>
      </div>
      <p class="modal-error" id="montantError" style="display:none;"></p>

      <div class="modal-actions">
        <button class="btn btn-ghost" id="cancelMontantBtn" type="button">Annuler</button>
        <button class="btn btn-primary" id="confirmMontantBtn" type="submit">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : enregistrer un versement (l'élève paie) -->
<div class="modal-overlay" id="versementModal">
  <div class="modal-box">
    <div class="modal-icon purple" style="background:var(--green-bg); color:var(--green);">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.3 2.3L16 10"/></svg>
    </div>
    <h3 class="modal-title" id="versementTitle">Enregistrer un paiement</h3>
    <p class="modal-text" id="versementText">Indiquez le montant réglé par cet élève. Le statut sera mis à jour automatiquement.</p>

    <form id="versementForm">
      <div class="modal-field">
        <label for="versementInput">Montant versé (DT)</label>
        <input type="number" id="versementInput" name="montant" min="0.01" step="0.01" required>
      </div>
      <p class="modal-error" id="versementError" style="display:none;"></p>

      <div class="modal-actions">
        <button class="btn btn-ghost" id="cancelVersementBtn" type="button">Annuler</button>
        <button class="btn btn-primary" id="confirmVersementBtn" type="submit" style="background:var(--green);">Confirmer le paiement</button>
      </div>
    </form>
  </div>
</div>

<script>
  // ---------- DATA (injectée depuis MySQL via PHP) ----------
  const students = <?php echo json_encode($students, JSON_UNESCAPED_UNICODE); ?>.map((s,i)=>{
    // Le statut vient déjà de PHP (calculé correctement : un montant à payer
    // de 0 DT ne veut pas dire "soldé", donc on ne le recalcule pas ici).
    const reste = s.montant - s.paye;
    return { n:i+1, ...s, reste };
  });

  // ---------- STATE ----------
  let filtered = [...students];
  let currentPage = 1;
  let perPage = 10;

  const fmt = n => n.toLocaleString('fr-FR').replace(/,/g,' ') + " DT";

  function chipFiliere(f){
    if(f === "Bac Math"){
      return `<span class="chip chip-fil math"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 4h4l3 8-3 8H4M13 4h7l-4 8 4 8h-7"/></svg>${f}</span>`;
    }
    return `<span class="chip chip-fil"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg>${f}</span>`;
  }
  function chipGroupe(g){
    return `<span class="chip chip-groupe">${g}</span>`;
  }
  function statutBadge(s){
    const map = {
      "Payé": ["paye","Payé"],
      "Partiel": ["partiel","Partiel"],
      "Non payé": ["impaye","Non payé"]
    };
    const [cls,label] = map[s];
    return `<span class="statut ${cls}"><span class="dot"></span>${label}</span>`;
  }

  function applyFilters(){
    const f = document.getElementById('filterFiliere').value;
    const g = document.getElementById('filterGroupe').value;
    const st = document.getElementById('filterStatut').value;
    const q = document.getElementById('searchInput').value.trim().toLowerCase();

    filtered = students.filter(s=>{
      if(f && s.filiere !== f) return false;
      if(g && s.groupe !== g) return false;
      if(st && s.statut !== st) return false;
      if(q){
        const hay = (s.nom + " " + s.prenom + " " + s.groupe).toLowerCase();
        if(!hay.includes(q)) return false;
      }
      return true;
    });
    currentPage = 1;
    renderAll();
  }

  function renderStats(){
    const totalAPayer = filtered.reduce((a,s)=>a+s.montant,0);
    const totalEncaisse = filtered.reduce((a,s)=>a+s.paye,0);
    const totalReste = filtered.reduce((a,s)=>a+s.reste,0);
    document.getElementById('statTotal').textContent = fmt(totalAPayer);
    document.getElementById('statEncaisse').textContent = fmt(totalEncaisse);
    document.getElementById('statReste').textContent = fmt(totalReste);
    document.getElementById('statEleves').textContent = filtered.length;
    document.getElementById('totalCount').textContent = filtered.length;
  }

  function renderTable(){
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = "";
    if(filtered.length === 0){
      tbody.innerHTML = `<tr class="empty-row"><td colspan="11">Aucun résultat trouvé pour ces filtres.</td></tr>`;
      return;
    }
    const start = (currentPage-1)*perPage;
    const pageItems = filtered.slice(start, start+perPage);
    pageItems.forEach((s, idx)=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${start+idx+1}</td>
        <td>${s.nom}</td>
        <td>${s.prenom}</td>
        <td>${chipGroupe(s.groupe)}</td>
        <td>${chipFiliere(s.filiere)}</td>
        <td>${s.montant} DT</td>
        <td>${s.paye} DT</td>
        <td class="${s.reste>0?'amount-reste':'amount-zero'}">${s.reste} DT</td>
        <td>${statutBadge(s.statut)}</td>
        <td>${s.date_paiement ? s.date_paiement : '<span style="color:var(--text-light);">—</span>'}</td>
        <td>
          <div class="btn-actions">
            <button type="button" class="btn-edit-montant" data-id="${s.id_paiement}" title="Définir le montant à payer">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              Montant
            </button>
            <button type="button" class="btn-payer" data-id="${s.id_paiement}" title="Enregistrer un paiement" ${s.reste<=0 ? 'disabled' : ''}>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.3 2.3L16 10"/></svg>
              Payer
            </button>
          </div>
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  function renderPagination(){
    const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
    if(currentPage > totalPages) currentPage = totalPages;
    const el = document.getElementById('pagination');
    el.innerHTML = "";

    const mkBtn = (label, page, opts={}) => {
      const b = document.createElement('button');
      b.className = 'page-btn' + (opts.active ? ' active' : '');
      b.innerHTML = label;
      b.disabled = !!opts.disabled;
      b.addEventListener('click', ()=>{ currentPage = page; renderAll(); });
      return b;
    };

    el.appendChild(mkBtn('<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M11 17 6 12l5-5"/><path d="M18 17l-5-5 5-5"/></svg>', 1, {disabled: currentPage===1}));
    el.appendChild(mkBtn('<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m14 17-5-5 5-5"/></svg>', Math.max(1,currentPage-1), {disabled: currentPage===1}));

    let pages = [];
    if(totalPages <= 5){
      for(let i=1;i<=totalPages;i++) pages.push(i);
    } else {
      pages = [1,2,3, '...', totalPages];
    }
    pages.forEach(p=>{
      if(p === '...'){
        const span = document.createElement('span');
        span.textContent = '…';
        span.style.padding = '0 4px';
        span.style.color = 'var(--text-light)';
        el.appendChild(span);
      } else {
        el.appendChild(mkBtn(p, p, {active: p===currentPage}));
      }
    });

    el.appendChild(mkBtn('<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m10 17 5-5-5-5"/></svg>', Math.min(totalPages,currentPage+1), {disabled: currentPage===totalPages}));
    el.appendChild(mkBtn('<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m6 17 5-5-5-5"/><path d="m13 17 5-5-5-5"/></svg>', totalPages, {disabled: currentPage===totalPages}));
  }

  function renderAll(){
    renderStats();
    renderTable();
    renderPagination();
  }

  // ---------- MODAL : Montant à payer ----------
  const montantModal   = document.getElementById('montantModal');
  const montantForm     = document.getElementById('montantForm');
  const montantInput    = document.getElementById('montantInput');
  const montantError    = document.getElementById('montantError');
  const cancelMontantBtn  = document.getElementById('cancelMontantBtn');
  const confirmMontantBtn = document.getElementById('confirmMontantBtn');
  let idPaiementEnEdition = null;

  function openMontantModal(idPaiement, montantActuel){
    idPaiementEnEdition = idPaiement;
    montantInput.value = montantActuel;
    montantError.style.display = 'none';
    montantModal.classList.add('open');
    montantInput.focus();
  }
  function closeMontantModal(){
    montantModal.classList.remove('open');
    idPaiementEnEdition = null;
    montantForm.reset();
  }

  // Délégation d'événement : les boutons sont recréés à chaque renderTable()
  document.getElementById('tableBody').addEventListener('click', (e)=>{
    const btn = e.target.closest('.btn-edit-montant');
    if(!btn) return;
    const idPaiement = parseInt(btn.dataset.id, 10);
    const s = students.find(st => st.id_paiement === idPaiement);
    if(s) openMontantModal(idPaiement, s.montant);
  });

  cancelMontantBtn.addEventListener('click', closeMontantModal);
  montantModal.addEventListener('click', (e)=>{ if(e.target === montantModal) closeMontantModal(); });

  montantForm.addEventListener('submit', (e)=>{
    e.preventDefault();
    if(idPaiementEnEdition === null) return;

    const montant = montantInput.value;
    confirmMontantBtn.disabled = true;
    montantError.style.display = 'none';

    fetch('paiment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ ajax_action: 'update_montant', id_paiement: idPaiementEnEdition, montant })
    })
    .then(res => res.json())
    .then(data => {
      if(data.success){
        const s = students.find(st => st.id_paiement === idPaiementEnEdition);
        if(s){
          s.montant = data.montant;
          s.paye    = data.paye;
          s.reste   = data.reste;
          s.statut  = data.statut;
          s.date_paiement = data.date_paiement;
        }
        closeMontantModal();
        renderAll();
      } else {
        montantError.textContent = data.message || "Erreur lors de l'enregistrement.";
        montantError.style.display = 'block';
      }
    })
    .catch(() => {
      montantError.textContent = "Erreur réseau. Réessayez.";
      montantError.style.display = 'block';
    })
    .finally(() => { confirmMontantBtn.disabled = false; });
  });

  // ---------- MODAL : Enregistrer un versement (paiement de l'élève) ----------
  const versementModal      = document.getElementById('versementModal');
  const versementForm       = document.getElementById('versementForm');
  const versementInput      = document.getElementById('versementInput');
  const versementError      = document.getElementById('versementError');
  const versementText       = document.getElementById('versementText');
  const cancelVersementBtn  = document.getElementById('cancelVersementBtn');
  const confirmVersementBtn = document.getElementById('confirmVersementBtn');
  let idPaiementVersement = null;

  function openVersementModal(idPaiement, reste){
    idPaiementVersement = idPaiement;
    versementInput.value = reste > 0 ? reste : '';
    versementInput.max = reste > 0 ? reste : '';
    versementText.textContent = `Reste à payer : ${fmt(reste)}. Indiquez le montant réglé, le statut sera mis à jour automatiquement.`;
    versementError.style.display = 'none';
    versementModal.classList.add('open');
    versementInput.focus();
  }
  function closeVersementModal(){
    versementModal.classList.remove('open');
    idPaiementVersement = null;
    versementForm.reset();
  }

  document.getElementById('tableBody').addEventListener('click', (e)=>{
    const btn = e.target.closest('.btn-payer');
    if(!btn || btn.disabled) return;
    const idPaiement = parseInt(btn.dataset.id, 10);
    const s = students.find(st => st.id_paiement === idPaiement);
    if(s) openVersementModal(idPaiement, s.reste);
  });

  cancelVersementBtn.addEventListener('click', closeVersementModal);
  versementModal.addEventListener('click', (e)=>{ if(e.target === versementModal) closeVersementModal(); });

  versementForm.addEventListener('submit', (e)=>{
    e.preventDefault();
    if(idPaiementVersement === null) return;

    const montant = versementInput.value;
    confirmVersementBtn.disabled = true;
    versementError.style.display = 'none';

    fetch('paiment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ ajax_action: 'add_versement', id_paiement: idPaiementVersement, montant })
    })
    .then(res => res.json())
    .then(data => {
      if(data.success){
        const s = students.find(st => st.id_paiement === idPaiementVersement);
        if(s){
          s.montant = data.montant;
          s.paye    = data.paye;
          s.reste   = data.reste;
          s.statut  = data.statut;
          s.date_paiement = data.date_paiement;
        }
        closeVersementModal();
        renderAll();
      } else {
        versementError.textContent = data.message || "Erreur lors de l'enregistrement.";
        versementError.style.display = 'block';
      }
    })
    .catch(() => {
      versementError.textContent = "Erreur réseau. Réessayez.";
      versementError.style.display = 'block';
    })
    .finally(() => { confirmVersementBtn.disabled = false; });
  });

  document.getElementById('btnFiltrer').addEventListener('click', applyFilters);
  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('perPageSelect').addEventListener('change', (e)=>{
    perPage = parseInt(e.target.value, 10);
    currentPage = 1;
    renderAll();
  });

  // ---------- NOTIFICATIONS DROPDOWN ----------
  const notifBtn = document.getElementById('notifBtn');
  const notifPanel = document.getElementById('notifPanel');
  const notifBadge = document.getElementById('notifBadge');
  const markAllRead = document.getElementById('markAllRead');

  function closeAllDropdowns(except){
    [notifPanel, calPanel].forEach(p=>{
      if(p !== except) p.classList.remove('open');
    });
  }

  notifBtn.addEventListener('click', (e)=>{
    e.stopPropagation();
    const willOpen = !notifPanel.classList.contains('open');
    closeAllDropdowns();
    if(willOpen) notifPanel.classList.add('open');
  });

  markAllRead.addEventListener('click', (e)=>{
    e.stopPropagation();
    const previouslyUnread = document.querySelectorAll('.notif-item.unread');

    fetch('paiment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ ajax_action: 'mark_all_notif_read' })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        previouslyUnread.forEach(el => el.classList.remove('unread'));
        notifBadge.textContent = '0';
        notifBadge.style.display = 'none';
      } else {
        console.error(data.message || "Erreur lors de la mise à jour des notifications.");
      }
    })
    .catch(() => {
      console.error('Erreur réseau lors du marquage des notifications.');
    });
  });

  // ---------- MINI CALENDAR ----------
  const calBtn = document.getElementById('calBtn');
  const calPanel = document.getElementById('calPanel');
  const calGrid = document.getElementById('calGrid');
  const calMonthLabel = document.getElementById('calMonthLabel');
  const calPrev = document.getElementById('calPrev');
  const calNext = document.getElementById('calNext');
  const monthsFull = ["Janvier","Février","Mars","Avril","Mai","Juin","Juillet","Août","Septembre","Octobre","Novembre","Décembre"];

  const today = new Date();
  let viewYear = today.getFullYear();
  let viewMonth = today.getMonth();
  let selectedDate = new Date(today);

  function renderCalendar(){
    calMonthLabel.textContent = `${monthsFull[viewMonth]} ${viewYear}`;
    calGrid.innerHTML = "";

    const firstDay = new Date(viewYear, viewMonth, 1);
    let startOffset = firstDay.getDay() - 1;
    if(startOffset < 0) startOffset = 6;
    const daysInMonth = new Date(viewYear, viewMonth+1, 0).getDate();
    const daysInPrevMonth = new Date(viewYear, viewMonth, 0).getDate();

    const cells = [];
    for(let i=startOffset; i>0; i--){
      cells.push({ day: daysInPrevMonth - i + 1, muted:true });
    }
    for(let d=1; d<=daysInMonth; d++){
      cells.push({ day:d, muted:false });
    }
    while(cells.length % 7 !== 0){
      cells.push({ day: cells.length - startOffset - daysInMonth + 1, muted:true });
    }

    cells.forEach(c=>{
      const div = document.createElement('div');
      div.className = 'cal-day' + (c.muted ? ' muted' : '');
      div.textContent = c.day;
      if(!c.muted){
        const isToday = c.day === today.getDate() && viewMonth === today.getMonth() && viewYear === today.getFullYear();
        const isSelected = c.day === selectedDate.getDate() && viewMonth === selectedDate.getMonth() && viewYear === selectedDate.getFullYear();
        if(isToday) div.classList.add('today');
        else if(isSelected) div.classList.add('selected');
        div.addEventListener('click', (e)=>{
          e.stopPropagation();
          selectedDate = new Date(viewYear, viewMonth, c.day);
          renderCalendar();
        });
      }
      calGrid.appendChild(div);
    });
  }

  calBtn.addEventListener('click', (e)=>{
    e.stopPropagation();
    const willOpen = !calPanel.classList.contains('open');
    closeAllDropdowns();
    if(willOpen){
      calPanel.classList.add('open');
      renderCalendar();
    }
  });

  calPrev.addEventListener('click', (e)=>{
    e.stopPropagation();
    viewMonth--;
    if(viewMonth < 0){ viewMonth = 11; viewYear--; }
    renderCalendar();
  });
  calNext.addEventListener('click', (e)=>{
    e.stopPropagation();
    viewMonth++;
    if(viewMonth > 11){ viewMonth = 0; viewYear++; }
    renderCalendar();
  });

  document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape'){ closeMontantModal(); closeVersementModal(); } });
  document.addEventListener('click', ()=> closeAllDropdowns());
  notifPanel.addEventListener('click', (e)=> e.stopPropagation());
  calPanel.addEventListener('click', (e)=> e.stopPropagation());

  // Live date/day display
  (function setDate(){
    const days = ["Dimanche","Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"];
    const months = ["Janvier","Février","Mars","Avril","Mai","Juin","Juillet","Août","Septembre","Octobre","Novembre","Décembre"];
    const now = new Date();
    document.getElementById('dateD1').textContent = `${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
    document.getElementById('dateD2').textContent = days[now.getDay()];
  })();

  renderAll();
</script>

</body>
</html>