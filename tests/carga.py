#!/usr/bin/env python3
"""
Prueba de carga ligera (RNF-19: «soportar al menos 500 usuarios simultáneos»; RNF-08: páginas en menos de 3 s).

Simula usuarios REALES: cada uno inicia sesión (contraseña con bcrypt, que es lo que más CPU cuesta) y luego navega por el panel,
los movimientos, las cuentas, los presupuestos y los reportes con un tiempo de «lectura» entre páginas. Solo usa la librería estándar
de Python y habla HTTP/1.1 con conexiones persistentes, como un navegador.

    python3 tests/carga.py --setup --users 500            # crea 500 usuarios con datos (borra los de una carga anterior)
    python3 tests/carga.py --users 500 --duration 60      # lanza la carga contra http://localhost:8090
    python3 tests/carga.py --cleanup                      # borra los usuarios de carga

No forma parte de tests/run-all.sh (es lenta y pesada). Úsala contra un entorno de pruebas, NUNCA contra producción: crea usuarios.
"""

import argparse
import asyncio
import random
import re
import statistics
import subprocess
import sys
import time

CONTRASENA = 'Carga#2026x'
PAGINAS = ['/panel.php', '/movimientos.php', '/cuentas.php', '/presupuestos.php', '/reportes.php', '/metas.php', '/categorias.php']
DIR = __file__.rsplit('/tests/', 1)[0]


def mysql(sql: str) -> str:
    """Ejecuta SQL como root dentro del contenedor de la base (solo para preparar y limpiar datos de prueba)."""
    r = subprocess.run(
        ['docker', 'compose', 'exec', '-T', 'db', 'sh', '-c', 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 lucas -N 2>/dev/null'],
        input=sql, text=True, capture_output=True, cwd=DIR,
    )
    if r.returncode != 0:
        sys.exit(f'Falló MySQL: {r.stderr}')
    return r.stdout.strip()


def limpiar() -> None:
    mysql("""
        DELETE a FROM aportes_meta a JOIN metas_ahorro m ON m.id_meta = a.id_meta JOIN usuarios u ON u.id_usuario = m.id_usuario WHERE u.correo LIKE 'carga\\_%@correo.com';
        DELETE p FROM presupuestos p JOIN usuarios u ON u.id_usuario = p.id_usuario WHERE u.correo LIKE 'carga\\_%@correo.com';
        DELETE t FROM transacciones t JOIN usuarios u ON u.id_usuario = t.id_usuario WHERE u.correo LIKE 'carga\\_%@correo.com';
        DELETE FROM usuarios WHERE correo LIKE 'carga\\_%@correo.com';
        DELETE FROM intentos_acceso;
    """)


def preparar(n: int) -> None:
    limpiar()
    r = subprocess.run(
        ['docker', 'compose', 'exec', '-T', 'php', 'php', '-r', f'echo password_hash("{CONTRASENA}", PASSWORD_DEFAULT);'],
        capture_output=True, text=True, cwd=DIR,
    )
    h = r.stdout.strip()
    mysql(f"""
        SET SESSION cte_max_recursion_depth = 100000;
        INSERT INTO usuarios (nombre, correo, contrasena_hash)
          WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < {n})
          SELECT CONCAT('Carga ', n), CONCAT('carga_', n, '@correo.com'), '{h}' FROM s;
        INSERT INTO cuentas (id_usuario, nombre, tipo, saldo_inicial)
          SELECT id_usuario, 'Efectivo', 'efectivo', 5000000 FROM usuarios WHERE correo LIKE 'carga\\_%@correo.com';
        INSERT INTO transacciones (id_usuario, id_cuenta, id_categoria, tipo, monto, fecha, descripcion)
          SELECT u.id_usuario, c.id_cuenta, k.id_categoria, 'gasto', ROUND(1000 + RAND() * 50000, 2), DATE_SUB(CURDATE(), INTERVAL FLOOR(RAND() * 60) DAY), 'Movimiento de carga'
          FROM usuarios u JOIN cuentas c ON c.id_usuario = u.id_usuario
          JOIN (SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'gasto' LIMIT 6) k
          JOIN (SELECT 1 AS i UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) r
          WHERE u.correo LIKE 'carga\\_%@correo.com';
        INSERT INTO presupuestos (id_usuario, id_categoria, mes, anio, monto_limite)
          SELECT u.id_usuario, k.id_categoria, MONTH(CURDATE()), YEAR(CURDATE()), 300000
          FROM usuarios u JOIN (SELECT id_categoria FROM categorias WHERE id_usuario IS NULL AND tipo = 'gasto' LIMIT 3) k
          WHERE u.correo LIKE 'carga\\_%@correo.com';
    """)
    print(f'Preparados {n} usuarios (carga_1..carga_{n}@correo.com), cada uno con 1 cuenta, 30 movimientos y 3 presupuestos.')


class Respuesta:
    __slots__ = ('estado', 'cookies', 'cuerpo', 'cabeceras')

    def __init__(self, estado, cabeceras, cookies, cuerpo):
        self.estado, self.cabeceras, self.cookies, self.cuerpo = estado, cabeceras, cookies, cuerpo


async def leer_respuesta(reader) -> Respuesta:
    linea = await reader.readline()
    if not linea:
        raise ConnectionError('conexión cerrada')
    estado = int(linea.split()[1])
    cabeceras, cookies = {}, []
    while True:
        l = await reader.readline()
        if l in (b'\r\n', b'\n', b''):
            break
        k, _, v = l.decode('latin1').partition(':')
        k = k.strip().lower()
        if k == 'set-cookie':
            cookies.append(v.strip())
        cabeceras[k] = v.strip()
    cuerpo = b''
    if cabeceras.get('transfer-encoding', '').lower() == 'chunked':
        while True:
            tam = int((await reader.readline()).strip().split(b';')[0], 16)
            if tam == 0:
                await reader.readline()
                break
            cuerpo += await reader.readexactly(tam)
            await reader.readexactly(2)
    elif 'content-length' in cabeceras:
        cuerpo = await reader.readexactly(int(cabeceras['content-length']))
    return Respuesta(estado, cabeceras, cookies, cuerpo)


class Usuario:
    def __init__(self, n: int, base: str, estadisticas: list, errores: list):
        self.n, self.estadisticas, self.errores = n, estadisticas, errores
        self.host, self.puerto = base.split(':')[0], int(base.split(':')[1])
        self.origen = f'http://localhost:{self.puerto}'
        self.cookie = ''
        self.reader = self.writer = None

    async def conectar(self):
        self.reader, self.writer = await asyncio.open_connection(self.host, self.puerto)

    async def pedir(self, nombre: str, metodo: str, ruta: str, cuerpo: str = '') -> Respuesta:
        cab = [f'{metodo} {ruta} HTTP/1.1', f'Host: localhost:{self.puerto}', 'Connection: keep-alive', 'Accept: text/html']
        if self.cookie:
            cab.append(f'Cookie: {self.cookie}')
        if metodo == 'POST':
            cab += [f'Origin: {self.origen}', 'Content-Type: application/x-www-form-urlencoded', f'Content-Length: {len(cuerpo.encode())}']
        t0 = time.perf_counter()
        try:
            self.writer.write(('\r\n'.join(cab) + '\r\n\r\n' + cuerpo).encode())
            await self.writer.drain()
            r = await asyncio.wait_for(leer_respuesta(self.reader), timeout=30)
        except Exception as e:  # noqa: BLE001 - cualquier fallo de red cuenta como error
            self.errores.append((nombre, f'{type(e).__name__}: {e}'))
            self.estadisticas.append((nombre, time.perf_counter() - t0, 0))
            await self.conectar()
            raise
        self.estadisticas.append((nombre, time.perf_counter() - t0, r.estado))
        for c in r.cookies:
            if c.startswith('lucas_session='):
                self.cookie = c.split(';')[0]
        return r

    async def sesion(self, hasta: float, lectura: tuple):
        await self.conectar()
        try:
            r = await self.pedir('GET /login.php', 'GET', '/login.php')
            token = re.search(rb'name="csrf_token" value="([a-f0-9]+)"', r.cuerpo)
            if not token:
                self.errores.append(('login', 'sin token CSRF'))
                return
            cuerpo = f'csrf_token={token.group(1).decode()}&correo=carga_{self.n}%40correo.com&clave=Carga%232026x'
            r = await self.pedir('POST /login.php (bcrypt)', 'POST', '/login.php', cuerpo)
            if r.estado != 302:
                self.errores.append(('login', f'estado {r.estado}'))
                return
            while time.time() < hasta:
                await asyncio.sleep(random.uniform(*lectura))  # la persona «lee» la página
                ruta = random.choice(PAGINAS)
                r = await self.pedir(f'GET {ruta}', 'GET', ruta)
                if r.estado != 200:
                    self.errores.append((ruta, f'estado {r.estado}'))
        except Exception:  # noqa: BLE001 - ya se anotó en pedir()
            pass
        finally:
            if self.writer:
                self.writer.close()


def pct(valores, p):
    valores = sorted(valores)
    return valores[min(len(valores) - 1, int(len(valores) * p))] if valores else 0.0


async def carga(args):
    estadisticas, errores = [], []
    inicio = time.time()
    hasta = inicio + args.ramp + args.duration
    usuarios = [Usuario(i + 1, args.base, estadisticas, errores) for i in range(args.users)]

    async def lanzar(u, retraso):
        await asyncio.sleep(retraso)
        await u.sesion(hasta, (args.think_min, args.think_max))

    t0 = time.time()
    await asyncio.gather(*(lanzar(u, random.uniform(0, args.ramp)) for u in usuarios))
    total = time.time() - t0

    print(f'\n{args.users} usuarios · {args.ramp:.0f} s de subida + {args.duration:.0f} s de carga · lectura entre páginas {args.think_min}-{args.think_max} s')
    print(f'Peticiones: {len(estadisticas)} en {total:.0f} s = {len(estadisticas) / total:.1f} pet/s · errores: {len(errores)}')
    print(f'\n{"Petición":34} {"n":>6} {"p50":>7} {"p95":>7} {"p99":>7} {"máx":>7}   (segundos)')
    grupos = {}
    for nombre, t, estado in estadisticas:
        grupos.setdefault(nombre, []).append(t)
    for nombre in sorted(grupos):
        v = grupos[nombre]
        print(f'{nombre:34} {len(v):6} {pct(v, .50):7.3f} {pct(v, .95):7.3f} {pct(v, .99):7.3f} {max(v):7.3f}')
    todas = [t for _, t, _ in estadisticas]
    print(f'{"TODAS":34} {len(todas):6} {pct(todas, .50):7.3f} {pct(todas, .95):7.3f} {pct(todas, .99):7.3f} {max(todas):7.3f}')
    codigos = {}
    for _, _, e in estadisticas:
        codigos[e] = codigos.get(e, 0) + 1
    print('Códigos HTTP:', dict(sorted(codigos.items())))
    if errores:
        resumen = {}
        for n, e in errores:
            resumen[(n, e)] = resumen.get((n, e), 0) + 1
        print('Errores (los más frecuentes):')
        for (n, e), c in sorted(resumen.items(), key=lambda x: -x[1])[:6]:
            print(f'  {c:5} × {n}: {e}')
    paginas = [t for n, t, _ in estadisticas if n.startswith('GET /') and n != 'GET /login.php']
    ok_p95 = pct(paginas, .95) < 3.0
    print(f'\nPáginas (sin login): p95 = {pct(paginas, .95):.3f} s → {"cumple" if ok_p95 else "NO cumple"} RNF-08 (< 3 s)')
    sys.exit(0 if not errores and ok_p95 else 1)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--users', type=int, default=500)
    ap.add_argument('--duration', type=float, default=60, help='segundos de carga sostenida tras la subida')
    ap.add_argument('--ramp', type=float, default=15, help='segundos en los que van llegando los usuarios')
    ap.add_argument('--think-min', type=float, default=2.0, help='lectura mínima entre páginas (s)')
    ap.add_argument('--think-max', type=float, default=8.0, help='lectura máxima entre páginas (s)')
    ap.add_argument('--base', default='localhost:8090')
    ap.add_argument('--setup', action='store_true', help='crea los usuarios de carga y sale')
    ap.add_argument('--cleanup', action='store_true', help='borra los usuarios de carga y sale')
    args = ap.parse_args()

    if args.cleanup:
        limpiar()
        print('Usuarios de carga borrados.')
        return
    if args.setup:
        preparar(args.users)
        return
    asyncio.run(carga(args))


if __name__ == '__main__':
    main()
