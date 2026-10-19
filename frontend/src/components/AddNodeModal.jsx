import React, { useState, useEffect } from 'react';
import * as LucideIcons from 'lucide-react';
import { X, Box, Sparkles, ShieldCheck, Layers } from 'lucide-react';

export default function AddNodeModal({ isOpen, onClose, onAddNode }) {
    const [nodes, setNodes] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        if (isOpen) {
            fetch('http://localhost:8000/nodes.php')
                .then((res) => res.json())
                .then((data) => {
                    if (data.success) setNodes(data.nodes);
                })
                .catch((err) => console.error('Error al cargar nodos dinámicos:', err))
                .finally(() => setLoading(false));
        }
    }, [isOpen]);

    if (!isOpen) return null;

    // Renderiza el ícono dinámico devuelto por PHP o Box por defecto
    const renderIcon = (iconName) => {
        if (!iconName) return <Box className="w-5 h-5" />;
        const IconComponent = LucideIcons[iconName] || LucideIcons[iconName.charAt(0).toUpperCase() + iconName.slice(1)];
        return IconComponent ? <IconComponent className="w-5 h-5" /> : <Box className="w-5 h-5" />;
    };

    // Renderiza el Badge correspondiente a la categoría
    const renderCategoryBadge = (category = 'Core') => {
        const cat = category.toUpperCase();
        if (cat === 'PREMIUM') {
            return (
                <span className="text-[9px] bg-amber-500/20 text-amber-300 border border-amber-500/30 px-1.5 py-0.5 rounded font-bold flex items-center gap-1">
          <Sparkles className="w-2.5 h-2.5" /> PREMIUM
        </span>
            );
        }
        if (cat === 'COMMUNITY') {
            return (
                <span className="text-[9px] bg-sky-500/20 text-sky-300 border border-sky-500/30 px-1.5 py-0.5 rounded font-bold">
          COMMUNITY
        </span>
            );
        }
        return (
            <span className="text-[9px] bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 px-1.5 py-0.5 rounded font-bold">
        CORE
      </span>
        );
    };

    return (
        <div className="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-5 shadow-2xl flex flex-col max-h-[80vh]">
                <div className="flex items-center justify-between border-b border-slate-700 pb-3 mb-4">
                    <h3 className="font-semibold text-slate-100">Agregar Nodo o Conector</h3>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-700">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {loading ? (
                    <div className="text-center py-8 text-xs text-slate-400">Descubriendo nodos instalados...</div>
                ) : (
                    <div className="space-y-2 max-h-96 overflow-y-auto pr-1">
                        {nodes.map((node) => (
                            <button
                                key={node.type}
                                onClick={() => {
                                    onAddNode({
                                        type: node.type,
                                        label: node.name,
                                        isStart: node.is_start || false
                                    });
                                    onClose();
                                }}
                                className="w-full flex items-start gap-3 p-3 rounded-lg bg-slate-900/60 hover:bg-slate-700/60 border border-slate-700/50 transition text-left group"
                            >
                                <div className="p-2 rounded-lg bg-indigo-500/20 text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition">
                                    {renderIcon(node.icon)}
                                </div>
                                <div className="flex-1">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-semibold text-slate-200">{node.name}</span>
                                        {renderCategoryBadge(node.category)}
                                    </div>
                                    <div className="text-[11px] text-slate-400 mt-0.5">
                                        {node.description || 'Sin descripción'}
                                    </div>
                                </div>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
