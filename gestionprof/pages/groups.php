<?php
/* ============================================================
   groups.php — Page "Groupes"
   Logique PHP : récupère depuis la base gestion_etude toutes les
   filières, leurs groupes, et les élèves de chaque groupe avec
   leur statut de paiement (pour le mois en cours).
   La structure de données finale ($FILIERES) reproduit EXACTEMENT
   la forme de l'ancien objet JS statique, afin de ne toucher à
   aucune ligne de HTML/CSS/JS existante — seule la source des
   données change (données réelles au lieu de données codées en dur).
   ============================================================ */

 
   require_once("../config/database.php");
   

// ---- Association filière -> icône / couleur / clé JS (reprend le design d'origine) ----
$iconMap = [
    'Bac Informatique' => ['key' => 'informatique',  'icon' => 'laptop',  'iconBg' => 'purple'],
    'Bac Math'          => ['key' => 'mathematiques', 'icon' => 'compass', 'iconBg' => 'blue'],
    'Bac Sciences'      => ['key' => 'sciences',      'icon' => 'flask',   'iconBg' => 'green'],
    'Bac Technique'     => ['key' => 'technique',     'icon' => 'gear',    'iconBg' => 'orange'],
];

// ---- Mois / année actuels (pour le calcul du statut de paiement) ----
$moisFr = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$moisActuelNum  = (int)date('n');           // 1 à 12
$anneeActuelle  = (int)date('Y');

/* ---------- 1. Filières ---------- */
$filieres = []; // id_filiere => nom_filiere
$resFilieres = $conn->query("SELECT id_filiere, nom_filiere FROM filiere ORDER BY id_filiere ASC");
while ($row = $resFilieres->fetch_assoc()) {
    $filieres[$row['id_filiere']] = $row['nom_filiere'];
}

/* ============================================================
   Endpoint AJAX : ajout d'un groupe (bouton "Ajouter un groupe")
   Appelé en POST depuis le JS de la modale ajoutée en bas de page.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'addGroupe') {
    header('Content-Type: application/json; charset=UTF-8');
    $response = ['success' => false];

    $nomGroupe   = trim($_POST['nom_groupe'] ?? '');
    $idFiliere   = (int)($_POST['id_filiere'] ?? 0);
    $capaciteRaw = trim($_POST['capacite'] ?? '');
    $capacite    = ($capaciteRaw === '') ? null : (int)$capaciteRaw;

    if ($nomGroupe === '' || !isset($filieres[$idFiliere])) {
        $response['message'] = "Champs invalides.";
    } else {
        // Le site est utilisé par un seul professeur : le nouveau groupe est
        // rattaché au premier enregistrement de la table `professeur`.
        $idProfesseur = 0;
        $resProf = $conn->query("SELECT id_professeur FROM professeur ORDER BY id_professeur ASC LIMIT 1");
        if ($resProf && ($rowProf = $resProf->fetch_assoc())) {
            $idProfesseur = (int)$rowProf['id_professeur'];
        }

        if ($idProfesseur <= 0) {
            $response['message'] = "Aucun professeur trouvé en base.";
        } else {
            $stmt = $conn->prepare("INSERT INTO groupe (nom_groupe, capacite, id_filiere, id_professeur) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("siii", $nomGroupe, $capacite, $idFiliere, $idProfesseur);
            if ($stmt->execute()) {
                $nouvelIdGroupe = $stmt->insert_id;
                $stmt->close();

                // Détermine la clé JS (ex: "mathematiques") correspondant à la filière,
                // utilisée par le front pour ranger le groupe dans le bon objet FILIERES.
                $nomFiliere = $filieres[$idFiliere];
                $meta = $iconMap[$nomFiliere] ?? ['key' => 'filiere' . $idFiliere];

                $response['success']    = true;
                $response['id_groupe']  = $nouvelIdGroupe;
                $response['nom_groupe'] = $nomGroupe;
                $response['filiereKey'] = $meta['key'];
            } else {
                $response['message'] = "Erreur lors de l'ajout : " . $stmt->error;
            }
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

/* ---------- 2. Groupes ---------- */
$groupes = []; // id_groupe => ['nom_groupe'=>, 'id_filiere'=>]
$resGroupes = $conn->query("SELECT id_groupe, nom_groupe, id_filiere FROM groupe ORDER BY nom_groupe ASC");
while ($row = $resGroupes->fetch_assoc()) {
    $groupes[$row['id_groupe']] = $row;
}

/* ---------- 3. Tous les paiements (utilisés pour déterminer payé / en attente / en retard) ---------- */
$paiementsParEleve = []; // id_eleve => tableau de paiements
$resPaie = $conn->query("SELECT id_eleve, mois, annee, statut FROM paiement");
while ($row = $resPaie->fetch_assoc()) {
    $paiementsParEleve[$row['id_eleve']][] = $row;
}

/* ---------- 4. Élèves ---------- */
$resEleves = $conn->query("SELECT id_eleve, nom, prenom, telephone, id_groupe FROM eleve ORDER BY nom ASC");

/* ---------- 5. Fonctions utilitaires ---------- */

// Convertit un nom de mois français en numéro (1-12)
function moisEnNumero($nomMois, $moisFr) {
    $idx = array_search($nomMois, $moisFr);
    return $idx === false ? 0 : $idx + 1;
}

// Détermine le statut affiché (paye / attente / retard) pour un élève donné
function determinerStatutPaiement($idEleve, $paiementsParEleve, $moisFr, $moisActuelNum, $anneeActuelle) {
    if (!isset($paiementsParEleve[$idEleve])) {
        return 'attente'; // aucun paiement enregistré pour cet élève
    }

    $statutMoisCourant = null;
    $enRetard = false;

    foreach ($paiementsParEleve[$idEleve] as $p) {
        $moisNum  = moisEnNumero($p['mois'], $moisFr);
        $anneeNum = (int)$p['annee'];

        $estMoisCourant = ($moisNum === $moisActuelNum && $anneeNum === $anneeActuelle);
        $estAnterieur   = ($anneeNum < $anneeActuelle) || ($anneeNum === $anneeActuelle && $moisNum < $moisActuelNum);

        if ($estMoisCourant) {
            $statutMoisCourant = $p['statut'];
        } elseif ($estAnterieur && $p['statut'] !== 'Payé') {
            $enRetard = true; // paiement d'un mois passé toujours non soldé
        }
    }

    if ($enRetard) return 'retard';
    if ($statutMoisCourant === 'Payé') return 'paye';
    return 'attente';
}

/* ---------- 6. Construction de la structure FILIERES (identique au format JS d'origine) ---------- */
$FILIERES = [];
$idFiliereParClef = []; // clé JS (ex: "mathematiques") => id_filiere réel, utilisé pour l'ajout de groupe

foreach ($filieres as $idFiliere => $nomFiliere) {
    $meta = $iconMap[$nomFiliere] ?? ['key' => 'filiere' . $idFiliere, 'icon' => 'book', 'iconBg' => 'purple'];
    $FILIERES[$meta['key']] = [
        '_id'      => $idFiliere,
        'name'     => preg_replace('/^Bac\s+/u', '', $nomFiliere),
        'icon'     => $meta['icon'],
        'iconBg'   => $meta['iconBg'],
        'groups'   => [],
        'students' => [],
    ];
    $idFiliereParClef[$meta['key']] = $idFiliere;
}

// Ajout des groupes à leur filière
// NOTE : chaque groupe est désormais un objet {id, nom} (et non plus une simple
// chaîne) afin de pouvoir cibler précisément un groupe pour le Modifier/Supprimer.
foreach ($groupes as $g) {
    foreach ($FILIERES as $key => &$f) {
        if ($f['_id'] == $g['id_filiere']) {
            $f['groups'][] = [
                'id'  => (int)$g['id_groupe'],
                'nom' => $g['nom_groupe'],
            ];
        }
    }
    unset($f);
}

// Ajout des élèves (avec statut de paiement) à leur filière
while ($eleve = $resEleves->fetch_assoc()) {
    $idGroupe = $eleve['id_groupe'];
    if (!isset($groupes[$idGroupe])) continue; // sécurité : groupe introuvable

    $idFiliere = $groupes[$idGroupe]['id_filiere'];
    $nomGroupe = $groupes[$idGroupe]['nom_groupe'];

    foreach ($FILIERES as $key => &$f) {
        if ($f['_id'] == $idFiliere) {
            $statut = determinerStatutPaiement($eleve['id_eleve'], $paiementsParEleve, $moisFr, $moisActuelNum, $anneeActuelle);
            $f['students'][] = [
                'name'   => trim($eleve['prenom'] . ' ' . $eleve['nom']),
                'phone'  => $eleve['telephone'] ?: '—',
                'group'  => $nomGroupe,
                'status' => $statut,
            ];
        }
    }
    unset($f);
}

// Nettoyage : on retire le champ interne _id avant de l'envoyer au JS
foreach ($FILIERES as $key => &$f) {
    unset($f['_id']);
}
unset($f);

// Filière sélectionnée par défaut = la première trouvée (évite une clé fixe "informatique" si la table change)
$premiereFiliere = array_key_first($FILIERES);

/* ---------- 7. Filtre via GET (venant des boutons "Voir le groupe" du dashboard) ----------
   Exemple : groups.php?filiere=Math -> ouvre directement l'onglet "Bac Math".
   Le reste des filières (Informatique, Sciences, Technique) reste accessible
   normalement via les onglets, seule la filière affichée par défaut change. */
$filiereGetMap = [
    'Informatique' => 'informatique',
    'Math'         => 'mathematiques',
    'Sciences'     => 'sciences',
    'Technique'    => 'technique',
];

if (isset($_GET['filiere'])) {
    $filiereDemandee = trim($_GET['filiere']);
    // Retire un éventuel préfixe "Bac " (ex: dashboard.php envoie "Bac Informatique")
    $filiereDemandeeCourte = preg_replace('/^Bac\s+/iu', '', $filiereDemandee);

    $clefDemandee = $filiereGetMap[$filiereDemandee]
                 ?? $filiereGetMap[$filiereDemandeeCourte]
                 ?? null;

    if ($clefDemandee !== null && isset($FILIERES[$clefDemandee])) {
        $premiereFiliere = $clefDemandee;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Groupes — SmartTeacher</title>
<style>
  :root{
    --purple:#6C5CE7;
    --purple-light:#EFEBFD;
    --purple-dark:#5A4BD1;
    --blue:#3B82F6;
    --blue-light:#E8F0FE;
    --green:#16A34A;
    --green-light:#E8F8ED;
    --orange:#EA8C1F;
    --orange-light:#FDF1E1;
    --red:#E23B3B;
    --red-light:#FBE9E9;
    --pink:#E85D9C;
    --pink-light:#FCEAF3;
    --bg:#F4F5FA;
    --card:#FFFFFF;
    --border:#E7E8F0;
    --text:#1E1F2B;
    --text-muted:#8B8D9C;
    --text-soft:#5B5D6E;
    --radius-lg:18px;
    --radius-md:12px;
    --radius-sm:8px;
    --shadow: 0 1px 2px rgba(20,20,43,0.04), 0 8px 24px -12px rgba(20,20,43,0.08);
  }

  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{
    font-family:'Segoe UI', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background:var(--bg);
    color:var(--text);
    padding:28px 32px 60px;
  }

  .page{max-width:1300px;margin:0 auto;}

  /* ===== Top bar ===== */
  .topbar{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    margin-bottom:26px;
    flex-wrap:wrap;
    gap:16px;
  }
  .title-block h1{
    font-size:28px;
    margin:0 0 6px;
    font-weight:700;
    letter-spacing:-0.5px;
  }
  .breadcrumb{
    color:var(--text-muted);
    font-size:14px;
  }
  .breadcrumb a{color:var(--text-muted);text-decoration:none;transition:color .15s ease;}
  .breadcrumb a:hover{color:var(--purple);}
  .breadcrumb span.current{color:var(--text-soft);}

  .topbar-right{
    display:flex;
    align-items:center;
    gap:14px;
  }
  .search-box{
    display:flex;
    align-items:center;
    gap:8px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:12px;
    padding:10px 16px;
    min-width:230px;
    color:var(--text-muted);
    font-size:14px;
  }
  .search-box input{
    border:none;
    outline:none;
    background:transparent;
    font-size:14px;
    width:100%;
    color:var(--text);
  }
  .icon-btn{
    position:relative;
    width:42px;
    height:42px;
    border-radius:12px;
    background:var(--card);
    border:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    flex-shrink:0;
  }
  .icon-btn .badge{
    position:absolute;
    top:-6px;
    right:-6px;
    background:var(--purple);
    color:white;
    font-size:11px;
    font-weight:700;
    width:18px;height:18px;
    border-radius:50%;
    display:flex;align-items:center;justify-content:center;
  }
  .date-pill{
    display:flex;
    align-items:center;
    gap:8px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:12px;
    padding:10px 16px;
    font-size:14px;
    color:var(--text-soft);
    white-space:nowrap;
  }
  .avatar-btn{
    width:42px;height:42px;
    border-radius:12px;
    background:#2B2C3A;
    color:white;
    display:flex;align-items:center;justify-content:center;
    font-weight:700;
    font-size:14px;
    cursor:pointer;
    flex-shrink:0;
  }

  /* ===== Filière picker ===== */
  .panel{
    background:var(--card);
    border-radius:var(--radius-lg);
    border:1px solid var(--border);
    padding:24px;
    margin-bottom:22px;
    box-shadow:var(--shadow);
  }
  .panel h2{
    margin:0 0 4px;
    font-size:18px;
    font-weight:700;
  }
  .panel .sub{
    margin:0 0 18px;
    color:var(--text-muted);
    font-size:14px;
  }
  .panel-header-row{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    flex-wrap:wrap;
  }
  .filiere-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:16px;
  }
  .filiere-card{
    position:relative;
    border:1.5px solid var(--border);
    border-radius:var(--radius-md);
    padding:22px 16px;
    text-align:center;
    cursor:pointer;
    transition:all .15s ease;
    background:var(--card);
  }
  .filiere-card:hover{border-color:#CFCBEF;}
  .filiere-card.active{
    border-color:var(--purple);
    background:var(--purple-light);
  }
  .filiere-check{
    display:none;
    position:absolute;
    top:10px;right:10px;
    width:20px;height:20px;
    border-radius:50%;
    background:var(--purple);
    color:white;
    align-items:center;justify-content:center;
    font-size:12px;
  }
  .filiere-card.active .filiere-check{display:flex;}
  .filiere-icon{
    width:52px;height:52px;
    border-radius:14px;
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 12px;
  }
  .filiere-card[data-icon-bg="purple"] .filiere-icon{background:var(--purple-light);color:var(--purple);}
  .filiere-card[data-icon-bg="blue"] .filiere-icon{background:var(--blue-light);color:var(--blue);}
  .filiere-card[data-icon-bg="green"] .filiere-icon{background:var(--green-light);color:#1E9E4C;}
  .filiere-card[data-icon-bg="orange"] .filiere-icon{background:var(--orange-light);color:var(--orange);}
  .filiere-card[data-icon-bg="pink"] .filiere-icon{background:var(--pink-light);color:var(--pink);}
  .filiere-name{font-weight:700;font-size:15px;margin-bottom:4px;}
  .filiere-count{font-size:13px;color:var(--text-muted);}

  /* ===== Stats section ===== */
  .stats-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
    flex-wrap:wrap;
    gap:12px;
  }
  .stats-header h2{margin:0;font-size:20px;font-weight:700;}
  .btn-primary{
    background:var(--purple);
    color:white;
    border:none;
    padding:12px 20px;
    border-radius:12px;
    font-size:14px;
    font-weight:600;
    display:flex;
    align-items:center;
    gap:8px;
    cursor:pointer;
    transition:background .15s ease;
  }
  .btn-primary:hover{background:var(--purple-dark);}

  .stats-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
    margin-bottom:24px;
  }
  .stat-card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    padding:20px;
    display:flex;
    align-items:center;
    gap:14px;
  }
  .stat-icon{
    width:48px;height:48px;
    border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;
  }
  .stat-icon.purple{background:var(--purple-light);color:var(--purple);}
  .stat-icon.blue{background:var(--blue-light);color:var(--blue);}
  .stat-icon.green{background:var(--green-light);color:var(--green);}
  .stat-icon.orange{background:var(--orange-light);color:var(--orange);}
  .stat-value{font-size:24px;font-weight:700;line-height:1.2;}
  .stat-label{font-size:13px;color:var(--text-muted);margin-top:2px;}

  /* ===== Table controls ===== */
  .table-controls{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:16px;
    flex-wrap:wrap;
    gap:12px;
  }
  .group-select-wrap{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .group-select-wrap label{font-size:14px;color:var(--text-soft);font-weight:600;}
  select#groupFilter{
    border:1px solid var(--border);
    border-radius:10px;
    padding:10px 14px;
    font-size:14px;
    background:var(--card);
    color:var(--text);
    min-width:170px;
    cursor:pointer;
  }
  .table-search{
    display:flex;
    align-items:center;
    gap:8px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:10px;
    padding:10px 14px;
    min-width:230px;
    color:var(--text-muted);
  }
  .table-search input{
    border:none;outline:none;background:transparent;font-size:14px;width:100%;color:var(--text);
  }

  /* ===== Table ===== */
  .table-wrap{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    overflow:hidden;
    box-shadow:var(--shadow);
  }
  table{width:100%;border-collapse:collapse;}
  thead th{
    text-align:left;
    font-size:13px;
    color:var(--text-muted);
    font-weight:600;
    padding:16px 24px;
    background:#FAFAFD;
    border-bottom:1px solid var(--border);
  }
  tbody td{
    padding:14px 24px;
    font-size:14px;
    border-bottom:1px solid var(--border);
    color:var(--text);
  }
  tbody tr:last-child td{border-bottom:none;}
  tbody tr:hover{background:#FAFAFD;}

  .student-cell{display:flex;align-items:center;gap:12px;}
  .avatar{
    width:38px;height:38px;
    border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    color:white;font-weight:700;font-size:14px;
    flex-shrink:0;
  }
  .student-name{font-weight:600;}

  .group-badge{
    background:var(--purple-light);
    color:var(--purple);
    padding:5px 12px;
    border-radius:8px;
    font-size:13px;
    font-weight:600;
    display:inline-block;
  }

  /* ===== Liste de gestion des groupes (Modifier / Supprimer — ajouté) ===== */
  .groups-manage-row{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-bottom:18px;
  }
  .group-manage-chip{
    display:flex;
    align-items:center;
    gap:6px;
    background:var(--purple-light);
    border-radius:8px;
    padding:5px 6px 5px 12px;
  }
  .group-manage-chip .group-badge{padding:0;background:transparent;}
  .group-manage-chip .chip-btn{
    width:26px;height:26px;
    border:none;
    background:transparent;
    color:var(--purple);
    border-radius:6px;
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;
    flex-shrink:0;
  }
  .group-manage-chip .chip-btn:hover{background:rgba(255,255,255,0.6);}
  .group-manage-chip .chip-btn.delete{color:var(--red);}
  .group-manage-empty{color:var(--text-muted);font-size:13px;}

  .status-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 12px;
    border-radius:20px;
    font-size:13px;
    font-weight:600;
  }
  .status-pill.paye{background:var(--green-light);color:#1E9E4C;}
  .status-pill.attente{background:var(--orange-light);color:var(--orange);}
  .status-pill.retard{background:var(--red-light);color:var(--red);}
  .status-dot{width:7px;height:7px;border-radius:50%;background:currentColor;flex-shrink:0;}

  .kebab{
    cursor:pointer;
    color:var(--text-muted);
    width:32px;height:32px;
    border-radius:8px;
    display:flex;align-items:center;justify-content:center;
  }
  .kebab:hover{background:var(--bg);color:var(--text);}

  .empty-row td{
    text-align:center;
    color:var(--text-muted);
    padding:40px 24px;
    font-size:14px;
  }

  @media (max-width:1100px){
    .filiere-grid{grid-template-columns:repeat(3,1fr);}
    .stats-grid{grid-template-columns:repeat(2,1fr);}
  }
  @media (max-width:640px){
    body{padding:18px;}
    .filiere-grid{grid-template-columns:repeat(2,1fr);}
    .stats-grid{grid-template-columns:1fr;}
    thead{display:none;}
    table, tbody, tr, td{display:block;width:100%;}
    tbody tr{border-bottom:1px solid var(--border);padding:12px 0;}
    tbody td{border:none;padding:6px 24px;}
  }

  /* ===== Modale "Ajouter un groupe" ===== */
  .modal-overlay{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(20,20,43,0.45);
    align-items:center;
    justify-content:center;
    z-index:200;
    padding:20px;
  }
  .modal-overlay.show{display:flex;}
  .modal-box{
    background:var(--card);
    border-radius:var(--radius-lg);
    padding:28px;
    width:100%;
    max-width:420px;
    box-shadow:0 20px 60px -20px rgba(20,20,43,0.35);
  }
  .modal-icon{
    width:44px;height:44px;
    border-radius:12px;
    background:var(--purple-light);
    color:var(--purple);
    display:flex;align-items:center;justify-content:center;
    margin-bottom:14px;
  }
  .modal-title{margin:0 0 6px;font-size:18px;font-weight:700;}
  .modal-text{margin:0 0 20px;color:var(--text-muted);font-size:14px;}
  .modal-field{margin-bottom:16px;}
  .modal-field label{
    display:block;
    font-size:13px;
    font-weight:600;
    color:var(--text);
    margin-bottom:6px;
  }
  .modal-field input,
  .modal-field select{
    width:100%;
    padding:11px 14px;
    border-radius:10px;
    border:1px solid var(--border);
    font-size:14px;
    color:var(--text);
    background:#fff;
    font-family:inherit;
  }
  .modal-field input:focus,
  .modal-field select:focus{
    outline:2px solid var(--purple);
    outline-offset:1px;
  }
  .modal-actions{
    display:flex;
    gap:10px;
    justify-content:flex-end;
    margin-top:6px;
  }
  .btn-ghost{
    background:transparent;
    color:var(--text-soft);
    border:1px solid var(--border);
    padding:12px 20px;
    border-radius:12px;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
  }
  .btn-ghost:hover{background:var(--bg);}

  /* ===== Toast ===== */
  .toast{
    position:fixed;
    bottom:28px;
    left:50%;
    transform:translateX(-50%) translateY(20px);
    background:var(--text);
    color:#fff;
    padding:12px 22px;
    border-radius:12px;
    font-size:14px;
    font-weight:600;
    box-shadow:0 10px 30px -10px rgba(0,0,0,0.4);
    opacity:0;
    pointer-events:none;
    transition:opacity .25s ease, transform .25s ease;
    z-index:300;
  }
  .toast.show{opacity:1;transform:translateX(-50%) translateY(0);}

  /* ===== Notifications dropdown (ajouté — ne modifie aucun style existant) ===== */
  .notif-dropdown{
    display:none;
    position:absolute;
    top:52px;
    right:0;
    width:340px;
    max-height:420px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    box-shadow:var(--shadow);
    z-index:400;
    overflow:hidden;
    flex-direction:column;
    cursor:default;
    text-align:left;
  }
  .notif-dropdown.show{display:flex;}
  .notif-dropdown-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:14px 16px;
    border-bottom:1px solid var(--border);
    font-size:14px;
    font-weight:700;
    color:var(--text);
  }
  .notif-mark-all{
    background:none;
    border:none;
    color:var(--purple);
    font-size:12px;
    font-weight:600;
    cursor:pointer;
    padding:0;
    font-family:inherit;
  }
  .notif-mark-all:hover{text-decoration:underline;}
  .notif-list{
    overflow-y:auto;
    max-height:360px;
  }
  .notif-item{
    display:flex;
    gap:12px;
    padding:12px 16px;
    border-bottom:1px solid var(--border);
    cursor:pointer;
    transition:background .15s ease;
    align-items:flex-start;
  }
  .notif-item:last-child{border-bottom:none;}
  .notif-item:hover{background:var(--bg);}
  .notif-item.unread{background:var(--purple-light);}
  .notif-icon{
    width:36px;height:36px;
    border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;
  }
  .notif-icon.purple{background:var(--purple-light);color:var(--purple);}
  .notif-icon.orange{background:var(--orange-light);color:var(--orange);}
  .notif-icon.red{background:var(--red-light);color:var(--red);}
  .notif-icon.blue{background:var(--blue-light);color:var(--blue);}
  .notif-icon.green{background:var(--green-light);color:var(--green);}
  .notif-content{flex:1;min-width:0;}
  .notif-title{font-size:13.5px;font-weight:700;margin-bottom:2px;color:var(--text);}
  .notif-desc{font-size:12.5px;color:var(--text-soft);margin-bottom:4px;line-height:1.4;}
  .notif-time{font-size:11px;color:var(--text-muted);}
  .notif-empty{padding:28px 16px;text-align:center;color:var(--text-muted);font-size:13px;}
</style>
</head>
<body>
<div class="page">

  <!-- ===== Top bar ===== -->
  <div class="topbar">
    <div class="title-block">
      <h1>Groupes</h1>
      <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> &nbsp;›&nbsp; <span class="current">Groupes</span></div>
    </div>
    <div class="topbar-right">
      <div class="search-box">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" placeholder="Rechercher...">
      </div>
      <div class="icon-btn" id="notifBtn" title="Notifications">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <span class="badge" id="notifBadge" style="display:none;">0</span>

        <!-- ===== Dropdown Notifications (ajouté) ===== -->
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-dropdown-header">
            <span>Notifications</span>
            <button type="button" class="notif-mark-all" id="notifMarkAll">Tout marquer comme lu</button>
          </div>
          <div class="notif-list" id="notifList">
            <div class="notif-empty">Chargement...</div>
          </div>
        </div>
      </div>
      <div class="date-pill">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span id="todayDate">30 Juin 2026</span>
      </div>
      <div class="avatar-btn">A</div>
    </div>
  </div>

  <!-- ===== Filière picker ===== -->
  <div class="panel">
    <div class="panel-header-row">
      <div>
        <h2>Choisir une filière</h2>
        <p class="sub">Sélectionnez une filière pour voir ses groupes</p>
      </div>
      <button class="btn-primary" id="addGroupBtn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Ajouter un groupe
      </button>
    </div>
    <div class="filiere-grid" id="filiereGrid"></div>
  </div>

  <!-- ===== Filière detail ===== -->
  <div class="panel">
    <div class="stats-header">
      <h2 id="filiereTitle">Filière : Informatique</h2>
      <button class="btn-primary" id="filterToggleBtn">
        Filtrer l'affichage
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      </button>
    </div>

    <div class="stats-grid" id="statsGrid"></div>

    <div class="table-controls">
      <div class="group-select-wrap">
        <label for="groupFilter">Groupe</label>
        <select id="groupFilter"></select>
      </div>
      <div class="table-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="studentSearch" placeholder="Rechercher un élève...">
      </div>
    </div>

    <!-- Liste des groupes de la filière courante, avec actions Modifier / Supprimer (ajouté) -->
    <div class="groups-manage-row" id="groupsManageRow"></div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Nom et prénom</th>
            <th>Téléphone</th>
            <th>Groupe</th>
            <th>Statut de paiement</th>
          </tr>
        </thead>
        <tbody id="studentsTbody"></tbody>
      </table>
    </div>
  </div>

</div>

<!-- Modal: ajouter un groupe -->
<div class="modal-overlay" id="addGroupModal">
  <div class="modal-box">
    <div class="modal-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <h3 class="modal-title">Ajouter un groupe</h3>
    <p class="modal-text">Renseignez les informations du nouveau groupe.</p>

    <form id="addGroupForm">
      <div class="modal-field">
        <label for="addGroupNom">Nom du groupe</label>
        <input type="text" id="addGroupNom" name="nom_groupe" placeholder="Ex : Groupe A" required>
      </div>

      <div class="modal-field">
        <label for="addGroupFiliere">Filière</label>
        <select id="addGroupFiliere" name="id_filiere" required></select>
      </div>

      <div class="modal-field">
        <label for="addGroupCapacite">Capacité (optionnel)</label>
        <input type="number" id="addGroupCapacite" name="capacite" min="1" placeholder="Ex : 20">
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" id="cancelAddGroupBtn">Annuler</button>
        <button type="submit" class="btn-primary" id="confirmAddGroupBtn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          Ajouter
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: modifier un groupe (ajouté) -->
<div class="modal-overlay" id="editGroupModal">
  <div class="modal-box">
    <div class="modal-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
    </div>
    <h3 class="modal-title">Modifier le groupe</h3>
    <p class="modal-text">Modifiez les informations du groupe.</p>

    <form id="editGroupForm">
      <input type="hidden" id="editGroupId" name="id_groupe">

      <div class="modal-field">
        <label for="editGroupNom">Nom du groupe</label>
        <input type="text" id="editGroupNom" name="nom_groupe" placeholder="Ex : Groupe A" required>
      </div>

      <div class="modal-field">
        <label for="editGroupFiliere">Filière</label>
        <select id="editGroupFiliere" name="id_filiere" required></select>
      </div>

      <div class="modal-field">
        <label for="editGroupCapacite">Capacité (optionnel)</label>
        <input type="number" id="editGroupCapacite" name="capacite" min="1" placeholder="Ex : 20">
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" id="cancelEditGroupBtn">Annuler</button>
        <button type="submit" class="btn-primary" id="confirmEditGroupBtn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          Enregistrer
        </button>
      </div>
    </form>
  </div>
</div>

<div class="toast" id="toast">Action effectuée</div>

<script>
/* ============ DATA ============ */
const ICONS = {
  laptop: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="14" rx="2"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
  calendar: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
  flask: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 2v6L4 18a2 2 0 0 0 2 3h12a2 2 0 0 0 2-3l-5-10V2"/><line x1="9" y1="2" x2="15" y2="2"/></svg>',
  atom: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><ellipse cx="12" cy="12" rx="10" ry="4.5"/><ellipse cx="12" cy="12" rx="10" ry="4.5" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="10" ry="4.5" transform="rotate(120 12 12)"/></svg>',
  compass: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="7" r="2"/><path d="M12 9 4 20h16z"/></svg>',
  gear: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
  book: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
  users: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  check: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
  user: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  pencil: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>',
  trash: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>'
};

const AVATAR_COLORS = ['#6C5CE7','#3B82F6','#16A34A','#E85D9C','#EA8C1F','#E23B3B','#0EA5A5'];
function initials(name){
  return name.split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();
}
function colorFor(name){
  let sum = 0;
  for(const c of name) sum += c.charCodeAt(0);
  return AVATAR_COLORS[sum % AVATAR_COLORS.length];
}

/* Échappement HTML (protection XSS) — les noms viennent de la base de données. */
function escapeHtml(str){
  if(str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

const FILIERES = <?php echo json_encode($FILIERES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const ID_FILIERE_PAR_CLE = <?php echo json_encode($idFiliereParClef, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

// Map inverse (id_filiere -> clé JS), utilisée pour replacer un groupe modifié
// dans la bonne filière côté client, sans recharger la page (ajouté).
const KEY_PAR_ID_FILIERE = {};
Object.entries(ID_FILIERE_PAR_CLE).forEach(([key, id]) => { KEY_PAR_ID_FILIERE[id] = key; });

let currentFiliere = <?php echo json_encode($premiereFiliere); ?>;

/* ============ RENDER FILIÈRE CARDS ============ */
function renderFiliereGrid(){
  const grid = document.getElementById('filiereGrid');
  grid.innerHTML = '';
  Object.entries(FILIERES).forEach(([key, f])=>{
    const card = document.createElement('div');
    card.className = 'filiere-card' + (key === currentFiliere ? ' active' : '');
    card.dataset.iconBg = f.iconBg;
    card.dataset.key = key;
    card.innerHTML = `
      <div class="filiere-check">✓</div>
      <div class="filiere-icon">${ICONS[f.icon]}</div>
      <div class="filiere-name">${escapeHtml(f.name)}</div>
      <div class="filiere-count">${f.groups.length} Groupe${f.groups.length>1?'s':''}</div>
    `;
    card.addEventListener('click', ()=>{
      currentFiliere = key;
      renderFiliereGrid();
      renderFiliereDetail();
    });
    grid.appendChild(card);
  });
}

/* ============ RENDER STATS ============ */
function renderStats(f){
  const total = f.students.length;
  const paye = f.students.filter(s=>s.status==='paye').length;
  const attente = f.students.filter(s=>s.status==='attente').length;
  const pct = total ? Math.round((paye/total)*100) : 0;

  const grid = document.getElementById('statsGrid');
  grid.innerHTML = `
    <div class="stat-card">
      <div class="stat-icon purple">${ICONS.users}</div>
      <div><div class="stat-value">${f.groups.length}</div><div class="stat-label">Groupes</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon blue">${ICONS.users}</div>
      <div><div class="stat-value">${total}</div><div class="stat-label">Élèves au total</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green">${ICONS.check}</div>
      <div><div class="stat-value">${pct}%</div><div class="stat-label">Paiements à jour</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange">${ICONS.user}</div>
      <div><div class="stat-value">${attente}</div><div class="stat-label">Élèves en attente</div></div>
    </div>
  `;
}

/* ============ RENDER GROUP DROPDOWN ============ */
function renderGroupSelect(f){
  const select = document.getElementById('groupFilter');
  select.innerHTML = '<option value="all">Tous les groupes</option>' +
    f.groups.map(g=>`<option value="${escapeHtml(g.nom)}">${escapeHtml(g.nom)}</option>`).join('');
  select.value = 'all';
}

/* ============ RENDER LISTE DE GESTION DES GROUPES (Modifier / Supprimer — ajouté) ============ */
function renderGroupsManageRow(f){
  const row = document.getElementById('groupsManageRow');

  if(!f.groups.length){
    row.innerHTML = '<span class="group-manage-empty">Aucun groupe dans cette filière.</span>';
    return;
  }

  row.innerHTML = f.groups.map(g => `
    <div class="group-manage-chip" data-id="${g.id}">
      <span class="group-badge">${escapeHtml(g.nom)}</span>
      <button type="button" class="chip-btn edit" title="Modifier" data-id="${g.id}">${ICONS.pencil}</button>
      <button type="button" class="chip-btn delete" title="Supprimer" data-id="${g.id}" data-nom="${escapeHtml(g.nom)}">${ICONS.trash}</button>
    </div>
  `).join('');
}

/* ============ RENDER TABLE ============ */
const STATUS_LABEL = { paye:'Payé', attente:'En attente', retard:'En retard' };

function renderTable(){
  const f = FILIERES[currentFiliere];
  const groupVal = document.getElementById('groupFilter').value;
  const searchVal = document.getElementById('studentSearch').value.trim().toLowerCase();

  let rows = f.students.filter(s=>{
    const groupOk = groupVal === 'all' || s.group === groupVal;
    const searchOk = !searchVal || s.name.toLowerCase().includes(searchVal);
    return groupOk && searchOk;
  });

  const tbody = document.getElementById('studentsTbody');
  if(rows.length === 0){
    tbody.innerHTML = `<tr class="empty-row"><td colspan="4">Aucun élève trouvé.</td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map(s=>`
    <tr>
      <td>
        <div class="student-cell">
          <div class="avatar" style="background:${colorFor(s.name)}">${escapeHtml(initials(s.name))}</div>
          <span class="student-name">${escapeHtml(s.name)}</span>
        </div>
      </td>
      <td>${escapeHtml(s.phone)}</td>
      <td><span class="group-badge">${escapeHtml(s.group)}</span></td>
      <td><span class="status-pill ${s.status}"><span class="status-dot"></span>${escapeHtml(STATUS_LABEL[s.status])}</span></td>
    </tr>
  `).join('');
}

/* ============ FULL REFRESH ============ */
function renderFiliereDetail(){
  const f = FILIERES[currentFiliere];
  document.getElementById('filiereTitle').textContent = `Filière : ${f.name}`;
  renderStats(f);
  renderGroupSelect(f);
  renderGroupsManageRow(f);
  renderTable();
}

document.getElementById('groupFilter').addEventListener('change', renderTable);
document.getElementById('studentSearch').addEventListener('input', renderTable);

document.getElementById('filterToggleBtn').addEventListener('click', ()=>{
  document.getElementById('groupFilter').focus();
});

/* ============ TODAY'S DATE ============ */
(function setDate(){
  const months = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
  const d = new Date();
  document.getElementById('todayDate').textContent = `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
})();

/* ============ TOAST ============ */
function showToast(msg){
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.classList.add('show');
  clearTimeout(window.__toastTimer);
  window.__toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
}

/* ============ MODAL: AJOUTER UN GROUPE ============ */
const addGroupModal   = document.getElementById('addGroupModal');
const addGroupForm    = document.getElementById('addGroupForm');
const addGroupBtn     = document.getElementById('addGroupBtn');
const cancelAddGroupBtn = document.getElementById('cancelAddGroupBtn');
const addGroupFiliereSelect = document.getElementById('addGroupFiliere');

// Peuple le <select> Filière à partir des filières déjà chargées depuis la base
Object.entries(FILIERES).forEach(([key, f]) => {
  const opt = document.createElement('option');
  opt.value = key;
  opt.textContent = f.name;
  addGroupFiliereSelect.appendChild(opt);
});

function openAddGroupModal(){
  addGroupForm.reset();
  // Présélectionne la filière actuellement affichée, par confort
  if(currentFiliere && FILIERES[currentFiliere]){
    addGroupFiliereSelect.value = currentFiliere;
  }
  addGroupModal.classList.add('show');
  document.getElementById('addGroupNom').focus();
}

function closeAddGroupModal(){
  addGroupModal.classList.remove('show');
}

addGroupBtn.addEventListener('click', openAddGroupModal);
cancelAddGroupBtn.addEventListener('click', (e) => { e.preventDefault(); closeAddGroupModal(); });
addGroupModal.addEventListener('click', (e) => { if(e.target === addGroupModal) closeAddGroupModal(); });
document.addEventListener('keydown', (e) => { if(e.key === 'Escape') closeAddGroupModal(); });

addGroupForm.addEventListener('submit', (e) => {
  e.preventDefault();

  if(!addGroupForm.checkValidity()){
    addGroupForm.reportValidity();
    return;
  }

  const nomGroupe   = document.getElementById('addGroupNom').value.trim();
  const filiereKey  = addGroupFiliereSelect.value;
  const idFiliere   = ID_FILIERE_PAR_CLE[filiereKey];
  const capacite    = document.getElementById('addGroupCapacite').value.trim();

  const confirmBtn = document.getElementById('confirmAddGroupBtn');
  confirmBtn.disabled = true;

  fetch('groups.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      ajax_action: 'addGroupe',
      nom_groupe: nomGroupe,
      id_filiere: idFiliere,
      capacite: capacite
    })
  })
  .then(res => res.json())
  .then(data => {
    if(data.success){
      // Met à jour la structure locale sans recharger la page
      FILIERES[data.filiereKey].groups.push({ id: data.id_groupe, nom: data.nom_groupe });

      renderFiliereGrid();
      if(currentFiliere === data.filiereKey){
        renderFiliereDetail();
      }

      showToast(`Groupe "${data.nom_groupe}" ajouté avec succès`);
      closeAddGroupModal();
    } else {
      showToast(data.message || "Erreur lors de l'ajout du groupe.");
    }
  })
  .catch(() => showToast("Erreur réseau. Réessayez."))
  .finally(() => { confirmBtn.disabled = false; });
});

/* ============ MODAL: MODIFIER UN GROUPE (ajouté) ============ */
const editGroupModal        = document.getElementById('editGroupModal');
const editGroupForm         = document.getElementById('editGroupForm');
const cancelEditGroupBtn    = document.getElementById('cancelEditGroupBtn');
const editGroupFiliereSelect = document.getElementById('editGroupFiliere');

// Peuple le <select> Filière de la modale Modifier (mêmes filières que la modale Ajouter)
Object.entries(FILIERES).forEach(([key, f]) => {
  const opt = document.createElement('option');
  opt.value = key;
  opt.textContent = f.name;
  editGroupFiliereSelect.appendChild(opt);
});

function openEditGroupModal(idGroupe){
  editGroupForm.reset();

  fetch(`get_groupe.php?id_groupe=${encodeURIComponent(idGroupe)}`)
    .then(res => res.json())
    .then(data => {
      if(!data.success){
        showToast(data.message || "Impossible de charger ce groupe.");
        return;
      }

      document.getElementById('editGroupId').value = data.id_groupe;
      document.getElementById('editGroupNom').value = data.nom_groupe;
      document.getElementById('editGroupCapacite').value = data.capacite ?? '';

      const filiereKey = KEY_PAR_ID_FILIERE[data.id_filiere];
      if(filiereKey) editGroupFiliereSelect.value = filiereKey;

      editGroupModal.classList.add('show');
      document.getElementById('editGroupNom').focus();
    })
    .catch(() => showToast("Erreur réseau. Réessayez."));
}

function closeEditGroupModal(){
  editGroupModal.classList.remove('show');
}

cancelEditGroupBtn.addEventListener('click', (e) => { e.preventDefault(); closeEditGroupModal(); });
editGroupModal.addEventListener('click', (e) => { if(e.target === editGroupModal) closeEditGroupModal(); });
document.addEventListener('keydown', (e) => { if(e.key === 'Escape') closeEditGroupModal(); });

// Déplace le groupe (et ses élèves affichés) d'une filière à une autre dans les
// données locales, et renomme le groupe partout où il apparaît (sans reload).
function appliquerModificationGroupeLocal(oldFiliereKey, newFiliereKey, idGroupe, oldNom, newNom){
  const oldF = FILIERES[oldFiliereKey];
  const newF = FILIERES[newFiliereKey];

  const idx = oldF.groups.findIndex(g => g.id === idGroupe);
  if(idx !== -1) oldF.groups.splice(idx, 1);
  newF.groups.push({ id: idGroupe, nom: newNom });

  if(oldFiliereKey === newFiliereKey){
    oldF.students.forEach(s => { if(s.group === oldNom) s.group = newNom; });
  } else {
    const eleves = oldF.students.filter(s => s.group === oldNom);
    oldF.students = oldF.students.filter(s => s.group !== oldNom);
    eleves.forEach(s => { s.group = newNom; });
    newF.students.push(...eleves);
  }
}

editGroupForm.addEventListener('submit', (e) => {
  e.preventDefault();

  if(!editGroupForm.checkValidity()){
    editGroupForm.reportValidity();
    return;
  }

  const idGroupe   = document.getElementById('editGroupId').value;
  const nomGroupe  = document.getElementById('editGroupNom').value.trim();
  const filiereKey = editGroupFiliereSelect.value;
  const idFiliere  = ID_FILIERE_PAR_CLE[filiereKey];
  const capacite   = document.getElementById('editGroupCapacite').value.trim();

  // Retrouve l'ancien nom et l'ancienne filière du groupe dans les données locales
  let oldFiliereKey = null;
  let oldNom = null;
  Object.entries(FILIERES).forEach(([key, f]) => {
    const g = f.groups.find(g => g.id == idGroupe);
    if(g){ oldFiliereKey = key; oldNom = g.nom; }
  });

  const confirmBtn = document.getElementById('confirmEditGroupBtn');
  confirmBtn.disabled = true;

  fetch('update_groupe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      id_groupe: idGroupe,
      nom_groupe: nomGroupe,
      id_filiere: idFiliere,
      capacite: capacite
    })
  })
  .then(res => res.json())
  .then(data => {
    if(data.success){
      if(oldFiliereKey){
        appliquerModificationGroupeLocal(oldFiliereKey, filiereKey, Number(idGroupe), oldNom, data.nom_groupe);
      }

      renderFiliereGrid();
      renderFiliereDetail();

      showToast(`Groupe "${data.nom_groupe}" modifié avec succès`);
      closeEditGroupModal();
    } else {
      showToast(data.message || "Erreur lors de la modification du groupe.");
    }
  })
  .catch(() => showToast("Erreur réseau. Réessayez."))
  .finally(() => { confirmBtn.disabled = false; });
});

/* ============ SUPPRIMER UN GROUPE (ajouté) ============ */
function supprimerGroupe(idGroupe, nomGroupe){
  if(!confirm("Êtes-vous sûr de vouloir supprimer ce groupe ?")) return;

  fetch('delete_groupe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ id_groupe: idGroupe })
  })
  .then(res => res.json())
  .then(data => {
    if(data.success){
      // Retire le groupe des données locales (statistiques, cartes, liste)
      Object.values(FILIERES).forEach(f => {
        f.groups = f.groups.filter(g => g.id !== Number(idGroupe));
      });

      renderFiliereGrid();
      renderFiliereDetail();

      showToast(`Groupe "${nomGroupe}" supprimé avec succès`);
    } else {
      showToast(data.message || "Erreur lors de la suppression du groupe.");
    }
  })
  .catch(() => showToast("Erreur réseau. Réessayez."));
}

// Délégation d'événements sur la liste des groupes (boutons Modifier / Supprimer)
document.getElementById('groupsManageRow').addEventListener('click', (e) => {
  const editBtn = e.target.closest('.chip-btn.edit');
  if(editBtn){
    openEditGroupModal(editBtn.dataset.id);
    return;
  }

  const deleteBtn = e.target.closest('.chip-btn.delete');
  if(deleteBtn){
    supprimerGroupe(deleteBtn.dataset.id, deleteBtn.dataset.nom);
  }
});

/* ============ NOTIFICATIONS ============ */
// Icônes spécifiques à chaque type de notification (n'altère pas l'objet ICONS existant)
const NOTIF_ICONS = {
  absence:  { svg: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h1"/><line x1="17" y1="14" x2="22" y2="19"/><line x1="22" y1="14" x2="17" y2="19"/></svg>', cls: 'red' },
  paiement: { svg: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>', cls: 'orange' },
  seance:   { svg: ICONS.calendar, cls: 'blue' },
  groupe:   { svg: ICONS.users,    cls: 'purple' },
  eleve:    { svg: ICONS.user,     cls: 'green' }
};

const notifBtn      = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');
const notifList     = document.getElementById('notifList');
const notifBadge    = document.getElementById('notifBadge');
const notifMarkAll  = document.getElementById('notifMarkAll');

// Affiche une date au format "Il y a X min / X h / Hier / X j"
function notifTimeAgo(dateStr){
  const d = new Date(String(dateStr).replace(' ', 'T'));
  if(isNaN(d.getTime())) return '';
  const diffMin = Math.floor((Date.now() - d.getTime()) / 60000);
  if(diffMin < 1)  return "À l'instant";
  if(diffMin < 60) return `Il y a ${diffMin} min`;
  const diffH = Math.floor(diffMin / 60);
  if(diffH < 24) return `Il y a ${diffH} h`;
  const diffJ = Math.floor(diffH / 24);
  return diffJ === 1 ? 'Hier' : `Il y a ${diffJ} j`;
}

function renderNotifications(notifications){
  if(!notifications.length){
    notifList.innerHTML = '<div class="notif-empty">Aucune notification pour le moment.</div>';
    return;
  }
  notifList.innerHTML = notifications.map(n => {
    const meta = NOTIF_ICONS[n.type] || { svg: ICONS.book, cls: 'purple' };
    return `
      <div class="notif-item ${n.lu ? '' : 'unread'}" data-id="${n.id}">
        <div class="notif-icon ${meta.cls}">${meta.svg}</div>
        <div class="notif-content">
          <div class="notif-title">${escapeHtml(n.titre)}</div>
          <div class="notif-desc">${escapeHtml(n.description)}</div>
          <div class="notif-time">${notifTimeAgo(n.date)}</div>
        </div>
      </div>
    `;
  }).join('');
}

function updateNotifBadge(count){
  if(count > 0){
    notifBadge.textContent = count > 9 ? '9+' : String(count);
    notifBadge.style.display = 'flex';
  } else {
    notifBadge.style.display = 'none';
  }
}

function loadNotifications(){
  fetch('getnotification.php')
    .then(res => res.json())
    .then(data => {
      if(data.success){
        renderNotifications(data.notifications);
        updateNotifBadge(data.unread);
      } else {
        notifList.innerHTML = '<div class="notif-empty">Erreur de chargement.</div>';
      }
    })
    .catch(() => {
      notifList.innerHTML = '<div class="notif-empty">Erreur réseau.</div>';
    });
}

function toggleNotifDropdown(forceState){
  const willShow = (forceState !== undefined) ? forceState : !notifDropdown.classList.contains('show');
  notifDropdown.classList.toggle('show', willShow);
  if(willShow) loadNotifications();
}

// Ouvre / ferme le dropdown au clic sur la cloche
notifBtn.addEventListener('click', (e) => {
  e.stopPropagation();
  toggleNotifDropdown();
});

// Empêche la fermeture quand on clique à l'intérieur du dropdown
notifDropdown.addEventListener('click', (e) => e.stopPropagation());

// Ferme le dropdown si on clique ailleurs sur la page
document.addEventListener('click', () => {
  notifDropdown.classList.remove('show');
});

// Marque une notification comme lue au clic dessus
notifList.addEventListener('click', (e) => {
  const item = e.target.closest('.notif-item');
  if(!item || !item.classList.contains('unread')) return;

  const id = item.dataset.id;
  item.classList.remove('unread');

  fetch('marknotification.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ id })
  })
  .then(res => res.json())
  .then(data => {
    if(data.success){
      const current = parseInt(notifBadge.textContent, 10) || 0;
      updateNotifBadge(Math.max(0, current - 1));
    }
  })
  .catch(() => {});
});

// Bouton "Tout marquer comme lu"
notifMarkAll.addEventListener('click', (e) => {
  e.stopPropagation();
  fetch('marknotification.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'all' })
  })
  .then(res => res.json())
  .then(data => {
    if(data.success){
      document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
      updateNotifBadge(0);
    }
  })
  .catch(() => {});
});

/* ============ INIT ============ */
renderFiliereGrid();
renderFiliereDetail();

// Chargement initial du compteur + rafraîchissement automatique toutes les 60s
loadNotifications();
setInterval(loadNotifications, 60000);
</script>
</body>
</html>