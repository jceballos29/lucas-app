-- Administrador de DESARROLLO. Clave conocida y pública «Admin#2026dev» (aquí solo su hash bcrypt).
--
-- Este archivo vive en una SUBCARPETA a propósito: la imagen de MySQL solo ejecuta los archivos de primer nivel de
-- docker-entrypoint-initdb.d, así que nunca se carga solo. Lo aplica database/04-dev-admin.sh, y únicamente cuando el servicio
-- db recibe APP_ENV=local (solo lo define docker-compose.yml de desarrollo; docker-compose.prod.yml NO). También lo carga
-- src/tests/integration.php en su base temporal.
--
-- Para un entorno real crea el administrador con scripts/create-admin.php (docs/DESPLIEGUE.md).
SET NAMES utf8mb4;

INSERT IGNORE INTO usuarios (nombre, correo, contrasena_hash, rol, activo, correo_verificado_en) VALUES
  ('Administrador', 'admin@lucas.local', '$2y$12$sIpi.EhC27iIqx2CgHUppuMiS8/IP67c5bVybuAVVIQ/jxBd/4Una', 'admin', 1, NOW());
