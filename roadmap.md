🗺️ Roadmap de Desarrollo: Project P8P
📌 Fase 1: Arquitectura Base Backend (PHP 8.1+)
Objetivo: Definir la estructura del core, base de datos y modelo de ejecución independiente de la UI.

Configuración del Entorno & Estructura de Directorios:

Estructura estilo PSR-4 (src/Core, src/Nodes, src/Engine).

Configuración de composer.json (autoloading y dependencias mínimas como GuzzleHTTP).

Abstracción de la Base de Datos:

Crear controladores de BD compatibles con SQLite y MySQL/MariaDB.

Tablas iniciales: workflows, node_executions, queue_jobs, logs.

Definición de Interfaces de Nodos (NodeInterface):

Crear la interfaz base para que cualquier conector implemente: execute(), getSchema(), validate().

Primeros Nodos Fundamentales (Core Nodes):

Start / Webhook: Punto de entrada de variables.

HTTP Request: Peticiones GET/POST/PUT/DELETE.

Set Variable: Transformación de arreglos y JSON.

If Condition: Bifurcación lógica.

🎨 Fase 2: Interface Visual Frontend (React + React Flow)
Objetivo: Crear el editor gráfico para dibujar y conectar flujos con una UX limpia.

Setup del Frontend (/frontend):

Inicializar proyecto en Vite/React con TailwindCSS y React Flow.

Lienzo Interactivo (Canvas):

Implementar lienzo con zoom, pan, selección múltiple y eliminación de nodos.

Diseño de Nodos Personalizados (Custom Nodes):

Nodos limpios (solo icono, nombre y conectores de entrada/salida).

Visualización del estado del nodo (Éxito, Error, En Ejecución).

Panel Lateral de Configuración (Drawer / Modal):

Abrir panel al hacer doble clic en un nodo para editar sus propiedades.

Probador en vivo del nodo para ver respuestas JSON recibidas.

Persistencia del Workflow:

Conectar con el backend en PHP para guardar/cargar la estructura JSON del mapa gráfico.

⚙️ Fase 3: Motor de Ejecución & Sistema de Colas
Objetivo: Hacer que los flujos se ejecuten en segundo plano sin bloquear el servidor.

Motor de Inferencia de Flujos (Engine.php):

Algoritmo de recorrido de grafos para ejecutar nodos en el orden correcto.

Manejo del contexto de variables globales y locales entre paso y paso.

Sistema de Colas por Base de Datos:

Tabla queue_jobs para encolar tareas pendientes.

Manejador de Cron Job (cron.php):

Script optimizado para ejecutarse cada minuto vía cPanel/Cron.

Lógica de procesamiento en lotes (batching) para no exceder límites de memoria o tiempo de ejecución de PHP.

Historial de Ejecución y Debugging:

Vista de logs en tiempo real para ver qué falló en cada nodo con sus entradas/salidas (Inputs/Outputs).

🔌 Fase 4: Conectores & Protocolos Avanzados
Objetivo: Expandir la funcionalidad para integraciones populares y nuevos estándares.

Sistema de Autenticación de Conectores:

Manejador centralizado de credenciales (API Keys, OAuth2 Token storage).

Conectores Esenciales de la Comunidad:

Slack (Webhooks e Inbound/Outbound).

Gmail / SMTP (Envío y lectura de correos).

Database Connector (Ejecutar queries en MySQL/PostgreSQL externas).

Soporte MCP (Model Context Protocol) & AI Agents:

Cliente JSON-RPC en PHP para conectar con servidores MCP.

Nodos para LLMs (OpenAI, Anthropic, Ollama).

🚀 Fase 5: Empaquetado & Lanzamiento Open-Source
Objetivo: Preparar el proyecto para la comunidad y distribución en hosting compartido.

Instalador Web Ligerísimo:

Script de instalación en 1 clic para configurar la base de datos y credenciales de administración.

Documentación & Guías de Contribución:

Documentar cómo crear un nuevo nodo en 15 minutos.

Publicación del primer Release (v1.0.0-alpha) en GitHub.
