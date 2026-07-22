-- Actualización: inventario con valor, fecha de compra y categorías reutilizables
-- Ejecutar UNA vez sobre la BD sns_principal existente.
USE sns_principal;

ALTER TABLE productos
  ADD COLUMN valor DECIMAL(10,0) DEFAULT NULL AFTER unidad,
  ADD COLUMN fecha_compra DATE DEFAULT NULL AFTER valor;

-- Categorías reutilizables (se van guardando a medida que se ingresan productos)
CREATE TABLE IF NOT EXISTS categorias_producto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO categorias_producto (nombre) VALUES
  ('Uñas - manos'), ('Uñas - pies'), ('Peluquería'), ('Tintura'),
  ('Keratina'), ('Pestañas'), ('Cafetería y bebidas'), ('Desechables'),
  ('Bioseguridad'), ('Aseo y limpieza'), ('Herramientas y equipos')
ON DUPLICATE KEY UPDATE nombre = nombre;

-- Backfill: categorías ya usadas en productos existentes
INSERT IGNORE INTO categorias_producto (nombre)
SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND categoria <> '';

-- Ítems de cafetería (se ofrecen gratis a la clienta pero tienen costo)
INSERT INTO productos (nombre, categoria, unidad, stock, stock_minimo)
SELECT * FROM (SELECT 'Café', 'Cafetería y bebidas', 'libra', 0, 2) x
WHERE NOT EXISTS (SELECT 1 FROM productos WHERE nombre = 'Café');
INSERT INTO productos (nombre, categoria, unidad, stock, stock_minimo)
SELECT * FROM (SELECT 'Aromáticas', 'Cafetería y bebidas', 'caja', 0, 2) x
WHERE NOT EXISTS (SELECT 1 FROM productos WHERE nombre = 'Aromáticas');
INSERT INTO productos (nombre, categoria, unidad, stock, stock_minimo)
SELECT * FROM (SELECT 'Agua (botellón)', 'Cafetería y bebidas', 'botellón', 0, 1) x
WHERE NOT EXISTS (SELECT 1 FROM productos WHERE nombre = 'Agua (botellón)');
