import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Copy, RefreshCw, Eye, EyeOff, Terminal, AlertTriangle } from 'lucide-react';
import { fetchLogs, fetchServers, sendRconCommand, getApiErrorMessage } from '../services/api';

const getStatusLabel = (status) => {
  const normalized = String(status || '').toLowerCase();
  switch (normalized) {
    case 'online':
      return 'ONLINE';
    case 'offline':
      return 'OFFLINE';
    case 'starting':
      return 'INICIANDO';
    case 'suspended':
      return 'SUSPENSO';
    default:
      return status || 'DESCONHECIDO';
  }
};

const Console = () => {
  const { serverId } = useParams();
  const navigate = useNavigate();
  const [server, setServer] = useState(null);
  const [serverLoading, setServerLoading] = useState(true);
  const [serverError, setServerError] = useState('');
  const [logs, setLogs] = useState([]);
  const [logsLoading, setLogsLoading] = useState(false);
  const [logsError, setLogsError] = useState('');
  const [command, setCommand] = useState('');
  const [commandFeedback, setCommandFeedback] = useState('');
  const [sendingCommand, setSendingCommand] = useState(false);
  const [autoScroll, setAutoScroll] = useState(true);
  const [viewCleared, setViewCleared] = useState(false);
  const [copied, setCopied] = useState(false);
  const logRef = useRef(null);

  const findServerById = (items) => items?.find((item) => String(item.id) === String(serverId));

  useEffect(() => {
    const loadServer = async () => {
      setServerLoading(true);
      setServerError('');
      try {
        const { data } = await fetchServers();
        const found = findServerById(data);
        if (found) {
          setServer(found);
        } else {
          setServer(null);
          setServerError('Servidor não encontrado.');
        }
      } catch (error) {
        setServer(null);
        setServerError(getApiErrorMessage(error) || 'Erro ao carregar informações do servidor.');
      } finally {
        setServerLoading(false);
      }
    };

    loadServer();
  }, [serverId]);

  const loadLogs = async () => {
    if (!server) {
      return;
    }

    setLogsLoading(true);
    setLogsError('');
    try {
      const { data } = await fetchLogs(server.id);
      setLogs(data.lines || []);
      setLogsError('');
      if (viewCleared) {
        setViewCleared(false);
      }
    } catch (error) {
      setLogsError(getApiErrorMessage(error) || 'Erro ao carregar logs.');
    } finally {
      setLogsLoading(false);
    }
  };

  useEffect(() => {
    if (!server) {
      return undefined;
    }

    loadLogs();
    const interval = setInterval(loadLogs, 5000);
    return () => clearInterval(interval);
  }, [server]);

  useEffect(() => {
    if (autoScroll && logRef.current) {
      logRef.current.scrollTop = logRef.current.scrollHeight;
    }
  }, [logs, autoScroll, viewCleared]);

  const formatLine = (line) => {
    const raw = String(line);
    const timestampMatch = raw.match(/^(\[[^\]]+\])/);
    const timestamp = timestampMatch ? timestampMatch[0] : '';
    const content = timestampMatch ? raw.slice(timestamp.length).trim() : raw;
    const normalized = raw.toUpperCase();

    let level = 'DEFAULT';
    if (normalized.includes('ERROR') || normalized.includes('FAIL') || normalized.includes('FATAL')) {
      level = 'ERROR';
    } else if (normalized.includes('WARNING') || normalized.includes('WARN')) {
      level = 'WARNING';
    } else if (normalized.includes('DEBUG')) {
      level = 'DEBUG';
    } else if (normalized.includes('INFO')) {
      level = 'INFO';
    }

    return { raw, timestamp, content, level };
  };

  const displayedLines = useMemo(() => {
    if (viewCleared) {
      return [];
    }
    return logs.slice(-1000).map(formatLine);
  }, [logs, viewCleared]);

  const handleCopy = async () => {
    if (displayedLines.length === 0) {
      return;
    }
    try {
      const text = displayedLines.map((line) => `${line.timestamp} ${line.content}`.trim()).join('\n');
      await navigator.clipboard.writeText(text);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1600);
    } catch (error) {
      console.error('Erro ao copiar logs:', error);
    }
  };

  const handleClear = () => {
    setViewCleared(true);
  };

  const handleSendCommand = async (event) => {
    event.preventDefault();
    setCommandFeedback('');
    if (!server) {
      setCommandFeedback('Nenhum servidor selecionado.');
      return;
    }
    if (!command.trim()) {
      setCommandFeedback('Digite um comando para enviar.');
      return;
    }

    setSendingCommand(true);
    try {
      await sendRconCommand(server.id, command.trim());
      setCommandFeedback('Comando enviado com sucesso.');
      setCommand('');
      loadLogs();
    } catch (error) {
      setCommandFeedback(getApiErrorMessage(error) || 'Erro ao enviar comando.');
    } finally {
      setSendingCommand(false);
    }
  };

  const statusLabel = getStatusLabel(server?.status);
  const serverAddress = server ? `${server.ip}:${server.port}` : '—';
  const engineLabel = server?.engine || server?.game_mode || server?.gamemode || server?.gamemode_name || '—';

  return (
    <section className="console-page">
      <div className="console-page-header">
        <div>
          <span className="console-page-title">Console do Servidor</span>
          <p className="console-page-subtitle">Acompanhe logs em tempo real e envie comandos RCON para o servidor selecionado.</p>
        </div>
        <button type="button" className="secondary-button console-back-btn" onClick={() => navigate('/dashboard')}>
          <ArrowLeft size={16} /> Voltar ao Dashboard
        </button>
      </div>

      <div className="console-info-row">
        <div className="console-info-card">
          <strong>Servidor</strong>
          <span>{server?.name || 'Carregando...'}</span>
        </div>
        <div className="console-info-card">
          <strong>Endereço</strong>
          <span>{serverAddress}</span>
        </div>
        <div className="console-info-card">
          <strong>Status</strong>
          <span className={`status-pill ${server?.status === 'online' ? 'online' : server?.status === 'suspended' ? 'suspended' : 'offline'}`}>
            {server ? statusLabel : serverLoading ? 'CARREGANDO' : 'N/A'}
          </span>
        </div>
        <div className="console-info-card">
          <strong>Engine</strong>
          <span>{engineLabel}</span>
        </div>
      </div>

      <div className="console-terminal-card">
        <div className="console-terminal-toolbar">
          <div className="console-terminal-actions">
            <button type="button" className="secondary-button" onClick={loadLogs} disabled={!server || logsLoading}>
              <RefreshCw size={16} /> Atualizar
            </button>
            <button type="button" className="secondary-button" onClick={handleCopy} disabled={displayedLines.length === 0}>
              <Copy size={16} /> {copied ? 'Copiado' : 'Copiar'}
            </button>
            <button type="button" className="secondary-button" onClick={handleClear} disabled={!server || displayedLines.length === 0}>
              <Terminal size={16} /> Limpar visualização
            </button>
            <button type="button" className={`secondary-button ${autoScroll ? 'active' : ''}`} onClick={() => setAutoScroll((prev) => !prev)}>
              {autoScroll ? <Eye size={16} /> : <EyeOff size={16} />} {autoScroll ? 'Auto-scroll ON' : 'Auto-scroll OFF'}
            </button>
          </div>
          {serverError && (
            <div className="console-terminal-status-message error">
              <AlertTriangle size={16} /> {serverError}
            </div>
          )}
        </div>

        <div className="console-body" ref={logRef}>
          {logsLoading && !displayedLines.length ? (
            <div className="console-empty">
              <div className="console-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                  <path d="M4 6h16M4 10h16M4 14h10" />
                </svg>
              </div>
              <p>Carregando logs...</p>
              <span>Aguarde enquanto buscamos as últimas entradas.</span>
            </div>
          ) : logsError && !displayedLines.length ? (
            <div className="console-empty error">
              <div className="console-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                  <path d="M12 8v4" />
                  <path d="M12 16h.01" />
                  <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                </svg>
              </div>
              <p>Erro ao carregar os logs</p>
              <span>{logsError}</span>
            </div>
          ) : viewCleared ? (
            <div className="console-empty">
              <div className="console-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                  <path d="M5 12h14" />
                </svg>
              </div>
              <p>Visualização limpa</p>
              <span>Novos logs aparecerão em breve.</span>
            </div>
          ) : displayedLines.length > 0 ? (
            <div className="console-lines">
              {displayedLines.map((line, index) => (
                <div key={`${index}-${line.raw}`} className={`console-line console-${line.level.toLowerCase()}`}>
                  {line.timestamp && <span className="console-timestamp">{line.timestamp}</span>}
                  <span className="console-content">{line.content}</span>
                </div>
              ))}
            </div>
          ) : (
            <div className="console-empty">
              <div className="console-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                  <path d="M4 6h16M4 18h16" />
                  <path d="M4 12h10" />
                </svg>
              </div>
              <p>Nenhum log disponível</p>
              <span>Certifique-se de que o servidor está ativo ou atualize manualmente.</span>
            </div>
          )}
        </div>

        <form className="console-command-row" onSubmit={handleSendCommand}>
          <input
            className="console-command-input"
            type="text"
            placeholder="Digite um comando RCON e pressione Enter"
            value={command}
            onChange={(event) => setCommand(event.target.value)}
            disabled={!server}
          />
          <button type="submit" className="primary-button" disabled={!server || sendingCommand}>
            Enviar
          </button>
        </form>
        {commandFeedback && <p className="console-command-feedback">{commandFeedback}</p>}
      </div>
    </section>
  );
};

export default Console;
