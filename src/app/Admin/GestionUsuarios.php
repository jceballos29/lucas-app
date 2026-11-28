<?php

declare(strict_types=1);

namespace App\Admin;

use App\Repository\UsuarioRepository;

/**
 * Activar/desactivar usuarios y cambiar su rol (RF-31, RF-32) respetando RF-33. Todo ocurre en UNA transacción que
 * primero bloquea a los administradores activos (`FOR UPDATE`) y al usuario objetivo: así, dos administradores que se
 * desactiven a la vez no pueden dejar al sistema sin ninguno (el segundo espera, ve el estado nuevo y es rechazado).
 */
final class GestionUsuarios
{
    public function __construct(private readonly UsuarioRepository $usuarios)
    {
    }

    /**
     * @param string $accion Una de {@see ReglasAdmin::ACCIONES}.
     * @return array{codigo: string, nombre: string|null} Resultado ({@see ReglasAdmin}) y el nombre del usuario afectado.
     */
    public function aplicar(int $actorId, int $objetivoId, string $accion): array
    {
        return $this->usuarios->transaction(function () use ($actorId, $objetivoId, $accion): array {
            $admins = $this->usuarios->lockActiveAdminIds();
            $objetivo = $this->usuarios->lockById($objetivoId);
            $codigo = ReglasAdmin::evaluar($admins, $actorId, $objetivo, $accion);

            if ($codigo === ReglasAdmin::OK && $objetivo !== null) {
                match ($accion) {
                    'activar' => $this->usuarios->setActivo($objetivo->id, true),
                    'desactivar' => $this->usuarios->setActivo($objetivo->id, false),
                    'hacer_admin' => $this->usuarios->setRol($objetivo->id, 'admin'),
                    default => $this->usuarios->setRol($objetivo->id, 'usuario'),
                };
            }

            return ['codigo' => $codigo, 'nombre' => $objetivo?->nombre];
        });
    }
}
