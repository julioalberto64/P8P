import React from 'react';
import { Globe, Play, Sliders, Bot, Cpu, Database, Wrench, Sparkles, X } from 'lucide-react';
const nodeTypes = [
    {
        type: 'start',
        label: 'Start Trigger',
        description: 'Disparador inicial de mensajes o eventos',
        icon: Play,
        isStart: true,
    },
    {
        type: 'ai_agent',
        label: 'AI Agent',
        description: 'Agente autónomo con bucle ReAct (Model + Memory + Tools)',
        icon: Bot,
        isStart: false,
    },
    {
        type: 'gemini_model',
        label: 'Google Gemini Chat Model',
        description: 'Modelo LLM en la nube de Google (Gemini 1.5 Flash / Pro)',
        icon: Sparkles,
        isStart: false,
    },
    {
        type: 'ollama_model',
        label: 'Ollama Chat Model',
        description: 'Modelo LLM local o remoto (Llama 3, Mistral, Qwen)',
        icon: Cpu,
        isStart: false,
    },
    {
        type: 'simple_memory',
        label: 'Simple Memory',
        description: 'Memoria buffer de conversación',
        icon: Database,
        isStart: false,
    },
    {
        type: 'mcp_tool',
        label: 'MCP Server Tool',
        description: 'Conector de herramientas Model Context Protocol (JSON-RPC)',
        icon: Wrench,
        isStart: false,
    },
    {
        type: 'http_request',
        label: 'HTTP Request',
        description: 'Petición GET, POST, PUT o DELETE a una API',
        icon: Globe,
        isStart: false,
    },
];

export default function AddNodeModal({ isOpen, onClose, onAddNode }) {
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-5 shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-700 pb-3 mb-4">
                    <h3 className="font-semibold text-slate-100">Agregar Nodo o Sub-conector</h3>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-700">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <div className="space-y-2.5 max-h-96 overflow-y-auto pr-1">
                    {nodeTypes.map((item) => {
                        const Icon = item.icon;
                        return (
                            <button
                                key={item.type}
                                onClick={() => {
                                    onAddNode(item);
                                    onClose();
                                }}
                                className="w-full flex items-start gap-3.5 p-3 rounded-lg bg-slate-900/60 hover:bg-slate-700/60 border border-slate-700/50 hover:border-indigo-500/50 transition text-left group"
                            >
                                <div className="p-2.5 rounded-lg bg-indigo-500/20 text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition">
                                    <Icon className="w-5 h-5" />
                                </div>
                                <div>
                                    <div className="text-sm font-semibold text-slate-200">{item.label}</div>
                                    <div className="text-xs text-slate-400 mt-0.5">{item.description}</div>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
