-- Actualización: cobro del servicio (monto + servicios realizados) por cita
-- Lo registra recepción o administrador. Ejecutar UNA vez.
USE sns_principal;

ALTER TABLE citas
  ADD COLUMN monto_pagado DECIMAL(10,0) DEFAULT NULL AFTER comprobante,
  ADD COLUMN servicios_realizados VARCHAR(500) DEFAULT NULL AFTER monto_pagado,
  ADD COLUMN cobrado_por INT DEFAULT NULL AFTER servicios_realizados,
  ADD COLUMN cobrado_en DATETIME DEFAULT NULL AFTER cobrado_por,
  ADD CONSTRAINT fk_citas_cobrado_por FOREIGN KEY (cobrado_por) REFERENCES usuarios(id);
