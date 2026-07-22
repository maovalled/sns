-- Orden de presentación de los servicios en el sitio público y en el agendamiento.
-- Antes se listaban por precio, así que lo primero que veía la clienta era el
-- servicio más barato. Ahora manda esta columna: menor número, primero.
-- Ejecutar UNA vez (en una instalación nueva ya viene incluida).
USE sns_principal;

ALTER TABLE servicios
  ADD COLUMN orden INT NOT NULL DEFAULT 999 AFTER duracion_min;

-- Se agrupa por familia de servicio; dentro de cada grupo desempata el precio.
UPDATE servicios SET orden = 10 WHERE id BETWEEN 1 AND 5;    -- Manicure
UPDATE servicios SET orden = 20 WHERE id BETWEEN 9 AND 11;   -- Pedicure
UPDATE servicios SET orden = 30 WHERE id = 6;                -- Recubrimiento polygel/acrílico
UPDATE servicios SET orden = 40 WHERE id BETWEEN 7 AND 8;    -- Retiros
UPDATE servicios SET orden = 50 WHERE id BETWEEN 12 AND 13;  -- Hombres
UPDATE servicios SET orden = 60 WHERE id BETWEEN 19 AND 20;  -- Cejas y pestañas
UPDATE servicios SET orden = 70 WHERE id BETWEEN 14 AND 18;  -- Depilación
UPDATE servicios SET orden = 80 WHERE id BETWEEN 21 AND 24;  -- Cabello

SELECT orden, GROUP_CONCAT(nombre ORDER BY precio SEPARATOR ' · ') AS servicios
FROM servicios WHERE activo = 1 GROUP BY orden ORDER BY orden;
