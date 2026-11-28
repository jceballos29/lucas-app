<?php

declare(strict_types=1);

namespace App\Movimientos;

use App\Database\Transaction;
use App\Repository\CategoriaRepository;
use App\Repository\CuentaRepository;
use App\Repository\MovimientoRepository;
use App\Support\Dinero;
use App\Validation\MovimientoValidator;
use PDO;

/**
 * Importador asistido de movimientos desde texto/archivo CSV (Fase 13).
 *
 * M8/U4: antes de guardar hay una VISTA PREVIA (`vistaPrevia`): cada fila con el monto como se interpretó, avisos de montos ambiguos
 * («1.234») y de posibles duplicados (mismo día, tipo, monto y descripción ya en la cuenta, o repetidos en el archivo), y los totales.
 *
 * Acepta líneas con separador `;` o `,`, con 3 o 4 columnas:
 *   - `Fecha;Descripción;Monto` (el signo del monto define si es `gasto` (-) o `ingreso` (+), o si todos son positivos y viene columna Tipo)
 *   - `Fecha;Tipo;Descripción;Monto`
 *   - También reconoce el propio CSV exportado por Lucas (`Fecha,Tipo,Categoría,Cuenta,Descripción,Monto`).
 */
final class ImportadorCsv
{
    public const MAX_FILAS = 200;

    public function __construct(
        private readonly PDO $pdo,
        private readonly MovimientoRepository $movimientos,
        private readonly CuentaRepository $cuentas,
        private readonly CategoriaRepository $categorias,
    ) {
    }

    /**
     * Analiza un texto CSV sin tocar la base de datos.
     *
     * @return array{
     *   filas: list<array{linea: int, fecha: string, tipo: string, monto: string, descripcion: string, monto_crudo: string, ambiguo: bool}>,
     *   errores: list<string>,
     *   truncado: bool
     * }
     */
    public static function parsearTexto(string $contenidoCsv): array
    {
        // Quitar BOM UTF-8 si viene del archivo exportado
        if (str_starts_with($contenidoCsv, "\xEF\xBB\xBF")) {
            $contenidoCsv = substr($contenidoCsv, 3);
        }

        $lineas = preg_split('/\r\n|\r|\n/', trim($contenidoCsv)) ?: [];
        $filas = [];
        $errores = [];
        $truncado = false;

        foreach ($lineas as $idx => $raw) {
            $numLinea = $idx + 1;
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $delim = substr_count($raw, ';') >= substr_count($raw, ',') ? ';' : ',';
            $cols = array_map(static fn (?string $c): string => trim((string) $c), str_getcsv($raw, $delim, '"', ''));

            if (count($cols) < 3) {
                $errores[] = "Línea $numLinea: se esperaban al menos 3 columnas (Fecha, Descripción, Monto).";
                continue;
            }

            // Si la primera fila es cabecera («Fecha...»), se salta.
            if ($idx === 0 && preg_match('/^fecha$/iu', $cols[0]) === 1) {
                continue;
            }

            $fecha = self::normalizarFecha($cols[0]);
            if ($fecha === null) {
                $errores[] = "Línea $numLinea: fecha no válida «{$cols[0]}» (usa AAAA-MM-DD o DD/MM/AAAA).";
                continue;
            }

            // Detectar formato según número de columnas:
            // - 6 columnas (formato de exportación de Lucas: Fecha, Tipo, Categoría, Cuenta, Descripción, Monto)
            // - 4 columnas (Fecha, Tipo, Descripción, Monto)
            // - 3 columnas (Fecha, Descripción, Monto con signo: negativo = gasto, positivo = gasto por defecto salvo '+' explícito)
            if (count($cols) >= 6) {
                $tipoBruto = mb_strtolower($cols[1], 'UTF-8');
                $descripcion = $cols[4];
                $montoBruto = $cols[5];
            } elseif (count($cols) === 4) {
                $tipoBruto = mb_strtolower($cols[1], 'UTF-8');
                $descripcion = $cols[2];
                $montoBruto = $cols[3];
            } else {
                $descripcion = $cols[1];
                $montoBruto = $cols[2];
                $tipoBruto = str_starts_with(ltrim($montoBruto), '+') ? 'ingreso' : 'gasto';
            }

            $montoParsed = Dinero::parse(ltrim($montoBruto, '+'));
            if ($montoParsed === null || $montoParsed === '0.00') {
                $errores[] = "Línea $numLinea: monto no válido «{$montoBruto}».";
                continue;
            }

            if (str_starts_with($montoParsed, '-')) {
                $tipo = 'gasto';
                $monto = ltrim($montoParsed, '-');
            } elseif (in_array($tipoBruto, ['ingreso', 'abono', 'credito', 'crédito', '+'], true)) {
                $tipo = 'ingreso';
                $monto = $montoParsed;
            } else {
                $tipo = 'gasto';
                $monto = $montoParsed;
            }

            $descripcionLimpia = mb_substr(trim(preg_replace('/\s+/u', ' ', ltrim($descripcion, "'")) ?? ''), 0, 150);

            $filas[] = [
                'linea' => $numLinea,
                'fecha' => $fecha,
                'tipo' => $tipo,
                'monto' => $monto,
                'descripcion' => $descripcionLimpia !== '' ? $descripcionLimpia : 'Movimiento importado CSV',
                'monto_crudo' => $montoBruto,
                'ambiguo' => self::esAmbiguo($montoBruto),
            ];

            if (count($filas) >= self::MAX_FILAS) {
                // Hay más filas de las que se importan por vez: se avisa en vez de cortar en silencio.
                $truncado = count(array_filter(array_slice($lineas, $idx + 1), static fn (string $l): bool => trim($l) !== '')) > 0;
                break;
            }
        }

        return ['filas' => $filas, 'errores' => $errores, 'truncado' => $truncado];
    }

    /**
     * ¿Se puede leer de dos maneras? «1.234» es mil doscientos treinta y cuatro (punto de miles, el formato de Lucas), pero un banco que
     * escriba el punto como decimal querría decir 1,234. Con coma decimal o con 2 decimales no hay duda.
     */
    public static function esAmbiguo(string $montoBruto): bool
    {
        $limpio = preg_replace('/[\s$+\-−]/u', '', $montoBruto) ?? '';

        return preg_match('/^\d{1,3}\.\d{3}$/', $limpio) === 1;
    }

    /**
     * Prepara la vista previa: qué filas parecen duplicados, los totales y cómo quedaría la cuenta. No escribe nada.
     *
     * @param list<array{linea: int, fecha: string, tipo: string, monto: string, descripcion: string}> $filas
     * @return array{
     *   cuenta: string, saldo_actual: string, saldo_final: string, saldo_negativo: bool,
     *   ingresos: string, gastos: string, duplicadas: array<int, string>
     * }|null null si la cuenta no es del usuario o espacio.
     */
    public function vistaPrevia(int $userId, int $cuentaId, array $filas, ?int $espacioId = null): ?array
    {
        $cuenta = $this->cuentas->findOwned($userId, $cuentaId, $espacioId);
        if ($cuenta === null || $filas === []) {
            return null;
        }

        $fechas = array_column($filas, 'fecha');
        $existentes = [];
        foreach ($this->movimientos->coincidentes($cuentaId, min($fechas), max($fechas)) as $e) {
            $existentes[self::huella($e['fecha'], $e['tipo'], $e['monto'], $e['descripcion'])] = true;
        }

        $duplicadas = [];
        $vistas = [];
        $ingresos = [];
        $gastos = [];
        $netos = [];
        foreach ($filas as $f) {
            $h = self::huella($f['fecha'], $f['tipo'], $f['monto'], $f['descripcion']);
            if (isset($existentes[$h])) {
                $duplicadas[$f['linea']] = 'bd';
            } elseif (isset($vistas[$h])) {
                $duplicadas[$f['linea']] = 'archivo';
            }
            $vistas[$h] = true;
            if ($f['tipo'] === 'ingreso') {
                $ingresos[] = $f['monto'];
                $netos[] = $f['monto'];
            } else {
                $gastos[] = $f['monto'];
                $netos[] = '-' . $f['monto'];
            }
        }

        $saldoFinal = Dinero::sumar([$cuenta->saldo, ...$netos]);

        return [
            'cuenta' => $cuenta->nombre,
            'saldo_actual' => $cuenta->saldo,
            'saldo_final' => $saldoFinal,
            'saldo_negativo' => $cuenta->tipo !== 'tarjeta_credito' && Dinero::aCentavos($saldoFinal) < 0,
            'ingresos' => Dinero::sumar($ingresos === [] ? ['0.00'] : $ingresos),
            'gastos' => Dinero::sumar($gastos === [] ? ['0.00'] : $gastos),
            'duplicadas' => $duplicadas,
        ];
    }

    private static function huella(string $fecha, string $tipo, string $monto, string $descripcion): string
    {
        return $fecha . '|' . $tipo . '|' . Dinero::aCentavos($monto) . '|' . mb_strtolower(trim($descripcion), 'UTF-8');
    }

    /**
     * Importa en una sola transacción las filas ya parseadas hacia una cuenta activa del usuario/espacio.
     *
     * @param list<array{linea: int, fecha: string, tipo: string, monto: string, descripcion: string}> $filas
     * @return array{codigo: string, importados: int, cuenta: string|null, saldo: string|null}
     */
    public function importar(
        int $userId,
        int $cuentaId,
        int $categoriaGastoId,
        int $categoriaIngresoId,
        array $filas,
        ?int $espacioId = null,
    ): array {
        return Transaction::run($this->pdo, function () use (
            $userId,
            $cuentaId,
            $categoriaGastoId,
            $categoriaIngresoId,
            $filas,
            $espacioId,
        ): array {
            $lock = $this->cuentas->lockForWrite($userId, $cuentaId, $espacioId);
            $cuenta = $this->cuentas->findOwned($userId, $cuentaId, $espacioId);

            if ($lock === null || $cuenta === null) {
                return ['codigo' => ReglasMovimiento::CUENTA_AJENA, 'importados' => 0, 'cuenta' => null, 'saldo' => null];
            }

            if (!$lock['activa']) {
                return ['codigo' => ReglasMovimiento::CUENTA_INACTIVA, 'importados' => 0, 'cuenta' => $cuenta->nombre, 'saldo' => $cuenta->saldo];
            }

            $catGasto = $this->categorias->findById($categoriaGastoId);
            $catIngreso = $this->categorias->findById($categoriaIngresoId);

            if (ReglasMovimiento::evaluar($lock, $catGasto, $userId, 'gasto', null, '0.00', $espacioId) !== ReglasMovimiento::OK) {
                return ['codigo' => ReglasMovimiento::CATEGORIA_AJENA, 'importados' => 0, 'cuenta' => $cuenta->nombre, 'saldo' => $cuenta->saldo];
            }
            if (ReglasMovimiento::evaluar($lock, $catIngreso, $userId, 'ingreso', null, '0.00', $espacioId) !== ReglasMovimiento::OK) {
                return ['codigo' => ReglasMovimiento::CATEGORIA_AJENA, 'importados' => 0, 'cuenta' => $cuenta->nombre, 'saldo' => $cuenta->saldo];
            }

            // Calcular efecto neto del lote sobre la cuenta para impedir que una cuenta normal quede con saldo negativo.
            $efectoNeto = [];
            foreach ($filas as $f) {
                $efectoNeto[] = $f['tipo'] === 'ingreso' ? $f['monto'] : ('-' . $f['monto']);
            }
            $saldoFinal = Dinero::sumar([$cuenta->saldo, ...$efectoNeto]);
            if ($cuenta->tipo !== 'tarjeta_credito' && Dinero::aCentavos($saldoFinal) < 0) {
                return ['codigo' => ReglasMovimiento::SALDO_INSUFICIENTE, 'importados' => 0, 'cuenta' => $cuenta->nombre, 'saldo' => $cuenta->saldo];
            }

            $importados = 0;
            foreach ($filas as $f) {
                $catId = $f['tipo'] === 'ingreso' ? $categoriaIngresoId : $categoriaGastoId;
                $this->movimientos->insert(
                    $userId,
                    $cuentaId,
                    $catId,
                    $f['tipo'],
                    $f['monto'],
                    $f['fecha'],
                    $f['descripcion'],
                    $espacioId,
                );
                $importados++;
            }

            return [
                'codigo' => ReglasMovimiento::OK,
                'importados' => $importados,
                'cuenta' => $cuenta->nombre,
                'saldo' => $saldoFinal,
            ];
        });
    }

    public static function normalizarFecha(string $raw): ?string
    {
        $raw = trim(ltrim($raw, "'"));
        if (MovimientoValidator::validDate($raw)) {
            return $raw;
        }

        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $raw, $m) === 1) {
            $iso = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            return MovimientoValidator::validDate($iso) ? $iso : null;
        }

        return null;
    }
}
