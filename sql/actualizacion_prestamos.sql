-- Actualización: nómina de manicuristas (comisión por servicios + bono quincenal) y préstamos.
-- Ejecutar UNA vez.
USE sns_principal;

-- ─── Porcentaje de comisión por manicurista (Valentina 50, Lorena 50, Estefani 45…) ───
ALTER TABLE usuarios
  ADD COLUMN porcentaje_comision DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER telefono;

-- ─── Asistencia: solo se registran las EXCEPCIONES del día programado ───
-- Un día programado sin fila aquí se asume asistido. asistio=0 descuenta el bono del día.
CREATE TABLE IF NOT EXISTS asistencia (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manicurista_id INT NOT NULL,
  fecha DATE NOT NULL,
  asistio TINYINT(1) NOT NULL DEFAULT 0,
  motivo VARCHAR(150) DEFAULT NULL,
  registrado_por INT DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mani_fecha (manicurista_id, fecha),
  FOREIGN KEY (manicurista_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
  INDEX idx_fecha (fecha)
) ENGINE=InnoDB;

-- ─── Liquidaciones (corte quincenal ya cerrado y pagado) ───
-- Congela el cálculo del corte para que un cobro registrado después no altere el histórico.
CREATE TABLE IF NOT EXISTS liquidaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manicurista_id INT NOT NULL,
  periodo_inicio DATE NOT NULL,
  periodo_fin DATE NOT NULL,
  base_servicios DECIMAL(10,0) NOT NULL DEFAULT 0,   -- total cobrado neto atendido por ella
  porcentaje DECIMAL(5,2) NOT NULL,                  -- % aplicado en ESTE corte
  comision DECIMAL(10,0) NOT NULL DEFAULT 0,
  dias_programados INT NOT NULL DEFAULT 0,
  dias_falta INT NOT NULL DEFAULT 0,
  bono DECIMAL(10,0) NOT NULL DEFAULT 0,
  ajuste DECIMAL(10,0) NOT NULL DEFAULT 0,           -- +/- manual, con nota
  descuento_prestamos DECIMAL(10,0) NOT NULL DEFAULT 0,
  neto DECIMAL(10,0) NOT NULL DEFAULT 0,
  nota VARCHAR(255) DEFAULT NULL,
  liquidado_por INT NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mani_periodo (manicurista_id, periodo_inicio, periodo_fin),
  FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  FOREIGN KEY (liquidado_por) REFERENCES usuarios(id),
  INDEX idx_periodo (periodo_inicio, periodo_fin)
) ENGINE=InnoDB;

-- ─── Préstamos a manicuristas ───
-- saldo = lo que falta por descontar. estado: pendiente | pagado | anulado.
CREATE TABLE IF NOT EXISTS prestamos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manicurista_id INT NOT NULL,
  monto DECIMAL(10,0) NOT NULL,
  saldo DECIMAL(10,0) NOT NULL,
  fecha DATE NOT NULL,
  motivo VARCHAR(200) DEFAULT NULL,
  estado ENUM('pendiente','pagado','anulado') NOT NULL DEFAULT 'pendiente',
  cupo_al_prestar DECIMAL(10,0) DEFAULT NULL,  -- cuánto llevaba ganado en el corte (auditoría)
  autorizado_por INT NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  FOREIGN KEY (autorizado_por) REFERENCES usuarios(id),
  INDEX idx_mani_estado (manicurista_id, estado),
  INDEX idx_fecha (fecha)
) ENGINE=InnoDB;

-- ─── Abonos a préstamos (por liquidación o manuales) ───
CREATE TABLE IF NOT EXISTS prestamos_abonos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  prestamo_id INT NOT NULL,
  monto DECIMAL(10,0) NOT NULL,
  fecha DATE NOT NULL,
  liquidacion_id INT DEFAULT NULL,   -- NULL = abono manual (en efectivo, fuera de nómina)
  registrado_por INT NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (prestamo_id) REFERENCES prestamos(id) ON DELETE CASCADE,
  FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE SET NULL,
  FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
  INDEX idx_prestamo (prestamo_id)
) ENGINE=InnoDB;

-- ─── Enlazar el pago en caja con su liquidación ───
ALTER TABLE pagos_manicuristas
  ADD COLUMN liquidacion_id INT DEFAULT NULL AFTER concepto,
  ADD CONSTRAINT fk_pagos_liquidacion FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE SET NULL;

-- ─── Paramétricas del bono ───
INSERT INTO configuracion (clave, valor) VALUES
  ('bono_quincenal','120000'),
  ('bono_valor_dia','8000')
ON DUPLICATE KEY UPDATE valor=VALUES(valor);
