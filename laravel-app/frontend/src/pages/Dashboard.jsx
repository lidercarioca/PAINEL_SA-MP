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
  fetchServerConsoleStream,
  fetchPlayers,
  fetchServerStats,
  refreshServerStatus,
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
import PlayersChart from '../components/PlayersChart';
import MetricsHistoryChart from '../components/MetricsHistoryChart';
import ServerConsole from '../components/ServerConsole';
import { resolveEngine, getEngineLabel } from '../utils/engine';

const getStatusLabel = (status, ping = null) => {
  const normalized = String(status || '').toLowerCase();
  switch (normalized) {
    case 'online':
      return ping ? `🟢 Online (${ping} ms)` : '🟢 Online (ping desconhecido)';
    case 'offline':
      return '🔴 Offline';
    case 'starting':
      return '🟡 Iniciando...';
    case 'stopping':
      return '🟡 Parando...';
    case 'suspended':
      return '🔴 Offline';
    default:
      return normalized ? `🟡 ${status}` : '🔴 Offline';
  }
};

// Função para obter servidores acessíveis ao usuário
const getAccessibleServers = (servers, user) => {
  if (!user) return [];
  if (user.role === 'admin' || user.role === 'administrador') {
    return servers;
  }
  // Clientes só veem seus próprios servidores
  return servers.filter(server => Number(server.owner_id) === Number(user.id));
};

const Dashboard = () => {
  const [servers, setServers] = useState([]);
  const [activeServer, setActiveServerState] = useState(null);
  const [activeServerId, setActiveServerId] = useState(null);
  const activeServerEngine = resolveEngine(activeServer);
  const activeServerIsFiveM = activeServerEngine === 'fivem';
  const [message, setMessage] = useState(null);
  const [resources, setResources] = useState(null);
  const [user, setUser] = useState(null);
  const [clients, setClients] = useState([]);
  const [plans, setPlans] = useState([]);
  const [players, setPlayers] = useState([]);
  const [playersLoading, setPlayersLoading] = useState(false);
  const [playersError, setPlayersError] = useState('');
  const [chartHistory, setChartHistory] = useState([]);
  const [chartTab, setChartTab] = useState('players');
  const [metricsHistory, setMetricsHistory] = useState([]);
  const [serverFps, setServerFps] = useState(null);
  const [serverCpu, setServerCpu] = useState(null);
  const [serverMemory, setServerMemory] = useState(null);
  const [serverDisk, setServerDisk] = useState(null);
  const [serverStatsDebug, setServerStatsDebug] = useState(null);
  const [serverStatsLoading, setServerStatsLoading] = useState(false);
  const [newServer, setNewServer] = useState({
    name: '',
    ip: '',
    port: 7777,
    txadmin_port: '',
    password: '',
    type: 'local',
    engine: 'samp',
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
  const serverLogOffsetRef = useRef(0);
  const serverLogFileRef = useRef(null);
  const firstLogLoadRef = useRef(true);
  const editSectionRef = useRef(null);
  const quickPollIntervalRef = useRef(null);
  const quickPollTimeoutRef = useRef(null);
  const serverStatsRequestIdRef = useRef(0);
  const activeServerIdRef = useRef(null);
  const navigate = useNavigate();

  const logServerSelection = (source, previousServer, nextServer, reason) => {
    if (process.env.NODE_ENV !== 'development') {
      return;
    }

    console.groupCollapsed('[SERVER_SELECTION]');
    console.log('source:', source);
    console.log('previousServer:', previousServer);
    console.log('nextServer:', nextServer);
    console.log('reason:', reason);
    console.trace();
    console.groupEnd();
  };

  const setActiveServer = (server) => {
    const previousServer = activeServer ? String(activeServer.id) : null;
    const nextServer = server?.id ? String(server.id) : null;

    setActiveServerState(server);
    setActiveServerId(nextServer);
    activeServerIdRef.current = nextServer;

    if (server) {
      localStorage.setItem('activeServerId', nextServer);
    } else {
      localStorage.removeItem('activeServerId');
    }

    if (previousServer !== nextServer) {
      logServerSelection('setActiveServer', previousServer, nextServer, 'explicit selection or reset');
    }
  };

  // Restaurar servidor do localStorage ao inicializar
  useEffect(() => {
    const savedServerId = localStorage.getItem('activeServerId');
    if (savedServerId) {
      setActiveServerId(savedServerId);
      activeServerIdRef.current = savedServerId;
    }
  }, []);

  // Sincronizar activeServer quando servers mudam
  useEffect(() => {
    if (servers.length === 0) {
      setActiveServerState(null);
      return;
    }

    const filtered = getAccessibleServers(servers, user);

    if (activeServerId) {
      const selected = filtered.find(
        (s) => String(s.id) === String(activeServerId)
      );

      if (selected) {
        setActiveServerState(selected);
        return;
      }
    }

    if (!activeServerId && filtered.length > 0) {
      const first = filtered[0];
      setActiveServer(first);
      logServerSelection('serverSync', null, String(first.id), 'initial active server selection');
    }
  }, [servers, user, activeServerId]);

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
        if (parsed.role === 'admin' || parsed.role === 'administrador') {
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
  }, [activeServerId]);

  useEffect(() => {
    activeServerIdRef.current = activeServerId;
  }, [activeServerId]);

  useEffect(() => {
    const interval = setInterval(() => {
      loadResources();
    }, 5000);

    return () => clearInterval(interval);
  }, []);

  // Limpar polling rápido quando componente desmontar
  useEffect(() => {
    return () => {
      if (quickPollIntervalRef.current) clearInterval(quickPollIntervalRef.current);
      if (quickPollTimeoutRef.current) clearTimeout(quickPollTimeoutRef.current);
    };
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
        setNewServer((prev) => ({ ...prev, folder: folderPath }));
        return;
      } catch (error) {
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

    setNewServer((prev) => ({ ...prev, folder: selectedFolder }));
  };

  const loadClients = async () => {
    try {
      const { data } = await fetchUsers();
      const clientRoles = ['client', 'cliente'];
      setClients(data.filter((item) => clientRoles.includes(String(item.role).toLowerCase())));
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
      const playersList = data.players || [];
      setPlayers(playersList);
      setPlayersError('');

      // Atualizar histórico de gráfico com os dados atuais
      setChartHistory((prev) => {
        const newEntry = {
          timestamp: new Date(),
          players: playersList.length,
        };
        // Manter apenas os últimos 60 pontos (10 minutos com atualizações a cada 5s)
        const updated = [...prev, newEntry];
        return updated.length > 60 ? updated.slice(-60) : updated;
      });
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
      setServerStatsDebug(null);
      setServerStatsLoading(false);
      return;
    }

    const requestId = ++serverStatsRequestIdRef.current;
    setServerStatsLoading(true);
    try {
      console.log("[STATS FETCH START]", serverId, `/servers/${serverId}/stats`);
      const response = await fetchServerStats(serverId);
      console.log("[STATS RAW RESPONSE]", response);
      const payload = response?.data?.data ?? response?.data ?? response;
      console.log("[STATS NORMALIZED PAYLOAD]", payload);

      if (requestId !== serverStatsRequestIdRef.current || String(serverId) !== String(activeServerIdRef.current)) {
        console.warn("[STATS IGNORED - STALE OR SERVER CHANGED]", serverId, activeServerIdRef.current, requestId, serverStatsRequestIdRef.current);
        return;
      }

      setServerFps(payload.fps ?? null);
      setServerCpu(payload.cpu ?? null);
      setServerMemory(payload.memory ?? null);
      setServerDisk(payload.disk ?? null);
      setServerStatsDebug(payload.debug ?? null);

      if (process.env.NODE_ENV === 'development') {
        const selectedServerId = activeServerId || serverId;
        console.groupCollapsed('[METRICS DEBUG] Server Stats');
        console.log('serverId:', selectedServerId);
        console.log('source:', payload.debug);
        console.log('cpu:', payload.cpu);
        console.log('memory:', payload.memory);
        console.log('disk:', payload.disk);
        console.groupEnd();
      }

      // Atualizar histórico de métricas
      setMetricsHistory((prev) => {
        const newEntry = {
          timestamp: new Date(),
          cpu: payload.cpu?.percent ?? null,
          memory: payload.memory?.percent ?? null,
          ping: activeServer?.ping ?? null,
        };
        // Manter apenas os últimos 60 pontos (10 minutos com atualizações a cada 5s)
        const updated = [...prev, newEntry];
        return updated.length > 60 ? updated.slice(-60) : updated;
      });
    } catch (error) {
      console.warn('Erro ao carregar estatísticas do servidor:', error);
      if (requestId === serverStatsRequestIdRef.current) {
        setServerFps(null);
        setServerCpu(null);
        setServerMemory(null);
        setServerDisk(null);
        setServerStatsDebug(null);
      }
    } finally {
      if (requestId === serverStatsRequestIdRef.current) {
        setServerStatsLoading(false);
      }
    }
  };

  // Polling rápido para detectar mudanças de status
  const startQuickPolling = (serverId, durationMs = 10000, initialDelayMs = 5000, intervalMs = 2000) => {
    if (quickPollIntervalRef.current) clearInterval(quickPollIntervalRef.current);
    if (quickPollTimeoutRef.current) clearTimeout(quickPollTimeoutRef.current);

    let elapsed = 0;
    let pollingActive = true;

    const stopPolling = () => {
      if (quickPollIntervalRef.current) {
        clearInterval(quickPollIntervalRef.current);
        quickPollIntervalRef.current = null;
      }
      if (quickPollTimeoutRef.current) {
        clearTimeout(quickPollTimeoutRef.current);
        quickPollTimeoutRef.current = null;
      }
      pollingActive = false;
    };

    const pollFunction = async () => {
      if (!pollingActive) return;

      try {
        if (serverId !== activeServerIdRef.current) {
          stopPolling();
          return;
        }

        const { data } = await refreshServerStatus(serverId);

        if (activeServer && Number(activeServer.id) === Number(serverId)) {
          const updatedServer = {
            ...activeServer,
            status: data.status,
            ping: data.ping,
          };
          setActiveServerState(updatedServer);
        }

        setServers(prev => prev.map(s =>
          Number(s.id) === Number(serverId)
            ? { ...s, status: data.status, ping: data.ping }
            : s
        ));

        if (data.status === 'online') {
          stopPolling();
          return;
        }

        if (elapsed >= durationMs && data.status === 'offline') {
          if (activeServer && Number(activeServer.id) === Number(serverId)) {
            setActiveServerState({
              ...activeServer,
              status: 'offline',
              ping: null,
            });
          }
          setServers(prev => prev.map(s =>
            Number(s.id) === Number(serverId)
              ? { ...s, status: 'offline', ping: null }
              : s
          ));
          stopPolling();
        }
      } catch (error) {
        if (error.response?.status >= 500) {
          stopPolling();
          setMessage('Falha ao verificar status do servidor. Tente novamente mais tarde.');
          return;
        }
      }
    };

    quickPollTimeoutRef.current = setTimeout(() => {
      if (!pollingActive) return;
      pollFunction();
      quickPollIntervalRef.current = setInterval(() => {
        elapsed += intervalMs;
        pollFunction();
      }, intervalMs);
    }, initialDelayMs);

    setTimeout(() => {
      if (pollingActive) stopPolling();
    }, durationMs + initialDelayMs);
  };

  const handleAction = async (serverId, action) => {
    setActionLoading({ serverId, action });
    try {
      if (action === 'start') {
        const { data } = await startServer(serverId);
        if (data?.success) {
          setMessage(data.message || 'Servidor iniciado com sucesso.');
          if (activeServer && Number(activeServer.id) === Number(serverId)) {
            setActiveServerState({ ...activeServer, status: 'starting', ping: null });
            setServers(prev => prev.map(s =>
              Number(s.id) === Number(serverId)
                ? { ...s, status: 'starting', ping: null }
                : s
            ));
          }
          startQuickPolling(serverId, 45000, 5000, 2000);
          await loadServers();
        } else {
          throw new Error(data?.message || 'Falha ao iniciar o servidor.');
        }
      }
      if (action === 'stop') {
        const { data } = await stopServer(serverId);
        setMessage(data?.message || 'Comando stop enviado.');
        if (activeServer && Number(activeServer.id) === Number(serverId)) {
          setActiveServerState({ ...activeServer, status: 'stopping' });
          setServers(prev => prev.map(s =>
            Number(s.id) === Number(serverId)
              ? { ...s, status: 'stopping' }
              : s
          ));
        }
        startQuickPolling(serverId, 10000, 1000, 2000);
        await loadServers();
      }
      if (action === 'restart') {
        await restartServer(serverId);
        setMessage('Comando restart enviado.');
        // Iniciar polling rápido por 30 segundos
        startQuickPolling(serverId, 30000);
        await loadServers();
      }
      if (action === 'suspend') {
        await suspendServer(serverId);
        setMessage('Comando suspend enviado.');
        // Atualizar estado local imediatamente
        if (activeServer && Number(activeServer.id) === Number(serverId)) {
          setActiveServerState({ ...activeServer, status: 'offline' });
        }
        // Iniciar polling rápido por 5 segundos
        startQuickPolling(serverId, 5000);
        await loadServers();
      }
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

  const handleBackup = () => {
  if (!activeServer) return;
  navigate(`/backups?server_id=${activeServer.id}`);
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
      txadmin_port: server.txadmin_port || '',
      password: server.password || '',
      type: server.type || 'local',
      engine: resolveEngine(server),
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
      txadmin_port: '',
      password: '',
      type: 'local',
      engine: 'samp',
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
          txadmin_port: newServer.txadmin_port ? Number(newServer.txadmin_port) : null,
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
          txadmin_port: newServer.txadmin_port ? Number(newServer.txadmin_port) : null,
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
        serverLogOffsetRef.current = 0;
        serverLogFileRef.current = null;
        setServerLogLoading(false);
        return;
      }

      if (firstLogLoadRef.current) {
        setServerLogLoading(true);
      }

      try {
        const isFiveM = activeServerIsFiveM;

        let response;
        if (isFiveM) {
          response = await fetchServerConsoleStream(serverId, serverLogOffsetRef.current);
        } else {
          response = await fetchLogs(serverId);
        }

        const data = response.data || {};

        if (isFiveM) {
          if (!data.success) {
            setServerLogError(data.message || 'Não foi possível carregar o console do FXServer.');
            setServerLogLines([]);
            serverLogLinesRef.current = [];
            return;
          }

          const incomingLines = Array.isArray(data.lines) ? data.lines : [];
          const nextOffset = Number(data.offset || 0);
          const nextFile = data.file || null;
          const shouldReset = data.truncated || serverLogFileRef.current !== nextFile;

          const nextLines = shouldReset
            ? incomingLines
            : [...serverLogLinesRef.current, ...incomingLines].slice(-300);

          serverLogOffsetRef.current = nextOffset;
          serverLogFileRef.current = nextFile;
          setServerLogLines(nextLines);
          serverLogLinesRef.current = nextLines;
          setServerLogError('');
        } else {
          const incomingLines = Array.isArray(data.lines) ? data.lines : [];
          setServerLogLines(incomingLines);
          serverLogLinesRef.current = incomingLines;
          serverLogOffsetRef.current = 0;
          serverLogFileRef.current = null;
          setServerLogError('');
        }
      } catch (error) {
        const message = getApiErrorMessage(error);
        console.error('[Dashboard] error loading logs:', message, error);
        setServerLogError(message || 'Não foi possível carregar o console do servidor.');
        setServerLogLines([]);
        serverLogLinesRef.current = [];
        serverLogOffsetRef.current = 0;
        serverLogFileRef.current = null;
      } finally {
        setServerLogLoading(false);
        firstLogLoadRef.current = false;
      }
    };

    serverLogOffsetRef.current = 0;
    serverLogFileRef.current = null;
    serverLogLinesRef.current = [];
    firstLogLoadRef.current = true;
    setServerLogLines([]);
    setServerLogError('');

    loadServerLogs(activeServer.id);
    const logInterval = setInterval(() => loadServerLogs(activeServer.id), 1500);
    return () => clearInterval(logInterval);
  }, [activeServer, activeServerEngine, plans]);

  useEffect(() => {
    if (activeServer) {
      loadPlayers(activeServer.id);
      const playersInterval = setInterval(() => loadPlayers(activeServer.id), 5000);
      return () => clearInterval(playersInterval);
    }
    setPlayers([]);
    setPlayersError('');
    setChartHistory([]);
    setMetricsHistory([]);
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
  const selectedServerId = activeServer?.id ?? activeServerId;
  const isDevelopment = process.env.NODE_ENV === 'development';

  const cpuPercent = serverCpu?.percent ?? null;
  const memoryUsedBytes = serverMemory?.used_bytes ?? null;
  const memoryTotalBytes = serverMemory?.total_bytes ?? null;
  const memoryPercent = serverMemory?.percent ?? null;
  const memoryLabel = serverMemory?.label ?? null;
  const diskUsedBytes = serverDisk?.folder_size_bytes ?? null;
  const diskTotalBytes = serverDisk?.total_bytes ?? null;
  const diskPercent = serverDisk?.percent ?? null;
  const diskLabel = serverDisk?.label ?? null;

  const cpuDisplay = cpuPercent != null ? `${cpuPercent}%` : '—';
  const memoryDisplay = memoryPercent != null ? `${memoryPercent}%` : memoryLabel ?? 'Sem dados';
  const diskDisplay = diskPercent != null ? `${diskPercent}%` : diskLabel ?? 'Sem dados';
  const playersRows = players;
  const serverUptime = activeServer?.uptime || activeServer?.running_time || '—';

  const formatPlayerIdentifier = (player) => {
    if (!player.identifiers || !Array.isArray(player.identifiers) || player.identifiers.length === 0) {
      return '';
    }

    const identifier = player.identifiers[0];
    if (typeof identifier !== 'string') {
      return '';
    }

    const parts = identifier.split(':');
    const prefix = parts.shift();
    const suffix = parts.join(':');
    const truncatedSuffix = suffix.length > 18 ? `${suffix.slice(0, 18)}...` : suffix;
    return prefix ? `${prefix}:${truncatedSuffix}` : truncatedSuffix;
  };

  const scrollToConsole = () => {
    if (activeServer) {
      navigate(`/console/${activeServer.id}`);
    }
  };

  const openTxAdminConsole = () => {
    if (!activeServer) {
      return;
    }

    const txPort = activeServer.txadmin_port || 40120;
    const txAdminUrl = `http://127.0.0.1:${txPort}`;
    window.open(txAdminUrl, '_blank', 'noopener,noreferrer');
  };

  return (
    <div className="dashboard-shell">
      <div className="dashboard-main">
        <div className="dashboard-container">
          <header className="dashboard-header">
            <div className="dashboard-header-left">
              <span className="dashboard-context">Painel de Controle</span>
              <div style={{ display: 'flex', alignItems: 'center', gap: '16px' }}>
                {/* Server Selector */}
                {getAccessibleServers(servers, user).length > 0 && (
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <select
                      value={activeServerId || ''}
                      onChange={(e) => {
                        const selectedId = String(e.target.value);
                        const server = servers.find(s => String(s.id) === selectedId);

                        if (server) {
                          setActiveServer(server);
                        } else {
                          setActiveServerId(selectedId);
                          activeServerIdRef.current = selectedId;
                          localStorage.setItem('activeServerId', selectedId);
                        }
                      }}
                      style={{
                        padding: '8px 14px',
                        borderRadius: '12px',
                        border: '1px solid rgba(59, 130, 246, 0.3)',
                        background: 'rgba(15, 23, 42, 0.9)',
                        color: '#e2e8f0',
                        cursor: 'pointer',
                        fontSize: '0.9rem',
                        fontWeight: '600',
                        transition: 'all 200ms ease',
                      }}
                      onMouseEnter={(e) => {
                        e.target.style.borderColor = 'rgba(59, 130, 246, 0.6)';
                        e.target.style.boxShadow = '0 0 12px rgba(59, 130, 246, 0.2)';
                      }}
                      onMouseLeave={(e) => {
                        e.target.style.borderColor = 'rgba(59, 130, 246, 0.3)';
                        e.target.style.boxShadow = 'none';
                      }}
                    >
                      {getAccessibleServers(servers, user).map((server) => {
                        const serverEngine = resolveEngine(server);
                        return (
                          <option key={server.id} value={server.id}>
                            {server.status === 'online' ? '🟢' : server.status === 'suspended' ? '🔴' : '⚪'} {server.name} [{getEngineLabel(serverEngine)}]
                          </option>
                        );
                      })}
                    </select>
                  </div>
                )}
                <h1 className="dashboard-page-title">
                  {activeServerIsFiveM ? 'Dashboard FiveM' : 'Dashboard SA-MP'}
                </h1>
                {activeServer && (
                  <span
                    style={{
                      padding: '6px 12px',
                      borderRadius: '4px',
                      fontSize: '12px',
                      fontWeight: 'bold',
                      background: activeServerIsFiveM ? '#8b5cf6' : '#3b82f6',
                      color: '#fff',
                    }}
                  >
                    {activeServerIsFiveM ? '🚀 FiveM' : '🎮 SA-MP'}
                  </span>
                )}
              </div>
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
          <p className="card-note">
            {memoryUsedBytes != null
              ? `${formatBytes(memoryUsedBytes)}${memoryTotalBytes != null && memoryTotalBytes > 0 ? ` / ${formatBytes(memoryTotalBytes)}` : ''}`
              : 'Sem dados'}
          </p>
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
          <p className="card-note">
            {diskUsedBytes != null
              ? `${formatBytes(diskUsedBytes)}${diskTotalBytes != null && diskTotalBytes > 0 ? ` / ${formatBytes(diskTotalBytes)}` : ''}`
              : 'Sem dados'}
          </p>
        </div>

        {isDevelopment && serverStatsDebug && (
          <div className="card metric-card debug-card">
            <div className="card-title">Debug Metrics</div>
            <div className="debug-details">
              <div><strong>PID:</strong> {serverStatsDebug?.process?.pid ?? '—'}</div>
              <div><strong>engine:</strong> {serverStatsDebug?.process?.engine ?? '—'}</div>
              <div><strong>disk_source:</strong> {serverStatsDebug?.disk_source ?? '—'}</div>
              <div><strong>server_folder:</strong> {serverStatsDebug?.server_folder ?? '—'}</div>
            </div>
            <p className="card-note">Informações de debug do fetch /servers/{selectedServerId}/stats</p>
          </div>
        )}

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
            <h3>Monitoramento</h3>
            <div className="chart-tabs">
              <button
                type="button"
                className={`chart-tab ${chartTab === 'players' ? 'active' : ''}`}
                onClick={() => setChartTab('players')}
              >
                Jogadores
              </button>
              <button
                type="button"
                className={`chart-tab ${chartTab === 'metrics' ? 'active' : ''}`}
                onClick={() => setChartTab('metrics')}
              >
                Métricas
              </button>
            </div>
          </div>
          <div className="chart-panel">
            {chartTab === 'players' ? (
              activeServer ? (
                <PlayersChart
                  players={serverPlayers}
                  maxSlots={activeServer.limit_slots || 32}
                  chartHistory={chartHistory}
                />
              ) : (
                <PlayersChart
                  players={0}
                  maxSlots={32}
                  chartHistory={[]}
                />
              )
            ) : (
              <MetricsHistoryChart metricsHistory={metricsHistory} />
            )}
          </div>
        </div>

        <div className="card server-info-card">
          <div className="card-header">
            <h3>
              {activeServerIsFiveM ? 'Informações do FiveM' : 'Informações do Servidor'}
            </h3>
            <small>Detalhes principais</small>
          </div>
          {activeServer ? (
            <div className="server-info-grid">
              {activeServerIsFiveM ? (
                <>
                  <div>
                    <strong>Servidor FiveM</strong>
                    <p>{activeServer.name}</p>
                  </div>
                  <div>
                    <strong>Endpoint TCP/UDP</strong>
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
                    <strong>OneSync</strong>
                    <p>Ativo</p>
                  </div>
                  <div>
                    <strong>Resources</strong>
                    <p>{activeServer.limit_slots ? `${activeServer.limit_slots} loaded` : '—'}</p>
                  </div>
                  <div>
                    <strong>txAdmin</strong>
                    <p>Disponível</p>
                  </div>
                  <div>
                    <strong>Disco Limite</strong>
                    <p>{activeServer.disk_limit_gb ? `${activeServer.disk_limit_gb} GB` : 'Ilimitado'}</p>
                  </div>
                </>
              ) : (
                <>
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
                  <div>
                    <strong>Slots</strong>
                    <p>{activeServer.limit_slots || '∞'}</p>
                  </div>
                  <div>
                    <strong>Disco Limite</strong>
                    <p>{activeServer.disk_limit_gb ? `${activeServer.disk_limit_gb} GB` : 'Ilimitado'}</p>
                  </div>
                </>
              )}
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
                <span>{activeServerIsFiveM ? 'Ping' : 'Score'}</span>
                <span>{activeServerIsFiveM ? 'Identificador' : 'Ping'}</span>
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
                    {activeServerIsFiveM ? (
                      <>
                        <span>{player.ping}</span>
                        <span>{formatPlayerIdentifier(player)}</span>
                      </>
                    ) : (
                      <>
                        <span>{player.score}</span>
                        <span>{player.ping}</span>
                      </>
                    )}
                  </div>
                ))
              ) : (
                <div className="players-table-empty">Nenhum jogador online</div>
              )}
            </div>
          </div>
        </div>

        <div className="card logs-card">
          <ServerConsole
            serverLogLines={serverLogLines}
            serverLogLoading={serverLogLoading}
            serverLogError={serverLogError}
            activeServer={activeServer}
          />
        </div>

        <div className="card quick-actions-card">
          <div className="card-header">
            <h3>
              {activeServerIsFiveM ? 'Ações do FiveM' : 'Ações Rápidas'}
            </h3>
            <small>Atalhos de controle</small>
          </div>
          <div className="quick-actions-grid">
            {activeServer ? (
              <>
                {serverStatus !== 'online' && (
                  <LoadingButton
                    loading={isActionLoading(activeServer.id, 'start')}
                    onClick={() => handleAction(activeServer.id, 'start')}
                    disabled={serverStatus === 'starting'}
                    className="action-button quick-action-btn btn-start"
                  >
                    {serverStatus === 'starting'
                      ? 'Iniciando...'
                      : activeServerIsFiveM
                        ? 'Iniciar FXServer'
                        : 'Iniciar'}
                  </LoadingButton>
                )}
                <LoadingButton
                  loading={isActionLoading(activeServer.id, 'restart')}
                  onClick={() => handleAction(activeServer.id, 'restart')}
                  className="action-button quick-action-btn btn-restart"
                >
                  {activeServerIsFiveM ? 'Reiniciar FXServer' : 'Reiniciar'}
                </LoadingButton>
                <LoadingButton
                  loading={isActionLoading(activeServer.id, 'stop')}
                  onClick={() => handleAction(activeServer.id, 'stop')}
                  className="action-button quick-action-btn btn-stop"
                >
                  {activeServerIsFiveM ? 'Parar FXServer' : 'Parar'}
                </LoadingButton>
                {user?.role === 'admin' || user?.role === 'administrador' && (
                  <LoadingButton
                    loading={isActionLoading(activeServer.id, 'suspend')}
                    onClick={() => handleAction(activeServer.id, 'suspend')}
                    className="action-button quick-action-btn btn-shutdown"
                  >
                    Desligar
                  </LoadingButton>
                )}
                {activeServerIsFiveM ? (
                  <>
                    <button
                      type="button"
                      onClick={openTxAdminConsole}
                      className="action-button quick-action-btn btn-console"
                      style={{
                        background: '#8b5cf6',
                        color: '#fff',
                        border: 'none',
                        cursor: 'pointer',
                        borderRadius: '4px',
                        padding: '8px 16px',
                      }}
                    >
                      🖥️ txAdmin Console
                    </button>
                    <button
                      type="button"
                      onClick={scrollToConsole}
                      className="action-button quick-action-btn btn-console"
                      style={{
                        background: '#8b5cf6',
                        color: '#fff',
                        border: 'none',
                        cursor: 'pointer',
                        borderRadius: '4px',
                        padding: '8px 16px',
                      }}
                    >
                      🔄 Recarregar Resources
                    </button>
                    <button
                      type="button"
                      onClick={() => navigate(`/resources?server_id=${activeServer.id}`)}
                      className="action-button quick-action-btn btn-resources"
                      style={{
                        background: '#06b6d4',
                        color: '#fff',
                        border: 'none',
                        cursor: 'pointer',
                        borderRadius: '4px',
                        padding: '8px 16px',
                      }}
                    >
                      📦 Gerenciar Resources
                    </button>
                  </>
                ) : (
                  <>
                    <button
                      type="button"
                      onClick={scrollToConsole}
                      className="action-button quick-action-btn btn-console"
                    >
                      Ver Console
                    </button>
                    <LoadingButton
                      loading={false}
                      onClick={handleSendCommand}
                      className="action-button quick-action-btn btn-command"
                    >
                      Enviar Comando
                    </LoadingButton>
                  </>
                )}
                <LoadingButton
                  loading={false}
                  onClick={handleBackup}
                  disabled={!activeServer}
                  title="Ir para backups"
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
