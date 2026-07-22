-- Actualización: cobro por RESERVA (total único, forma de pago, fecha = registro, manicurista)
-- Reemplaza el cobro por-cita anterior. Ejecutar UNA vez.
USE sns_principal;

-- Quitar el cobro por-cita (se mueve a la tabla cobros)
ALTER TABLE citas
  DROP FOREIGN KEY fk_citas_cobrado_por,
  DROP COLUMN monto_pagado,
  DROP COLUMN servicios_realizados,
  DROP COLUMN cobrado_por,
  DROP COLUMN cobrado_en;

CREATE TABLE IF NOT EXISTS cobros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_id VARCHAR(20) DEFAULT NULL,        -- reserva (si aplica)
  cita_id INT DEFAULT NULL,                 -- cita suelta (si no hay reserva)
  cliente_id INT NOT NULL,
  manicurista_id INT DEFAULT NULL,          -- manicurista que realizó el servicio
  monto DECIMAL(10,0) NOT NULL,             -- total por todos los servicios
  forma_pago VARCHAR(20) NOT NULL,          -- Nequi / Daviplata / Bancolombia / Efectivo / Tarjeta
  servicios_realizados VARCHAR(500) DEFAULT NULL,
  fecha_pago DATE NOT NULL,                 -- = fecha de registro
  registrado_por INT DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
  INDEX idx_grupo (grupo_id),
  INDEX idx_cita (cita_id),
  INDEX idx_fecha (fecha_pago)
) ENGINE=InnoDB;
