---
name: p8p-node-creator
description: "Implementar o ampliar nodos y conectores PHP de P8P con NodeInterface, esquema para React y credenciales, siguiendo TelegramNode sin copiar sus reglas de negocio. Usar para nodos de workflow de este repositorio, no para módulos CRUD genéricos ni cambios exclusivamente visuales."
---

# Crear nodos P8P

Implementar nodos completos del motor P8P a partir de `src/Nodes/Custom/TelegramNode.php`. Seguir `AGENTS.md` para arquitectura, estilo y trabajo compartido. Las rutas de código indicadas aquí parten de la raíz del repositorio, no de la carpeta del skill. Releer el código afectado: esta guía describe patrones observados, no sustituye sus contratos actuales.

## Alcance por defecto: un solo archivo

Crear o modificar únicamente `src/Nodes/Custom/NombreNode.php`. Reutilizar el descubrimiento, formulario y credenciales existentes, como hace actualmente `SlackNode.php` con `generic_bearer` y `data.api_key`. No modificar `frontend/`, `public/`, core, configuración, SQL, manifiestos, documentación o skills para integrar un nodo. No añadir iconos al lienzo, filtros del inspector ni tipos nuevos al gestor de credenciales; usar el icono genérico y validar la credencial en PHP.

Realizar pruebas simuladas en `/private/tmp` y explicar configuración/limitaciones en la respuesta, sin añadir pruebas o documentación al repositorio mientras el alcance siga siendo un solo archivo. Una solicitud general de integración o verificación no amplía ese alcance. Si un requisito esencial exige cambiar otra ruta, señalar qué archivo y por qué, y esperar una solicitud expresa de ampliar el alcance antes de editarlo. Continuar el trabajo independiente dentro del nodo.

## Entradas variables

Extraer de la solicitud un contrato breve; no heredar valores de Telegram por defecto.

| Entrada | Definir antes de implementar |
| --- | --- |
| Nombre | Clase `...Node`, `type` único snake_case, etiqueta, categoría y carpeta. |
| Campos | Nombre, tipo, obligatoriedad, valor inicial, límites, opciones y salida esperada; separar datos públicos de secretos. |
| Relaciones | `credential_id` y tipo/contenido esperado si aplica; dependencias de nodos, rutas de contexto, handles y persistencia adicional necesaria. Puede ser ninguna. |
| Operaciones | Acciones, servicio/protocolo, parámetros, efectos, timeout y comportamiento ante reintentos si hay efectos externos. |
| Permisos | Quién puede configurar/ejecutar, endpoint administrativo o entrada pública y comprobaciones necesarias. No asumir roles ni licencias por categoría. |
| Aceptación | Casos verificables de éxito, entradas inválidas, fallo externo y vías requeridas (editor, API, cola, webhook). Definir si un fallo debe detener el grafo. |

Resolver detalles rutinarios con evidencia del código. Preguntar solo si falta una decisión funcional esencial y continuar la inspección independiente. Si se pide un módulo que no es un nodo de workflow, aclarar su arquitectura antes de aplicar este patrón.

## Procedimiento

1. **Delimitar el cambio.** Leer los `AGENTS.md` aplicables y revisar `git status --short`. Inspeccionar Telegram, `src/Interfaces/NodeInterface.php`, `src/Core/NodeRegistry.php`, `src/Core/Engine.php` y `ExpressionEvaluator.php`. Comparar `HttpRequestNode.php` para transporte y `SetVariableNode.php` para validación dentro de `execute()`. Identificar archivos necesarios y aceptación. No corregir problemas ajenos como parte implícita de crear un nodo.

2. **Diseñar esquema y salida.** Usar `src/Nodes/Custom/NombreNode.php` y namespace `P8P\Nodes\Custom` para conectores comunitarios, salvo otra categoría justificada por la solicitud. Mantener PSR-4, constructor sin argumentos obligatorios y sin I/O. Declarar `type`, `name`, `category`, `description`, `icon`, `is_start`, `help_text` y `parameters` según necesidad. Buscar colisiones de `type` con `rg`. El esquema alimenta el editor; no debe llamar servicios ni descifrar credenciales.

3. **Implementar el contrato.** Implementar `getSchema(): array`, `validate(array $parameters): bool`, `execute(array $parameters, array $context): array`. Validar desde `execute()` antes de cualquier efecto: el motor no invoca `validate()`. Comprobar tipos reales tras interpolar, campos vacíos, límites, JSON y opciones; no usar `empty()` cuando `0` o `false` sean válidos. Definir claves de salida para expresiones posteriores. Reutilizar `ExpressionEvaluator`; no asumir que modificar `$context` local cambia el estado del motor.

4. **Reutilizar servicios y datos.** Para secretos, usar `CredentialManager::getCredentialDecrypted()` con un tipo disponible en el gestor y comprobar existencia/contenido antes de llamar al servicio. El gestor ya carga el entorno y resuelve la clave; no cargar `.env`, modificar `APP_SECRET` ni duplicar cifrado o compatibilidad antigua en el nodo. Consultar [datos y permisos](references/integration.md#datos-credenciales-y-permisos). Reutilizar PDO si hace falta leer datos existentes; no añadir tablas, tipos de credencial ni cambios en endpoints/configuración. Usar Guzzle instalado o cURL, con timeout, TLS verificado y manejo de transporte, HTTP y JSON. No emitir secretos en resultados o excepciones. Diagnosticar por separado almacenamiento, descifrado y API remota. Las correcciones compartidas requieren alcance explícito y deben servir a todos los nodos, no introducir excepciones por proveedor.

5. **Definir el fallo explícitamente.** Si debe detenerse el grafo, lanzar una excepción con mensaje seguro; devolver `success: false` solo si continuar forma parte del contrato. No confundir estados HTTP del servicio con estados del motor. Telegram devuelve errores como datos; no es un contrato universal. Revisar [errores y contradicciones](references/integration.md#errores-y-contradicciones), especialmente si se añaden subpuertos.

6. **Comprobar integración sin editar otras capas.** Usar el esquema para el selector/inspector existentes y verificar compatibilidad del payload con el motor. Leer [UI y ejecución](references/integration.md#ui-y-vias-de-ejecucion) para distinguir descubrimiento automático de registro manual. No cambiar `AddNodeModal`, `NodeInspector`, `CustomNode`, `CredentialsModal` ni `public/`. El webhook queda sin registro del nodo salvo ampliación expresa del alcance. Una vía no habilitada debe figurar como limitación, no como integración completada.

7. **Verificar y entregar.** Ejecutar sintaxis PHP, descubrimiento y pruebas focalizadas con respuestas simuladas desde `/private/tmp`, según [verificación](references/integration.md#verificacion). Cubrir éxito, entradas inválidas, credenciales, errores remotos y expresiones. No enviar mensajes ni producir efectos reales sin autorización. Revisar que el único archivo añadido/modificado por la implementación sea el nodo; conservar cambios previos ajenos. Informar verificaciones aprobadas, fallidas y no ejecutadas, configuración con tipos de credencial existentes y vías fuera de alcance.

## Qué tomar de Telegram

Tomar la separación entre esquema, validación, ejecución y recuperación centralizada de credenciales. Ejemplos reales: `credential_id` con `type: credential` produce un selector; `message` con `type: textarea` produce un área de texto en el inspector.

No trasladar a otros conectores `chat_id`, `sendMessage`, fallback entre `bot_token`/`api_key`/`token`/`value`, limpieza de espacios, recorte a 4000/3980 caracteres, timeout de diez segundos ni ausencia de `parse_mode`. Evaluarlos contra el contrato del nuevo servicio. No copiar TLS deshabilitado ni validaciones incompletas.

## Lista de finalización

- [ ] Nombre, campos, relaciones, operaciones, permisos y aceptación definidos; casos no aplicables explícitos.
- [ ] Clase/namespace y `type` cumplen PSR-4 y descubrimiento; esquema estable sin efectos externos.
- [ ] `execute()` garantiza validación y cumple el contrato de salida/fallo acordado.
- [ ] Credenciales, entorno, cifrado y transporte reutilizan la infraestructura sin revelar secretos ni duplicar lógica central. Los fallos previos a la petición no se atribuyen al servicio remoto.
- [ ] Selector y formulario usan el esquema y componentes existentes; no se modificó frontend ni `public/`. Vías de ejecución y exclusiones explicadas.
- [ ] Verificaciones pertinentes ejecutadas y resultados separados en aprobados/fallidos/no ejecutados; exit code por sí solo no demuestra éxito funcional.
- [ ] Único archivo de implementación: `src/Nodes/Custom/NombreNode.php`, salvo ampliación expresa del usuario; pruebas temporales fuera del repositorio y cambios ajenos conservados.
