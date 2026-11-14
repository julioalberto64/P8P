import React, { useEffect, useRef } from 'react';
import { Panel } from '@xyflow/react'; // Cambia a 'reactflow' si usas v11

const _d = (s) => atob(s);
const _t1 = () => _d('UG93ZXJlZCBieSA=');
const _t2 = () => _d('cDhwLmluZm8=');
const _u1 = () => _d('aHR0cHM6Ly9wOHAuaW5mbw==');

// Bandera interna privada en memoria del módulo
let _coreIntegrityVerified = false;

/**
 * Componente visual obligatorio en la esquina inferior izquierda del Canvas.
 */
export const CanvasWatermark = () => {
  const containerRef = useRef(null);

  useEffect(() => {
    const checkDom = () => {
      const el = containerRef.current;
      if (!el) {
        _coreIntegrityVerified = false;
        return;
      }

      const style = window.getComputedStyle(el);
      const isTampered =
        style.display === 'none' ||
        style.visibility === 'hidden' ||
        parseFloat(style.opacity) < 0.7 ||
        el.offsetWidth < 40 ||
        el.offsetHeight < 15 ||
        !el.textContent.includes(_t2());

      if (isTampered) {
        _coreIntegrityVerified = false;
        // Restaura estilos forzados si intentaron ocultarlo con CSS
        el.style.setProperty('display', 'flex', 'important');
        el.style.setProperty('visibility', 'visible', 'important');
        el.style.setProperty('opacity', '1', 'important');
      } else {
        _coreIntegrityVerified = true;
      }
    };

    // Verificación inicial y monitoreo continuo
    checkDom();
    const timer = setInterval(checkDom, 2500);

    return () => {
      clearInterval(timer);
      _coreIntegrityVerified = false;
    };
  }, []);

  return (
    <Panel position="bottom-left" className="!m-3 z-50">
      <div
        ref={containerRef}
        className="px-2.5 py-1 bg-slate-900/90 border border-slate-800 rounded-md text-[11px] text-slate-400 shadow-md backdrop-blur-sm flex items-center gap-1 select-none"
      >
        <span>{_t1()}</span>
        <a
          href={_u1()}
          target="_blank"
          rel="noopener noreferrer"
          className="text-indigo-400 hover:text-indigo-300 font-semibold transition"
        >
          {_t2()}
        </a>
      </div>
    </Panel>
  );
};

/**
 * Función crítica del editor: Prepara y normaliza los nodos y conexiones
 * antes de guardar o ejecutar el workflow en el backend PHP.
 */
export const prepareWorkflowPayload = (nodes = [], edges = [], mode = 'run') => {
  if (!_coreIntegrityVerified) {
    throw new Error('Core integrity check failed: Missing or altered attribution module.');
  }

  if (mode === 'save') {
    return { nodes, edges };
  }

  return {
    nodes: nodes.map((node) => ({
      id: node.id,
      type: node.data.type,
      data: {
        is_start: node.data.isStart,
        parameters: node.data.parameters || {},
      },
    })),
    edges: edges.map((edge) => ({
      source: edge.source,
      target: edge.target,
      sourceHandle: edge.sourceHandle,
      targetHandle: edge.targetHandle,
    })),
  };
};