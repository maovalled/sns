-- ═══════════════════════════════════════════════════════════════════════════
--  Sailor Nails Spa · Actualización de producción  (v1.0.0 → v1.1.0)
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Para una base que YA está instalada y en uso. Reúne, en el orden correcto:
--    1. Columna `orden`     — cómo se listan los servicios en el sitio
--    2. Columna `suma_fidelidad` — qué servicios sellan la tarjeta
--    3. Catálogo de servicios — los 24 reales, reemplazando los de ejemplo
--    4. Parámetros de la marca de agua de la galería
--
--  CÓMO EJECUTARLO EN HOSTINGER
--    hPanel → phpMyAdmin → selecciona TU base a la izquierda → pestaña SQL →
--    pega este archivo → Continuar.
--
--  No lleva CREATE DATABASE ni USE a propósito: corre sobre la base que tengas
--  seleccionada, sin importar cómo se llame (en el hosting lleva prefijo).
--
--  Es seguro repetirlo: detecta si las columnas ya existen y no da error.
-- ═══════════════════════════════════════════════════════════════════════════


-- ───────────────────────────────────────────────────────────────────────────
--  actualizacion_orden_servicios.sql
-- ───────────────────────────────────────────────────────────────────────────
-- Orden de presentación de los servicios en el sitio público y en el agendamiento.
-- Antes se listaban por precio, así que lo primero que veía la clienta era el
-- servicio más barato. Ahora manda esta columna: menor número, primero.
-- Ejecutar UNA vez (en una instalación nueva ya viene incluida).

-- Solo si la columna no existe todavía: así el script se puede repetir sin errores.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'servicios'
                  AND column_name = 'orden');
SET @sql := IF(@existe = 0,
  'ALTER TABLE servicios ADD COLUMN orden INT NOT NULL DEFAULT 999 AFTER duracion_min',
  'DO 0');
PREPARE _st FROM @sql; EXECUTE _st; DEALLOCATE PREPARE _st;

-- Se agrupa por familia de servicio; dentro de cada grupo desempata el precio.
UPDATE servicios SET orden = 10 WHERE id BETWEEN 1 AND 5;    -- Manicure
UPDATE servicios SET orden = 20 WHERE id BETWEEN 9 AND 11;   -- Pedicure
UPDATE servicios SET orden = 30 WHERE id = 6;                -- Recubrimiento polygel/acrílico
UPDATE servicios SET orden = 40 WHERE id BETWEEN 7 AND 8;    -- Retiros
UPDATE servicios SET orden = 50 WHERE id BETWEEN 12 AND 13;  -- Hombres
UPDATE servicios SET orden = 60 WHERE id BETWEEN 19 AND 20;  -- Cejas y pestañas
UPDATE servicios SET orden = 70 WHERE id BETWEEN 14 AND 18;  -- Depilación
UPDATE servicios SET orden = 80 WHERE id BETWEEN 21 AND 24;  -- Cabello

-- ───────────────────────────────────────────────────────────────────────────
--  actualizacion_sello_fidelidad.sql
-- ───────────────────────────────────────────────────────────────────────────
-- Regla de fidelidad: solo los servicios semipermanentes y superiores sellan la
-- tarjeta. El resto (tradicional, retiros, depilación, cabello) no suma visita.
-- La bandera se edita por servicio en Admin → Paramétricas.
-- Ejecutar UNA vez (en una instalación nueva ya viene incluida).

-- Por defecto NO sella: un servicio nuevo tiene que activarse a propósito.
-- Solo si la columna no existe todavía: así el script se puede repetir sin errores.
SET @existe := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'servicios'
                  AND column_name = 'suma_fidelidad');
SET @sql := IF(@existe = 0,
  'ALTER TABLE servicios ADD COLUMN suma_fidelidad TINYINT(1) NOT NULL DEFAULT 0 AFTER orden',
  'DO 0');
PREPARE _st FROM @sql; EXECUTE _st; DEALLOCATE PREPARE _st;

-- Semipermanentes y superiores
UPDATE servicios SET suma_fidelidad = 1 WHERE id IN (
   3,  -- Manicure semipermanente
   4,  -- Manicure base rubber
   5,  -- Manicure soft gel o press-on
   6,  -- Recubrimiento en polygel o acrílico
  11,  -- Pedicure semipermanente
  12,  -- Manicure semipermanente para hombre
  13   -- Pedicure semipermanente para hombre
);

-- ───────────────────────────────────────────────────────────────────────────
--  actualizacion_catalogo_servicios.sql
-- ───────────────────────────────────────────────────────────────────────────
-- Actualización del catálogo de servicios (lista de precios vigente).
-- Para una base que YA está en uso: reemplaza los servicios existentes en sitio,
-- sin borrarlos, porque las citas ya registradas los referencian.
-- Requiere las columnas `orden` y `suma_fidelidad`
-- (sql/actualizacion_orden_servicios.sql y sql/actualizacion_sello_fidelidad.sql).
-- En una instalación nueva NO se ejecuta: ya viene en sns_instalacion_completa.sql.

INSERT INTO servicios (id, nombre, descripcion, precio, duracion_min, orden, suma_fidelidad, activo) VALUES
  -- Manicure
  (1,'Manicure tradicional','Limpieza, limado, cutícula y esmalte tradicional.',23000,45,10,0,1),
  (2,'Manicure tradicional secado rápido','Manicure tradicional con esmalte de secado rápido: sales lista en menos tiempo.',28000,45,10,0,1),
  (3,'Manicure semipermanente','Esmaltado en gel de larga duración, con brillo que dura semanas.',45000,60,10,1,1),
  (4,'Manicure base rubber','Base rubber que refuerza y nivela la uña natural, con acabado semipermanente.',58000,75,10,1,1),
  (5,'Manicure soft gel o press-on','Extensiones soft gel o uñas press-on: largo y forma a tu gusto en una sola cita.',90000,90,10,1,1),
  -- Dipping
  (6,'Recubrimiento en polygel o acrílico','Recubrimiento que da resistencia y estructura a la uña, en polygel o acrílico.',80000,120,30,1,1),
  -- Retiros
  (7,'Retiro de semipermanente','Retiro cuidadoso del esmaltado en gel, sin maltratar la uña natural.',15000,30,40,0,1),
  (8,'Retiro de sistemas artificiales','Retiro de acrílico, polygel o soft gel, con limado e hidratación final.',25000,45,40,0,1),
  -- Pedicure
  (9,'Pedicure tradicional','Pedicure completo: limpieza, corte, cutícula y esmalte tradicional.',30000,45,20,0,1),
  (10,'Pedicure secado rápido','Pedicure completo con esmalte de secado rápido.',38000,45,20,0,1),
  (11,'Pedicure semipermanente','Pedicure con esmaltado en gel de larga duración.',50000,60,20,1,1),
  -- Hombres
  (12,'Manicure semipermanente para hombre','Manicure masculino con acabado semipermanente natural.',40000,45,50,1,1),
  (13,'Pedicure semipermanente para hombre','Pedicure masculino con acabado semipermanente natural.',45000,60,50,1,1),
  -- Depilación
  (14,'Depilación de bigote','Depilación del bozo, rápida y precisa.',10000,15,70,0,1),
  (15,'Depilación de cejas','Diseño y depilación de cejas según la forma de tu rostro.',15000,20,70,0,1),
  (16,'Depilación de axilas','Depilación completa de axilas.',25000,20,70,0,1),
  (17,'Depilación de pierna completa','Depilación de pierna completa, de la ingle al tobillo.',80000,45,70,0,1),
  (18,'Depilación de media pierna','Depilación de media pierna, de la rodilla al tobillo.',60000,30,70,0,1),
  -- Cejas y pestañas
  (19,'Cejas semipermanente','Diseño y pigmentación semipermanente de cejas, con efecto natural.',25000,45,60,0,1),
  (20,'Pestañas punto a punto','Extensiones pelo a pelo para una mirada definida y natural.',45000,90,60,0,1),
  -- Cabello
  (21,'Cepillado','Lavado y cepillado con secador para un acabado liso y con movimiento.',25000,30,80,0,1),
  (22,'Shampoo','Lavado del cabello con shampoo y acondicionador.',10000,20,80,0,1),
  (23,'Reforzamiento capilar','Tratamiento que repara y fortalece el cabello desde la raíz a las puntas.',70000,60,80,0,1),
  (24,'Peinado','Peinado para la ocasión que quieras: recogido, ondas o liso.',25000,45,80,0,1)
ON DUPLICATE KEY UPDATE
  nombre         = VALUES(nombre),
  descripcion    = VALUES(descripcion),
  precio         = VALUES(precio),
  duracion_min   = VALUES(duracion_min),
  orden          = VALUES(orden),
  suma_fidelidad = VALUES(suma_fidelidad),
  activo         = VALUES(activo);

-- Los servicios viejos que ya no están en la lista se desactivan (no se borran:
-- las citas y los cobros históricos deben poder seguir mostrando su nombre).
UPDATE servicios SET activo = 0 WHERE id > 24;

-- ───────────────────────────────────────────────────────────────────────────
--  actualizacion_marca_agua.sql
-- ───────────────────────────────────────────────────────────────────────────
-- Marca de agua en las fotos de la galería.
-- La marca queda GRABADA en el archivo publicado, así que quien descargue la
-- foto desde el sitio se la lleva con la marca. El original sin marca se guarda
-- en uploads/galeria/originales/ (bloqueado por .htaccess).
-- Ejecutar UNA vez (en una instalación nueva ya viene incluida).

INSERT INTO configuracion (clave, valor) VALUES
  ('marca_agua','1'),            -- 1 = activada, 0 = desactivada
  ('marca_agua_opacidad','70'),  -- 10 a 100 (por debajo de 60 se pierde en fondos claros)
  ('marca_agua_tamano','22')     -- ancho como % del lado menor de la foto (5 a 40)
ON DUPLICATE KEY UPDATE valor = VALUES(valor);


-- ═══════════════════════════════════════════════════════════════════════════
--  COMPROBACIÓN · debe devolver 24 servicios, 7 que sellan y 21 parámetros
-- ═══════════════════════════════════════════════════════════════════════════
SELECT
  (SELECT COUNT(*) FROM servicios WHERE activo = 1)        AS servicios_activos,
  (SELECT SUM(suma_fidelidad) FROM servicios WHERE activo = 1) AS sellan_tarjeta,
  (SELECT COUNT(*) FROM configuracion)                     AS parametros;
