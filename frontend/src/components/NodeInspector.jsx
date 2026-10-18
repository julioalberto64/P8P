import React from 'react';
import { X, Trash2 } from 'lucide-react';

export default function NodeInspector({ selectedNode, onClose, onUpdateNode, onDeleteNode }) {
    if (!selectedNode) return null;

    const { id, data } = selectedNode;
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
        <aside className="absolute top-4 right-4 bottom-4 w-80 bg-slate-800/95 backdrop-blur border border-slate-700 rounded-xl p-4 shadow-2xl z-20 flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between border-b border-slate-700 pb-3 mb-4">
                <div>
                    <h3 className="font-semibold text-slate-100 text-sm">{data.label}</h3>
                    <span className="text-[10px] text-indigo-400 font-mono uppercase">{data.type}</span>
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
                        className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-500 font-mono"
                    />
                </div>

                {data.type === 'set_variable' && (
                    <>
                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Variable Name</label>
                            <input
                                type="text"
                                value={parameters.variable_name || ''}
                                onChange={(e) => handleParamChange('variable_name', e.target.value)}
                                placeholder="ej. custom_status"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>

                        <div>
                            <label className="block text-slate-300 mb-1 font-medium">Variable Value</label>
                            <input
                                type="text"
                                value={parameters.variable_value || ''}
                                onChange={(e) => handleParamChange('variable_value', e.target.value)}
                                placeholder="ej. active"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500 font-mono text-[11px]"
                            />
                        </div>
                    </>
                )}

                {data.type === 'start' && (
                    <div className="p-3 bg-slate-900/50 rounded-lg border border-slate-700/50 text-slate-400 text-[11px]">
                        Este es el nodo detonador. No requiere parámetros iniciales adicionales.
                    </div>
                )}
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
