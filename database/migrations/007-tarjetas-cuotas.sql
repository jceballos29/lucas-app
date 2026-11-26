SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cuentas' AND column_name = 'dia_corte') = 0, 'ALTER TABLE cuentas ADD COLUMN dia_corte TINYINT NULL CHECK (dia_corte BETWEEN 1 AND 31)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cuentas' AND column_name = 'dia_pago_limite') = 0, 'ALTER TABLE cuentas ADD COLUMN dia_pago_limite TINYINT NULL CHECK (dia_pago_limite BETWEEN 1 AND 31)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'num_cuotas') = 0, 'ALTER TABLE transacciones ADD COLUMN num_cuotas TINYINT NOT NULL DEFAULT 1 CHECK (num_cuotas BETWEEN 1 AND 36)', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS cuotas_tarjeta (
  id_cuota       INT AUTO_INCREMENT PRIMARY KEY,
  id_transaccion INT NOT NULL,
  id_cuenta      INT NOT NULL,
  numero         TINYINT NOT NULL CHECK (numero BETWEEN 1 AND 36),
  fecha_cobro    DATE NOT NULL,
  monto_capital  DECIMAL(12,2) NOT NULL CHECK (monto_capital > 0),
  monto_interes  DECIMAL(12,2) NOT NULL DEFAULT 0.00 CHECK (monto_interes >= 0),
  monto_total    DECIMAL(12,2) NOT NULL CHECK (monto_total > 0),
  FOREIGN KEY (id_transaccion) REFERENCES transacciones(id_transaccion) ON DELETE CASCADE,
  FOREIGN KEY (id_cuenta)      REFERENCES cuentas(id_cuenta) ON DELETE CASCADE,
  INDEX idx_cuotas_cuenta_fecha (id_cuenta, fecha_cobro)
);
