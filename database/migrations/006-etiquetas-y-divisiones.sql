SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'etiqueta') = 0, 'ALTER TABLE transacciones ADD COLUMN etiqueta VARCHAR(40) NULL, ADD INDEX idx_trans_etiqueta (id_usuario, etiqueta)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'grupo_split') = 0, 'ALTER TABLE transacciones ADD COLUMN grupo_split CHAR(16) NULL', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
