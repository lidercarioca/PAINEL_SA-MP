import React, { useState, useEffect, useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { createServer, updateServer, fetchUsers, fetchPlans, fetchServers, getApiErrorMessage } from '../services/api';
import LoadingButton from '../components/LoadingButton';

const CreateServer = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const folderInputRef = useRef(null);

  const editingServerId = searchParams.get('edit');

  const [newServer, setNewServer] = useState({
    name: '',
    ip: '',
    port: 7777,
    password: '',
    type: 'local',
    engine: 'samp',
    folder: '',
    owner_id: null,
    plan_id: null,
    game_mode: '',
    limit_ram: '',
    limit_slots: '',
    disk_limit_gb: '',
    auto_restart_interval_hours: '',
    auto_restart_on_crash: false,
    auto_restart_on_offline: false,
  });

  const [clients, setClients] = useState([]);
  const [plans, setPlans] = useState([]);
  const [message, setMessage] = useState('');
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(false);

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
          
          // Se estiver editando, carregar dados do servidor
          if (editingServerId) {
            loadServerForEdit(editingServerId);
          }
        }
      } catch (err) {
        console.warn('Falha ao ler usuário autenticado');
      }
    }
  }, [navigate, editingServerId]);

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

  const loadServerForEdit = async (serverId) => {
    try {
      setLoading(true);
      const { data: servers } = await fetchServers();
      const server = servers.find(s => s.id == serverId);
      
      if (server) {
        setNewServer({
          name: server.name || '',
          ip: server.ip || '',
          port: server.port || 7777,
          password: server.password || '',
          type: server.type || 'local',
          engine: server.engine || 'samp',
          folder: server.folder || '',
          owner_id: server.owner_id || null,
          plan_id: server.plan_id || null,
          game_mode: server.game_mode || server.gamemode || '',
          limit_ram: server.limit_ram || '',
          limit_slots: server.limit_slots || '',
          disk_limit_gb: server.disk_limit_gb || '',
          auto_restart_interval_hours: server.auto_restart_interval_hours || '',
          auto_restart_on_crash: Boolean(server.auto_restart_on_crash),
          auto_restart_on_offline: Boolean(server.auto_restart_on_offline),
        });
      } else {
        setMessage('Servidor não encontrado.');
        navigate('/servers');
      }
    } catch (error) {
      console.warn('Falha ao carregar servidor para edição:', error);
      setMessage('Erro ao carregar dados do servidor.');
      navigate('/servers');
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (event) => {
    event.preventDefault();

    try {
      const serverData = {
        ...newServer,
        game_mode: newServer.game_mode ? String(newServer.game_mode) : null,
        auto_restart_interval_hours: newServer.auto_restart_interval_hours ? Number(newServer.auto_restart_interval_hours) : null,
        disk_limit_gb: newServer.disk_limit_gb ? Number(newServer.disk_limit_gb) : null,
        auto_restart_on_crash: Boolean(newServer.auto_restart_on_crash),
        auto_restart_on_offline: Boolean(newServer.auto_restart_on_offline),
      };

      if (editingServerId) {
        // Atualizar servidor existente
        await updateServer({
          ...serverData,
          server_id: editingServerId,
        });
        setMessage('Servidor atualizado com sucesso.');
      } else {
        // Criar novo servidor
        await createServer(serverData);
        setMessage('Servidor criado com sucesso.');
      }

      setTimeout(() => {
        navigate('/servers');
      }, 2000);
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

  const handleEngineChange = (engine) => {
    const engineDefaults = {
      samp: { port: 7777, gamemode: 'SA-MP' },
      fivem: { port: 30120, gamemode: 'FiveM' }
    };

    const defaults = engineDefaults[engine] || engineDefaults.samp;
    setNewServer((prev) => ({
      ...prev,
      engine: engine,
      port: defaults.port,
      game_mode: defaults.gamemode
    }));
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

  if (!user || user.role !== 'admin') {
    return (
      <div className="servers-page">
        <div className="page-container">
          <div className="empty-state">Acesso negado. Apenas administradores podem criar servidores.</div>
        </div>
      </div>
    );
  }

  return (
    <div className="servers-page">
      <div className="page-container">
        <header className="page-header">
          <div className="page-header-content">
            <h1>{editingServerId ? 'Editar Servidor' : 'Criar Servidor'}</h1>
            <p>{editingServerId ? 'Atualize as configurações do servidor' : 'Configure um novo servidor SA-MP'}</p>
          </div>
        </header>

        {message && <div className="message">{message}</div>}

        <section className="server-form-section card-surface">
          <div className="section-header">
            <div>
              <p className="section-overline">{editingServerId ? 'Editar servidor' : 'Novo servidor'}</p>
              <h2>{editingServerId ? 'Atualizar servidor existente' : 'Adicionar novo servidor'}</h2>
            </div>
          </div>

          <form className="server-form" onSubmit={handleSubmit}>
            <div className="form-grid">
              <div className="form-field">
                <label>Nome do servidor</label>
                <input
                  type="text"
                  value={newServer.name}
                  onChange={(e) => setNewServer({ ...newServer, name: e.target.value })}
                  required
                />
              </div>
              <div className="form-field">
                <label>IP</label>
                <input
                  type="text"
                  value={newServer.ip}
                  onChange={(e) => setNewServer({ ...newServer, ip: e.target.value })}
                  required
                />
              </div>
              <div className="form-field">
                <label>Porta</label>
                <input
                  type="number"
                  value={newServer.port}
                  onChange={(e) => setNewServer({ ...newServer, port: Number(e.target.value) })}
                  required
                />
              </div>
              <div className="form-field">
                <label>Gamemode</label>
                <input
                  type="text"
                  value={newServer.game_mode}
                  onChange={(e) => setNewServer({ ...newServer, game_mode: e.target.value })}
                />
              </div>
              <div className="form-field form-field-full">
                <label>Caminho da pasta</label>
                <input
                  type="text"
                  value={newServer.folder}
                  onChange={(e) => setNewServer({ ...newServer, folder: e.target.value })}
                  placeholder="Digite ou cole o caminho completo"
                />
                <button type="button" className="button secondary-button folder-button" onClick={() => folderInputRef.current?.click()}>
                  📁 Selecionar pasta
                </button>
                <input
                  ref={folderInputRef}
                  type="file"
                  webkitdirectory="true"
                  directory="true"
                  style={{ display: 'none' }}
                  onChange={handleFolderSelect}
                />
              </div>
              <div className="form-field">
                <label>Cliente</label>
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
              </div>
              <div className="form-field">
                <label>Plano</label>
                <select
                  value={newServer.plan_id || ''}
                  onChange={(e) => setNewServer({ ...newServer, plan_id: e.target.value ? Number(e.target.value) : null })}
                >
                  <option value="">Nenhum plano vinculado</option>
                  {plans.map((plan) => (
                    <option key={plan.id} value={plan.id}>
                      {plan.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="form-field">
                <label>Limite RAM (MB)</label>
                <input
                  type="number"
                  value={newServer.limit_ram}
                  onChange={(e) => setNewServer({ ...newServer, limit_ram: e.target.value })}
                />
              </div>
              <div className="form-field">
                <label>Limite de slots</label>
                <input
                  type="number"
                  value={newServer.limit_slots}
                  onChange={(e) => setNewServer({ ...newServer, limit_slots: e.target.value })}
                />
              </div>
              <div className="form-field">
                <label>Limite de Disco (GB)</label>
                <input
                  type="number"
                  value={newServer.disk_limit_gb}
                  onChange={(e) => setNewServer({ ...newServer, disk_limit_gb: e.target.value })}
                  placeholder="Deixe vazio para ilimitado"
                />
              </div>
              <div className="form-field">
                <label>Auto restart</label>
                <input
                  type="number"
                  value={newServer.auto_restart_interval_hours}
                  onChange={(e) => setNewServer({ ...newServer, auto_restart_interval_hours: e.target.value })}
                  placeholder="Intervalo em horas"
                />
              </div>
              <div className="form-field checkbox-field">
                <label>
                  <input
                    type="checkbox"
                    checked={newServer.auto_restart_on_crash}
                    onChange={(e) => setNewServer({ ...newServer, auto_restart_on_crash: e.target.checked })}
                  />
                  Reiniciar se crashar
                </label>
              </div>
              <div className="form-field checkbox-field">
                <label>
                  <input
                    type="checkbox"
                    checked={newServer.auto_restart_on_offline}
                    onChange={(e) => setNewServer({ ...newServer, auto_restart_on_offline: e.target.checked })}
                  />
                  Reiniciar se ficar offline
                </label>
              </div>
              <div className="form-field">
                <label>Senha RCON</label>
                <input
                  type="text"
                  value={newServer.password}
                  onChange={(e) => setNewServer({ ...newServer, password: e.target.value })}
                />
              </div>
              <div className="form-field">
                <label>Engine / Motor do Jogo</label>
                <div className="engine-selector" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                  <button
                    type="button"
                    className={`engine-button ${newServer.engine === 'samp' ? 'active' : ''}`}
                    style={{
                      padding: '12px',
                      border: `2px solid ${newServer.engine === 'samp' ? '#4CAF50' : '#ccc'}`,
                      borderRadius: '6px',
                      background: newServer.engine === 'samp' ? '#f0f8f0' : '#fff',
                      cursor: 'pointer',
                      fontWeight: newServer.engine === 'samp' ? 'bold' : 'normal'
                    }}
                    onClick={() => handleEngineChange('samp')}
                  >
                    🎮 SA-MP
                  </button>
                  <button
                    type="button"
                    className={`engine-button ${newServer.engine === 'fivem' ? 'active' : ''}`}
                    style={{
                      padding: '12px',
                      border: `2px solid ${newServer.engine === 'fivem' ? '#4CAF50' : '#ccc'}`,
                      borderRadius: '6px',
                      background: newServer.engine === 'fivem' ? '#f0f8f0' : '#fff',
                      cursor: 'pointer',
                      fontWeight: newServer.engine === 'fivem' ? 'bold' : 'normal'
                    }}
                    onClick={() => handleEngineChange('fivem')}
                  >
                    🚀 FiveM
                  </button>
                </div>
              </div>
              <div className="form-field">
                <label>Tipo / Local</label>
                <select
                  value={newServer.type}
                  onChange={(e) => setNewServer({ ...newServer, type: e.target.value })}
                >
                  <option value="local">Local</option>
                  <option value="remote">Remoto</option>
                </select>
              </div>
            </div>

            <div className="form-actions-row">
              <LoadingButton type="submit" variant="primary" loading={loading}>
                {editingServerId ? 'Atualizar servidor' : 'Criar servidor'}
              </LoadingButton>
              {editingServerId && (
                <button type="button" className="button secondary-button cancel-button" onClick={() => navigate('/servers')}>
                  Cancelar edição
                </button>
              )}
              {!editingServerId && (
                <button type="button" className="button secondary-button cancel-button" onClick={() => navigate('/servers')}>
                  Cancelar
                </button>
              )}
            </div>
          </form>
        </section>
      </div>
    </div>
  );
};

export default CreateServer;