<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\IntentoRepository;

/**
 * Limita los intentos de login y de registro por correo y por IP (D1, RNF-24): ventana de 15 minutos.
 *
 * En `login` y `clave` (adivinar una contraseña) el bloqueo duro es por PAR (correo, IP): 5 fallos. Así quien falle desde su IP no puede
 * dejar fuera al dueño de la cuenta, que entra desde otra (M1). Por correo solo se cuenta con un umbral alto (50, contra quien rote IPs
 * para probar claves de una misma cuenta) y por IP, 20. En `registro` se mantiene el límite clásico: 5 por correo y 20 por IP.
 * Las claves van hasheadas, para no guardar correos ajenos en claro.
 */
final class LimitadorIntentos
{
    public const VENTANA_SEGUNDOS = 900;
    public const MAX_POR_CORREO = 5;
    public const MAX_POR_IP = 20;
    public const MAX_POR_PAR = 5;
    public const MAX_POR_CORREO_ROTANDO_IP = 50;

    /** Tipos que adivinan una contraseña: bloquean por par (correo, IP). */
    private const TIPOS_POR_PAR = ['login', 'clave'];

    public function __construct(private readonly IntentoRepository $intentos)
    {
    }

    public static function usaPar(string $tipo): bool
    {
        return in_array($tipo, self::TIPOS_POR_PAR, true);
    }

    /** Lógica pura: ¿se pasó de algún límite? ($porPar es null en los tipos que no cuentan por par.) */
    public static function excede(int $porCorreo, int $porIp, ?int $porPar = null): bool
    {
        if ($porPar === null) {
            return $porCorreo >= self::MAX_POR_CORREO || $porIp >= self::MAX_POR_IP;
        }

        return $porPar >= self::MAX_POR_PAR || $porCorreo >= self::MAX_POR_CORREO_ROTANDO_IP || $porIp >= self::MAX_POR_IP;
    }

    public static function claveCorreo(string $correo): string
    {
        return 'correo:' . hash('sha256', mb_strtolower(trim($correo)));
    }

    public static function clavePar(string $correo, string $ip): string
    {
        return 'par:' . hash('sha256', mb_strtolower(trim($correo)) . "\n" . substr($ip, 0, 45));
    }

    public static function claveIp(string $ip): string
    {
        return 'ip:' . substr($ip, 0, 45);
    }

    /** Límite por correo más estricto para el segundo factor (códigos de 6 dígitos): 10 fallos / 15 min aunque cambie la IP. */
    public const MAX_POR_CORREO_DOS_PASOS = 10;

    /** @param int|null $maxCorreo Si se indica, además bloquea al llegar a ese número de fallos por correo (sea cual sea la IP). */
    public function bloqueado(string $tipo, string $correo, string $ip, ?int $maxCorreo = null): bool
    {
        if ($maxCorreo !== null && $this->intentos->countSince($tipo, self::claveCorreo($correo), self::VENTANA_SEGUNDOS) >= $maxCorreo) {
            return true;
        }

        return self::excede(
            $this->intentos->countSince($tipo, self::claveCorreo($correo), self::VENTANA_SEGUNDOS),
            $this->intentos->countSince($tipo, self::claveIp($ip), self::VENTANA_SEGUNDOS),
            self::usaPar($tipo) ? $this->intentos->countSince($tipo, self::clavePar($correo, $ip), self::VENTANA_SEGUNDOS) : null,
        );
    }

    public function registrar(string $tipo, string $correo, string $ip): void
    {
        $this->intentos->record($tipo, self::claveCorreo($correo));
        $this->intentos->record($tipo, self::claveIp($ip));
        if (self::usaPar($tipo)) {
            $this->intentos->record($tipo, self::clavePar($correo, $ip));
        }

        // Mantenimiento barato: una de cada 50 veces se borra lo que ya salió de la ventana.
        if (random_int(1, 50) === 1) {
            $this->intentos->purgeOlderThan(self::VENTANA_SEGUNDOS * 2);
        }
    }

    /** Tras un acceso correcto se perdonan los fallos de ese correo y de ese par (los de la IP siguen contando). */
    public function perdonar(string $tipo, string $correo, ?string $ip = null): void
    {
        $this->intentos->clear($tipo, self::claveCorreo($correo));
        if ($ip !== null && self::usaPar($tipo)) {
            $this->intentos->clear($tipo, self::clavePar($correo, $ip));
        }
    }
}
