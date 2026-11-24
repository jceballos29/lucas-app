-- Primera seed (solo se ejecuta con el volumen de MySQL vacío). Ver docs/BASE-DE-DATOS.md, «Seed».

-- Categorías predeterminadas (RF-08): id_usuario NULL = visibles para todos, editables solo por un admin (RF-34).
INSERT INTO categorias (id_usuario, nombre, tipo, color) VALUES
  (NULL, 'Alimentación',          'gasto',   '#E4572E'),
  (NULL, 'Transporte',            'gasto',   '#F3A712'),
  (NULL, 'Vivienda',              'gasto',   '#7A5195'),
  (NULL, 'Servicios',             'gasto',   '#4C78A8'),
  (NULL, 'Salud',                 'gasto',   '#D1495B'),
  (NULL, 'Educación',             'gasto',   '#0B3A53'),
  (NULL, 'Entretenimiento',       'gasto',   '#EF8354'),
  (NULL, 'Otros gastos',          'gasto',   '#8DA0AB'),
  (NULL, 'Salario',               'ingreso', '#2E9E6B'),
  (NULL, 'Ventas / independiente','ingreso', '#0FA3B1'),
  (NULL, 'Intereses',             'ingreso', '#4C78A8'),
  (NULL, 'Otros ingresos',        'ingreso', '#8DA0AB');

-- Administrador inicial: SOLO DESARROLLO. Clave conocida y pública «Admin#2026dev» (aquí solo su hash bcrypt).
-- Cámbiala o crea otro admin con scripts/create-admin.php antes de exponer la app (RNF-23).
INSERT IGNORE INTO usuarios (nombre, correo, contrasena_hash, rol, activo) VALUES
  ('Administrador', 'admin@lucas.local', '$2y$12$sIpi.EhC27iIqx2CgHUppuMiS8/IP67c5bVybuAVVIQ/jxBd/4Una', 'admin', 1);
