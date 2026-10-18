import React, { useState, useEffect } from 'react';
import { X, Trash2, HelpCircle } from 'lucide-react';

export default function NodeInspector({ selectedNode, onClose, onUpdateNode, onDeleteNode }) {
    const [credentialsList, setCredentialsList] = useState([]);

    // Cargar la lista pública de credenciales desde MariaDB
    useEffect(() => {
        const fetchCredentials = async () => {
            try {
                const res = await fetch('http://localhost:8000/credentials.php');
                const data = await res.json();
                if (data.success) {
                    setCredentialsList(data.credentials || []);
                }
            } catch (err) {
                console.error('Error al cargar lista de credenciales:', err);
            }
        };

        fetchCredentials();
    }, [selectedNode]);

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

                {/* If Condition Form */}
                {nodeType === 'if_condition' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Value 1</label>
                            <input
                                type="text"
                                value={parameters.value_1 || ''}
                                onChange={(e) => handleParamChange('value_1', e.target.value)}
                                placeholder="ej. {{output_text}} o {{title}}"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Operator</label>
                            <select
                                value={parameters.operator || 'equals'}
                                onChange={(e) => handleParamChange('operator', e.target.value)}
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 text-xs"
                            >
                                <option value="equals">Equals (==)</option>
                                <option value="not_equals">Not Equals (!=)</option>
                                <option value="contains">Contains</option>
                                <option value="not_contains">Does Not Contain</option>
                                <option value="is_empty">Is Empty</option>
                                <option value="is_not_empty">Is Not Empty</option>
                            </select>
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Value 2</label>
                            <input
                                type="text"
                                value={parameters.value_2 || ''}
                                onChange={(e) => handleParamChange('value_2', e.target.value)}
                                placeholder="ej. OK"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* Chat Trigger Form */}
                {nodeType === 'chat_trigger' && (
                    <div className="p-3 bg-slate-900/50 rounded-lg border border-slate-700/50 text-slate-400 text-[11px] space-y-2">
                        <div>Punto de entrada para el widget de chat flotante (<code className="text-indigo-400">p8p-chat.js</code>).</div>
                        <div>Recibe automáticamente las variables <code className="text-emerald-400">{`{{user_message}}`}</code> y <code className="text-emerald-400">{`{{session_id}}`}</code>.</div>
                    </div>
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
                                placeholder="ej. title"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Variable Value</label>
                            <input
                                type="text"
                                value={parameters.variable_value || ''}
                                onChange={(e) => handleParamChange('variable_value', e.target.value)}
                                placeholder="ej. Mi Titulo Personalizado"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* HTTP Request Form */}
                {nodeType === 'http_request' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Method</label>
                            <select
                                value={parameters.method || 'GET'}
                                onChange={(e) => handleParamChange('method', e.target.value)}
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 text-xs"
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
                                placeholder='{"Content-Type": "application/json"}'
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Request Body (JSON)</label>
                            <textarea
                                rows={3}
                                value={parameters.body || ''}
                                onChange={(e) => handleParamChange('body', e.target.value)}
                                placeholder='{"response": "{{output_text}}"}'
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {/* Google Gemini Model Form */}
                {nodeType === 'gemini_model' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Credencial Guardada</label>
                            <select
                                value={parameters.credential_id || ''}
                                onChange={(e) => handleParamChange('credential_id', e.target.value)}
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 text-xs mb-2"
                            >
                                <option value="">-- Seleccionar Credencial Segura --</option>
                                {credentialsList.map((c) => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                        </div>

                        {!parameters.credential_id && (
                            <div>
                                <label className="block text-slate-400 mb-1 font-medium">O API Key Directa (Texto Plano)</label>
                                <input
                                    type="password"
                                    value={parameters.api_key || ''}
                                    onChange={(e) => handleParamChange('api_key', e.target.value)}
                                    placeholder="AIzaSy..."
                                    className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                                />
                            </div>
                        )}

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Model</label>
                            <select
                                value={parameters.model || 'gemini-3.8-flash'}
                                onChange={(e) => handleParamChange('model', e.target.value)}
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 text-xs"
                            >
                                <option value="gemini-3.8-flash">gemini-3.8-flash (Recomendado)</option>
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

                {/* Ollama Model Form */}
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
            Puedes usar expresiones dinámicas como <code className="bg-slate-900 px-1 py-0.5 rounded text-white">{`{{nombre_variable}}`}</code> o <code className="bg-slate-900 px-1 py-0.5 rounded text-white">{`{{node_id.output_text}}`}</code> para inyectar datos entre nodos.
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
