-- Actualización: reservas con varios servicios (agrupación de citas)
-- Ejecutar UNA vez sobre la BD sns_principal existente.
USE sns_principal;

-- grupo_id enlaza las citas creadas en una misma reserva (varios servicios).
ALTER TABLE citas
  ADD COLUMN grupo_id VARCHAR(20) DEFAULT NULL AFTER codigo_pago,
  ADD INDEX idx_grupo (grupo_id);
