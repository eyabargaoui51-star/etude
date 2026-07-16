<?php
/* ============================================================
   login.php — Connexion SmartTeacher
   ============================================================ */
require_once("../config/database.php");

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Déjà connecté : direction le dashboard.
if (!empty($_SESSION['id_utilisateur'])) {
    header('Location: dashboard.php');
    exit;
}

$erreur  = '';
$expired = isset($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email       = trim($_POST['email'] ?? '');
    $motDePasse  = $_POST['mot_de_passe'] ?? '';

    if ($email === '' || $motDePasse === '') {
        $erreur = "Veuillez remplir tous les champs.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = "Adresse email invalide.";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id_utilisateur, nom, mot_de_passe FROM utilisateur WHERE email = ? LIMIT 1");
        if (!$stmt) {
            error_log("Erreur de préparation (login) : " . mysqli_error($conn));
            $erreur = "Une erreur est survenue. Veuillez réessayer plus tard.";
        } else {
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $utilisateur = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);

            if ($utilisateur && password_verify($motDePasse, $utilisateur['mot_de_passe'])) {
                // Empêche la fixation de session : on régénère l'ID après connexion.
                session_regenerate_id(true);
                $_SESSION['id_utilisateur']  = $utilisateur['id_utilisateur'];
                $_SESSION['nom_utilisateur'] = $utilisateur['nom'];
                $_SESSION['last_activity']   = time();
                header('Location: dashboard.php');
                exit;
            } else {
                // Message volontairement générique : ne pas révéler si c'est
                // l'email ou le mot de passe qui est incorrect.
                $erreur = "Email ou mot de passe incorrect.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SmartTeacher — Connexion</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #6c5ce7;
      --primary-dark: #574bce;
      --bg: #f7f7ff;
      --card: #ffffff;
      --text: #1c1832;
      --muted: #7c7892;
      --border: #ebe8fb;
      --pink: #eb5757;
      --shadow: 0 18px 45px rgba(35, 31, 74, .10);
      --radius: 22px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: var(--bg);
      color: var(--text);
      padding: 20px;
    }
    .login-card {
      width: 100%;
      max-width: 380px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 36px 30px;
    }
    .login-card__brand {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 20px;
      font-weight: 700;
      margin-bottom: 4px;
    }
    .login-card__brand strong { color: var(--primary); }
    .login-card__subtitle { color: var(--muted); font-size: 14px; margin: 0 0 24px; }
    .login-field { margin-bottom: 16px; }
    .login-field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
    .login-field input {
      width: 100%;
      padding: 11px 14px;
      border: 1px solid var(--border);
      border-radius: 12px;
      font-family: inherit;
      font-size: 14px;
      outline: none;
      transition: border-color .15s ease;
    }
    .login-field input:focus { border-color: var(--primary); }
    .login-submit {
      width: 100%;
      padding: 12px;
      border: none;
      border-radius: 12px;
      background: var(--primary);
      color: #fff;
      font-weight: 700;
      font-size: 14px;
      cursor: pointer;
      transition: background .15s ease;
    }
    .login-submit:hover { background: var(--primary-dark); }
    .login-alert {
      background: #fdeeee;
      border: 1px solid var(--pink);
      color: var(--pink);
      border-radius: 12px;
      padding: 10px 14px;
      font-size: 13px;
      margin-bottom: 18px;
    }
    .login-alert--info {
      background: #fff6ec;
      border-color: #f2994a;
      color: #92500f;
    }
  
    @media (max-width: 400px) {
      .login-card { padding: 26px 18px; }
    }
</style>
</head>
<body>
  <div class="login-card">
    <div class="login-card__brand"><span>🎓</span><span>Smart<strong>Teacher</strong></span></div>
    <p class="login-card__subtitle">Connectez-vous pour accéder à votre espace.</p>

    <?php if ($expired): ?>
      <div class="login-alert login-alert--info">⏱️ Votre session a expiré après 30 minutes d'inactivité. Veuillez vous reconnecter.</div>
    <?php endif; ?>

    <?php if ($erreur !== ''): ?>
      <div class="login-alert"><?php echo htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php" autocomplete="off">
      <div class="login-field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required
               value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
      </div>
      <div class="login-field">
        <label for="mot_de_passe">Mot de passe</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" required>
      </div>
      <button type="submit" class="login-submit">Se connecter</button>
    </form>
  </div>
</body>
</html>