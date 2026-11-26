-- Fase 19: Adjuntos de comprobantes (recibos/facturas) en transacciones.

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'adjunto_ruta') = 0, 'ALTER TABLE transacciones ADD COLUMN adjunto_ruta VARCHAR(120) NULL AFTER tasa_mensual_bps', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'adjunto_nombre') = 0, 'ALTER TABLE transacciones ADD COLUMN adjunto_nombre VARCHAR(120) NULL AFTER adjunto_ruta', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'adjunto_mime') = 0, 'ALTER TABLE transacciones ADD COLUMN adjunto_mime VARCHAR(60) NULL AFTER adjunto_nombre', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
