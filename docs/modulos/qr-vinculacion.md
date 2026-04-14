# Vinculación de báscula por QR

Flujo de auto-configuración que evita copiar/pegar manualmente la URL del servidor y la API Key larga en cada tablet.

## Flujo end-to-end

1. El admin de sucursal genera una nueva API Key en `Sucursal/Configuracion.vue`.
2. El backend responde con `newKey` como prop de Inertia (única vez que se expone en claro).
3. El panel verde muestra la key en texto + un **código QR** con el payload JSON completo.
4. En la tablet, la app `bascula` abre la cámara con `QrScannerDialog`, decodifica el QR y autocompleta URL + key.
5. Se dispara automáticamente `testConnection()` de la store `config`. Si es exitoso, redirige a `dashboard`.

## Payload del QR

```json
{
  "v": 1,
  "type": "carniceria-saas.setup",
  "baseUrl": "https://app.example.com",
  "apiKey": "csa_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "branch": "Sucursal Centro"
}
```

| Campo | Notas |
|-------|-------|
| `v` | Versión del formato. Permite evolucionar sin romper básculas ya desplegadas. |
| `type` | Discriminador. La báscula rechaza cualquier QR cuyo `type` no sea `carniceria-saas.setup`. |
| `baseUrl` | Se toma de `window.location.origin` en el SaaS (coincide con el host desde el que el admin abrió la sesión). |
| `apiKey` | Key en claro tal como se entrega a `newKey`. |
| `branch` | Opcional. Solo se usa para UX ("Conectando a..."). |

## SaaS — generación

- Componente: `Sucursal/Configuracion.vue`.
- Librería: `qrcode.vue@^3.8.1` (render SVG).
- El bloque `v-if="newKey"` construye un `computed qrPayload` con el JSON serializado y lo pasa a `<QrcodeVue :value="qrPayload" :size="180" level="M" render-as="svg" />`.
- Solo se muestra en esa sesión: al recargar la página `newKey` vuelve a `null` y el QR desaparece.

## Báscula — escaneo

- Componente modal: `src/components/QrScannerDialog.vue`.
- Librería: `qr-scanner@^1.4.2` (Nimiq). Usa `BarcodeDetector` nativo en Edge/Chrome en Windows (Surface Pro 7) con fallback a WebAssembly.
- Estados: `starting | scanning | denied | nocamera | invalid | error`.
- Cámara preferida: `environment` (trasera).
- Al decodificar, valida `v === 1 && type === 'carniceria-saas.setup'` y que existan `baseUrl` + `apiKey`. Si no, muestra un toast rojo inline y sigue escaneando.
- `onBeforeUnmount` llama `scanner.stop()` + `scanner.destroy()` para liberar el stream.

### UX del `SetupView`

- El campo **Nombre del equipo** (`deviceName`) está siempre visible arriba porque es único por báscula y no puede venir en el QR.
- Botón primario grande "Escanear QR" (desactivado hasta que se escribe el nombre del equipo).
- Link secundario "¿Sin cámara? Configurar manualmente" que despliega el formulario antiguo (URL + API Key) como plan B.
- Al escanear con éxito: la vista llama `connect()` internamente y navega a `dashboard` en cuanto la API valida la key.

## Restricciones

- **HTTPS obligatorio**: `getUserMedia` sólo funciona en `https://` o `localhost`. El SaaS corre en HTTPS y la báscula debe servirse desde HTTPS también (o `localhost` en dev).
- **Una cámara trasera presente**: si no hay cámara, el modal muestra "No se detectó cámara" y el admin debe usar el fallback manual.
- **QR no rotado**: el QR es equivalente a exponer la API Key en claro. Se muestra solo en la sesión en que se generó.

## Seguridad

- La API Key sigue siendo de un solo uso en visibilidad: tras recargar la página ya no hay forma de regenerar el QR — hay que crear una key nueva.
- El QR no contiene secretos extra más allá de la propia key. Misma clase de riesgo que el `<code>` con botón copiar.
- No se persiste el QR en BD ni en logs.
