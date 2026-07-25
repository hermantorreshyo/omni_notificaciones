# Manual de Usuario · [1007] Notificaciones y Alertas Centralizadas
## JOSEPAN 360 · Ecosistema OMNI

> Para quién es este manual: cualquier persona que use un subsistema OMNI
> ([1002] Compras, [1003] Almacén, [1004] Producción) y vea el ícono de
> campana 🔔, y para quien administre las reglas de alerta desde el Panel
> Admin. No se requieren conocimientos técnicos.

---

## 1. ¿Qué es la campana de notificaciones?

En la parte superior de cada aplicación verás un ícono de campana 🔔. Si
tienes alertas pendientes, aparece un número rojo encima — esa es la
cantidad de notificaciones que aún no has leído.

Al tocar la campana se abre un panel con el listado de tus notificaciones,
de la más reciente a la más antigua.

## 2. Colores — qué significa cada uno

| Color | Severidad | Qué significa |
|---|---|---|
| 🔴 Rojo | Crítico | Requiere atención inmediata (ej. stock agotado, sede sin traspaso registrado) |
| 🟠 Naranja | Advertencia | Algo se está acercando a un límite (ej. stock mínimo, producto próximo a vencer) |
| ⚪ Gris | Informativo | Comunicados de la empresa, avisos generales |

## 3. Marcar notificaciones como leídas

- **Una por una**: toca el ✓ que aparece junto a cada notificación no leída.
- **Todas a la vez**: toca "Marcar todas leídas" en la parte superior del panel.

El número rojo de la campana se actualiza automáticamente.

## 4. ¿Por qué a mí no me llegan las mismas notificaciones que a otro compañero?

Las notificaciones **no le llegan a todo el mundo por igual** — dependen del
tipo:

- **Notificaciones de stock** (agotado, mínimo, próximo a vencer): solo le
  llegan a los usuarios de la sede/bodega dueña de ese producto. Si trabajas
  en otra sede, no las verás — es intencional, no un error.
- **Comunicados corporativos**: pueden ser para todos, o dirigidos solo a
  ciertos niveles (por ejemplo, un resumen que solo ven los Mandos Medios).
- **Alertas de sede** (ej. "traspaso no registrado"): le llegan a la sede
  que no cumplió, y en algunos casos también un resumen consolidado a la
  supervisión de zona.

## 5. Tipos de notificación que existen hoy

| Notificación | Cuándo aparece |
|---|---|
| **Stock Agotado** | Un producto llegó a 0 unidades en tu sede/bodega |
| **Stock Mínimo Alcanzado** | Un producto bajó del umbral mínimo configurado |
| **Próximo a Vencer** | Un lote entra en la ventana de alerta antes de su vencimiento |
| **Comunicado Corporativo** | Mensaje informativo de la empresa (manual, no automático) |
| **Traspaso No Registrado** | Tu sede no envió la solicitud de insumos antes de la hora límite del día |

## 6. Preguntas frecuentes

**No veo ninguna notificación aunque sé que hay una alerta de stock.**
Verifica que estés viendo la sede correcta — las alertas de stock son
exclusivas de la sede dueña del producto.

**Marqué una notificación como leída por error.**
No hay forma de "desmarcarla" desde la campana; si necesitas volver a
verla, contacta a tu supervisor o a soporte técnico.

**Recibí una alerta de "traspaso no registrado" pero sí lo envié.**
Puede deberse a que el traspaso quedó guardado como **borrador** sin
enviarse formalmente — revisa en tu aplicación de traspasos si hay un
borrador pendiente y complétalo con el botón "Enviar".

**¿Puedo desactivar un tipo de notificación que no me interesa?**
No desde la bandeja de notificaciones — eso lo configura un administrador
desde el Panel Admin (ver sección 7).

---

## 7. Para administradores: crear una regla de alerta programada

> Esta sección es solo para usuarios con permiso de administración de
> notificaciones (rol con acceso a "Administración de Reglas Programadas"
> en el Panel Admin).

### 7.1 ¿Qué es una regla programada?

Una alerta que se dispara automáticamente a una hora específica del día si
cierta condición no se cumple — por ejemplo, "avisar a las 9:00 AM si una
sede no ha registrado su traspaso diario".

### 7.2 Cómo crear una

1. Entra al Panel Admin → **Administración de Reglas Programadas**.
2. Completa el formulario:
   - **Nombre**: una etiqueta clara, ej. "Traspaso diario no registrado".
   - **Tipo de notificación**: elige de la lista (ej. "Traspaso No Registrado").
   - **Patrón de condición**: elige el patrón ya construido que aplica
     (ej. "Ausencia de registro a una hora límite"). No necesitas saber
     nada técnico — solo elegir el que mejor describe lo que quieres vigilar.
   - **Hora de evaluación**: a qué hora del día se revisa (ej. 09:00:00).
   - **Alcance**: elige uno:
     - *Todas las sedes*: cada sede que incumpla recibe su propia alerta.
     - *Una sede específica*: solo esa sede.
     - *Nivel jerárquico*: en vez de alertar a cada sede, se envía **un solo
       resumen consolidado** (ej. a todos los Mandos Medios) con la lista de
       sedes que incumplieron ese día.
3. Guarda. La regla queda activa de inmediato — se evalúa automáticamente
   cada día a la hora elegida, sin que nadie tenga que ejecutarla a mano.

### 7.3 Ver el historial y desactivar una regla

En la tabla de reglas puedes ver cuántas notificaciones generó cada una en
los últimos 30 días — útil para detectar una regla mal configurada (por
ejemplo, si dispara todos los días sin falta, puede que la hora o el
alcance no estén bien ajustados). Para eliminarla, toca el ícono 🗑 junto
a la regla; queda desactivada de inmediato.

### 7.4 Panel de monitoreo centralizado

En **Monitoreo Centralizado de Notificaciones** puedes ver, para toda la
empresa:
- Totales por severidad (crítico/advertencia/informativo).
- El detalle de cada notificación, incluyendo **quién la vio y quién no**
  (columna "Destinatarios").
- Filtros por severidad, fecha y sede.

Esto te permite detectar rápidamente, por ejemplo, si una alerta crítica
lleva varios días sin que nadie la reconozca.
