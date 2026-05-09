import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Copy, RefreshCw, Search, Eye, EyeOff, Server, AlertTriangle, Info, Square, Circle, Trash2 } from 'lucide-react';
import { fetchLogs, fetchServers, getApiErrorMessage } from '../services/api';

const LOG_LEVELS = ['ALL', 'INFO', 'WARNING', 'ERROR', 'DEBUG'];

const Logs = () => {
  const [servers, setServers] = useState([]);
  const [selectedServer, setSelectedServer] = useState(null);
  const [lines, setLines] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [levelFilter, setLevelFilter] = useState('ALL');
  const [autoScroll, setAutoScroll] = useState(true);
  const [localClear, setLocalClear] = useState(false);
  const [copied, setCopied] = useState(false);
  const logContainerRef = useRef(null);

  const loadServers = async () => {
    try {
      const { data } = await fetchServers();
      setServers(data || []);
      setSelectedServer((current) => {
        if (current && data?.find((server) => server.id === current.id)) {
          return current;
        }
        return data?.[0] || null;
      });
    } catch (err) {
      const message = getApiErrorMessage(err);
      setError(message || 'Não foi possível carregar os servidores.');
    }
  };

  const loadServerLogs = async (server) => {
    if (!server) {
      setLines([]);
      setError('Nenhum servidor selecionado.');
      setLoading(false);
      return;
    }

    setLoading(true);
    setError('');
    setLocalClear(false);

    try {
      const { data } = await fetchLogs(server.id);
      setLines(data.lines || []);
    } catch (err) {
      const message = getApiErrorMessage(err);
      setError(message || 'Erro ao carregar logs.');
      setLines([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadServers();
  }, []);

  useEffect(() => {
    if (selectedServer) {
      loadServerLogs(selectedServer);
    } else {
      setLines([]);
      setError('Nenhum servidor selecionado.');
      setLoading(false);
    }
  }, [selectedServer]);

  const normalizeLine = (line) => {
    return line.toString();
  };

  const detectLevel = (line) => {
    const normalized = line.toUpperCase();
    if (normalized.includes('ERROR') || normalized.includes('FAIL') || normalized.includes('FATAL')) {
      return 'ERROR';
    }
    if (normalized.includes('WARNING') || normalized.includes('WARN')) {
      return 'WARNING';
    }
    if (normalized.includes('DEBUG')) {
      return 'DEBUG';
    }
    if (normalized.includes('INFO')) {
      return 'INFO';
    }
    return 'DEFAULT';
  };

  const formatLine = (line) => {
    const raw = normalizeLine(line);
    const timestampMatch = raw.match(/^(\[[^\]]+\])/);
    const timestamp = timestampMatch ? timestampMatch[0] : '';
    const content = timestampMatch ? raw.slice(timestamp.length).trim() : raw;
    return {
      raw,
      timestamp,
      content,
      level: detectLevel(raw),
    };
  };

  const filteredLines = useMemo(() => {
    if (localClear) {
      return [];
    }

    const normalizedSearch = search.trim().toLowerCase();
    return lines
      .map(formatLine)
      .filter((line) => {
        if (levelFilter !== 'ALL' && line.level !== levelFilter) {
          return false;
        }
        if (normalizedSearch && !line.raw.toLowerCase().includes(normalizedSearch)) {
          return false;
        }
        return true;
      });
  }, [lines, search, levelFilter, localClear]);

  const displayedLines = useMemo(() => filteredLines.slice(-700), [filteredLines]);
  const visibleCount = displayedLines.length;
  const totalFilteredCount = filteredLines.length;

  useEffect(() => {
    if (autoScroll && logContainerRef.current) {
      const el = logContainerRef.current;
      el.scrollTop = el.scrollHeight;
    }
  }, [displayedLines, autoScroll]);

  const handleCopy = async () => {
    if (visibleCount === 0) return;

    try {
      const text = displayedLines.map((line) => `${line.timestamp} ${line.content}`.trim()).join('\n');
      await navigator.clipboard.writeText(text);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch (err) {
      console.error('Erro ao copiar logs:', err);
    }
  };

  const handleClear = () => {
    setLocalClear(true);
    setSearch('');
    setLevelFilter('ALL');
  };

  const handleRefresh = () => {
    if (selectedServer) {
      loadServerLogs(selectedServer);
    }
  };

  const serverStatus = selectedServer?.status?.toLowerCase() || 'offline';

  return (
    <section className="logs-page">
      <div className="logs-header">
        <div>
          <span className="logs-title">Logs</span>
          <p className="logs-subtitle">Visualize os logs recentes dos servidores.</p>
        </div>
        {selectedServer && (
          <div className={`logs-badge status-${serverStatus}`}>
            <Server size={16} />
            <div>
              <strong>{selectedServer.name}</strong>
              <span>{selectedServer.status?.toUpperCase() || 'OFFLINE'}</span>
            </div>
          </div>
        )}
      </div>

      <div className="logs-card">
        <div className="logs-card-top">
          <div className="logs-controls-row">
            <label className="logs-select-label">
              Servidor
              <select
                value={selectedServer?.id || ''}
                onChange={(event) => {
                  const server = servers.find((item) => item.id.toString() === event.target.value.toString());
                  setSelectedServer(server || null);
                }}
              >
                <option value="">Selecione um servidor</option>
                {servers.map((server) => (
                  <option key={server.id} value={server.id}>
                    {server.name} · {server.status?.toUpperCase() || 'OFFLINE'}
                  </option>
                ))}
              </select>
            </label>

            <label className="logs-search-field">
              <Search size={16} />
              <input
                type="search"
                placeholder="Buscar logs..."
                value={search}
                onChange={(event) => setSearch(event.target.value)}
              />
            </label>

            <label className="logs-select-label">
              Nível
              <select value={levelFilter} onChange={(event) => setLevelFilter(event.target.value)}>
                {LOG_LEVELS.map((level) => (
                  <option key={level} value={level}>
                    {level}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="logs-actions-row">
            <button type="button" className="secondary-button" onClick={handleRefresh} disabled={!selectedServer || loading}>
              <RefreshCw size={16} /> Atualizar
            </button>
            <button type="button" className="secondary-button" onClick={handleCopy} disabled={visibleCount === 0}>
              <Copy size={16} /> Copiar logs visíveis
            </button>
            <button type="button" className="secondary-button" onClick={handleClear} disabled={lines.length === 0 && !localClear}>
              <Trash2 size={16} /> Limpar visualização
            </button>
            <button
              type="button"
              className={`secondary-button ${autoScroll ? 'active' : ''}`}
              onClick={() => setAutoScroll((prev) => !prev)}
              title={autoScroll ? 'Auto-scroll ON' : 'Auto-scroll OFF'}
            >
              {autoScroll ? <Eye size={16} /> : <EyeOff size={16} />} {autoScroll ? 'Auto-scroll ON' : 'Auto-scroll OFF'}
            </button>
          </div>
        </div>

        <div className="logs-state-row">
          {loading && (
            <div className="logs-state-message">
              <Info size={16} /> Carregando logs...
            </div>
          )}
          {!loading && error && (
            <div className="logs-state-message error">
              <AlertTriangle size={16} /> {error}
            </div>
          )}
          {!loading && !error && !selectedServer && (
            <div className="logs-state-message">
              <Info size={16} /> Nenhum servidor selecionado.
            </div>
          )}
          {!loading && !error && selectedServer && !localClear && lines.length === 0 && (
            <div className="logs-state-message">
              <Info size={16} /> Nenhum log disponível.
            </div>
          )}
          {!loading && !error && localClear && (
            <div className="logs-state-message">
              <Info size={16} /> Visualização local limpa. Atualize para recarregar.
            </div>
          )}
        </div>

        <div className="logs-output-wrapper">
          <div className="logs-output" ref={logContainerRef}>
            {displayedLines.map((line, index) => (
              <div key={`${index}-${line.raw}`} className={`log-line log-${line.level.toLowerCase()}`}>
                {line.timestamp && <span className="log-timestamp">{line.timestamp}</span>}
                <span className="log-text">{line.content}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="logs-footer">
          <div className="logs-summary">
            <span>{selectedServer ? `${visibleCount} linhas visíveis` : '0 linhas visíveis'}</span>
            <span>{selectedServer ? `Total carregado: ${lines.length}` : 'Total carregado: 0'}</span>
            {displayedLines.length < filteredLines.length && <span>Mostrando últimos 700 linhas</span>}
          </div>
          <div className="logs-hint">
            <Circle size={12} className={`status-dot status-${serverStatus}`} />
            <span>{selectedServer ? `${selectedServer.name} · ${selectedServer.status?.toUpperCase() || 'OFFLINE'}` : 'Selecione um servidor'}</span>
          </div>
        </div>
      </div>
    </section>
  );
};

export default Logs;
