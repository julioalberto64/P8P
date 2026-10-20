# Guía para agentes de P8P

## Proyecto y capas
P8P es un motor de automatización visual autohospedado para hosting compartido: PHP >= 8.1 con Composer, React 19 con Vite, React Flow y Tailwind CSS 4. El backend actual usa PDO con MySQL/MariaDB; Node.js se usa para desarrollar y compilar el frontend, no para ejecutar los workflows en producción.

- `src/Interfaces/`: contrato `NodeInterface` de los conectores.
- `src/Core/`: ejecución del grafo, descubrimiento de nodos, expresiones, conexión PDO, cola, autenticación y cifrado de credenciales.
- `src/Nodes/{Core,Custom,Premium}/`: nodos ejecutables; `Custom` se presenta como categoría `Community`. Las categorías no implementan permisos ni licencias.
- `public/`: endpoints PHP de workflows, credenciales, ejecución y logs; webhook, worker `cron.php`, instalador y widget de chat. No es la salida compilada de React; `index.php` es un marcador.
- `frontend/src/`: `App.jsx` coordina estado y serialización; `components/` contiene lienzo, nodo visual, inspector y modales; `api/client.js` ofrece `apiFetch` con Bearer y tratamiento de 401.
- `config/database.php`: configuración efectiva de conexión; `database/schema.sql`: definición SQL. El instalador también contiene DDL y genera configuración.
- `test_engine.php`: prueba manual del motor con una petición HTTP externa. `vendor/`, `frontend/node_modules/` y `frontend/dist/` son dependencias o salidas; no editarlas manualmente.

## Convenciones y referencia
- PHP: namespace `P8P\` → `src/` mediante PSR-4; archivo y clase en PascalCase, nodos con sufijo `Node`, métodos y variables en camelCase. Tipar parámetros y retornos como en las interfaces; usar cuatro espacios y llaves de clase/método en línea propia.
- JS: módulos ES, componentes funcionales y hooks; componentes/archivos JSX en PascalCase, funciones y propiedades en camelCase. El código mezcla indentación y punto y coma: conservar el estilo del archivo sin reformatear código ajeno. Seguir `frontend/eslint.config.js`.
- Tipos de nodo y parámetros del contrato usan snake_case (`telegram_bot`, `credential_id`); conservar los identificadores existentes porque se guardan en workflows. Textos de UI y errores son mayoritariamente españoles.
- Referencia solicitada: `src/Nodes/Custom/TelegramNode.php`. Reutilizar su organización `getSchema()` / `validate()` / `execute()`, esquema declarativo y acceso a `CredentialManager`. Comparar también con `NodeInterface`, `NodeRegistry`, `Engine`, `HttpRequestNode` y `SetVariableNode` antes de generalizar.
- Son exclusivos de Telegram sus campos `chat_id`/`message`, API `sendMessage`, claves alternativas de token, búsqueda contextual, limpieza de espacios y recorte de mensajes. No imponerlos a otros módulos ni convertir sus inconsistencias en reglas.

## Implementación de módulos
- Para un nuevo nodo, crear o modificar únicamente `src/Nodes/Custom/NombreNode.php`, salvo que el usuario solicite expresamente otro alcance. No modificar `frontend/`, `public/`, el core, configuración, SQL, dependencias, documentación ni skills como parte de implementar ese nodo. Una petición general de integración no autoriza ampliar esa lista.
- Usar el descubrimiento automático y el formulario generado por `getSchema()`. Reutilizar los controles y tipos de credencial ya disponibles, como `generic_bearer` con `data.api_key`; validar en el nodo el contenido requerido por el servicio. No agregar tipos de credencial, filtros del selector ni mapas de iconos al frontend. Aceptar el icono genérico del lienzo.
- Si una vía requiere cambios externos al archivo del nodo (por ejemplo, el registro manual de `public/webhook.php`), informar que queda fuera del alcance. Si impide un criterio esencial, explicar la dependencia y pedir una ampliación concreta antes de editar esas rutas. No corregir inconsistencias globales de entorno, cifrado o autenticación durante la creación del nodo.
- Cuando el alcance sea un solo archivo, ejecutar pruebas simuladas desde `/private/tmp` y dar instrucciones de uso en la respuesta; no añadir archivos de pruebas o documentación al repositorio sin solicitud expresa de ampliar ese alcance.
- Implementar `NodeInterface`: `getSchema(): array`, `validate(array $parameters): bool` y `execute(array $parameters, array $context): array`. Mantener constructor sin argumentos obligatorios y sin efectos externos: `NodeRegistry` instancia los nodos al descubrirlos, incluso para listar esquemas.
- Declarar un `type` único y estable, nombre, descripción, icono Lucide disponible, categoría, `is_start` y parámetros por clave con tipo, etiqueta, obligatoriedad y valores/opciones aplicables. Adaptar el esquema a los controles existentes de `AddNodeModal` y `NodeInspector`.
- Validar en backend tipos, campos requeridos y datos externos antes de ejecutar efectos. `Engine` actualmente **no llama a `validate()`**: el nodo debe garantizar la validación desde `execute()`. Los indicadores `required` del inspector no sustituyen esa validación.
- Reutilizar `ExpressionEvaluator` para `{{variable}}` y `{{node_id.campo}}`; el motor almacena resultados bajo el ID del nodo. Evitar un nuevo interpolador o modificar el contexto como si se compartiera por referencia.
- Revisar la integración completa: UI usa `type: customNode` y tipo real en `data.type`; el payload de ejecución usa el tipo real y `data.is_start`. Mantener serialización, carga y normalización de cron compatibles. Conservar handles `main_input`/`main_output`; ramas y subpuertos requieren coordinación con `CustomNode.jsx` y `Engine`.
- El descubrimiento automático cubre `api.php`, `nodes.php` y cron; `webhook.php` registra clases manualmente. Distinguir las vías disponibles por descubrimiento de las que quedan pendientes; no editar el webhook para un nuevo nodo sin solicitud expresa.
- Reutilizar `Database::getConnection()` y consultas preparadas con valores enlazados; mantener persistencia en PHP. Si cambia el esquema, revisar tanto `database/schema.sql` como el DDL del instalador y cómo actualizar instalaciones existentes.
- Reutilizar `CredentialManager` y referencias `credential_id`: almacenar secretos cifrados, entregar solo metadatos al selector y evitar secretos en esquemas, respuestas e historiales. Reutilizar `apiFetch` al incorporar llamadas administrativas; no duplicar gestión de sesión ni copiar URLs locales como requisito de despliegue.
- `CredentialManager` centraliza la carga de `.env`, exige `APP_SECRET` no vacía y respeta las variables existentes mediante `EnvLoader::load(..., false)`. Los nodos no deben cargar entorno, cambiar claves ni implementar cifrado/descifrado propio. La compatibilidad de lectura con credenciales antiguas pertenece exclusivamente al gestor; no copiarla a conectores ni usarla para nuevos guardados.
- Distinguir fallos de almacenamiento/descifrado de errores del servicio remoto antes de proponer cambios en tokens o permisos. Si se autoriza una corrección de infraestructura compartida, implementarla en la utilidad central de forma genérica, probar varios tipos de credencial y conservar los datos existentes. Esa autorización no amplía el alcance de futuros nodos.
- Los endpoints administrativos deben pasar por `AuthMiddleware`; distinguirlos de las entradas públicas de webhook/chat y definir la autorización necesaria para cada nueva operación. No hay un sistema de roles demostrado.
- Responder JSON válido y códigos HTTP pertinentes en endpoints; errores con `success: false` y `error` donde corresponda al contrato. En nodos, definir cuándo devolver un resultado de fallo y cuándo lanzar una excepción: el motor solo marca `failed` y detiene la secuencia ante una excepción del nodo principal. Devolver `success: false` por sí solo no detiene el grafo.
- Usar timeouts en llamadas externas, mantener verificación TLS y no exponer credenciales en mensajes de error. No copiar `CURLOPT_SSL_VERIFYPEER=false` de Telegram. No versionar `.env` ni añadir secretos a configuración o ejemplos.

## Comandos y entorno
Los scripts npm siguientes están declarados en `frontend/package.json`; Composer no define scripts. Las invocaciones de instalación y de archivos PHP usan los manifiestos, lockfiles y entrypoints existentes; no hay un lanzador de desarrollo PHP documentado.

| Directorio | Comando / procedimiento | Uso |
| --- | --- | --- |
| Raíz | `php composer.phar install` | Instalar dependencias según `composer.lock`; requiere PHP >= 8.1. |
| `frontend/` | `npm ci` | Instalar dependencias según `package-lock.json`. |
| `frontend/` | `npm run dev` | Servidor Vite de desarrollo. La UI actual llama a `http://localhost:8000`; requiere backend PHP servido por separado con raíz pública `public/`. |
| `frontend/` | `npm run build` | Compilar a `frontend/dist/`. No hay proceso de despliegue integrado documentado. |
| `frontend/` | `npm run preview` | Previsualizar la compilación. |
| `frontend/` | `npm run lint` | ESLint, incluidas reglas de hooks y React Refresh; no equivale a formatear. |
| Raíz | `php test_engine.php` | Prueba manual con JSONPlaceholder; necesita red, no contiene aserciones. Inspeccionar `history` y la respuesta, no solo el código de salida. |
| Raíz | `php public/cron.php` | Ejecutar worker real, hasta cinco trabajos; modifica la cola y puede invocar servicios. No usar como prueba inocua. El instalador indica programarlo cada minuto con ruta absoluta. |
| Servidor PHP | Abrir `/install.php` | Comprobar requisitos e instalar; crea BD/tablas y escribe `.env` y `config/database.php`. No ejecutar sobre una instalación existente para verificar cambios. |

El instalador comprueba `pdo_mysql`, `curl`, `openssl` y `mbstring`. No se encontraron comandos de formato, suite PHPUnit, runner de tests frontend ni CI; no inventar `npm test`, `composer test` o `format`.

## Discrepancias conocidas
- README anuncia SQLite, pero `Database` y SQL usan MySQL/MariaDB. `roadmap.md` describe objetivos, no capacidades garantizadas; el README enlaza `ROADMAP.md` con capitalización distinta.
- `.env.example` declara variables DB, pero `config/database.php` contiene valores literales; editar solo `.env` no reconfigura esa conexión. La carga del entorno varía por endpoint. Comprobar qué configuración se consume realmente.
- El SQL del instalador difiere de `database/schema.sql` en longitudes y tipos JSON/texto; no asumir que las bases instaladas son idénticas.
- `AuthMiddleware` comprueba presencia de un Bearer, pero no verifica su firma, vigencia ni almacenamiento. Su uso no demuestra autorización efectiva; no replicar esa omisión como patrón.
- El motor puede devolver `status: completed` con nodos `failed` en `history`; revisar historial al verificar ejecuciones. No convertir este comportamiento ni los errores devueltos como datos por algunos conectores en un contrato obligatorio para módulos nuevos.

## Instrucciones y skills
Antes de editar, leer los `AGENTS.md` aplicables desde los ancestros hasta la carpeta objetivo. Para crear nodos, leer completo `.agents/skills/p8p-node-creator/SKILL.md` y sus referencias pertinentes. Los skills del proyecto se encuentran en `.agents/skills/`; localizar otros por nombre/ruta en el catálogo de la sesión y en el repositorio. Mantener aquí las reglas generales y dejar los procedimientos detallados en los skills; no crear ni modificar skills durante una tarea de implementación salvo solicitud expresa.

## Trabajo en paralelo y cierre
- Si se delega, el coordinador asigna archivos distintos a cada subagente y comunica contratos, dependencias y orden de integración antes de editar. Reservar cambios compartidos (interfaces, motor/registro, `App.jsx`, esquema, configuración y manifiestos/lockfiles) al coordinador o a un único responsable explícito.
- Revisar el estado inicial de Git; conservar cambios ajenos, no sobrescribirlos ni revertirlos para resolver conflictos. Comunicar conflictos de propiedad y revisar las diferencias conjuntas antes de integrar.
- Terminar la implementación completa y revisar integración entre esquema, UI, persistencia, ejecución síncrona, cron y webhook según el alcance. Verificar rutas de éxito y fallo, validación y permisos afectados.
- Ejecutar verificaciones pertinentes: lint/build para frontend; sintaxis y ejecución representativa para PHP; integración de datos/servicios cuando corresponda y haya entorno adecuado. Un cambio solo documental requiere revisar contenido, rutas y diff; no exige ejecutar el worker ni llamadas externas.
- Informar por separado verificaciones **aprobadas**, **fallidas** y **no ejecutadas**, con comandos, resultados y motivos o requisitos pendientes. No confundir inspección estática con pruebas de ejecución ni declarar éxito solo porque un proceso terminó con código cero.

Fuentes: `README.md`, `roadmap.md`, manifiestos y lockfiles; configuración Vite/ESLint; `.gitignore` y ejemplos de entorno; interfaz y clases de `src/`; endpoints e instalador de `public/`; componentes/API de `frontend/src/`; `database/schema.sql` y `test_engine.php`.
