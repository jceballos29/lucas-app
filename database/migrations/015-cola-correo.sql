-- M4 (docs/AUDITORIA.md): cola de correo. La petición web solo inserta la fila; un proceso aparte (scripts/enviar-cola.php) la envía,
-- con reintentos y espera creciente. Idempotente.
--   estado: pendiente → enviado | fallido (tras agotar los intentos).
--   El cuerpo puede llevar enlaces con token (restablecer contraseña, verificar correo): se BORRA en cuanto el correo sale o se da por perdido.
CREATE TABLE IF NOT EXISTS cola_correo (
  id_correo       INT AUTO_INCREMENT PRIMARY KEY,
  destinatario    VARCHAR(190) NOT NULL,
  asunto          VARCHAR(255) NOT NULL,
  cuerpo          MEDIUMTEXT NOT NULL,
  estado          ENUM('pendiente','enviado','fallido') NOT NULL DEFAULT 'pendiente',
  intentos        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  proximo_intento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_error    VARCHAR(255) NULL DEFAULT NULL,
  creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  procesado_en    DATETIME NULL DEFAULT NULL,
  INDEX idx_cola_pendiente (estado, proximo_intento)
);
