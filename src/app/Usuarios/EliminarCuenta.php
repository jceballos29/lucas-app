<?php

declare(strict_types=1);

namespace App\Usuarios;

use App\Auth\LimitadorIntentos;
use App\Database\Transaction;
use App\Repository\IntentoRepository;
use App\Repository\UsuarioRepository;
use App\Support\AlmacenAdjuntos;
use PDO;

/**
 * Eliminar la cuenta de un usuario y TODOS sus datos (RNF-20, D5): en una sola transacción, en orden explícito. Un administrador
 * no puede eliminar su propia cuenta (como en RF-33, no puede desactivarse ni quitarse el rol): otro administrador debe quitarle
 * el rol primero.
 */
final class EliminarCuenta
{
    public const OK = 'ok';
    public const NO_EXISTE = 'no_existe';
    public const ES_ADMIN = 'es_admin';

    public function __construct(
        private readonly PDO $pdo,
        private readonly UsuarioRepository $usuarios,
        private readonly IntentoRepository $intentos,
    ) {
    }

    /** @return string {@see OK}, {@see NO_EXISTE} o {@see ES_ADMIN} */
    public function eliminar(int $userId): string
    {
        $correo = null;
        $adjuntosABorrar = [];

        $resultado = Transaction::run($this->pdo, function () use ($userId, &$correo, &$adjuntosABorrar): string {
            // Se bloquea la fila: nada nuevo puede colgarse del usuario mientras se borra.
            $usuario = $this->usuarios->lockById($userId);

            if ($usuario === null) {
                return self::NO_EXISTE;
            }

            if ($usuario->esAdmin()) {
                return self::ES_ADMIN;
            }

            $correo = $usuario->correo;
            $adjuntosABorrar = $this->usuarios->deleteWithAllData($userId);

            return self::OK;
        });

        // Los intentos de acceso guardan el hash del correo y los adjuntos viven en disco: tampoco debe quedar eso (RNF-20).
        if ($resultado === self::OK) {
            if ($correo !== null) {
                $this->intentos->clearKey(LimitadorIntentos::claveCorreo($correo));
            }
            foreach ($adjuntosABorrar as $ruta) {
                AlmacenAdjuntos::eliminar($ruta);
            }
        }

        return $resultado;
    }
}
