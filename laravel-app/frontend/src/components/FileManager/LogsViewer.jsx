import React, { useEffect, useState } from 'react';
import { fetchLogs } from '../../services/api';

const LOG_OPTIONS = [
  { value: 'server_log', label: 'server_log.txt' },
  { value: 'crashdetect', label: 'crashdetect' },
  { value: 'errors', label: 'erros' },
];

const LogsViewer = ({ serverId, live, onToggleLive }) => {
  const [lines, setLines] = useState([]);
  const [loading, setLoading] = useState(false);
  const [selectedFile, setSelectedFile] = useState('server_log');

  const loadLogs = async (fileName = selectedFile) => {
    if (!serverId) {
      setLines([]);
      return;
    }

    setLoading(true);
    try {
      const { data } = await fetchLogs(serverId, fileName);
      setLines(data.lines || []);
    } catch {
      setLines(['Falha ao carregar logs.']);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadLogs();
  }, [serverId, selectedFile]);

  useEffect(() => {
    if (!live || !serverId) return undefined;
    const interval = setInterval(() => loadLogs(selectedFile), 3000);
    return () => clearInterval(interval);
  }, [live, serverId, selectedFile]);

  return (
    <div className="logs-viewer">
      <div className="logs-header">
        <h3>Visualizador de logs</h3>
        <button className="secondary-button" onClick={() => loadLogs(selectedFile)} disabled={loading || !serverId}>
          Atualizar
        </button>
      </div>
      <div className="logs-actions">
        <label className="logs-file-select">
          Arquivo:
          <select
            value={selectedFile}
            onChange={(event) => setSelectedFile(event.target.value)}
            disabled={!serverId}
          >
            {LOG_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
        <label>
          <input type="checkbox" checked={live} onChange={onToggleLive} /> Atualização em tempo real
        </label>
      </div>
      <div className="logs-output">
        {loading ? (
          <p>Carregando logs…</p>
        ) : (
          <pre>{lines.join('\n') || 'Sem registros para exibir.'}</pre>
        )}
      </div>
    </div>
  );
};

export default LogsViewer;
