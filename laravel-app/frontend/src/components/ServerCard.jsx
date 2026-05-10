import React, { useState } from 'react';
import LoadingButton from './LoadingButton';
import { resolveEngine } from '../utils/engine';

const ServerCard = ({ server, ownerName, onAction, onRcon, onEdit, onDelete, canControl, isAdmin, actionLoading }) => {
  const [command, setCommand] = useState('');
  const serverGamemode = server.game_mode || server.gamemode || server.gamemode_name;
  const engine = resolveEngine(server);
  const engineInfo = {
    samp: { icon: '🎮', label: 'SA-MP' },
    fivem: { icon: '🚀', label: 'FiveM' }
  }[engine];

  const isLoading = (action) => actionLoading?.serverId === server.id && actionLoading?.action === action;

  const statusClass = ['online', 'offline', 'suspended', 'starting'].includes(String(server.status).toLowerCase())
    ? String(server.status).toLowerCase()
    : 'suspended';

  const getStatusLabel = (status, ping = null) => {
    const normalized = String(status || '').toLowerCase();
    switch (normalized) {
      case 'online':
        return ping ? `Online • ${ping} ms` : 'Online';
      case 'offline':
        return 'Offline';
      case 'starting':
        return 'Iniciando';
      case 'suspended':
        return 'Suspenso';
      default:
        return normalized ? String(status) : 'Offline';
    }
  };

  return (
    <article className="server-card">
      <div className="server-card-header">
        <div>
          <h3>{server.name}</h3>
          <p className="server-card-address">{server.ip}:{server.port}</p>
        </div>
        <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
          <span className="engine-badge" style={{
            padding: '6px 12px',
            borderRadius: '4px',
            fontSize: '12px',
            fontWeight: 'bold',
            background: '#e3f2fd',
            color: '#1976d2'
          }}>
            {engineInfo.icon} {engineInfo.label}
          </span>
          <span className={`status-badge ${statusClass}`}>
            {getStatusLabel(server.status, server.ping)}
          </span>
        </div>
      </div>

      <div className="server-card-meta">
        {server.folder && (
          <div className="meta-item">
            <span className="meta-label">Pasta</span>
            <span>{server.folder}</span>
          </div>
        )}
        {ownerName && (
          <div className="meta-item">
            <span className="meta-label">Cliente</span>
            <span>{ownerName}</span>
          </div>
        )}
        {server.plan_name && (
          <div className="meta-item">
            <span className="meta-label">Plano</span>
            <span>{server.plan_name}</span>
          </div>
        )}
        {serverGamemode && (
          <div className="meta-item">
            <span className="meta-label">Gamemode</span>
            <span>{serverGamemode}</span>
          </div>
        )}
      </div>

      {canControl ? (
        <>
          <div className="server-card-actions">
            <LoadingButton
              type="button"
              variant="success"
              loading={isLoading('start')}
              onClick={() => onAction(server.id, 'start')}
            >
              Ligar
            </LoadingButton>
            <LoadingButton
              type="button"
              variant="danger"
              loading={isLoading('stop')}
              onClick={() => onAction(server.id, 'stop')}
            >
              Desligar
            </LoadingButton>
            <LoadingButton
              type="button"
              variant="primary"
              loading={isLoading('restart')}
              onClick={() => onAction(server.id, 'restart')}
            >
              Reiniciar
            </LoadingButton>
            {isAdmin && (
              <LoadingButton
                type="button"
                variant="danger"
                loading={isLoading('suspend')}
                onClick={() => onAction(server.id, 'suspend')}
              >
                Suspender
              </LoadingButton>
            )}
            {isAdmin && (
              <LoadingButton type="button" variant="secondary" onClick={() => onEdit(server)}>
                Editar
              </LoadingButton>
            )}
            {isAdmin && (
              <LoadingButton type="button" variant="danger" onClick={() => onDelete(server.id)}>
                Excluir
              </LoadingButton>
            )}
          </div>

          <div className="server-card-rcon">
            <input
              type="text"
              value={command}
              placeholder="Comando RCON"
              onChange={(e) => setCommand(e.target.value)}
            />
            <LoadingButton
              type="button"
              variant="primary"
              onClick={() => onRcon(server.id, command)}
            >
              Enviar RCON
            </LoadingButton>
          </div>
        </>
      ) : (
        <div className="server-card-note">Somente administrador ou proprietário podem controlar este servidor.</div>
      )}
    </article>
  );
};

export default ServerCard;
