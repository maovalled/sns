-- Actualización: tipificación y validación de clientas
-- Ejecutar UNA vez sobre la BD sns_principal existente.
USE sns_principal;

ALTER TABLE clientes
  ADD COLUMN tipo VARCHAR(40) DEFAULT NULL AFTER es_nueva,
  ADD COLUMN documento VARCHAR(40) DEFAULT NULL AFTER tipo,
  ADD COLUMN fecha_nacimiento DATE DEFAULT NULL AFTER documento,
  ADD COLUMN direccion VARCHAR(200) DEFAULT NULL AFTER fecha_nacimiento,
  ADD COLUMN notas VARCHAR(500) DEFAULT NULL AFTER direccion,
  ADD COLUMN validado TINYINT(1) NOT NULL DEFAULT 0 AFTER notas,
  ADD COLUMN validado_por INT DEFAULT NULL AFTER validado,
  ADD COLUMN validado_en DATETIME DEFAULT NULL AFTER validado_por,
  ADD INDEX idx_telefono (telefono),
  ADD CONSTRAINT fk_clientes_validado_por FOREIGN KEY (validado_por) REFERENCES usuarios(id);
