import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  fetchServers,
  startServer,
  stopServer,
  restartServer,
  suspendServer,
  sendRconCommand,
  deleteServer,
  getApiErrorMessage,
} from '../services/api';
import ServerCard from '../components/ServerCard';
import LoadingButton from '../components/LoadingButton';

const Servers = () => {
  const [servers, setServers] = useState([]);
  const [message, setMessage] = useState(null);
  const [user, setUser] = useState(null);
  const [actionLoading, setActionLoading] = useState({ serverId: null, action: null });
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
    navigate(`/create-server?edit=${server.id}`);
  };

  const isActionLoading = (serverId, action) => actionLoading.serverId === serverId && actionLoading.action === action;

  const isAdmin = user?.role === 'admin';

  return (
    <div className="servers-page">
      <header className="servers-header">
        <div className="servers-head-copy">
          <div className="servers-head-row">
            <h1>Servidores</h1>
            {isAdmin && <span className="page-badge">Admin</span>}
          </div>
          <p>Gerencie os servidores configurados.</p>
        </div>
      </header>

      {message && <div className="servers-message">{message}</div>}

      <section className="server-list-section card-surface">
        <div className="section-header">
          <div>
            <p className="section-overline">Visão geral</p>
            <h2>Servidores configurados</h2>
          </div>
          <span className="section-note">{servers.length} servidor{servers.length === 1 ? '' : 'es'}</span>
        </div>

        <div className="server-list">
          {servers.length > 0 ? (
            servers.map((server) => {
              const canControl = isAdmin || server.owner_id === user?.id;
              return (
                <ServerCard
                  key={server.id}
                  server={server}
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
            <p className="empty-state">Nenhum servidor configurado.</p>
          )}
        </div>
      </section>
    </div>
  );
};

export default Servers;