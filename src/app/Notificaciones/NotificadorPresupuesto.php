<?php

declare(strict_types=1);

namespace App\Notificaciones;

use App\Logging\Logger;
use App\Mail\Mailer;
use App\Repository\EspacioRepository;
use App\Repository\NotificacionRepository;
use App\Repository\PresupuestoRepository;
use App\Repository\TasaCambioRepository;
use App\Repository\UsuarioRepository;
use App\Support\Dinero;
use App\Support\Mes;
use Throwable;

/**
 * Alertas por correo cuando un gasto deja una categoría en el 80 % o el 100 % de su presupuesto del mes (Fase 20).
 *
 * - Espacio personal: avisa al usuario si activó `notif_presupuestos`.
 * - Espacio compartido: avisa a TODOS los miembros que lo activaron, indicando quién registró el gasto.
 * - Como máximo un correo por umbral (80 y 100) por destinatario, categoría y mes (`notificaciones_enviadas`).
 * - Nunca rompe la petición: si el correo falla se registra en el log y el gasto ya guardado queda igual.
 */
final class NotificadorPresupuesto
{
    public function __construct(
        private readonly PresupuestoRepository $presupuestos,
        private readonly TasaCambioRepository $tasas,
        private readonly UsuarioRepository $usuarios,
        private readonly EspacioRepository $espacios,
        private readonly NotificacionRepository $notificaciones,
        private readonly ?Mailer $mailer,
    ) {
    }

    /**
     * Revisa el presupuesto de la categoría del gasto recién registrado y envía las alertas que correspondan.
     *
     * @return int Cantidad de correos enviados.
     */
    public function notificarGasto(int $autorId, int $categoriaId, string $fecha, ?int $espacioId = null): int
    {
        try {
            return $this->procesar($autorId, $categoriaId, $fecha, $espacioId);
        } catch (Throwable $error) {
            Logger::exception('notificacion-presupuesto', $error);

            return 0;
        }
    }

    /** Umbral alcanzado (80 o 100) según el porcentaje consumido, o null si aún está por debajo del 80 %. */
    public static function umbral(string $estado): ?int
    {
        return match ($estado) {
            'cerca' => 80,
            'limite', 'excedido' => 100,
            default => null,
        };
    }

    public static function clave(int $destinatarioId, ?int $espacioId, int $categoriaId, int $mes, int $anio, int $umbral): string
    {
        return sprintf('presupuesto:u%d:e%d:c%d:%04d-%02d:%d', $destinatarioId, $espacioId ?? 0, $categoriaId, $anio, $mes, $umbral);
    }

    private function procesar(int $autorId, int $categoriaId, string $fecha, ?int $espacioId): int
    {
        if ($this->mailer === null) {
            return 0;
        }

        $mes = Mes::deFecha($fecha);
        $autor = $this->usuarios->findById($autorId);

        if ($mes === null || $autor === null) {
            return 0;
        }

        $moneda = $autor->moneda;
        $nombreEspacio = null;

        if ($espacioId !== null) {
            $espacio = $this->espacios->findForMember($autorId, $espacioId);

            if ($espacio === null) {
                return 0;
            }

            $moneda = $espacio->moneda;
            $nombreEspacio = $espacio->nombre;
        }

        $presupuesto = $this->presupuestos->findForCategory($autorId, $categoriaId, $mes['mes'], $mes['anio'], $espacioId, $moneda, $this->tasas->listMap($autorId, $espacioId));

        if ($presupuesto === null) {
            return 0;
        }

        $progreso = $presupuesto->progreso();
        $umbral = self::umbral($progreso['estado']);

        if ($umbral === null) {
            return 0;
        }

        $destinatarios = [];

        if ($espacioId === null) {
            $destinatarios[] = $autor;
        } else {
            foreach ($this->espacios->listMembers($espacioId) as $miembro) {
                $usuario = $this->usuarios->findById($miembro['id_usuario']);

                if ($usuario !== null) {
                    $destinatarios[] = $usuario;
                }
            }
        }

        $enviados = 0;
        $periodo = Mes::etiqueta($mes['mes'], $mes['anio']);
        $gastado = Dinero::formatearEn($presupuesto->gastado, $moneda);
        $limite = Dinero::formatearEn($presupuesto->montoLimite, $moneda);
        $situacion = $umbral === 100
            ? ($progreso['estado'] === 'excedido' ? 'superó' : 'llegó al límite de')
            : "va en el {$progreso['percent']} % de";

        foreach ($destinatarios as $usuario) {
            if (!$usuario->activo || !$usuario->notifPresupuestos || !$usuario->correoVerificado) {
                continue;
            }

            $clave = self::clave($usuario->id, $espacioId, $categoriaId, $mes['mes'], $mes['anio'], $umbral);

            if (!$this->notificaciones->reservar($usuario->id, $espacioId, $clave)) {
                continue;
            }

            $donde = $nombreEspacio === null ? '' : " del espacio «{$nombreEspacio}»";
            $quien = $nombreEspacio !== null && $autor->id !== $usuario->id ? "{$autor->nombre} registró un gasto y " : '';
            $asunto = $umbral === 100
                ? "Lucas: presupuesto de «{$presupuesto->categoriaNombre}» al 100 %"
                : "Lucas: presupuesto de «{$presupuesto->categoriaNombre}» al 80 %";
            $cuerpo = "Hola {$usuario->nombre},\n\n"
                . "{$quien}el presupuesto de «{$presupuesto->categoriaNombre}»{$donde} {$situacion} su límite de {$periodo}.\n\n"
                . "  Gastado: {$gastado}\n  Presupuesto: {$limite}\n  Consumido: {$progreso['percent']} %\n\n"
                . "Puedes desactivar estas alertas en tu perfil de Lucas.\n";

            try {
                $this->mailer->send($usuario->correo, $asunto, $cuerpo);
                $enviados++;
            } catch (Throwable $error) {
                $this->notificaciones->liberar($clave);
                Logger::exception('notificacion-presupuesto', $error);
            }
        }

        return $enviados;
    }
}
