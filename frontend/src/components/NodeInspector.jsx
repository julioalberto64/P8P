import React from 'react';
import { X, Trash2, HelpCircle } from 'lucide-react';

export default function NodeInspector({ selectedNode, onClose, onUpdateNode, onDeleteNode }) {
    if (!selectedNode) return null;

    const { id, data } = selectedNode;
    const nodeType = (data.type || '').toLowerCase();
    const parameters = data.parameters || {};

    const handleParamChange = (key, value) => {
        onUpdateNode(id, {
            ...data,
            parameters: {
                ...parameters,
                [key]: value,
            },
        });
    };

    return (
        <aside className="absolute top-4 right-4 bottom-4 w-84 bg-slate-800/95 backdrop-blur border border-slate-700 rounded-xl p-4 shadow-2xl z-20 flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between border-b border-slate-700 pb-3 mb-4">
                <div>
                    <h3 className="font-semibold text-slate-100 text-sm">{data.label}</h3>
                    <span className="text-[10px] text-indigo-400 font-mono uppercase">{nodeType}</span>
                </div>
                <button
                    onClick={onClose}
                    className="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-700 transition"
                >
                    <X className="w-4 h-4" />
                </button>
            </div>

            {/* Form Parameters */}
            <div className="flex-1 space-y-4 overflow-y-auto pr-1 text-xs text-slate-300">
                <div>
                    <label className="block text-slate-400 mb-1 font-medium">Node ID</label>
                    <input
                        type="text"
                        disabled
                        value={id}
                        className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-500 font-mono text-[11px]"
                    />
                </div>

                {/* HTTP Request Form */}
                {nodeType === 'http_request' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Method</label>
                            <select
                                value={parameters.method || 'GET'}
                                onChange={(e) => handleParamChange('method', e.target.value)}
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500"
                            >
                                <option value="GET">GET</option>
                                <option value="POST">POST</option>
                                <option value="PUT">PUT</option>
                                <option value="DELETE">DELETE</option>
                            </select>
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">URL Endpoint</label>
                            <input
                                type="text"
                                value={parameters.url || ''}
                                onChange={(e) => handleParamChange('url', e.target.value)}
                                placeholder="https://api.example.com/data o {{url}}"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Headers (JSON)</label>
                            <textarea
                                rows={2}
                                value={parameters.headers || ''}
                                onChange={(e) => handleParamChange('headers', e.target.value)}
                                placeholder='{"Authorization": "Bearer token"}'
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Request Body (JSON)</label>
                            <textarea
                                rows={3}
                                value={parameters.body || ''}
                                onChange={(e) => handleParamChange('body', e.target.value)}
                                placeholder='{"title": "{{title}}"}'
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* Set Variable Form */}
                {nodeType === 'set_variable' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Variable Name</label>
                            <input
                                type="text"
                                value={parameters.variable_name || ''}
                                onChange={(e) => handleParamChange('variable_name', e.target.value)}
                                placeholder="ej. url"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Variable Value</label>
                            <input
                                type="text"
                                value={parameters.variable_value || ''}
                                onChange={(e) => handleParamChange('variable_value', e.target.value)}
                                placeholder="ej. https://jsonplaceholder.typicode.com/todos/1"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* Google Gemini Model Form */}
                {nodeType === 'gemini_model' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">API Key (Google AI Studio)</label>
                            <input
                                type="password"
                                value={parameters.api_key || ''}
                                onChange={(e) => handleParamChange('api_key', e.target.value)}
                                placeholder="AIzaSy..."
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Model</label>
                            <select
                                value={parameters.model || 'gemini-3.8-flash'}
                                onChange={(e) => handleParamChange('model', e.target.value)}
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 text-xs"
                            >
                                <option value="gemini-3.8-flash">gemini-3.8-flash (Recomendado)</option>
                                <option value="gemini-3-flash">gemini-3-flash</option>
                                <option value="gemini-2.5-flash">gemini-2.5-flash</option>
                                <option value="gemini-1.5-flash">gemini-1.5-flash</option>
                            </select>
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Temperature</label>
                            <input
                                type="number"
                                step="0.1"
                                min="0"
                                max="2"
                                value={parameters.temperature ?? 0.7}
                                onChange={(e) => handleParamChange('temperature', parseFloat(e.target.value))}
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* Ollama Chat Model Form */}
                {nodeType === 'ollama_model' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Ollama Endpoint</label>
                            <input
                                type="text"
                                value={parameters.endpoint || 'http://127.0.0.1:11434'}
                                onChange={(e) => handleParamChange('endpoint', e.target.value)}
                                placeholder="http://127.0.0.1:11434"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Model Name</label>
                            <input
                                type="text"
                                value={parameters.model || 'llama3'}
                                onChange={(e) => handleParamChange('model', e.target.value)}
                                placeholder="llama3, mistral, qwen..."
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* AI Agent Form */}
                {nodeType === 'ai_agent' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">System Prompt</label>
                            <textarea
                                rows={4}
                                value={parameters.system_prompt || ''}
                                onChange={(e) => handleParamChange('system_prompt', e.target.value)}
                                placeholder="Eres un asistente de automatización en P8P..."
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* MCP Tool Form */}
                {nodeType === 'mcp_tool' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">MCP Server URL</label>
                            <input
                                type="text"
                                value={parameters.server_url || ''}
                                onChange={(e) => handleParamChange('server_url', e.target.value)}
                                placeholder="http://localhost:3001/mcp"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Tool Name</label>
                            <input
                                type="text"
                                value={parameters.tool_name || ''}
                                onChange={(e) => handleParamChange('tool_name', e.target.value)}
                                placeholder="search_database"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* Start Trigger Form */}
                {nodeType === 'start' && (
                    <div className="p-3 bg-slate-900/50 rounded-lg border border-slate-700/50 text-slate-400 text-[11px]">
                        Nodo detonador inicial. No requiere parámetros adicionales.
                    </div>
                )}

                {/* Dynamic Variable Hint */}
                <div className="p-2.5 bg-indigo-500/10 border border-indigo-500/20 rounded-lg text-[11px] text-indigo-300 flex items-start gap-2">
                    <HelpCircle className="w-4 h-4 shrink-0 mt-0.5" />
                    <span>
            Puedes usar expresiones dinámicas como <code className="bg-slate-900 px-1 py-0.5 rounded text-white">{`{{nombre_variable}}`}</code> o <code className="bg-slate-900 px-1 py-0.5 rounded text-white">{`{{node_id.output.key}}`}</code> para inyectar datos entre nodos.
          </span>
                </div>
            </div>

            {/* Footer Actions */}
            <div className="border-t border-slate-700 pt-3 mt-auto">
                <button
                    onClick={() => onDeleteNode(id)}
                    className="w-full flex items-center justify-center gap-1.5 px-3 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 rounded-lg text-xs font-medium border border-red-500/20 transition"
                >
                    <Trash2 className="w-3.5 h-3.5" /> Eliminar Nodo
                </button>
            </div>
        </aside>
    );
}
