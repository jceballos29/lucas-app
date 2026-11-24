<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use Throwable;

/** Ejecuta un trabajo dentro de una transacción SQL: confirma si termina y deshace si lanza una excepción. */
final class Transaction
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(PDO $pdo, callable $work): mixed
    {
        $pdo->beginTransaction();

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
