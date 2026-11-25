-- Fase 8: Transferencias entre cuentas propias y abono a tarjetas de crédito (idempotente).
CREATE TABLE IF NOT EXISTS transferencias (
  id_transferencia  INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario        INT NOT NULL,
  id_cuenta_origen  INT NOT NULL,
  id_cuenta_destino INT NOT NULL,
  monto             DECIMAL(12,2) NOT NULL CHECK (monto > 0),
  fecha             DATE NOT NULL,
  descripcion       VARCHAR(150),
  creado_en         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CHECK (id_cuenta_origen <> id_cuenta_destino),
  FOREIGN KEY (id_usuario)        REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_cuenta_origen)  REFERENCES cuentas(id_cuenta),
  FOREIGN KEY (id_cuenta_destino) REFERENCES cuentas(id_cuenta),
  INDEX idx_transf_usuario_fecha (id_usuario, fecha)
);
