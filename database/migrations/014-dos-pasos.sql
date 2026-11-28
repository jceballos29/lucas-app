-- M7 (docs/AUDITORIA.md): autenticación en dos pasos (TOTP) con códigos de recuperación. Idempotente.
--   totp_secreto      secreto TOTP CIFRADO (libsodium, clave APP_KEY); NULL = sin 2FA.
--   totp_activado_en  cuándo se confirmó con un código; NULL = 2FA no activo.
--   totp_ultimo_paso  último paso de 30 s aceptado: un código ya usado no sirve otra vez (anti-repetición).

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'totp_secreto') = 0, 'ALTER TABLE usuarios ADD COLUMN totp_secreto VARCHAR(255) NULL DEFAULT NULL, ADD COLUMN totp_activado_en DATETIME NULL DEFAULT NULL, ADD COLUMN totp_ultimo_paso BIGINT NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Códigos de recuperación de un solo uso (si se pierde el teléfono). Solo se guarda el HASH (SHA-256) de cada código (80 bits).
CREATE TABLE IF NOT EXISTS codigos_recuperacion (
  id_codigo   INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario  INT NOT NULL,
  codigo_hash CHAR(64) NOT NULL,
  creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usado_en    DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uq_codigo_usuario (id_usuario, codigo_hash),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);
