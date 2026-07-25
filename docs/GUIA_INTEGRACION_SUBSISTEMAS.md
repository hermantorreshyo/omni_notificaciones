# Guía de Integración para Subsistemas · [1007] Notificaciones y Alertas
## OMNI API CORE v6.9 · JOSEPAN 360

> Documento único para quien va a **integrar el widget de notificaciones en
> un subsistema** ([1002] Compras, [1003] Almacenes, [1004] Producción, o
> cualquier subsistema futuro). Consolida en un solo lugar lo que antes
> estaba repartido entre `DEPLOYMENT.md` (el snippet) y
> `MANUAL_DESARROLLADOR.md` (el contrato) — esos documentos siguen siendo
> la referencia completa, este es el atajo práctico para esta tarea puntual.

---

## 1. Qué necesita tu subsistema antes de integrar

1. **Los archivos JS ya publicados**, servidos desde el subdominio dedicado
   `notificaciones.josepan.app` (no necesitas copiarlos a tu propio proyecto):
   - `https://notificaciones.josepan.app/assets/js/api-client.js`
   - `https://notificaciones.josepan.app/assets/js/notifications-widget.js`

   Cargar un `<script src>` cross-origin es seguro y no requiere CORS — el
   código se ejecuta en el contexto de tu página, así que las llamadas
   `fetch()` relativas dentro de `api-client.js` siguen resolviendo contra
   **tu propio dominio** (ej. `compras.josepan.app`), no contra
   `notificaciones.josepan.app`. Ver `docs/DEPLOYMENT.md` sección 2 para
   cómo se configuró ese subdominio.

   > Si por alguna razón tu subsistema no puede cargar recursos externos
   > (política de seguridad interna, CSP estricta), copia los 2 archivos a
   > tu propio `assets/js/` — el código es idéntico, solo cambia de dónde
   > se sirve.

2. **El proxy PHP ya funcionando** en tu subsistema — el widget llama a
   `/api/omni.php?action=notifications...`, el mismo proxy que ya usas para
   el resto de las llamadas a OMNI API CORE. Si tu subsistema ya consume
   otras rutas del core (inventario, traspasos, etc.), este paso ya está
   resuelto — no hay nada nuevo que configurar en el proxy.

3. **Que tu subsistema esté registrado como pantalla RBAC** — esto ya
   ocurrió en la migración de [1007] (`subsystem_screens` tiene filas para
   `1007`, no para tu subsistema — eso es aparte). Lo único que tu
   subsistema necesita es que los usuarios que lo usan tengan el permiso
   `notifications.read` asignado a su rol (ver sección 3).

## 2. El snippet exacto para integrar el widget

Pegar antes del cierre de `</body>` en el layout HTML principal de tu
subsistema (el archivo que se carga en todas las páginas autenticadas —
usualmente un `header.php`/`layout.php` compartido):

```html
<!-- Contenedor del widget: colócalo en el header, junto a los demás íconos de navegación -->
<div id="omni-notif-widget"></div>

<!-- Cargar DESPUÉS de que el usuario ya está autenticado en la página.
     Las librerías se sirven desde el subdominio centralizado -- así,
     cualquier actualización del widget se refleja en todos los
     subsistemas sin que cada uno tenga que redesplegar sus propios JS. -->
<script src="https://notificaciones.josepan.app/assets/js/api-client.js"></script>
<script src="https://notificaciones.josepan.app/assets/js/notifications-widget.js"></script>
<script>
  OmniNotificationsWidget.mount('omni-notif-widget');
</script>
```

**Dónde ponerlo exactamente**: en el `<header>` o barra de navegación
superior de tu layout, idealmente al lado del logo/menú de usuario —
mismo lugar donde ya viven otros íconos globales (ej. el selector de sede).

**Ejemplo de página completa integrada** (layout típico de un subsistema):

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mi Subsistema · JOSEPAN 360</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="app-header">
    <img src="/assets/img/logo.svg" alt="JOSEPAN">
    <nav><!-- menú de tu subsistema --></nav>

    <!-- Widget de notificaciones -->
    <div id="omni-notif-widget"></div>

    <div class="user-menu"><!-- selector de sede / usuario --></div>
  </header>

  <main>
    <!-- contenido de tu subsistema -->
  </main>

  <!-- Scripts de tu subsistema -->
  <script src="/assets/js/mi-subsistema.js"></script>

  <!-- Widget de notificaciones, servido desde notificaciones.josepan.app
       (orden: api-client antes que el widget) -->
  <script src="https://notificaciones.josepan.app/assets/js/api-client.js"></script>
  <script src="https://notificaciones.josepan.app/assets/js/notifications-widget.js"></script>
  <script>
    OmniNotificationsWidget.mount('omni-notif-widget');
  </script>
</body>
</html>
```

No hay nada que inicializar en JS de tu parte más allá de esas 3 líneas —
el widget se autoconfigura: pide notificaciones al montar, arranca el
polling de 60s, y maneja sus propios clicks internamente.

## 3. Permisos que deben tener tus usuarios

El widget llama a `GET /notifications`, `PATCH /notifications/{id}/read` y
`PATCH /notifications/read-all` — las tres requieren el permiso
`notifications.read` (ya existe en la tabla `permissions`, creado en la
migración de [1007]). Si tus usuarios ya tienen roles asignados en el
sistema, solo falta:

```sql
-- Verificar si el rol ya tiene el permiso
SELECT * FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.resource = 'notifications' AND p.action = 'read' AND rp.role_id = <tu_role_id>;

-- Si no aparece nada, asignarlo
INSERT INTO role_permissions (role_id, permission_id)
SELECT <tu_role_id>, id FROM permissions WHERE resource='notifications' AND action='read';
```

Sin este permiso, el widget carga visualmente pero el backend responde
`ERR_RBAC` y el panel queda vacío — no es un bug del widget, es que falta
este paso.

> El permiso `notifications.admin` (panel de monitoreo + CRUD de reglas)
> **no** es necesario para integrar el widget — es aparte, solo para
> quienes administran el módulo (ver `MANUAL_USUARIO.md` sección 7).

## 4. Checklist de integración

- [ ] `https://notificaciones.josepan.app/assets/js/api-client.js` responde 200 (confirmar antes de integrar — ver `docs/DEPLOYMENT.md` sección 2.5)
- [ ] `<div id="omni-notif-widget">` agregado al layout, en el header
- [ ] Las 2 líneas `<script src>` (apuntando al subdominio) + el `mount()` agregadas, en ese orden
- [ ] Confirmado que tu proxy responde a `action=notifications` (probar con
      la collection de Postman apuntando a tu dominio, o con `curl` directo)
- [ ] Al menos un rol de tu subsistema tiene `notifications.read` asignado
- [ ] Login con un usuario de prueba → la campana aparece y no da error RBAC
- [ ] Ver `docs/MANUAL_PRUEBAS.md` sección 6.1 para el checklist visual completo

## 5. Qué NO es parte de esta integración

Estas 3 cosas son responsabilidad de quien mantiene **OMNI API CORE**, no
de cada subsistema — no las repitas por cada integración:

1. Registrar las rutas de `NotificationController`/`NotificationRulesController`
   en el router central (una sola vez, para todo el ecosistema).
2. Enganchar `NotificationTriggerService` en `InventoryController` y en el
   cron de Pareto de vencimientos (una sola vez).
3. Aplicar las migraciones de [1007] (una sola vez, sobre la BD compartida).

Ver `docs/MANUAL_DESARROLLADOR.md` sección 6 para el detalle técnico de
esos tres puntos, y `docs/DEPLOYMENT.md` para los comandos exactos.

## 6. Dudas comunes al integrar

**El widget aparece pero el badge nunca cambia de número.**
Revisa la consola del navegador — si hay un error de CORS o 404 al llamar
`/api/omni.php?action=notifications`, el proxy no está resolviendo la
ruta. Confirma con la collection de Postman (`docs/MANUAL_PRUEBAS.md`)
que el endpoint responde bien fuera del navegador primero.

**¿El widget necesita saber el `interlocutor_id` o el subsistema?**
No — el proxy ya inyecta `X-Interlocutor-Id` a partir del JWT/sesión activa,
igual que en el resto de llamadas de tu subsistema. El widget no requiere
ninguna configuración de contexto adicional.

**¿Puedo tener más de un widget en la misma página?**
Sí, con IDs de contenedor distintos: `OmniNotificationsWidget.mount('otro-id')`.
No es un caso de uso típico, pero el código lo soporta (cada instancia
mantiene su propio estado y su propio `setInterval`).
