# Sailor Nails Spa 🌙 · v1.0.0

Sitio web + panel de administración para el spa de uñas temático.
PHP 8 · Bootstrap 5.3 · MySQL (`sns_principal`).

> La versión se define en `includes/funciones.php` (`APP_VERSION`) y se muestra
> en el login del panel. Súbela al publicar cambios.

## Instalación en un servidor nuevo (MVP 1)

Un solo archivo monta todo: **`sql/sns_instalacion_completa.sql`**.

```
mysql -u USUARIO -p < sql/sns_instalacion_completa.sql
```

Crea las 25 tablas, los catálogos, los parámetros del local y el personal ya
configurado (con sus claves y horarios). No trae datos de operación: arranca limpio.

En Hostinger: **hPanel → Bases de datos → phpMyAdmin → Importar** y sube ese archivo.
Antes, si el hosting ya te creó la base con otro nombre, borra de la primera línea
del script el `CREATE DATABASE` / `USE` y ejecútalo estando dentro de tu base.

Después:

1. En `config/db.php`, llena **solo** la constante `DB_PRODUCCION` con los datos que
   da Hostinger (hPanel → Bases de datos). No toques `DB_LOCAL`: el archivo detecta
   solo dónde está corriendo y usa las credenciales que toquen.
2. **No subas `instalar.php`** (o bórralo): en el servidor no sirve y es un archivo de más.
3. **No subas `tests/` ni `sql/`**: son de desarrollo. Si los subes, ya traen `.htaccess`
   que bloquea el acceso web, pero es más limpio dejarlos fuera.
4. Crea `uploads/galeria/` con permisos de escritura (755).
5. En **Admin → Paramétricas**, pon `sitio_url` con tu dominio (`https://tudominio.com`):
   lo usan el sitemap y las URLs que se comparten.
6. **Borra el .sql del servidor** una vez importado: contiene los hashes de las claves.

> Los `sql/actualizacion_*.sql` son el historial de migraciones del entorno local.
> En una instalación nueva **no se ejecutan**: ya están incluidos en el script completo.

### Catálogo de servicios

El **orden** en que se listan en el sitio y al agendar lo manda la columna `orden`
de `servicios` (menor número, primero) — se edita en **Admin → Paramétricas**:

| Orden | Grupo |
|---|---|
| 10 | Manicure |
| 20 | Pedicure |
| 30 | Recubrimiento en polygel o acrílico |
| 40 | Retiros |
| 50 | Hombres |
| 60 | Cejas y pestañas |
| 70 | Depilación |
| 80 | Cabello |

Dentro de cada grupo desempata el precio, de menor a mayor. Un servicio nuevo entra
con orden 999 (al final) hasta que se le asigne uno.

**Sello de fidelidad.** Solo sellan la tarjeta los servicios con `suma_fidelidad = 1`:
los semipermanentes y superiores. Se activa por servicio en **Paramétricas** (columna
🎫 Sello) y al crearlo. Un servicio nuevo **no sella** hasta que se marque.

Como el cobro se registra por reserva, basta con que **uno** de sus servicios selle
para contar la visita — y cuenta **una sola**, no una por servicio. Las citas
canceladas de la reserva no arrastran el sello. Si ningún servicio aplica, no se
registra visita ni se crea tarjeta, y el panel lo avisa al cobrar.


Los 24 servicios y sus precios vienen dentro del script de instalación. Para
actualizar el catálogo en una base **que ya está en uso**, usa
`sql/actualizacion_catalogo_servicios.sql`: reemplaza los servicios en sitio en vez
de borrarlos, porque las citas y los cobros ya registrados los referencian.
Ese archivo se genera a partir del script de instalación, así que ambos no pueden
quedar desalineados.

## Instalación local de desarrollo (XAMPP / WAMP / Laragon)

1. Copia la carpeta `sailor-nails-spa` dentro de `htdocs` (XAMPP) o `www`.
2. Asegúrate de que MySQL esté corriendo (localhost, usuario `root`, sin clave, puerto 3306).
3. Abre en el navegador: `http://localhost/sailor-nails-spa/instalar.php`
   - Define la clave del usuario **admin** y ejecuta la instalación (crea BD, tablas, datos semilla y carpeta de uploads).
4. **Borra `instalar.php`** cuando termine.
5. Sitio público: `http://localhost/sailor-nails-spa/`
   Panel admin: `http://localhost/sailor-nails-spa/admin/login.php`

> Requiere la extensión **GD** de PHP habilitada (para el captcha) — viene activa por defecto en XAMPP.

## Estructura

- `index.php` — home: hero, servicios, galería, ubicación/contacto
- `agendar.php` — wizard de citas (servicio → manicurista → fecha/hora → datos)
  - Clienta nueva ☑ → la cita queda "Esperando pago" y se genera un **código de pago** (abono por transferencia, sin pasarela)
  - `api/horas.php` — horas disponibles según manicurista/fecha
- `admin/` — panel con login + captcha (GD) y roles:
  - **admin**: todo
  - **recepcion**: citas, inventario, galería
  - **manicurista**: sus citas y sus pagos (solo lectura)
  - Módulos: citas (estados/comprobantes), personal, asistencia, préstamos, pagos manicuristas, inventario, galería (subir fotos), paramétricas (servicios + datos del local + horarios)
- `sql/sns_principal.sql` — script completo de la BD
- `config/db.php` — conexión PDO. Trae dos juegos de credenciales (`DB_LOCAL` y
  `DB_PRODUCCION`) y elige según el entorno: dominio `localhost`/`.test`/`.local` →
  local; cualquier otro dominio → producción. Por consola (pruebas, cron) decide el
  sistema operativo: Windows → local. Para forzarlo, la variable de entorno
  `SNS_ENTORNO=local|produccion` manda sobre todo.

## Nómina de manicuristas

Se liquida por **corte quincenal**: del 1 al 15 (se paga el 15) y del 16 al último día del mes
(se paga el 30, el 31 o el último día de febrero, según el mes).

| Concepto | Cómo se calcula |
|---|---|
| **Comisión** | % propio de cada manicurista sobre lo cobrado (neto, ya con cupones descontados) de los servicios que ella atendió. El % se edita en **Personal** (Valentina 50 %, Lorena 50 %, Estefani 45 %…). |
| **Bono** | $120.000 por quincena, menos $8.000 por cada día programado al que **no** asistió. Las faltas se marcan en **Asistencia**; los montos se editan en **Paramétricas**. |
| **Préstamos** | Se descuentan del neto al liquidar el corte, del préstamo más antiguo al más nuevo. |

**Días programados** = los del horario semanal de la manicurista (módulo Personal), descontando
domingos y días especiales cerrados, y sumando las aperturas especiales.

### Préstamos (`admin/prestamos.php`)

Solo se puede prestar hasta lo que la manicurista **lleva ganado en el corte actual**:

```
cupo = comisión de los servicios ya cobrados
     + bono causado ($8.000 × días trabajados hasta hoy, tope $120.000)
     − préstamos vigentes
```

Si no tiene servicios realizados en el corte, el sistema **no permite prestarle**. El préstamo
queda con saldo pendiente y se descuenta solo en la liquidación; también admite abonos manuales.

### Liquidación (`admin/pagos.php`)

Muestra el desglose del corte por manicurista y registra el pago. Al liquidar, los valores quedan
**congelados** en la tabla `liquidaciones`: un cobro registrado después ya no altera el histórico.
La liquidación se puede deshacer (revierte los abonos y el pago).

> Migración: `sql/actualizacion_prestamos.sql` — ejecutar **una sola vez**.

## Galería: marca de agua

Al subir una foto se le graba el logo del spa abajo a la derecha. La marca queda
**dentro del archivo publicado**, así que quien descargue la foto desde el sitio
(o la copie desde Instagram) se la lleva con la marca.

Al subir, la foto además se endereza según la orientación de la cámara y se
reduce a 1600 px de lado mayor (una foto de celular puede pesar 5 MB).

El **original sin marca** se guarda en `uploads/galeria/originales/`, bloqueado por
`.htaccess`. Sin esa copia no habría manera de rehacer la marca con otro tamaño u
opacidad, porque va grabada. Al borrar una foto del panel se borran las dos.

Se ajusta en **Admin → Paramétricas**:

| Parámetro | Por defecto | |
|---|---|---|
| `marca_agua` | `1` | 1 = activada, 0 = desactivada |
| `marca_agua_opacidad` | `70` | 10–100. Por debajo de 60 se pierde en fotos claras |
| `marca_agua_tamano` | `22` | % del lado menor de la foto |

> Migración: `sql/actualizacion_marca_agua.sql` (en instalación nueva ya viene).
> Requiere la extensión **GD** de PHP — la misma del captcha.

## Teléfono de la clienta

El teléfono **identifica** a la clienta: con él se la reconoce al agendar y con él se
enlaza su tarjeta de fidelidad. Por eso:

- Es **obligatorio** y de **exactamente 10 dígitos, sin indicativo**. Si se escribe con
  `+57`, espacios o guiones, se limpia solo. Se guarda siempre como los 10 dígitos pelados.
- Es **único**: lo garantiza la base (`uq_telefono`), no solo el código. Dos altas
  simultáneas con el mismo número ya no pueden crear fichas duplicadas.
- Al cambiarlo desde Clientas, su **tarjeta de fidelidad se mueve con ella** para no
  perder las visitas.
- Agendar con un teléfono ya registrado **nunca renombra la ficha**: el nombre de la
  titular manda. Si se escribió otro nombre, la cita queda anotada como
  `Asiste: <nombre>` y el panel lo avisa. El correo solo se completa si faltaba.
  El nombre de la ficha se cambia únicamente desde **Clientas → Editar**.

La restricción no aplica al teléfono del **personal**, que puede ser fijo o traer extensión.

> Migración: `sql/actualizacion_telefono_unico.sql` — ejecutar **una sola vez**, y solo
> si no hay duplicados (el propio archivo trae la consulta para comprobarlo).

### Pruebas

```
php tests/test_nomina.php          # cortes, comisión, bono, cupo de préstamo y abonos
php tests/test_flujos.php          # flujos POST: validaciones de préstamo y liquidación
php tests/test_clientes.php        # alta de clientas y endpoints de búsqueda
php tests/test_fidelidad.php       # cobro → visita → cupón
php tests/test_telefono.php        # teléfono obligatorio de 10 dígitos
php tests/test_telefono_unico.php  # unicidad garantizada por la base
php tests/test_nombre_no_sobrescribe.php  # la ficha no se renombra al agendar
php tests/test_entorno.php         # detección local vs producción en db.php
php tests/test_instalador.php      # instalador en ambos entornos
php tests/test_sello_fidelidad.php # qué servicios sellan la tarjeta
php tests/test_marca_agua.php      # marca de agua en la galería
```

Corren contra la base local y **limpian sus propios datos** al terminar.

## Inventario

Cada producto vive en un **lugar físico** (`ubicacion`) y tiene una **marca** además de
su categoría. Las ubicaciones son un catálogo reutilizable (tabla `ubicaciones`), igual
que las categorías: lo que se escribe al ingresar un producto queda disponible para los
demás. El módulo (`admin/inventario.php`) permite filtrar por ubicación y por categoría,
buscar por nombre/marca en vivo, y **editar toda la ficha** de cada producto (nombre,
ubicación, categoría, marca, unidad, stock, mínimo, fecha; el costo solo lo ve/edita el
admin). El aviso **stock bajo** salta cuando `stock <= stock_minimo`.

**Carga del inventario real.** El inventario del negocio se migró desde el Excel
`INVENTARIO.xlsx` (una pestaña por lugar) con `sql/actualizacion_inventario_excel.sql`:
agrega las columnas `ubicacion`/`marca`, siembra el catálogo de ubicaciones e inserta los
~515 ítems. Como los demás `actualizacion_*.sql`, se ejecuta **una sola vez** sobre una
base ya instalada (en una instalación nueva las columnas y el catálogo ya vienen en el
script completo; los ítems no, porque son datos de operación). El Excel no trae precios,
así que `valor` queda vacío hasta que se cargue en el panel.

## Notas

- Los datos del local (dirección, teléfono, WhatsApp, cuenta de abonos, horario de atención) se editan en **Admin → Paramétricas**.
- Las citas se agendan en bloques de 1 hora entre la hora de apertura y cierre configuradas.
- Fotos de la galería se guardan en `uploads/galeria/`.
