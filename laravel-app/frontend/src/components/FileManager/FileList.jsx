import React from 'react';
import { motion } from 'framer-motion';

const iconForType = (item) => {
  if (item.type === 'directory') return '📁';
  const extension = item.name.split('.').pop().toLowerCase();
  if (extension === 'pwn') return '📜';
  if (extension === 'amx') return '⚙️';
  if (extension === 'cfg') return '🛠️';
  if (extension === 'log') return '📝';
  if (extension === 'inc') return '📌';
  return '📄';
};

const FileList = ({
  items,
  loading,
  selectedFilePath,
  selectedPaths,
  onOpenEntry,
  onToggleSelect,
  onDownload,
  onRename,
  onDelete,
  onCompile,
}) => {
  return (
    <div className="file-list-panel">
      <div className="file-list-header">
        <h3>Conteúdo</h3>
        <span>{items.length} item(s)</span>
      </div>

      {loading ? (
        <div className="empty-state">Carregando arquivos…</div>
      ) : items.length === 0 ? (
        <div className="empty-state">Nenhum arquivo ou pasta encontrado.</div>
      ) : (
        <motion.ul className="files-list-items" layout>
          {items.map((item) => (
            <motion.li
              key={item.path}
              layout
              initial={{ opacity: 0, scale: 0.98, y: 8 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              transition={{ duration: 0.25 }}
              className={selectedFilePath === item.path ? 'active' : ''}
            >
              <div className="item-row">
                <div className="item-left">
                  <input
                    type="checkbox"
                    checked={selectedPaths.includes(item.path)}
                    onChange={() => onToggleSelect(item.path)}
                  />
                  <button className="item-name" onClick={() => onOpenEntry(item)}>
                    <span className="item-icon">{iconForType(item)}</span>
                    <span>{item.name}</span>
                    {item.critical && <span className="badge critical">Crítico</span>}
                  </button>
                </div>

                <div className="item-actions">
                  {item.type === 'file' && (
                    <button className="secondary-button" onClick={() => onDownload(item)}>
                      Baixar
                    </button>
                  )}
                  {item.type === 'file' && item.name.toLowerCase().endsWith('.pwn') && (
                    <button className="secondary-button" onClick={() => onCompile(item)}>
                      Compilar
                    </button>
                  )}
                  <button className="secondary-button" onClick={() => onRename(item)}>
                    Renomear
                  </button>
                  <button className="danger-button" onClick={() => onDelete(item)}>
                    Excluir
                  </button>
                </div>
              </div>
            </motion.li>
          ))}
        </motion.ul>
      )}
    </div>
  );
};

export default FileList;
