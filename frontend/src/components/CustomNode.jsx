import React from 'react';
import { Handle, Position } from '@xyflow/react';
import { Globe, Play, Settings, Bot, Cpu, Database, Wrench, Sparkles } from 'lucide-react';

const icons = {
    start: Play,
    http_request: Globe,
    ai_agent: Bot,
    gemini_model: Sparkles,
    ollama_model: Cpu,
    simple_memory: Database,
    mcp_tool: Wrench,
    default: Settings
};

export default function CustomNode({ data, selected }) {
    const IconComponent = icons[data.type] || icons.default;
    const isAgent = data.type === 'ai_agent';

    // Determinar si es un sub-nodo de soporte (Modelo, Memoria o Herramienta)
    const isModelNode = data.type === 'ollama_model' || data.type === 'gemini_model';
    const isMemoryNode = data.type === 'simple_memory';
    const isToolNode = data.type === 'mcp_tool';
    const isSubNode = isModelNode || isMemoryNode || isToolNode;

    return (
        <div className={`px-4 py-3 shadow-xl rounded-xl bg-slate-800 border-2 min-w-[200px] relative transition-all ${
            selected ? 'border-indigo-500 ring-2 ring-indigo-500/30' : 'border-slate-700'
        }`}>
            {/* Entrada Principal (Izquierda) - Solo para nodos del flujo principal */}
            {!data.isStart && !isSubNode && (
                <Handle
                    type="target"
                    position={Position.Left}
                    id="main_input"
                    className="!w-3 !h-3 !bg-indigo-400 !border-2 !border-slate-800"
                />
            )}

            <div className="flex items-center gap-3">
                <div className={`p-2 rounded-lg ${
                    isAgent ? 'bg-purple-500/20 text-purple-400' :
                        data.isStart ? 'bg-emerald-500/20 text-emerald-400' :
                            data.type === 'gemini_model' ? 'bg-sky-500/20 text-sky-400' : 'bg-indigo-500/20 text-indigo-400'
                }`}>
                    <IconComponent className="w-5 h-5" />
                </div>
                <div>
                    <div className="text-[10px] text-slate-400 font-mono uppercase tracking-wider">
                        {data.type || 'Node'}
                    </div>
                    <div className="text-xs font-semibold text-slate-100">
                        {data.label}
                    </div>
                </div>
            </div>

            {/* Salida Principal (Derecha) */}
            {!isModelNode && !isMemoryNode && (
                <Handle
                    type="source"
                    position={isToolNode ? Position.Top : Position.Right}
                    id={isToolNode ? 'tools' : 'main_output'}
                    className="!w-3 !h-3 !bg-indigo-400 !border-2 !border-slate-800"
                />
            )}

            {/* SUB-PUERTOS INFERIORES PARA AI AGENT (Model, Memory, Tools) */}
            {isAgent && (
                <div className="flex justify-between items-center pt-3 mt-3 border-t border-slate-700/60 text-[9px] text-slate-400 font-mono">
                    {/* Handle Modelo */}
                    <div className="relative flex flex-col items-center">
                        <span>Model*</span>
                        <Handle
                            type="target"
                            position={Position.Bottom}
                            id="model"
                            className="!w-2.5 !h-2.5 !bg-purple-400 !border-2 !border-slate-800 !-bottom-4"
                        />
                    </div>

                    {/* Handle Memoria */}
                    <div className="relative flex flex-col items-center">
                        <span>Memory</span>
                        <Handle
                            type="target"
                            position={Position.Bottom}
                            id="memory"
                            className="!w-2.5 !h-2.5 !bg-emerald-400 !border-2 !border-slate-800 !-bottom-4"
                        />
                    </div>

                    {/* Handle Herramientas MCP */}
                    <div className="relative flex flex-col items-center">
                        <span>Tools</span>
                        <Handle
                            type="target"
                            position={Position.Bottom}
                            id="tools"
                            className="!w-2.5 !h-2.5 !bg-amber-400 !border-2 !border-slate-800 !-bottom-4"
                        />
                    </div>
                </div>
            )}

            {/* Salida Superior para Sub-Nodos de Modelo o Memoria */}
            {(isModelNode || isMemoryNode) && (
                <Handle
                    type="source"
                    position={Position.Top}
                    id={isModelNode ? 'model' : 'memory'}
                    className="!w-3 !h-3 !bg-purple-400 !border-2 !border-slate-800"
                />
            )}
        </div>
    );
}
