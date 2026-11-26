<?php

declare(strict_types=1);

namespace App\Notificaciones;

use App\Domain\Usuario;
use App\Logging\Logger;
use App\Mail\Mailer;
use App\Repository\MetaRepository;
use App\Repository\NotificacionRepository;
use App\Repository\PresupuestoRepository;
use App\Repository\ReporteRepository;
use App\Repository\TasaCambioRepository;
use App\Support\Dinero;
use App\Support\Mes;
use Throwable;

/**
 * Resumen financiero mensual por correo (Fase 20): ingresos, gastos, balance, 3 categorías con más gasto, presupuestos
 * excedidos y avance de las metas de ahorro en curso. Cubre el espacio personal del usuario.
 */
final class ResumenMensual
{
    public function __construct(
        private readonly ReporteRepository $reportes,
        private readonly PresupuestoRepository $presupuestos,
        private readonly MetaRepository $metas,
        private readonly TasaCambioRepository $tasas,
        private readonly NotificacionRepository $notificaciones,
        private readonly Mailer $mailer,
    ) {
    }

    public static function clave(int $userId, int $mes, int $anio): string
    {
        return sprintf('resumen:u%d:%04d-%02d', $userId, $anio, $mes);
    }

    /** @return array{asunto: string, cuerpo: string} */
    public function construir(Usuario $usuario, int $mes, int $anio): array
    {
        $moneda = $usuario->moneda;
        $tasas = $this->tasas->listMap($usuario->id, null);
        $periodo = Mes::etiqueta($mes, $anio);
        $totales = $this->reportes->totalesMes($usuario->id, $mes, $anio, null, $moneda, $tasas);
        $balance = Dinero::deCentavos(Dinero::aCentavos($totales['ingresos']) - Dinero::aCentavos($totales['gastos']));
        $fmt = static fn (string $m): string => Dinero::formatearEn($m, $moneda);

        $lineas = [
            "Hola {$usuario->nombre},",
            '',
            "Este es tu resumen financiero de {$periodo}:",
            '',
            '  Ingresos: ' . $fmt($totales['ingresos']),
            '  Gastos:   ' . $fmt($totales['gastos']),
            '  Balance:  ' . $fmt($balance),
            '',
        ];

        $top = array_slice($this->reportes->gastosPorCategoria($usuario->id, $mes, $anio, null, $moneda, $tasas), 0, 3);
        $lineas[] = 'Donde más gastaste:';

        if ($top === []) {
            $lineas[] = '  Sin gastos registrados este mes.';
        }

        foreach ($top as $i => $categoria) {
            $lineas[] = '  ' . ($i + 1) . '. ' . $categoria['nombre'] . ': ' . $fmt($categoria['total']);
        }

        $lineas[] = '';
        $excedidos = array_values(array_filter(
            $this->presupuestos->listByMonth($usuario->id, $mes, $anio, null, $moneda, $tasas),
            static fn ($p): bool => $p->progreso()['estado'] === 'excedido'
        ));
        $lineas[] = 'Presupuestos excedidos:';

        if ($excedidos === []) {
            $lineas[] = '  Ninguno. ¡Buen control!';
        }

        foreach ($excedidos as $p) {
            $lineas[] = "  - {$p->categoriaNombre}: " . $fmt($p->gastado) . ' de ' . $fmt($p->montoLimite);
        }

        $lineas[] = '';
        $enCurso = array_values(array_filter($this->metas->listByUser($usuario->id, null), static fn ($m): bool => $m->enCurso()));
        $lineas[] = 'Metas de ahorro en curso:';

        if ($enCurso === []) {
            $lineas[] = '  No tienes metas en curso.';
        }

        foreach ($enCurso as $meta) {
            $lineas[] = "  - {$meta->nombre}: " . $meta->progreso()['percent'] . ' % (' . $fmt($meta->ahorrado) . ' de ' . $fmt($meta->montoObjetivo) . ')';
        }

        $lineas[] = '';
        $lineas[] = 'Puedes desactivar este resumen en tu perfil de Lucas.';

        return ['asunto' => "Lucas: tu resumen de {$periodo}", 'cuerpo' => implode("\n", $lineas) . "\n"];
    }

    /**
     * Envía el resumen del mes UNA sola vez (deduplicado). Con `$forzar` (resumen de prueba) no reserva ni deduplica.
     *
     * @return bool true si se envió.
     */
    public function enviar(Usuario $usuario, int $mes, int $anio, bool $forzar = false): bool
    {
        $clave = self::clave($usuario->id, $mes, $anio);

        if (!$forzar && !$this->notificaciones->reservar($usuario->id, null, $clave)) {
            return false;
        }

        try {
            $m = $this->construir($usuario, $mes, $anio);
            $this->mailer->send($usuario->correo, $m['asunto'], $m['cuerpo']);

            return true;
        } catch (Throwable $error) {
            if (!$forzar) {
                $this->notificaciones->liberar($clave);
            }

            Logger::exception('resumen-mensual', $error);

            return false;
        }
    }
}
