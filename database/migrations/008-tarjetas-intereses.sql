SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cuentas' AND column_name = 'tasa_mensual_bps') = 0, 'ALTER TABLE cuentas ADD COLUMN tasa_mensual_bps INT NOT NULL DEFAULT 0 CHECK (tasa_mensual_bps BETWEEN 0 AND 2000)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'con_intereses') = 0, 'ALTER TABLE transacciones ADD COLUMN con_intereses BOOLEAN NOT NULL DEFAULT TRUE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'tasa_mensual_bps') = 0, 'ALTER TABLE transacciones ADD COLUMN tasa_mensual_bps INT NOT NULL DEFAULT 0 CHECK (tasa_mensual_bps BETWEEN 0 AND 2000)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
