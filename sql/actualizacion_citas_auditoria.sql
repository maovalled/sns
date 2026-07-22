-- Actualización: autoría y auditoría de citas
-- Ejecutar UNA vez sobre la BD sns_principal existente.
USE sns_principal;

-- Quién insertó la cita (NULL = agendada por la clienta desde la web).
ALTER TABLE citas
  ADD COLUMN creado_por INT DEFAULT NULL AFTER creado_en,
  ADD CONSTRAINT fk_citas_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id);

-- Bitácora de cambios de cada cita (creación, reprogramación, estado).
CREATE TABLE IF NOT EXISTS citas_auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cita_id INT NOT NULL,
  usuario_id INT DEFAULT NULL,           -- quién hizo el cambio (NULL = clienta/web)
  accion VARCHAR(20) NOT NULL,           -- 'creada' | 'reprogramada' | 'estado'
  fecha_anterior DATE DEFAULT NULL,
  hora_anterior TIME DEFAULT NULL,
  fecha_nueva DATE DEFAULT NULL,
  hora_nueva TIME DEFAULT NULL,
  detalle VARCHAR(255) DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cita_id) REFERENCES citas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX idx_cita (cita_id)
) ENGINE=InnoDB;
