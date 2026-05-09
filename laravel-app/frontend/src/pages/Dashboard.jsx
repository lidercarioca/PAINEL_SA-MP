import React, { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  fetchServers,
  startServer,
  stopServer,
  restartServer,
  suspendServer,
  sendRconCommand,
  fetchLogs,
  fetchPlayers,
  fetchServerStats,
  createServer,
  updateServer,
  deleteServer,
  logout,
  getResources,
  fetchUsers,
  fetchPlans,
  getApiErrorMessage,
} from '../services/api';
import ServerCard from '../components/ServerCard';
import LoadingButton from '../components/LoadingButton';

const getStatusLabel = (status, ping = null) => {
  const normalized = String(status || '').toLowerCase();
  switch (normalized) {
    case 'online':
      return ping ? `🟢 Online (${ping} ms)` : '🟢 Online (ping desconhecido)';
    case 'offline':
      return '🔴 Offline';
    case 'starting':
      return '🟡 Iniciando';
    case 'suspended':
      return '🔴 Offline';
    default:
      return normalized ? `🟡 ${status}` : '🔴 Offline';
  }
};

const Dashboard = () => {
  const [servers, setServers] = useState([]);
  const [message, setMessage] = useState(null);
  const [resources, setResources] = useState(null);
  const [user, setUser] = useState(null);
  const [clients, setClients] = useState([]);
  const [plans, setPlans] = useState([]);
  const [players, setPlayers] = useState([]);
  const [playersLoading, setPlayersLoading] = useState(false);
  const [playersError, setPlayersError] = useState('');
  const [serverFps, setServerFps] = useState(null);
  const [serverCpu, setServerCpu] = useState(null);
  const [serverMemory, setServerMemory] = useState(null);
  const [serverDisk, setServerDisk] = useState(null);
  const [serverStatsLoading, setServerStatsLoading] = useState(false);
  const [newServer, setNewServer] = useState({
    name: '',
    ip: '',
    port: 7777,
    password: '',
    type: 'local',
    folder: '',
    owner_id: null,
    plan_id: null,
    game_mode: '',
    limit_ram: '',
    limit_slots: '',
    auto_restart_interval_hours: '',
    auto_restart_on_crash: false,
    auto_restart_on_offline: false,
  });
  const [editingServerId, setEditingServerId] = useState(null);
  const [serverLogLines, setServerLogLines] = useState([]);
  const [serverLogError, setServerLogError] = useState('');
  const [serverLogLoading, setServerLogLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState({ serverId: null, action: null });
  const serverLogRef = useRef(null);
  const serverLogLinesRef = useRef([]);
  const firstLogLoadRef = useRef(true);
  const editSectionRef = useRef(null);
  const navigate = useNavigate();

  const loadServers = async () => {
    try {
      const { data } = await fetchServers();
      setServers(data);
    } catch (error) {
      const message = getApiErrorMessage(error);
      if (error.response?.status === 401) {
        localStorage.removeItem('auth_token');
        navigate('/login');
        return;
      }
      setMessage(message || 'Não foi possível carregar os servidores.');
    }
  };

  useEffect(() => {
    const token = localStorage.getItem('auth_token');
    const authUser = localStorage.getItem('auth_user');
    if (!token) {
      navigate('/login');
      return;
    }

    if (authUser) {
      try {
        const parsed = JSON.parse(authUser);
        setUser(parsed);
        if (parsed.role === 'admin') {
          loadClients();
          loadPlans();
        }
      } catch (err) {
        console.warn('Falha ao ler usuário autenticado');
      }
    }

    loadServers();
    loadResources();
  }, [navigate]);

  useEffect(() => {
    const interval = setInterval(() => {
      loadServers();
    }, 5000);

    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    const interval = setInterval(() => {
      loadResources();
    }, 5000);

    return () => clearInterval(interval);
  }, []);

  const loadResources = async () => {
    try {
      const { data } = await getResources();
      setResources(data);
    } catch (error) {
      console.warn('Erro ao obter recursos:', error);
    }
  };

  const formatBytes = (bytes) => {
    if (bytes == null || isNaN(bytes)) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Number(bytes);
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
      value /= 1024;
      unitIndex += 1;
    }

    return `${value.toFixed(1)} ${units[unitIndex]}`;
  };

  const folderInputRef = useRef(null);

  const handleFolderPick = async () => {
    // Tentar usar a nova API File System Access (mais moderna e confiável)
    if (window.showDirectoryPicker) {
      try {
        const dirHandle = await window.showDirectoryPicker();
        
        // Obter o caminho completo através da propriedade nativa
        // Em Electron/Tauri, isso fornece o caminho real
        // Em navegadores, temos acesso limitado mas podemos armazenar a referência
        const folderPath = dirHandle.name;
        
        console.log('Pasta selecionada (API File System):', folderPath);
        setNewServer((prev) => ({ ...prev, folder: folderPath }));
        return;
      } catch (error) {
        console.log('Erro ou cancelado na API File System:', error);
        // Fallback para o método tradicional
      }
    }

    // Fallback: usar input file tradicional
    folderInputRef.current?.click();
  };

  const handleFolderSelect = (event) => {
    const files = event.target.files;
    if (!files || files.length === 0) {
      return;
    }

    let selectedFolder = '';
    const file = files[0];
    
    // Tentar obter o caminho completo (funciona em Electron/aplicações desktop)
    if (file.path) {
      selectedFolder = file.path.replace(/\\/g, '/');
      // Remover o nome do arquivo para obter apenas a pasta
      if (selectedFolder.includes('/')) {
        selectedFolder = selectedFolder.substring(0, selectedFolder.lastIndexOf('/'));
      }
    } else if (file.webkitRelativePath) {
      selectedFolder = file.webkitRelativePath.replace(/\\/g, '/');
      // Remover o arquivo final, deixando só a pasta
      if (selectedFolder.includes('/')) {
        selectedFolder = selectedFolder.substring(0, selectedFolder.lastIndexOf('/'));
      }
    } else {
      selectedFolder = file.name;
    }

    console.log('Caminho final:', selectedFolder);
    setNewServer((prev) => ({ ...prev, folder: selectedFolder }));
  };

  const loadClients = async () => {
    try {
      const { data } = await fetchUsers();
      setClients(data.filter((item) => item.role === 'client'));
    } catch (error) {
      console.warn('Falha ao carregar clientes:', error);
    }
  };

  const loadPlans = async () => {
    try {
      const { data } = await fetchPlans();
      setPlans(data);
    } catch (error) {
      console.warn('Falha ao carregar planos:', error);
    }
  };

  const loadPlayers = async (serverId) => {
    if (!serverId) {
      setPlayers([]);
      setPlayersError('');
      setPlayersLoading(false);
      return;
    }

    setPlayersLoading(true);
    try {
      const { data } = await fetchPlayers(serverId);
      setPlayers(data.players || []);
      setPlayersError('');
    } catch (error) {
      const message = getApiErrorMessage(error);
      setPlayers([]);
      setPlayersError(message || 'Não foi possível carregar jogadores online.');
      console.warn('Erro ao carregar jogadores:', error);
    } finally {
      setPlayersLoading(false);
    }
  };

  const loadServerStats = async (serverId) => {
    if (!serverId) {
      setServerFps(null);
      setServerCpu(null);
      setServerMemory(null);
      setServerDisk(null);
      setServerStatsLoading(false);
      return;
    }

    setServerStatsLoading(true);
    try {
      const { data } = await fetchServerStats(serverId);
      setServerFps(data.fps ?? null);
      setServerCpu(data.cpu ?? null);
      setServerMemory(data.memory ?? null);
      setServerDisk(data.disk ?? null);
    } catch (error) {
      console.warn('Erro ao carregar estatísticas do servidor:', error);
      setServerFps(null);
      setServerCpu(null);
      setServerMemory(null);
      setServerDisk(null);
    } finally {
      setServerStatsLoading(false);
    }
  };

  const handleAction = async (serverId, action) => {
    setActionLoading({ serverId, action });
    try {
      if (action === 'start') await startServer(serverId);
      if (action === 'stop') await stopServer(serverId);
      if (action === 'restart') await restartServer(serverId);
      if (action === 'suspend') await suspendServer(serverId);
      setMessage(`Comando ${action} enviado.`);
      await loadServers();
    } catch (error) {
      const message = getApiErrorMessage(error);
      if (error.response?.status === 401) {
        localStorage.removeItem('auth_token');
        navigate('/login');
        return;
      }
      setMessage(message || 'Erro ao executar ação.');
    } finally {
      setActionLoading({ serverId: null, action: null });
    }
  };

  const isActionLoading = (serverId, action) => actionLoading.serverId === serverId && actionLoading.action === action;

  const handleRcon = async (serverId, command) => {
    try {
      await sendRconCommand(serverId, command);
      setMessage('Comando RCON enviado com sucesso.');
      loadServers();
    } catch (error) {
      const message = getApiErrorMessage(error);
      if (error.response?.status === 401) {
        localStorage.removeItem('auth_token');
        navigate('/login');
        return;
      }
      setMessage(message || 'Erro ao enviar comando RCON.');
    }
  };

  const handleSendCommand = () => {
    if (!activeServer) return;
    const command = window.prompt('Digite o comando RCON para enviar:');
    if (!command || !command.trim()) return;
    handleRcon(activeServer.id, command.trim());
  };

  const handleBackup = async () => {
    // Backup ainda não implementado
  };

  const handleDelete = async (serverId) => {
    if (!window.confirm('Tem certeza que deseja excluir este servidor? Essa ação não pode ser desfeita.')) {
      return;
    }

    try {
      await deleteServer(serverId);
      setMessage('Servidor removido.');
      loadServers();
    } catch (error) {
      const message = getApiErrorMessage(error);
      if (error.response?.status === 401) {
        localStorage.removeItem('auth_token');
        navigate('/login');
        return;
      }
      setMessage(message || 'Erro ao remover servidor.');
    }
  };

  const handleEdit = (server) => {
    setEditingServerId(server.id);
    setNewServer({
      name: server.name || '',
      ip: server.ip || '',
      port: server.port || 7777,
      password: server.password || '',
      type: server.type || 'local',
      folder: server.folder || '',
      owner_id: server.owner_id || null,
      plan_id: server.plan_id || null,
      game_mode: server.game_mode || server.gamemode || '',
      limit_ram: server.limit_ram ?? '',
      limit_slots: server.limit_slots ?? '',
      auto_restart_interval_hours: server.auto_restart_interval_hours ?? '',
      auto_restart_on_crash: server.auto_restart_on_crash ?? false,
      auto_restart_on_offline: server.auto_restart_on_offline ?? false,
    });
    setTimeout(() => {
      editSectionRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 0);
  };

  const handleCancelEdit = () => {
    setEditingServerId(null);
    setNewServer({
      name: '',
      ip: '',
      port: 7777,
      password: '',
      type: 'local',
      folder: '',
      owner_id: null,
      plan_id: null,
      game_mode: '',
      limit_ram: '',
      limit_slots: '',
      auto_restart_interval_hours: '',
      auto_restart_on_crash: false,
      auto_restart_on_offline: false,
    });
  };

  const handleCreate = async (event) => {
    event.preventDefault();

    try {
      if (editingServerId) {
        await updateServer({
          server_id: editingServerId,
          ...newServer,
          port: Number(newServer.port),
          game_mode: newServer.game_mode ? String(newServer.game_mode) : null,
          plan_id: newServer.plan_id ? Number(newServer.plan_id) : null,
          limit_ram: newServer.limit_ram ? Number(newServer.limit_ram) : null,
          limit_slots: newServer.limit_slots ? Number(newServer.limit_slots) : null,
          owner_id: newServer.owner_id ? Number(newServer.owner_id) : null,
          auto_restart_interval_hours: newServer.auto_restart_interval_hours ? Number(newServer.auto_restart_interval_hours) : null,
          auto_restart_on_crash: Boolean(newServer.auto_restart_on_crash),
          auto_restart_on_offline: Boolean(newServer.auto_restart_on_offline),
        });
        setMessage('Servidor atualizado com sucesso.');
        setEditingServerId(null);
      } else {
        await createServer({
          ...newServer,
          game_mode: newServer.game_mode ? String(newServer.game_mode) : null,
          auto_restart_interval_hours: newServer.auto_restart_interval_hours ? Number(newServer.auto_restart_interval_hours) : null,
          auto_restart_on_crash: Boolean(newServer.auto_restart_on_crash),
          auto_restart_on_offline: Boolean(newServer.auto_restart_on_offline),
        });
        setMessage('Servidor criado com sucesso.');
      }

      setNewServer({
        name: '',
        ip: '',
        port: 7777,
        password: '',
        type: 'local',
        folder: '',
        owner_id: null,
        plan_id: null,
        game_mode: '',
        limit_ram: '',
        limit_slots: '',
        auto_restart_interval_hours: '',
        auto_restart_on_crash: false,
        auto_restart_on_offline: false,
      });
      loadServers();
    } catch (error) {
      const message = getApiErrorMessage(error);
      if (error.response?.status === 401) {
        localStorage.removeItem('auth_token');
        navigate('/login');
        return;
      }
      setMessage(message || (editingServerId ? 'Erro ao atualizar servidor.' : 'Erro ao criar servidor.'));
    }
  };

  const handleLogout = async () => {
    try {
      await logout();
    } catch (error) {
      console.warn('Logout falhou:', error);
    }
    localStorage.removeItem('auth_token');
    localStorage.removeItem('auth_user');
    navigate('/login');
  };

  const activeServer = servers[0] || null;
  const serverPlan = activeServer
    ? plans.find((plan) => plan.id === activeServer.plan_id)?.name || activeServer.plan_name || 'Sem plano'
    : 'Sem plano';
  const serverGamemode = activeServer
    ? activeServer.game_mode || activeServer.gamemode || activeServer.gamemode_name || 'Sem gamemode'
    : 'Sem gamemode';

  useEffect(() => {
    if (!activeServer) {
      setServerLogLines([]);
      serverLogLinesRef.current = [];
      firstLogLoadRef.current = true;
      return;
    }

    const loadServerLogs = async (serverId) => {
      if (!serverId) {
        setServerLogError('Nenhum servidor selecionado para exibir o log.');
        setServerLogLines([]);
        serverLogLinesRef.current = [];
        setServerLogLoading(false);
        return;
      }

      if (firstLogLoadRef.current) {
        setServerLogLoading(true);
      }

      try {
        const { data } = await fetchLogs(serverId);
        const incomingLines = data.lines || [];
        const previousLines = serverLogLinesRef.current;
        let nextLines = incomingLines;

        if (previousLines.length > 0 && incomingLines.length >= previousLines.length) {
          const hasSamePrefix = previousLines.every((line, index) => line === incomingLines[index]);
          if (hasSamePrefix) {
            nextLines = [...previousLines, ...incomingLines.slice(previousLines.length)];
          }
        }

        setServerLogLines(nextLines);
        serverLogLinesRef.current = nextLines;
        setServerLogError('');
      } catch (error) {
        const message = getApiErrorMessage(error);
        setServerLogError(message || 'Não foi possível carregar o console do servidor.');
        setServerLogLines([]);
        serverLogLinesRef.current = [];
      } finally {
        setServerLogLoading(false);
        firstLogLoadRef.current = false;
      }
    };

    loadServerLogs(activeServer.id);
    const logInterval = setInterval(() => loadServerLogs(activeServer.id), 5000);
    return () => clearInterval(logInterval);
  }, [activeServer, plans]);

  useEffect(() => {
    if (activeServer) {
      loadPlayers(activeServer.id);
      const playersInterval = setInterval(() => loadPlayers(activeServer.id), 5000);
      return () => clearInterval(playersInterval);
    }
    setPlayers([]);
    setPlayersError('');
    return undefined;
  }, [activeServer]);

  useEffect(() => {
    if (activeServer) {
      loadServerStats(activeServer.id);
      const statsInterval = setInterval(() => loadServerStats(activeServer.id), 5000);
      return () => clearInterval(statsInterval);
    }
    setServerFps(null);
    setServerCpu(null);
    setServerMemory(null);
    setServerDisk(null);
    return undefined;
  }, [activeServer]);

  useEffect(() => {
    if (serverLogRef.current) {
      serverLogRef.current.scrollTop = serverLogRef.current.scrollHeight;
    }
  }, [serverLogLines]);

  const serverStatus = activeServer?.status || 'offline';
  const serverStatusLabel = getStatusLabel(serverStatus, activeServer?.ping ?? null);
  const serverOnline = serverStatus === 'online';
  const serverSuspended = serverStatus === 'suspended';
  const serverAddress = activeServer ? `${activeServer.ip}:${activeServer.port}` : '';
  const serverPlayers = activeServer ? players.length : 0;
  const serverOwnerName = activeServer
    ? clients.find((client) => client.id === activeServer.owner_id)?.name || activeServer.owner_name || ''
    : '';

  const cpuPercent = serverCpu?.percent ?? resources?.cpu?.percent ?? resources?.cpu?.load_percentage ?? null;
  const memoryUsed = serverMemory?.used ?? resources?.memory?.used ?? null;
  const memoryTotal = serverMemory?.total ?? resources?.memory?.total ?? null;
  const memoryPercent = serverMemory?.percent ?? resources?.memory?.percent ?? null;
  const diskUsed = serverDisk?.used ?? resources?.disk?.used ?? null;
  const diskTotal = serverDisk?.total ?? resources?.disk?.total ?? null;
  const diskPercent = serverDisk?.percent ?? resources?.disk?.percent ?? null;

  const cpuDisplay = cpuPercent != null ? `${cpuPercent}%` : '—';
  const memoryDisplay = memoryPercent != null ? `${memoryPercent}%` : memoryUsed != null ? `${formatBytes(memoryUsed)}` : '—';
  const diskDisplay = diskPercent != null ? `${diskPercent}%` : '—';
  const playersRows = players;
  const serverUptime = activeServer?.uptime || activeServer?.running_time || '—';

  const scrollToConsole = () => {
    serverLogRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  const formatLogLine = (line) => {
    const timestampMatch = line.match(/^\[[^\]]+\]/);
    const timestamp = timestampMatch ? timestampMatch[0] : '';
    const content = timestampMatch ? line.slice(timestamp.length).trim() : line;
    const level = content.includes('ERROR')
      ? 'error'
      : content.includes('WARNING')
      ? 'warning'
      : content.includes('INFO')
      ? 'info'
      : 'default';
    return { timestamp, content, level };
  };

  return (
    <div className="dashboard-shell">
      <div className="dashboard-main">
        <div className="dashboard-container">
          <header className="dashboard-header">
            <div className="dashboard-header-left">
              <span className="dashboard-context">Painel de Controle</span>
              <h1 className="dashboard-page-title">Dashboard SA-MP</h1>
            </div>
          </header>

          {message && <div className="message">{message}</div>}

      <div className="dashboard-grid">
        <div className="card server-hero">
          <div className="server-hero-card">
            <div className="server-hero-title-row">
              <div className="server-hero-title-left">
                <span className={`server-status-dot ${serverSuspended ? 'offline' : serverOnline ? 'online' : 'offline'}`} />
                <div>
                  <h2>{activeServer?.name || 'Servidor sem nome'}</h2>
                  <p className="server-hero-ip">{activeServer ? `${activeServer.ip}:${activeServer.port}` : 'Servidor não configurado'}</p>
                </div>
              </div>
              <span className={`status-pill ${serverSuspended ? 'offline' : serverOnline ? 'online' : 'offline'}`}>
                {serverStatusLabel}
              </span>
            </div>
            <div className="server-hero-meta">
              <div>
                <span className="card-label">Plano</span>
                <strong className="card-value">{activeServer ? serverPlan : '—'}</strong>
              </div>
              <div>
                <span className="card-label">Jogadores</span>
                <strong className="card-value">{activeServer ? `${serverPlayers}/${activeServer.limit_slots || '∞'}` : '—'}</strong>
              </div>
            </div>
          </div>
        </div>

        <div className="card summary-card players-summary">
          <span className="card-label">Jogadores Online</span>
          <div className="metric-value-large">{activeServer ? serverPlayers : 0}</div>
          <p className="card-note">{activeServer ? `${serverPlayers} conectados` : 'Nenhum servidor ativo'}</p>
        </div>

        <div className="card summary-card players-summary">
          <span className="card-label">Status / Uptime</span>
          <div className="status-summary">
            <span className={`status-pill ${serverSuspended ? 'offline' : serverOnline ? 'online' : 'offline'}`}>
              {serverStatusLabel}
            </span>
          </div>
          <p className="card-note">{activeServer ? `Uptime ${serverUptime}` : 'Servidor offline'}</p>
        </div>

        <div className="card metric-card">
          <div className="card-title">CPU</div>
          <div className="metric-ring" style={{ '--accent': 'var(--blue)' }}>
            <div
              className="metric-ring-progress"
              style={{ '--progress': `${cpuPercent ?? 0}` }}
            >
              <div className="metric-ring-inner">
                <strong>{cpuDisplay}</strong>
              </div>
            </div>
          </div>
          <p className="card-note">Uso atual do processador</p>
        </div>

        <div className="card metric-card">
          <div className="card-title">Memória RAM</div>
          <div className="metric-ring" style={{ '--accent': 'var(--green)' }}>
            <div
              className="metric-ring-progress"
              style={{ '--progress': `${memoryPercent ?? 0}` }}
            >
              <div className="metric-ring-inner">
                <strong>{memoryDisplay}</strong>
              </div>
            </div>
          </div>
          <p className="card-note">{memoryUsed != null ? `${formatBytes(memoryUsed)} / ${formatBytes(memoryTotal)}` : 'Sem dados'}</p>
        </div>

        <div className="card metric-card">
          <div className="card-title">Disco</div>
          <div className="metric-ring" style={{ '--accent': 'var(--orange)' }}>
            <div
              className="metric-ring-progress"
              style={{ '--progress': `${diskPercent ?? 0}` }}
            >
              <div className="metric-ring-inner">
                <strong>{diskDisplay}</strong>
              </div>
            </div>
          </div>
          <p className="card-note">{diskUsed != null ? `${formatBytes(diskUsed)} / ${formatBytes(diskTotal)}` : 'Sem dados'}</p>
        </div>

        <div className="card metric-card">
          <div className="card-title">Ping</div>
          <div className="metric-ring" style={{ '--accent': 'var(--purple)' }}>
            <div
              className="metric-ring-progress"
              style={{ '--progress': `${activeServer?.ping ? Math.min(activeServer.ping / 2, 100) : 0}` }}
            >
              <div className="metric-ring-inner">
                <strong>{activeServer?.ping ? `${activeServer.ping} ms` : '—'}</strong>
              </div>
            </div>
          </div>
          <p className="card-note">Última atualização de latência</p>
        </div>

       <div className="card chart-card">
          <div className="card-header">
            <h3>Gráfico de Jogadores</h3>
            <small>Visão rápida em tempo real</small>
          </div>
          <div className="chart-panel">
            {activeServer ? (
              <div className="chart-empty">{serverPlayers} jogadores conectados</div>
            ) : (
              <div className="chart-empty">Nenhum servidor ativo</div>
            )}
          </div>
        </div>

        <div className="card server-info-card">
          <div className="card-header">
            <h3>Informações do Servidor</h3>
            <small>Detalhes principais</small>
          </div>
          {activeServer ? (
            <div className="server-info-grid">
              <div>
                <strong>Hostname</strong>
                <p>{activeServer.name}</p>
              </div>
              <div>
                <strong>IP:Porta</strong>
                <p>{serverAddress}</p>
              </div>
              <div>
                <strong>Plano</strong>
                <p>{serverPlan}</p>
              </div>
              <div>
                <strong>Proprietário</strong>
                <p>{serverOwnerName || '—'}</p>
              </div>
              <div>
                <strong>Gamemode</strong>
                <p>{activeServer.game_mode || activeServer.gamemode || '—'}</p>
              </div>
              <div>
                <strong>RAM Limite</strong>
                <p>{activeServer.limit_ram ? `${activeServer.limit_ram} MB` : '—'}</p>
              </div>
            </div>
          ) : (
            <div className="empty-state">Sem servidor configurado.</div>
          )}
        </div>

        <div className="card players-card">
          <div className="card-header">
            <h3>Jogadores Online</h3>
            <small>{activeServer ? `${serverPlayers} players agora` : 'Sem dados'}</small>
          </div>
          <div className="players-table-wrapper">
            <div className="players-table">
              <div className="players-table-row players-table-head">
                <span>ID</span>
                <span>Nome</span>
                <span>Score</span>
                <span>Ping</span>
              </div>
              {playersLoading ? (
                <div className="players-table-empty">Carregando jogadores...</div>
              ) : playersError ? (
                <div className="players-table-empty error">{playersError}</div>
              ) : playersRows.length > 0 ? (
                playersRows.slice(0, 8).map((player) => (
                  <div key={player.id} className="players-table-row">
                    <span>{player.id}</span>
                    <span>{player.name}</span>
                    <span>{player.score}</span>
                    <span>{player.ping}</span>
                  </div>
                ))
              ) : (
                <div className="players-table-empty">Nenhum jogador online</div>
              )}
            </div>
          </div>
        </div>

        <div className="card logs-card">
          <div className="card-header">
            <h3>Console / Logs Recentes</h3>
            <small>Últimas entradas</small>
          </div>
          <div className="server-log-output">
            {serverLogLoading && serverLogLines.length === 0 ? (
              <div className="empty-state">Carregando logs...</div>
            ) : serverLogError ? (
              <div className="empty-state error">{serverLogError}</div>
            ) : serverLogLines.length > 0 ? (
              serverLogLines.slice(-24).map((line, index) => {
                const { timestamp, content, level } = formatLogLine(line);
                return (
                  <div key={index} className={`log-line log-${level}`}>
                    {timestamp && <span className="log-timestamp">{timestamp}</span>}
                    <span className="log-message">{content}</span>
                  </div>
                );
              })
            ) : (
              <div className="empty-state">Nenhum log disponível</div>
            )}
          </div>
        </div>

        <div className="card quick-actions-card">
          <div className="card-header">
            <h3>Ações Rápidas</h3>
            <small>Atalhos de controle</small>
          </div>
          <div className="quick-actions-grid">
            {activeServer ? (
              <>
                {serverStatus !== 'online' && (
                  <LoadingButton
                    loading={isActionLoading(activeServer.id, 'start')}
                    onClick={() => handleAction(activeServer.id, 'start')}
                    className="action-button quick-action-btn btn-start"
                  >
                    Iniciar
                  </LoadingButton>
                )}
                <LoadingButton
                  loading={isActionLoading(activeServer.id, 'restart')}
                  onClick={() => handleAction(activeServer.id, 'restart')}
                  className="action-button quick-action-btn btn-restart"
                >
                  Reiniciar
                </LoadingButton>
                <LoadingButton
                  loading={isActionLoading(activeServer.id, 'stop')}
                  onClick={() => handleAction(activeServer.id, 'stop')}
                  className="action-button quick-action-btn btn-stop"
                >
                  Parar
                </LoadingButton>
                {user?.role === 'admin' && (
                  <LoadingButton
                    loading={isActionLoading(activeServer.id, 'suspend')}
                    onClick={() => handleAction(activeServer.id, 'suspend')}
                    className="action-button quick-action-btn btn-shutdown"
                  >
                    Desligar
                  </LoadingButton>
                )}
                <button type="button" onClick={scrollToConsole} className="action-button quick-action-btn btn-console">
                  Ver Console
                </button>
                <LoadingButton
                  loading={false}
                  onClick={handleSendCommand}
                  className="action-button quick-action-btn btn-command"
                >
                  Enviar Comando
                </LoadingButton>
                <LoadingButton
                  loading={false}
                  onClick={handleBackup}
                  disabled={true}
                  title="Backup ainda não implementado"
                  className="action-button quick-action-btn btn-backup"
                >
                  Fazer Backup
                </LoadingButton>
              </>
            ) : (
              <div className="empty-state">Inicie um servidor para ver as ações disponíveis.</div>
            )}
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
  );
};

export default Dashboard;
