-- R9 (docs/AUDITORIA.md): índices para las pantallas de espacios compartidos. Idempotente.
--
-- Los listados de un espacio filtran por `id_espacio` y ordenan por fecha, pero solo existía `(id_usuario, fecha)` (que sirve a los datos
-- personales) y la clave foránea de `id_espacio`, que obliga a ordenar en memoria (EXPLAIN: «Using filesort»). Con `(id_espacio, fecha)` el
-- orden sale del propio índice.

SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'transacciones' AND index_name = 'idx_trans_espacio_fecha') = 0, 'ALTER TABLE transacciones ADD INDEX idx_trans_espacio_fecha (id_espacio, fecha)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'transferencias' AND index_name = 'idx_transf_espacio_fecha') = 0, 'ALTER TABLE transferencias ADD INDEX idx_transf_espacio_fecha (id_espacio, fecha)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'movimientos_recurrentes' AND index_name = 'idx_rec_espacio_proxima') = 0, 'ALTER TABLE movimientos_recurrentes ADD INDEX idx_rec_espacio_proxima (id_espacio, activo, proxima_fecha)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
