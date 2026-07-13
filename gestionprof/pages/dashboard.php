<?php
require_once("../config/database.php");
if (!$conn->ping()) {
  die("Connexion MySQL perdue.");
}
/* ==========================================================================
   SmartTeacher — Dashboard One Page
   PHP + CSS + JS dans un seul fichier
   ========================================================================== */

/* --------------------------------------------------------------------
   Endpoint AJAX : mise à jour manuelle du statut d'une séance dépassée.
   Déclenché uniquement par un clic du professeur sur l'un des deux
   boutons de l'alerte de rappel ("Terminée" / "Annulée"). Le statut
   n'est JAMAIS modifié automatiquement ailleurs dans le code : c'est
   toujours le professeur qui décide.
   -------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_seance_statut') {
    header('Content-Type: application/json; charset=UTF-8');
    $response = ['success' => false];

    $id     = (int)($_POST['id'] ?? 0);
    $statut = $_POST['statut'] ?? '';
    // Seuls ces deux statuts peuvent être choisis depuis l'alerte de rappel :
    // "À venir" n'est volontairement pas autorisé ici.
    $statutsAutorises = ['Terminée', 'Annulée'];

    if ($id <= 0 || !in_array($statut, $statutsAutorises, true)) {
        $response['message'] = "Requête invalide.";
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE seance SET statut = ? WHERE id_seance = ?");
        if (!$stmt) {
            $response['message'] = "Erreur de préparation de la requête : " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($stmt, "si", $statut, $id);
            if (mysqli_stmt_execute($stmt)) {
                $response['success'] = true;
                $response['id']      = $id;
                $response['statut']  = $statut;
            } else {
                $response['message'] = "Erreur lors de la mise à jour : " . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    mysqli_close($conn);
    exit;
}

function fetch_count(mysqli $conn, string $sql): int {
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        die("Erreur SQL : " . mysqli_error($conn));
    }
    $row = mysqli_fetch_assoc($res);
    return (int)($row['total'] ?? 0);
}

function safe_query(mysqli $conn, string $sql): mysqli_result {
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        die("Erreur SQL : " . mysqli_error($conn));
    }
    return $res;
}

$total_eleves = fetch_count($conn, "SELECT COUNT(*) AS total FROM eleve");
$total_groupes = fetch_count($conn, "SELECT COUNT(*) AS total FROM groupe");
$total_seances_today = fetch_count($conn, "SELECT COUNT(*) AS total FROM seance WHERE date_seance = CURDATE()");

$sql_absences_today = "SELECT COUNT(*) AS total
                       FROM presence p
                       INNER JOIN seance s ON p.id_seance = s.id_seance
                       WHERE s.date_seance = CURDATE()
                       AND p.statut = 'Absent'";
$total_absences_today = fetch_count($conn, $sql_absences_today);

$total_paiements_attente = fetch_count($conn, "SELECT COUNT(*) AS total FROM paiement WHERE statut = 'En attente'");

$sql_seances_list = "SELECT s.heure_debut, g.nom_groupe
                     FROM seance s
                     INNER JOIN groupe g ON s.id_groupe = g.id_groupe
                     WHERE s.date_seance = CURDATE()
                     ORDER BY s.heure_debut ASC";
$res_seances_list = safe_query($conn, $sql_seances_list);

$sql_paiements_list = "SELECT e.nom, e.prenom, g.nom_groupe, p.montant_a_payer
                       FROM paiement p
                       INNER JOIN eleve e ON p.id_eleve = e.id_eleve
                       INNER JOIN groupe g ON e.id_groupe = g.id_groupe
                       WHERE p.statut = 'En attente'
                       ORDER BY p.id_paiement DESC
                       LIMIT 5";
$res_paiements_list = safe_query($conn, $sql_paiements_list);

$sql_absences_list = "SELECT e.id_eleve, e.nom, e.prenom, g.nom_groupe, COUNT(*) AS nb_absences
                      FROM presence p
                      INNER JOIN eleve e ON p.id_eleve = e.id_eleve
                      INNER JOIN groupe g ON e.id_groupe = g.id_groupe
                      WHERE p.statut = 'Absent'
                      GROUP BY e.id_eleve, e.nom, e.prenom, g.nom_groupe
                      ORDER BY nb_absences DESC
                      LIMIT 5";
$res_absences_list = safe_query($conn, $sql_absences_list);

$sql_filieres = "SELECT f.id_filiere,
                        f.nom_filiere,
                        (SELECT COUNT(*) FROM groupe g WHERE g.id_filiere = f.id_filiere) AS nb_groupes,
                        (SELECT COUNT(*)
                         FROM eleve e
                         INNER JOIN groupe g2 ON e.id_groupe = g2.id_groupe
                         WHERE g2.id_filiere = f.id_filiere) AS nb_eleves,
                        (SELECT COALESCE(SUM(g3.capacite), 0)
                         FROM groupe g3
                         WHERE g3.id_filiere = f.id_filiere) AS capacite_totale
                 FROM filiere f
                 ORDER BY f.id_filiere ASC";
$res_filieres = safe_query($conn, $sql_filieres);

$filiere_style = [
    'Bac Informatique' => ['icon' => '💻', 'color' => 'primary'],
    'Bac Math'         => ['icon' => '🧮', 'color' => 'blue'],
    'Bac Sciences'     => ['icon' => '🧪', 'color' => 'green'],
    'Bac Technique'    => ['icon' => '⚙️', 'color' => 'orange'],
];

/* --------------------------------------------------------------------
   Listes réelles (filières / groupes) pour alimenter dynamiquement les
   menus déroulants des formulaires modaux (Ajouter élève, Créer groupe,
   Nouvelle séance) — remplace les tableaux JS codés en dur.
   -------------------------------------------------------------------- */
function fetch_all_prepared(mysqli $conn, string $sql): array {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        die("Erreur de préparation de la requête : " . mysqli_error($conn));
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

$filieres_list = fetch_all_prepared($conn, "SELECT id_filiere, nom_filiere FROM filiere ORDER BY nom_filiere ASC");
$groupes_list  = fetch_all_prepared($conn, "SELECT id_groupe, nom_groupe, id_filiere FROM groupe ORDER BY nom_groupe ASC");

/* --------------------------------------------------------------------
   Système de rappel intelligent : séances dont la date/heure de fin
   est déjà passée mais dont le statut est resté "À venir". Le statut
   n'est jamais changé ici automatiquement — cette requête sert
   uniquement à afficher une alerte demandant au professeur de
   confirmer manuellement (Terminée / Annulée).
   -------------------------------------------------------------------- */
$sql_seances_a_confirmer = "SELECT s.id_seance, s.date_seance, s.heure_debut, s.heure_fin, g.nom_groupe
                            FROM seance s
                            INNER JOIN groupe g ON s.id_groupe = g.id_groupe
                            WHERE s.statut = 'À venir'
                              AND TIMESTAMP(s.date_seance, s.heure_fin) < NOW()
                            ORDER BY s.date_seance ASC, s.heure_fin ASC";
$stmt_a_confirmer = mysqli_prepare($conn, $sql_seances_a_confirmer);
if (!$stmt_a_confirmer) {
    die("Erreur de préparation de la requête : " . mysqli_error($conn));
}
mysqli_stmt_execute($stmt_a_confirmer);
$res_seances_a_confirmer = mysqli_stmt_get_result($stmt_a_confirmer);
$seances_a_confirmer = [];
while ($row = mysqli_fetch_assoc($res_seances_a_confirmer)) {
    $seances_a_confirmer[] = $row;
}
mysqli_stmt_close($stmt_a_confirmer);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SmartTeacher — Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --primary: #6c5ce7;
      --primary-dark: #574bce;
      --blue: #2f80ed;
      --green: #27ae60;
      --orange: #f2994a;
      --pink: #eb5757;
      --bg: #f7f7ff;
      --card: #ffffff;
      --text: #1c1832;
      --muted: #7c7892;
      --border: #ebe8fb;
      --shadow: 0 18px 45px rgba(35, 31, 74, .10);
      --radius: 22px;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; }

    .app { min-height: 100vh; display: flex; }

    .sidebar {
      width: 280px;
      min-height: 100vh;
      background: #fff;
      border-right: 1px solid var(--border);
      padding: 22px;
      position: sticky;
      top: 0;
      display: flex;
      flex-direction: column;
      z-index: 40;
      transition: width .25s ease;
    }
    .sidebar__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
    .brand { display: flex; align-items: center; gap: 10px; font-size: 21px; font-weight: 700; }
    .brand__icon { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 14px; background: rgba(108,92,231,.12); }
    .brand strong { color: var(--primary); }
    .sidebar__toggle, .mobile-menu-btn, .icon-btn {
      border: 0;
      background: #f4f2ff;
      color: var(--primary);
      width: 42px;
      height: 42px;
      border-radius: 14px;
      cursor: pointer;
      display: grid;
      place-items: center;
    }

    .nav-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
    .nav-link { display: flex; align-items: center; gap: 12px; padding: 13px 14px; border-radius: 16px; color: var(--muted); font-weight: 700; transition: .2s; }
    .nav-link:hover, .nav-item--active .nav-link { background: linear-gradient(135deg, var(--primary), #8f7cff); color: #fff; box-shadow: 0 12px 25px rgba(108,92,231,.25); }
    .nav-icon { font-size: 19px; }

    .sidebar__footer { margin-top: auto; display: grid; gap: 14px; }
    .user-card { display: flex; align-items: center; gap: 12px; padding: 14px; background: #f7f5ff; border-radius: 18px; }
    .user-card__avatar { width: 45px; height: 45px; border-radius: 50%; background: #fff; display: grid; place-items: center; font-size: 25px; }
    .user-card__name { margin: 0; font-weight: 800; line-height: 1.2; }
    .user-card__badge { color: var(--primary); font-size: 12px; font-weight: 800; }
    .logout-btn { border: 0; padding: 13px; border-radius: 16px; background: #fff0f0; color: var(--pink); cursor: pointer; font-weight: 800; }

    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 30; }

    .main { flex: 1; min-width: 0; }
    .topbar { height: 82px; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; background: rgba(247,247,255,.85); backdrop-filter: blur(12px); position: sticky; top: 0; z-index: 20; border-bottom: 1px solid rgba(235,232,251,.7); }
    .topbar__left, .topbar__right { display: flex; align-items: center; gap: 14px; }
    .mobile-menu-btn { display: none; }
    .topbar__title { margin: 0; font-size: 28px; }

    .date-dropdown { position: relative; }
    .date-pill { border: 0; background: #fff; border-radius: 18px; padding: 9px 13px; display: flex; align-items: center; gap: 10px; box-shadow: 0 10px 25px rgba(35,31,74,.08); cursor: pointer; }
    .date-pill__icon { width: 36px; height: 36px; display: grid; place-items: center; background: #f4f2ff; border-radius: 12px; }
    .date-pill__text { display: grid; text-align: left; }
    .date-pill__text small { color: var(--muted); font-weight: 700; }
    .calendar-popover { position: absolute; right: 0; top: calc(100% + 12px); width: 310px; background: #fff; border-radius: 22px; box-shadow: var(--shadow); padding: 16px; z-index: 50; border: 1px solid var(--border); }
    .calendar-popover__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .calendar-popover__month { font-weight: 900; }
    .calendar-nav, .calendar-today-btn { border: 0; background: #f4f2ff; color: var(--primary); border-radius: 12px; cursor: pointer; font-weight: 900; }
    .calendar-nav { width: 35px; height: 35px; font-size: 23px; }
    .calendar-weekdays, .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; text-align: center; }
    .calendar-weekdays { color: var(--muted); font-size: 12px; font-weight: 900; margin-bottom: 8px; }
    .calendar-day { border: 0; background: transparent; padding: 9px 0; border-radius: 11px; cursor: pointer; font-weight: 800; color: var(--text); }
    .calendar-day:hover { background: rgba(108,92,231,.12); }
    .calendar-day--muted { color: #bbb; }
    .calendar-day--today { color: var(--primary); background: rgba(108,92,231,.10); }
    .calendar-day--selected { color: #fff; background: var(--primary); }
    .calendar-today-btn { width: 100%; padding: 11px; margin-top: 12px; }

    .content { padding: 28px; display: grid; gap: 24px; }
    .banner { display: grid; grid-template-columns: 1.4fr .6fr; gap: 20px; align-items: center; background: linear-gradient(135deg, #fff, #f0edff); border: 1px solid var(--border); border-radius: 30px; padding: 28px; box-shadow: var(--shadow); overflow: hidden; }
    .banner h2 { margin: 0 0 8px; font-size: clamp(26px, 4vw, 42px); }
    .banner p { margin: 0; color: var(--muted); font-weight: 600; }
    .banner__illustration { min-height: 150px; display: grid; place-items: center; font-size: 96px; background: rgba(108,92,231,.10); border-radius: 26px; }
    .wave { display: inline-block; animation: wave 1.6s infinite; transform-origin: 70% 70%; }
    @keyframes wave { 0%,100%{transform:rotate(0)} 20%{transform:rotate(15deg)} 40%{transform:rotate(-8deg)} 60%{transform:rotate(10deg)} }

    .stats-grid { display: grid; grid-template-columns: repeat(5, minmax(160px, 1fr)); gap: 16px; }
    .stat-card, .panel, .filiere-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: 0 12px 35px rgba(35,31,74,.07); }
    .stat-card { padding: 20px; display: grid; gap: 8px; }
    .stat-card__icon, .panel__icon, .filiere-card__icon { width: 45px; height: 45px; display: grid; place-items: center; border-radius: 15px; font-size: 22px; }
    .stat-card__icon--primary, .panel__icon--primary { background: rgba(108,92,231,.12); }
    .stat-card__icon--blue { background: rgba(47,128,237,.12); }
    .stat-card__icon--orange, .panel__icon--orange { background: rgba(242,153,74,.14); }
    .stat-card__icon--pink, .panel__icon--pink { background: rgba(235,87,87,.12); }
    .stat-card__icon--green { background: rgba(39,174,96,.13); }
    .stat-card__label { margin: 0; color: var(--muted); font-weight: 800; font-size: 14px; }
    .stat-card__value { margin: 0; font-size: 31px; font-weight: 900; }
    .stat-card__link, .panel__footer-link { color: var(--primary); font-weight: 900; font-size: 13px; }
    .stat-card__link--danger, .panel__footer-link--pink { color: var(--pink); }
    .stat-card__link--success { color: var(--green); }
    .panel__footer-link--orange { color: var(--orange); }

    .columns-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
    .panel { padding: 20px; }
    .panel--wide { width: 100%; }
    .panel__header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
    .panel__header--spread { justify-content: space-between; }
    .panel__header-left { display: flex; align-items: center; gap: 12px; }
    .panel__header h3 { margin: 0; font-size: 18px; }

    .schedule-list, .payment-list, .absence-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
    .schedule-item, .payment-item, .absence-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 13px; background: #faf9ff; border-radius: 16px; }
    .schedule-item__time { font-weight: 900; color: var(--primary); }
    .schedule-item__group, .payment-item__name, .absence-item__name { font-weight: 900; }
    .schedule-item__subject, .payment-item__group, .absence-item__group { color: var(--muted); font-size: 13px; font-weight: 700; }
    .payment-item__info, .absence-item__info { display: grid; gap: 3px; }
    .payment-item__amount { font-weight: 900; color: var(--orange); white-space: nowrap; }
    .badge { border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 900; white-space: nowrap; }
    .badge--danger { background: #ffe9e9; color: var(--pink); }
    .badge--danger-outline { border: 1px solid rgba(235,87,87,.35); color: var(--pink); }

    .filiere-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
    .filiere-card { padding: 20px; display: grid; gap: 14px; }
    .filiere-card h4 { margin: 0; font-size: 17px; }
    .filiere-card__stats { display: flex; justify-content: space-between; gap: 12px; }
    .filiere-card__stats div { display: grid; }
    .filiere-card__stats strong { font-size: 23px; }
    .filiere-card__stats span { color: var(--muted); font-size: 12px; font-weight: 800; }
    .filiere-card__bar { height: 10px; background: #f0eefb; border-radius: 999px; overflow: hidden; }
    .filiere-card__bar span { display: block; height: 100%; border-radius: inherit; background: var(--primary); }
    .filiere-card--blue .filiere-card__bar span { background: var(--blue); }
    .filiere-card--green .filiere-card__bar span { background: var(--green); }
    .filiere-card--orange .filiere-card__bar span { background: var(--orange); }

    .btn, .action-btn, .modal__btn { border: 0; border-radius: 15px; padding: 12px 14px; cursor: pointer; font-weight: 900; }
    .btn--ghost { background: #f4f2ff; color: var(--primary); }
    .btn--block { width: 100%; }
    .btn--primary, .modal__btn--primary { background: var(--primary); color: #fff; }
    .btn--blue { background: var(--blue); color: #fff; }
    .btn--green { background: var(--green); color: #fff; }
    .btn--orange { background: var(--orange); color: #fff; }

    .actions-row { display: flex; flex-wrap: wrap; gap: 12px; }
    .action-btn { color: #fff; transition: .18s; }
    .action-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 25px rgba(35,31,74,.12); }
    .action-btn--primary { background: var(--primary); }
    .action-btn--blue { background: var(--blue); }
    .action-btn--green { background: var(--green); }
    .action-btn--orange { background: var(--orange); }
    .action-btn--pink { background: var(--pink); }

    .modal-overlay { position: fixed; inset: 0; background: rgba(20,16,45,.45); z-index: 100; display: grid; place-items: center; padding: 20px; }
    .modal-overlay[hidden] { display: none; }
    .modal { width: min(620px, 100%); max-height: 90vh; overflow: auto; background: #fff; border-radius: 26px; padding: 22px; box-shadow: var(--shadow); position: relative; }
    .modal__close { position: absolute; right: 15px; top: 15px; width: 38px; height: 38px; border: 0; border-radius: 13px; background: #fff0f0; color: var(--pink); cursor: pointer; font-weight: 900; }
    .modal__header { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
    .modal__icon { width: 45px; height: 45px; display: grid; place-items: center; border-radius: 15px; background: #f4f2ff; font-size: 23px; }
    .modal__header h3 { margin: 0; }
    .modal__fields { display: grid; gap: 13px; }
    .modal__field { display: grid; gap: 7px; }
    .modal__field-label { font-weight: 900; font-size: 13px; }
    .required-mark { color: var(--pink); margin-left: 3px; }
    .modal__input, .modal__select, .modal__textarea, .modal__search { width: 100%; border: 1px solid var(--border); border-radius: 14px; padding: 12px 13px; outline: none; font: inherit; background: #fbfaff; }
    .modal__textarea { min-height: 90px; resize: vertical; }
    .modal__field-error { display: none; color: var(--pink); font-size: 12px; font-weight: 800; }
    .modal__field--invalid .modal__input, .modal__field--invalid .modal__select, .modal__field--invalid .modal__textarea { border-color: var(--pink); }
    .modal__field--invalid .modal__field-error { display: block; }
    .modal__student-selector { margin-top: 14px; display: grid; gap: 10px; }
    .modal__student-list { max-height: 180px; overflow: auto; display: grid; gap: 8px; }
    .student-row { display: flex; gap: 10px; align-items: center; padding: 10px; border-radius: 14px; background: #faf9ff; }
    .student-row__info { display: grid; }
    .student-row__name { font-weight: 900; }
    .student-row__meta { color: var(--muted); font-size: 12px; font-weight: 700; }
    .modal__selected-count { color: var(--muted); font-size: 13px; font-weight: 800; }
    .modal__actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
    .modal__btn--ghost { background: #f4f2ff; color: var(--primary); }

    .toast-container { position: fixed; right: 20px; bottom: 20px; z-index: 120; display: grid; gap: 10px; }
    .toast { background: #1c1832; color: #fff; padding: 14px 16px; border-radius: 16px; box-shadow: var(--shadow); font-weight: 800; animation: toastIn .2s ease; }
    .toast--error { background: var(--pink); }
    .toast.is-leaving { opacity: 0; transform: translateY(8px); }
    @keyframes toastIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

    @media (min-width: 861px) {
      .sidebar.is-collapsed { width: 96px; padding-left: 16px; padding-right: 16px; }
      .sidebar.is-collapsed .sidebar__header { justify-content: center; gap: 10px; }
      .sidebar.is-collapsed .brand span:not(.brand__icon) { display: none; }
      .sidebar.is-collapsed .nav-link { justify-content: center; gap: 0; }
      .sidebar.is-collapsed .nav-link span:not(.nav-icon) { display: none; }
      .sidebar.is-collapsed .user-card { justify-content: center; padding: 14px 0; }
      .sidebar.is-collapsed .user-card__info { display: none; }
      .sidebar.is-collapsed .logout-btn { display: none; }
      .main { min-width: 0; }
    }

    @media (max-width: 1180px) {
      .stats-grid { grid-template-columns: repeat(3, 1fr); }
      .columns-grid { grid-template-columns: 1fr; }
      .filiere-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 860px) {
      .sidebar { position: fixed; left: 0; top: 0; transform: translateX(-105%); transition: .25s; }
      .sidebar.is-open { transform: translateX(0); }
      .sidebar-overlay.is-visible { display: block; }
      .mobile-menu-btn { display: grid; }
      .topbar { padding: 0 16px; }
      .content { padding: 18px; }
      .banner { grid-template-columns: 1fr; }
      .stats-grid, .filiere-grid { grid-template-columns: 1fr; }
      .date-pill__text { display: none; }
      .calendar-popover { right: -55px; }
    }
  </style>
</head>
<body>

<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar__header">
      <div class="brand"><span class="brand__icon">🎓</span><span>Smart<strong>Teacher</strong></span></div>
      <button class="sidebar__toggle" id="sidebarToggle" type="button" aria-label="Menu">☰</button>
    </div>

    <nav class="sidebar__nav">
      <ul class="nav-list">
        <li class="nav-item nav-item--active"><a href="dashboard.php" class="nav-link"><span class="nav-icon">🏠</span><span>Dashboard</span></a></li>
        <li class="nav-item"><a href="groups.php" class="nav-link"><span class="nav-icon">👥</span><span>Groups</span></a></li>
        <li class="nav-item"><a href="eleve.php" class="nav-link"><span class="nav-icon">🧑‍🎓</span><span>Élèves</span></a></li>
        <li class="nav-item"><a href="seance.php" class="nav-link"><span class="nav-icon">📅</span><span>Séance</span></a></li>
        <li class="nav-item"><a href="presence.php" class="nav-link"><span class="nav-icon">⬇️</span><span>Présence</span></a></li>
        <li class="nav-item"><a href="paiment.php" class="nav-link"><span class="nav-icon">💳</span><span>Paiements</span></a></li>
      </ul>
    </nav>

    <div class="sidebar__footer">
      <div class="user-card">
        <span class="user-card__avatar">👨‍🏫</span>
        <div class="user-card__info">
          <p class="user-card__name">Professeur<br>Physique</p>
          <span class="user-card__badge">Enseignant</span>
        </div>
      </div>
      <button class="logout-btn" type="button">Déconnexion</button>
    </div>
  </aside>

  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="main">
    <header class="topbar">
      <div class="topbar__left">
        <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" aria-label="Menu">☰</button>
        <h1 class="topbar__title">Dashboard</h1>
      </div>

      <div class="topbar__right">
        <button class="icon-btn" id="searchBtn" type="button" aria-label="Rechercher">🔎</button>
        <div class="date-dropdown" id="dateDropdown">
          <button class="date-pill" id="datePillBtn" type="button" aria-haspopup="true" aria-expanded="false">
            <span class="date-pill__icon">📅</span>
            <span class="date-pill__text"><strong id="todayDate">--</strong><small id="todayDay">--</small></span>
            <span>⌄</span>
          </button>
          <div class="calendar-popover" id="calendarPopover" hidden>
            <div class="calendar-popover__header">
              <button class="calendar-nav" id="calPrev" type="button" aria-label="Mois précédent">‹</button>
              <span class="calendar-popover__month" id="calMonthLabel">--</span>
              <button class="calendar-nav" id="calNext" type="button" aria-label="Mois suivant">›</button>
            </div>
            <div class="calendar-weekdays"><span>L</span><span>M</span><span>M</span><span>J</span><span>V</span><span>S</span><span>D</span></div>
            <div class="calendar-grid" id="calendarGrid"></div>
            <button class="calendar-today-btn" id="calToday" type="button">Aujourd'hui</button>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
      <?php if (!empty($seances_a_confirmer)): ?>
      <section class="seance-reminder-stack" id="seanceReminderStack" aria-live="polite">
        <?php foreach ($seances_a_confirmer as $sc):
              $dateLabel  = date('d/m/Y', strtotime($sc['date_seance']));
              $heureLabel = substr($sc['heure_debut'], 0, 5);
        ?>
        <div class="seance-reminder-alert" data-id="<?php echo (int)$sc['id_seance']; ?>"
             style="display:flex;align-items:flex-start;gap:14px;background:#fff6ec;border:1px solid var(--orange);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px 18px;margin-bottom:14px;">
          <span style="font-size:22px;line-height:1;" aria-hidden="true">⚠️</span>
          <div style="flex:1;min-width:0;">
            <p style="margin:0 0 4px;color:var(--text);font-weight:600;">
              La séance du groupe <?php echo htmlspecialchars($sc['nom_groupe'], ENT_QUOTES, 'UTF-8'); ?>,
              prévue le <?php echo $dateLabel; ?> à <?php echo $heureLabel; ?>,
              est terminée mais son statut est toujours « À venir ».
            </p>
            <p style="margin:0 0 12px;color:var(--muted);font-size:14px;">Veuillez mettre à jour son statut.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button type="button" class="seance-reminder-btn" data-statut="Terminée"
                      style="border:none;cursor:pointer;padding:8px 14px;border-radius:10px;font-weight:600;background:var(--green);color:#fff;">
                ✅ Marquer comme Terminée
              </button>
              <button type="button" class="seance-reminder-btn" data-statut="Annulée"
                      style="border:none;cursor:pointer;padding:8px 14px;border-radius:10px;font-weight:600;background:var(--pink);color:#fff;">
                ❌ Marquer comme Annulée
              </button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <section class="banner">
        <div class="banner__text">
          <h2>Bonjour Professeur ! <span class="wave">👋</span></h2>
          <p>Voici un aperçu général de vos groupes et activités.</p>
        </div>
        <div class="banner__illustration" aria-hidden="true">👨‍🏫</div>
      </section>

      <section class="stats-grid">
        <article class="stat-card"><div class="stat-card__icon stat-card__icon--primary">👥</div><p class="stat-card__label">Total Élèves</p><p class="stat-card__value"><?php echo $total_eleves; ?></p></article>
        <article class="stat-card"><div class="stat-card__icon stat-card__icon--blue">👩‍🏫</div><p class="stat-card__label">Total Groupes</p><p class="stat-card__value"><?php echo $total_groupes; ?></p></article>
        <article class="stat-card"><div class="stat-card__icon stat-card__icon--orange">📅</div><p class="stat-card__label">Séances Aujourd'hui</p><p class="stat-card__value"><?php echo $total_seances_today; ?></p><a href="seance.php" class="stat-card__link">Voir emploi du temps ›</a></article>
        <article class="stat-card"><div class="stat-card__icon stat-card__icon--pink">🚫</div><p class="stat-card__label">Absences Aujourd'hui</p><p class="stat-card__value"><?php echo $total_absences_today; ?></p><a href="presence.php" class="stat-card__link stat-card__link--danger">Voir détails ›</a></article>
        <article class="stat-card"><div class="stat-card__icon stat-card__icon--green">💵</div><p class="stat-card__label">Paiements en attente</p><p class="stat-card__value"><?php echo $total_paiements_attente; ?></p><a href="paiment.php" class="stat-card__link stat-card__link--success">Voir la liste ›</a></article>
      </section>

      <section class="columns-grid">
        <article class="panel">
          <header class="panel__header"><span class="panel__icon panel__icon--primary">🕐</span><h3>Prochaines séances aujourd'hui</h3></header>
          <ul class="schedule-list">
            <?php if (mysqli_num_rows($res_seances_list) > 0): ?>
              <?php while ($seance = mysqli_fetch_assoc($res_seances_list)): ?>
                <li class="schedule-item"><span class="schedule-item__time"><?php echo htmlspecialchars(date('H:i', strtotime($seance['heure_debut'])), ENT_QUOTES, 'UTF-8'); ?></span><span class="schedule-item__group"><?php echo htmlspecialchars($seance['nom_groupe'], ENT_QUOTES, 'UTF-8'); ?></span><span class="schedule-item__subject">Physique</span></li>
              <?php endwhile; ?>
            <?php else: ?>
              <li class="schedule-item"><span class="schedule-item__group">Aucune séance aujourd'hui</span></li>
            <?php endif; ?>
          </ul>
          <br><a href="seance.php" class="panel__footer-link">Voir tout l'emploi du temps ›</a>
        </article>

        <article class="panel">
          <header class="panel__header"><span class="panel__icon panel__icon--orange">💳</span><h3>Paiements en attente</h3></header>
          <ul class="payment-list">
            <?php if (mysqli_num_rows($res_paiements_list) > 0): ?>
              <?php while ($paiement = mysqli_fetch_assoc($res_paiements_list)): ?>
                <li class="payment-item"><div class="payment-item__info"><span class="payment-item__name"><?php echo htmlspecialchars($paiement['prenom'] . ' ' . $paiement['nom'], ENT_QUOTES, 'UTF-8'); ?></span><span class="payment-item__group"><?php echo htmlspecialchars($paiement['nom_groupe'], ENT_QUOTES, 'UTF-8'); ?></span></div><span class="payment-item__amount"><?php echo number_format((float)$paiement['montant_a_payer'], 0, ',', ' '); ?> DT</span><span class="badge badge--danger">Non payé</span></li>
              <?php endwhile; ?>
            <?php else: ?>
              <li class="payment-item"><div class="payment-item__info"><span class="payment-item__name">Aucun paiement en attente</span></div></li>
            <?php endif; ?>
          </ul>
          <br><a href="paiment.php" class="panel__footer-link panel__footer-link--orange">Voir tous les impayés ›</a>
        </article>

        <article class="panel">
          <header class="panel__header"><span class="panel__icon panel__icon--pink">🚷</span><h3>Absences récentes</h3></header>
          <ul class="absence-list">
            <?php if (mysqli_num_rows($res_absences_list) > 0): ?>
              <?php while ($absence = mysqli_fetch_assoc($res_absences_list)): ?>
                <li class="absence-item"><div class="absence-item__info"><span class="absence-item__name"><?php echo htmlspecialchars($absence['prenom'] . ' ' . $absence['nom'], ENT_QUOTES, 'UTF-8'); ?></span><span class="absence-item__group"><?php echo htmlspecialchars($absence['nom_groupe'], ENT_QUOTES, 'UTF-8'); ?></span></div><span class="badge badge--danger-outline"><?php echo (int)$absence['nb_absences']; ?> absence<?php echo ((int)$absence['nb_absences'] > 1) ? 's' : ''; ?></span></li>
              <?php endwhile; ?>
            <?php else: ?>
              <li class="absence-item"><div class="absence-item__info"><span class="absence-item__name">Aucune absence enregistrée</span></div></li>
            <?php endif; ?>
          </ul>
          <br><a href="presence.php" class="panel__footer-link panel__footer-link--pink">Voir toutes les absences ›</a>
        </article>
      </section>

      <section class="panel panel--wide">
        <header class="panel__header panel__header--spread"><div class="panel__header-left"><span class="panel__icon panel__icon--primary">📘</span><h3>Aperçu par filière</h3></div><button class="btn btn--ghost" type="button" data-action="addFiliere">⚙️ Gérer les filières</button></header>
        <div class="filiere-grid">
          <?php if (mysqli_num_rows($res_filieres) > 0): ?>
            <?php while ($filiere = mysqli_fetch_assoc($res_filieres)):
              $style = $filiere_style[$filiere['nom_filiere']] ?? ['icon' => '📘', 'color' => 'primary'];
              $capacite = (int)$filiere['capacite_totale'];
              $eleves = (int)$filiere['nb_eleves'];
              $pourcentage = $capacite > 0 ? min(100, round(($eleves / $capacite) * 100)) : 0;
              $nb_groupes = (int)$filiere['nb_groupes'];
              $label_groupe = $nb_groupes > 1 ? 'Groupes' : 'Groupe';
            ?>
              <article class="filiere-card filiere-card--<?php echo htmlspecialchars($style['color'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="filiere-card__icon"><?php echo htmlspecialchars($style['icon'], ENT_QUOTES, 'UTF-8'); ?></div>
                <h4><?php echo htmlspecialchars($filiere['nom_filiere'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="filiere-card__stats"><div><strong><?php echo $nb_groupes; ?></strong><span><?php echo $label_groupe; ?></span></div><div><strong><?php echo $eleves; ?></strong><span>Élèves</span></div></div>
                <div class="filiere-card__bar"><span style="width:<?php echo $pourcentage; ?>%"></span></div>
                <button class="btn btn--block btn--<?php echo htmlspecialchars($style['color'], ENT_QUOTES, 'UTF-8'); ?>" type="button" onclick="location.href='groups.php?filiere=<?php echo urlencode($filiere['nom_filiere']); ?>'">Voir <?php echo $nb_groupes > 1 ? 'les groupes' : 'le groupe'; ?> ›</button>

              </article>
            <?php endwhile; ?>
          <?php else: ?>
            <p>Aucune filière enregistrée.</p>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel panel--wide actions-panel">
        <header class="panel__header"><span class="panel__icon panel__icon--orange">⚡</span><h3>Actions rapides</h3></header>
        <div class="actions-row">
          <button class="action-btn action-btn--primary" type="button" data-action="addStudent">👤➕ Ajouter un élève</button>
          <button class="action-btn action-btn--blue" type="button" data-action="createGroup">👥✏️ Créer un groupe</button>
          <button class="action-btn action-btn--green" type="button" data-action="newSession">🗓️➕ Nouvelle séance</button>
          <button class="action-btn action-btn--orange" type="button" onclick="window.location.href='paiment.php'">💳✔️ Vérifier les paiements</button>
          <button class="action-btn action-btn--pink" type="button" data-action="exportReport">📄⬇️ Exporter un rapport</button>
        </div>
      </section>
    </main>
  </div>
</div>

<div class="modal-overlay" id="modalOverlay" hidden>
  <div class="modal" id="modalBox" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <button class="modal__close" id="modalClose" type="button" aria-label="Fermer">✕</button>
    <header class="modal__header"><span class="modal__icon" id="modalIcon">📝</span><h3 id="modalTitle">Titre</h3></header>
    <form class="modal__form" id="modalForm" novalidate>
      <div class="modal__fields" id="modalFields"></div>
      <div class="modal__student-selector" id="modalStudentSelector" hidden>
        <label class="modal__field-label" for="studentSearch">Sélectionner les élèves</label>
        <input type="text" class="modal__search" id="studentSearch" placeholder="Rechercher un élève par nom...">
        <div class="modal__student-list" id="studentList"></div>
        <span class="modal__selected-count" id="selectedCount">0 élève(s) sélectionné(s)</span>
      </div>
      <div class="modal__actions"><button type="button" class="modal__btn modal__btn--ghost" id="modalCancel">Annuler</button><button type="submit" class="modal__btn modal__btn--primary" id="modalSubmit">Valider</button></div>
    </form>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  initMobileSidebar();
  initCalendarDropdown();
  initSearchButton();
  initNavSelection();
  initModalSystem();
  initLogout();
  initSeanceReminders();
});

function initMobileSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const openBtn = document.getElementById('mobileMenuBtn');
  const toggleBtn = document.getElementById('sidebarToggle');
  if (!sidebar || !overlay) return;

  const isMobile = () => window.innerWidth <= 860;

  const openSidebar = () => { sidebar.classList.add('is-open'); overlay.classList.add('is-visible'); document.body.style.overflow = 'hidden'; };
  const closeSidebar = () => { sidebar.classList.remove('is-open'); overlay.classList.remove('is-visible'); document.body.style.overflow = ''; };
  const toggleMobileSidebar = () => { sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar(); };
  const toggleDesktopSidebar = () => { sidebar.classList.toggle('is-collapsed'); };

  if (openBtn) openBtn.addEventListener('click', toggleMobileSidebar);

  if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
      if (isMobile()) closeSidebar();
      else toggleDesktopSidebar();
    });
  }

  overlay.addEventListener('click', closeSidebar);
  sidebar.querySelectorAll('.nav-link').forEach((link) => link.addEventListener('click', () => { if (isMobile()) closeSidebar(); }));
  window.addEventListener('resize', () => { if (!isMobile()) closeSidebar(); });
}

function initCalendarDropdown() {
  const dropdown = document.getElementById('dateDropdown');
  const trigger = document.getElementById('datePillBtn');
  const popover = document.getElementById('calendarPopover');
  const monthLabel = document.getElementById('calMonthLabel');
  const grid = document.getElementById('calendarGrid');
  const prevBtn = document.getElementById('calPrev');
  const nextBtn = document.getElementById('calNext');
  const todayBtn = document.getElementById('calToday');
  const dateEl = document.getElementById('todayDate');
  const dayEl = document.getElementById('todayDay');
  if (!dropdown || !trigger || !popover || !grid || !monthLabel || !prevBtn || !nextBtn || !todayBtn || !dateEl || !dayEl) return;

  const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
  const DAY_NAMES = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
  const today = new Date();
  let viewYear = today.getFullYear();
  let viewMonth = today.getMonth();
  let selectedDate = new Date(today);

  function updatePillText(date) {
    dateEl.textContent = `${date.getDate()} ${MONTHS[date.getMonth()]} ${date.getFullYear()}`;
    dayEl.textContent = DAY_NAMES[date.getDay()];
  }
  function sameDay(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }
  function renderCalendar() {
    monthLabel.textContent = `${MONTHS[viewMonth]} ${viewYear}`;
    grid.innerHTML = '';
    const firstOfMonth = new Date(viewYear, viewMonth, 1);
    const startOffset = (firstOfMonth.getDay() + 6) % 7;
    const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
    const daysInPrevMonth = new Date(viewYear, viewMonth, 0).getDate();
    const cells = [];
    for (let i = startOffset - 1; i >= 0; i--) cells.push({ day: daysInPrevMonth - i, muted: true });
    for (let d = 1; d <= daysInMonth; d++) cells.push({ day: d, muted: false });
    while (cells.length % 7 !== 0) cells.push({ day: cells.length - (startOffset + daysInMonth) + 1, muted: true });

    cells.forEach((cell) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'calendar-day';
      btn.textContent = cell.day;
      if (cell.muted) btn.classList.add('calendar-day--muted');
      else {
        const cellDate = new Date(viewYear, viewMonth, cell.day);
        if (sameDay(cellDate, today)) btn.classList.add('calendar-day--today');
        if (sameDay(cellDate, selectedDate)) btn.classList.add('calendar-day--selected');
        btn.addEventListener('click', () => { selectedDate = cellDate; updatePillText(selectedDate); renderCalendar(); closePopover(); });
      }
      grid.appendChild(btn);
    });
  }
  function openPopover() { popover.hidden = false; dropdown.classList.add('is-open'); trigger.setAttribute('aria-expanded', 'true'); viewYear = selectedDate.getFullYear(); viewMonth = selectedDate.getMonth(); renderCalendar(); }
  function closePopover() { popover.hidden = true; dropdown.classList.remove('is-open'); trigger.setAttribute('aria-expanded', 'false'); }
  trigger.addEventListener('click', (e) => { e.stopPropagation(); popover.hidden ? openPopover() : closePopover(); });
  prevBtn.addEventListener('click', (e) => { e.stopPropagation(); viewMonth--; if (viewMonth < 0) { viewMonth = 11; viewYear--; } renderCalendar(); });
  nextBtn.addEventListener('click', (e) => { e.stopPropagation(); viewMonth++; if (viewMonth > 11) { viewMonth = 0; viewYear++; } renderCalendar(); });
  todayBtn.addEventListener('click', (e) => { e.stopPropagation(); selectedDate = new Date(today); updatePillText(selectedDate); viewYear = today.getFullYear(); viewMonth = today.getMonth(); renderCalendar(); closePopover(); });
  popover.addEventListener('click', (e) => e.stopPropagation());
  document.addEventListener('click', (e) => { if (!dropdown.contains(e.target)) closePopover(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePopover(); });
  updatePillText(selectedDate);
}

function initSearchButton() {
  const searchBtn = document.getElementById('searchBtn');
  if (!searchBtn) return;
  searchBtn.addEventListener('click', () => {
    const query = window.prompt('Rechercher un élève, un groupe ou une séance :');
    if (!query || !query.trim()) return;
    window.location.href = 'eleve.php?search=' + encodeURIComponent(query.trim());
  });
}

function initNavSelection() {
  const items = document.querySelectorAll('.nav-item');
  const currentFile = window.location.pathname.split('/').pop() || 'dashboard.php';
  items.forEach((item) => {
    const link = item.querySelector('.nav-link');
    if (!link) return;
    const hrefFile = (link.getAttribute('href') || '').split('/').pop();
    if (hrefFile === currentFile) { items.forEach((i) => i.classList.remove('nav-item--active')); item.classList.add('nav-item--active'); }
  });
}

function initLogout() {
  const btn = document.querySelector('.logout-btn');
  if (!btn) return;
  btn.addEventListener('click', () => {
    if (window.confirm('Voulez-vous vraiment vous déconnecter ?')) window.location.href = 'logout.php';
  });
}

/* --------------------------------------------------------------------
   Système de rappel intelligent — séances dépassées toujours "À venir".
   Le statut n'est JAMAIS changé tout seul : cette fonction se contente
   de câbler les deux boutons ("Terminée" / "Annulée") de chaque alerte
   sur l'endpoint AJAX update_seance_statut. C'est le professeur qui
   décide, en cliquant, ce que devient la séance.
   -------------------------------------------------------------------- */
function initSeanceReminders() {
  const stack = document.getElementById('seanceReminderStack');
  if (!stack) return;

  stack.addEventListener('click', async (e) => {
    const btn = e.target.closest('.seance-reminder-btn');
    if (!btn) return;

    const alertEl = btn.closest('.seance-reminder-alert');
    const id = alertEl?.getAttribute('data-id');
    const statut = btn.getAttribute('data-statut');
    if (!id || !statut) return;

    const allButtons = alertEl.querySelectorAll('.seance-reminder-btn');
    allButtons.forEach((b) => (b.disabled = true));
    const originalLabel = btn.textContent;
    btn.textContent = 'Mise à jour...';

    try {
      const result = await postAction('dashboard.php', {
        ajax_action: 'update_seance_statut',
        id,
        statut,
      });

      if (result && result.success) {
        // Le statut n'est plus "À venir" : l'alerte n'a plus lieu d'être,
        // elle disparaît donc immédiatement (et ne reviendra plus au
        // prochain chargement puisque la base est à jour).
        alertEl.style.transition = 'opacity .2s ease';
        alertEl.style.opacity = '0';
        setTimeout(() => {
          alertEl.remove();
          if (!stack.querySelector('.seance-reminder-alert')) stack.remove();
        }, 200);
        showToast(statut === 'Terminée' ? '✅ Séance marquée comme Terminée.' : '❌ Séance marquée comme Annulée.');
      } else {
        showToast((result && result.message) || "❌ Une erreur est survenue.", 'error');
        allButtons.forEach((b) => (b.disabled = false));
        btn.textContent = originalLabel;
      }
    } catch (err) {
      showToast('❌ Erreur réseau, veuillez réessayer.', 'error');
      allButtons.forEach((b) => (b.disabled = false));
      btn.textContent = originalLabel;
    }
  });
}

const FILIERES = <?php echo json_encode($filieres_list, JSON_UNESCAPED_UNICODE); ?>;
const GROUPES = <?php echo json_encode($groupes_list, JSON_UNESCAPED_UNICODE); ?>;
const filiereOptions = () => FILIERES.map((f) => ({ value: String(f.id_filiere), label: f.nom_filiere }));
const groupeOptions = () => GROUPES.map((g) => ({ value: String(g.id_groupe), label: g.nom_groupe }));

async function postAction(endpoint, data) {
  let res;
  try {
    res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(data).toString(),
    });
  } catch (networkErr) {
    // Le fetch lui-même a échoué (pas de connexion, CORS, serveur injoignable...)
    const err = new Error('network');
    err.kind = 'network';
    throw err;
  }

  // On lit toujours le corps en texte brut d'abord : si le PHP a renvoyé
  // un warning/notice/espace en plus du JSON, on veut pouvoir le
  // diagnostiquer au lieu de mélanger ça avec une vraie erreur réseau.
  const rawText = await res.text();

  let payload;
  try {
    payload = JSON.parse(rawText);
  } catch (parseErr) {
    console.error(`Réponse non-JSON reçue de ${endpoint} (HTTP ${res.status}) :`, rawText);
    const err = new Error('invalid-json');
    err.kind = 'invalid-json';
    throw err;
  }

  if (!res.ok) {
    // Le serveur a répondu avec un code d'erreur HTTP mais un JSON exploitable
    // (ex: 500 avec message d'erreur) : on privilégie ce message plutôt
    // qu'un "erreur réseau" générique trompeur.
    return payload;
  }

  return payload;
}

/* --------------------------------------------------------------------
   Cascade Filière -> Groupe pour le formulaire "Ajouter un élève".
   Les deux listes sont chargées depuis la base de données via AJAX
   (addfiliere.php / get_groupe.php), pas depuis un tableau codé en dur.
   -------------------------------------------------------------------- */
function initAddStudentCascade() {
  const filiereSelect = document.getElementById('field-id_filiere');
  const groupeSelect = document.getElementById('field-id_groupe');
  if (!filiereSelect || !groupeSelect) return;

  function resetSelect(selectEl, placeholder) {
    selectEl.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = ''; ph.textContent = placeholder; ph.disabled = true; ph.selected = true;
    selectEl.appendChild(ph);
  }

  resetSelect(groupeSelect, 'Sélectionner…');
  groupeSelect.disabled = true;

  // Charger la liste des filières depuis la base de données via AJAX
  resetSelect(filiereSelect, 'Chargement…');
  filiereSelect.disabled = true;
  fetch('addfiliere.php')
    .then((res) => res.json())
    .then((data) => {
      resetSelect(filiereSelect, 'Sélectionner…');
      filiereSelect.disabled = false;
      (data.filieres || []).forEach((f) => {
        const opt = document.createElement('option');
        opt.value = f.id_filiere; opt.textContent = f.nom_filiere;
        filiereSelect.appendChild(opt);
      });
    })
    .catch(() => showToast('❌ Impossible de charger la liste des filières.', 'error'));

  // Quand une filière est choisie, charger uniquement ses groupes via AJAX
  filiereSelect.addEventListener('change', () => {
    const idFiliere = filiereSelect.value;
    resetSelect(groupeSelect, 'Sélectionner…');
    groupeSelect.disabled = true;
    if (!idFiliere) return;
    resetSelect(groupeSelect, 'Chargement…');
    fetch('get_groupe.php?id_filiere=' + encodeURIComponent(idFiliere))
      .then((res) => res.json())
      .then((data) => {
        resetSelect(groupeSelect, 'Sélectionner…');
        (data.groupes || []).forEach((g) => {
          const opt = document.createElement('option');
          opt.value = g.id_groupe; opt.textContent = g.nom_groupe;
          groupeSelect.appendChild(opt);
        });
        groupeSelect.disabled = false;
      })
      .catch(() => {
        resetSelect(groupeSelect, 'Sélectionner…');
        groupeSelect.disabled = false;
        showToast('❌ Impossible de charger la liste des groupes.', 'error');
      });
  });

  // Affichage conditionnel du champ "Montant payé" selon le statut de paiement :
  // visible et obligatoire uniquement si Statut = "Payé".
  const statutSelect = document.getElementById('field-statut_paiement');
  const montantInput = document.getElementById('field-montant_paye');
  if (statutSelect && montantInput) {
    const montantWrap = montantInput.closest('.modal__field');
    function toggleMontantPaye() {
      const estPaye = statutSelect.value === 'Payé';
      montantWrap.hidden = !estPaye;
      if (estPaye) {
        montantInput.setAttribute('required', 'required');
      } else {
        montantInput.removeAttribute('required');
        montantInput.value = '';
        montantWrap.classList.remove('modal__field--invalid');
      }
    }
    toggleMontantPaye();
    statutSelect.addEventListener('change', toggleMontantPaye);
  }
}

function initModalSystem() {
  const overlay = document.getElementById('modalOverlay');
  const closeBtn = document.getElementById('modalClose');
  const cancelBtn = document.getElementById('modalCancel');
  const form = document.getElementById('modalForm');
  const titleEl = document.getElementById('modalTitle');
  const iconEl = document.getElementById('modalIcon');
  const fieldsEl = document.getElementById('modalFields');
  const selectorWrap = document.getElementById('modalStudentSelector');
  const studentListEl = document.getElementById('studentList');
  const studentSearchEl = document.getElementById('studentSearch');
  const selectedCountEl = document.getElementById('selectedCount');
  const submitBtn = document.getElementById('modalSubmit');
  if (!overlay || !form || !closeBtn || !cancelBtn || !titleEl || !iconEl || !fieldsEl || !selectorWrap || !studentListEl || !studentSearchEl || !selectedCountEl || !submitBtn) return;

  let currentAction = null;
  let selectedStudentIds = new Set();
  const studentOptions = () => STUDENTS.map((s) => ({ value: String(s.id), label: `${s.prenom} ${s.nom} — ${s.groupe}` }));
  const ACTIONS = {
    addStudent: {
      title: 'Ajouter un élève', icon: '👤', submitLabel: "Ajouter l'élève",
      fields: [
        { name: 'nom', label: 'Nom', type: 'text', required: true, placeholder: 'ex: Ben Ali' },
        { name: 'prenom', label: 'Prénom', type: 'text', required: true, placeholder: 'ex: Ahmed' },
        { name: 'telephone', label: 'Téléphone', type: 'tel', required: true, placeholder: 'ex: 22 345 678' },
        { name: 'date_inscription', label: "Date d'inscription", type: 'date', required: true },
        { name: 'id_filiere', label: 'Filière', type: 'select', required: true, options: [] },
        { name: 'id_groupe', label: 'Groupe', type: 'select', required: true, options: [], disabled: true },
        { name: 'statut_paiement', label: 'Statut de paiement', type: 'select', required: true, options: [
            { value: 'En attente', label: 'En attente' },
            { value: 'Payé', label: 'Payé' },
          ] },
        { name: 'montant_paye', label: 'Montant payé (DT)', type: 'number', required: false, placeholder: 'ex: 50' },
      ],
      onSubmit: (data) => {
        if (data.statut_paiement === 'Payé' && (!data.montant_paye || parseFloat(data.montant_paye) <= 0)) {
          return { success: false, message: 'Veuillez saisir le montant payé.' };
        }
        return postAction('add_eleve.php', data);
      },
      postRender: initAddStudentCascade,
    },
    createGroup: {
      title: 'Créer un groupe', icon: '👥', submitLabel: 'Créer le groupe',
      fields: [
        { name: 'nom_groupe', label: 'Nom du groupe', type: 'text', required: true, placeholder: 'ex: Info C' },
        { name: 'id_filiere', label: 'Filière', type: 'select', required: true, options: filiereOptions },
        { name: 'capacite', label: 'Capacité (nombre de places)', type: 'number', required: false, placeholder: 'ex: 25' },
      ],
      onSubmit: (data) => postAction('add_group.php', data),
    },
    newSession: {
      title: 'Nouvelle séance', icon: '🗓️', submitLabel: 'Planifier',
      fields: [
        { name: 'id_groupe', label: 'Groupe', type: 'select', required: true, options: groupeOptions },
        { name: 'chapitre', label: 'Chapitre', type: 'text', required: true, placeholder: 'ex: Les forces et mouvements' },
        { name: 'date_seance', label: 'Date', type: 'date', required: true },
        { name: 'heure_debut', label: 'Heure début', type: 'time', required: true },
        { name: 'heure_fin', label: 'Heure fin', type: 'time', required: true },
      ],
      onSubmit: (data) => postAction('add_seance.php', data),
    },
    exportReport: {
      title: 'Exporter un rapport', icon: '📄', submitLabel: 'Exporter', reload: false,
      fields: [
        { name: 'type', label: 'Type de rapport', type: 'select', required: true, options: ['Présences', 'Paiements', 'Notes', 'Groupes'] },
        { name: 'dateDebut', label: 'Date début', type: 'date', required: true },
        { name: 'dateFin', label: 'Date fin', type: 'date', required: true },
        { name: 'format', label: 'Format', type: 'select', required: true, options: ['PDF', 'Excel'] },
      ],
      onSubmit: (data) => {
        if (data.format === 'PDF') {
          window.open('export_rapport.php', '_blank');
          return { success: true, message: '📄 Le rapport PDF a été généré et téléchargé.' };
        }
        return { success: false, message: "⚠️ L'export Excel n'est pas encore disponible. Choisissez le format PDF." };
      },
    },
    addFiliere: { title: 'Ajouter une filière', icon: '🗂️', submitLabel: 'Ajouter la filière', fields: [{ name: 'nomFiliere', label: 'Nom de la filière', type: 'text', required: true, placeholder: 'ex: Bac Sport' }, { name: 'niveau', label: 'Niveau', type: 'select', required: true, options: ['1ère année', '2ème année', 'Bac'] }], onSubmit: (data) => showToast(`✅ Filière « ${data.nomFiliere} » ajoutée.`) },
  };

  function buildField(field) {
    const wrap = document.createElement('div');
    wrap.className = 'modal__field';
    const label = document.createElement('label');
    label.className = 'modal__field-label';
    label.htmlFor = `field-${field.name}`;
    label.innerHTML = field.required ? `${field.label}<span class="required-mark">*</span>` : field.label;
    let input;
    if (field.type === 'select') {
      input = document.createElement('select');
      input.className = 'modal__select';
      const ph = document.createElement('option');
      ph.value = ''; ph.textContent = 'Sélectionner…'; ph.disabled = true; ph.selected = true;
      input.appendChild(ph);
      const opts = typeof field.options === 'function' ? field.options() : field.options;
      opts.forEach((opt) => { const o = document.createElement('option'); if (typeof opt === 'object') { o.value = opt.value; o.textContent = opt.label; } else { o.value = opt; o.textContent = opt; } input.appendChild(o); });
    } else if (field.type === 'textarea') {
      input = document.createElement('textarea'); input.className = 'modal__textarea'; if (field.placeholder) input.placeholder = field.placeholder;
    } else {
      input = document.createElement('input'); input.className = 'modal__input'; input.type = field.type; if (field.placeholder) input.placeholder = field.placeholder;
    }
    input.id = `field-${field.name}`; input.name = field.name; if (field.required) input.required = true; if (field.disabled) input.disabled = true;
    const error = document.createElement('span'); error.className = 'modal__field-error'; error.textContent = 'Ce champ est requis.';
    wrap.appendChild(label); wrap.appendChild(input); wrap.appendChild(error); return wrap;
  }

  function renderStudentList(filterText) {
    const term = (filterText || '').trim().toLowerCase();
    const filtered = STUDENTS.filter((s) => `${s.prenom} ${s.nom} ${s.groupe} ${s.filiere}`.toLowerCase().includes(term));
    studentListEl.innerHTML = '';
    if (!filtered.length) { studentListEl.innerHTML = '<div class="student-row__empty">Aucun élève trouvé.</div>'; return; }
    filtered.forEach((s) => {
      const row = document.createElement('label'); row.className = 'student-row';
      const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = selectedStudentIds.has(s.id);
      checkbox.addEventListener('change', () => { checkbox.checked ? selectedStudentIds.add(s.id) : selectedStudentIds.delete(s.id); updateSelectedCount(); });
      const info = document.createElement('span'); info.className = 'student-row__info'; info.innerHTML = `<span class="student-row__name">${s.prenom} ${s.nom}</span><span class="student-row__meta">${s.groupe} · ${s.filiere}</span>`;
      row.appendChild(checkbox); row.appendChild(info); studentListEl.appendChild(row);
    });
  }
  function updateSelectedCount() { const n = selectedStudentIds.size; selectedCountEl.textContent = `${n} élève${n > 1 ? 's' : ''} sélectionné${n > 1 ? 's' : ''}`; }
  function openModal(actionKey) {
    const config = ACTIONS[actionKey]; if (!config) return;
    currentAction = config; selectedStudentIds = new Set(); titleEl.textContent = config.title; iconEl.textContent = config.icon; fieldsEl.innerHTML = '';
    config.fields.forEach((f) => fieldsEl.appendChild(buildField(f))); submitBtn.textContent = config.submitLabel || 'Valider';
    if (typeof config.postRender === 'function') config.postRender();
    if (config.withStudentSelector) { selectorWrap.hidden = false; studentSearchEl.value = ''; renderStudentList(''); updateSelectedCount(); } else selectorWrap.hidden = true;
    overlay.hidden = false; document.body.style.overflow = 'hidden'; setTimeout(() => fieldsEl.querySelector('input, select, textarea')?.focus(), 50);
  }
  function closeModal() { overlay.hidden = true; document.body.style.overflow = ''; currentAction = null; form.reset(); }
  document.querySelectorAll('[data-action]').forEach((el) => { const actionKey = el.getAttribute('data-action'); if (!actionKey) return; el.addEventListener('click', (e) => { e.preventDefault(); openModal(actionKey); }); });
  closeBtn.addEventListener('click', closeModal); cancelBtn.addEventListener('click', closeModal); overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !overlay.hidden) closeModal(); });
  studentSearchEl.addEventListener('input', () => renderStudentList(studentSearchEl.value));
  form.addEventListener('submit', async (e) => {
    e.preventDefault(); if (!currentAction) return;
    let isValid = true; const data = {};
    currentAction.fields.forEach((field) => { const inputEl = document.getElementById(`field-${field.name}`); const fieldWrap = inputEl.closest('.modal__field'); const value = inputEl.value.trim(); if (field.required && !value) { isValid = false; fieldWrap.classList.add('modal__field--invalid'); } else fieldWrap.classList.remove('modal__field--invalid'); data[field.name] = value; });
    if (currentAction.withStudentSelector && selectedStudentIds.size === 0) { isValid = false; selectedCountEl.style.color = 'var(--pink)'; selectedCountEl.textContent = 'Sélectionnez au moins un élève.'; } else selectedCountEl.style.color = '';
    if (!isValid) return;

    const originalLabel = submitBtn.textContent;
    submitBtn.disabled = true; submitBtn.textContent = 'Enregistrement...';
    try {
      const result = await currentAction.onSubmit(data, Array.from(selectedStudentIds));
      if (result && result.success) {
        showToast(result.message || '✅ Opération réussie.');
        closeModal();
        if (currentAction.reload !== false) setTimeout(() => window.location.reload(), 800);
      } else {
        showToast((result && result.message) || "❌ Une erreur est survenue.", 'error');
      }
    } catch (err) {
      if (err && err.kind === 'invalid-json') {
        // L'opération a très probablement réussi côté serveur (l'INSERT a eu lieu),
        // mais la réponse PHP contenait du texte parasite avant/après le JSON
        // (warning, notice, BOM, espace...). On le signale clairement au lieu
        // d'afficher "erreur réseau", qui est trompeur ici.
        showToast('⚠️ Opération probablement effectuée, mais réponse serveur invalide. Vérifiez la liste puis contactez le support si besoin.', 'error');
        if (currentAction.reload !== false) setTimeout(() => window.location.reload(), 1200);
      } else {
        showToast('❌ Erreur réseau, veuillez réessayer.', 'error');
      }
    } finally {
      submitBtn.disabled = false; submitBtn.textContent = originalLabel;
    }
  });
}

function showToast(message, type = 'success') {
  const container = document.getElementById('toastContainer'); if (!container) return;
  const toast = document.createElement('div'); toast.className = `toast${type === 'error' ? ' toast--error' : ''}`; toast.textContent = message; container.appendChild(toast);
  setTimeout(() => { toast.classList.add('is-leaving'); setTimeout(() => toast.remove(), 200); }, 3500);
}
</script>
</body>
</html>