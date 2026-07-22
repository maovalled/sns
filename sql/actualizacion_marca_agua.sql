-- Marca de agua en las fotos de la galería.
-- La marca queda GRABADA en el archivo publicado, así que quien descargue la
-- foto desde el sitio se la lleva con la marca. El original sin marca se guarda
-- en uploads/galeria/originales/ (bloqueado por .htaccess).
-- Ejecutar UNA vez (en una instalación nueva ya viene incluida).
USE sns_principal;

INSERT INTO configuracion (clave, valor) VALUES
  ('marca_agua','1'),            -- 1 = activada, 0 = desactivada
  ('marca_agua_opacidad','70'),  -- 10 a 100 (por debajo de 60 se pierde en fondos claros)
  ('marca_agua_tamano','22')     -- ancho como % del lado menor de la foto (5 a 40)
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

SELECT clave, valor FROM configuracion WHERE clave LIKE 'marca_agua%' ORDER BY clave;
