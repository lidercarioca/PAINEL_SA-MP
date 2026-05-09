import React, { useState } from 'react';
import LoadingButton from './LoadingButton';

const ServerCard = ({ server, ownerName, onAction, onRcon, onEdit, onDelete, canControl, isAdmin, actionLoading }) => {
  const [command, setCommand] = useState('');
  const serverGamemode = server.game_mode || server.gamemode || server.gamemode_name;

  const isLoading = (action) => actionLoading?.serverId === server.id && actionLoading?.action === action;

  const statusClass = ['online', 'offline', 'suspended', 'starting'].includes(String(server.status).toLowerCase())
    ? String(server.status).toLowerCase()
    : 'suspended';

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

  return (
    <div className="server-card">
      <h3>{server.name}</h3>
      <p>{server.ip}:{server.port}</p>
      <p>
        Status:{' '}
        <span className={`status-badge ${statusClass}`}>
          {getStatusLabel(server.status, server.ping)}
        </span>
      </p>
      {server.folder && <p>Pasta: {server.folder}</p>}
      {ownerName && <p>Cliente: {ownerName}</p>}
      {server.plan_name && <p>Plano: {server.plan_name}</p>}
      {serverGamemode && <p>Gamemode: {serverGamemode}</p>}

      {canControl ? (
        <>
          <div className="buttons">
            <LoadingButton
              type="button"
              variant="primary"
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
              variant="secondary"
              loading={isLoading('restart')}
              onClick={() => onAction(server.id, 'restart')}
            >
              Reiniciar
            </LoadingButton>
            {isAdmin && (
              <LoadingButton
                type="button"
                variant="secondary"
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

          <div className="rcon-form">
            <input
              type="text"
              value={command}
              placeholder="Comando RCON"
              onChange={(e) => setCommand(e.target.value)}
            />
            <button type="button" onClick={() => onRcon(server.id, command)}>Enviar RCON</button>
          </div>
        </>
      ) : (
        <p>Somente administrador ou proprietário podem controlar este servidor.</p>
      )}
    </div>
  );
};

export default ServerCard;
