-- Fase 20: Notificaciones por correo (alertas de presupuesto y resumen mensual). Idempotente.

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'notif_presupuestos') = 0, 'ALTER TABLE usuarios ADD COLUMN notif_presupuestos BOOLEAN NOT NULL DEFAULT FALSE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'notif_resumen_mensual') = 0, 'ALTER TABLE usuarios ADD COLUMN notif_resumen_mensual BOOLEAN NOT NULL DEFAULT FALSE', 'DO 0');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- Deduplicación e historial de envíos: `clave_dedup` única = como máximo un correo por clave.
CREATE TABLE IF NOT EXISTS notificaciones_enviadas (
  id_notificacion INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario      INT NOT NULL,
  id_espacio      INT NULL,
  clave_dedup     VARCHAR(100) NOT NULL UNIQUE,
  enviado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_usuario (id_usuario),
  FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
  FOREIGN KEY (id_espacio) REFERENCES espacios(id_espacio) ON DELETE CASCADE
);
