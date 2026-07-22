-- ⚠ OBSOLETO · NO EJECUTAR EN UNA INSTALACIÓN NUEVA ⚠
--
-- Este archivo es el esquema original del proyecto y quedó incompleto: le faltan
-- las tablas de nómina y préstamos (asistencia, liquidaciones, prestamos,
-- prestamos_abonos), la columna porcentaje_comision y la unicidad del teléfono.
--
-- Para instalar usa:  sql/sns_instalacion_completa.sql
-- Se conserva solo como referencia histórica.
--
-- Sailor Nails Spa · Base de datos sns_principal
-- MySQL 8 / MariaDB · charset utf8mb4
CREATE DATABASE IF NOT EXISTS sns_principal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sns_principal;

-- ─── Usuarios (admin / recepcion / manicurista) ───
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  clave_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','recepcion','manicurista') NOT NULL DEFAULT 'manicurista',
  telefono VARCHAR(30) DEFAULT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── Disponibilidad (horario semanal por manicurista) ───
-- dia_semana: 1=Lunes … 7=Domingo (ISO, igual que date('N')).
CREATE TABLE IF NOT EXISTS disponibilidad (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  dia_semana TINYINT NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  UNIQUE KEY uq_usuario_dia (usuario_id, dia_semana),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Días especiales del local (festivos / aperturas especiales) ───
-- Excepción al horario semanal para todo el local. abierto: 0=cerrado, 1=abierto especial.
CREATE TABLE IF NOT EXISTS dias_especiales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL UNIQUE,
  abierto TINYINT(1) NOT NULL,
  hora_inicio TIME DEFAULT NULL,
  hora_fin TIME DEFAULT NULL,
  titulo VARCHAR(100) DEFAULT NULL,
  nota VARCHAR(255) DEFAULT NULL,
  creado_por INT DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ─── Bloqueos de agenda (ausencias) por manicurista ───
-- hora_inicio/hora_fin NULL = todo el día bloqueado.
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

-- ─── Paramétrica: estados de cita ───
CREATE TABLE IF NOT EXISTS estados_cita (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT 'secondary'
) ENGINE=InnoDB;

INSERT INTO estados_cita (id, nombre, color) VALUES
  (1,'Esperando pago','warning'),
  (2,'Confirmada','info'),
  (3,'Atendida','success'),
  (4,'Cancelada','danger')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

-- ─── Paramétrica: servicios ───
CREATE TABLE IF NOT EXISTS servicios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  precio DECIMAL(10,0) NOT NULL DEFAULT 0,
  duracion_min INT NOT NULL DEFAULT 60,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO servicios (id, nombre, descripcion, precio, duracion_min) VALUES
  (1,'Manicure tradicional','Limpieza, limado y esmalte clásico',25000,60),
  (2,'Semipermanente','Esmaltado en gel de larga duración',45000,60),
  (3,'Uñas acrílicas','Extensión y decoración en acrílico',80000,120),
  (4,'Pedicure spa','Pedicure completo con exfoliación',40000,60),
  (5,'Nail art temático','Diseños personalizados estilo Sailor Moon',60000,90)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

-- ─── Clientas ───
CREATE TABLE IF NOT EXISTS clientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  telefono VARCHAR(30) NOT NULL,
  email VARCHAR(120) DEFAULT NULL,
  es_nueva TINYINT(1) NOT NULL DEFAULT 1,
  tipo VARCHAR(40) DEFAULT NULL,
  documento VARCHAR(40) DEFAULT NULL,
  fecha_nacimiento DATE DEFAULT NULL,
  direccion VARCHAR(200) DEFAULT NULL,
  notas VARCHAR(500) DEFAULT NULL,
  validado TINYINT(1) NOT NULL DEFAULT 0,
  validado_por INT DEFAULT NULL,
  validado_en DATETIME DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_telefono (telefono),
  FOREIGN KEY (validado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ─── Citas ───
CREATE TABLE IF NOT EXISTS citas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  servicio_id INT NOT NULL,
  manicurista_id INT DEFAULT NULL,
  fecha DATE NOT NULL,
  hora TIME NOT NULL,
  estado_id INT NOT NULL DEFAULT 2,
  codigo_pago VARCHAR(20) DEFAULT NULL,
  grupo_id VARCHAR(20) DEFAULT NULL,
  comprobante VARCHAR(100) DEFAULT NULL,
  notas VARCHAR(255) DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  creado_por INT DEFAULT NULL,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  FOREIGN KEY (servicio_id) REFERENCES servicios(id),
  FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  FOREIGN KEY (estado_id) REFERENCES estados_cita(id),
  FOREIGN KEY (creado_por) REFERENCES usuarios(id),
  INDEX idx_grupo (grupo_id)
) ENGINE=InnoDB;

-- ─── Cobros (un total por reserva; forma de pago; fecha = registro; manicurista) ───
CREATE TABLE IF NOT EXISTS cobros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_id VARCHAR(20) DEFAULT NULL,
  cita_id INT DEFAULT NULL,
  cliente_id INT NOT NULL,
  manicurista_id INT DEFAULT NULL,
  monto DECIMAL(10,0) NOT NULL,
  descuento DECIMAL(10,0) DEFAULT NULL,
  forma_pago VARCHAR(20) NOT NULL,
  cupon_id INT DEFAULT NULL,
  servicios_realizados VARCHAR(500) DEFAULT NULL,
  fecha_pago DATE NOT NULL,
  registrado_por INT DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
  INDEX idx_grupo (grupo_id),
  INDEX idx_cita (cita_id),
  INDEX idx_fecha (fecha_pago),
  INDEX idx_cupon (cupon_id)
) ENGINE=InnoDB;

-- ─── Auditoría de citas (creación, reprogramación, estado) ───
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

-- ─── Pagos a manicuristas (registro manual) ───
CREATE TABLE IF NOT EXISTS pagos_manicuristas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manicurista_id INT NOT NULL,
  monto DECIMAL(10,0) NOT NULL,
  fecha_pago DATE NOT NULL,
  concepto VARCHAR(150) DEFAULT NULL,
  registrado_por INT NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ─── Inventario ───
CREATE TABLE IF NOT EXISTS productos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  categoria VARCHAR(60) DEFAULT NULL,
  ubicacion VARCHAR(60) DEFAULT NULL,
  marca VARCHAR(60) DEFAULT NULL,
  unidad VARCHAR(20) NOT NULL DEFAULT 'unidad',
  valor DECIMAL(10,0) DEFAULT NULL,
  fecha_compra DATE DEFAULT NULL,
  stock INT NOT NULL DEFAULT 0,
  stock_minimo INT NOT NULL DEFAULT 3,
  actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ubicacion (ubicacion)
) ENGINE=InnoDB;

-- Categorías reutilizables de inventario
CREATE TABLE IF NOT EXISTS categorias_producto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO categorias_producto (nombre) VALUES
  ('Uñas - manos'), ('Uñas - pies'), ('Peluquería'), ('Tintura'),
  ('Keratina'), ('Pestañas'), ('Cafetería y bebidas'), ('Desechables'),
  ('Bioseguridad'), ('Aseo y limpieza'), ('Herramientas y equipos')
ON DUPLICATE KEY UPDATE nombre = nombre;

-- Ubicaciones reutilizables (lugares físicos donde se guarda cada producto)
CREATE TABLE IF NOT EXISTS ubicaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL UNIQUE,
  orden  INT NOT NULL DEFAULT 100
) ENGINE=InnoDB;

INSERT INTO ubicaciones (nombre, orden) VALUES
  ('Esmaltero semipermanente',10), ('Esmaltero gato y painting',20),
  ('Esmaltero secado rápido',30), ('Esmaltero tradicional',40),
  ('Recepción',50), ('Pestañas',60)
ON DUPLICATE KEY UPDATE nombre = nombre;

INSERT INTO productos (nombre, categoria, unidad, stock, stock_minimo) VALUES
  ('Café', 'Cafetería y bebidas', 'libra', 0, 2),
  ('Aromáticas', 'Cafetería y bebidas', 'caja', 0, 2),
  ('Agua (botellón)', 'Cafetería y bebidas', 'botellón', 0, 1);

-- ─── Galería ───
CREATE TABLE IF NOT EXISTS galeria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  archivo VARCHAR(150) NOT NULL,
  titulo VARCHAR(100) DEFAULT NULL,
  subido_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── Promociones (se muestran el día anterior y el día de la promo) ───
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

-- ─── Fidelidad: tarjetas virtuales ───
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
  cobro_id INT DEFAULT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),
  FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
  FOREIGN KEY (cobro_id) REFERENCES cobros(id) ON DELETE CASCADE,
  INDEX idx_cobro (cobro_id)
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

-- ─── Control de tasa (anti-barrido) para endpoints públicos ───
CREATE TABLE IF NOT EXISTS rate_limit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  endpoint VARCHAR(40) NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_ep (ip, endpoint, creado_en)
) ENGINE=InnoDB;

-- ─── Configuración del local (paramétrica clave/valor) ───
CREATE TABLE IF NOT EXISTS configuracion (
  clave VARCHAR(50) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO configuracion (clave, valor) VALUES
  ('direccion','Calle 152 # 116-62 Local 9'),
  ('telefono','+57 3222773886'),
  ('whatsapp','573222773886'),
  ('instagram','@Sailor_Nails_Spa'),
  ('horario','Lun–Vie 10:00 am – 7:00 pm · Sáb 9:00 am – 7:00 pm'),
  ('cuenta_pago','Nequi 300 000 0000 · Sailor Nails Spa'),
  ('atencion_lv_inicio','10:00'),
  ('atencion_lv_fin','19:00'),
  ('atencion_sab_inicio','09:00'),
  ('atencion_sab_fin','19:00'),
  ('sitio_url',''),
  ('ciudad','Bogotá'),
  ('fidelidad_visitas','5'),
  ('fidelidad_descuento','10'),
  ('fidelidad_max_cupones','2'),
  ('fidelidad_vigencia_dias','30')
ON DUPLICATE KEY UPDATE valor=VALUES(valor);
