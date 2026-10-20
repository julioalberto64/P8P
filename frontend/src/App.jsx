import React, { useState } from 'react';
import Canvas from './components/Canvas';
import AddNodeModal from './components/AddNodeModal';
import LoadWorkflowModal from './components/LoadWorkflowModal';
import CredentialsModal from './components/CredentialsModal';
import ExecutionLogsModal from './components/ExecutionLogsModal';
import NodeInspector from './components/NodeInspector';
import NewWorkflowModal from './components/NewWorkflowModal';
import { FilePlus, FolderOpen, Save, Play, Key, Activity, Plus, Copy, Check } from 'lucide-react';

export default function App() {
  const [nodes, setNodes] = useState([]);
  const [edges, setEdges] = useState([]);
  const [workflowId, setWorkflowId] = useState(null);
  const [workflowName, setWorkflowName] = useState('Mi Workflow P8P');
  const [copiedId, setCopiedId] = useState(false);

  // Estado de Modales
  const [isAddNodeOpen, setIsAddNodeOpen] = useState(false);
  const [isLoadModalOpen, setIsLoadModalOpen] = useState(false);
  const [isCredentialsOpen, setIsCredentialsOpen] = useState(false);
  const [isLogsOpen, setIsLogsOpen] = useState(false);
  const [isNewModalOpen, setIsNewModalOpen] = useState(false);

  // Inspector
  const [selectedNode, setSelectedNode] = useState(null);

  // Reiniciar el lienzo para un Nuevo Workflow
  const handleNewWorkflow = () => {
    setNodes([]);
    setEdges([]);
    setWorkflowId(null);
    setWorkflowName('Mi Nuevo Workflow P8P');
    setSelectedNode(null);
  };

  // Copiar ID al portapapeles
  const handleCopyId = () => {
    if (!workflowId) return;
    navigator.clipboard.writeText(workflowId);
    setCopiedId(true);
    setTimeout(() => setCopiedId(false), 2000);
  };

  // Guardar Workflow en MariaDB
  const handleSaveWorkflow = async () => {
    try {
      const token = localStorage.getItem('p8p_token');
      const res = await fetch('http://localhost:8000/save_workflow.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        },
        body: JSON.stringify({
          id: workflowId,
          name: workflowName,
          nodes,
          edges
        })
      });

      const data = await res.json();
      if (data.success) {
        setWorkflowId(data.workflow_id);
        alert('Workflow guardado correctamente');
      } else {
        alert('Error al guardar: ' + (data.error || 'Error desconocido'));
      }
    } catch (err) {
      alert('Error al conectar con el servidor');
    }
  };

  // Cargar Workflow
  const handleLoadWorkflow = async (id) => {
    try {
      const token = localStorage.getItem('p8p_token');
      const res = await fetch(`http://localhost:8000/get_workflows.php?id=${id}`, {
        headers: {
          'Content-Type': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        }
      });

      const data = await res.json();
      if (data.success && data.workflow) {
        setWorkflowId(data.workflow.id);
        setWorkflowName(data.workflow.name);
        setNodes(data.workflow.structure?.nodes || []);
        setEdges(data.workflow.structure?.edges || []);
        setIsLoadModalOpen(false);
      }
    } catch (err) {
      console.error('Error al cargar workflow:', err);
    }
  };

  // Ejecutar Prueba de Workflow
  const handleTestRun = async () => {
    try {
      const token = localStorage.getItem('p8p_token');
      await fetch('http://localhost:8000/api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        },
        body: JSON.stringify({ nodes, edges })
      });

      setIsLogsOpen(true);
    } catch (err) {
      alert('Error durante la ejecución del flujo');
    }
  };

  return (
      <div className="flex h-screen w-screen bg-slate-950 overflow-hidden font-sans relative">

        {/* Barra de Acciones Flotante en la esquina Superior Derecha */}
        <div className="absolute top-4 right-4 z-20 flex items-center gap-2 bg-slate-900/90 backdrop-blur-md border border-slate-800 p-2 rounded-2xl shadow-2xl">

          {/* Nombre del Workflow e Indicador de ID */}
          <div className="flex flex-col gap-0.5">
            <input
                type="text"
                value={workflowName}
                onChange={(e) => setWorkflowName(e.target.value)}
                placeholder="Nombre del Workflow..."
                className="bg-slate-950 border border-slate-800 rounded-xl px-2.5 py-1 text-xs text-slate-100 focus:outline-none focus:border-indigo-500 font-medium w-44"
            />

            {/* Muestra del ID del Workflow */}
            <div className="flex items-center justify-between bg-slate-950/80 border border-slate-800/80 px-2 py-0.5 rounded-lg text-[9px] text-slate-400 font-mono">
                        <span className="truncate max-w-[120px]">
                            {workflowId ? `ID: ${workflowId.substring(0, 10)}...` : 'ID: [Nuevo]'}
                        </span>
              {workflowId && (
                  <button
                      onClick={handleCopyId}
                      className="text-indigo-400 hover:text-indigo-300 ml-1 transition"
                      title="Copiar ID completo"
                  >
                    {copiedId ? <Check className="w-2.5 h-2.5 text-emerald-400" /> : <Copy className="w-2.5 h-2.5" />}
                  </button>
              )}
            </div>
          </div>

          <div className="h-6 w-[1px] bg-slate-800 mx-0.5" />



          {/* Botón Nuevo Workflow */}
          <button
              onClick={() => setIsNewModalOpen(true)}
              className="flex items-center gap-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-medium px-3 py-1.5 rounded-xl transition"
              title="Limpiar Lienzo"
          >
            <FilePlus className="w-3.5 h-3.5 text-indigo-400" />
            <span>Nuevo</span>
          </button>

          {/* Botón Abrir */}
          <button
              onClick={() => setIsLoadModalOpen(true)}
              className="flex items-center gap-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-medium px-3 py-1.5 rounded-xl transition"
          >
            <FolderOpen className="w-3.5 h-3.5 text-sky-400" />
            <span>Abrir</span>
          </button>


          {/* Botón Agregar Nodo */}
          <button
              onClick={() => setIsAddNodeOpen(true)}
              className="flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shadow-md"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>Agregar Nodo</span>
          </button>

          
          {/* Botón Guardar */}
          <button
              onClick={handleSaveWorkflow}
              className="flex items-center gap-1.5 bg-indigo-600/80 hover:bg-indigo-600 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition"
          >
            <Save className="w-3.5 h-3.5" />
            <span>Guardar</span>
          </button>

          {/* Botón Probar */}
          <button
              onClick={handleTestRun}
              className="flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition"
          >
            <Play className="w-3.5 h-3.5 fill-current" />
            <span>Probar</span>
          </button>

          <div className="h-6 w-[1px] bg-slate-800 mx-0.5" />

          {/* Credenciales */}
          <button
              onClick={() => setIsCredentialsOpen(true)}
              className="p-1.5 text-slate-400 hover:text-slate-200 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl transition"
              title="Gestor de Credenciales"
          >
            <Key className="w-4 h-4 text-amber-400" />
          </button>

          {/* Logs */}
          <button
              onClick={() => setIsLogsOpen(true)}
              className="p-1.5 text-slate-400 hover:text-slate-200 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl transition"
              title="Logs de Ejecución"
          >
            <Activity className="w-4 h-4 text-purple-400" />
          </button>
        </div>

        {/* Lienzo Principal de React Flow */}
        <div className="flex-1 h-full">
          <Canvas
              nodes={nodes}
              setNodes={setNodes}
              edges={edges}
              setEdges={setEdges}
              onNodeSelect={(node) => setSelectedNode(node)}
          />
        </div>

        {/* Panel del Inspector de Nodos */}
        {selectedNode && (
            <NodeInspector
                selectedNode={selectedNode}
                onClose={() => setSelectedNode(null)}
                onUpdateNode={(id, newData) => {
                  setNodes((nds) =>
                      nds.map((node) => (node.id === id ? { ...node, data: newData } : node))
                  );
                }}
                onDeleteNode={(id) => {
                  setNodes((nds) => nds.filter((n) => n.id !== id));
                  setEdges((eds) => eds.filter((e) => e.source !== id && e.target !== id));
                  setSelectedNode(null);
                }}
            />
        )}

        {/* Modales */}
        <AddNodeModal
            isOpen={isAddNodeOpen}
            onClose={() => setIsAddNodeOpen(false)}
            onAddNode={(nodeData) => {
              const newNode = {
                id: `node_${Date.now()}`,
                type: 'customNode',
                position: { x: 250, y: 150 },
                data: {
                  label: nodeData.label,
                  type: nodeData.type,
                  isStart: nodeData.isStart,
                  parameters: {}
                }
              };
              setNodes((nds) => [...nds, newNode]);
            }}
        />

        <LoadWorkflowModal
            isOpen={isLoadModalOpen}
            onClose={() => setIsLoadModalOpen(false)}
            onLoadWorkflow={handleLoadWorkflow}
        />

        <CredentialsModal
            isOpen={isCredentialsOpen}
            onClose={() => setIsCredentialsOpen(false)}
        />

        <ExecutionLogsModal
            isOpen={isLogsOpen}
            onClose={() => setIsLogsOpen(false)}
        />

        <NewWorkflowModal
            isOpen={isNewModalOpen}
            onClose={() => setIsNewModalOpen(false)}
            onConfirm={handleNewWorkflow}
        />
      </div>
  );
}
