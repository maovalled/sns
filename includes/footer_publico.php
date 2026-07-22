<footer class="footer-sns py-4 mt-5" id="contacto-footer">
  <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
    <div class="d-flex align-items-center gap-2">
      <span class="estrellas">✦ ☾ ✦</span>
      <span class="fw-semibold">Sailor Nails Spa</span>
    </div>
    <small><?= e(config('direccion')) ?> · <?= e(config('telefono')) ?></small>
    <small><a href="admin/login.php" class="text-decoration-none">Administración</a></small>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(rutaApp()) ?>/assets/js/telefono.js"></script>
</body>
</html>
