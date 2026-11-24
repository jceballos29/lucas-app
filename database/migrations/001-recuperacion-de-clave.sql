-- Fase 7: recuperación de contraseña (RF-04) y límite de intentos de recuperación y de cambio de clave (RNF-24).
-- Es IDEMPOTENTE: una base nueva ya lo trae en 01-schema.sql y aplicarla de nuevo no cambia nada.
-- Después de aplicarla en una base existente, vuelve a ejecutar 03-users.sh para dar permisos sobre la tabla nueva:
--   docker compose exec db bash /docker-entrypoint-initdb.d/03-users.sh

-- Enlaces de restablecimiento. Solo se guarda el HASH (SHA-256) del token: quien lea la BD no puede usarlo.
CREATE TABLE IF NOT EXISTS restablecimientos (
  id_restablecimiento INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario          INT NOT NULL,
  token_hash          CHAR(64) NOT NULL UNIQUE,
  creado_en           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en           TIMESTAMP NOT NULL,
  usado_en            TIMESTAMP NULL,
  INDEX idx_usuario (id_usuario),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);

ALTER TABLE intentos_acceso MODIFY tipo ENUM('login','registro','recuperacion','clave') NOT NULL;
