# Seeders y Datos Demo

## Seeders

Se ejecutan con `sail artisan migrate:fresh --seed`.

### RoleSeeder (`database/seeders/RoleSeeder.php`)

Crea los 4 roles del sistema con `firstOrCreate` (idempotente):
- `superadmin`
- `admin-empresa`
- `admin-sucursal`
- `cajero`

### DemoSeeder (`database/seeders/DemoSeeder.php`)

Crea un tenant de prueba con su sucursal y usuarios de cada rol.

## Datos de prueba

### Tenant

| Campo | Valor |
|-------|-------|
| Nombre | Carnicería El Toro |
| Slug | `el-toro` |
| RFC | TORO850101ABC |

### Sucursal

| Campo | Valor |
|-------|-------|
| Nombre | Sucursal Centro |
| Horario | Lun-Sáb 7am-8pm |

### Usuarios

Todos con password: `password`

| Email | Rol | Tenant | Sucursal |
|-------|-----|--------|----------|
| `superadmin@carniceria.test` | superadmin | — | — |
| `admin@eltoro.test` | admin-empresa | el-toro | — |
| `sucursal@eltoro.test` | admin-sucursal | el-toro | Sucursal Centro |
| `cajero@eltoro.test` | cajero | el-toro | Sucursal Centro |

### API Key

| Campo | Valor |
|-------|-------|
| Nombre | Kiosco Demo |
| Key | `csa_demo_test_key_for_development_only_1234` |

### Productos

| Nombre | Tipo | Precio |
|--------|------|--------|
| Bistec de res | cut | $180.00 |
| Chuleta de cerdo | cut | $120.00 |
| Arrachera | cut | $250.00 |
| Carne molida | kg | $130.00 |
| Pollo entero | piece | $85.00 |
| Costilla de res | kg | $160.00 |

## URLs de acceso por rol

| Rol | URL |
|-----|-----|
| superadmin | `http://localhost/admin` |
| admin-empresa | `http://localhost/el-toro/empresa` |
| admin-sucursal | `http://localhost/el-toro/sucursal` |
| cajero | `http://localhost/el-toro/caja` |
