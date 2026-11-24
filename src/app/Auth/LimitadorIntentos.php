<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\IntentoRepository;

/**
 * Limita los intentos de login y de registro por correo y por IP (D1, RNF-24): ventana de 15 minutos,
 * máximo 5 por correo y 20 por IP. La clave del correo es su hash, para no guardar correos ajenos en claro.
 */
final class LimitadorIntentos
{
    public const VENTANA_SEGUNDOS = 900;
    public const MAX_POR_CORREO = 5;
    public const MAX_POR_IP = 20;

    public function __construct(private readonly IntentoRepository $intentos)
    {
    }

    /** Lógica pura: ¿se pasó de algún límite? */
    public static function excede(int $porCorreo, int $porIp): bool
    {
        return $porCorreo >= self::MAX_POR_CORREO || $porIp >= self::MAX_POR_IP;
    }

    public static function claveCorreo(string $correo): string
    {
        return 'correo:' . hash('sha256', mb_strtolower(trim($correo)));
    }

    public static function claveIp(string $ip): string
    {
        return 'ip:' . substr($ip, 0, 45);
    }

    public function bloqueado(string $tipo, string $correo, string $ip): bool
    {
        return self::excede(
            $this->intentos->countSince($tipo, self::claveCorreo($correo), self::VENTANA_SEGUNDOS),
            $this->intentos->countSince($tipo, self::claveIp($ip), self::VENTANA_SEGUNDOS),
        );
    }

    public function registrar(string $tipo, string $correo, string $ip): void
    {
        $this->intentos->record($tipo, self::claveCorreo($correo));
        $this->intentos->record($tipo, self::claveIp($ip));

        // Mantenimiento barato: una de cada 50 veces se borra lo que ya salió de la ventana.
        if (random_int(1, 50) === 1) {
            $this->intentos->purgeOlderThan(self::VENTANA_SEGUNDOS * 2);
        }
    }

    /** Tras un acceso correcto se perdonan los fallos de ese correo (los de la IP siguen contando). */
    public function perdonar(string $tipo, string $correo): void
    {
        $this->intentos->clear($tipo, self::claveCorreo($correo));
    }
}
