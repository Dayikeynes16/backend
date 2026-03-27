# Corte de Caja (Cash Register Shifts)

Gestión de turnos del cajero. El turno registra cuándo abrió y cerró caja, y los totales acumulados.

## Responsabilidades

- Controlar que el cajero tenga un turno abierto antes de cobrar.
- Acumular totales por método de pago durante el turno.
- Generar un registro inmutable al cerrar el turno.
- Proveer historial de cortes al admin de sucursal.

**No hace:** no maneja fondo de caja ni arqueo. No permite reabrir un turno cerrado.

## Modelo Eloquent (`app/Models/CashRegisterShift.php`)

| Campo | Tipo | Notas |
|-------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | FK → tenants | |
| `branch_id` | FK → branches | |
| `user_id` | FK → users | El cajero que operó el turno |
| `opened_at` | timestamp | Momento de apertura |
| `closed_at` | timestamp nullable | Null = turno abierto |
| `total_cash` | decimal(12,2) | Se llena al cerrar |
| `total_card` | decimal(12,2) | Se llena al cerrar |
| `total_transfer` | decimal(12,2) | Se llena al cerrar |
| `total_sales` | decimal(12,2) | Suma de los 3 anteriores |
| `sale_count` | unsigned int | Número de ventas cobradas |
| `timestamps` | | |

**Usa `BelongsToTenant`.** Relaciones: `branch()`, `user()`.

**Inmutabilidad:** una vez que `closed_at` tiene valor, el registro no se modifica.

## Flujo del turno

```
1. Cajero accede a /{tenant}/caja
2. ¿Tiene turno abierto?
   NO  → Redirige a /{tenant}/caja/turno/abrir (OpenShift)
   SÍ  → Muestra cola de ventas (Queue)
3. Cajero cobra ventas (pendiente → completada)
4. Cajero va a /{tenant}/caja/turno (Shift)
   → Ve resumen: totales por método, conteo
5. Cajero presiona "Cerrar Turno"
   → Se calculan totales desde las ventas del turno
   → Se guarda closed_at + totales
   → Redirige a OpenShift
```

## Controllers

### `Caja\ShiftController` (`app/Http/Controllers/Caja/ShiftController.php`)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `create` | `GET /{tenant}/caja/turno/abrir` | Pantalla para abrir turno |
| `store` | `POST /{tenant}/caja/turno` | Crea shift con opened_at=now(). Previene duplicados. |
| `show` | `GET /{tenant}/caja/turno` | Resumen del turno activo con totales en tiempo real |
| `close` | `PATCH /{tenant}/caja/turno/cerrar` | Calcula totales, cierra el turno, redirige a abrir |

### `Caja\DashboardController` (`app/Http/Controllers/Caja/DashboardController.php`)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /{tenant}/caja/dashboard` | Dashboard del día: métricas + 10 ventas recientes |

### `Sucursal\ShiftController` (`app/Http/Controllers/Sucursal/ShiftController.php`)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `index` | `GET /{tenant}/sucursal/cortes` | Historial de cortes cerrados, filtro por fecha |

## Métricas del dashboard

- Total acumulado del turno
- Desglose por método de pago (efectivo / tarjeta / transferencia)
- Número de transacciones cobradas
- Promedio por venta
- Últimas 10 ventas cobradas

## Seguridad

- Solo el cajero dueño del turno puede cerrarlo.
- No se permite tener más de un turno abierto por usuario.
- `CajaSaleController@index` redirige a OpenShift si no hay turno abierto.
