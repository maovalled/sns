-- Actualización: días especiales del local (festivos / aperturas especiales)
-- Excepciones al horario semanal, para TODO el local. Ejecutar UNA vez.
USE sns_principal;

CREATE TABLE IF NOT EXISTS dias_especiales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL UNIQUE,
  abierto TINYINT(1) NOT NULL,          -- 0 = cerrado (festivo) · 1 = abierto especial
  hora_inicio TIME DEFAULT NULL,        -- horario si abierto especial
  hora_fin TIME DEFAULT NULL,
  titulo VARCHAR(100) DEFAULT NULL,     -- ej. "Festivo · Independencia"
  nota VARCHAR(255) DEFAULT NULL,       -- mensaje para el banner del sitio
  creado_por INT DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;
