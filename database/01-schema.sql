CREATE TABLE usuarios (
  id_usuario      INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(80)  NOT NULL,
  correo          VARCHAR(120) NOT NULL UNIQUE,
  contrasena_hash VARCHAR(255) NOT NULL,
  moneda          CHAR(3)      NOT NULL DEFAULT 'COP',
  rol             ENUM('usuario','admin') NOT NULL DEFAULT 'usuario',
  activo          BOOLEAN      NOT NULL DEFAULT TRUE,
  creado_en       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rol_activo (rol, activo)
);

CREATE TABLE espacios (
  id_espacio INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(80) NOT NULL,
  id_creador INT NOT NULL,
  moneda     CHAR(3) NOT NULL DEFAULT 'COP',
  creado_en  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_creador) REFERENCES usuarios(id_usuario)
);

CREATE TABLE espacio_miembros (
  id_espacio INT NOT NULL,
  id_usuario INT NOT NULL,
  rol        ENUM('propietario','editor','lector') NOT NULL DEFAULT 'editor',
  unido_en   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_espacio, id_usuario),
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE,
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);

CREATE TABLE espacio_invitaciones (
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

CREATE TABLE cuentas (
  id_cuenta        INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario       INT NOT NULL,
  id_espacio       INT NULL,
  nombre           VARCHAR(60) NOT NULL,
  tipo             ENUM('efectivo','bancaria','tarjeta_credito','ahorro') NOT NULL,
  saldo_inicial    DECIMAL(12,2) NOT NULL DEFAULT 0,
  dia_corte        TINYINT NULL CHECK (dia_corte BETWEEN 1 AND 31),
  dia_pago_limite  TINYINT NULL CHECK (dia_pago_limite BETWEEN 1 AND 31),
  tasa_mensual_bps INT NOT NULL DEFAULT 0 CHECK (tasa_mensual_bps BETWEEN 0 AND 2000),
  activa           BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE
);

CREATE TABLE categorias (
  id_categoria INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario   INT NULL,
  id_espacio   INT NULL,
  nombre       VARCHAR(50) NOT NULL,
  tipo         ENUM('ingreso','gasto') NOT NULL,
  color        CHAR(7) NOT NULL DEFAULT '#888888',
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE
);

CREATE TABLE transacciones (
  id_transaccion INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario     INT NOT NULL,
  id_espacio     INT NULL,
  id_cuenta      INT NOT NULL,
  id_categoria   INT NOT NULL,
  tipo           ENUM('ingreso','gasto') NOT NULL,
  monto          DECIMAL(12,2) NOT NULL CHECK (monto > 0),
  fecha          DATE NOT NULL,
  descripcion    VARCHAR(150),
  etiqueta         VARCHAR(40) NULL,
  grupo_split      CHAR(16) NULL,
  num_cuotas       TINYINT NOT NULL DEFAULT 1 CHECK (num_cuotas BETWEEN 1 AND 36),
  con_intereses    BOOLEAN NOT NULL DEFAULT TRUE,
  tasa_mensual_bps INT NOT NULL DEFAULT 0 CHECK (tasa_mensual_bps BETWEEN 0 AND 2000),
  creado_en        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_usuario)   REFERENCES usuarios(id_usuario)     ON DELETE CASCADE,
  FOREIGN KEY (id_espacio)   REFERENCES espacios(id_espacio)     ON DELETE CASCADE,
  FOREIGN KEY (id_cuenta)    REFERENCES cuentas(id_cuenta),
  FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria),
  INDEX idx_usuario_fecha (id_usuario, fecha),
  INDEX idx_trans_etiqueta (id_usuario, etiqueta)
);

CREATE TABLE cuotas_tarjeta (
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

CREATE TABLE presupuestos (
  id_presupuesto INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario     INT NOT NULL,
  id_espacio     INT NULL,
  id_categoria   INT NOT NULL,
  mes            TINYINT NOT NULL CHECK (mes BETWEEN 1 AND 12),
  anio           SMALLINT NOT NULL,
  monto_limite   DECIMAL(12,2) NOT NULL CHECK (monto_limite > 0),
  UNIQUE (id_usuario, id_categoria, mes, anio),
  FOREIGN KEY (id_usuario)   REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_espacio)   REFERENCES espacios(id_espacio) ON DELETE CASCADE,
  FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria)
);

CREATE TABLE metas_ahorro (
  id_meta        INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario     INT NOT NULL,
  id_espacio     INT NULL,
  nombre         VARCHAR(80) NOT NULL,
  monto_objetivo DECIMAL(12,2) NOT NULL CHECK (monto_objetivo > 0),
  fecha_limite   DATE,
  estado         ENUM('en_curso','lograda','cancelada') NOT NULL DEFAULT 'en_curso',
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE
);

CREATE TABLE aportes_meta (
  id_aporte INT AUTO_INCREMENT PRIMARY KEY,
  id_meta   INT NOT NULL,
  id_cuenta INT NOT NULL,
  monto     DECIMAL(12,2) NOT NULL CHECK (monto > 0),
  fecha     DATE NOT NULL,
  FOREIGN KEY (id_meta)   REFERENCES metas_ahorro(id_meta) ON DELETE CASCADE,
  FOREIGN KEY (id_cuenta) REFERENCES cuentas(id_cuenta)
);

CREATE TABLE deudas (
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

CREATE TABLE pagos_deuda (
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

CREATE TABLE transferencias (
  id_transferencia  INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario        INT NOT NULL,
  id_espacio        INT NULL,
  id_cuenta_origen  INT NOT NULL,
  id_cuenta_destino INT NOT NULL,
  monto             DECIMAL(12,2) NOT NULL CHECK (monto > 0),
  fecha             DATE NOT NULL,
  descripcion       VARCHAR(150),
  creado_en         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CHECK (id_cuenta_origen <> id_cuenta_destino),
  FOREIGN KEY (id_usuario)        REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_espacio)        REFERENCES espacios(id_espacio) ON DELETE CASCADE,
  FOREIGN KEY (id_cuenta_origen)  REFERENCES cuentas(id_cuenta),
  FOREIGN KEY (id_cuenta_destino) REFERENCES cuentas(id_cuenta),
  INDEX idx_transf_usuario_fecha (id_usuario, fecha)
);

CREATE TABLE movimientos_recurrentes (
  id_recurrente INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario    INT NOT NULL,
  id_espacio    INT NULL,
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
  FOREIGN KEY (id_espacio)   REFERENCES espacios(id_espacio) ON DELETE CASCADE,
  FOREIGN KEY (id_cuenta)    REFERENCES cuentas(id_cuenta),
  FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria),
  INDEX idx_rec_usuario_proxima (id_usuario, activo, proxima_fecha)
);

-- Intentos de acceso para limitar la fuerza bruta (RNF-24). `clave` no guarda el correo en claro:
-- es "ip:<dirección>" o "correo:<sha256 del correo>". Las filas viejas se borran al consultar.
CREATE TABLE intentos_acceso (
  id_intento INT AUTO_INCREMENT PRIMARY KEY,
  tipo       ENUM('login','registro','recuperacion','clave') NOT NULL,
  clave      VARCHAR(80) NOT NULL,
  creado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tipo_clave_fecha (tipo, clave, creado_en)
);

-- Enlaces de restablecimiento de contraseña (RF-04). Solo se guarda el HASH (SHA-256) del token.
CREATE TABLE restablecimientos (
  id_restablecimiento INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario          INT NOT NULL,
  token_hash          CHAR(64) NOT NULL UNIQUE,
  creado_en           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en           TIMESTAMP NOT NULL,
  usado_en            TIMESTAMP NULL,
  INDEX idx_usuario (id_usuario),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);
