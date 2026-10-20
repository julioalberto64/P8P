import React, { useState, useEffect } from 'react';
import Canvas from './components/Canvas.jsx';
import AddNodeModal from './components/AddNodeModal.jsx';
import NodeInspector from './components/NodeInspector.jsx';
import LoadWorkflowModal from './components/LoadWorkflowModal.jsx';
import CredentialsModal from './components/CredentialsModal.jsx';
import ExecutionLogsModal from './components/ExecutionLogsModal.jsx';
import LoginModal from './components/LoginModal.jsx';
import { Play, Plus, Save, Layers, FolderOpen, Key, Activity, LogOut, User } from 'lucide-react';

export default function App() {
  // Estado de Autenticación Admin
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [user, setUser] = useState(null);

  // Estados del lienzo y modal
  const [selectedNode, setSelectedNode] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isLoadModalOpen, setIsLoadModalOpen] = useState(false);
  const [isCredentialsOpen, setIsCredentialsOpen] = useState(false);
  const [isLogsOpen, setIsLogsOpen] = useState(false);
  const [nodes, setNodes] = useState([]);
  const [edges, setEdges] = useState([]);
  const [executionResult, setExecutionResult] = useState(null);
  const [isRunning, setIsRunning] = useState(false);
  const [workflowId, setWorkflowId] = useState(null);
  const [isSaving, setIsSaving] = useState(false);

  // Estado para almacenar los esquemas cargados desde backend PHP (/nodes.php)
  const [nodeSchemas, setNodeSchemas] = useState([]);

  // Validar si existe sesión guardada al cargar la aplicación
  useEffect(() => {
    const token = localStorage.getItem('p8p_token');
    const storedUser = localStorage.getItem('p8p_user');

    if (token && storedUser) {
      setIsAuthenticated(true);
      setUser(storedUser);
    } else {
      setIsAuthenticated(false);
    }
  }, []);

  // Función helper para realizar peticiones HTTP autenticadas con Token Bearer
  const authenticatedFetch = async (url, options = {}) => {
    const token = localStorage.getItem('p8p_token');
    const headers = {
      'Content-Type': 'application/json',
      ...(options.headers || {}),
      ...(token ? { 'Authorization': `Bearer ${token}` } : {})
    };

    const response = await fetch(url, { ...options, headers });

    // Si el backend devuelve 401 Unauthorized, expira la sesión local
    if (response.status === 401) {
      handleLogout();
      throw new Error('Sesión expirada o no autorizada. Por favor, inicia sesión nuevamente.');
    }

    return response;
  };

  // Cargar esquemas de nodos registrados desde PHP al iniciar la aplicación o autenticarse
  useEffect(() => {
    if (!isAuthenticated) return;

    const fetchSchemas = async () => {
      try {
        const response = await authenticatedFetch('http://localhost:8000/nodes.php');
        const data = await response.json();
        if (data.success && Array.isArray(data.nodes)) {
          setNodeSchemas(data.nodes);
        }
      } catch (err) {
        console.error('Error al obtener esquemas de nodos:', err);
      }
    };

    fetchSchemas();
  }, [isAuthenticated]);

  // Gestores de Login y Logout
  const handleLoginSuccess = (userData) => {
    setIsAuthenticated(true);
    setUser(userData.username);
  };

  const handleLogout = () => {
    localStorage.removeItem('p8p_token');
    localStorage.removeItem('p8p_user');
    setIsAuthenticated(false);
    setUser(null);
  };

  // 1. Agregar un nuevo nodo al lienzo
  const handleAddNode = (nodeType) => {
    const newNode = {
      id: `node_${Date.now()}`,
      type: 'customNode',
      position: {
        x: 250 + nodes.length * 30,
        y: 150 + nodes.length * 30
      },
      data: {
        label: nodeType.label,
        type: nodeType.type,
        isStart: nodeType.isStart,
        parameters: nodeType.type === 'http_request'
            ? { url: 'https://www.p8p.info/v1/api/1', method: 'GET' }
            : nodeType.type === 'set_variable'
                ? { variable_name: 'status', variable_value: 'active' }
                : {}
      },
    };
    setNodes((prev) => [...prev, newNode]);
  };

  // 2. Actualizar parámetros de un nodo desde el NodeInspector
  const handleUpdateNode = (nodeId, newData) => {
    setNodes((prevNodes) =>
        prevNodes.map((node) => (node.id === nodeId ? { ...node, data: newData } : node))
    );
    setSelectedNode((prev) => (prev ? { ...prev, data: newData } : null));
  };

  // 3. Eliminar un nodo y sus conexiones
  const handleDeleteNode = (nodeId) => {
    setNodes((prev) => prev.filter((n) => n.id !== nodeId));
    setEdges((prev) => prev.filter((e) => e.source !== nodeId && e.target !== nodeId));
    setSelectedNode(null);
  };

  // 4. Cargar un workflow guardado desde MariaDB
  const handleLoadWorkflow = async (id) => {
    try {
      const response = await authenticatedFetch(`http://localhost:8000/get_workflows.php?id=${id}`);
      const data = await response.json();

      if (data.success && data.workflow) {
        const { structure } = data.workflow;
        setWorkflowId(data.workflow.id);
        setNodes(structure.nodes || []);
        setEdges(structure.edges || []);
        setIsLoadModalOpen(false);
      } else {
        alert(`Error al obtener workflow: ${data.error || 'Respuesta inválida'}`);
      }
    } catch (err) {
      alert(`Error de conexión al cargar el workflow: ${err.message}`);
    }
  };

  // 5. Ejecutar el Workflow en el motor PHP (api.php)
  const handleTestWorkflow = async () => {
    setIsRunning(true);
    setExecutionResult(null);

    const payload = {
      nodes: nodes.map((n) => ({
        id: n.id,
        type: n.data.type,
        data: {
          is_start: n.data.isStart,
          parameters: n.data.parameters || {}
        }
      })),
      edges: edges.map((e) => ({
        source: e.source,
        target: e.target,
        sourceHandle: e.sourceHandle,
        targetHandle: e.targetHandle
      }))
    };

    try {
      const response = await authenticatedFetch('http://localhost:8000/api.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      const data = await response.json();
      setExecutionResult(data);
    } catch (err) {
      setExecutionResult({ error: 'Error al conectar con el backend PHP: ' + err.message });
    } finally {
      setIsRunning(false);
    }
  };

  // 6. Guardar el Workflow en MariaDB (save_workflow.php)
  const handleSaveWorkflow = async () => {
    setIsSaving(true);

    const payload = {
      id: workflowId,
      name: 'Workflow Principal',
      nodes: nodes,
      edges: edges
    };

    try {
      const response = await authenticatedFetch('http://localhost:8000/save_workflow.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      const data = await response.json();
      if (data.success) {
        setWorkflowId(data.workflow_id);
        alert(`Workflow Guardado con Éxito. ID: ${data.workflow_id}`);
      } else {
        alert(`Error al guardar: ${data.error}`);
      }
    } catch (err) {
      alert(`Error de conexión con la base de datos: ${err.message}`);
    } finally {
      setIsSaving(false);
    }
  };

  // Extraer el tipo exacto del nodo seleccionado
  const selectedNodeType = selectedNode?.data?.type || selectedNode?.type;

  // Encontrar el esquema que coincide con el tipo del nodo seleccionado
  const selectedNodeSchema = nodeSchemas.find(
      (schema) => schema.type === selectedNodeType
  );

  // Si el usuario no está autenticado, solo renderiza la pantalla de bloqueo de login
  if (!isAuthenticated) {
    return (
        <div className="w-screen h-screen bg-slate-950 flex items-center justify-center">
          <LoginModal isOpen={true} onLoginSuccess={handleLoginSuccess} />
        </div>
    );
  }

  return (
      <div className="flex flex-col h-screen w-screen bg-slate-900 text-slate-100 overflow-hidden">
        {/* Top Navbar */}
        <header className="h-14 border-b border-slate-800 bg-slate-950/80 backdrop-blur px-4 flex items-center justify-between z-10">
          <div className="flex items-center gap-3">
            <div className="bg-indigo-600 p-1.5 rounded-lg text-white font-bold tracking-wider text-sm flex items-center gap-1">
              <Layers className="w-4 h-4" /> P8P
            </div>
            <span className="text-slate-500">/</span>
            <span className="text-sm font-medium text-slate-300">Editor Visual</span>
            {workflowId && (
                <span className="text-[10px] font-mono bg-slate-800 border border-slate-700 px-2 py-0.5 rounded text-indigo-400">
              ID: {workflowId.substring(0, 8)}...
            </span>
            )}
          </div>

          <div className="flex items-center gap-2">
            <button
                onClick={() => setIsModalOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-medium transition"
            >
              <Plus className="w-3.5 h-3.5" /> Agregar Nodo
            </button>

            <button
                onClick={() => setIsLoadModalOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium transition border border-slate-700"
            >
              <FolderOpen className="w-3.5 h-3.5 text-indigo-400" /> Abrir
            </button>

            <button
                onClick={handleSaveWorkflow}
                disabled={isSaving || nodes.length === 0}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-slate-200 rounded-lg text-xs font-medium transition border border-slate-700"
            >
              <Save className="w-3.5 h-3.5" /> {isSaving ? 'Guardando...' : 'Guardar'}
            </button>

            <button
                onClick={() => setIsCredentialsOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium border border-slate-700 transition"
            >
              <Key className="w-3.5 h-3.5 text-indigo-400" /> Credenciales
            </button>

            <button
                onClick={() => setIsLogsOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium border border-slate-700 transition"
            >
              <Activity className="w-3.5 h-3.5 text-indigo-400" /> Logs
            </button>

            <button
                onClick={handleTestWorkflow}
                disabled={isRunning || nodes.length === 0}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-xs font-medium transition shadow-lg shadow-emerald-900/20"
            >
              <Play className="w-3.5 h-3.5" /> {isRunning ? 'Ejecutando...' : 'Test Workflow'}
            </button>

            {/* Separador e información del usuario activo */}
            <div className="h-5 w-[1px] bg-slate-800 mx-1"></div>

            <div className="flex items-center gap-2 bg-slate-900 border border-slate-800 px-2.5 py-1 rounded-lg text-xs">
              <User className="w-3.5 h-3.5 text-indigo-400" />
              <span className="text-slate-300 font-medium">{user}</span>
            </div>

            <button
                onClick={handleLogout}
                title="Cerrar Sesión"
                className="p-1.5 bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 rounded-lg transition"
            >
              <LogOut className="w-3.5 h-3.5" />
            </button>
          </div>
        </header>

        {/* Canvas Principal y Paneles */}
        <main className="flex-1 relative flex overflow-hidden">
          <div className="flex-1 relative">
            <Canvas
                nodes={nodes}
                setNodes={setNodes}
                edges={edges}
                setEdges={setEdges}
                onNodeSelect={setSelectedNode}
            />
          </div>

          {/* Modal de selección de nuevos nodos */}
          <AddNodeModal
              isOpen={isModalOpen}
              onClose={() => setIsModalOpen(false)}
              onAddNode={handleAddNode}
          />

          {/* Modal para cargar workflows guardados */}
          <LoadWorkflowModal
              isOpen={isLoadModalOpen}
              onClose={() => setIsLoadModalOpen(false)}
              onLoadWorkflow={handleLoadWorkflow}
          />

          {/* Modal para gestión de credenciales encriptadas */}
          <CredentialsModal
              isOpen={isCredentialsOpen}
              onClose={() => setIsCredentialsOpen(false)}
          />

          {/* Modal para historial de ejecuciones y logs */}
          <ExecutionLogsModal
              isOpen={isLogsOpen}
              onClose={() => setIsLogsOpen(false)}
          />

          {/* Inspector lateral para editar parámetros del nodo */}
          {selectedNode && (
              <NodeInspector
                  selectedNode={selectedNode}
                  nodeSchema={selectedNodeSchema}
                  onClose={() => setSelectedNode(null)}
                  onUpdateNode={handleUpdateNode}
                  onDeleteNode={handleDeleteNode}
              />
          )}

          {/* Consola inferior con el JSON de respuesta de la API en PHP */}
          {executionResult && (
              <div className="absolute bottom-4 left-4 right-4 max-h-48 bg-slate-950/90 border border-slate-700 rounded-xl p-3 font-mono text-xs overflow-auto z-30 shadow-2xl">
                <div className="flex justify-between items-center mb-2 border-b border-slate-800 pb-1 text-slate-400">
                  <span>Resultado de Ejecución (Backend PHP)</span>
                  <button onClick={() => setExecutionResult(null)} className="hover:text-white">✕</button>
                </div>
                <pre className="text-emerald-400">{JSON.stringify(executionResult, null, 2)}</pre>
              </div>
          )}
        </main>
      </div>
  );
}
