-- ═══════════════════════════════════════════════════════════════════════════
--  Sailor Nails Spa · Instalación completa (MVP 1)
--  Base de datos sns_principal · MySQL 8 / MariaDB · utf8mb4
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Script ÚNICO para montar el sistema en un servidor nuevo. Reemplaza a
--  sns_principal.sql y a todos los sql/actualizacion_*.sql: ya los incluye.
--
--  CÓMO EJECUTARLO
--    mysql -u USUARIO -p < sns_instalacion_completa.sql
--    (o pegándolo en phpMyAdmin → Importar)
--
--  QUÉ CREA
--    · Las 24 tablas del sistema, con sus llaves e índices.
--    · Catálogos y parámetros del local (servicios, horarios, fidelidad, bono).
--    · El personal ya configurado, con sus claves y horarios actuales.
--
--  QUÉ NO TRAE (arranca limpio)
--    Clientas, citas, cobros, tarjetas de fidelidad, préstamos, liquidaciones,
--    asistencia, bloqueos, días especiales, promociones y galería.
--
--  ⚠ Este archivo contiene los hashes de las claves del personal.
--    Bórralo del servidor una vez ejecutado y no lo publiques en un repo abierto.
--
--  DESPUÉS DE EJECUTARLO
--    1. Edita config/db.php con los datos del servidor nuevo (host, usuario, clave).
--    2. Borra instalar.php del servidor: este script ya hizo su trabajo.
--    3. Crea la carpeta uploads/galeria/ con permisos de escritura.
--    4. Entra a admin/login.php con el usuario "admin" y su clave actual.
--
--  Se usa utf8mb4_unicode_ci (no el 0900 de MySQL 8) para que corra igual en
--  MySQL 5.7, MySQL 8 y MariaDB.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS sns_principal
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sns_principal;

SET NAMES utf8mb4;


-- ───────────────────────────────────────────────────────────────────────────
--  1. PERSONAL Y AGENDA
-- ───────────────────────────────────────────────────────────────────────────

-- Usuarios del panel. porcentaje_comision solo aplica a las manicuristas:
-- es el % que se les paga sobre lo cobrado por los servicios que atendieron.
CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  usuario VARCHAR(50) NOT NULL,
  clave_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','recepcion','manicurista') NOT NULL DEFAULT 'manicurista',
  telefono VARCHAR(30) DEFAULT NULL,
  porcentaje_comision DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horario semanal de cada manicurista. dia_semana: 1=Lunes … 7=Domingo (ISO).
CREATE TABLE disponibilidad (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  dia_semana TINYINT NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  UNIQUE KEY uq_usuario_dia (usuario_id, dia_semana),
  CONSTRAINT disponibilidad_ibfk_1 FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Excepciones del local: festivos (abierto=0) o aperturas especiales (abierto=1).
CREATE TABLE dias_especiales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  abierto TINYINT(1) NOT NULL,
  hora_inicio TIME DEFAULT NULL,
  hora_fin TIME DEFAULT NULL,
  titulo VARCHAR(100) DEFAULT NULL,
  nota VARCHAR(255) DEFAULT NULL,
  creado_por INT DEFAULT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY fecha (fecha),
  KEY creado_por (creado_por),
  CONSTRAINT dias_especiales_ibfk_1 FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ausencias por manicurista. hora_inicio/hora_fin en NULL = todo el día.
CREATE TABLE bloqueos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  fecha DATE NOT NULL,
  hora_inicio TIME DEFAULT NULL,
  hora_fin TIME DEFAULT NULL,
  motivo VARCHAR(150) DEFAULT NULL,
  creado_por INT DEFAULT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY creado_por (creado_por),
  KEY idx_usuario_fecha (usuario_id, fecha),
  CONSTRAINT bloqueos_ibfk_1 FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT bloqueos_ibfk_2 FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────────────────
--  2. CATÁLOGOS
-- ───────────────────────────────────────────────────────────────────────────

CREATE TABLE estados_cita (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT 'secondary'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE servicios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  descripcion VARCHAR(255) DEFAULT NULL,
  precio DECIMAL(10,0) NOT NULL DEFAULT 0,
  duracion_min INT NOT NULL DEFAULT 60,
  orden INT NOT NULL DEFAULT 999,          -- menor número = aparece primero en el sitio
  suma_fidelidad TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = sella la tarjeta de fidelidad
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramétrica clave/valor: datos del local, horario, fidelidad y bono de nómina.
CREATE TABLE configuracion (
  clave VARCHAR(50) NOT NULL PRIMARY KEY,
  valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────────────────
--  3. CLIENTAS Y CITAS
-- ───────────────────────────────────────────────────────────────────────────

-- El teléfono IDENTIFICA a la clienta: con él se la reconoce al agendar y con
-- él se enlaza su tarjeta de fidelidad. Por eso es único a nivel de base, y la
-- aplicación exige exactamente 10 dígitos sin indicativo.
CREATE TABLE clientes (
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
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_telefono (telefono),
  KEY fk_clientes_validado_por (validado_por),
  CONSTRAINT fk_clientes_validado_por FOREIGN KEY (validado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- grupo_id agrupa los servicios de una misma reserva.
CREATE TABLE citas (
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
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  creado_por INT DEFAULT NULL,
  KEY cliente_id (cliente_id),
  KEY servicio_id (servicio_id),
  KEY manicurista_id (manicurista_id),
  KEY estado_id (estado_id),
  KEY fk_citas_creado_por (creado_por),
  KEY idx_grupo (grupo_id),
  CONSTRAINT citas_ibfk_1 FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  CONSTRAINT citas_ibfk_2 FOREIGN KEY (servicio_id) REFERENCES servicios(id),
  CONSTRAINT citas_ibfk_3 FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  CONSTRAINT citas_ibfk_4 FOREIGN KEY (estado_id) REFERENCES estados_cita(id),
  CONSTRAINT fk_citas_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora: creación, reprogramación y cambios de estado. usuario_id NULL = la clienta desde la web.
CREATE TABLE citas_auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cita_id INT NOT NULL,
  usuario_id INT DEFAULT NULL,
  accion VARCHAR(20) NOT NULL,
  fecha_anterior DATE DEFAULT NULL,
  hora_anterior TIME DEFAULT NULL,
  fecha_nueva DATE DEFAULT NULL,
  hora_nueva TIME DEFAULT NULL,
  detalle VARCHAR(255) DEFAULT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY usuario_id (usuario_id),
  KEY idx_cita (cita_id),
  CONSTRAINT citas_auditoria_ibfk_1 FOREIGN KEY (cita_id) REFERENCES citas(id),
  CONSTRAINT citas_auditoria_ibfk_2 FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────────────────
--  4. FIDELIDAD Y COBROS
--  Orden obligado: tarjetas → cupones → cobros → visitas_tarjeta
--  (cobros referencia el cupón aplicado, y la visita referencia el cobro).
-- ───────────────────────────────────────────────────────────────────────────

CREATE TABLE tarjetas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(30) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  codigo VARCHAR(8) NOT NULL,
  abierta TINYINT(1) NOT NULL DEFAULT 1,
  creada_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  cerrada_en DATETIME DEFAULT NULL,
  KEY idx_telefono (telefono)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cupones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id INT NOT NULL,
  descuento_pct INT NOT NULL,
  generado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  vence_el DATE NOT NULL,
  usado_en DATETIME DEFAULT NULL,
  usado_por INT DEFAULT NULL,
  KEY tarjeta_id (tarjeta_id),
  KEY usado_por (usado_por),
  CONSTRAINT cupones_ibfk_1 FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),
  CONSTRAINT cupones_ibfk_2 FOREIGN KEY (usado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un cobro por reserva. monto ya viene NETO (con el cupón descontado): es la
-- base sobre la que se calcula la comisión de la manicurista.
CREATE TABLE cobros (
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
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY cliente_id (cliente_id),
  KEY manicurista_id (manicurista_id),
  KEY registrado_por (registrado_por),
  KEY idx_grupo (grupo_id),
  KEY idx_cita (cita_id),
  KEY idx_fecha (fecha_pago),
  KEY idx_cupon (cupon_id),
  CONSTRAINT cobros_ibfk_1 FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  CONSTRAINT cobros_ibfk_2 FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  CONSTRAINT cobros_ibfk_3 FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
  CONSTRAINT fk_cobro_cupon FOREIGN KEY (cupon_id) REFERENCES cupones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si se elimina el cobro, su visita de fidelidad se va con él (ON DELETE CASCADE).
CREATE TABLE visitas_tarjeta (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tarjeta_id INT NOT NULL,
  fecha DATE NOT NULL,
  registrado_por INT NOT NULL,
  cobro_id INT DEFAULT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY tarjeta_id (tarjeta_id),
  KEY registrado_por (registrado_por),
  KEY idx_cobro (cobro_id),
  CONSTRAINT fk_visita_cobro FOREIGN KEY (cobro_id) REFERENCES cobros(id) ON DELETE CASCADE,
  CONSTRAINT visitas_tarjeta_ibfk_1 FOREIGN KEY (tarjeta_id) REFERENCES tarjetas(id),
  CONSTRAINT visitas_tarjeta_ibfk_2 FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────────────────
--  5. NÓMINA Y PRÉSTAMOS
--  Corte quincenal: 1–15 y 16–fin de mes.
--    comisión  = % de la manicurista sobre lo cobrado de los servicios que atendió
--    bono      = 120.000 por quincena, menos 8.000 por cada día programado que faltó
--    préstamos = se descuentan del neto al liquidar el corte
-- ───────────────────────────────────────────────────────────────────────────

-- Solo se registran las EXCEPCIONES: un día programado sin fila aquí se asume
-- asistido. asistio=0 descuenta el valor del día del bono quincenal.
CREATE TABLE asistencia (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manicurista_id INT NOT NULL,
  fecha DATE NOT NULL,
  asistio TINYINT(1) NOT NULL DEFAULT 0,
  motivo VARCHAR(150) DEFAULT NULL,
  registrado_por INT DEFAULT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mani_fecha (manicurista_id, fecha),
  KEY registrado_por (registrado_por),
  KEY idx_fecha (fecha),
  CONSTRAINT asistencia_ibfk_1 FOREIGN KEY (manicurista_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT asistencia_ibfk_2 FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Congela el cálculo del corte: un cobro registrado después no altera el histórico.
CREATE TABLE liquidaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manicurista_id INT NOT NULL,
  periodo_inicio DATE NOT NULL,
  periodo_fin DATE NOT NULL,
  base_servicios DECIMAL(10,0) NOT NULL DEFAULT 0,
  porcentaje DECIMAL(5,2) NOT NULL,
  comision DECIMAL(10,0) NOT NULL DEFAULT 0,
  dias_programados INT NOT NULL DEFAULT 0,
  dias_falta INT NOT NULL DEFAULT 0,
  bono DECIMAL(10,0) NOT NULL DEFAULT 0,
  ajuste DECIMAL(10,0) NOT NULL DEFAULT 0,
  descuento_prestamos DECIMAL(10,0) NOT NULL DEFAULT 0,
  neto DECIMAL(10,0) NOT NULL DEFAULT 0,
  nota VARCHAR(255) DEFAULT NULL,
  liquidado_por INT NOT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mani_periodo (manicurista_id, periodo_inicio, periodo_fin),
  KEY liquidado_por (liquidado_por),
  KEY idx_periodo (periodo_inicio, periodo_fin),
  CONSTRAINT liquidaciones_ibfk_1 FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  CONSTRAINT liquidaciones_ibfk_2 FOREIGN KEY (liquidado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El pago que sale de caja. liquidacion_id NULL = pago suelto fuera de nómina.
CREATE TABLE pagos_manicuristas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manicurista_id INT NOT NULL,
  monto DECIMAL(10,0) NOT NULL,
  fecha_pago DATE NOT NULL,
  concepto VARCHAR(150) DEFAULT NULL,
  liquidacion_id INT DEFAULT NULL,
  registrado_por INT NOT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY manicurista_id (manicurista_id),
  KEY registrado_por (registrado_por),
  KEY fk_pagos_liquidacion (liquidacion_id),
  CONSTRAINT fk_pagos_liquidacion FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE SET NULL,
  CONSTRAINT pagos_manicuristas_ibfk_1 FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  CONSTRAINT pagos_manicuristas_ibfk_2 FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- saldo = lo que falta por descontar. cupo_al_prestar guarda cuánto llevaba
-- ganado en el corte al autorizarlo (auditoría).
CREATE TABLE prestamos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  manicurista_id INT NOT NULL,
  monto DECIMAL(10,0) NOT NULL,
  saldo DECIMAL(10,0) NOT NULL,
  fecha DATE NOT NULL,
  motivo VARCHAR(200) DEFAULT NULL,
  estado ENUM('pendiente','pagado','anulado') NOT NULL DEFAULT 'pendiente',
  cupo_al_prestar DECIMAL(10,0) DEFAULT NULL,
  autorizado_por INT NOT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY autorizado_por (autorizado_por),
  KEY idx_mani_estado (manicurista_id, estado),
  KEY idx_fecha (fecha),
  CONSTRAINT prestamos_ibfk_1 FOREIGN KEY (manicurista_id) REFERENCES usuarios(id),
  CONSTRAINT prestamos_ibfk_2 FOREIGN KEY (autorizado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- liquidacion_id NULL = abono manual (en efectivo, fuera de nómina).
CREATE TABLE prestamos_abonos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  prestamo_id INT NOT NULL,
  monto DECIMAL(10,0) NOT NULL,
  fecha DATE NOT NULL,
  liquidacion_id INT DEFAULT NULL,
  registrado_por INT NOT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY liquidacion_id (liquidacion_id),
  KEY registrado_por (registrado_por),
  KEY idx_prestamo (prestamo_id),
  CONSTRAINT prestamos_abonos_ibfk_1 FOREIGN KEY (prestamo_id) REFERENCES prestamos(id) ON DELETE CASCADE,
  CONSTRAINT prestamos_abonos_ibfk_2 FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE SET NULL,
  CONSTRAINT prestamos_abonos_ibfk_3 FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────────────────
--  6. INVENTARIO, CONTENIDO Y SEGURIDAD
-- ───────────────────────────────────────────────────────────────────────────

CREATE TABLE categorias_producto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  UNIQUE KEY nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ubicaciones físicas del inventario (esmalteros, recepción…): dónde está cada producto.
CREATE TABLE ubicaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  orden  INT NOT NULL DEFAULT 100,
  UNIQUE KEY nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE productos (
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
  actualizado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ubicacion (ubicacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galeria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  archivo VARCHAR(150) NOT NULL,
  titulo VARCHAR(100) DEFAULT NULL,
  subido_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se muestran el día anterior y el día de la promoción.
CREATE TABLE promociones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  descripcion VARCHAR(500) DEFAULT NULL,
  fecha DATE NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_por INT DEFAULT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY creado_por (creado_por),
  KEY idx_fecha (fecha),
  CONSTRAINT promociones_ibfk_1 FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anti-barrido de los endpoints públicos (se limpia sola).
CREATE TABLE rate_limit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  endpoint VARCHAR(40) NOT NULL,
  creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_ep (ip, endpoint, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════════════
--  DATOS INICIALES
-- ═══════════════════════════════════════════════════════════════════════════

-- ── Estados de cita ──
INSERT INTO estados_cita (id, nombre, color) VALUES
  (1,'Esperando pago','warning'),
  (2,'Confirmada','info'),
  (3,'Atendida','success'),
  (4,'Cancelada','danger');

-- ── Servicios · lista de precios vigente ──
-- Los precios son "desde": el valor final puede subir según largo y diseño.
-- El nombre lleva la categoría adelante porque la lista trae repetidos
-- ("tradicional" y "semipermanente" existen en manicure y en pedicure).
-- La duración define los bloques de la agenda; se ajusta en Admin → Paramétricas.
INSERT INTO servicios (id, nombre, descripcion, precio, duracion_min, orden, suma_fidelidad, activo) VALUES
  -- Manicure
  (1,'Manicure tradicional','Limpieza, limado, cutícula y esmalte tradicional.',23000,45,10,0,1),
  (2,'Manicure tradicional secado rápido','Manicure tradicional con esmalte de secado rápido: sales lista en menos tiempo.',28000,45,10,0,1),
  (3,'Manicure semipermanente','Esmaltado en gel de larga duración, con brillo que dura semanas.',45000,60,10,1,1),
  (4,'Manicure base rubber','Base rubber que refuerza y nivela la uña natural, con acabado semipermanente.',58000,75,10,1,1),
  (5,'Manicure soft gel o press-on','Extensiones soft gel o uñas press-on: largo y forma a tu gusto en una sola cita.',90000,90,10,1,1),
  -- Dipping
  (6,'Recubrimiento en polygel o acrílico','Recubrimiento que da resistencia y estructura a la uña, en polygel o acrílico.',80000,120,30,1,1),
  -- Retiros
  (7,'Retiro de semipermanente','Retiro cuidadoso del esmaltado en gel, sin maltratar la uña natural.',15000,30,40,0,1),
  (8,'Retiro de sistemas artificiales','Retiro de acrílico, polygel o soft gel, con limado e hidratación final.',25000,45,40,0,1),
  -- Pedicure
  (9,'Pedicure tradicional','Pedicure completo: limpieza, corte, cutícula y esmalte tradicional.',30000,45,20,0,1),
  (10,'Pedicure secado rápido','Pedicure completo con esmalte de secado rápido.',38000,45,20,0,1),
  (11,'Pedicure semipermanente','Pedicure con esmaltado en gel de larga duración.',50000,60,20,1,1),
  -- Hombres
  (12,'Manicure semipermanente para hombre','Manicure masculino con acabado semipermanente natural.',40000,45,50,1,1),
  (13,'Pedicure semipermanente para hombre','Pedicure masculino con acabado semipermanente natural.',45000,60,50,1,1),
  -- Depilación
  (14,'Depilación de bigote','Depilación del bozo, rápida y precisa.',10000,15,70,0,1),
  (15,'Depilación de cejas','Diseño y depilación de cejas según la forma de tu rostro.',15000,20,70,0,1),
  (16,'Depilación de axilas','Depilación completa de axilas.',25000,20,70,0,1),
  (17,'Depilación de pierna completa','Depilación de pierna completa, de la ingle al tobillo.',80000,45,70,0,1),
  (18,'Depilación de media pierna','Depilación de media pierna, de la rodilla al tobillo.',60000,30,70,0,1),
  -- Cejas y pestañas
  (19,'Cejas semipermanente','Diseño y pigmentación semipermanente de cejas, con efecto natural.',25000,45,60,0,1),
  (20,'Pestañas punto a punto','Extensiones pelo a pelo para una mirada definida y natural.',45000,90,60,0,1),
  -- Cabello
  (21,'Cepillado','Lavado y cepillado con secador para un acabado liso y con movimiento.',25000,30,80,0,1),
  (22,'Shampoo','Lavado del cabello con shampoo y acondicionador.',10000,20,80,0,1),
  (23,'Reforzamiento capilar','Tratamiento que repara y fortalece el cabello desde la raíz a las puntas.',70000,60,80,0,1),
  (24,'Peinado','Peinado para la ocasión que quieras: recogido, ondas o liso.',25000,45,80,0,1);

-- ── Parámetros del local, fidelidad y nómina ──
INSERT INTO configuracion (clave, valor) VALUES
  ('direccion','Calle 152 # 116-62 Local 9'),
  ('telefono','+57 3222773886'),
  ('whatsapp','573222773886'),
  ('instagram','@Sailor_Nails_Spa'),
  ('ciudad','Bogotá'),
  ('horario','Lun a Vie 10:00 am 7:00 pm y Sáb 9:00 am a 7:00 pm'),
  ('cuenta_pago','Nequi 322 277 38 86 - Sailor Nails Spa'),
  ('sitio_url',''),
  -- Horario de atención del local (acota los horarios de las manicuristas)
  ('atencion_lv_inicio','10:00'),
  ('atencion_lv_fin','19:00'),
  ('atencion_sab_inicio','09:00'),
  ('atencion_sab_fin','19:00'),
  -- Fidelidad: un cupón cada N servicios
  ('fidelidad_visitas','5'),
  ('fidelidad_descuento','10'),
  ('fidelidad_max_cupones','2'),
  ('fidelidad_vigencia_dias','60'),
  -- Nómina: bono quincenal y descuento por día no asistido
  ('bono_quincenal','120000'),
  ('bono_valor_dia','8000'),
  -- Galería: marca de agua grabada en las fotos publicadas
  ('marca_agua','1'),
  ('marca_agua_opacidad','70'),
  ('marca_agua_tamano','22');

-- ── Categorías de inventario ──
INSERT INTO categorias_producto (id, nombre) VALUES
  (1,'Uñas - manos'), (2,'Uñas - pies'), (3,'Peluquería'), (4,'Tintura'),
  (5,'Keratina'), (6,'Pestañas'), (7,'Cafetería y bebidas'), (8,'Desechables'),
  (9,'Bioseguridad'), (10,'Aseo y limpieza'), (11,'Herramientas y equipos');

-- ── Ubicaciones de inventario (lugares físicos donde se guarda cada producto) ──
INSERT INTO ubicaciones (nombre, orden) VALUES
  ('Esmaltero semipermanente',10), ('Esmaltero gato y painting',20),
  ('Esmaltero secado rápido',30), ('Esmaltero tradicional',40),
  ('Recepción',50), ('Pestañas',60);

-- ── Productos base de cafetería ──
INSERT INTO productos (nombre, categoria, unidad, stock, stock_minimo) VALUES
  ('Café','Cafetería y bebidas','libra',0,2),
  ('Aromáticas','Cafetería y bebidas','caja',0,2),
  ('Agua (botellón)','Cafetería y bebidas','botellón',0,1);

-- ── Personal ──
-- Se conservan las claves actuales de cada quien. Los ids no son consecutivos
-- a propósito: son los mismos del sistema actual, por si algún día se importan
-- los datos de operación (citas, cobros) y deben calzar las llaves foráneas.
INSERT INTO usuarios (id, nombre, usuario, clave_hash, rol, telefono, porcentaje_comision, activo) VALUES
  (1,'Administradora','admin','$2y$10$mTudvEnJM6Mg1kF.OOD75.qMA8.RbvaCc2m6iV3XmGK5Re88ie1bC','admin',NULL,50.00,1),
  (2,'Jennifer Rosero','jrosero','$2y$10$keNBBmLp64z.1qKIt2.uD.XwOsiSPpx3THW0ulvCUPq.KHr8QiKki','recepcion','3005299113',50.00,1),
  (3,'Valentina','valentina','$2y$10$pSzvU7OBWHdNb0FT/nN8UOkN17Wv5c.h85SEeFZxqTW13iwiwhZ2O','manicurista','3124784861',50.00,1),
  (24,'Lorena','lorena','$2y$10$EZATAR7PIUM7dfe9ePxEW.0KL4pRSVjopO4/s4Pkf2Mb7BaDym9tq','manicurista',NULL,50.00,1),
  (25,'Estefani','estefani','$2y$10$SCfyUVk81MI6Xr2iHq4ppuO1aWFaMweKQMfrmhy3d.fyuDHpzx4uG','manicurista',NULL,45.00,1);

-- ── Horarios de atención por manicurista (Lun–Vie 10–19, Sáb 9–19) ──
INSERT INTO disponibilidad (usuario_id, dia_semana, hora_inicio, hora_fin) VALUES
  (3,1,'10:00','19:00'),(3,2,'10:00','19:00'),(3,3,'10:00','19:00'),
  (3,4,'10:00','19:00'),(3,5,'10:00','19:00'),(3,6,'09:00','19:00'),
  (24,1,'10:00','19:00'),(24,2,'10:00','19:00'),(24,3,'10:00','19:00'),
  (24,4,'10:00','19:00'),(24,5,'10:00','19:00'),(24,6,'09:00','19:00'),
  (25,1,'10:00','19:00'),(25,2,'10:00','19:00'),(25,3,'10:00','19:00'),
  (25,4,'10:00','19:00'),(25,5,'10:00','19:00'),(25,6,'09:00','19:00');


-- ═══════════════════════════════════════════════════════════════════════════
--  COMPROBACIÓN
--  Debe devolver: 25 tablas · 5 usuarios · 18 horarios · 21 parámetros
--                 24 servicios · 4 estados · 11 categorías · 6 ubicaciones · 3 productos
-- ═══════════════════════════════════════════════════════════════════════════
SELECT
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'sns_principal') AS tablas,
  (SELECT COUNT(*) FROM usuarios)            AS usuarios,
  (SELECT COUNT(*) FROM disponibilidad)      AS horarios,
  (SELECT COUNT(*) FROM configuracion)       AS parametros,
  (SELECT COUNT(*) FROM servicios)           AS servicios,
  (SELECT COUNT(*) FROM estados_cita)        AS estados,
  (SELECT COUNT(*) FROM categorias_producto) AS categorias,
  (SELECT COUNT(*) FROM ubicaciones)         AS ubicaciones,
  (SELECT COUNT(*) FROM productos)           AS productos;
