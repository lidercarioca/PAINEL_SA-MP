import React, { useEffect, useRef, useState } from 'react';
import { Copy, Trash2, Eye, EyeOff } from 'lucide-react';

const ServerConsole = ({
  serverLogLines = [],
  serverLogLoading = false,
  serverLogError = '',
  activeServer = null
}) => {
  const [autoScroll, setAutoScroll] = useState(true);
  const [localLogs, setLocalLogs] = useState([]);
  const consoleRef = useRef(null);
  const lastLogCountRef = useRef(0);

  // Sincronizar logs locais com logs do servidor
  useEffect(() => {
    if (serverLogLines.length > lastLogCountRef.current) {
      setLocalLogs(serverLogLines);
      lastLogCountRef.current = serverLogLines.length;
    }
  }, [serverLogLines]);

  // Auto-scroll para o final quando novos logs chegam
  useEffect(() => {
    if (autoScroll && consoleRef.current) {
      const scrollContainer = consoleRef.current;
      scrollContainer.scrollTop = scrollContainer.scrollHeight;
    }
  }, [localLogs, autoScroll]);

  const formatLogLine = (line) => {
    const timestampMatch = line.match(/^\[[^\]]+\]/);
    const timestamp = timestampMatch ? timestampMatch[0] : '';
    const content = timestampMatch ? line.slice(timestamp.length).trim() : line;

    // Detectar nível do log baseado no conteúdo
    const level = content.includes('ERROR') || content.includes('FAIL')
      ? 'error'
      : content.includes('WARNING') || content.includes('WARN')
      ? 'warning'
      : content.includes('INFO')
      ? 'info'
      : content.includes('DEBUG')
      ? 'debug'
      : 'default';

    return { timestamp, content, level };
  };

  const clearLocalLogs = () => {
    setLocalLogs([]);
    lastLogCountRef.current = 0;
  };

  const copyLogsToClipboard = async () => {
    try {
      const logText = localLogs.slice(-24).map(line => {
        const { timestamp, content } = formatLogLine(line);
        return `${timestamp} ${content}`;
      }).join('\n');

      await navigator.clipboard.writeText(logText);
      // Poderia adicionar um toast de sucesso aqui
    } catch (error) {
      console.error('Erro ao copiar logs:', error);
    }
  };

  const toggleAutoScroll = () => {
    setAutoScroll(!autoScroll);
  };

  const displayedLogs = localLogs.slice(-24);

  return (
    <div className="server-console">
      <div className="console-header">
        <div className="console-header-left">
          <h3>Console Terminal</h3>
          {activeServer && (
            <span className="console-live-badge">
              <span className="live-dot"></span>
              LIVE
            </span>
          )}
        </div>
        <div className="console-header-actions">
          <button
            type="button"
            onClick={toggleAutoScroll}
            className={`console-btn ${autoScroll ? 'active' : ''}`}
            title={autoScroll ? 'Desativar auto-scroll' : 'Ativar auto-scroll'}
          >
            {autoScroll ? <Eye /> : <EyeOff />}
          </button>
          <button
            type="button"
            onClick={copyLogsToClipboard}
            className="console-btn"
            title="Copiar logs para clipboard"
            disabled={displayedLogs.length === 0}
          >
            <Copy />
          </button>
          <button
            type="button"
            onClick={clearLocalLogs}
            className="console-btn"
            title="Limpar visualização local"
            disabled={displayedLogs.length === 0}
          >
            <Trash2 />
          </button>
        </div>
      </div>

      <div className="console-body" ref={consoleRef}>
        {serverLogLoading && displayedLogs.length === 0 ? (
          <div className="console-empty">
            <div className="console-empty-icon">
              <svg
                width="32"
                height="32"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
              >
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
              </svg>
            </div>
            <p>Carregando logs do servidor...</p>
            <span>Conectando ao terminal</span>
          </div>
        ) : serverLogError ? (
          <div className="console-empty error">
            <div className="console-empty-icon">
              <svg
                width="32"
                height="32"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
              >
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
              </svg>
            </div>
            <p>Erro ao carregar logs</p>
            <span>{serverLogError}</span>
          </div>
        ) : displayedLogs.length > 0 ? (
          <div className="console-lines">
            {displayedLogs.map((line, index) => {
              const { timestamp, content, level } = formatLogLine(line);
              return (
                <div key={index} className={`console-line console-${level}`}>
                  {timestamp && <span className="console-timestamp">{timestamp}</span>}
                  <span className="console-content">{content}</span>
                </div>
              );
            })}
          </div>
        ) : (
          <div className="console-empty">
            <div className="console-empty-icon">
              <svg
                width="32"
                height="32"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
              >
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
              </svg>
            </div>
            <p>Aguardando logs do servidor...</p>
            <span>Os logs aparecerão aqui quando houver atividade</span>
          </div>
        )}
      </div>
    </div>
  );
};

export default ServerConsole;