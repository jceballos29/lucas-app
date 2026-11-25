CREATE TABLE IF NOT EXISTS deudas (
  id_deuda         INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario       INT NOT NULL,
  id_espacio       INT NULL,
  nombre           VARCHAR(80) NOT NULL,
  acreedor         VARCHAR(80),
  monto_inicial    DECIMAL(12,2) NOT NULL CHECK (monto_inicial > 0),
  tasa_mensual_bps INT NOT NULL DEFAULT 0 CHECK (tasa_mensual_bps BETWEEN 0 AND 2000),
  cuota_minima     DECIMAL(12,2) NOT NULL CHECK (cuota_minima > 0),
  dia_pago         TINYINT CHECK (dia_pago BETWEEN 1 AND 31),
  estado           ENUM('activa','pagada') NOT NULL DEFAULT 'activa',
  creado_en        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pagos_deuda (
  id_pago       INT AUTO_INCREMENT PRIMARY KEY,
  id_deuda      INT NOT NULL,
  id_cuenta     INT NOT NULL,
  id_usuario    INT NOT NULL,
  monto_total   DECIMAL(12,2) NOT NULL CHECK (monto_total > 0),
  abono_capital DECIMAL(12,2) NOT NULL CHECK (abono_capital >= 0),
  pago_interes  DECIMAL(12,2) NOT NULL CHECK (pago_interes >= 0),
  fecha         DATE NOT NULL,
  descripcion   VARCHAR(150),
  creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_deuda)   REFERENCES deudas(id_deuda) ON DELETE CASCADE,
  FOREIGN KEY (id_cuenta)  REFERENCES cuentas(id_cuenta),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);
