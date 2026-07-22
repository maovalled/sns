-- Actualización: disponibilidad (horario semanal) por manicurista
-- Ejecutar UNA vez sobre la BD sns_principal existente.
USE sns_principal;

-- Horario semanal de cada manicurista. dia_semana: 1=Lunes … 7=Domingo (ISO, date('N')).
CREATE TABLE IF NOT EXISTS disponibilidad (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  dia_semana TINYINT NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  UNIQUE KEY uq_usuario_dia (usuario_id, dia_semana),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Horario de atención del local (editable en Paramétricas). Domingo = cerrado.
INSERT INTO configuracion (clave, valor) VALUES
  ('atencion_lv_inicio','10:00'),
  ('atencion_lv_fin','19:00'),
  ('atencion_sab_inicio','09:00'),
  ('atencion_sab_fin','19:00')
ON DUPLICATE KEY UPDATE clave=clave;

-- Sembrar el horario de atención por defecto a manicuristas existentes que no tengan horario.
INSERT INTO disponibilidad (usuario_id, dia_semana, hora_inicio, hora_fin)
SELECT u.id, d.dia, d.ini, d.fin
FROM usuarios u
JOIN (
  SELECT 1 AS dia, '10:00' AS ini, '19:00' AS fin UNION ALL
  SELECT 2, '10:00','19:00' UNION ALL
  SELECT 3, '10:00','19:00' UNION ALL
  SELECT 4, '10:00','19:00' UNION ALL
  SELECT 5, '10:00','19:00' UNION ALL
  SELECT 6, '09:00','19:00'
) d
WHERE u.rol = 'manicurista' AND u.activo = 1
  AND NOT EXISTS (SELECT 1 FROM disponibilidad x WHERE x.usuario_id = u.id);
