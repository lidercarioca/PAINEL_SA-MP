import React, { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import {
  fetchServers,
  createBackup,
  fetchBackups,
  deleteBackup,
  downloadBackup,
  getApiErrorMessage,
} from '../services/api';
import LoadingButton from '../components/LoadingButton';

const Backups = () => {
  const [servers, setServers] = useState([]);
  const [selectedServerId, setSelectedServerId] = useState(null);
  const [backups, setBackups] = useState([]);
  const [loading, setLoading] = useState(false);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState('');
  const [message, setMessage] = useState(null);
  const [error, setError] = useState(null);
  const navigate = useNavigate();
  const location = useLocation();

  const query = new URLSearchParams(location.search);
  const initialServerId = query.get('server_id');

  const loadServers = async () => {
    try {
      const { data } = await fetchServers();
      setServers(data);
      if (!selectedServerId) {
        const initial = initialServerId && data.some((server) => String(server.id) === String(initialServerId))
          ? initialServerId
          : data[0]?.id;
        setSelectedServerId(initial);
      }
    } catch (err) {
      const message = getApiErrorMessage(err);
      setError(message || 'Não foi possível carregar servidores.');
    }
  };

  const loadBackups = async (serverId) => {
    if (!serverId) {
      setBackups([]);
      return;
    }

    setLoading(true);
    try {
      const { data } = await fetchBackups(serverId);
      setBackups(data.backups || []);
      setError('');
    } catch (err) {
      const message = getApiErrorMessage(err);
      setError(message || 'Não foi possível carregar backups.');
    } finally {
      setLoading(false);
    }
  };

  const handleCreateBackup = async () => {
    if (!selectedServerId) return;
    setCreating(true);
    setMessage(null);
    setError(null);

    try {
      const { data } = await createBackup(selectedServerId);
      if (data.success) {
        setMessage(data.message || 'Backup criado com sucesso.');
        await loadBackups(selectedServerId);
      } else {
        setError(data.message || 'Falha ao criar backup.');
      }
    } catch (err) {
      const message = getApiErrorMessage(err);
      setError(message || 'Falha ao criar backup.');
    } finally {
      setCreating(false);
    }
  };

  const handleDeleteBackup = async (backupName) => {
    if (!selectedServerId || !backupName) return;
    if (!window.confirm('Tem certeza que deseja excluir este backup?')) {
      return;
    }

    setDeleting(backupName);
    setMessage(null);
    setError(null);
    try {
      const { data } = await deleteBackup(selectedServerId, backupName);
      if (data.success) {
        setMessage(data.message || 'Backup excluído com sucesso.');
        await loadBackups(selectedServerId);
      } else {
        setError(data.message || 'Falha ao excluir backup.');
      }
    } catch (err) {
      const message = getApiErrorMessage(err);
      setError(message || 'Falha ao excluir backup.');
    } finally {
      setDeleting('');
    }
  };

  const handleDownloadBackup = async (backupName) => {
    if (!selectedServerId || !backupName) return;

    try {
      const response = await downloadBackup(selectedServerId, backupName);
      const blob = new Blob([response.data], { type: 'application/zip' });
      const downloadUrl = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = downloadUrl;
      link.setAttribute('download', backupName);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(downloadUrl);
    } catch (err) {
      const message = getApiErrorMessage(err);
      setError(message || 'Falha ao baixar backup.');
    }
  };

  useEffect(() => {
    loadServers();
  }, []);

  useEffect(() => {
    if (selectedServerId) {
      loadBackups(selectedServerId);
    }
  }, [selectedServerId]);

  useEffect(() => {
    setMessage(null);
    setError(null);
    setBackups([]);
  }, [selectedServerId]);

  return (
    <div className="backups-page">
      <header className="servers-header">
        <div className="servers-head-copy">
          <div className="servers-head-row">
            <h1>Backups</h1>
          </div>
          <p>Crie e gerencie backups dos seus servidores.</p>
        </div>
      </header>

      {message && <div className="servers-message success">{message}</div>}
      {error && <div className="servers-message error">{error}</div>}

      <section className="card-surface backups-section">
        <div className="section-header">
          <div>
            <p className="section-overline">Backup</p>
            <h2>Gerenciar backups</h2>
          </div>
          <div className="backup-actions">
            <select
              value={selectedServerId || ''}
              onChange={(e) => setSelectedServerId(e.target.value)}
              className="server-select"
            >
              <option value="">Selecione um servidor</option>
              {servers.map((server) => (
                <option key={server.id} value={server.id}>
                  {server.name || `Servidor ${server.id}`} - {server.engine || 'samp'}
                </option>
              ))}
            </select>
            <LoadingButton
              onClick={handleCreateBackup}
              loading={creating}
              disabled={!selectedServerId}
            >
              Criar Backup
            </LoadingButton>
          </div>
        </div>

        <div className="backups-list card">
          {loading ? (
            <div className="empty-state">Carregando backups...</div>
          ) : backups.length === 0 ? (
            <div className="empty-state">Nenhum backup encontrado para este servidor.</div>
          ) : (
            <div className="backups-table">
              <div className="backups-table-row backups-table-head">
                <span>Nome</span>
                <span>Engine</span>
                <span>Tamanho</span>
                <span>Data</span>
                <span>Ações</span>
              </div>
              {backups.map((backup) => (
                <div key={backup.name} className="backups-table-row">
                  <span>{backup.name}</span>
                  <span>{backup.engine}</span>
                  <span>{backup.size}</span>
                  <span>{backup.created_at}</span>
                  <span className="backups-table-actions">
                      <button
                      type="button"
                      onClick={() => handleDownloadBackup(backup.name)}
                      className="secondary-button"
                    >
                      Baixar
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDeleteBackup(backup.name)}
                      className="danger-button"
                      disabled={deleting === backup.name}
                    >
                      {deleting === backup.name ? 'Excluindo...' : 'Excluir'}
                    </button>
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>
    </div>
  );
};

export default Backups;
