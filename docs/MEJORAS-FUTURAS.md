# Propuestas de Evolución y Nuevas Funcionalidades (Post-MVP)

Con el **MVP de Lucas** cerrado, probado de punta a punta y endurecido en seguridad y compatibilidad multiplataforma, este documento presenta la hoja de ruta recomendada para llevar la aplicación al nivel de un **gestor de finanzas personales y familiares de grado profesional**.

> **Estado actual (Fase 20):** las **13 propuestas** descritas en este documento ya están **implementadas, probadas y auditadas** en el repositorio (ver [`docs/AUDITORIA.md`](AUDITORIA.md)).

Cada propuesta describe **qué mejora en el producto**, **por qué debería implementarse** (valor para el usuario y para el negocio/arquitectura) y **cómo encaja técnicamente** en el diseño actual de Lucas (`PHP 8.4` + `MySQL 8.4`, sin perder precisión en centavos ni privacidad por diseño).

---

## Matriz Resumen por Prioridad de Implementación

| Prioridad | Orden | Propuesta | Complejidad | Estado | Impacto en el Usuario |
|---|---|---|---|---|---|
| **P1 · Crítica** | 1 | **Transferencias entre cuentas propias y pago de tarjetas de crédito** | Baja | ✅ Implementada | Elimina la fricción #1 del uso diario (mover dinero sin inflar ingresos ni gastos). |
| **P1 · Crítica** | 2 | **Movimientos recurrentes y suscripciones programadas** | Media | ✅ Implementada | Automatiza gastos fijos (arriendo, servicios, internet) y evita olvidos mensuales. |
| **P1 · Crítica** | 3 | **Espacios compartidos / Cuenta Familiar (Multiusuario colaborativo)** | Alta | ✅ Implementada | Abre Lucas a parejas, familias y socios manteniendo intacta la privacidad personal. |
| **P2 · Alta** | 4 | **Módulo de Deudas, cálculo de intereses y estrategias de pago (Bola de Nieve / Avalancha)** | Media-Alta | ✅ Implementada | Guía paso a paso para salir de deudas ahorrando intereses y con abonos recurrentes. |
| **P2 · Alta** | 5 | **Clonación inteligente de presupuestos y regla 50/30/20** | Baja | ✅ Implementada | Evita recrear presupuestos desde cero el día 1 de cada mes. |
| **P2 · Alta** | 6 | **Importación bancaria asistida (CSV) y conciliación de saldos** | Media | ✅ Implementada | Reduce drásticamente el tiempo de digitación manual y corrige descuadres. |
| **P2 · Alta** | 7 | **División de movimientos (*Split Transactions*) y etiquetas (*Tags*)** | Media | ✅ Implementada | Permite repartir una compra de supermercado en varias categorías o etiquetar un viaje. |
| **P3 · Media** | 8 | **Gestión avanzada de tarjetas de crédito (cuotas, fecha de corte y pago)** | Media-Alta | ✅ Implementada | Adapta Lucas a la realidad financiera latinoamericana (compras diferidas a *N* cuotas). |
| **P3 · Media** | 9 | **Aplicación Web Progresiva (PWA) e instalación en móvil** | Baja-Media | ✅ Implementada | Experiencia de app nativa desde la pantalla de inicio del celular sin tiendas de apps. |
| **P3 · Media** | 10 | **Autenticación en dos pasos (2FA con TOTP) y gestión de sesiones activas** | Media | ✅ Implementada | Eleva la seguridad de la cuenta al estándar bancario moderno. |
| **P4 · Evolutiva** | 11 | **Multimoneda por cuenta con tasa de cambio manual o referencial** | Media | ✅ Implementada | Permite ahorrar en dólares/euros y gastar en pesos con patrimonio neto consolidado. |
| **P4 · Evolutiva** | 12 | **Adjuntos de comprobantes (facturas/recibos) y reportes imprimibles** | Media | ✅ Implementada | Respaldo documental para garantías, declaración de renta o rendición de cuentas. |
| **P4 · Evolutiva** | 13 | **Alertas proactivas por correo y resumen financiero semanal/mensual** | Baja-Media | ✅ Implementada | Mantiene el hábito financiero sin exigir que el usuario recuerde abrir la aplicación. |

---

## Prioridad 1 · Fundamentales para el Uso Diario y Colaborativo

### 1. Transferencias entre cuentas propias y abono a tarjetas de crédito
- **¿Qué mejora?**
  Actualmente, si una persona retira `$200.000` del banco para pasarlos a su billetera en efectivo, o si paga la cuota de su tarjeta de crédito desde su cuenta de ahorros, tiene que registrar un «Gasto» en una cuenta y un «Ingreso» en la otra. Eso **distorsiona los reportes del mes**, haciendo parecer que ganó y gastó `$200.000` adicionales.
- **¿Por qué se debería implementar?**
  En la contabilidad personal real existen tres flujos básicos: **Ingreso**, **Gasto** y **Transferencia**. Separar las transferencias de los gastos/ingresos hace que los gráficos de evolución mensual y los totales de ingresos/gastos sean 100 % fieles a la realidad, y permite registrar el pago de la tarjeta de crédito subiendo su saldo (reduciendo la deuda) y bajando el de la cuenta bancaria en un solo paso.
- **Enfoque técnico en Lucas:**
  - Añadir la tabla `transferencias` (`id_transferencia`, `id_usuario`, `id_cuenta_origen`, `id_cuenta_destino`, `monto`, `fecha`, `descripcion`) o un par enlazado en `transacciones`.
  - Actualizar la fórmula de `CuentaRepository` para restar las transferencias salientes y sumar las entrantes, bloqueando ambas cuentas (`FOR UPDATE` en orden de `id_cuenta` ascendente para evitar *deadlocks*).

### 2. Movimientos recurrentes, gastos fijos y recordatorios de vencimiento
- **¿Qué mejora?**
  Agrega una sección de **«Programados / Recurrentes»** donde el usuario define una plantilla (p. ej. *Arriendo: `$1.200.000` cada día 5 del mes*; *Salario: cada día 15 y 30*; *Netflix: cada día 18*) con dos modos: **registro automático** en la fecha o **pendiente de confirmación** con un clic en el panel.
- **¿Por qué se debería implementar?**
  El 60 %–70 % de los movimientos mensuales de un hogar son repetitivos. Automatizarlos reduce la fatiga de registro (la principal causa por la que las personas abandonan las apps de finanzas tras el primer mes) y permite proyectar en el panel **«Cuánto dinero libre te queda después de tus compromisos fijos del mes»**.
- **Enfoque técnico en Lucas:**
  - Tabla `movimientos_recurrentes` (`frecuencia`: mensual, quincenal, semanal, anual; `proxima_fecha`; `auto_aplicar`).
  - Un comando de consola ligero (`scripts/procesar-recurrentes.php`) ejecutado por `cron` diario (o al iniciar sesión el usuario) que materializa los movimientos vencidos dentro de una transacción.

### 3. Espacios compartidos / Cuenta Familiar (Multiusuario colaborativo)
- **¿Qué mejora?**
  Permite que dos o más usuarios registrados en Lucas creen un **Espacio Compartido** (p. ej. *«Hogar Familia Restrepo»*, *«Gastos Pareja»* o *«Viaje a Santa Marta»*) sin perder su espacio personal privado.
  Cada usuario tiene un selector rápido en la barra lateral para alternar entre **«Mi espacio personal»** y **«Hogar / Familia»** (o ver sus cuentas compartidas integradas):
  - **Cuentas comunes:** fondo común de la casa, cuenta bancaria conjunta o alcancía familiar.
  - **Presupuestos y metas familiares:** mercado del hogar, servicios públicos, ahorro para vacaciones familiares.
  - **Trazabilidad por miembro:** cada movimiento registrado en el espacio familiar muestra el avatar y nombre de quién lo registró (*«Camila registró Mercado · −$280.000»*) y permite ver cuánto ha aportado cada miembro al fondo común.
  - **Roles dentro del espacio familiar:** *Propietario* (invita/retira miembros, administra cuentas), *Editor* (registra movimientos y aportes) y *Lector* (solo consulta).
- **¿Por qué se debería implementar?**
  Las finanzas rara vez son 100 % individuales: parejas y familias necesitan visibilidad compartida sobre los gastos del hogar, pero al mismo tiempo cada persona quiere conservar su privacidad sobre sus cuentas y gastos individuales. Este modelo híbrido (**espacio personal privado + espacio familiar compartido por invitación**) es uno de los mayores diferenciadores frente a las hojas de cálculo y a las aplicaciones bancarias tradicionales.
- **Enfoque técnico en Lucas:**
  ```mermaid
  erDiagram
      ESPACIOS ||--o{ ESPACIO_MIEMBROS : integra
      USUARIOS ||--o{ ESPACIO_MIEMBROS : participa
      ESPACIOS ||--o{ CUENTAS : posee
      ESPACIOS ||--o{ CATEGORIAS : define
      ESPACIOS ||--o{ TRANSACCIONES : registra
      ESPACIOS ||--o{ PRESUPUESTOS : fija
      ESPACIOS ||--o{ METAS_AHORRO : ahorra
  ```
  - Introducir la entidad `espacios` (al registrarse cada usuario recibe su espacio personal por defecto, y puede crear o aceptar invitaciones por correo/token a espacios familiares).
  - Las consultas de los repositorios pasan de filtrar por `id_usuario` a filtrar por `id_espacio` activo en la sesión (conservando `id_usuario_autor` en `transacciones` y `aportes_meta` para saber quién hizo cada registro).

---

## Prioridad 2 · Control de Deudas, Productividad y Análisis Financiero

### 4. Módulo de Deudas, cálculo de intereses y estrategias de pago (Bola de Nieve y Avalancha)
- **¿Qué mejora?**
  Incorpora un módulo dedicado a **Salir de Deudas** (préstamos bancarios, créditos de libre inversión, créditos educativos, hipotecas, tarjetas de crédito o préstamos familiares) como contraparte de las *Metas de ahorro*:
  - **Ficha de cada deuda:** monto original, saldo pendiente actual, tasa de interés (E.A. o M.V., con conversión automática), cuota mínima mensual, día de pago y plazo restante.
  - **Desglose de cada pago (Capital vs. Intereses + Seguros):** al registrar un pago (o programarlo como **pago recurrente** desde una cuenta activa), Lucas calcula y muestra cuánto fue a **intereses** (el costo real del dinero) y cuánto amortizó el **capital** de la deuda, permitiendo también registrar **abonos extraordinarios a capital** y ver cuántos meses e intereses se ahorró la persona.
  - **Simulador y planificador de estrategias de desendeudamiento:** el usuario define un presupuesto mensual total para pagar deudas (la suma de sus cuotas mínimas + un monto extra que pueda destinar) y Lucas compara interactivamente dos planes:
    1. **Método Bola de Nieve (*Debt Snowball*):** ordena las deudas de **menor a mayor saldo**. Se pagan las cuotas mínimas de todas y todo el excedente se concentra en liquidar la más pequeña primero; cuando esa cae, su cuota entera se suma al pago de la siguiente (genera victorias rápidas y motivación psicológica).
    2. **Método Avalancha (*Debt Avalanche*):** ordena las deudas de **mayor a menor tasa de interés**. Todo el excedente ataca primero la deuda más cara (matemáticamente óptima: minimiza el total de intereses pagados y el tiempo total de endeudamiento).
  - **Comparativa visual:** muestra exactamente la *«Fecha estimada libre de deudas»* y *«Cuánto dinero en intereses te ahorras»* con Bola de Nieve vs. Avalancha vs. pagar solo las cuotas mínimas.
- **¿Por qué se debería implementar?**
  Para gran parte del público objetivo de Lucas (jóvenes, trabajadores independientes y familias), **salir de deudas es el paso previo e indispensable antes de poder ahorrar**. La mayoría de las personas solo ven la cuota mensual sin entender cuánto de su pago se va en intereses ni cuál deuda les conviene atacar primero. Integrar el cálculo de amortización, pagos recurrentes y las estrategias Bola de Nieve / Avalancha convierte a Lucas en un verdadero asesor de salud financiera.
- **Enfoque técnico en Lucas:**
  - Tablas `deudas` (`id_deuda`, `id_usuario`, `nombre`, `acreedor`, `monto_inicial`, `saldo_capital`, `tasa_mensual_bps` en puntos básicos enteros para no usar `float`, `cuota_minima`, `dia_pago`, `estado`) y `pagos_deuda` (`id_pago`, `id_deuda`, `id_cuenta`, `fecha`, `monto_total`, `abono_capital`, `pago_interes`).
  - Motor puro `App\Deudas\PlanificadorDeudas` (probado en `unit.php`) que proyecta mes a mes las tablas de amortización bajo *Solo mínimos*, *Bola de Nieve* y *Avalancha* usando aritmética entera en centavos y puntos básicos.

### 5. Clonación inteligente de presupuestos mensuales y asistente 50/30/20
- **¿Qué mejora?**
  En `presupuestos.php`, cuando un usuario entra a un mes nuevo que aún no tiene presupuestos, en lugar de ver solo el estado vacío, encuentra el botón **«Copiar presupuestos del mes anterior»** (con opción de ajustar los montos en lote antes de guardar) y una calculadora guiada de la **regla 50/30/20** (50 % necesidades básicas, 30 % estilo de vida, 20 % ahorro/pago de deudas) basada en sus ingresos habituales.
- **¿Por qué se debería implementar?**
  Actualmente los presupuestos se definen por `(categoría, mes, año)`. Copiarlos con un solo clic elimina la tarea repetitiva de crear 6 u 8 presupuestos cada primero de mes y educa financieramente a los usuarios que no saben cuánto asignar a cada categoría.

### 6. Importación bancaria asistida (CSV) y conciliación de saldos
- **¿Qué mejora?**
  - **Conciliación rápida («Ajustar saldo»):** botón en cada cuenta donde el usuario escribe cuánto dinero real tiene hoy en su banco o billetera; si hay diferencia con el saldo calculado en Lucas, ofrece crear automáticamente un movimiento de ajuste por la diferencia.
  - **Importador de extractos CSV:** asistente de 3 pasos (subir CSV del banco → mapear columnas Fecha/Descripción/Monto → previsualizar y asignar categorías antes de importar), con detección de duplicados por fecha y monto.
- **¿Por qué se debería implementar?**
  Si una persona olvida anotar tres gastos pequeños en efectivo durante la semana, su saldo en Lucas deja de coincidir con su billetera y pierde confianza en el sistema. El botón de conciliación resuelve ese descuadre en 5 segundos, y el importador CSV permite cargar un mes entero de extracto bancario sin digitar fila por fila.

### 7. División de movimientos (*Split Transactions*) y etiquetas transversales (*Tags*)
- **¿Qué mejora?**
  - **Movimientos divididos:** permite que un solo pago de `$300.000` en un hipermercado se divida en `$220.000` para *Alimentación*, `$50.000` para *Mascotas* y `$30.000` para *Hogar*, descontando un único movimiento de `$300.000` en la cuenta pero sumando cada parte a su respectivo presupuesto.
  - **Etiquetas (`#vacaciones-2027`, `#remodelacion`, `#impuestos`):** agrupan gastos que cruzan distintas categorías (un viaje incluye gastos de *Transporte*, *Alimentación* y *Entretenimiento*).
- **¿Por qué se debería implementar?**
  Resuelve el dilema clásico de clasificación: las **categorías** responden *«en qué tipo de bien gasté»* (para los presupuestos mensuales), mientras que las **etiquetas** responden *«para qué proyecto o evento gasté»* (para reportes transversales).

---

## Prioridad 3 · Especialización Local, Móvil y Seguridad Avanzada

### 8. Gestión avanzada de tarjetas de crédito (compras a cuotas, fecha de corte y fecha de pago)
- **¿Qué mejora?**
  En Colombia y Latinoamérica las tarjetas de crédito se usan frecuentemente con **compras diferidas a *N* cuotas** (1 a 36 meses). Esta mejora añade a las cuentas de tipo `tarjeta_credito`:
  - Día de corte y día límite de pago.
  - Selector de **número de cuotas** al registrar un gasto con tarjeta.
  - Cálculo de la **cuota del mes actual** frente a la **deuda total acumulada**, para que un gasto a 6 cuotas no rompa el presupuesto de un solo mes si el usuario prefiere presupuestar por flujo de caja mensual.
- **¿Por qué se debería implementar?**
  Convierte una simple cuenta con saldo negativo en una herramienta real de control de endeudamiento que avisa cuánto hay que pagar en el próximo corte para no generar intereses de mora.

### 9. Aplicación Web Progresiva (PWA) instalable
- **¿Qué mejora?**
  Añade un `manifest.webmanifest`, iconos adaptables y un *Service Worker* ligero para los activos estáticos (`css/`, `js/`, `favicon.svg`).
- **¿Por qué se debería implementar?**
  Cumple con RNF-10 (*«registrar un movimiento en menos de 3 clics»*) en el mundo real: cuando la persona está pagando en una tienda con su celular, puede abrir **Lucas** desde el icono en su pantalla de inicio a pantalla completa (sin barra de direcciones del navegador), con carga instantánea de estilos y scripts.

### 10. Autenticación de dos factores (2FA TOTP) y panel de sesiones activas
- **¿Qué mejora?**
  - **2FA opcional con apps autenticadoras** (Google Authenticator, Authy, Bitwarden) implementado en PHP puro mediante el estándar RFC 6238 (`HMAC-SHA1` sobre ventana de 30 s, sin librerías externas) y códigos de respaldo de un solo uso.
  - **Historial y cierre de sesiones en `perfil.php`:** muestra desde qué dispositivos/navegadores hay sesiones abiertas y permite cerrar cualquiera de forma remota.
- **¿Por qué se debería implementar?**
  Los datos financieros personales son altamente sensibles. Ofrecer 2FA y visibilidad de sesiones activas brinda tranquilidad al usuario y posiciona a Lucas al nivel de seguridad de la banca digital.

---

## Prioridad 4 · Expansión y Automatización

### 11. Multimoneda por cuenta con patrimonio neto consolidado
- **¿Qué mejora?**
  Actualmente la moneda se elige a nivel de perfil y solo cambia el símbolo visual. Esta mejora permite que cada **cuenta** tenga su propia moneda (`COP`, `USD`, `EUR`, `MXN`) y que el usuario registre una tasa de cambio de referencia para ver su **patrimonio total consolidado** en su moneda principal.
- **¿Por qué se debería implementar?**
  Muchos trabajadores independientes y jóvenes ahorran en dólares (`USD`) o tienen cuentas en el exterior mientras sus gastos diarios son en pesos (`COP`).

### 12. Adjuntos de comprobantes (recibos/facturas) y reportes PDF imprimibles
- **¿Qué mejora?**
  - Permite adjuntar una foto o PDF comprimido del recibo/factura a un movimiento (almacenado fuera de `public/`, servido por un controlador autenticado que verifica propiedad y fuerza `Content-Disposition: attachment` / `X-Content-Type-Options: nosniff`).
  - Hoja de estilos `@media print` dedicada en `reportes.php` para exportar el informe mensual limpio en PDF directamente desde el navegador.
- **¿Por qué se debería implementar?**
  Útil para conservar garantías de electrodomésticos, soportes para declaración de renta o recibos de pagos compartidos en la cuenta familiar.

### 13. Notificaciones proactivas por correo y resumen mensual automático
- **¿Qué mejora?**
  Aprovechando el cliente `App\Mail\SmtpMailer` ya construido en el MVP, permite al usuario activar en su perfil:
  - Alerta por correo cuando un presupuesto supera el 80 % o el 100 % (especialmente útil en **Cuentas Familiares**, cuando otro miembro registra un gasto que agota el presupuesto común).
  - Resumen ejecutivo el día 1 de cada mes con el balance del mes anterior, la categoría donde más gastó y el avance de sus metas de ahorro.
- **¿Por qué se debería implementar?**
  Transforma a Lucas de una herramienta pasiva (que solo informa cuando entras) a un asistente financiero activo que acompaña al usuario y a su familia.
