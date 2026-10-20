# Integración y verificación de nodos

Consultar las secciones aplicables al cambio. Las rutas siguientes son relativas a la raíz de P8P. Releer las fuentes si han cambiado.

Esta referencia sirve para inspeccionar compatibilidad, no para autorizar cambios compartidos. Por defecto solo se escribe `src/Nodes/Custom/NombreNode.php`; cualquier cambio fuera de ese archivo requiere una solicitud expresa de ampliar el alcance.

## UI y vías de ejecución

| Punto | Fuente y comportamiento observado | Qué comprobar |
| --- | --- | --- |
| Descubrimiento | `src/Core/NodeRegistry.php` busca clases `NodeInterface` recursivamente en `src/Nodes/` y las instancia sin argumentos; `Custom` se muestra como `Community`. | Clase descubrible, `type` único, esquema sin I/O. |
| Catálogo | `public/nodes.php` entrega esquemas; `frontend/src/components/AddNodeModal.jsx` los lista y usa iconos Lucide. | Aparición con nombre, icono y categoría. |
| Configuración | `NodeInspector.jsx` usa `parameters` como objeto por clave; soporta credencial, `select`, `textarea`, número y texto; otros tipos caen en texto. | Adaptar el esquema a esos controles sin editar el inspector. `required` es visual. |
| Credenciales | `CredentialsModal.jsx` administra credenciales; `NodeInspector.jsx` lista metadatos sin filtrar por tipo. | Usar tipos existentes, por ejemplo `generic_bearer` con `data.api_key`; validar compatibilidad en el nodo sin modificar modal ni selector. |
| Lienzo | `Canvas.jsx` y `CustomNode.jsx` usan `customNode`; `App.jsx` mantiene el tipo lógico en `data.type` e inicio en `data.isStart`. | Conservar identidad y parámetros; aceptar el icono genérico sin editar el mapa de iconos ni agregar handles. |
| Persistencia | `public/save_workflow.php`, `get_workflows.php` guardan/leen `nodes` y `edges` como JSON. | Compatibilidad de workflows guardados; no renombrar identificadores sin estrategia. |
| Ejecución directa | `App.jsx` transforma tipo lógico en `node.type` y `data.is_start`; `public/api.php` descubre nodos y ejecuta el motor. | El tipo visual `customNode` no llega como tipo lógico al motor. |
| Cola | `public/async_run.php` encola; `public/cron.php` normaliza `data.type`/`type` e `isStart`/`is_start`, usando `QueueManager`. | Parámetros y payload llegan al nodo correcto; worker requiere BD y puede producir efectos reales. |
| Webhook | `public/webhook.php` comprueba `is_active`, registra clases manualmente e inyecta `webhook` en contexto. | Informar que no incorpora automáticamente el nodo. No editar su registro sin ampliación expresa del alcance. |

El motor conserva el resultado en `$context[$nodeId]`. `{{node_1.data}}` puede acceder a la salida de `HttpRequestNode`; `SetVariableNode` entrega `variable`/`value`, que `ExpressionEvaluator` sabe resolver. No todas las salidas tienen `data`: definir las del nuevo nodo.

Handles normales: `main_input`/`main_output`. `IfConditionNode` devuelve `branch` (`true`/`false`) para elegir el `sourceHandle`. AI usa subpuertos como `model`, `memory`, `tools`, resueltos en `_sub_connections`. No añadir ramas o subpuertos a conectores lineales sin necesidad.

Ejemplo del esquema real de Telegram, sin secretos:

```php
'credential_id' => [
    'type' => 'credential',
    'required' => true,
    'label' => 'Credencial de Telegram'
],
'message' => [
    'type' => 'textarea',
    'required' => true,
    'label' => 'Mensaje (ej. {{message}})'
]
```

## Datos, credenciales y permisos

- `CredentialManager` almacena datos cifrados con AES-256-GCM, entrega metadatos con `getCredentials()` y descifra con `getCredentialDecrypted($id)`. La UI necesita el ID; extraer solo las claves requeridas. No copiar el fallback de claves de Telegram como regla general.
- El constructor del gestor carga `.env` con `EnvLoader::load(..., false)`, respeta claves ya definidas en `$_ENV` o el proceso y exige `APP_SECRET` no vacía. Guardado y ejecución deben usar esa misma configuración estable. Los nodos solo invocan al gestor; no cargan entorno ni cambian variables para lograr descifrar una credencial.
- El gestor permite leer el cifrado histórico generado cuando faltaba `APP_SECRET`; las nuevas escrituras siempre usan la clave configurada. Esta compatibilidad es genérica y exclusiva de `CredentialManager`: no reproducir claves alternativas ni algoritmos en nodos. La lectura no migra ni reescribe registros; no regenerar claves ni modificar credenciales existentes como parte de una prueba.
- El motor registra `evaluated_parameters` y resultados de subnodos en `_sub_connections`; evitar poner secretos allí. Revisar excepciones de clientes HTTP que puedan incluir URLs con tokens.
- `Database::getConnection()` devuelve PDO MySQL con excepciones y consultas preparadas nativas. No hay ORM ni capa de repositorios obligatoria. `QueueManager` y `CredentialManager` centralizan sus operaciones; no duplicarlas en el nodo.
- `database/schema.sql` define `workflows`, `queue_jobs`, `credentials`. `public/install.php` repite DDL con tipos/longitudes distintos. No añadir persistencia ni cambiar DDL al crear un nodo bajo el alcance de un archivo; si es esencial, informar la dependencia. No hay runner de migraciones configurado.
- `config/database.php` contiene valores literales; cambiar variables DB de `.env` no reconfigura automáticamente la conexión. No copiar esos valores a documentación o fixtures.
- Endpoints administrativos invocan `AuthMiddleware::check()`, que actualmente acepta cualquier Bearer no vacío. No demuestra firma, vigencia ni autorización efectiva; no inventar roles, aislamiento por usuario o permisos Premium. Si la aceptación depende de una garantía ausente, señalar la dependencia y resolver su alcance con el usuario.
- Las entradas públicas de chat/webhook tienen otro contrato; no justifican abrir endpoints administrativos. Crear nodos no incluye añadir llamadas React, endpoints ni registros en `public/`; usar el esquema y motor existentes.
- Si falla la recuperación de credenciales, distinguir conexión/consulta a BD, ID ausente, fallo de descifrado y contenido incompatible antes de revisar autenticación/permisos del proveedor. Un error anterior a la petición no es un rechazo de la API. Informar la categoría sin imprimir tokens, claves, cuerpos sensibles ni excepciones originales con secretos.
- Si el diagnóstico demuestra un fallo compartido y el usuario amplía expresamente el alcance, corregirlo en la utilidad central, sin ramas para Slack u otro proveedor. Preservar IDs, tipos y registros y comprobar compatibilidad entre varios nodos. No modificar frontend ni `public/` si el arreglo central basta; la ampliación aplica a esa tarea, no a las siguientes.

## Errores y contradicciones

| Evidencia | Decisión para un nuevo nodo |
| --- | --- |
| La interfaz define `validate()`, pero el motor invoca directamente `execute()`; Telegram solo valida credencial en `validate()` y otros campos después. | Garantizar validación desde `execute()` y alinear esquema y ejecución. |
| Telegram/HTTP devuelven algunos fallos con `success: false`; `SetVariableNode` lanza excepción por parámetros inválidos. | Elegir continuar o detener según requisito funcional. |
| El motor captura excepciones del nodo principal, registra `history[*].status: failed`, interrumpe y aun devuelve `status: completed`. | Revisar historial y error funcional, no solo estado superior. |
| Los subnodos se ejecutan antes del `try` del nodo principal. | Comprobar propagación de errores aparte si se introducen subpuertos. |
| Telegram desactiva TLS, no comprueba explícitamente `curl_exec === false` y decodifica JSON sin comprobar error. | Mantener TLS verificado; tratar transporte, estado HTTP y respuesta inválida por separado. |
| README menciona SQLite, pero PDO/SQL son MySQL/MariaDB. | No prometer soporte SQLite sin implementación y verificación. |

Estos hallazgos no autorizan arreglos globales durante una tarea limitada a otro nodo. Informar dependencias que impidan cumplir aceptación y proponer el cambio mínimo necesario.

## Verificación

Comandos respaldados por manifiestos/entrypoints y comprobaciones directas. Ejecutar desde el directorio indicado; sustituir archivos de ejemplo por los realmente cambiados.

| Directorio | Comando | Alcance |
| --- | --- | --- |
| Raíz | `php composer.phar install` | Si faltan dependencias, usando `composer.lock`. |
| `frontend/` | `npm ci` | Si falta/restaurar instalación con lockfile. |
| Raíz | `php -l src/Nodes/Custom/TelegramNode.php` | Ejemplo de sintaxis de un archivo real; para el nodo nuevo usar su ruta y repetir para otros PHP modificados. No prueba ejecución ni autoload. |
| `frontend/` | `npm run lint` | Si cambia JS/JSX o configuración. |
| `frontend/` | `npm run build` | Si cambia frontend o integración del esquema en UI. No sustituye revisar el editor. |
| `frontend/` | `npm run dev` / `npm run preview` | Desarrollo / revisión de compilación, con backend separado; no hay script PHP de desarrollo documentado. |
| Raíz | `php test_engine.php` | Prueba manual HTTP con JSONPlaceholder, sin aserciones; no cubre otro nodo ni certifica éxito por exit code. |
| Raíz | `git diff --check` | Espacios/conflictos, no prueba funcional. Revisar también archivos nuevos que no aparecen en diff normal. |

No hay `npm test`, `composer test`, PHPUnit, comando de formato ni suite CI configurados. Para el alcance de un archivo, crear pruebas focalizadas en `/private/tmp` con datos ficticios y transporte simulado, sin añadir archivos al repositorio. Registrar los comandos temporales reales. No inventar comandos de runners ausentes. No es necesario ejecutar lint/build del frontend cuando solo cambia PHP; si se ejecutan y fallan por problemas previos, informarlos sin corregir frontend.

Verificar descubrimiento/esquema sin ejecutar efectos externos; después probar `execute()` con casos representativos. Para credenciales, distinguir ausencia, tipo incompatible y datos inválidos. En UI revisar edición, guardado, recarga y ejecución. Si el fallo debe detener el grafo, comprobar que no se ejecuta el siguiente nodo y revisar `history`.

Para un cambio compartido autorizado en credenciales/entorno, probar con datos ficticios: guardado y ejecución con distinto contexto de carga, prioridad de variables del servidor, lectura de cifrado antiguo y actual, rechazo de clave ausente/incorrecta y datos alterados, varios tipos de credencial y metadatos sin secretos. Confirmar que leer no cambia los registros y que el guardado nuevo no usa la clave histórica. Mantener estas pruebas fuera del repositorio si no se autorizaron archivos adicionales. Las consultas a una BD real para diagnóstico deben ser de solo lectura y mostrar únicamente resultados sanitizados; las pruebas automatizadas usan almacenamiento desechable.

`php public/cron.php` procesa hasta cinco trabajos reales, cambia BD y puede llamar servicios; `/install.php` crea tablas y escribe configuración. No usarlos para verificar documentación o sintaxis. Usar entorno apropiado cuando la tarea requiera probar esas vías.

Entregar resultados separados: **aprobadas** (comando y evidencia), **fallidas** (error y si era preexistente), **no ejecutadas** (motivo y requisitos pendientes). Sin BD/red/servicio de prueba, declarar la limitación en lugar de afirmar integración verificada.
