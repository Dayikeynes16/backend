# Guía: Vincular una báscula por código QR

Esta guía explica paso a paso cómo conectar una tablet nueva (báscula) con el sistema usando el código QR, evitando tener que copiar manualmente la URL y la API Key.

> Tiempo estimado: **menos de 1 minuto** por báscula.

---

## Antes de empezar

Necesitas:

- Ser **Administrador de Sucursal** en el sistema SaaS.
- Tener acceso a la tablet (Surface Pro 7) donde se instalará la báscula.
- Que la tablet tenga **cámara trasera funcional**.
- Que la tablet tenga conexión a internet.
- El navegador de la báscula debe abrirse en **HTTPS** (la cámara no funciona en conexiones sin cifrar).

---

## Paso 1 — Generar la API Key en el panel

1. Entra al SaaS como **admin de sucursal** y abre **Configuración** en el menú lateral.
2. Baja a la sección **API Keys**.
3. Haz clic en **Nueva Key**.
4. Ponle un nombre descriptivo (por ejemplo: *"Balanza Mostrador"*, *"Caja Norte"*).
5. Haz clic en **Generar**.

Verás un panel verde arriba con:

- La API Key en texto (con botón **Copiar** por si la necesitas).
- Un **código QR** grande.
- El mensaje *"Vincular báscula por QR"*.

⚠️ **Importante:** este panel **solo se muestra una vez**. Si recargas la página, el QR desaparece y tendrás que generar otra key. No cierres la pantalla hasta terminar de vincular la báscula.

---

## Paso 2 — Preparar la báscula

1. En la tablet, abre la aplicación **Bascula**.
2. Si es la primera vez que se usa, aparecerá la pantalla de **Configuración**.
3. Si ya estaba configurada y quieres reconectarla a otra sucursal, entra a la pantalla de configuración desde el menú.
4. Escribe el **Nombre del equipo** (por ejemplo: *"Balanza 1"*, *"Caja Norte"*). Este nombre aparecerá en las ventas y te permite identificar qué equipo generó cada una.

---

## Paso 3 — Escanear el QR

1. En la pantalla de configuración de la báscula, toca el botón rojo **Escanear QR**.
2. La primera vez, el navegador pedirá permiso para usar la cámara — toca **Permitir**.
3. Apunta la cámara trasera de la tablet al QR que aparece en la pantalla del admin.
4. La báscula detectará el código, se conectará automáticamente y te llevará al **Dashboard** en menos de 2 segundos.

Verás un mensaje verde *"Conexión exitosa — Sucursal: [nombre]"* antes del redirect.

---

## Si algo falla

### "Permiso de cámara denegado"

El navegador bloqueó el acceso a la cámara. Soluciones:

- Edge/Chrome: haz clic en el ícono del candado junto a la URL → **Permisos del sitio** → **Cámara: Permitir** → recarga.
- Si no quieres dar permiso: usa el botón **"¿Sin cámara? Configurar manualmente"** que está justo debajo del botón de escanear.

### "El QR no pertenece al sistema"

Estás escaneando un código QR que no es el de la configuración del SaaS (por ejemplo, un QR de WhatsApp, un link aleatorio, etc.). Asegúrate de apuntar al QR del panel de admin.

### "Permiso de cámara denegado" o "No se detectó cámara"

La tablet no tiene cámara o está bloqueada por el sistema. Usa el modo manual:

1. Toca **"¿Sin cámara? Configurar manualmente"**.
2. Copia la **URL del sistema** (ej: `https://app.carniceria.com`).
3. Copia la **API Key** completa desde el panel del admin (botón **Copiar**).
4. Pégala en el campo de la báscula.
5. Toca **Conectar manualmente**.

### El QR ya no aparece en mi panel

Recargaste la página y la key desapareció (por diseño — solo se muestra una vez). Dos opciones:

1. Si ya copiaste la key: usa el modo manual en la báscula.
2. Si no la copiaste: **revoca** la key que acabas de crear (aparecerá en la lista de activas) y genera una nueva.

---

## Revocar una báscula

Si una tablet se pierde, se vende, o ya no debe tener acceso:

1. Ve a **Configuración → API Keys** como admin de sucursal.
2. Encuentra la key con el nombre de esa báscula.
3. Haz clic en **Revocar** y confirma.

La báscula dejará de funcionar inmediatamente en la siguiente petición. La key pasará a la lista de *"Revocadas"*.

---

## Preguntas frecuentes

**¿Puedo usar el mismo QR para varias básculas?**
No es recomendable. Cada báscula debe tener su propia key para poder revocar una sin afectar las otras y para identificar qué equipo hizo cada venta.

**¿El QR contiene mi contraseña?**
No. Contiene la URL del sistema y la API Key de sucursal. No contiene tu usuario ni contraseña.

**¿Puedo escanear el QR desde mi celular primero y luego compartirlo?**
Técnicamente sí, pero **no lo hagas**: el QR es equivalente a una llave de acceso. Trátalo como tal. Escanea directo desde la báscula y cierra el panel del admin al terminar.

**¿Qué pasa si pierdo el QR antes de escanearlo?**
Revoca la key recién creada y genera una nueva.

**¿La báscula queda vinculada para siempre?**
Sí, hasta que revoques la key desde el panel de admin o limpies la configuración desde la misma báscula.
