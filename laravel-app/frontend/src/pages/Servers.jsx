import React, { useEffect, useState, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  fetchServers,
  startServer,
  stopServer,
  restartServer,
  suspendServer,
  sendRconCommand,
  createServer,
  updateServer,
  deleteServer,
  fetchUsers,
  fetchPlans,
  getApiErrorMessage,
} from '../services/api';
import ServerCard from '../components/ServerCard';
import LoadingButton from '../components/LoadingButton';

const Servers = () => {
  const [servers, setServers] = useState([]);
  const [clients, setClients] = useState([]);
  const [plans, setPlans] = useState([]);
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
  const [message, setMessage] = useState(null);
  const [user, setUser] = useState(null);
  const [actionLoading, setActionLoading] = useState({ serverId: null, action: null });
  const navigate = useNavigate();
  const editSectionRef = useRef(null);
  const folderInputRef = useRef(null);

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
      } catch {
        setUser(null);
      }
    }

    loadServers();
  }, [navigate]);

  useEffect(() => {
    const interval = setInterval(() => {
      loadServers();
    }, 5000);

    return () => clearInterval(interval);
  }, []);

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

  const isActionLoading = (serverId, action) => actionLoading.serverId === serverId && actionLoading.action === action;

  const isAdmin = user?.role === 'admin';

  return (
    <div className="servers-page">
      <header className="dashboard-header">
        <div>
          <h1>Servidores</h1>
          <p>Gerencie os servidores configurados e cadastre novos servidores.</p>
        </div>
      </header>

      {message && <div className="message">{message}</div>}

      <section className="server-list-section">
        <h2>Servidores configurados</h2>
        <div className="server-list">
          {servers.length > 0 ? (
            servers.map((server) => {
              const canControl = isAdmin || server.owner_id === user?.id;
              return (
                <ServerCard
                  key={server.id}
                  server={{ ...server, plan_name: plans.find((plan) => plan.id === server.plan_id)?.name || '' }}
                  ownerName={clients.find((client) => client.id === server.owner_id)?.name || ''}
                  onAction={handleAction}
                  onRcon={handleRcon}
                  onEdit={handleEdit}
                  onDelete={handleDelete}
                  canControl={canControl}
                  isAdmin={isAdmin}
                  actionLoading={actionLoading}
                />
              );
            })
          ) : (
            <p>Nenhum servidor configurado.</p>
          )}
        </div>
      </section>

      {isAdmin && (
        <section ref={editSectionRef} className="server-form-section">
          <h2>{editingServerId ? 'Editar servidor' : 'Adicionar novo servidor'}</h2>
          <form className="server-form" onSubmit={handleCreate}>
            <input
              type="text"
              placeholder="Nome do servidor"
              value={newServer.name}
              onChange={(e) => setNewServer({ ...newServer, name: e.target.value })}
              required
            />
            <input
              type="text"
              placeholder="IP"
              value={newServer.ip}
              onChange={(e) => setNewServer({ ...newServer, ip: e.target.value })}
              required
            />
            <input
              type="number"
              placeholder="Porta"
              value={newServer.port}
              onChange={(e) => setNewServer({ ...newServer, port: Number(e.target.value) })}
              required
            />
            <input
              type="text"
              placeholder="Nome do gamemode"
              value={newServer.game_mode}
              onChange={(e) => setNewServer({ ...newServer, game_mode: e.target.value })}
            />
            <div className="folder-picker">
              <label style={{ fontSize: '0.85rem', color: '#999', marginBottom: '4px', display: 'block' }}>
                Caminho da pasta do servidor
              </label>
              <input
                type="text"
                placeholder="Digite ou cole o caminho completo"
                value={newServer.folder}
                onChange={(e) => setNewServer({ ...newServer, folder: e.target.value })}
                style={{ marginBottom: '8px' }}
              />
              <button type="button" className="folder-button" onClick={() => folderInputRef.current?.click()}>
                📁 Escolher pasta (copiar nome para campo)
              </button>
              <input
                ref={folderInputRef}
                type="file"
                webkitdirectory="true"
                directory="true"
                style={{ display: 'none' }}
                onChange={(event) => {
                  const files = event.target.files;
                  if (!files || files.length === 0) {
                    return;
                  }
                  const file = files[0];
                  let selectedFolder = '';
                  if (file.path) {
                    selectedFolder = file.path.replace(/\\/g, '/');
                    if (selectedFolder.includes('/')) {
                      selectedFolder = selectedFolder.substring(0, selectedFolder.lastIndexOf('/'));
                    }
                  } else if (file.webkitRelativePath) {
                    selectedFolder = file.webkitRelativePath.replace(/\\/g, '/');
                    if (selectedFolder.includes('/')) {
                      selectedFolder = selectedFolder.substring(0, selectedFolder.lastIndexOf('/'));
                    }
                  } else {
                    selectedFolder = file.name;
                  }
                  setNewServer((prev) => ({ ...prev, folder: selectedFolder }));
                }}
              />
            </div>
            <select
              value={newServer.owner_id || ''}
              onChange={(e) => setNewServer({ ...newServer, owner_id: e.target.value ? Number(e.target.value) : null })}
            >
              <option value="">Nenhum cliente vinculado</option>
              {clients.map((client) => (
                <option key={client.id} value={client.id}>
                  {client.name} ({client.email})
                </option>
              ))}
            </select>
            <select
              value={newServer.plan_id || ''}
              onChange={(e) => setNewServer({ ...newServer, plan_id: e.target.value ? Number(e.target.value) : null })}
            >
              <option value="">Nenhum plano vinculado</option>
              {plans.map((plan) => (
                <option key={plan.id} value={plan.id}>
                  {plan.name} - {plan.slots} slots - R$ {plan.price}
                </option>
              ))}
            </select>
            <input
              type="number"
              placeholder="Limite RAM (MB)"
              value={newServer.limit_ram}
              onChange={(e) => setNewServer({ ...newServer, limit_ram: e.target.value })}
            />
            <input
              type="number"
              placeholder="Limite de slots"
              value={newServer.limit_slots}
              onChange={(e) => setNewServer({ ...newServer, limit_slots: e.target.value })}
            />
            <div className="auto-restart-options">
              <label>
                <input
                  type="checkbox"
                  checked={newServer.auto_restart_on_crash}
                  onChange={(e) => setNewServer({ ...newServer, auto_restart_on_crash: e.target.checked })}
                />
                Reiniciar se crashar
              </label>
              <label>
                <input
                  type="checkbox"
                  checked={newServer.auto_restart_on_offline}
                  onChange={(e) => setNewServer({ ...newServer, auto_restart_on_offline: e.target.checked })}
                />
                Reiniciar se ficar offline
              </label>
            </div>
            <input
              type="number"
              min="1"
              placeholder="Reiniciar a cada X horas"
              value={newServer.auto_restart_interval_hours}
              onChange={(e) => setNewServer({ ...newServer, auto_restart_interval_hours: e.target.value })}
            />
            <input
              type="text"
              placeholder="Senha RCON"
              value={newServer.password}
              onChange={(e) => setNewServer({ ...newServer, password: e.target.value })}
            />
            <select
              value={newServer.type}
              onChange={(e) => setNewServer({ ...newServer, type: e.target.value })}
            >
              <option value="local">Local</option>
              <option value="ssh">SSH</option>
            </select>
            <div className="form-actions">
              <button type="submit">{editingServerId ? 'Salvar alterações' : 'Criar servidor'}</button>
              {editingServerId && (
                <button type="button" className="cancel-button" onClick={handleCancelEdit}>
                  Cancelar
                </button>
              )}
            </div>
          </form>
        </section>
      )}
    </div>
  );
};

export default Servers;
