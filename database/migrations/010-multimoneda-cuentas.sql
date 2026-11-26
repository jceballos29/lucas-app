-- Fase 18: Multimoneda por cuenta, transferencias entre monedas y tasas de cambio de referencia.

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cuentas' AND column_name = 'moneda') = 0, 'ALTER TABLE cuentas ADD COLUMN moneda CHAR(3) NULL AFTER tipo', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transferencias' AND column_name = 'monto_destino') = 0, 'ALTER TABLE transferencias ADD COLUMN monto_destino DECIMAL(12,2) NULL CHECK (monto_destino > 0) AFTER monto', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS tasas_cambio (
  id_tasa        INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario     INT NOT NULL,
  id_espacio     INT NULL,
  moneda_origen  CHAR(3) NOT NULL,
  moneda_destino CHAR(3) NOT NULL,
  tasa_micros    BIGINT UNSIGNED NOT NULL CHECK (tasa_micros > 0),
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CHECK (moneda_origen <> moneda_destino),
  INDEX idx_tasa_ambito (id_usuario, id_espacio, moneda_origen, moneda_destino),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE
);
