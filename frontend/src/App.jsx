import React, { useState } from 'react';
import Canvas from './components/Canvas';
import AddNodeModal from './components/AddNodeModal';
import { Play, Plus, Layers } from 'lucide-react';

export default function App() {
  const [selectedNode, setSelectedNode] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [nodes, setNodes] = useState([]);
  const [edges, setEdges] = useState([]);
  const [executionResult, setExecutionResult] = useState(null);
  const [isRunning, setIsRunning] = useState(false);

  const handleAddNode = (nodeType) => {
    const newNode = {
      id: `node_${Date.now()}`,
      type: 'customNode',
      position: { x: 250 + nodes.length * 50, y: 200 },
      data: {
        label: nodeType.label,
        type: nodeType.type,
        isStart: nodeType.isStart,
        parameters: {
          url: 'https://jsonplaceholder.typicode.com/todos/1',
          method: 'GET'
        }
      },
    };
    setNodes((prev) => [...prev, newNode]);
  };

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
        target: e.target
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

  return (
      <div className="flex flex-col h-screen w-screen bg-slate-900 text-slate-100 overflow-hidden">
        <header className="h-14 border-b border-slate-800 bg-slate-950/80 backdrop-blur px-4 flex items-center justify-between z-10">
          <div className="flex items-center gap-3">
            <div className="bg-indigo-600 p-1.5 rounded-lg text-white font-bold tracking-wider text-sm flex items-center gap-1">
              <Layers className="w-4 h-4" /> P8P
            </div>
            <span className="text-slate-500">/</span>
            <span className="text-sm font-medium text-slate-300">Editor Visual</span>
          </div>

          <div className="flex items-center gap-2">
            <button
                onClick={() => setIsModalOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-medium transition"
            >
              <Plus className="w-3.5 h-3.5" /> Agregar Nodo
            </button>
            <button
                onClick={handleTestWorkflow}
                disabled={isRunning || nodes.length === 0}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-xs font-medium transition"
            >
              <Play className="w-3.5 h-3.5" /> {isRunning ? 'Ejecutando...' : 'Test Workflow'}
            </button>
          </div>
        </header>

        <main className="flex-1 relative">
          <Canvas
              nodes={nodes}
              setNodes={setNodes}
              edges={edges}
              setEdges={setEdges}
              onNodeSelect={setSelectedNode}
          />

          <AddNodeModal
              isOpen={isModalOpen}
              onClose={() => setIsModalOpen(false)}
              onAddNode={handleAddNode}
          />

          {/* Console de Resultados */}
          {executionResult && (
              <div className="absolute bottom-4 left-4 right-4 max-h-48 bg-slate-950/90 border border-slate-700 rounded-xl p-3 font-mono text-xs overflow-auto z-20">
                <div className="flex justify-between items-center mb-2 border-b border-slate-800 pb-1 text-slate-400">
                  <span>Resultado de la Ejecución</span>
                  <button onClick={() => setExecutionResult(null)} className="hover:text-white">✕</button>
                </div>
                <pre className="text-emerald-400">{JSON.stringify(executionResult, null, 2)}</pre>
              </div>
          )}
        </main>
      </div>
  );
}
