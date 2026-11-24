<?php

declare(strict_types=1);

namespace App\Database;

/** Parte un archivo .sql en sentencias sueltas (sin comentarios ni vacías). Sirve para el esquema, la seed y las migraciones. */
final class SqlScript
{
    /** @return list<string> */
    public static function statements(string $sql): array
    {
        // Se quitan los comentarios de línea (--) y se corta en cada «;» al final de línea. Los archivos del proyecto no
        // llevan «;» dentro de textos ni procedimientos almacenados, así que esto basta.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        $statements = [];

        foreach (preg_split('/;\s*(?:\n|$)/', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }

        return $statements;
    }
}
