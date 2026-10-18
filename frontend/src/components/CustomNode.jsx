import React from 'react';
import { Handle, Position } from '@xyflow/react';
import { Globe, Play, Settings, Sliders } from 'lucide-react';
const icons = {
    start: Play,
    http_request: Globe,
    set_variable: Sliders,
    default: Settings
};

export default function CustomNode({ data, selected }) {
    const IconComponent = icons[data.type] || icons.default;

    return (
        <div className={`px-4 py-3 shadow-lg rounded-xl bg-slate-800 border-2 min-w-[180px] transition-all ${
            selected ? 'border-indigo-500 ring-2 ring-indigo-500/30' : 'border-slate-700'
        }`}>
            {/* Handle de entrada (Input) */}
            {!data.isStart && (
                <Handle
                    type="target"
                    position={Position.Left}
                    className="!w-3 !h-3 !bg-indigo-400 !border-2 !border-slate-800"
                />
            )}

            <div className="flex items-center gap-3">
                <div className={`p-2 rounded-lg ${data.isStart ? 'bg-emerald-500/20 text-emerald-400' : 'bg-indigo-500/20 text-indigo-400'}`}>
                    <IconComponent className="w-5 h-5" />
                </div>
                <div>
                    <div className="text-xs text-slate-400 font-medium uppercase tracking-wider">
                        {data.type || 'Node'}
                    </div>
                    <div className="text-sm font-semibold text-slate-100">
                        {data.label}
                    </div>
                </div>
            </div>

            {/* Handle de salida (Output) */}
            <Handle
                type="source"
                position={Position.Right}
                className="!w-3 !h-3 !bg-indigo-400 !border-2 !border-slate-800"
            />
        </div>
    );
}
