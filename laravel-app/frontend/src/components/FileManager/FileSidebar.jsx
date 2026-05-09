import React from 'react';
import { motion } from 'framer-motion';

const icons = {
  scriptfiles: '🧾',
  gamemodes: '🎮',
  filterscripts: '🧩',
  plugins: '🔌',
  logs: '📄',
};

const FileSidebar = ({ server, servers, activeFolder, folders, onSelectServer, onSelectFolder }) => {
  return (
    <motion.aside
      className="file-sidebar"
      initial={{ opacity: 0, x: -14 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.35 }}
    >
      <div className="sidebar-card">
        <div className="sidebar-header">
          <div>
            <h3>Servidor</h3>
            <p>{server?.name || 'Nenhum servidor selecionado'}</p>
          </div>
          <span className={`status-pill ${server?.status === 'online' ? 'online' : 'offline'}`}>
            {server?.status === 'online' ? 'ONLINE' : 'OFFLINE'}
          </span>
        </div>

        <select value={server?.id || ''} onChange={(e) => onSelectServer(Number(e.target.value))}>
          <option value="">Selecionar servidor</option>
          {servers.map((item) => (
            <option key={item.id} value={item.id}>
              {item.name}
            </option>
          ))}
        </select>
      </div>

      <div className="sidebar-card">
        <h4>Pastas principais</h4>
        <nav className="sidebar-links">
          <button className={activeFolder === '' ? 'active' : ''} onClick={() => onSelectFolder('')}>
            Raiz
          </button>
          {folders.map((folder) => (
            <button
              key={folder}
              className={activeFolder === folder ? 'active' : ''}
              onClick={() => onSelectFolder(folder)}
            >
              <span className="folder-icon">{icons[folder] || '📁'}</span>
              {folder}
            </button>
          ))}
        </nav>
      </div>

    </motion.aside>
  );
};

export default FileSidebar;
