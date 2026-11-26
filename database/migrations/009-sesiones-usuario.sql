CREATE TABLE IF NOT EXISTS sesiones_usuario (
  id_sesion  CHAR(64) PRIMARY KEY,
  id_usuario INT NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NOT NULL,
  creado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ses_usuario_uso (id_usuario, ultimo_uso),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);
