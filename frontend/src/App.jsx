import React, { useState } from 'react';
import Canvas from './components/Canvas.jsx';
import AddNodeModal from './components/AddNodeModal.jsx';
import NodeInspector from './components/NodeInspector.jsx';
import LoadWorkflowModal from './components/LoadWorkflowModal.jsx';
import CredentialsModal from './components/CredentialsModal.jsx';
import { Play, Plus, Save, Layers, FolderOpen, Key } from 'lucide-react';

export default function App() {
  const [selectedNode, setSelectedNode] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isLoadModalOpen, setIsLoadModalOpen] = useState(false);
  const [isCredentialsOpen, setIsCredentialsOpen] = useState(false);
  const [nodes, setNodes] = useState([]);
  const [edges, setEdges] = useState([]);
  const [executionResult, setExecutionResult] = useState(null);
  const [isRunning, setIsRunning] = useState(false);
  const [workflowId, setWorkflowId] = useState(null);
  const [isSaving, setIsSaving] = useState(false);

  // 1. Agregar un nuevo nodo al lienzo
  const handleAddNode = (nodeType) => {
    const newNode = {
      id: `node_${Date.now()}`,
      type: 'customNode',
      position: { x: 250 + nodes.length * 40, y: 150 + nodes.length * 40 },
      data: {
        label: nodeType.label,
        type: nodeType.type,
        isStart: nodeType.isStart,
        parameters: nodeType.type === 'http_request'
            ? { url: 'https://jsonplaceholder.typicode.com/todos/1', method: 'GET' }
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

  // 4. Cargar un workflow guardado desde MariaDB (get_workflows.php)
  const handleLoadWorkflow = async (id) => {
    try {
      const response = await fetch(`http://localhost:8000/get_workflows.php?id=${id}`);
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
      const response = await fetch('http://localhost:8000/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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
      const response = await fetch('http://localhost:8000/save_workflow.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const data = await response.json();
      if (data.success) {
        setWorkflowId(data.workflow_id);
        alert(`Workflow guardado con éxito en MariaDB. ID: ${data.workflow_id}`);
      } else {
        alert(`Error al guardar: ${data.error}`);
      }
    } catch (err) {
      alert(`Error de conexión con la base de datos: ${err.message}`);
    } finally {
      setIsSaving(false);
    }
  };

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
              <FolderOpen className="w-3.5 h-3.5 text-indigo-400" /> Open
            </button>

            <button
                onClick={handleSaveWorkflow}
                disabled={isSaving || nodes.length === 0}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-slate-200 rounded-lg text-xs font-medium transition border border-slate-700"
            >
              <Save className="w-3.5 h-3.5" /> {isSaving ? 'Guardando...' : 'Save'}
            </button>

            <button
                onClick={() => setIsCredentialsOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium border border-slate-700 transition"
            >
              <Key className="w-3.5 h-3.5 text-indigo-400" /> Credenciales
            </button>

            <button
                onClick={handleTestWorkflow}
                disabled={isRunning || nodes.length === 0}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-xs font-medium transition shadow-lg shadow-emerald-900/20"
            >
              <Play className="w-3.5 h-3.5" /> {isRunning ? 'Ejecutando...' : 'Test Workflow'}
            </button>
          </div>
        </header>

        {/* Canvas Principal */}
        <main className="flex-1 relative">
          <Canvas
              nodes={nodes}
              setNodes={setNodes}
              edges={edges}
              setEdges={setEdges}
              onNodeSelect={setSelectedNode}
          />

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

          {/* Inspector lateral para editar parámetros del nodo */}
          <NodeInspector
              selectedNode={selectedNode}
              onClose={() => setSelectedNode(null)}
              onUpdateNode={handleUpdateNode}
              onDeleteNode={handleDeleteNode}
          />

          {/* Consola inferior con el JSON de respuesta de la API en PHP */}
          {executionResult && (
              <div className="absolute bottom-4 left-4 right-4 max-h-48 bg-slate-950/90 border border-slate-700 rounded-xl p-3 font-mono text-xs overflow-auto z-20 shadow-2xl">
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
