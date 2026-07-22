-- Actualización del catálogo de servicios (lista de precios vigente).
-- Para una base que YA está en uso: reemplaza los servicios existentes en sitio,
-- sin borrarlos, porque las citas ya registradas los referencian.
-- Requiere las columnas `orden` y `suma_fidelidad`
-- (sql/actualizacion_orden_servicios.sql y sql/actualizacion_sello_fidelidad.sql).
-- En una instalación nueva NO se ejecuta: ya viene en sns_instalacion_completa.sql.
USE sns_principal;

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
  (24,'Peinado','Peinado para la ocasión que quieras: recogido, ondas o liso.',25000,45,80,0,1)
ON DUPLICATE KEY UPDATE
  nombre         = VALUES(nombre),
  descripcion    = VALUES(descripcion),
  precio         = VALUES(precio),
  duracion_min   = VALUES(duracion_min),
  orden          = VALUES(orden),
  suma_fidelidad = VALUES(suma_fidelidad),
  activo         = VALUES(activo);

-- Los servicios viejos que ya no están en la lista se desactivan (no se borran:
-- las citas y los cobros históricos deben poder seguir mostrando su nombre).
UPDATE servicios SET activo = 0 WHERE id > 24;

SELECT COUNT(*) AS servicios_activos, SUM(suma_fidelidad) AS sellan
FROM servicios WHERE activo = 1;
