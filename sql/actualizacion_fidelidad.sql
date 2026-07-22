-- Actualización: tarjeta virtual de fidelidad
-- Ejecutar en la BD sns_principal existente (o usar instalar.php en una instalación nueva)
USE sns_principal;

CREATE TABLE IF NOT EXISTS tarjetas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(30) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  codigo VARCHAR(8) NOT NULL,
  abierta TINYINT(1) NOT NULL DEFAULT 1,
  creada_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  cerrada_en DATETIME DEFAULT NULL,
  INDEX idx_telefono (telefono)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visitas_tarjeta (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id INT NOT NULL,
  fecha DATE NOT NULL,
  registrado_por INT NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),
  FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cupones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id INT NOT NULL,
  descuento_pct INT NOT NULL,
  generado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  vence_el DATE NOT NULL,
  usado_en DATETIME DEFAULT NULL,
  usado_por INT DEFAULT NULL,
  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),
  FOREIGN KEY (usado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

INSERT INTO configuracion (clave, valor) VALUES
  ('fidelidad_visitas','5'),
  ('fidelidad_descuento','10'),
  ('fidelidad_max_cupones','2'),
  ('fidelidad_vigencia_dias','30')
ON DUPLICATE KEY UPDATE clave=clave;
