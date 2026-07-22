-- Regla de fidelidad: solo los servicios semipermanentes y superiores sellan la
-- tarjeta. El resto (tradicional, retiros, depilación, cabello) no suma visita.
-- La bandera se edita por servicio en Admin → Paramétricas.
-- Ejecutar UNA vez (en una instalación nueva ya viene incluida).
USE sns_principal;

-- Por defecto NO sella: un servicio nuevo tiene que activarse a propósito.
ALTER TABLE servicios
  ADD COLUMN suma_fidelidad TINYINT(1) NOT NULL DEFAULT 0 AFTER orden;

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

SELECT IF(suma_fidelidad, 'SELLA', 'no sella') AS sello,
       GROUP_CONCAT(nombre ORDER BY orden, precio SEPARATOR ' · ') AS servicios
FROM servicios WHERE activo = 1 GROUP BY suma_fidelidad ORDER BY suma_fidelidad DESC;
