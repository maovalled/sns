-- Actualización: promociones + datos para SEO
-- Ejecutar UNA vez sobre la BD sns_principal existente.
USE sns_principal;

-- Promociones para un día específico. Se muestran en el front el día anterior y el día de la promo.
CREATE TABLE IF NOT EXISTS promociones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  descripcion VARCHAR(500) DEFAULT NULL,
  fecha DATE NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_por INT DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (creado_por) REFERENCES usuarios(id),
  INDEX idx_fecha (fecha)
) ENGINE=InnoDB;

-- Config para SEO (editable en Paramétricas).
INSERT INTO configuracion (clave, valor) VALUES
  ('sitio_url', ''),          -- dominio en producción, ej. https://sailornailsspa.com (vacío = se deduce del request)
  ('ciudad', 'Bogotá')        -- ciudad para SEO local / datos estructurados
ON DUPLICATE KEY UPDATE clave = clave;
