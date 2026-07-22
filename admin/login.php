<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if (usuarioActual()) { header('Location: index.php'); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave   = $_POST['clave'] ?? '';
    $captcha = strtoupper(trim($_POST['captcha'] ?? ''));

    if ($captcha === '' || $captcha !== ($_SESSION['captcha'] ?? '__')) {
        $error = 'El código captcha no coincide.';
    } else {
        $st = db()->prepare('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1');
        $st->execute([$usuario]);
        $u = $st->fetch();
        if ($u && password_verify($clave, $u['clave_hash'])) {
            session_regenerate_id(true);
            $_SESSION['usuario'] = ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
            header('Location: index.php');
            exit;
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
    unset($_SESSION['captcha']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administración · Sailor Nails Spa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="../assets/css/estilo.css" rel="stylesheet">
</head>
<body class="bg-crema d-flex align-items-center justify-content-center min-vh-100 p-3">
  <div class="card login-card w-100 shadow-sm">
    <div class="text-center py-4" style="background:var(--sns-crema)">
      <img src="../assets/img/logo.jpg" alt="Sailor Nails Spa" width="84" height="84" class="rounded-circle border border-3" style="border-color:var(--sns-rosa-claro)!important;object-fit:cover">
      <h1 class="h4 text-rosa fw-bold mt-2 mb-0">Sailor Nails Spa</h1>
      <small class="text-muted d-block">Panel de administración</small>
      <small class="text-muted" style="font-size:.75rem;opacity:.75">v<?= e(APP_VERSION) ?></small>
    </div>
    <form method="post" class="p-4 d-flex flex-column gap-3 bg-white">
      <?= csrfField() ?>
      <?php if ($error): ?><div class="alert alert-danger py-2 mb-0"><?= e($error) ?></div><?php endif; ?>
      <div>
        <label class="form-label" for="usuario">Usuario</label>
        <input type="text" class="form-control" id="usuario" name="usuario" required autofocus>
      </div>
      <div>
        <label class="form-label" for="clave">Contraseña</label>
        <input type="password" class="form-control" id="clave" name="clave" required>
      </div>
      <div>
        <label class="form-label" for="captcha">Captcha</label>
        <div class="d-flex gap-2 align-items-center">
          <img src="captcha.php?<?= time() ?>" alt="captcha" id="imgCaptcha" class="rounded border" style="border-color:var(--sns-rosa-claro)!important">
          <button type="button" class="btn btn-sns-outline btn-sm" title="Otro código"
                  onclick="document.getElementById('imgCaptcha').src='captcha.php?'+Date.now()">↻</button>
        </div>
        <input type="text" class="form-control mt-2" id="captcha" name="captcha" placeholder="Escribe el código" required autocomplete="off">
      </div>
      <button type="submit" class="btn btn-sns">Entrar ✦</button>
      <small class="text-muted text-center">¿Olvidaste tu clave? Pídele al admin que la restablezca.</small>
      <a href="../index.php" class="text-center small">← Volver al sitio</a>
    </form>
  </div>
</body>
</html>
