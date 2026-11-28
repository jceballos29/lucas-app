<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Domain\Movimiento;
use App\Notificaciones\NotificadorPresupuesto;
use App\Repository\MovimientoRepository;
use App\Support\AlmacenAdjuntos;
use App\Support\Dinero;
use App\Support\ResultadoSubida;

/**
 * Aplica un {@see EnvioMovimiento} ya validado (R1 de docs/AUDITORIA.md): guarda el movimiento (normal o dividido) con
 * {@see GestionMovimientos}, gestiona el comprobante adjunto, avisa por correo si se pasa un presupuesto (RF-17) y arma el mensaje.
 * No lee la petición ni redirige: devuelve un {@see ResultadoGuardado} y el controlador decide qué responder.
 */
final class GuardarMovimiento
{
    public function __construct(
        private readonly GestionMovimientos $gestion,
        private readonly MovimientoRepository $movimientos,
        private readonly NotificadorPresupuesto $notificador,
        private readonly AvisoPresupuesto $aviso,
    ) {
    }

    public function guardar(int $userId, ?Movimiento $existente, EnvioMovimiento $envio, ?int $espacioId = null): ResultadoGuardado
    {
        return $envio->dividido
            ? $this->guardarDividido($userId, $envio, $espacioId)
            : $this->guardarNormal($userId, $existente, $envio, $espacioId);
    }

    private function guardarNormal(int $userId, ?Movimiento $existente, EnvioMovimiento $envio, ?int $espacioId): ResultadoGuardado
    {
        $data = $envio->datos;
        $subida = $envio->subida;
        $saved = $this->gestion->guardar($userId, $existente?->id, $data, $espacioId);

        switch ($saved['codigo']) {
            case ReglasMovimiento::OK:
                $movId = (int) $saved['id'];
                if ($subida->hayArchivo()) {
                    $this->adjuntar($userId, $movId, $subida, $espacioId);
                    if ($existente !== null && $existente->adjuntoRuta !== null) {
                        AlmacenAdjuntos::eliminar($existente->adjuntoRuta);
                    }
                } elseif ($existente !== null && $this->pideQuitarAdjunto() && $existente->adjuntoRuta !== null) {
                    $this->movimientos->actualizarAdjunto($userId, $movId, null, null, null, $espacioId);
                    AlmacenAdjuntos::eliminar($existente->adjuntoRuta);
                }

                $mensaje = $existente === null
                    ? 'Se registró ' . ($data['tipo'] === 'ingreso' ? 'el ingreso' : 'el gasto') . ' de ' . Dinero::formatear($data['monto']) . " en «{$saved['cuenta']}»."
                    : 'Se guardaron los cambios del movimiento.';

                // RF-17: si el gasto deja la categoría cerca o por encima de su presupuesto del mes, se avisa aquí mismo.
                $aviso = null;
                if ($data['tipo'] === 'gasto') {
                    $this->notificador->notificarGasto($userId, $data['id_categoria'], $data['fecha'], $espacioId);
                    $aviso = $this->aviso->para($userId, $data['id_categoria'], $data['fecha'], $espacioId);
                }

                return $this->conAviso($mensaje, $aviso === null ? [] : [$aviso]);

            case ReglasMovimiento::TIPO_DISCORDANTE:
                return ResultadoGuardado::conErrores(['id_categoria' => 'Esa categoría no es de ' . ($data['tipo'] === 'ingreso' ? 'ingresos' : 'gastos') . '. Elige otra o cambia el tipo.']);

            case ReglasMovimiento::CUENTA_INACTIVA:
                return ResultadoGuardado::conErrores(['id_cuenta' => "La cuenta «{$saved['cuenta']}» está inactiva. Actívala en Cuentas o elige otra."]);

            case ReglasMovimiento::SALDO_INSUFICIENTE:
                $disponible = Dinero::formatear((string) ($saved['saldo'] ?? '0.00'));

                return ResultadoGuardado::conErrores(['monto' => "La cuenta «{$saved['cuenta']}» tiene $disponible disponibles: no alcanza para registrar este movimiento sin dejarla en negativo."]);

            default:
                return ResultadoGuardado::noEncontrado();
        }
    }

    private function guardarDividido(int $userId, EnvioMovimiento $envio, ?int $espacioId): ResultadoGuardado
    {
        $base = $envio->datos;
        $partes = $envio->partes;
        $subida = $envio->subida;
        $saved = $this->gestion->guardarDividido($userId, $base, $partes, $espacioId);

        switch ($saved['codigo']) {
            case ReglasMovimiento::OK:
                if ($subida->hayArchivo() && isset($saved['ids'][0])) {
                    $this->adjuntar($userId, (int) $saved['ids'][0], $subida, $espacioId);
                }

                $mensaje = 'Se registró ' . ($base['tipo'] === 'ingreso' ? 'el ingreso dividido' : 'el gasto dividido')
                    . ' (' . count($partes) . ' categorías) por ' . Dinero::formatear($base['monto_total']) . " en «{$saved['cuenta']}».";

                $avisos = [];
                if ($base['tipo'] === 'gasto') {
                    foreach ($partes as $p) {
                        $this->notificador->notificarGasto($userId, $p['id_categoria'], $base['fecha'], $espacioId);
                        $av = $this->aviso->para($userId, $p['id_categoria'], $base['fecha'], $espacioId);
                        if ($av !== null && !in_array($av, $avisos, true)) {
                            $avisos[] = $av;
                        }
                    }
                }

                return $this->conAviso($mensaje, $avisos);

            case ReglasMovimiento::TIPO_DISCORDANTE:
                return ResultadoGuardado::conErrores(['split' => 'Alguna categoría de la división no coincide con el tipo (' . ($base['tipo'] === 'ingreso' ? 'ingresos' : 'gastos') . ').']);

            case ReglasMovimiento::CUENTA_INACTIVA:
                return ResultadoGuardado::conErrores(['id_cuenta' => "La cuenta «{$saved['cuenta']}» está inactiva. Actívala en Cuentas o elige otra."]);

            case ReglasMovimiento::SALDO_INSUFICIENTE:
                $disponible = Dinero::formatear((string) ($saved['saldo'] ?? '0.00'));

                return ResultadoGuardado::conErrores(['monto' => "La cuenta «{$saved['cuenta']}» tiene $disponible disponibles: no alcanza para registrar este movimiento dividido sin dejarla en negativo."]);

            default:
                return ResultadoGuardado::noEncontrado();
        }
    }

    /** Guarda el archivo subido y lo asocia al movimiento. */
    private function adjuntar(int $userId, int $movimientoId, ResultadoSubida $subida, ?int $espacioId): void
    {
        $guardado = AlmacenAdjuntos::guardarDesdeArchivo((string) $subida->tmp, (string) $subida->mime, (string) $subida->nombre);
        $this->movimientos->actualizarAdjunto($userId, $movimientoId, $guardado['ruta'], $guardado['nombre'], $guardado['mime'], $espacioId);
    }

    private function pideQuitarAdjunto(): bool
    {
        return ($_POST['eliminar_adjunto'] ?? '') === '1';
    }

    /** @param list<string> $avisos Avisos de presupuesto (sin repetir). */
    private function conAviso(string $mensaje, array $avisos): ResultadoGuardado
    {
        $texto = implode(' ', $avisos);

        return $texto === '' ? ResultadoGuardado::guardado('ok', $mensaje) : ResultadoGuardado::guardado('warn', "$mensaje $texto");
    }
}
