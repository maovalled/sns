-- Actualización: bloqueos de agenda (ausencias) por manicurista
-- Ejecutar UNA vez sobre la BD sns_principal existente.
USE sns_principal;

-- Bloqueo de una manicurista en una fecha. hora_inicio/hora_fin NULL = todo el día.
CREATE TABLE IF NOT EXISTS bloqueos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  fecha DATE NOT NULL,
  hora_inicio TIME DEFAULT NULL,
  hora_fin TIME DEFAULT NULL,
  motivo VARCHAR(150) DEFAULT NULL,
  creado_por INT DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (creado_por) REFERENCES usuarios(id),
  INDEX idx_usuario_fecha (usuario_id, fecha)
) ENGINE=InnoDB;
