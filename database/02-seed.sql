-- Primera seed (solo se ejecuta con el volumen de MySQL vacío). Ver docs/BASE-DE-DATOS.md, «Seed».

-- El cliente mysql de la imagen arranca en latin1 (sin LANG) y guardaría «Alimentación» como «AlimentaciÃ³n»:
-- este archivo es UTF-8 y hay que decirlo ANTES de insertar nada.
SET NAMES utf8mb4;

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

-- El administrador de DESARROLLO ya no se crea aquí (hallazgo C1 de docs/AUDITORIA.md): este archivo se ejecuta también en
-- producción y no debe dejar una cuenta admin con la clave publicada. Ahora lo crea database/04-dev-admin.sh, solo con APP_ENV=local.
-- En un entorno real, el administrador se crea con scripts/create-admin.php (docs/DESPLIEGUE.md).
