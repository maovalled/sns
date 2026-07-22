<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/imagen.php';
$u = requerirLogin(['admin', 'recepcion']);
$activo = 'galeria';
$tituloAdmin = 'Galería de trabajos';
$pdo = db();
$mensaje = null;
$error = null;

$dirUploads = __DIR__ . '/../uploads/galeria';
if (!is_dir($dirUploads)) mkdir($dirUploads, 0775, true);

$dirOriginales = dirOriginalesGaleria();   // originales sin marca, bloqueados por .htaccess

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['foto'])) {
        [$archivo, $motivo] = guardarFotoGaleria($_FILES['foto']);
        if (!$archivo) {
            $error = $motivo;
        } else {
            $st = $pdo->prepare('INSERT INTO galeria (archivo, titulo) VALUES (?,?)');
            $st->execute([$archivo, trim($_POST['titulo'] ?? '') ?: null]);
            $mensaje = marcaAguaActiva()
                ? 'Foto subida con la marca de agua ✦'
                : 'Foto subida (la marca de agua está desactivada en Paramétricas).';
        }
    } elseif (isset($_POST['regenerar'])) {
        // Vuelve a estampar la marca sobre los originales guardados. Sirve al
        // cambiar la opacidad o el tamaño, sin tener que subir las fotos de nuevo.
        $rehechas = 0; $sinOriginal = 0; $fallidas = 0;
        foreach ($pdo->query('SELECT archivo FROM galeria') as $g) {
            $orig = $dirOriginales . '/' . $g['archivo'];
            if (!is_file($orig)) { $sinOriginal++; continue; }
            $mime = @mime_content_type($orig) ?: 'image/jpeg';
            [$ok] = procesarFotoGaleria($orig, $dirUploads . '/' . $g['archivo'], $mime);
            $ok ? $rehechas++ : $fallidas++;
        }
        $mensaje = "Marcas regeneradas en $rehechas foto(s)."
                 . ($sinOriginal ? " $sinOriginal sin original guardado (se subieron antes): quedan como estaban." : '')
                 . ($fallidas ? " $fallidas no se pudieron procesar." : '');
    } elseif (isset($_POST['eliminar_id'])) {
        $st = $pdo->prepare('SELECT archivo FROM galeria WHERE id = ?');
        $st->execute([(int)$_POST['eliminar_id']]);
        if ($f = $st->fetchColumn()) {
            @unlink($dirUploads . '/' . $f);
            @unlink($dirOriginales . '/' . $f);   // también su original sin marca
            $pdo->prepare('DELETE FROM galeria WHERE id = ?')->execute([(int)$_POST['eliminar_id']]);
            $mensaje = 'Foto eliminada.';
        }
    }
}

$fotos = $pdo->query('SELECT * FROM galeria ORDER BY subido_en DESC')->fetchAll();
require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card card-servicio p-3 mb-4">
  <h5 class="text-rosa">Subir foto de trabajo</h5>
  <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
    <?= csrfField() ?>
    <div class="col-md-4"><label class="form-label mb-1">Imagen (JPG/PNG/WebP, máx 5 MB)</label><input type="file" name="foto" class="form-control" accept="image/jpeg,image/png,image/webp" required></div>
    <div class="col-md-4"><label class="form-label mb-1">Título (opcional)</label><input name="titulo" class="form-control" placeholder="Acrílicas galaxia 🌙"></div>
    <div class="col-md-2"><button class="btn btn-sns w-100">Subir ✦</button></div>
  </form>
  <?php $conOriginal = count(glob($dirOriginales . '/*.{jpg,png,webp}', GLOB_BRACE) ?: []); ?>
  <?php if ($conOriginal): ?>
  <hr class="my-3">
  <form method="post" class="d-flex align-items-center gap-2 flex-wrap"
        onsubmit="return confirm('¿Volver a estampar la marca en <?= $conOriginal ?> foto(s)?')">
    <?= csrfField() ?>
    <input type="hidden" name="regenerar" value="1">
    <button class="btn btn-sns-outline btn-sm">🎨 Regenerar marcas de agua</button>
    <small class="text-muted">
      Rehace la marca sobre las fotos originales con la opacidad y el tamaño actuales
      (<?= $conOriginal ?> foto<?= $conOriginal === 1 ? '' : 's' ?> con original guardado).
      Úsalo después de cambiar esos valores en Paramétricas.
    </small>
  </form>
  <?php endif; ?>
</div>

<div class="row g-3">
  <?php if (!$fotos): ?><p class="text-muted">Aún no hay fotos en la galería.</p><?php endif; ?>
  <?php foreach ($fotos as $f): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card card-servicio overflow-hidden">
      <img src="../uploads/galeria/<?= e($f['archivo']) ?>" alt="<?= e($f['titulo'] ?: 'Trabajo') ?>" style="aspect-ratio:1;object-fit:cover;width:100%">
      <div class="card-body p-2 d-flex justify-content-between align-items-center">
        <small><?= e($f['titulo'] ?? '') ?></small>
        <form method="post" onsubmit="return confirm('¿Eliminar esta foto?')">
          <?= csrfField() ?>
          <input type="hidden" name="eliminar_id" value="<?= (int)$f['id'] ?>">
          <button class="btn btn-outline-danger btn-sm">🗑</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
