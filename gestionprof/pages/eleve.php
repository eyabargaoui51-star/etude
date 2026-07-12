<?php
/* ============================================================
   eleve.php — Page "Liste des élèves"
   - Charge depuis la base gestion_etude : filières/groupes et
     élèves (avec leur statut de paiement du mois en cours).
   - Gère aussi, en AJAX (POST avec ajax_action), les opérations
     CRUD déclenchées par les modals Ajouter / Modifier / Supprimer
     déjà présents dans le design (aucun changement HTML/CSS).
   ============================================================ */

require_once '../config/database.php';

// ---- Mois / année actuels (utilisés pour le statut de paiement affiché) ----
$moisFr = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$moisActuel    = $moisFr[(int)date('n') - 1];
$anneeActuelle = (int)date('Y');

/* ---------- Chargement des groupes / filières (utilisé partout ci-dessous) ----------
   IMPORTANT : on identifie chaque groupe par son id_groupe (et non par son nom),
   car rien n'empêche en base d'avoir deux groupes portant le même nom dans deux
   filières différentes. Utiliser le nom comme clé provoquerait une collision et
   pourrait rattacher un élève au mauvais groupe/filière. */
$groupesParFiliere    = []; // nom_filiere => [ ['id' => id_groupe, 'nom' => nom_groupe], ... ]
$groupeIdToNom         = []; // id_groupe => nom_groupe
$groupeIdToFiliereName = []; // id_groupe => nom_filiere
$idsGroupesValides     = []; // id_groupe => true (pour validation rapide)

$sqlGroupes = "SELECT g.id_groupe, g.nom_groupe, f.nom_filiere
               FROM groupe g
               INNER JOIN filiere f ON f.id_filiere = g.id_filiere
               ORDER BY f.nom_filiere ASC, g.nom_groupe ASC";
$resGroupes = $conn->query($sqlGroupes);
while ($row = $resGroupes->fetch_assoc()) {
    $idGroupe = (int)$row['id_groupe'];
    $groupesParFiliere[$row['nom_filiere']][] = ['id' => $idGroupe, 'nom' => $row['nom_groupe']];
    $groupeIdToNom[$idGroupe]           = $row['nom_groupe'];
    $groupeIdToFiliereName[$idGroupe]   = $row['nom_filiere'];
    $idsGroupesValides[$idGroupe]       = true;
}

/* ---------- Fonction : crée ou met à jour le paiement du mois en cours ----------
   Remarque : le formulaire Ajouter/Modifier élève ne demande qu'un statut
   ("Payé" / "Non payé"), pas de montant. Le montant à payer se règle sur la
   page Paiements ; ici on utilise 0.00 par défaut si aucune ligne n'existe
   encore pour ce mois, et on se contente de mettre à jour le statut sinon. */
function definirStatutPaiement($conn, $idEleve, $statutFront, $moisActuel, $anneeActuelle) {
    $statutDb = ($statutFront === 'Payé') ? 'Payé' : 'En attente';

    $stmt = $conn->prepare("SELECT id_paiement FROM paiement WHERE id_eleve = ? AND mois = ? AND annee = ? LIMIT 1");
    $stmt->bind_param("isi", $idEleve, $moisActuel, $anneeActuelle);
    $stmt->execute();
    $existant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existant) {
        $stmt = $conn->prepare("UPDATE paiement SET statut = ? WHERE id_paiement = ?");
        $stmt->bind_param("si", $statutDb, $existant['id_paiement']);
        $stmt->execute();
        $stmt->close();
    } else {
        $montantDefaut = 0.00;
        $stmt = $conn->prepare("INSERT INTO paiement (id_eleve, mois, annee, montant_a_payer, statut) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isids", $idEleve, $moisActuel, $anneeActuelle, $montantDefaut, $statutDb);
        $stmt->execute();
        $stmt->close();
    }
}

/* ============================================================
   Endpoints AJAX (CRUD) — appelés en POST depuis le JS du bas
   de page. On répond en JSON puis on arrête l'exécution avant
   tout affichage HTML.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $action   = $_POST['ajax_action'];
    $response = ['success' => false];

    if ($action === 'add') {
        $nom        = trim($_POST['nom'] ?? '');
        $prenom     = trim($_POST['prenom'] ?? '');
        $tel        = trim($_POST['tel'] ?? '');
        $idGroupe   = (int)($_POST['groupe'] ?? 0); // le front envoie désormais l'id_groupe
        $dateEntree = $_POST['dateEntree'] ?? date('Y-m-d');
        $paiement   = $_POST['paiement'] ?? 'Non payé';

        if ($nom === '' || $prenom === '' || !isset($idsGroupesValides[$idGroupe])) {
            $response['message'] = "Champs invalides.";
        } else {
            $stmt = $conn->prepare("INSERT INTO eleve (nom, prenom, telephone, date_inscription, id_groupe) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $nom, $prenom, $tel, $dateEntree, $idGroupe);
            if ($stmt->execute()) {
                $nouvelId = $stmt->insert_id;
                $stmt->close();
                definirStatutPaiement($conn, $nouvelId, $paiement, $moisActuel, $anneeActuelle);
                $response['success'] = true;
                $response['id']      = $nouvelId;
                $response['filiere'] = $groupeIdToFiliereName[$idGroupe] ?? '';
                $response['groupe']  = $groupeIdToNom[$idGroupe] ?? '';
            } else {
                $response['message'] = "Erreur lors de l'ajout : " . $stmt->error;
            }
        }
    }

    elseif ($action === 'edit') {
        $id         = (int)($_POST['id'] ?? 0);
        $nom        = trim($_POST['nom'] ?? '');
        $prenom     = trim($_POST['prenom'] ?? '');
        $tel        = trim($_POST['tel'] ?? '');
        $idGroupe   = (int)($_POST['groupe'] ?? 0); // le front envoie désormais l'id_groupe
        $dateEntree = $_POST['dateEntree'] ?? date('Y-m-d');
        $paiement   = $_POST['paiement'] ?? 'Non payé';

        if ($id <= 0 || $nom === '' || $prenom === '' || !isset($idsGroupesValides[$idGroupe])) {
            $response['message'] = "Champs invalides.";
        } else {
            $stmt = $conn->prepare("UPDATE eleve SET nom=?, prenom=?, telephone=?, date_inscription=?, id_groupe=? WHERE id_eleve=?");
            $stmt->bind_param("ssssii", $nom, $prenom, $tel, $dateEntree, $idGroupe, $id);
            if ($stmt->execute()) {
                $stmt->close();
                definirStatutPaiement($conn, $id, $paiement, $moisActuel, $anneeActuelle);
                $response['success'] = true;
                $response['filiere'] = $groupeIdToFiliereName[$idGroupe] ?? '';
                $response['groupe']  = $groupeIdToNom[$idGroupe] ?? '';
            } else {
                $response['message'] = "Erreur lors de la modification : " . $stmt->error;
            }
        }
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM eleve WHERE id_eleve = ?");
            $stmt->bind_param("i", $id);
            $response['success'] = $stmt->execute();
            if (!$response['success']) $response['message'] = $stmt->error;
            $stmt->close();
        } else {
            $response['message'] = "ID invalide.";
        }
    }

    else {
        $response['message'] = "Action inconnue.";
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

/* ============================================================
   Rendu initial de la page (requête GET normale)
   ============================================================ */

// Paiements du mois en cours, indexés par élève (évite une requête par élève)
$paiementsMoisCourant = []; // id_eleve => statut
$stmtP = $conn->prepare("SELECT id_eleve, statut FROM paiement WHERE mois = ? AND annee = ?");
$stmtP->bind_param("si", $moisActuel, $anneeActuelle);
$stmtP->execute();
$resP = $stmtP->get_result();
while ($row = $resP->fetch_assoc()) {
    $paiementsMoisCourant[(int)$row['id_eleve']] = $row['statut'];
}
$stmtP->close();

// Liste complète des élèves avec filière/groupe/statut de paiement
$eleves = [];
$sqlEleves = "SELECT e.id_eleve, e.nom, e.prenom, e.telephone, e.date_inscription, e.id_groupe,
                     g.nom_groupe, f.nom_filiere
              FROM eleve e
              INNER JOIN groupe g  ON g.id_groupe  = e.id_groupe
              INNER JOIN filiere f ON f.id_filiere = g.id_filiere
              ORDER BY e.nom ASC";
$resEleves = $conn->query($sqlEleves);
while ($row = $resEleves->fetch_assoc()) {
    $idEleve = (int)$row['id_eleve'];
    $statutBrut = $paiementsMoisCourant[$idEleve] ?? null;

    $eleves[] = [
        'id'         => $idEleve,
        'nom'        => $row['nom'],
        'prenom'     => $row['prenom'],
        'tel'        => $row['telephone'] ?: '',
        'filiere'    => $row['nom_filiere'],
        'groupe'     => $row['nom_groupe'],
        'idGroupe'   => (int)$row['id_groupe'], // utilisé par le JS pour présélectionner le bon groupe (évite toute ambiguïté en cas de noms de groupe identiques)
        'statut'     => 'Actif', // champ non stocké en base, non affiché dans ce design
        'dateEntree' => $row['date_inscription'],
        'paiement'   => ($statutBrut === 'Payé') ? 'Payé' : 'Non payé',
    ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Élèves — Liste</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lucide-static/0.469.0/umd/lucide.min.js"></script>
<style>
  :root{
    --bg: #f4f5fb;
    --card: #ffffff;
    --border: #eceefb;
    --text-main: #1f2440;
    --text-sub: #8b8fa8;
    --purple: #6c5ce7;
    --purple-dark: #5a4bd6;
    --purple-soft: #eeecff;
    --purple-soft-icon: #f0edff;
    --green-bg: #e8f9ee;
    --green-text: #21a35e;
    --red-bg: #fdecec;
    --red-text: #e35555;
    --amber-bg: #fff4e0;
    --amber-text: #d68b1a;
    --radius-lg: 18px;
    --shadow: 0 1px 3px rgba(31,36,64,0.04), 0 8px 24px -12px rgba(31,36,64,0.08);
  }

  *{ box-sizing: border-box; }

  body{
    margin:0;
    font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
    background: var(--bg);
    color: var(--text-main);
    -webkit-font-smoothing: antialiased;
  }

  .page{
    max-width: 1280px;
    margin: 0 auto;
    padding: 28px 32px 60px;
  }

  /* ---------- Bande Espace Étudiant ---------- */
  .student-banner{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap: 24px;
    background: linear-gradient(120deg, #6c5ce7 0%, #5a4bd6 55%, #4c3fc9 100%);
    border-radius: 22px;
    padding: 30px 40px;
    margin-bottom: 22px;
    box-shadow: 0 14px 34px -14px rgba(90,75,214,0.55);
    overflow: hidden;
    position: relative;
  }

  .student-banner::before{
    content:"";
    position:absolute;
    top:-60px;
    right:120px;
    width: 220px;
    height: 220px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
  }

  .student-banner-text{ position:relative; z-index:1; max-width: 560px; }

  .student-banner-tag{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background: rgba(255,255,255,0.16);
    color:#fff;
    font-size: 12.5px;
    font-weight:700;
    padding: 6px 14px;
    border-radius: 999px;
    margin-bottom: 14px;
  }
  .student-banner-tag svg{ width:14px; height:14px; }

  .student-banner-title{
    font-size: 24px;
    font-weight:700;
    color:#fff;
    margin: 0 0 8px;
    line-height:1.3;
  }

  .student-banner-sub{
    font-size: 14px;
    color: rgba(255,255,255,0.82);
    margin: 0;
  }

  .student-banner-illustration{
    position:relative;
    z-index:1;
    flex-shrink:0;
    width: 170px;
  }
  .student-banner-illustration svg{ width:100%; height:auto; display:block; }

  /* ---------- Breadcrumb ---------- */
  .breadcrumb{
    display:flex;
    align-items:center;
    gap:8px;
    font-size: 14px;
    color: var(--text-sub);
    margin-bottom: 20px;
  }
  .breadcrumb a{ color: var(--text-sub); text-decoration:none; transition: color .15s ease; }
  .breadcrumb a:hover{ color: var(--purple); }
  .breadcrumb .current{ color: var(--text-main); font-weight:600; }
  .breadcrumb svg{ width:14px; height:14px; }

  /* ---------- Main card ---------- */
  .main-card{
    background: var(--card);
    border-radius: 24px;
    border: 1px solid var(--border);
    padding: 26px 30px 30px;
    box-shadow: var(--shadow);
  }

  .card-header-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 14px;
  }

  .card-title{
    font-size: 20px;
    font-weight: 700;
    margin: 0;
  }
  .card-subtitle{
    font-size: 13.5px;
    color: var(--text-sub);
    margin: 4px 0 0;
  }

  .btn{
    display:inline-flex;
    align-items:center;
    gap:8px;
    font-size:14px;
    font-weight:600;
    padding: 11px 20px;
    border-radius: 12px;
    cursor:pointer;
    border:1px solid var(--border);
    transition: all .15s ease;
    white-space: nowrap;
  }
  .btn svg{ width:16px; height:16px; }

  .btn-ghost{ background:#fff; color: var(--text-main); }
  .btn-ghost:hover{ background:#f7f7fc; border-color:#dcdff2; }

  .btn-primary{
    background: var(--purple);
    color:#fff;
    border-color: var(--purple);
    box-shadow: 0 6px 16px -6px rgba(108,92,231,0.55);
  }
  .btn-primary:hover{ background: var(--purple-dark); }

  .btn-danger-soft{
    background: var(--red-bg);
    color: var(--red-text);
    border-color: var(--red-bg);
  }
  .btn-danger-soft:hover{ background:#fbdcdc; }

  /* ---------- Filters ---------- */
  .filters{
    display:flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 22px;
  }

  .filter-search{
    display:flex;
    align-items:center;
    gap:10px;
    background:#fff;
    border:1px solid var(--border);
    border-radius: 12px;
    padding: 11px 16px;
    flex: 1 1 220px;
    min-width: 200px;
  }
  .filter-search input{
    border:none; outline:none; font-size:14px; width:100%; background:transparent; color: var(--text-main);
  }
  .filter-search svg{ width:17px; height:17px; color: var(--text-sub); flex-shrink:0;}

  select.filter-select{
    appearance:none;
    -webkit-appearance:none;
    background:#fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="%238b8fa8" stroke-width="2"><path d="M4 6l4 4 4-4"/></svg>') no-repeat right 14px center;
    border:1px solid var(--border);
    border-radius: 12px;
    padding: 11px 38px 11px 16px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-main);
    cursor:pointer;
    min-width: 170px;
  }

  .reset-btn{
    display:flex;
    align-items:center;
    gap:6px;
    font-size: 13.5px;
    font-weight:600;
    color: var(--text-sub);
    background: none;
    border: none;
    cursor:pointer;
    padding: 0 6px;
  }
  .reset-btn:hover{ color: var(--purple); }
  .reset-btn svg{ width:15px; height:15px; }

  /* ---------- Table ---------- */
  .table-wrap{
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
  }

  table{
    width:100%;
    border-collapse: collapse;
    font-size: 14px;
  }

  thead tr{ background: #fafaff; }

  th{
    text-align:left;
    padding: 14px 18px;
    font-size: 12.5px;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: var(--text-sub);
    font-weight: 700;
    border-bottom: 1px solid var(--border);
    white-space:nowrap;
  }

  td{
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    color: var(--text-main);
    vertical-align: middle;
  }

  tbody tr:last-child td{ border-bottom: none; }
  tbody tr{ transition: background .12s ease; }
  tbody tr:hover{ background: #fbfbff; }

  .student-cell{
    display:flex;
    align-items:center;
    gap: 12px;
  }
  .student-avatar{
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(160deg, #cfe6ff 0%, #e9d8ff 100%);
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size: 13.5px;
    color: #4b3f8f;
    flex-shrink:0;
  }
  .student-name{ font-weight:600; }
  .student-sub{ font-size: 12.5px; color: var(--text-sub); }

  .pill{
    display:inline-flex;
    align-items:center;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 12.5px;
    font-weight: 700;
    white-space:nowrap;
  }
  .pill-purple{ background: var(--purple-soft); color: var(--purple); }
  .pill-green{ background: var(--green-bg); color: var(--green-text); }
  .pill-red{ background: var(--red-bg); color: var(--red-text); }
  .pill-amber{ background: var(--amber-bg); color: var(--amber-text); }

  .actions-cell{
    display:flex;
    gap: 8px;
    justify-content:flex-end;
  }

  .action-icon-btn{
    width: 34px;
    height: 34px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    color: var(--text-sub);
    transition: all .15s ease;
  }
  .action-icon-btn svg{ width:16px; height:16px; }
  .action-icon-btn:hover{ transform: translateY(-1px); }
  .action-icon-btn.edit:hover{ background: var(--purple-soft); color: var(--purple); border-color: var(--purple-soft); }
  .action-icon-btn.delete:hover{ background: var(--red-bg); color: var(--red-text); border-color: var(--red-bg); }
  .action-icon-btn.view:hover{ background: #eef6ff; color:#2f7fd6; border-color:#eef6ff; }

  .action-text-btn{
    padding: 8px 14px;
    font-size: 13px;
    border-radius: 10px;
  }
  .action-text-btn svg{ width:14px; height:14px; }

  .empty-state{
    display:flex;
    flex-direction: column;
    align-items:center;
    justify-content:center;
    padding: 60px 20px;
    color: var(--text-sub);
    text-align:center;
  }
  .empty-state svg{ width:44px; height:44px; margin-bottom: 14px; color: #c9cbe6; }
  .empty-state p{ margin: 0; font-size: 14px; }

  .table-footer{
    display:flex;
    align-items:center;
    justify-content: space-between;
    padding: 16px 4px 4px;
    font-size: 13.5px;
    color: var(--text-sub);
  }

  /* ---------- Modal ---------- */
  .modal-overlay{
    position: fixed;
    inset: 0;
    background: rgba(31,36,64,0.45);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index: 200;
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease;
  }
  .modal-overlay.show{ opacity:1; pointer-events:auto; }

  .modal-box{
    background:#fff;
    border-radius: 20px;
    width: 100%;
    max-width: 420px;
    padding: 26px 26px 24px;
    box-shadow: 0 20px 60px -20px rgba(0,0,0,0.35);
    transform: translateY(14px) scale(.98);
    transition: transform .2s ease;
  }
  .modal-overlay.show .modal-box{ transform: translateY(0) scale(1); }

  .modal-icon{
    width: 46px;
    height: 46px;
    border-radius: 14px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom: 16px;
  }
  .modal-icon svg{ width:22px; height:22px; }
  .modal-icon.purple{ background: var(--purple-soft); color: var(--purple); }
  .modal-icon.red{ background: var(--red-bg); color: var(--red-text); }

  .modal-title{ font-size: 17px; font-weight:700; margin: 0 0 6px; }
  .modal-text{ font-size: 14px; color: var(--text-sub); margin: 0 0 20px; line-height:1.5; }
  .modal-text b{ color: var(--text-main); }

  .modal-field label{
    display:block;
    font-size: 13px;
    font-weight:600;
    color: var(--text-main);
    margin-bottom: 8px;
  }
  .modal-field select{
    width:100%;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid var(--border);
    font-size: 14px;
    font-weight: 600;
    color: var(--text-main);
    background:#fff;
    margin-bottom: 22px;
    cursor:pointer;
  }

  .modal-actions{
    display:flex;
    gap: 10px;
    justify-content: flex-end;
  }

  .modal-box-lg{ max-width: 480px; }

  .form-row{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }

  .modal-field input[type="text"],
  .modal-field input[type="tel"],
  .modal-field input[type="date"]{
    width:100%;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid var(--border);
    font-size: 14px;
    font-weight: 500;
    color: var(--text-main);
    background:#fff;
    margin-bottom: 18px;
    font-family: inherit;
  }
  .modal-field input:focus{
    outline: 2px solid var(--purple);
    outline-offset: 1px;
  }

  .modal-field select#addGroupe,
  .modal-field select#addStatutPaiement{
    margin-bottom: 18px;
  }

  .field-error{
    display:block;
    font-size: 12px;
    color: var(--red-text);
    margin: -14px 0 14px;
    font-weight: 600;
  }

  @media (max-width: 900px){
    .table-wrap{ overflow-x:auto; }
    table{ min-width: 720px; }
  }

  @media (max-width: 640px){
    .page{ padding: 18px; }
    .student-banner{ flex-direction: column; text-align:center; padding: 26px 22px; }
    .student-banner-text{ max-width:100%; }
    .student-banner-illustration{ width: 130px; }
    .main-card{ padding: 20px 16px 24px; }
    .filters{ flex-direction: column; }
    select.filter-select{ width:100%; }
  }

  button:focus-visible, a:focus-visible, select:focus-visible, input:focus-visible{
    outline: 2px solid var(--purple);
    outline-offset: 2px;
  }

  .toast{
    position: fixed;
    bottom: 28px;
    left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: var(--text-main);
    color: #fff;
    padding: 12px 22px;
    border-radius: 12px;
    font-size: 14px;
    font-weight:600;
    box-shadow: 0 10px 30px -10px rgba(0,0,0,0.4);
    opacity: 0;
    pointer-events:none;
    transition: opacity .25s ease, transform .25s ease;
    z-index: 300;
  }
  .toast.show{ opacity: 1; transform: translateX(-50%) translateY(0); }
</style>
</head>
<body>

<div class="page">

  <!-- Bande Espace Étudiant -->
  <div class="student-banner">
    <div class="student-banner-text">
      <span class="student-banner-tag">
        <i data-lucide="graduation-cap"></i>
        Espace Étudiant
      </span>
      <h1 class="student-banner-title">Gérez tous vos élèves en un seul endroit</h1>
      <p class="student-banner-sub">Ajoutez, modifiez et suivez la liste de vos élèves facilement.</p>
    </div>
    <div class="student-banner-illustration">
      <svg viewBox="0 0 220 180" xmlns="http://www.w3.org/2000/svg">
        <ellipse cx="110" cy="165" rx="85" ry="10" fill="#000" opacity="0.06"/>
        <circle cx="110" cy="70" r="66" fill="#ffffff" opacity="0.14"/>
        <path d="M62 150 C62 118 82 96 110 96 C138 96 158 118 158 150 Z" fill="#2b2338"/>
        <circle cx="110" cy="66" r="30" fill="#f6c199"/>
        <path d="M80 60 C80 40 94 28 110 28 C126 28 140 40 140 60 C140 60 133 52 128 52 C128 52 125 44 110 43 C95 44 92 52 92 52 C87 52 80 60 80 60 Z" fill="#2b2338"/>
        <g transform="translate(70,118)">
          <path d="M0 26 L40 34 L80 26 L80 40 L40 48 L0 40 Z" fill="#ffffff"/>
          <path d="M0 26 L40 34 L80 26" fill="none" stroke="#d8dcf5" stroke-width="1.5"/>
          <line x1="40" y1="34" x2="40" y2="48" stroke="#d8dcf5" stroke-width="1.5"/>
          <line x1="8" y1="29" x2="8" y2="43" stroke="#eceefb" stroke-width="1"/>
          <line x1="16" y1="31" x2="16" y2="45" stroke="#eceefb" stroke-width="1"/>
          <line x1="64" y1="31" x2="64" y2="43" stroke="#eceefb" stroke-width="1"/>
          <line x1="72" y1="29" x2="72" y2="41" stroke="#eceefb" stroke-width="1"/>
        </g>
        <path d="M55 150 C60 130 75 118 110 118 C145 118 160 130 165 150 Z" fill="#6c5ce7"/>
      </svg>
    </div>
  </div>

  <!-- Breadcrumb -->
  <nav class="breadcrumb" aria-label="Fil d'ariane">
    <a href="dashboard.php">Dashboard</a>
    <i data-lucide="chevron-right"></i>
    <span class="current">Élèves</span>
  </nav>

  <!-- Main card -->
  <div class="main-card">

    <div class="card-header-row">
      <div>
        <h1 class="card-title">Liste des élèves</h1>
        <p class="card-subtitle"><span id="countLabel">0</span> élève(s) — tous groupes et filières confondus</p>
      </div>
      <button class="btn btn-primary" id="addBtn">
        <i data-lucide="plus"></i>
        Ajouter un élève
      </button>
    </div>

    <!-- Filters -->
    <div class="filters">
      <div class="filter-search">
        <i data-lucide="search"></i>
        <input id="searchInput" type="text" placeholder="Rechercher un élève par nom...">
      </div>
      <select id="filterFiliere" class="filter-select">
        <option value="">Toutes les filières</option>
      </select>
      <select id="filterGroupe" class="filter-select">
        <option value="">Tous les groupes</option>
      </select>
      <button class="reset-btn" id="resetFilters">
        <i data-lucide="x-circle"></i>
        Réinitialiser
      </button>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Élève</th>
            <th>Filière</th>
            <th>Groupe</th>
            <th>Paiement</th>
            <th style="text-align:right;">Modifier / Supprimer</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <!-- rows injected by JS -->
        </tbody>
      </table>
      <div class="empty-state" id="emptyState" style="display:none;">
        <i data-lucide="search-x"></i>
        <p>Aucun élève ne correspond à votre recherche.</p>
      </div>
    </div>

    <div class="table-footer">
      <span id="footerCount"></span>
    </div>

  </div>
</div>

<!-- Modal: modifier élève -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box modal-box-lg">
    <div class="modal-icon purple"><i data-lucide="pencil"></i></div>
    <h3 class="modal-title">Modifier l'élève</h3>
    <p class="modal-text">Mettez à jour les informations de <b id="editModalName"></b>.</p>

    <form id="editForm">
      <div class="form-row">
        <div class="modal-field">
          <label for="editNom">Nom</label>
          <input type="text" id="editNom" name="nom" required>
        </div>
        <div class="modal-field">
          <label for="editPrenom">Prénom</label>
          <input type="text" id="editPrenom" name="prenom" required>
        </div>
      </div>

      <div class="modal-field">
        <label for="editTel">Numéro de téléphone</label>
        <input type="tel" id="editTel" name="tel" required>
      </div>

      <div class="modal-field">
        <label for="editGroupe">Groupe (matière à laquelle il assiste)</label>
        <select id="editGroupe" name="groupe" required></select>
      </div>

      <div class="form-row">
        <div class="modal-field">
          <label for="editDateEntree">Date d'entrée</label>
          <input type="date" id="editDateEntree" name="dateEntree" required>
        </div>
        <div class="modal-field">
          <label for="editStatutPaiement">Statut de paiement</label>
          <select id="editStatutPaiement" name="paiement" required>
            <option value="Payé">Payé</option>
            <option value="Non payé">Non payé</option>
          </select>
        </div>
      </div>

      <div class="modal-actions">
        <button class="btn btn-ghost" id="cancelEditBtn" type="button">Annuler</button>
        <button class="btn btn-primary" id="confirmEditBtn" type="submit">
          <i data-lucide="check"></i>
          Enregistrer
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: supprimer -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-icon red"><i data-lucide="trash-2"></i></div>
    <h3 class="modal-title">Supprimer l'élève</h3>
    <p class="modal-text">Voulez-vous vraiment supprimer <b id="deleteModalName"></b> ? Cette action est irréversible.</p>
    <div class="modal-actions">
      <button class="btn btn-ghost" id="cancelDeleteBtn">Annuler</button>
      <button class="btn btn-danger-soft" id="confirmDeleteBtn">
        <i data-lucide="trash-2"></i>
        Supprimer
      </button>
    </div>
  </div>
</div>

<!-- Modal: ajouter élève -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box modal-box-lg">
    <div class="modal-icon purple"><i data-lucide="user-plus"></i></div>
    <h3 class="modal-title">Ajouter un élève</h3>
    <p class="modal-text">Renseignez les informations de l'élève.</p>

    <form id="addForm">
      <div class="form-row">
        <div class="modal-field">
          <label for="addNom">Nom</label>
          <input type="text" id="addNom" name="nom" placeholder="Ex : Ben Ali" required>
        </div>
        <div class="modal-field">
          <label for="addPrenom">Prénom</label>
          <input type="text" id="addPrenom" name="prenom" placeholder="Ex : Achref" required>
        </div>
      </div>

      <div class="modal-field">
        <label for="addTel">Numéro de téléphone</label>
        <input type="tel" id="addTel" name="tel" placeholder="Ex : 55 123 456" required>
      </div>

      <div class="modal-field">
        <label for="addGroupe">Groupe (matière à laquelle il assiste)</label>
        <select id="addGroupe" name="groupe" required></select>
      </div>

      <div class="form-row">
        <div class="modal-field">
          <label for="addDateEntree">Date d'entrée</label>
          <input type="date" id="addDateEntree" name="dateEntree" required>
        </div>
        <div class="modal-field">
          <label for="addStatutPaiement">Statut de paiement</label>
          <select id="addStatutPaiement" name="paiement" required>
            <option value="Payé">Payé</option>
            <option value="Non payé">Non payé</option>
          </select>
        </div>
      </div>

      <div class="modal-actions">
        <button class="btn btn-ghost" id="cancelAddBtn" type="button">Annuler</button>
        <button class="btn btn-primary" id="confirmAddBtn" type="submit">
          <i data-lucide="check"></i>
          Ajouter
        </button>
      </div>
    </form>
  </div>
</div>

<div class="toast" id="toast">Action effectuée</div>

<script>
(function(){
  'use strict';

  function safeCreateIcons(){
    try {
      if(window.lucide && typeof lucide.createIcons === 'function'){
        lucide.createIcons();
      }
    } catch(err){
      console.error('Erreur lucide-icons:', err);
    }
  }

  safeCreateIcons();

  /* ---------- Données (chargées depuis la base via PHP) ----------
     groupesParFiliere : { "Bac Math": [{id, nom}, ...], ... }
     Chaque groupe est identifié par son id (et non son nom) pour éviter
     toute ambiguïté si deux groupes portent le même nom dans deux filières
     différentes. */
  const groupesParFiliere = <?php echo json_encode($groupesParFiliere, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  const toutesLesFilieres = Object.keys(groupesParFiliere);
  const tousLesGroupes = Object.values(groupesParFiliere).flat(); // [{id, nom}, ...]

  function initiales(nom, prenom){
    const n = nom && nom[0] ? nom[0] : '';
    const p = prenom && prenom[0] ? prenom[0] : '';
    return (p + n).toUpperCase();
  }

  /* ---------- Échappement HTML (protection XSS) ----------
     Les données élève viennent de la base ; on échappe tout texte inséré
     via innerHTML pour empêcher qu'une valeur contenant des caractères
     HTML ne casse l'affichage ou n'exécute du code dans le navigateur. */
  function escapeHtml(str){
    if(str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  let eleves = <?php echo json_encode($eleves, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  let currentTargetId = null;

  /* ---------- Populate filter selects ---------- */
  const filterFiliere = document.getElementById('filterFiliere');
  toutesLesFilieres.forEach(f => {
    const opt = document.createElement('option');
    opt.value = f; opt.textContent = f;
    filterFiliere.appendChild(opt);
  });

  const filterGroupe = document.getElementById('filterGroupe');
  tousLesGroupes.forEach(g => {
    const opt = document.createElement('option');
    opt.value = g.id; opt.textContent = g.nom;
    filterGroupe.appendChild(opt);
  });

  /* ---------- Render ---------- */
  const tableBody = document.getElementById('tableBody');
  const emptyState = document.getElementById('emptyState');
  const countLabel = document.getElementById('countLabel');
  const footerCount = document.getElementById('footerCount');

  function getFiltered(){
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const fil = filterFiliere.value;
    const grp = filterGroupe.value;

    return eleves.filter(e => {
      const fullName = (e.prenom + " " + e.nom).toLowerCase();
      const matchQ = !q || fullName.includes(q);
      const matchFil = !fil || e.filiere === fil;
      const matchGrp = !grp || e.idGroupe === Number(grp);
      return matchQ && matchFil && matchGrp;
    });
  }

  function render(){
    const list = getFiltered();
    tableBody.innerHTML = '';

    emptyState.style.display = list.length === 0 ? 'flex' : 'none';

    list.forEach(e => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>
          <div class="student-cell">
            <div class="student-avatar">${escapeHtml(initiales(e.nom, e.prenom))}</div>
            <div>
              <div class="student-name">${escapeHtml(e.prenom)} ${escapeHtml(e.nom)}</div>
              <div class="student-sub">${escapeHtml(e.tel || '')}</div>
            </div>
          </div>
        </td>
        <td>${escapeHtml(e.filiere)}</td>
        <td><span class="pill pill-purple">${escapeHtml(e.groupe)}</span></td>
        <td><span class="pill ${e.paiement === 'Payé' ? 'pill-green' : 'pill-amber'}">${escapeHtml(e.paiement || 'Non payé')}</span></td>
        <td>
          <div class="actions-cell">
            <button class="btn btn-ghost action-text-btn" title="Modifier" data-action="edit" data-id="${e.id}">
              <i data-lucide="pencil"></i>
              Modifier
            </button>
            <button class="btn btn-danger-soft action-text-btn" title="Supprimer" data-action="delete" data-id="${e.id}">
              <i data-lucide="trash-2"></i>
              Supprimer
            </button>
          </div>
        </td>
      `;
      tableBody.appendChild(tr);
    });

    safeCreateIcons();
    countLabel.textContent = eleves.length;
    footerCount.textContent = `Affichage de ${list.length} sur ${eleves.length} élève(s)`;
  }

  /* ---------- Filters events ---------- */
  document.getElementById('searchInput').addEventListener('input', render);
  filterFiliere.addEventListener('change', render);
  filterGroupe.addEventListener('change', render);

  document.getElementById('resetFilters').addEventListener('click', () => {
    document.getElementById('searchInput').value = '';
    filterFiliere.value = '';
    filterGroupe.value = '';
    render();
  });

  /* ---------- Toast ---------- */
  function showToast(msg){
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
  }

  /* ---------- Modal: modifier élève ---------- */
  const editModal = document.getElementById('editModal');
  const editModalName = document.getElementById('editModalName');
  const editForm = document.getElementById('editForm');
  const editGroupeSelect = document.getElementById('editGroupe');
  const cancelEditBtn = document.getElementById('cancelEditBtn');

  if(editGroupeSelect){
    tousLesGroupes.forEach(g => {
      const opt = document.createElement('option');
      opt.value = g.id; opt.textContent = g.nom;
      editGroupeSelect.appendChild(opt);
    });
  }

  function openEditModal(id){
    const eleve = eleves.find(e => e.id === id);
    if(!eleve) return;
    currentTargetId = id;

    editModalName.textContent = `${eleve.prenom} ${eleve.nom}`;
    document.getElementById('editNom').value = eleve.nom;
    document.getElementById('editPrenom').value = eleve.prenom;
    document.getElementById('editTel').value = eleve.tel || '';
    editGroupeSelect.value = eleve.idGroupe;
    document.getElementById('editDateEntree').value = eleve.dateEntree || '';
    document.getElementById('editStatutPaiement').value = eleve.paiement || 'Payé';

    editModal.classList.add('show');
  }

  function closeEditModal(){
    editModal.classList.remove('show');
    currentTargetId = null;
  }

  if(cancelEditBtn){
    cancelEditBtn.addEventListener('click', (e) => {
      e.preventDefault();
      closeEditModal();
    });
  }

  editModal.addEventListener('click', (e) => { if(e.target === editModal) closeEditModal(); });

  editForm.addEventListener('submit', (e) => {
    e.preventDefault();

    if(!editForm.checkValidity()){
      editForm.reportValidity();
      return;
    }

    const eleve = eleves.find(e => e.id === currentTargetId);
    if(!eleve) { closeEditModal(); return; }

    const nom = document.getElementById('editNom').value.trim();
    const prenom = document.getElementById('editPrenom').value.trim();
    const tel = document.getElementById('editTel').value.trim();
    const groupeId = editGroupeSelect.value; // id_groupe (en string)
    const groupeNom = editGroupeSelect.options[editGroupeSelect.selectedIndex].text;
    const dateEntree = document.getElementById('editDateEntree').value;
    const paiement = document.getElementById('editStatutPaiement').value;

    const confirmBtn = document.getElementById('confirmEditBtn');
    if(confirmBtn) confirmBtn.disabled = true;

    fetch('eleve.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        ajax_action: 'edit', id: currentTargetId,
        nom, prenom, tel, groupe: groupeId, dateEntree, paiement
      })
    })
    .then(res => res.json())
    .then(data => {
      if(data.success){
        eleve.nom = nom;
        eleve.prenom = prenom;
        eleve.tel = tel;
        eleve.idGroupe = Number(groupeId);
        eleve.groupe = data.groupe || groupeNom;
        eleve.filiere = data.filiere || filiereFromGroupeId(eleve.idGroupe);
        eleve.dateEntree = dateEntree;
        eleve.paiement = paiement;

        showToast(`Informations mises à jour pour ${eleve.prenom} ${eleve.nom}`);
        render();
      } else {
        showToast(data.message || "Erreur lors de la modification.");
      }
    })
    .catch(() => showToast("Erreur réseau. Réessayez."))
    .finally(() => {
      if(confirmBtn) confirmBtn.disabled = false;
      closeEditModal();
    });
  });

  /* ---------- Modals: supprimer ---------- */
  const deleteModal = document.getElementById('deleteModal');
  const deleteModalName = document.getElementById('deleteModalName');

  function openDeleteModal(id){
    const eleve = eleves.find(e => e.id === id);
    if(!eleve) return;
    currentTargetId = id;
    deleteModalName.textContent = `${eleve.prenom} ${eleve.nom}`;
    deleteModal.classList.add('show');
  }

  function closeDeleteModal(){
    deleteModal.classList.remove('show');
    currentTargetId = null;
  }

  document.getElementById('cancelDeleteBtn').addEventListener('click', closeDeleteModal);
  deleteModal.addEventListener('click', (e) => { if(e.target === deleteModal) closeDeleteModal(); });

  document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    const eleve = eleves.find(e => e.id === currentTargetId);
    if(!eleve) { closeDeleteModal(); return; }

    const idASupprimer = currentTargetId;
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    confirmBtn.disabled = true;

    fetch('eleve.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ ajax_action: 'delete', id: idASupprimer })
    })
    .then(res => res.json())
    .then(data => {
      if(data.success){
        eleves = eleves.filter(e => e.id !== idASupprimer);
        showToast(`${eleve.prenom} ${eleve.nom} a été supprimé(e)`);
        render();
      } else {
        showToast(data.message || "Erreur lors de la suppression.");
      }
    })
    .catch(() => showToast("Erreur réseau. Réessayez."))
    .finally(() => {
      confirmBtn.disabled = false;
      closeDeleteModal();
    });
  });

  /* ---------- Row actions ---------- */
  tableBody.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action]');
    if(!btn) return;
    const id = Number(btn.dataset.id);
    const action = btn.dataset.action;

    if(action === 'edit') openEditModal(id);
    else if(action === 'delete') openDeleteModal(id);
  });

  /* ---------- Modal: ajouter élève ---------- */
  const addModal = document.getElementById('addModal');
  const addForm = document.getElementById('addForm');
  const addGroupeSelect = document.getElementById('addGroupe');
  const addBtn = document.getElementById('addBtn');
  const cancelAddBtn = document.getElementById('cancelAddBtn');

  if(addGroupeSelect){
    tousLesGroupes.forEach(g => {
      const opt = document.createElement('option');
      opt.value = g.id; opt.textContent = g.nom;
      addGroupeSelect.appendChild(opt);
    });
  }

  function openAddModal(){
    if(addForm) addForm.reset();
    if(addGroupeSelect) addGroupeSelect.selectedIndex = 0;
    const dateField = document.getElementById('addDateEntree');
    if(dateField) dateField.value = new Date().toISOString().split('T')[0];
    addModal.classList.add('show');
    const nomField = document.getElementById('addNom');
    if(nomField) nomField.focus();
  }

  function closeAddModal(){
    addModal.classList.remove('show');
  }

  if(addBtn){
    addBtn.addEventListener('click', openAddModal);
  } else {
    console.error('Bouton #addBtn introuvable dans le DOM.');
  }

  if(cancelAddBtn){
    cancelAddBtn.addEventListener('click', (e) => {
      e.preventDefault();
      closeAddModal();
    });
  }

  addModal.addEventListener('click', (e) => { if(e.target === addModal) closeAddModal(); });

  function filiereFromGroupeId(idGroupe){
    for(const [fil, groupes] of Object.entries(groupesParFiliere)){
      if(groupes.some(g => g.id === idGroupe)) return fil;
    }
    return '';
  }

  addForm.addEventListener('submit', (e) => {
    e.preventDefault();

    if(!addForm.checkValidity()){
      addForm.reportValidity();
      return;
    }

    const nom = document.getElementById('addNom').value.trim();
    const prenom = document.getElementById('addPrenom').value.trim();
    const tel = document.getElementById('addTel').value.trim();
    const groupeId = addGroupeSelect.value; // id_groupe (en string)
    const groupeNom = addGroupeSelect.options[addGroupeSelect.selectedIndex].text;
    const dateEntree = document.getElementById('addDateEntree').value;
    const paiement = document.getElementById('addStatutPaiement').value;

    const confirmBtn = document.getElementById('confirmAddBtn');
    if(confirmBtn) confirmBtn.disabled = true;

    fetch('eleve.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        ajax_action: 'add', nom, prenom, tel, groupe: groupeId, dateEntree, paiement
      })
    })
    .then(res => res.json())
    .then(data => {
      if(data.success){
        const idGroupeNum = Number(groupeId);
        const nouvelEleve = {
          id: data.id,
          nom, prenom, tel,
          filiere: data.filiere || filiereFromGroupeId(idGroupeNum),
          groupe: data.groupe || groupeNom,
          idGroupe: idGroupeNum,
          statut: "Actif",
          dateEntree,
          paiement
        };
        eleves.unshift(nouvelEleve);
        showToast(`${prenom} ${nom} a été ajouté(e)`);
        closeAddModal();
        render();
      } else {
        showToast(data.message || "Erreur lors de l'ajout.");
      }
    })
    .catch(() => showToast("Erreur réseau. Réessayez."))
    .finally(() => {
      if(confirmBtn) confirmBtn.disabled = false;
    });
  });

  /* Escape key closes modals */
  document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape'){
      closeEditModal();
      closeDeleteModal();
      closeAddModal();
    }
  });

  render();

})();
</script>

</body>
</html>