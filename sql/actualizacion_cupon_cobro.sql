-- Actualización: aplicar cupón de fidelidad dentro del cobro
-- El monto guardado es el NETO cobrado; descuento e id de cupón quedan de registro.
USE sns_principal;

ALTER TABLE cobros
  ADD COLUMN descuento DECIMAL(10,0) DEFAULT NULL AFTER monto,
  ADD COLUMN cupon_id INT DEFAULT NULL AFTER forma_pago,
  ADD INDEX idx_cupon (cupon_id),
  ADD CONSTRAINT fk_cobro_cupon FOREIGN KEY (cupon_id) REFERENCES cupones(id);
