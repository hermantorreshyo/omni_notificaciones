# Integración pendiente — Fase 2

`NotificationController.php` está completo y validado funcionalmente
(creación, listado, marcado de lectura, aislamiento por interlocutor/rol,
resolución recursiva de entidad legal, y RBAC negativo — ver resultados de
la prueba en el commit de esta fase).

## Lo que falta para que quede 100% conectado (no es código de este módulo)

El manual de desarrollador no documenta el bootstrap/router interno de
OMNI API CORE (cómo se instancian `AuthController`, `TransferController`,
etc., ni cómo se decodifica el JWT antes de llegar al controller). Por eso
`NotificationController` declara su contrato de entrada explícitamente
(ver el bloque de comentario al inicio del archivo) en vez de asumir una
clase base o un formato de request concretos.

Quien mantiene el router debe:

1. Registrar las 4 rutas nuevas apuntando a los métodos del controller
   (ver tabla en el comentario de cabecera del archivo).
2. Armar el array `$authContext` a partir de lo que el proxy ya inyecta
   hoy (`X-Interlocutor-Id`, JWT decodificado) y pasarlo al constructor.
3. Confirmar el nombre real de la clase/método de conexión a BD — este
   controller asume `Database::getConnection()` (nombre mencionado en
   sesiones previas de trabajo sobre OMNI, no confirmado en el manual).

Ninguno de estos tres puntos requiere cambios en la lógica de negocio del
controller — son puntos de cableado (wiring), no de diseño.
