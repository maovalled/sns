<?php
/* Layout superior admin · requiere $u (usuario), $activo (slug del menú), $tituloAdmin */
$menus = [
    'citas'          => ['Citas', 'index.php'],
    'clientes'       => ['Clientas', 'clientes.php'],
    'bloqueos'       => ['Bloqueos de agenda', 'bloqueos.php'],
    'dias_especiales'=> ['Días especiales', 'dias_especiales.php'],
    'promociones'    => ['Promociones', 'promociones.php'],
    'personal'     => ['Personal', 'personal.php'],
    'asistencia'   => ['Asistencia', 'asistencia.php'],
    'prestamos'    => ['Préstamos', 'prestamos.php'],
    'pagos'        => ['Pagos manicuristas', 'pagos.php'],
    'inventario'   => ['Inventario', 'inventario.php'],
    'galeria'      => ['Galería', 'galeria.php'],
    'parametricas' => ['Paramétricas', 'parametricas.php'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($tituloAdmin ?? 'Admin') ?> · Sailor Nails Spa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="../assets/css/estilo.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <aside class="col-12 col-md-3 col-lg-2 sidebar-sns p-3">
      <div class="d-flex align-items-center gap-2 mb-4">
        <img src="../assets/img/logo.jpg" alt="logo" width="40" height="40" class="rounded-circle" style="object-fit:cover">
        <div>
          <strong class="text-rosa d-block" style="line-height:1">Sailor Nails</strong>
          <small class="text-muted">Administración</small>
        </div>
      </div>
      <nav class="nav flex-column gap-1">
        <?php foreach ($menus as $slug => [$nombre, $url]): if (!puedeVer($slug)) continue; ?>
          <a class="nav-link px-3 py-2 <?= ($activo ?? '') === $slug ? 'active' : '' ?>" href="<?= e($url) ?>"><?= e($nombre) ?></a>
        <?php endforeach; ?>
      </nav>
      <hr>
      <div class="small px-2">
        👤 <strong><?= e($u['nombre']) ?></strong><br>
        <span class="badge text-bg-light border"><?= e($u['rol']) ?></span>
      </div>
      <a href="logout.php" class="btn btn-sns-outline btn-sm mt-3 w-100">Cerrar sesión</a>
    </aside>
    <main class="col-12 col-md-9 col-lg-10 p-4">
      <h1 class="h3 text-rosa fw-bold mb-4"><?= e($tituloAdmin ?? '') ?></h1>
