-- A1 (docs/AUDITORIA.md): verificación del correo. Idempotente.
--
-- Sin verificar, quien se registra con el correo de otra persona recibía sus invitaciones a espacios compartidos y podía hacer que
-- Lucas le enviara correo. Ahora: aceptar/enviar invitaciones y recibir notificaciones por correo exigen `correo_verificado_en`.
--
-- Las cuentas EXISTENTES quedan sin verificar (NULL) salvo los administradores, que solo se crean por consola o por la seed de
-- desarrollo. Si confías en todas tus cuentas actuales y no quieres que verifiquen, ejecuta a mano:
--   UPDATE usuarios SET correo_verificado_en = creado_en WHERE correo_verificado_en IS NULL;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'correo_verificado_en') = 0, 'ALTER TABLE usuarios ADD COLUMN correo_verificado_en DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

UPDATE usuarios SET correo_verificado_en = COALESCE(creado_en, NOW()) WHERE rol = 'admin' AND correo_verificado_en IS NULL;

-- Enlaces de verificación: solo se guarda el HASH del token (256 bits), vale 48 horas y se usa una vez. Guarda el correo que se verifica.
CREATE TABLE IF NOT EXISTS verificaciones_correo (
  id_verificacion INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario      INT NOT NULL,
  correo          VARCHAR(120) NOT NULL,
  token_hash      CHAR(64) NOT NULL UNIQUE,
  creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en       DATETIME NOT NULL,
  usado_en        DATETIME NULL DEFAULT NULL,
  INDEX idx_verif_usuario (id_usuario),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);
