# Despliegues a producción

Registro de qué hay publicado en Hostinger y desde cuándo. Este proyecto no usa
control de versiones, así que la fecha de este archivo sirve además de marca:
para saber qué falta por subir, comparar contra él.

```
find . -newer DESPLIEGUES.md -type f \( -name "*.php" -o -name "*.js" \) \
  -not -path "./tests/*" -not -path "./uploads/*"
```

---

## v1.2.0 · 21 de julio de 2026

Inventario real del negocio, con ubicación física y marca por producto.

**Archivos publicados**

- `admin/inventario.php` — ubicación y marca en el alta; filtros por ubicación y
  categoría; buscador en vivo; edición completa de la ficha de cada producto y
  resumen por ubicación.
- `admin/personal.php` — edición completa de cada integrante (nombre, usuario, rol,
  teléfono y % comisión) desde un panel desplegable. Sin cambios de base de datos:
  la tabla `usuarios` ya tenía todas las columnas.

**Base de datos:** `sql/actualizacion_inventario_excel.sql` — agrega las columnas
`ubicacion` y `marca`, crea el catálogo `ubicaciones` (las 6 pestañas del Excel) e
importa los ~515 ítems del inventario actual. **Ejecutar una sola vez** (el `ALTER`
falla si ya existen las columnas). Migrado desde `INVENTARIO.xlsx`.

> Los scripts de instalación (`sns_instalacion_completa.sql`, `sns_principal.sql`) ya
> traen las columnas y el catálogo de ubicaciones para cualquier instalación nueva.

---

## v1.1.0 · 20 de julio de 2026

Catálogo real de servicios, orden de presentación, sello de fidelidad selectivo
y marca de agua en la galería.

**Archivos publicados**

- `includes/imagen.php` *(nuevo)* — marca de agua, orientación y reducción de fotos
- `includes/fidelidad.php` — solo sellan los semipermanentes y superiores
- `admin/galeria.php` — aplica la marca al subir
- `admin/parametricas.php` — editor de orden, sello y parámetros de la marca
- `admin/index.php` · `index.php` · `agendar.php` — orden de los servicios

**Base de datos:** `sql/actualizacion_produccion_v1.1.sql`
(columna `orden`, columna `suma_fidelidad`, los 24 servicios reales y los
3 parámetros de la marca de agua). Es repetible sin error.

**Pendiente de subir** (correcciones posteriores al despliegue):

- `includes/imagen.php` — la marca se anclaba al borde inferior y la galería,
  que muestra las fotos en cuadrado, la recortaba: solo se veía el 38 %. Ahora
  se ancla al cuadrado central, que siempre sobrevive al recorte.
- `admin/galeria.php` — botón **Regenerar marcas de agua**: vuelve a estampar la
  marca sobre los originales guardados, con la opacidad y el tamaño del momento.
- `includes/funciones.php` — solo para que el login muestre la versión correcta.

Sin base de datos: estos tres son solo archivos.

---

## v1.0.0 · 20 de julio de 2026

Primera publicación. Instalación completa con
`sql/sns_instalacion_completa.sql`.

Incluye: agendamiento público y del panel, clientas, citas, cobros, fidelidad,
inventario, galería, nómina de manicuristas (comisión + bono quincenal),
préstamos con cupo por corte, asistencia, teléfono único de 10 dígitos y
detección automática de entorno en `config/db.php`.
