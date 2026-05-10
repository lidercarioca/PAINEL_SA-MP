import React, { useEffect, useRef, useState, useCallback } from 'react';
import { Copy, Trash2, Eye, EyeOff, Pause, Play, Search, Filter, ArrowDown, Check } from 'lucide-react';

const ServerConsole = ({
  serverLogLines = [],
  serverLogLoading = false,
  serverLogError = '',
  activeServer = null
}) => {
  const [autoScroll, setAutoScroll] = useState(true);
  const [isPaused, setIsPaused] = useState(false);
  const [pausedLines, setPausedLines] = useState([]);
  const [localLogs, setLocalLogs] = useState([]);
  const [filteredLogs, setFilteredLogs] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [activeFilter, setActiveFilter] = useState('all');
  const [showScrollToBottom, setShowScrollToBottom] = useState(false);
  const [copyFeedback, setCopyFeedback] = useState(false);
  const consoleRef = useRef(null);
  const lastScrollTop = useRef(0);
  const isNearBottom = useRef(true);

  const engineType = activeServer?.engine || 'samp';
  const serverStatus = String(activeServer?.status || '').toLowerCase();
  const isServerOnline = serverStatus === 'online';
  const badgeText = isServerOnline ? 'LIVE' : 'OFFLINE';
  const badgeStatus = isServerOnline ? 'live' : 'offline';

  // Detectar se usuário está próximo do final
  const checkNearBottom = useCallback(() => {
    if (!consoleRef.current) return true;
    const { scrollTop, scrollHeight, clientHeight } = consoleRef.current;
    const threshold = 50; // pixels do final
    return scrollTop + clientHeight >= scrollHeight - threshold;
  }, []);

  // Handler de scroll inteligente
  const handleScroll = useCallback(() => {
    if (!consoleRef.current) return;

    const { scrollTop } = consoleRef.current;
    const wasNearBottom = isNearBottom.current;
    isNearBottom.current = checkNearBottom();

    // Se usuário rolou para cima, desativar auto-scroll
    if (scrollTop < lastScrollTop.current && wasNearBottom) {
      setAutoScroll(false);
    }

    // Se voltou para o final, reativar auto-scroll
    if (isNearBottom.current && !wasNearBottom) {
      setAutoScroll(true);
    }

    // Mostrar botão "Voltar ao final" se não estiver no final
    setShowScrollToBottom(!isNearBottom.current);

    lastScrollTop.current = scrollTop;
  }, [checkNearBottom]);

  // Scroll para o final
  const scrollToBottom = useCallback(() => {
    if (consoleRef.current) {
      consoleRef.current.scrollTop = consoleRef.current.scrollHeight;
      setAutoScroll(true);
      setShowScrollToBottom(false);
      isNearBottom.current = true;
    }
  }, []);

  // Toggle pause/resume
  const togglePause = useCallback(() => {
    setIsPaused(!isPaused);
    if (isPaused) {
      // Resumindo: adicionar linhas pausadas aos logs locais
      setLocalLogs(prev => [...prev, ...pausedLines]);
      setPausedLines([]);
    }
  }, [isPaused, pausedLines]);

  // Sincronizar logs locais com logs do servidor
  useEffect(() => {
    if (isPaused) {
      // Se pausado, adicionar às linhas pausadas
      const newLines = serverLogLines.slice(localLogs.length + pausedLines.length);
      setPausedLines(prev => [...prev, ...newLines]);
    } else {
      setLocalLogs(serverLogLines || []);
    }
  }, [serverLogLines, isPaused, localLogs.length, pausedLines.length]);

  // Auto-scroll para o final quando novos logs chegam
  useEffect(() => {
    if (autoScroll && !isPaused && consoleRef.current && isNearBottom.current) {
      scrollToBottom();
    }
  }, [localLogs, autoScroll, isPaused, scrollToBottom]);

  // Aplicar filtros e busca
  useEffect(() => {
    let filtered = localLogs;

    // Aplicar filtro de tipo
    if (activeFilter !== 'all') {
      filtered = filtered.filter(line => {
        const { level } = formatLogLine(line);
        return level === activeFilter;
      });
    }

    // Aplicar busca
    if (searchTerm.trim()) {
      const term = searchTerm.toLowerCase();
      filtered = filtered.filter(line => {
        const { content } = formatLogLine(line);
        return content.toLowerCase().includes(term);
      });
    }

    setFilteredLogs(filtered);
  }, [localLogs, activeFilter, searchTerm]);

  const formatLogLine = (line) => {
    const timestampMatch = line.match(/^\[[^\]]+\]/);
    const timestamp = timestampMatch ? timestampMatch[0] : '';
    const content = timestampMatch ? line.slice(timestamp.length).trim() : line;

    // Para FiveM, detectar cores especiais e tipos de evento
    if (engineType === 'fivem') {
      const normalized = content.toUpperCase();
      const isResource = /RESOURCE|STARTED RESOURCE|STARTING RESOURCE|STOPPED RESOURCE|LOADED RESOURCE|FAILED TO LOAD|ENSURE/i.test(content);
      const isPlayer = /PLAYER|CONNECT|DISCONNECT|JOINED|LEFT|PLAYER CONNECTED|PLAYER DISCONNECTED/i.test(content);
      const isCommand = /COMMAND|EXECUTED|REGISTERED|TXADMIN|CITIZEN-SERVER/i.test(content);
      const isError = /ERROR|FAILED|EXCEPTION|STACK TRACEBACK|SCRIPT ERROR|FATAL/i.test(content);
      const isWarning = /WARNING|WARN|DEPRECATED/i.test(content);

      const level = isError
        ? 'error'
        : isWarning
        ? 'warning'
        : isResource
        ? 'resource'
        : isPlayer || isCommand
        ? 'info'
        : 'default';

      return { timestamp, content, level, isFiveM: true };
    }

    // Para SA-MP, manter detectar nível do log baseado no conteúdo
    const level = content.includes('ERROR') || content.includes('FAIL')
      ? 'error'
      : content.includes('WARNING') || content.includes('WARN')
      ? 'warning'
      : content.includes('INFO')
      ? 'info'
      : content.includes('DEBUG')
      ? 'debug'
      : 'default';

    return { timestamp, content, level, isFiveM: false };
  };

  const clearLocalLogs = () => {
    setLocalLogs([]);
    setPausedLines([]);
    setFilteredLogs([]);
  };

  const copyLogsToClipboard = async () => {
    try {
      const logText = filteredLogs.slice(-100).map(line => {
        const { timestamp, content } = formatLogLine(line);
        return `${timestamp} ${content}`;
      }).join('\n');

      await navigator.clipboard.writeText(logText);
      setCopyFeedback(true);
      setTimeout(() => setCopyFeedback(false), 2000);
    } catch (error) {
      console.error('Erro ao copiar logs:', error);
    }
  };

  const toggleAutoScroll = () => {
    setAutoScroll(!autoScroll);
    if (!autoScroll) {
      scrollToBottom();
    }
  };

  const filterButtons = [
    { key: 'all', label: 'Todos', color: 'gray' },
    { key: 'error', label: 'Erros', color: 'red' },
    { key: 'warning', label: 'Avisos', color: 'yellow' },
    { key: 'resource', label: 'Resources', color: 'green' },
    { key: 'info', label: 'Info', color: 'blue' },
  ];

  const displayedLogs = filteredLogs.slice(-300); // Limitar a 300 linhas para performance

  return (
    <div className="server-console" data-engine={engineType}>
      <div className="console-header">
        <div className="console-header-left">
          <h3>
            {engineType === 'fivem' ? 'FXServer Terminal' : 'Console Terminal'}
          </h3>
          {activeServer && (
            <span className={`console-live-badge ${engineType === 'fivem' ? 'fivem-badge' : ''} ${badgeStatus}`}>
              <span className="live-dot"></span>
              {badgeText}
            </span>
          )}
        </div>
        <div className="console-header-actions">
          <button
            type="button"
            onClick={togglePause}
            className={`console-btn ${isPaused ? 'paused' : ''}`}
            title={isPaused ? 'Retomar console' : 'Pausar console'}
          >
            {isPaused ? <Play size={16} /> : <Pause size={16} />}
            {isPaused && pausedLines.length > 0 && (
              <span className="pause-count">{pausedLines.length}</span>
            )}
          </button>
          <button
            type="button"
            onClick={toggleAutoScroll}
            className={`console-btn ${autoScroll ? 'active' : ''}`}
            title={autoScroll ? 'Desativar auto-scroll' : 'Ativar auto-scroll'}
          >
            {autoScroll ? <Eye size={16} /> : <EyeOff size={16} />}
          </button>
          <button
            type="button"
            onClick={copyLogsToClipboard}
            className={`console-btn ${copyFeedback ? 'success' : ''}`}
            title="Copiar logs para clipboard"
            disabled={displayedLogs.length === 0}
          >
            {copyFeedback ? <Check size={16} /> : <Copy size={16} />}
          </button>
          <button
            type="button"
            onClick={clearLocalLogs}
            className="console-btn"
            title="Limpar visualização local"
            disabled={displayedLogs.length === 0}
          >
            <Trash2 size={16} />
          </button>
        </div>
      </div>

      <div className="console-filters">
        <div className="console-search">
          <Search size={16} />
          <input
            type="text"
            placeholder="Buscar no console..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="console-search-input"
          />
        </div>
        <div className="console-filter-buttons">
          {filterButtons.map(filter => (
            <button
              key={filter.key}
              onClick={() => setActiveFilter(filter.key)}
              className={`console-filter-btn ${activeFilter === filter.key ? 'active' : ''} filter-${filter.color}`}
              title={`Mostrar apenas ${filter.label.toLowerCase()}`}
            >
              {filter.label}
            </button>
          ))}
        </div>
      </div>

      <div className={`console-body ${engineType === 'fivem' ? 'console-fivem' : ''}`} ref={consoleRef} onScroll={handleScroll}>
        {showScrollToBottom && (
          <button
            type="button"
            onClick={scrollToBottom}
            className="console-scroll-to-bottom"
            title="Voltar ao final"
          >
            <ArrowDown size={16} />
            <span>Final</span>
          </button>
        )}

        {isPaused && pausedLines.length > 0 && (
          <div className="console-paused-notice">
            <Pause size={14} />
            <span>Console pausado - {pausedLines.length} novas linhas</span>
          </div>
        )}

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
            <p>{engineType === 'fivem' ? 'Conectando ao FXServer...' : 'Carregando logs do servidor...'}</p>
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
                <div key={index} className={`console-line console-${level} ${engineType === 'fivem' ? `fivem-${level}` : ''}`}>
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
            <p>{engineType === 'fivem' ? 'Aguardando saída do FXServer...' : 'Aguardando logs do servidor...'}</p>
            <span>Os logs aparecerão aqui quando houver atividade</span>
          </div>
        )}
      </div>
    </div>
  );
};

export default ServerConsole;