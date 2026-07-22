-- Actualización: la visita de fidelidad se cuenta automáticamente al registrar el cobro
-- Vincula cada visita al cobro que la generó (si se borra el cobro, se descuenta la visita).
USE sns_principal;

ALTER TABLE visitas_tarjeta
  ADD COLUMN cobro_id INT DEFAULT NULL AFTER registrado_por,
  ADD INDEX idx_cobro (cobro_id),
  ADD CONSTRAINT fk_visita_cobro FOREIGN KEY (cobro_id) REFERENCES cobros(id) ON DELETE CASCADE;
