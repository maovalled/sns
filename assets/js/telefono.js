/*
 * Teléfono de clienta: exactamente 10 dígitos, sin indicativo.
 * Se aplica a cualquier input marcado con data-telefono-cliente, tanto en el
 * sitio público como en el panel. El servidor valida lo mismo por su cuenta
 * (errorTelefonoCliente en includes/funciones.php): esto es solo comodidad.
 *
 * No aplica al teléfono del personal, que no tiene esta restricción.
 */
(function () {
  var MAX = 10;

  function limpiar(el) {
    var antes = el.value;
    var soloDigitos = antes.replace(/\D+/g, '');
    // Con indicativo de Colombia (+57 y 12 dígitos) el número real son los 10
    // finales; recortar por la izquierda guardaría un teléfono equivocado.
    if (soloDigitos.length === 12 && soloDigitos.indexOf('57') === 0) soloDigitos = soloDigitos.slice(2);
    soloDigitos = soloDigitos.slice(0, MAX);
    if (soloDigitos === antes) return;
    // Conservar la posición del cursor al limpiar mientras se escribe
    var pos = el.selectionStart;
    var borradosAntesDelCursor = (antes.slice(0, pos).match(/\D/g) || []).length;
    el.value = soloDigitos;
    if (el.type !== 'tel' && el.type !== 'text') return;
    try { el.setSelectionRange(pos - borradosAntesDelCursor, pos - borradosAntesDelCursor); } catch (e) { /* no crítico */ }
  }

  // Mensaje propio: el genérico del navegador ("haz coincidir el formato
  // solicitado") no dice cuántos dígitos faltan.
  function avisar(el) {
    var n = el.value.length;
    if (n === 0 || n === MAX) { el.setCustomValidity(''); return; }
    el.setCustomValidity('El teléfono son ' + MAX + ' dígitos, sin indicativo. Llevas ' + n + '.');
  }

  function preparar(el) {
    el.setAttribute('inputmode', 'numeric');
    el.setAttribute('maxlength', String(MAX));
    // El navegador avisa antes de enviar si no son los 10 dígitos completos.
    el.setAttribute('pattern', '[0-9]{' + MAX + '}');
    el.setAttribute('title', 'El teléfono son ' + MAX + ' dígitos, sin indicativo. Ej: 3001234567');
    if (!el.getAttribute('placeholder')) el.setAttribute('placeholder', '10 dígitos');
    el.addEventListener('input', function () { limpiar(el); avisar(el); });
    el.addEventListener('paste', function () { setTimeout(function () { limpiar(el); avisar(el); }, 0); });
    limpiar(el); // por si viene con formato viejo desde la base
    avisar(el);
  }

  document.querySelectorAll('input[data-telefono-cliente]').forEach(preparar);
})();
