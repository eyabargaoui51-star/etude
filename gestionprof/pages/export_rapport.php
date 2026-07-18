<?php
/* ==========================================================================
   SmartTeacher — Export PDF du rapport Dashboard
   Génère un rapport PDF professionnel (FPDF) à partir des données réelles
   de la base, en utilisant exclusivement des Prepared Statements.
   ========================================================================== */

// Les avertissements PHP ne doivent jamais s'afficher avant l'envoi du PDF
// (ça corromprait le fichier). Ils partent dans les logs à la place.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once("../config/auth.php");
require_once("../config/database.php");
require_once(__DIR__ . "/lib/fpd/fpdf.php");

if (!$conn->ping()) {
    die("Connexion MySQL perdue.");
}

/* --------------------------------------------------------------------
   Palette de couleurs reprise du Dashboard (variables CSS :root)
   -------------------------------------------------------------------- */
const COLOR_PRIMARY      = [108, 92, 231];   // --primary  #6c5ce7
const COLOR_PRIMARY_DARK = [87, 75, 206];    // --primary-dark #574bce
const COLOR_BLUE         = [47, 128, 237];   // --blue     #2f80ed
const COLOR_GREEN        = [39, 174, 96];    // --green    #27ae60
const COLOR_ORANGE       = [242, 153, 74];   // --orange   #f2994a
const COLOR_PINK         = [235, 87, 87];    // --pink     #eb5757
const COLOR_TEXT         = [28, 24, 50];     // --text     #1c1832
const COLOR_MUTED        = [124, 120, 146];  // --muted    #7c7892
const COLOR_BORDER       = [235, 232, 251];  // --border   #ebe8fb
const COLOR_ROW_ALT      = [247, 247, 255];  // --bg       #f7f7ff

/* --------------------------------------------------------------------
   Fonctions utilitaires de requêtage (Prepared Statements uniquement)
   -------------------------------------------------------------------- */
function pdf_fetch_count(mysqli $conn, string $sql): int {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("Erreur de préparation SQL (pdf_fetch_count) : " . mysqli_error($conn));
        return 0;
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return (int)($row['total'] ?? 0);
}

/**
 * Exécute une requête préparée (sans paramètre variable) et retourne
 * toutes les lignes sous forme de tableau associatif.
 */
function pdf_fetch_all(mysqli $conn, string $sql): array {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("Erreur de préparation SQL (pdf_fetch_all) : " . mysqli_error($conn));
        return [];
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

/**
 * Exécute une requête préparée AVEC paramètres liés et retourne toutes
 * les lignes. $types est la chaîne de type mysqli ("i", "s", "is", ...)
 * et $params la liste des valeurs à lier, dans l'ordre des "?".
 */
function pdf_fetch_all_params(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("Erreur de préparation SQL (pdf_fetch_all_params) : " . mysqli_error($conn));
        return [];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
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

/** Libellé d'affichage convivial pour un statut de paiement brut en base */
function pdf_statut_paiement_libelle(string $statutBrut): string {
    switch ($statutBrut) {
        case 'Payé':      return 'Payé';
        case 'En cours':  return 'Partiel';
        case 'En attente':
        default:          return 'Non payé';
    }
}

/* --------------------------------------------------------------------
   0) Lecture des filtres envoyés par la modale "Exporter un rapport"
      (type, dateDebut, dateFin). Si aucun filtre valide n'est fourni
      (ex: accès direct via le lien du haut du dashboard), on repasse
      sur le rapport complet, comme avant — donc rien ne casse pour
      les usages existants.
   -------------------------------------------------------------------- */
$typesValides = ['Présences', 'Paiements', 'Notes', 'Groupes'];
$typeDemande  = $_GET['type'] ?? '';
$typeDemande  = in_array($typeDemande, $typesValides, true) ? $typeDemande : null;

function pdf_date_valide(string $d): bool {
    if ($d === '') return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

$dateDebutBrute = $_GET['dateDebut'] ?? '';
$dateFinBrute   = $_GET['dateFin'] ?? '';
$filtreDatesActif = pdf_date_valide($dateDebutBrute)
    && pdf_date_valide($dateFinBrute)
    && $dateDebutBrute <= $dateFinBrute;

$dateDebut = $filtreDatesActif ? $dateDebutBrute : null;
$dateFin   = $filtreDatesActif ? $dateFinBrute   : null;

/* --------------------------------------------------------------------
   1) Statistiques globales (compteurs) — toujours affichées en haut,
      quel que soit le type de rapport choisi (vue d'ensemble).
   -------------------------------------------------------------------- */
$total_eleves            = pdf_fetch_count($conn, "SELECT COUNT(*) AS total FROM eleve");
$total_groupes           = pdf_fetch_count($conn, "SELECT COUNT(*) AS total FROM groupe");
$total_seances           = pdf_fetch_count($conn, "SELECT COUNT(*) AS total FROM seance");
$total_paiements         = pdf_fetch_count($conn, "SELECT COUNT(*) AS total FROM paiement");
$total_paiements_attente = pdf_fetch_count($conn, "SELECT COUNT(*) AS total FROM paiement WHERE statut = 'En attente'");
$total_absences          = pdf_fetch_count($conn, "SELECT COUNT(*) AS total FROM presence WHERE statut = 'Absent'");

/* --------------------------------------------------------------------
   2) Tableau des groupes (avec filière, capacité, nb d'élèves)
      Affiché si : aucun type demandé (rapport complet) OU type = "Groupes"
   -------------------------------------------------------------------- */
$groupes = [];
if ($typeDemande === null || $typeDemande === 'Groupes') {
    $sql_groupes = "SELECT g.nom_groupe,
                           f.nom_filiere,
                           g.capacite,
                           (SELECT COUNT(*) FROM eleve e WHERE e.id_groupe = g.id_groupe) AS nb_eleves
                    FROM groupe g
                    INNER JOIN filiere f ON g.id_filiere = f.id_filiere
                    ORDER BY g.nom_groupe ASC";
    $groupes = pdf_fetch_all($conn, $sql_groupes);
}

/* --------------------------------------------------------------------
   3) Tableau des paiements
      Affiché si : aucun type demandé (rapport complet, 12 derniers,
      non filtré) OU type = "Paiements" (tous les paiements de la
      période choisie, filtrés sur la date de paiement réelle).
   -------------------------------------------------------------------- */
$derniers_paiements = [];
if ($typeDemande === null) {
    $sql_paiements = "SELECT p.id_paiement, e.nom, e.prenom, g.nom_groupe,
                              p.montant_a_payer, p.statut,
                              COALESCE(SUM(v.montant), 0) AS montant_paye
                       FROM paiement p
                       INNER JOIN eleve e ON p.id_eleve = e.id_eleve
                       INNER JOIN groupe g ON e.id_groupe = g.id_groupe
                       LEFT JOIN versement v ON v.id_paiement = p.id_paiement
                       GROUP BY p.id_paiement, e.nom, e.prenom, g.nom_groupe,
                                p.montant_a_payer, p.statut
                       ORDER BY p.id_paiement DESC
                       LIMIT ?";
    $derniers_paiements = pdf_fetch_all_params($conn, $sql_paiements, "i", [12]);
} elseif ($typeDemande === 'Paiements') {
    if ($filtreDatesActif) {
        $sql_paiements = "SELECT p.id_paiement, e.nom, e.prenom, g.nom_groupe,
                                  p.montant_a_payer, p.statut,
                                  COALESCE(SUM(v.montant), 0) AS montant_paye
                           FROM paiement p
                           INNER JOIN eleve e ON p.id_eleve = e.id_eleve
                           INNER JOIN groupe g ON e.id_groupe = g.id_groupe
                           LEFT JOIN versement v ON v.id_paiement = p.id_paiement
                           WHERE p.date_paiement IS NOT NULL
                             AND DATE(p.date_paiement) BETWEEN ? AND ?
                           GROUP BY p.id_paiement, e.nom, e.prenom, g.nom_groupe,
                                    p.montant_a_payer, p.statut
                           ORDER BY p.date_paiement DESC";
        $derniers_paiements = pdf_fetch_all_params($conn, $sql_paiements, "ss", [$dateDebut, $dateFin]);
    } else {
        // Type demandé mais dates invalides/absentes : on retombe sur tous
        // les paiements (non filtrés par date) plutôt que de planter.
        $sql_paiements = "SELECT p.id_paiement, e.nom, e.prenom, g.nom_groupe,
                                  p.montant_a_payer, p.statut,
                                  COALESCE(SUM(v.montant), 0) AS montant_paye
                           FROM paiement p
                           INNER JOIN eleve e ON p.id_eleve = e.id_eleve
                           INNER JOIN groupe g ON e.id_groupe = g.id_groupe
                           LEFT JOIN versement v ON v.id_paiement = p.id_paiement
                           GROUP BY p.id_paiement, e.nom, e.prenom, g.nom_groupe,
                                    p.montant_a_payer, p.statut
                           ORDER BY p.id_paiement DESC";
        $derniers_paiements = pdf_fetch_all($conn, $sql_paiements);
    }
}

/* --------------------------------------------------------------------
   4) Tableau des séances (rapport complet uniquement — remplacé par
      le tableau de présences détaillé quand type = "Présences")
   -------------------------------------------------------------------- */
$dernieres_seances = [];
if ($typeDemande === null) {
    $sql_seances = "SELECT s.date_seance, s.heure_debut, s.heure_fin, g.nom_groupe, s.chapitre, s.statut
                     FROM seance s
                     INNER JOIN groupe g ON s.id_groupe = g.id_groupe
                     ORDER BY s.date_seance DESC, s.heure_debut DESC
                     LIMIT ?";
    $dernieres_seances = pdf_fetch_all_params($conn, $sql_seances, "i", [12]);
}

/* --------------------------------------------------------------------
   4bis) Tableau de présences détaillé (élève par élève, séance par
   séance) — n'existait pas avant. Affiché uniquement pour type =
   "Présences", filtré sur la période choisie si elle est valide.
   -------------------------------------------------------------------- */
$presences_detail = [];
if ($typeDemande === 'Présences') {
    $sql_presences = "SELECT s.date_seance, s.heure_debut, s.heure_fin,
                              g.nom_groupe, e.nom, e.prenom, pr.statut
                       FROM presence pr
                       INNER JOIN seance s ON pr.id_seance = s.id_seance
                       INNER JOIN eleve e ON pr.id_eleve = e.id_eleve
                       INNER JOIN groupe g ON s.id_groupe = g.id_groupe";
    if ($filtreDatesActif) {
        $sql_presences .= " WHERE s.date_seance BETWEEN ? AND ?
                             ORDER BY s.date_seance DESC, s.heure_debut DESC";
        $presences_detail = pdf_fetch_all_params($conn, $sql_presences, "ss", [$dateDebut, $dateFin]);
    } else {
        $sql_presences .= " ORDER BY s.date_seance DESC, s.heure_debut DESC LIMIT 50";
        $presences_detail = pdf_fetch_all($conn, $sql_presences);
    }
}

/* --------------------------------------------------------------------
   5) Tableau des élèves en attente de paiement
      (état actuel, pas lié à une plage de dates — affiché pour le
      rapport complet et pour le type "Paiements")
   -------------------------------------------------------------------- */
$eleves_attente = [];
if ($typeDemande === null || $typeDemande === 'Paiements') {
    $sql_attente = "SELECT p.id_paiement, e.nom, e.prenom, g.nom_groupe,
                            p.montant_a_payer,
                            COALESCE(SUM(v.montant), 0) AS montant_paye
                     FROM paiement p
                     INNER JOIN eleve e ON p.id_eleve = e.id_eleve
                     INNER JOIN groupe g ON e.id_groupe = g.id_groupe
                     LEFT JOIN versement v ON v.id_paiement = p.id_paiement
                     WHERE p.statut = 'En attente'
                     GROUP BY p.id_paiement, e.nom, e.prenom, g.nom_groupe, p.montant_a_payer
                     ORDER BY p.id_paiement DESC";
    $eleves_attente = pdf_fetch_all($conn, $sql_attente);
}

mysqli_close($conn);

/* ==========================================================================
   Classe PDF — mise en page SmartTeacher (logo, en-tête, pied de page,
   pagination, cartes de statistiques, tableaux zébrés)
   ========================================================================== */
class SmartTeacherPDF extends FPDF
{
    /** Convertit un texte UTF-8 (issu de la base) vers l'encodage attendu par FPDF */
    public function txt(string $s): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
        return $converted !== false ? $converted : $s;
    }

    /** Rectangle à coins arrondis (recette standard FPDF, domaine public) */
    public function RoundedRect(float $x, float $y, float $w, float $h, float $r, string $style = 'FD'): void
    {
        $k  = $this->k;
        $hp = $this->h;
        if ($style === 'F') {
            $op = 'f';
        } elseif ($style === 'FD' || $style === 'DF') {
            $op = 'B';
        } else {
            $op = 'S';
        }
        $myArc = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));

        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_arc($xc + $r * $myArc, $yc - $r, $xc + $r, $yc - $r * $myArc, $xc + $r, $yc);

        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_arc($xc + $r, $yc + $r * $myArc, $xc + $r * $myArc, $yc + $r, $xc, $yc + $r);

        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_arc($xc - $r * $myArc, $yc + $r, $xc - $r, $yc + $r * $myArc, $xc - $r, $yc);

        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_arc($xc - $r, $yc - $r * $myArc, $xc - $r * $myArc, $yc - $r, $xc, $yc - $r);

        $this->_out($op);
    }

    private function _arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c ',
            $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k,
            $x3 * $this->k, ($h - $y3) * $this->k
        ));
    }

    /** En-tête du logo SmartTeacher (dessiné en vectoriel, pas de fichier externe requis) */
    public function Header(): void
    {
        $logoPath = __DIR__ . '/assets/logo.png';

        if (file_exists($logoPath)) {
            $this->Image($logoPath, 10, 8, 20);
        } else {
            // Logo vectoriel de secours : pastille arrondie "ST" aux couleurs du dashboard
            $this->SetFillColor(...COLOR_PRIMARY);
            $this->RoundedRect(10, 8, 16, 16, 4, 'F');
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Helvetica', 'B', 12);
            $this->SetXY(10, 8);
            $this->Cell(16, 16, 'ST', 0, 0, 'C');
        }

        $this->SetXY(30, 9);
        $this->SetTextColor(...COLOR_TEXT);
        $this->SetFont('Helvetica', 'B', 15);
        $this->Cell(0, 7, $this->txt('SmartTeacher'), 0, 2, 'L');

        $this->SetXY(30, 16);
        $this->SetTextColor(...COLOR_MUTED);
        $this->SetFont('Helvetica', '', 9);
        $this->Cell(0, 6, $this->txt('Rapport de gestion pedagogique'), 0, 2, 'L');

        // Ligne de séparation sous l'en-tête
        $this->SetDrawColor(...COLOR_BORDER);
        $this->SetLineWidth(0.4);
        $this->Line(10, 27, $this->GetPageWidth() - 10, 27);
        $this->SetY(32);
    }

    /** Pied de page avec pagination */
    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetDrawColor(...COLOR_BORDER);
        $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...COLOR_MUTED);
        $this->Cell(0, 8, $this->txt('SmartTeacher - Rapport genere automatiquement'), 0, 0, 'L');
        $this->Cell(0, 8, 'Page ' . $this->PageNo() . ' / {nb}', 0, 0, 'R');
    }

    /** Titre de section (bande colorée avec libellé) */
    public function SectionTitle(string $label, array $color = COLOR_PRIMARY): void
    {
        if ($this->GetY() > $this->GetPageHeight() - $this->bMargin - 25) {
            $this->AddPage();
        }
        $this->Ln(3);
        $this->SetFillColor(...$color);
        $this->Rect(10, $this->GetY(), 1.2, 7, 'F');
        $this->SetXY(14, $this->GetY());
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetTextColor(...COLOR_TEXT);
        $this->Cell(0, 7, $this->txt($label), 0, 1, 'L');
        $this->Ln(2);
    }

    /** Une carte statistique colorée (façon "stat-card" du dashboard) */
    public function StatCard(float $x, float $y, float $w, string $label, string $value, array $color): void
    {
        $h = 22;
        $this->SetXY($x, $y);
        $this->SetFillColor(...COLOR_ROW_ALT);
        $this->SetDrawColor(...COLOR_BORDER);
        $this->RoundedRect($x, $y, $w, $h, 2.5, 'FD');

        // Pastille de couleur
        $this->SetFillColor(...$color);
        $this->RoundedRect($x + 4, $y + 4, 6, 6, 1.5, 'F');

        $this->SetXY($x + 4, $y + 11);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...COLOR_MUTED);
        $this->Cell($w - 8, 4, $this->txt($label), 0, 2, 'L');

        $this->SetX($x + 4);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetTextColor(...COLOR_TEXT);
        $this->Cell($w - 8, 6, $value, 0, 0, 'L');
    }

    /**
     * Tableau générique avec en-tête coloré, bordures et lignes zébrées.
     * $columns = [['label' => 'Nom', 'width' => 40, 'align' => 'L'], ...]
     * $rows    = tableau associatif de données (déjà formatées en string)
     */
    public function DataTable(array $columns, array $rows, string $emptyMessage = 'Aucune donnee disponible.'): void
    {
        $lineH = 7;

        $printHeader = function () use ($columns, $lineH) {
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->SetFillColor(...COLOR_PRIMARY);
            $this->SetTextColor(255, 255, 255);
            $this->SetDrawColor(...COLOR_BORDER);
            foreach ($columns as $col) {
                $this->Cell($col['width'], $lineH, $this->txt($col['label']), 1, 0, $col['align'] ?? 'L', true);
            }
            $this->Ln();
        };

        $printHeader();

        if (empty($rows)) {
            $this->SetFont('Helvetica', 'I', 9);
            $this->SetTextColor(...COLOR_MUTED);
            $totalWidth = array_sum(array_column($columns, 'width'));
            $this->Cell($totalWidth, $lineH, $this->txt($emptyMessage), 1, 1, 'C');
            $this->Ln(2);
            return;
        }

        $this->SetFont('Helvetica', '', 8.5);
        $zebra = false;
        foreach ($rows as $row) {
            if ($this->GetY() + $lineH > $this->GetPageHeight() - $this->bMargin) {
                $this->AddPage();
                $printHeader();
            }
            $this->SetTextColor(...COLOR_TEXT);
            $this->SetFillColor(...($zebra ? COLOR_ROW_ALT : [255, 255, 255]));
            foreach ($columns as $col) {
                $value = $row[$col['key']] ?? '';
                $this->Cell($col['width'], $lineH, $this->txt((string)$value), 1, 0, $col['align'] ?? 'L', true);
            }
            $this->Ln();
            $zebra = !$zebra;
        }
        $this->Ln(2);
    }
}

/* ==========================================================================
   Construction du document PDF
   ========================================================================== */
$pdf = new SmartTeacherPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 32, 10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

/* --- Titre du rapport + date de génération ------------------------------ */
$pdf->SetFont('Helvetica', 'B', 16);
$pdf->SetTextColor(...COLOR_TEXT);
$pdf->Cell(0, 9, $pdf->txt('Rapport du Dashboard SmartTeacher'), 0, 1, 'L');

$pdf->SetFont('Helvetica', '', 9.5);
$pdf->SetTextColor(...COLOR_MUTED);
$dateGeneration = date('d/m/Y \\à H:i:s');
$pdf->Cell(0, 6, $pdf->txt('Genere le ' . $dateGeneration), 0, 1, 'L');

if ($typeDemande !== null) {
    $sousTitre = 'Type de rapport : ' . $typeDemande;
    if ($filtreDatesActif) {
        $sousTitre .= ' | Periode : du ' . date('d/m/Y', strtotime($dateDebut))
                    . ' au ' . date('d/m/Y', strtotime($dateFin));
    }
    $pdf->Cell(0, 6, $pdf->txt($sousTitre), 0, 1, 'L');
}
$pdf->Ln(3);

/* --- Cartes de statistiques (grille 3 x 2) ------------------------------- */
$pdf->SectionTitle('Statistiques generales');

$cardY   = $pdf->GetY();
$cardGap = 4;
$cardW   = (190 - 2 * $cardGap) / 3;

$stats = [
    ['label' => 'Total eleves',            'value' => (string)$total_eleves,            'color' => COLOR_PRIMARY],
    ['label' => 'Total groupes',           'value' => (string)$total_groupes,           'color' => COLOR_BLUE],
    ['label' => 'Total seances',           'value' => (string)$total_seances,           'color' => COLOR_GREEN],
    ['label' => 'Total paiements',         'value' => (string)$total_paiements,         'color' => COLOR_ORANGE],
    ['label' => 'Paiements en attente',    'value' => (string)$total_paiements_attente, 'color' => COLOR_PINK],
    ['label' => 'Total absences',          'value' => (string)$total_absences,          'color' => COLOR_PRIMARY_DARK],
];

foreach ($stats as $i => $stat) {
    $col = $i % 3;
    $row = intdiv($i, 3);
    $x = 10 + $col * ($cardW + $cardGap);
    $y = $cardY + $row * (22 + $cardGap);
    $pdf->StatCard($x, $y, $cardW, $stat['label'], $stat['value'], $stat['color']);
}
$pdf->SetY($cardY + 2 * (22 + $cardGap));

/* --- Tableau des groupes -------------------------------------------------- */
if ($typeDemande === null || $typeDemande === 'Groupes') {
    $pdf->SectionTitle('Tableau des groupes', COLOR_BLUE);
    $rowsGroupes = array_map(static function (array $g): array {
        return [
            'nom_groupe'  => $g['nom_groupe'],
            'nom_filiere' => $g['nom_filiere'],
            'capacite'    => $g['capacite'] !== null ? (string)$g['capacite'] : '-',
            'nb_eleves'   => (string)$g['nb_eleves'],
        ];
    }, $groupes);
    $pdf->DataTable(
        [
            ['key' => 'nom_groupe',  'label' => 'Groupe',       'width' => 55, 'align' => 'L'],
            ['key' => 'nom_filiere', 'label' => 'Filiere',      'width' => 65, 'align' => 'L'],
            ['key' => 'capacite',    'label' => 'Capacite',     'width' => 35, 'align' => 'C'],
            ['key' => 'nb_eleves',   'label' => 'Nb. eleves',   'width' => 35, 'align' => 'C'],
        ],
        $rowsGroupes,
        'Aucun groupe enregistre.'
    );
}

/* --- Tableau des paiements ---------------------------------------- */
if ($typeDemande === null || $typeDemande === 'Paiements') {
    $pdf->SectionTitle($typeDemande === null ? 'Derniers paiements' : 'Paiements de la periode', COLOR_ORANGE);
    $rowsPaiements = array_map(static function (array $p): array {
        return [
            'eleve'        => $p['prenom'] . ' ' . $p['nom'],
            'groupe'       => $p['nom_groupe'],
            'montant_du'   => number_format((float)$p['montant_a_payer'], 2, ',', ' ') . ' DT',
            'montant_paye' => number_format((float)$p['montant_paye'], 2, ',', ' ') . ' DT',
            'statut'       => pdf_statut_paiement_libelle($p['statut']),
        ];
    }, $derniers_paiements);
    $pdf->DataTable(
        [
            ['key' => 'eleve',        'label' => 'Eleve',         'width' => 50, 'align' => 'L'],
            ['key' => 'groupe',       'label' => 'Groupe',        'width' => 30, 'align' => 'L'],
            ['key' => 'montant_du',   'label' => 'Montant du',    'width' => 35, 'align' => 'R'],
            ['key' => 'montant_paye', 'label' => 'Montant paye',  'width' => 35, 'align' => 'R'],
            ['key' => 'statut',       'label' => 'Statut',        'width' => 40, 'align' => 'C'],
        ],
        $rowsPaiements,
        'Aucun paiement enregistre pour cette periode.'
    );
}

/* --- Tableau des dernières séances (rapport complet uniquement) ------------ */
if ($typeDemande === null) {
    $pdf->SectionTitle('Dernieres seances', COLOR_GREEN);
    $rowsSeances = array_map(static function (array $s): array {
        return [
            'date'      => date('d/m/Y', strtotime($s['date_seance'])),
            'horaire'   => substr($s['heure_debut'], 0, 5) . ' - ' . substr($s['heure_fin'], 0, 5),
            'groupe'    => $s['nom_groupe'],
            'chapitre'  => $s['chapitre'] ?: '-',
            'statut'    => $s['statut'],
        ];
    }, $dernieres_seances);
    $pdf->DataTable(
        [
            ['key' => 'date',     'label' => 'Date',     'width' => 25, 'align' => 'C'],
            ['key' => 'horaire',  'label' => 'Horaire',  'width' => 30, 'align' => 'C'],
            ['key' => 'groupe',   'label' => 'Groupe',   'width' => 30, 'align' => 'L'],
            ['key' => 'chapitre', 'label' => 'Chapitre', 'width' => 65, 'align' => 'L'],
            ['key' => 'statut',   'label' => 'Statut',   'width' => 40, 'align' => 'C'],
        ],
        $rowsSeances,
        'Aucune seance enregistree.'
    );
}

/* --- Tableau des présences (type = "Présences") ----------------------------- */
if ($typeDemande === 'Présences') {
    $pdf->SectionTitle('Presences de la periode', COLOR_GREEN);
    $rowsPresences = array_map(static function (array $p): array {
        return [
            'date'     => date('d/m/Y', strtotime($p['date_seance'])),
            'horaire'  => substr($p['heure_debut'], 0, 5) . ' - ' . substr($p['heure_fin'], 0, 5),
            'groupe'   => $p['nom_groupe'],
            'eleve'    => $p['prenom'] . ' ' . $p['nom'],
            'statut'   => $p['statut'],
        ];
    }, $presences_detail);
    $pdf->DataTable(
        [
            ['key' => 'date',    'label' => 'Date',    'width' => 25, 'align' => 'C'],
            ['key' => 'horaire', 'label' => 'Horaire', 'width' => 30, 'align' => 'C'],
            ['key' => 'groupe',  'label' => 'Groupe',  'width' => 30, 'align' => 'L'],
            ['key' => 'eleve',   'label' => 'Eleve',   'width' => 55, 'align' => 'L'],
            ['key' => 'statut',  'label' => 'Statut',  'width' => 30, 'align' => 'C'],
        ],
        $rowsPresences,
        'Aucune presence enregistree pour cette periode.'
    );
}

/* --- Type "Notes" : fonctionnalité non implémentée dans l'application ------
   Il n'existe aucune table de notes/évaluations dans la base actuelle.
   On le dit clairement dans le PDF plutôt que de générer un rapport vide
   ou trompeur qui laisserait croire que la donnée existe. ------------------ */
if ($typeDemande === 'Notes') {
    $pdf->SectionTitle('Notes', COLOR_PINK);
    $pdf->SetFont('Helvetica', 'I', 10);
    $pdf->SetTextColor(...COLOR_MUTED);
    $pdf->MultiCell(0, 6, $pdf->txt(
        "La gestion des notes n'est pas encore disponible dans SmartTeacher. " .
        "Cette section sera activee des que la fonctionnalite sera developpee."
    ));
    $pdf->Ln(2);
}

/* --- Tableau des élèves en attente de paiement ------------------------------ */
if ($typeDemande === null || $typeDemande === 'Paiements') {
    $pdf->SectionTitle('Eleves en attente de paiement', COLOR_PINK);
    $rowsAttente = array_map(static function (array $a): array {
        $reste = (float)$a['montant_a_payer'] - (float)$a['montant_paye'];
        return [
            'eleve'   => $a['prenom'] . ' ' . $a['nom'],
            'groupe'  => $a['nom_groupe'],
            'du'      => number_format((float)$a['montant_a_payer'], 2, ',', ' ') . ' DT',
            'paye'    => number_format((float)$a['montant_paye'], 2, ',', ' ') . ' DT',
            'reste'   => number_format($reste, 2, ',', ' ') . ' DT',
        ];
    }, $eleves_attente);
    $pdf->DataTable(
        [
            ['key' => 'eleve',  'label' => 'Eleve',           'width' => 50, 'align' => 'L'],
            ['key' => 'groupe', 'label' => 'Groupe',          'width' => 30, 'align' => 'L'],
            ['key' => 'du',     'label' => 'Montant du',      'width' => 35, 'align' => 'R'],
            ['key' => 'paye',   'label' => 'Montant paye',    'width' => 35, 'align' => 'R'],
            ['key' => 'reste',  'label' => 'Reste a payer',   'width' => 40, 'align' => 'R'],
        ],
        $rowsAttente,
        'Aucun eleve en attente de paiement.'
    );
}

/* --------------------------------------------------------------------
   Téléchargement automatique du PDF
   -------------------------------------------------------------------- */
$filenameBase = 'rapport_smartteacher';
if ($typeDemande !== null) {
    $filenameBase .= '_' . strtolower(str_replace(
        ['é', 'è', ' '], ['e', 'e', '_'], $typeDemande
    ));
    if ($filtreDatesActif) {
        $filenameBase .= '_' . $dateDebut . '_au_' . $dateFin;
    }
}
$filename = $filenameBase . '_' . date('Y-m-d_His') . '.pdf';
$pdf->Output('D', $filename);
exit;