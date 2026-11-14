import { useCallback } from 'react';
import {
    ReactFlow,
    Controls,
    Background,
    applyNodeChanges,
    applyEdgeChanges,
    addEdge,
    BackgroundVariant
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import CustomNode from './CustomNode.jsx';
import DeletableEdge from './DeletableEdge.jsx';
import { CanvasWatermark } from './canvasCore.jsx';

const nodeTypes = {
    customNode: CustomNode,
};

const edgeTypes = {
    default: DeletableEdge,
};

export default function Canvas({ nodes, setNodes, edges, setEdges, onNodeSelect }) {
    const onNodesChange = useCallback(
        (changes) => setNodes((nds) => applyNodeChanges(changes, nds)),
        [setNodes]
    );

    const onEdgesChange = useCallback(
        (changes) => setEdges((eds) => applyEdgeChanges(changes, eds)),
        [setEdges]
    );

    const onConnect = useCallback(
        (params) => setEdges((eds) => addEdge({ ...params, animated: true }, eds)),
        [setEdges]
    );

    const handleNodeClick = (_, node) => {
        if (onNodeSelect) {
            onNodeSelect(node);
        }
    };

    return (
        <div className="w-full h-full bg-slate-900">
            <ReactFlow
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                onConnect={onConnect}
                onNodeClick={handleNodeClick}
                proOptions={{ hideAttribution: true }}
                fitView
            >
                <Background color="#334155" variant={BackgroundVariant.Dots} gap={20} size={1} />
                <Controls
                    position="bottom-right"
                    className="!bg-slate-800 !border-slate-700 !text-slate-200 [&>button]:!bg-slate-800 [&>button]:!border-slate-700 [&>button]:!fill-slate-200 hover:[&>button]:!bg-slate-700"
                />
                <CanvasWatermark />
            </ReactFlow>
        </div>
    );
}