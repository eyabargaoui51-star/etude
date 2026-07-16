<?php
/* =========================================================================
   PRESENCE.PHP — Gestion des présences (version dynamique)
   Connecté à la base "gestion_etude" via mysqli

   ⚠️ IMPORTANT — Contrainte d'unicité recommandée :
   Pour qu'il soit strictement impossible, même en cas de double clic ou de
   requêtes simultanées, d'enregistrer deux présences pour le même élève sur
   la même séance, exécute une seule fois cette requête dans phpMyAdmin :

     ALTER TABLE `presence`
       ADD UNIQUE KEY `uniq_seance_eleve` (`id_seance`, `id_eleve`);

   Le code ci-dessous utilise INSERT ... ON DUPLICATE KEY UPDATE, qui exploite
   cette contrainte : la base elle-même refuse toute deuxième ligne pour la
   même paire (séance, élève) et met simplement à jour le statut existant.
   ========================================================================= */
require_once("../config/auth.php");
require_once("../config/database.php");

/* -------------------------------------------------------------------------
   1) ACTION AJAX : ENREGISTREMENT DE LA PRESENCE (appelée en POST par le JS)
   ------------------------------------------------------------------------- */
if (isset($_GET['action']) && $_GET['action'] === 'save_presence' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json; charset=utf-8');

    $payload   = json_decode(file_get_contents('php://input'), true);
    $id_seance = isset($payload['id_seance']) ? (int)$payload['id_seance'] : 0;
    $liste     = isset($payload['presences']) && is_array($payload['presences']) ? $payload['presences'] : [];

    if ($id_seance <= 0) {
        echo json_encode(['success' => false, 'message' => "Séance invalide."]);
        exit;
    }
    if (empty($liste)) {
        echo json_encode(['success' => false, 'message' => "Aucun élève à enregistrer."]);
        exit;
    }

    // Une seule requête préparée, réutilisée pour chaque élève : la contrainte
    // UNIQUE (id_seance, id_eleve) empêche toute présence en double au niveau
    // de la base elle-même — même en cas de requêtes envoyées simultanément.
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO presence (id_seance, id_eleve, statut) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE statut = VALUES(statut)"
    );
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => "Erreur de préparation de la requête."]);
        exit;
    }

    $erreur = null;
    foreach ($liste as $p) {
        $id_eleve = (int)($p['id_eleve'] ?? 0);
        if ($id_eleve <= 0) continue;
        $statut = (!empty($p['present'])) ? 'Présent' : 'Absent';

        mysqli_stmt_bind_param($stmt, "iis", $id_seance, $id_eleve, $statut);
        if (!mysqli_stmt_execute($stmt)) {
            $erreur = mysqli_stmt_error($stmt);
            break;
        }
    }
    mysqli_stmt_close($stmt);

    if ($erreur !== null) {
        echo json_encode(['success' => false, 'message' => "Erreur lors de l'enregistrement des présences."]);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

/* -------------------------------------------------------------------------
   2) PARAMETRES DE LA PAGE (filière / groupe / date sélectionnés)
   ------------------------------------------------------------------------- */

// Date sélectionnée (par défaut aujourd'hui)
$selected_date = $_GET['date'] ?? date('Y-m-d');
$d = DateTime::createFromFormat('Y-m-d', $selected_date);
if (!$d || $d->format('Y-m-d') !== $selected_date) {
    $selected_date = date('Y-m-d');
}

/* -------------------------------------------------------------------------
   3) CHARGEMENT DES FILIERES ET GROUPES
   ------------------------------------------------------------------------- */
$filieresList = [];               // ["Bac Informatique", "Bac Math", ...]
$groupesParFiliere = [];          // ["Bac Informatique" => ["Info A","Info B"], ...]
$groupeIdParNom = [];             // ["Info A" => 3, ...]
$filiereParGroupe = [];           // ["Info A" => "Bac Informatique", ...]

$sql = "SELECT f.nom_filiere, g.id_groupe, g.nom_groupe
        FROM filiere f
        LEFT JOIN groupe g ON g.id_filiere = f.id_filiere
        ORDER BY f.id_filiere ASC, g.nom_groupe ASC";
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $nomFiliere = $row['nom_filiere'];
    if (!in_array($nomFiliere, $filieresList)) {
        $filieresList[] = $nomFiliere;
        $groupesParFiliere[$nomFiliere] = [];
    }
    if ($row['id_groupe'] !== null) {
        $groupesParFiliere[$nomFiliere][] = $row['nom_groupe'];
        $groupeIdParNom[$row['nom_groupe']] = (int)$row['id_groupe'];
        $filiereParGroupe[$row['nom_groupe']] = $nomFiliere;
    }
}

// Filière / groupe sélectionnés par défaut = les premiers de la liste
$filiere_defaut = $_GET['filiere'] ?? ($filieresList[0] ?? '');
if (!in_array($filiere_defaut, $filieresList)) {
    $filiere_defaut = $filieresList[0] ?? '';
}
$groupesDeLaFiliere = $groupesParFiliere[$filiere_defaut] ?? [];
$groupe_defaut = $_GET['groupe'] ?? ($groupesDeLaFiliere[0] ?? '');
if (!in_array($groupe_defaut, $groupesDeLaFiliere)) {
    $groupe_defaut = $groupesDeLaFiliere[0] ?? '';
}

/* -------------------------------------------------------------------------
   4) SEANCES DU JOUR SELECTIONNE, PAR GROUPE
   ------------------------------------------------------------------------- */
$seancesParGroupe = [];   // ["Info A" => [ ["id"=>5,"label"=>"17:00 - 18:30"], ... ] ]
$idsSeancesDuJour  = [];

$stmt = mysqli_prepare($conn, "SELECT id_seance, id_groupe, heure_debut, heure_fin FROM seance WHERE date_seance = ? ORDER BY heure_debut ASC");
mysqli_stmt_bind_param($stmt, "s", $selected_date);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    // Retrouver le nom du groupe correspondant à l'id_groupe
    $nomGroupe = array_search((int)$row['id_groupe'], $groupeIdParNom);
    if ($nomGroupe === false) continue;

    $label = substr($row['heure_debut'], 0, 5) . " - " . substr($row['heure_fin'], 0, 5);
    if (!isset($seancesParGroupe[$nomGroupe])) $seancesParGroupe[$nomGroupe] = [];
    $seancesParGroupe[$nomGroupe][] = ['id' => (int)$row['id_seance'], 'label' => $label];
    $idsSeancesDuJour[] = (int)$row['id_seance'];
}
mysqli_stmt_close($stmt);

/* -------------------------------------------------------------------------
   5) ELEVES + STATUT DE PRESENCE, PAR GROUPE ET PAR SEANCE
   ------------------------------------------------------------------------- */

// a) Elèves de chaque groupe
$elevesParGroupe = [];  // ["Info A" => [ ["id"=>1,"name"=>"Ahmed Ben Ali"], ... ] ]
$sql = "SELECT e.id_eleve, e.nom, e.prenom, g.nom_groupe
        FROM eleve e
        INNER JOIN groupe g ON g.id_groupe = e.id_groupe
        ORDER BY e.nom ASC, e.prenom ASC";
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $nomGroupe = $row['nom_groupe'];
    if (!isset($elevesParGroupe[$nomGroupe])) $elevesParGroupe[$nomGroupe] = [];
    $elevesParGroupe[$nomGroupe][] = [
        'id'   => (int)$row['id_eleve'],
        'name' => $row['nom'] . " " . $row['prenom'],
    ];
}

// b) Statuts de présence déjà enregistrés pour les séances du jour sélectionné
$presenceExistante = []; // [id_seance][id_eleve] = 'Présent' | 'Absent'
if (!empty($idsSeancesDuJour)) {
    $idsStr = implode(',', array_map('intval', $idsSeancesDuJour));
    $sql = "SELECT id_seance, id_eleve, statut FROM presence WHERE id_seance IN ($idsStr)";
    $result = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($result)) {
        $presenceExistante[(int)$row['id_seance']][(int)$row['id_eleve']] = $row['statut'];
    }
}

// c) Construction finale : studentsData[nomGroupe][id_seance] = [ {id,name,present}, ... ]
$studentsData = [];
foreach ($groupeIdParNom as $nomGroupe => $idGroupe) {
    $studentsData[$nomGroupe] = [];
    if (empty($seancesParGroupe[$nomGroupe])) continue;

    foreach ($seancesParGroupe[$nomGroupe] as $seance) {
        $idSeance = $seance['id'];
        $eleves = $elevesParGroupe[$nomGroupe] ?? [];
        $liste = [];
        foreach ($eleves as $el) {
            $statutActuel = $presenceExistante[$idSeance][$el['id']] ?? 'Présent'; // par défaut : présent
            $liste[] = [
                'id'      => $el['id'],
                'name'    => $el['name'],
                'present' => ($statutActuel === 'Présent'),
            ];
        }
        $studentsData[$nomGroupe][(string)$idSeance] = $liste;
    }
}

// Nom & jour affichés dans le sélecteur de date (en français)
$moisNoms = ["Janvier","Février","Mars","Avril","Mai","Juin","Juillet","Août","Septembre","Octobre","Novembre","Décembre"];
$joursComplets = ["Dimanche","Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"];
$dateObj = new DateTime($selected_date);
$dateAffichee = $dateObj->format('d') . " " . $moisNoms[(int)$dateObj->format('n') - 1] . " " . $dateObj->format('Y');
$jourAffiche = $joursComplets[(int)$dateObj->format('w')];
$dateSlashes = $dateObj->format('d/m/Y');

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Présence - SmartTeacher</title>
<style>
  :root{
    --violet:#5b4bff;
    --violet-light:#eeecff;
    --green:#1fb469;
    --green-bg:#e7f9ef;
    --red:#f43f5e;
    --red-bg:#fde8ec;
    --orange:#f59e0b;
    --orange-bg:#fff3e0;
    --text-dark:#1b1d29;
    --text-gray:#8a8fa3;
    --border:#eef0f5;
    --bg:#f6f7fb;
    --white:#ffffff;
  }
  *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',system-ui,-apple-system,Roboto,Arial,sans-serif;}
  body{background:var(--bg);color:var(--text-dark);padding:24px;}
  .page{max-width:1300px;margin:0 auto;}

  /* HEADER */
  .header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap;gap:16px;}
  .header-left{display:flex;align-items:center;gap:16px;}
  .menu-icon{width:22px;height:16px;display:flex;flex-direction:column;justify-content:space-between;cursor:pointer;}
  .menu-icon span{display:block;height:2px;background:var(--text-dark);border-radius:2px;}
  .header-left h1{font-size:26px;font-weight:800;}

  .header-right{display:flex;align-items:center;gap:14px;}
  .search-box{position:relative;}
  .search-box input{
    width:280px;padding:11px 44px 11px 16px;border-radius:12px;border:1px solid var(--border);
    background:var(--white);font-size:14px;outline:none;color:var(--text-dark);
  }
  .search-box input::placeholder{color:var(--text-gray);}
  .search-box svg{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--text-gray);}

  .date-pill{
    display:flex;align-items:center;gap:10px;background:var(--white);border:1px solid var(--border);
    border-radius:12px;padding:8px 14px;
  }
  .date-pill .date-text{font-size:13px;font-weight:600;line-height:1.3;}
  .date-pill .date-text small{display:block;font-weight:400;color:var(--text-gray);font-size:12px;}
  .avatar{
    width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--violet),#8b7cff);
    color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;
  }

  /* FILTER CARD */
  .filters-card{
    background:var(--white);border-radius:16px;padding:20px 24px;display:flex;gap:28px;flex-wrap:wrap;
    align-items:flex-end;margin-bottom:20px;box-shadow:0 1px 2px rgba(20,20,50,0.03);position:relative;z-index:30;
  }
  .filter-group{display:flex;flex-direction:column;gap:8px;flex:1;min-width:200px;position:relative;}
  .filter-group label{font-size:13px;font-weight:700;color:var(--text-dark);}

  /* CUSTOM DROPDOWN */
  .custom-dd{position:relative;}
  .dd-trigger{
    display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;
    padding:10px 12px;background:var(--white);cursor:pointer;user-select:none;transition:border-color .15s ease;
  }
  .dd-trigger:hover{border-color:#d7d9e6;}
  .custom-dd.open .dd-trigger{border-color:var(--violet);}
  .dd-trigger .ic{
    width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;
  }
  .custom-dd.filiere .ic{background:var(--violet-light);color:var(--violet);}
  .custom-dd.groupe .ic{background:#e6f0ff;color:#3b82f6;}
  .dd-trigger .dd-label{flex:1;font-size:14px;font-weight:600;color:var(--text-dark);}
  .dd-trigger .chevron{color:var(--text-gray);flex-shrink:0;transition:transform .15s ease;}
  .custom-dd.open .chevron{transform:rotate(180deg);}
  .dd-panel{
    position:absolute;top:calc(100% + 6px);left:0;right:0;background:var(--white);border:1px solid var(--border);
    border-radius:12px;box-shadow:0 10px 30px rgba(20,20,50,0.12);display:none;overflow:hidden;z-index:40;
    max-height:240px;overflow-y:auto;
  }
  .custom-dd.open .dd-panel{display:block;}
  .dd-option{padding:11px 14px;font-size:14px;font-weight:500;cursor:pointer;color:var(--text-dark);}
  .dd-option:hover{background:var(--bg);}
  .dd-option.selected{background:var(--violet-light);color:var(--violet);font-weight:700;}

  /* CUSTOM DATE PICKER */
  .date-wrap{
    display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;
    padding:10px 12px;background:var(--white);cursor:pointer;position:relative;transition:border-color .15s ease;
  }
  .date-wrap:hover{border-color:#d7d9e6;}
  .date-wrap.open{border-color:var(--violet);}
  .date-wrap .date-display{flex:1;font-size:14px;font-weight:600;color:var(--text-dark);}
  .date-wrap svg{color:var(--text-gray);flex-shrink:0;}

  .cal-panel{
    position:absolute;top:calc(100% + 6px);left:0;width:280px;background:var(--white);border:1px solid var(--border);
    border-radius:14px;box-shadow:0 10px 30px rgba(20,20,50,0.14);display:none;padding:14px;z-index:50;
  }
  .date-wrap.open .cal-panel{display:block;}
  .cal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
  .cal-header button{
    width:28px;height:28px;border-radius:8px;border:1px solid var(--border);background:var(--white);
    cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-dark);
  }
  .cal-header button:hover{background:var(--bg);}
  .cal-title{font-size:14px;font-weight:700;}
  .cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center;}
  .cal-grid .cal-dow{font-size:11px;color:var(--text-gray);font-weight:700;padding:4px 0;}
  .cal-day{
    font-size:13px;padding:7px 0;border-radius:8px;cursor:pointer;color:var(--text-dark);font-weight:500;
  }
  .cal-day:hover{background:var(--bg);}
  .cal-day.empty{cursor:default;}
  .cal-day.selected{background:var(--violet);color:#fff;font-weight:700;}
  .cal-day.today:not(.selected){border:1px solid var(--violet);}

  .btn-save{
    display:flex;align-items:center;gap:8px;background:var(--violet);color:#fff;border:none;
    padding:13px 20px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;white-space:nowrap;
    transition:background .15s ease;
  }
  .btn-save:hover{background:#4a3ce0;}
  .btn-save:disabled{background:#c9c6e6;cursor:not-allowed;}

  /* STAT CARDS */
  .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;}
  .stat-card{
    background:var(--white);border-radius:16px;padding:20px;display:flex;align-items:center;gap:14px;
    box-shadow:0 1px 2px rgba(20,20,50,0.03);
  }
  .stat-icon{
    width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;
  }
  .stat-icon.blue{background:var(--violet-light);color:var(--violet);}
  .stat-icon.green{background:var(--green-bg);color:var(--green);}
  .stat-icon.red{background:var(--red-bg);color:var(--red);}
  .stat-icon.orange{background:var(--orange-bg);color:var(--orange);}
  .stat-info .stat-value{display:flex;align-items:baseline;gap:8px;}
  .stat-info .stat-value h2{font-size:24px;font-weight:800;}
  .stat-info .stat-value .pct{font-size:13px;font-weight:700;}
  .stat-value .pct.green-txt{color:var(--green);}
  .stat-value .pct.red-txt{color:var(--red);}
  .stat-info p{font-size:13px;color:var(--text-gray);margin-top:2px;}

  .time-edit{display:flex;align-items:center;gap:4px;}
  .time-edit .time-part{
    font-size:19px;font-weight:800;color:var(--text-dark);border:none;background:transparent;
    width:32px;text-align:center;outline:none;font-family:inherit;padding:2px 0;border-radius:4px;
  }
  .time-edit .time-part:focus{background:var(--violet-light);color:var(--violet);}
  .time-edit .colon, .time-edit .dash{font-size:19px;font-weight:800;color:var(--text-dark);}
  .time-edit .dash{margin:0 4px;color:var(--text-gray);}

  .custom-dd.seance{position:relative;margin-top:2px;}
  .dd-trigger.seance-trigger{
    border:none;padding:0;background:transparent;gap:8px;
  }
  .seance-trigger .seance-label{font-size:19px;font-weight:800;color:var(--text-dark);flex:none;}
  .seance-trigger .chevron{color:var(--text-gray);}
  .custom-dd.seance .dd-panel{min-width:170px;left:0;right:auto;}

  /* TABLE */
  .table-card{background:var(--white);border-radius:16px;padding:22px 24px 8px;box-shadow:0 1px 2px rgba(20,20,50,0.03);}
  .table-card h3{font-size:16px;font-weight:800;margin-bottom:16px;}
  table{width:100%;border-collapse:collapse;}
  thead th{
    text-align:left;font-size:12px;color:var(--text-gray);font-weight:700;text-transform:none;
    padding:10px 14px;border-bottom:1px solid var(--border);
  }
  tbody td{padding:14px;border-bottom:1px solid var(--border);font-size:14px;vertical-align:middle;}
  tbody tr:last-child td{border-bottom:none;}
  .col-num{width:50px;color:var(--text-gray);}
  .col-name{font-weight:600;}

  .status-toggle{display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;}
  .switch{
    width:42px;height:24px;border-radius:20px;background:var(--red);position:relative;
    transition:background .2s ease;flex-shrink:0;
  }
  .switch::after{
    content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;
    background:#fff;transition:left .2s ease;box-shadow:0 1px 3px rgba(0,0,0,0.2);
  }
  .status-toggle.present .switch{background:var(--green);}
  .status-toggle.present .switch::after{left:21px;}
  .status-label{font-size:14px;font-weight:600;color:var(--text-gray);}
  .status-toggle.present .status-label{color:var(--green);}
  .status-toggle:not(.present) .status-label{color:var(--red);}

  @media (max-width:900px){
    .stats-row{grid-template-columns:repeat(2,1fr);}
    .search-box input{width:180px;}
    table{display:block;overflow-x:auto;white-space:nowrap;}
  }

  /* ======================================================
     RESPONSIVE — ajustements supplémentaires mobile
     (corrige le comportement du tableau pour un vrai
     scroll horizontal au lieu d'un table en display:block)
     ====================================================== */
  @media (max-width: 900px){
    .table-card{ overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table{ display: table; min-width: 700px; white-space: normal; }
  }
  @media (max-width: 700px){
    .filters-card{ flex-direction: column; align-items: stretch; }
    .filter-group{ min-width: 0; }
    .header-right{ width: 100%; justify-content: space-between; flex-wrap: wrap; }
    .search-box input{ width: 100%; }
    .dd-panel{ left: 0; right: 0; }
    .cal-panel{ left: 0; right: auto; width: min(280px, calc(100vw - 32px)); }
  }
  @media (max-width: 480px){
    .stats-row{ grid-template-columns: 1fr; }
    .header-left h1{ font-size: 20px; }
    .table-card{ padding: 16px 16px 8px; }
  }
</style>
</head>
<body>
<div class="page">

  <!-- HEADER -->
  <div class="header">
    <div class="header-left">
      
      <div>
        <h1>Présence</h1>
        <div style="font-size:13px;color:var(--text-gray);margin-top:2px;"><a href="dashboard.php" style="color:var(--text-gray);text-decoration:none;">Dashboard</a> &nbsp;›&nbsp; <span style="color:var(--text-dark);font-weight:600;">Présence</span></div>
      </div>
    </div>
    <div class="header-right">
      <div class="search-box">
        <input type="text" id="searchInput" placeholder="Rechercher un élève...">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
      <div class="date-pill">
        <div class="date-text" id="topDate"><?php echo $dateAffichee; ?><small><?php echo $jourAffiche; ?></small></div>
      </div>
      <div class="avatar">A</div>
    </div>
  </div>

  <!-- FILTERS -->
  <div class="filters-card">
    <div class="filter-group">
      <label>Filière</label>
      <div class="custom-dd filiere" id="filiereDD">
        <div class="dd-trigger">
          <div class="ic">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
          </div>
          <div class="dd-label" id="filiereLabel"><?php echo htmlspecialchars($filiere_defaut); ?></div>
          <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="dd-panel" id="filierePanel"></div>
      </div>
    </div>

    <div class="filter-group">
      <label>Groupe</label>
      <div class="custom-dd groupe" id="groupeDD">
        <div class="dd-trigger">
          <div class="ic">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div class="dd-label" id="groupeLabel"><?php echo htmlspecialchars($groupe_defaut); ?></div>
          <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="dd-panel" id="groupePanel"></div>
      </div>
    </div>

    <div class="filter-group">
      <label>Date</label>
      <div class="date-wrap" id="dateWrap">
        <div class="date-display" id="dateDisplay"><?php echo $dateSlashes; ?></div>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <div class="cal-panel" id="calPanel">
          <div class="cal-header">
            <button id="calPrev" type="button">&#8249;</button>
            <div class="cal-title" id="calTitle">Mai 2024</div>
            <button id="calNext" type="button">&#8250;</button>
          </div>
          <div class="cal-grid" id="calGrid"></div>
        </div>
      </div>
    </div>

    <button class="btn-save" id="saveBtn" type="button">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Enregistrer la présence
    </button>
  </div>

  <!-- STATS -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon blue">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div class="stat-info">
        <div class="stat-value"><h2 id="totalCount">0</h2></div>
        <p>Total élèves</p>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-icon green">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div class="stat-info">
        <div class="stat-value"><h2 id="presentCount">0</h2><span class="pct green-txt" id="presentPct">0%</span></div>
        <p>Présents</p>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-icon red">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      </div>
      <div class="stat-info">
        <div class="stat-value"><h2 id="absentCount">0</h2><span class="pct red-txt" id="absentPct">0%</span></div>
        <p>Absents</p>
      </div>
    </div>

    <div class="stat-card time">
      <div class="stat-icon orange">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div class="stat-info">
        <div class="custom-dd seance" id="seanceDD">
          <div class="dd-trigger seance-trigger">
            <div class="dd-label seance-label" id="seanceLabel">--:-- - --:--</div>
            <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="dd-panel" id="seancePanel"></div>
        </div>
        <p>Heure de la séance</p>
      </div>
    </div>
  </div>

  <!-- TABLE -->
  <div class="table-card">
    <h3>Liste des élèves</h3>
    <table>
      <thead>
        <tr>
          <th class="col-num">N°</th>
          <th>Nom et Prénom</th>
          <th>Statut</th>
        </tr>
      </thead>
      <tbody id="studentsBody"></tbody>
    </table>
  </div>

</div>

<script>
/* ---------- DONNEES INJECTEES DEPUIS PHP / MYSQL ---------- */
const groupesParFiliere = <?php echo json_encode($groupesParFiliere, JSON_UNESCAPED_UNICODE); ?>;
const filieresList = Object.keys(groupesParFiliere);

// seancesParGroupe["Info A"] = [ {id:5, label:"17:00 - 18:30"}, ... ] pour la date sélectionnée
const seancesParGroupe = <?php echo json_encode($seancesParGroupe, JSON_UNESCAPED_UNICODE); ?>;

// studentsData["Info A"]["5"] = [ {id, name, present}, ... ]
const studentsData = <?php echo json_encode($studentsData, JSON_UNESCAPED_UNICODE); ?>;

const initialFiliere = <?php echo json_encode($filiere_defaut, JSON_UNESCAPED_UNICODE); ?>;
const initialGroupe  = <?php echo json_encode($groupe_defaut, JSON_UNESCAPED_UNICODE); ?>;
const selectedDateStr = <?php echo json_encode($selected_date); ?>; // YYYY-MM-DD

// Liste actuellement affichée + séance actuellement sélectionnée
let students = [];
let currentIdSeance = null;

/* ---------- DROPDOWN GENERIQUE ---------- */
function setupDropdown(ddId, panelId, labelId, options, onSelect, initial, getLabel){
  const dd = document.getElementById(ddId);
  const trigger = dd.querySelector('.dd-trigger');
  const panel = document.getElementById(panelId);
  const label = document.getElementById(labelId);
  let current = initial;
  getLabel = getLabel || (o => o);

  function renderOptions(){
    panel.innerHTML = "";
    if(options.length === 0){
      const empty = document.createElement('div');
      empty.className = 'dd-option';
      empty.style.color = 'var(--text-gray)';
      empty.textContent = 'Aucune option disponible';
      panel.appendChild(empty);
      return;
    }
    options.forEach(opt => {
      const div = document.createElement('div');
      div.className = 'dd-option' + (opt === current ? ' selected' : '');
      div.textContent = getLabel(opt);
      div.addEventListener('click', (e) => {
        e.stopPropagation();
        current = opt;
        label.textContent = getLabel(opt);
        closeAllDropdowns();
        renderOptions();
        onSelect(opt);
      });
      panel.appendChild(div);
    });
  }

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = dd.classList.contains('open');
    closeAllDropdowns();
    closeCalendar();
    if(!isOpen){ dd.classList.add('open'); }
  });

  renderOptions();
  return {
    setOptions(newOptions, newCurrent){
      options = newOptions;
      current = newCurrent;
      label.textContent = newCurrent !== null && newCurrent !== undefined ? getLabel(newCurrent) : '';
      renderOptions();
    },
    getValue(){ return current; }
  };
}

function closeAllDropdowns(){
  document.querySelectorAll('.custom-dd.open').forEach(d => d.classList.remove('open'));
}

/* ---------- NAVIGATION (changement de filière / groupe / date) ---------- */
function goTo(filiere, groupe, date){
  const params = new URLSearchParams();
  params.set('filiere', filiere);
  params.set('groupe', groupe);
  params.set('date', date);
  window.location.href = 'presence.php?' + params.toString();
}

/* ---------- SEANCE + TABLEAU DES ELEVES ---------- */
let seanceDropdown;

function loadSeancesForGroupe(nomGroupe){
  const seances = seancesParGroupe[nomGroupe] || [];
  const saveBtn = document.getElementById('saveBtn');

  if(seances.length === 0){
    currentIdSeance = null;
    students = [];
    seanceDropdown.setOptions([], null);
    document.getElementById('seanceLabel').textContent = 'Aucune séance';
    saveBtn.disabled = true;
    renderTable();
    updateStats();
    return;
  }

  saveBtn.disabled = false;
  seanceDropdown.setOptions(seances, seances[0]);
  switchStudentList(nomGroupe, seances[0].id);
}

function switchStudentList(nomGroupe, idSeance){
  currentIdSeance = idSeance;
  students = (studentsData[nomGroupe] && studentsData[nomGroupe][String(idSeance)]) || [];
  document.getElementById('searchInput').value = "";
  renderTable();
  updateStats();
}

/* ---------- INITIALISATION DROPDOWNS ---------- */
let groupeDropdown;

const filiereDropdown = setupDropdown('filiereDD','filierePanel','filiereLabel', filieresList, (selected) => {
  const groupes = groupesParFiliere[selected] || [];
  groupeDropdown.setOptions(groupes, groupes[0] || null);
  if(groupes[0]){
    loadSeancesForGroupe(groupes[0]);
  } else {
    loadSeancesForGroupe(null);
  }
}, initialFiliere);

groupeDropdown = setupDropdown('groupeDD','groupePanel','groupeLabel', groupesParFiliere[initialFiliere] || [], (selectedGroupe) => {
  loadSeancesForGroupe(selectedGroupe);
}, initialGroupe);

seanceDropdown = setupDropdown('seanceDD','seancePanel','seanceLabel', seancesParGroupe[initialGroupe] || [], (selectedSeance) => {
  switchStudentList(groupeDropdown.getValue(), selectedSeance.id);
}, (seancesParGroupe[initialGroupe] || [])[0] || null, (s) => s ? s.label : 'Aucune séance');

/* ---------- CALENDRIER CUSTOM ---------- */
const jours = ["Dim","Lun","Mar","Mer","Jeu","Ven","Sam"];
const joursComplets = ["Dimanche","Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"];
const moisNoms = ["Janvier","Février","Mars","Avril","Mai","Juin","Juillet","Août","Septembre","Octobre","Novembre","Décembre"];

const [selY, selM, selD] = selectedDateStr.split('-').map(Number);
let selectedDate = new Date(selY, selM - 1, selD);
let viewMonth = selectedDate.getMonth();
let viewYear = selectedDate.getFullYear();

const dateWrap = document.getElementById('dateWrap');
const calPanel = document.getElementById('calPanel');
const calGrid = document.getElementById('calGrid');
const calTitle = document.getElementById('calTitle');
const dateDisplay = document.getElementById('dateDisplay');
const topDate = document.getElementById('topDate');

function pad(n){ return n.toString().padStart(2,'0'); }

function renderCalendar(){
  calTitle.textContent = `${moisNoms[viewMonth]} ${viewYear}`;
  calGrid.innerHTML = "";
  jours.forEach(j => {
    const dow = document.createElement('div');
    dow.className = 'cal-dow';
    dow.textContent = j;
    calGrid.appendChild(dow);
  });

  const firstDay = new Date(viewYear, viewMonth, 1);
  let startOffset = firstDay.getDay(); // 0=dim
  const daysInMonth = new Date(viewYear, viewMonth+1, 0).getDate();
  const today = new Date();

  for(let i=0;i<startOffset;i++){
    const empty = document.createElement('div');
    empty.className = 'cal-day empty';
    calGrid.appendChild(empty);
  }

  for(let d=1; d<=daysInMonth; d++){
    const cell = document.createElement('div');
    cell.className = 'cal-day';
    cell.textContent = d;
    const isSelected = selectedDate.getDate()===d && selectedDate.getMonth()===viewMonth && selectedDate.getFullYear()===viewYear;
    const isToday = today.getDate()===d && today.getMonth()===viewMonth && today.getFullYear()===viewYear;
    if(isSelected) cell.classList.add('selected');
    if(isToday) cell.classList.add('today');
    cell.addEventListener('click', (e) => {
      e.stopPropagation();
      const newDate = new Date(viewYear, viewMonth, d);
      const iso = `${newDate.getFullYear()}-${pad(newDate.getMonth()+1)}-${pad(newDate.getDate())}`;
      // Un changement de date recharge la page avec les séances/présences du nouveau jour
      goTo(filiereDropdown.getValue(), groupeDropdown.getValue(), iso);
    });
    calGrid.appendChild(cell);
  }
}

function openCalendar(){
  closeAllDropdowns();
  dateWrap.classList.add('open');
  renderCalendar();
}
function closeCalendar(){
  dateWrap.classList.remove('open');
}

dateWrap.addEventListener('click', (e) => {
  e.stopPropagation();
  if(dateWrap.classList.contains('open')){
    closeCalendar();
  } else {
    openCalendar();
  }
});
calPanel.addEventListener('click', (e) => e.stopPropagation());

document.getElementById('calPrev').addEventListener('click', (e) => {
  e.stopPropagation();
  viewMonth--;
  if(viewMonth < 0){ viewMonth = 11; viewYear--; }
  renderCalendar();
});
document.getElementById('calNext').addEventListener('click', (e) => {
  e.stopPropagation();
  viewMonth++;
  if(viewMonth > 11){ viewMonth = 0; viewYear++; }
  renderCalendar();
});

document.addEventListener('click', () => {
  closeAllDropdowns();
  closeCalendar();
});

/* ---------- TABLEAU ELEVES ---------- */
const tbody = document.getElementById('studentsBody');

function renderTable(filter=""){
  tbody.innerHTML = "";

  if(students.length === 0){
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="3" style="text-align:center;color:var(--text-gray);padding:24px;">
      Aucune séance programmée pour ce groupe à cette date. Créez une séance dans la page "Séance".
    </td>`;
    tbody.appendChild(tr);
    return;
  }

  const filtered = students.filter(s => s.name.toLowerCase().includes(filter.toLowerCase()));

  if(filtered.length === 0){
    const tr = document.createElement('tr');
    tr.innerHTML = `<td colspan="3" style="text-align:center;color:var(--text-gray);padding:24px;">Aucun élève trouvé.</td>`;
    tbody.appendChild(tr);
    return;
  }

  filtered.forEach((s) => {
    const realIndex = students.indexOf(s);
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="col-num">${realIndex+1}</td>
      <td class="col-name">${s.name}</td>
      <td>
        <div class="status-toggle ${s.present ? 'present' : ''}" data-index="${realIndex}">
          <div class="switch"></div>
          <span class="status-label">${s.present ? 'Présent' : 'Absent'}</span>
        </div>
      </td>
    `;
    tbody.appendChild(tr);
  });

  document.querySelectorAll('.status-toggle').forEach(el => {
    el.addEventListener('click', () => {
      const idx = el.dataset.index;
      students[idx].present = !students[idx].present;
      renderTable(document.getElementById('searchInput').value);
      updateStats();
    });
  });
}

function updateStats(){
  const total = students.length;
  const presentCount = students.filter(s => s.present).length;
  const absentCount = total - presentCount;
  document.getElementById('totalCount').textContent = total;
  document.getElementById('presentCount').textContent = presentCount;
  document.getElementById('absentCount').textContent = absentCount;
  document.getElementById('presentPct').textContent = total > 0 ? ((presentCount/total)*100).toFixed(1) + "%" : "0%";
  document.getElementById('absentPct').textContent = total > 0 ? ((absentCount/total)*100).toFixed(1) + "%" : "0%";
}

document.getElementById('searchInput').addEventListener('input', (e) => {
  renderTable(e.target.value);
});

/* ---------- ENREGISTREMENT REEL EN BASE DE DONNEES (AJAX) ---------- */
document.getElementById('saveBtn').addEventListener('click', () => {
  if(!currentIdSeance || students.length === 0) return;

  const btn = document.getElementById('saveBtn');
  const original = btn.innerHTML;
  btn.disabled = true;

  const payload = {
    id_seance: currentIdSeance,
    presences: students.map(s => ({ id_eleve: s.id, present: s.present }))
  };

  fetch('presence.php?action=save_presence', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(data => {
    if(data.success){
      btn.innerHTML = "✓ Présence enregistrée";
      btn.style.background = "#1fb469";
    } else {
      btn.innerHTML = "✗ Erreur : " + (data.message || "réessayez");
      btn.style.background = "#f43f5e";
    }
    setTimeout(() => {
      btn.innerHTML = original;
      btn.style.background = "";
      btn.disabled = false;
    }, 1800);
  })
  .catch(() => {
    btn.innerHTML = "✗ Erreur réseau";
    btn.style.background = "#f43f5e";
    setTimeout(() => {
      btn.innerHTML = original;
      btn.style.background = "";
      btn.disabled = false;
    }, 1800);
  });
});

/* ---------- CHARGEMENT INITIAL ---------- */
renderCalendar();
loadSeancesForGroupe(initialGroupe);
</script>
</body>
</html>