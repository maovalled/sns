-- Actualización: control de tasa (anti-barrido) para endpoints públicos
-- Ejecutar UNA vez sobre la BD sns_principal existente.
USE sns_principal;

CREATE TABLE IF NOT EXISTS rate_limit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  endpoint VARCHAR(40) NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_ep (ip, endpoint, creado_en)
) ENGINE=InnoDB;
