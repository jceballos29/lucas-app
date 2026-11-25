-- Fase 9: Movimientos recurrentes y gastos fijos programados (idempotente).
CREATE TABLE IF NOT EXISTS movimientos_recurrentes (
  id_recurrente INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario    INT NOT NULL,
  id_cuenta     INT NOT NULL,
  id_categoria  INT NOT NULL,
  tipo          ENUM('ingreso','gasto') NOT NULL,
  monto         DECIMAL(12,2) NOT NULL CHECK (monto > 0),
  frecuencia    ENUM('semanal','quincenal','mensual','anual') NOT NULL DEFAULT 'mensual',
  proxima_fecha DATE NOT NULL,
  descripcion   VARCHAR(150),
  auto_aplicar  BOOLEAN NOT NULL DEFAULT TRUE,
  activo        BOOLEAN NOT NULL DEFAULT TRUE,
  creado_en     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_usuario)   REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_cuenta)    REFERENCES cuentas(id_cuenta),
  FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria),
  INDEX idx_rec_usuario_proxima (id_usuario, activo, proxima_fecha)
);
