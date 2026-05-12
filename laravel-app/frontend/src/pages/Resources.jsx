import React, { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { fetchServers, fetchFiveMResources, executeFiveMResourceAction, logout, fetchUsers, getApiErrorMessage } from '../services/api';
import LoadingButton from '../components/LoadingButton';
import { resolveEngine } from '../utils/engine';

const getAccessibleServers = (servers, user) => {
  if (!user) return [];
  if (user.role === 'admin' || user.role === 'administrador') {
    return servers;
  }
  return servers.filter(server => Number(server.owner_id) === Number(user.id));
};

const Resources = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const initialServerId = searchParams.get('server_id');

  const [user, setUser] = useState(null);
  const [servers, setServers] = useState([]);
  const [activeServerId, setActiveServerId] = useState(initialServerId);
  const [activeServer, setActiveServer] = useState(null);
  const [resources, setResources] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterType, setFilterType] = useState('all');
  const [actionLoading, setActionLoading] = useState({});

  const authUser = localStorage.getItem('auth_user');

  useEffect(() => {
    if (!authUser) {
      navigate('/login');
      return;
    }

    try {
      const parsed = JSON.parse(authUser);
      setUser(parsed);
    } catch (err) {
      console.warn('Falha ao ler usuário autenticado');
    }
  }, [navigate]);

  useEffect(() => {
    if (!user) return;

    const loadServers = async () => {
      try {
        const { data } = await fetchServers();
        const accessibleServers = getAccessibleServers(data, user);
        setServers(accessibleServers);

        if (initialServerId) {
          const server = accessibleServers.find(s => s.id == initialServerId);
          if (server) {
            setActiveServer(server);
            setActiveServerId(server.id);
          }
        } else if (accessibleServers.length > 0) {
          const storedId = localStorage.getItem('activeServerId');
          const serverToSelect = storedId ? accessibleServers.find(s => s.id == storedId) : accessibleServers[0];
          if (serverToSelect) {
            setActiveServer(serverToSelect);
            setActiveServerId(serverToSelect.id);
          }
        }
      } catch (err) {
        setError('Erro ao carregar servidores');
      }
    };

    loadServers();
  }, [user, initialServerId]);

  useEffect(() => {
    if (!activeServerId || !activeServer) return;

    const isFiveM = resolveEngine(activeServer) === 'fivem';
    if (!isFiveM) {
      setError('Resource Manager disponível apenas para FiveM');
      setResources([]);
      setStats(null);
      return;
    }

    loadResources();
  }, [activeServerId, activeServer]);

  const loadResources = async () => {
    if (!activeServerId) return;

    setLoading(true);
    setError('');
    setMessage(null);

    try {
      const response = await fetchFiveMResources(activeServerId);
      if (response.data.success) {
        setResources(response.data.resources || []);
      }
    } catch (err) {
      const errorMsg = getApiErrorMessage(err);
      setError(errorMsg);
      setResources([]);
    } finally {
      setLoading(false);
    }
  };

  const handleServerChange = (e) => {
    const serverId = parseInt(e.target.value);
    const server = servers.find(s => s.id === serverId);
    if (server) {
      setActiveServer(server);
      setActiveServerId(serverId);
      localStorage.setItem('activeServerId', serverId);
    }
  };

  const handleResourceAction = async (resourceName, action) => {
    if (!activeServerId) return;

    setMessage({ type: 'info', text: 'Executando ação...' });
    setActionLoading(prev => ({ ...prev, [`${resourceName}-${action}`]: true }));

    try {
      const response = await executeFiveMResourceAction(activeServerId, resourceName, action);
      const responseData = response.data || {};

      if (responseData.success) {
        setMessage({
          type: 'success',
          text: responseData.message || `Resource iniciado com sucesso via ${responseData.bridge || 'desconhecido'}`,
          bridge: responseData.bridge,
          errorCode: responseData.error_code,
          attemptedBridges: responseData.attempted_bridges,
        });
        await new Promise(resolve => setTimeout(resolve, 1000));
        await loadResources();
        setTimeout(() => setMessage(null), 5000);
      } else {
        setMessage({
          type: 'error',
          text: responseData.message || 'Falha ao executar ação',
          bridge: responseData.bridge,
          errorCode: responseData.error_code,
          attemptedBridges: responseData.attempted_bridges,
        });
      }
    } catch (err) {
      const errorMsg = getApiErrorMessage(err);
      setMessage({ type: 'error', text: errorMsg, bridge: null, errorCode: null });
    } finally {
      setActionLoading(prev => ({ ...prev, [`${resourceName}-${action}`]: false }));
    }
  };

  const filteredResources = resources.filter(resource => {
    const matchesSearch = resource.name.toLowerCase().includes(searchTerm.toLowerCase());
    let matchesFilter = true;

    switch (filterType) {
      case 'enabled':
        matchesFilter = resource.started || resource.ensured;
        break;
      case 'disabled':
        matchesFilter = !resource.started && !resource.ensured;
        break;
      case 'fxmanifest':
        matchesFilter = resource.hasFxmanifest;
        break;
      case 'resource-lua':
        matchesFilter = resource.hasResourceLua;
        break;
      default:
        matchesFilter = true;
    }

    return matchesSearch && matchesFilter;
  });

  const isFiveMServer = activeServer && resolveEngine(activeServer) === 'fivem';

  return (
    <div className="dashboard-container resources-page">
      <div className="dashboard-header">
        <h1>Gerenciar Resources</h1>
        <p className="dashboard-subtitle">Controle os resources do seu servidor FiveM</p>
      </div>

      {error && !isFiveMServer && (
        <div className="dashboard-error-banner">
          <p>{error}</p>
        </div>
      )}

      {message && (
        <div className={`dashboard-message ${message.type || 'info'}`}>
          <p>{message.text}</p>
          {message.bridge && <p className="message-detail">Bridge: {message.bridge}</p>}
          {message.errorCode && <p className="message-detail">Código: {message.errorCode}</p>}
          {message.attemptedBridges && message.attemptedBridges.length > 0 && (
            <div className="message-detail">
              <p>Bridges tentadas:</p>
              <ul>
                {message.attemptedBridges.map((attempt, index) => (
                  <li key={index}>
                    {attempt.bridge}: {attempt.success ? 'sucesso' : `falhou - ${attempt.reason}`}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}

      {isFiveMServer && (
        <>
          {/* Server Selector */}
          <div className="resources-selector">
            <label>Servidor:</label>
            <select value={activeServerId || ''} onChange={handleServerChange}>
              <option value="">Selecione um servidor...</option>
              {servers.map(server => (
                <option key={server.id} value={server.id}>
                  {server.name}
                </option>
              ))}
            </select>
            <LoadingButton
              loading={loading}
              onClick={loadResources}
              className="action-button"
            >
              Recarregar
            </LoadingButton>
          </div>

          {/* Search and Filters */}
          <div className="resources-controls">
            <input
              type="text"
              placeholder="Buscar resource..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="search-input"
            />
            <select value={filterType} onChange={(e) => setFilterType(e.target.value)} className="filter-select">
              <option value="all">Todos</option>
              <option value="enabled">Ativos</option>
              <option value="disabled">Desativados</option>
              <option value="fxmanifest">Com FxManifest</option>
              <option value="resource-lua">Com resource.lua</option>
            </select>
          </div>

          {/* Resources Table/Cards */}
          {loading ? (
            <div className="loading-spinner">
              <p>Carregando resources...</p>
            </div>
          ) : filteredResources.length === 0 ? (
            <div className="no-data-message">
              <p>Nenhum resource encontrado</p>
            </div>
          ) : (
            <div className="resources-list-card">
              <table className="resources-table">
                <thead>
                  <tr>
                    <th>Nome</th>
                    <th>Estado</th>
                    <th>Tipo</th>
                    <th>Ensure</th>
                    <th>Path</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredResources.map(resource => (
                    <tr key={resource.name}>
                      <td data-label="Nome">{resource.name}</td>
                      <td data-label="Estado">
                        <span className={`resource-badge ${resource.started || resource.ensured ? 'badge-active' : 'badge-inactive'}`}>
                          {resource.started || resource.ensured ? 'ONLINE' : 'STOPPED'}
                        </span>
                      </td>
                      <td data-label="Tipo">
                        <span className="resource-type-badge">
                          {resource.hasFxmanifest ? 'FxManifest' : 'Resource.lua'}
                        </span>
                      </td>
                      <td data-label="Ensure">
                        <span className={`resource-badge ${resource.ensured ? 'badge-active' : 'badge-inactive'}`}>
                          {resource.ensured ? 'Sim' : 'Não'}
                        </span>
                      </td>
                      <td data-label="Path">{resource.path}</td>
                      <td data-label="Ações" className="resource-actions">
                        <LoadingButton
                          loading={actionLoading[`${resource.name}-ensure`]}
                          onClick={() => handleResourceAction(resource.name, 'ensure')}
                          className="action-button-small btn-ensure"
                          title="Carregar ou recarregar este resource"
                        >
                          Ensure
                        </LoadingButton>
                        <LoadingButton
                          loading={actionLoading[`${resource.name}-start`]}
                          onClick={() => handleResourceAction(resource.name, 'start')}
                          className="action-button-small btn-start"
                          title="Iniciar este resource"
                        >
                          Iniciar
                        </LoadingButton>
                        <LoadingButton
                          loading={actionLoading[`${resource.name}-stop`]}
                          onClick={() => handleResourceAction(resource.name, 'stop')}
                          className="action-button-small btn-stop"
                          title="Parar este resource"
                        >
                          Parar
                        </LoadingButton>
                        <LoadingButton
                          loading={actionLoading[`${resource.name}-restart`]}
                          onClick={() => handleResourceAction(resource.name, 'restart')}
                          className="action-button-small btn-restart"
                          title="Reiniciar este resource"
                        >
                          Reiniciar
                        </LoadingButton>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
};

export default Resources;
