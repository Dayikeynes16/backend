# Corte de Caja (Cash Register Shifts)

Gestión de turnos del cajero. El turno registra cuándo abrió y cerró caja, los totales acumulados, y permite reconciliación de efectivo.

## Responsabilidades

- Controlar que el cajero tenga un turno abierto antes de cobrar.
- Acumular totales por método de pago durante el turno.
- Calcular efectivo esperado: fondo inicial + efectivo cobrado - retiros.
- Comparar efectivo declarado vs esperado para detectar faltantes/sobrantes.
- Proveer historial de cortes al admin de sucursal.
- Permitir recalcular cortes cerrados (cuando se corrige un método de pago o se cancela una venta cobrada).
- Permitir reabrir turnos cerrados (admin).
- Auto-recalcular cortes al cancelar ventas completadas.

## Modelo Eloquent (`app/Models/CashRegisterShift.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | |
| `branch_id` | FK → branches | |
| `user_id` | FK → users | El cajero que operó el turno |
| `opened_at` | timestamp | Momento de apertura |
| `opening_amount` | decimal(12,2) | Fondo inicial de caja |
| `closed_at` | timestamp nullable | Null = turno abierto |
| `total_cash` | decimal(12,2) | Se llena al cerrar |
| `total_card` | decimal(12,2) | Se llena al cerrar |
| `total_transfer` | decimal(12,2) | Se llena al cerrar |
| `total_sales` | decimal(12,2) | Suma de los 3 anteriores |
| `sale_count` | unsigned int | Número de ventas únicas cobradas |
| `declared_amount` | decimal(12,2) | Efectivo declarado por el cajero al cerrar |
| `declared_card` | decimal(12,2) nullable | Tarjeta declarada por el cajero al cerrar |
| `declared_transfer` | decimal(12,2) nullable | Transferencia declarada por el cajero al cerrar |
| `expected_amount` | decimal(12,2) | Efectivo esperado calculado |
| `difference` | decimal(12,2) | declarado_efectivo - esperado (+sobrante / -faltante) |
| `difference_card` | decimal(12,2) | declarado_tarjeta - total_card |
| `difference_transfer` | decimal(12,2) | declarado_transfer - total_transfer |
| `notes` | text nullable | Observaciones del cajero al cerrar (descuadres) |
| `timestamps` | | |

**Usa `BelongsToTenant`.** Relaciones: `branch()`, `user()`, `withdrawals()`.

## Modelo Payment - Auditoría y SoftDeletes

| Campo | Tipo | Notas |
|-------|------|-------|
| `updated_by` | FK → users nullable | Quién modificó el pago (solo se llena al editar) |
| `deleted_at` | timestamp nullable | SoftDeletes — pagos de ventas canceladas |

- Cuando un admin corrige el método de pago o monto, se registra su `user_id` en `updated_by`.
- Al cancelar una venta, los pagos se soft-deleted. Las queries normales los excluyen automáticamente.

## Fórmulas

```
Efectivo esperado = Fondo inicial + Σ(pagos en efectivo NO eliminados) - Σ(retiros)
Diferencia efectivo = Efectivo declarado - Efectivo esperado
Diferencia tarjeta = Tarjeta declarada - total_card (registrado en sistema)
Diferencia transferencia = Transfer declarada - total_transfer (registrado en sistema)
Diferencia total = Σ(diferencia efectivo + diferencia tarjeta + diferencia transferencia)

sale_count = cantidad de ventas únicas (no pagos individuales)
```

## Flujo del turno

```
1. Cajero accede a /{tenant}/caja/turno
2. ¿Tiene turno abierto?
   NO  → Pantalla para abrir turno (ingresa fondo inicial)
   SÍ  → Muestra resumen con totales en tiempo real
3. Cajero cobra ventas en la mesa de trabajo
4. Cajero va a turno → ve resumen: totales por método, efectivo esperado
5. Cajero ve sección de conciliación con 3 inputs:
   - Efectivo físico en caja
   - Monto confirmado en terminal de tarjeta
   - Monto confirmado en banca móvil (transferencias)
6. El sistema muestra diferencia en tiempo real por cada método
7. Cajero presiona "Cerrar Turno":
   - Si hay diferencias → se pide confirmación explícita
   - Se puede agregar observaciones opcionales para explicar descuadres
   → Se calculan totales y diferencias por método
   → Se guarda closed_at + totales + diferencias + notas
   → Redirige a abrir turno
```

## Corrección de errores en método de pago

Si un cajero registra un pago con el método incorrecto (ej: efectivo como tarjeta):

1. **Antes del cierre:** Un admin puede editar el pago desde la mesa de trabajo.
2. **Después del cierre:** Un admin puede:
   - **Recalcular** el corte: re-consulta los pagos actuales y actualiza los totales.
   - **Reabrir** el turno: limpia los totales y permite al cajero seguir operando.

## Cancelación de ventas completadas

El admin puede cancelar ventas ya cobradas. Al hacerlo:

1. Los pagos se **soft-deleted** (no se eliminan fisicamente).
2. Si el turno del cajero está **abierto**: los totales en vivo se ajustan automáticamente (las queries excluyen soft-deleted).
3. Si el turno del cajero está **cerrado**: el sistema **auto-recalcula** los totales del corte afectado.
4. El cajero **no se ve afectado**: no necesita hacer nada. La diferencia del corte se ajusta sola.

### Flujo:
```
Admin selecciona venta cobrada → "Cancelar venta"
  → Se muestra advertencia: "Esta venta ya fue cobrada"
  → Admin confirma con motivo
  → Pagos soft-deleted, venta → cancelled
  → Cortes cerrados afectados se recalculan automáticamente
  → Cortes abiertos se ajustan en vivo (queries excluyen deleted)
```

### Desde el cajero:
- El cajero ve las ventas cobradas del día en su mesa de trabajo.
- Puede **solicitar cancelación** (requiere aprobación del admin).
- Al aprobar, el mismo flujo de auto-recalculation aplica.

## Controllers

### `Sucursal\CashShiftController`

| Método | Ruta | Descripción |
|--------|------|-------------|
| `active` | `GET /{tenant}/sucursal/turno` | Turno activo o pantalla de apertura |
| `open` | `POST /{tenant}/sucursal/turno/abrir` | Abre turno con fondo inicial |
| `close` | `POST /{tenant}/sucursal/turno/cerrar` | Cierra turno con monto declarado |
| `history` | `GET /{tenant}/sucursal/cortes` | Historial de cortes cerrados |
| `show` | `GET /{tenant}/sucursal/cortes/{shift}` | Detalle de un corte |
| `recalculate` | `POST /{tenant}/sucursal/cortes/{shift}/recalcular` | Recalcula totales (admin) |
| `reopen` | `POST /{tenant}/sucursal/cortes/{shift}/reabrir` | Reabre turno cerrado (admin) |

### `Caja\TurnoController`

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /{tenant}/caja/turno` | Turno activo o pantalla de apertura |
| `open` | `POST /{tenant}/caja/turno/abrir` | Abre turno |
| `close` | `POST /{tenant}/caja/turno/cerrar` | Cierra turno |

### `Sucursal\PaymentController`

| Método | Ruta | Auth | Notas |
|--------|------|------|-------|
| `store` | `POST .../pagos` | Cualquier usuario con turno | Registra pago |
| `update` | `PUT .../pagos/{payment}` | Admin | Corrige método/monto, registra `updated_by` |
| `destroy` | `DELETE .../pagos/{payment}` | Admin | Elimina pago |

### `Sucursal\WorkbenchController`

| Método | Ruta | Auth | Notas |
|--------|------|------|-------|
| `cancel` | `PATCH .../cancelar` | Admin | Cancela ventas activas Y completadas. Auto-recalcula shifts. |
| `requestCancel` | `POST .../solicitar-cancelacion` | Todos | Solicita cancelación (incluye ventas completadas) |

## Seguridad

- Solo el cajero dueño del turno puede cerrarlo.
- No se permite tener más de un turno abierto por usuario.
- Solo admins pueden recalcular o reabrir turnos cerrados.
- Solo admins pueden editar/eliminar pagos.
- Solo admins pueden cancelar ventas directamente (cajeros solo solicitan).
- Al reabrir, se verifica que el cajero no tenga otro turno abierto.
- Ediciones de pagos quedan auditadas con `updated_by`.
- Cancelación de ventas cobradas muestra advertencia explícita.
