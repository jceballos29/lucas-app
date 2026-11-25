CREATE TABLE IF NOT EXISTS espacios (
  id_espacio INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(80) NOT NULL,
  id_creador INT NOT NULL,
  moneda     CHAR(3) NOT NULL DEFAULT 'COP',
  creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_creador) REFERENCES usuarios(id_usuario)
);

CREATE TABLE IF NOT EXISTS espacio_miembros (
  id_espacio INT NOT NULL,
  id_usuario INT NOT NULL,
  rol        ENUM('propietario','editor','lector') NOT NULL DEFAULT 'editor',
  unido_en   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_espacio, id_usuario),
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE,
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS espacio_invitaciones (
  id_invitacion INT AUTO_INCREMENT PRIMARY KEY,
  id_espacio    INT NOT NULL,
  correo        VARCHAR(120) NOT NULL,
  rol           ENUM('editor','lector') NOT NULL DEFAULT 'editor',
  token_hash    CHAR(64) NOT NULL UNIQUE,
  creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expira_en     TIMESTAMP NOT NULL,
  INDEX idx_esp_inv_correo (correo),
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE
);

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cuentas' AND column_name = 'id_espacio') = 0, 'ALTER TABLE cuentas ADD COLUMN id_espacio INT NULL, ADD CONSTRAINT fk_cuentas_espacio FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'categorias' AND column_name = 'id_espacio') = 0, 'ALTER TABLE categorias ADD COLUMN id_espacio INT NULL, ADD CONSTRAINT fk_categorias_espacio FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND column_name = 'id_espacio') = 0, 'ALTER TABLE transacciones ADD COLUMN id_espacio INT NULL, ADD CONSTRAINT fk_transacciones_espacio FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'presupuestos' AND column_name = 'id_espacio') = 0, 'ALTER TABLE presupuestos ADD COLUMN id_espacio INT NULL, ADD CONSTRAINT fk_presupuestos_espacio FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'metas_ahorro' AND column_name = 'id_espacio') = 0, 'ALTER TABLE metas_ahorro ADD COLUMN id_espacio INT NULL, ADD CONSTRAINT fk_metas_espacio FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transferencias' AND column_name = 'id_espacio') = 0, 'ALTER TABLE transferencias ADD COLUMN id_espacio INT NULL, ADD CONSTRAINT fk_transferencias_espacio FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'movimientos_recurrentes' AND column_name = 'id_espacio') = 0, 'ALTER TABLE movimientos_recurrentes ADD COLUMN id_espacio INT NULL, ADD CONSTRAINT fk_recurrentes_espacio FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
