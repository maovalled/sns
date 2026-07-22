-- El teléfono identifica a la clienta (agendamiento, fidelidad, cupones):
-- se blinda la unicidad en la base, no solo en el código.
-- Ejecutar UNA vez. Antes verifica que no haya duplicados con:
--   SELECT telefono, COUNT(*) FROM clientes GROUP BY telefono HAVING COUNT(*) > 1;
USE sns_principal;

-- El índice único reemplaza al normal: sigue sirviendo para buscar por teléfono.
ALTER TABLE clientes
  DROP INDEX idx_telefono,
  ADD UNIQUE KEY uq_telefono (telefono);
