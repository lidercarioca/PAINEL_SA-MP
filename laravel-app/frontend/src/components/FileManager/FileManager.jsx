import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import FileSidebar from './FileSidebar';
import FileList from './FileList';
import FileEditor from './FileEditor';
import LogsViewer from './LogsViewer';
import {
  compileSource,
  createFolder,
  deleteFile,
  downloadFile,
  fetchServers,
  getFileContent,
  getFiles,
  moveFile,
  renameFile,
  restartServerNow,
  uploadFiles,
  updateFile,
} from '../../services/api';

const ROOT_FOLDERS = ['scriptfiles', 'gamemodes', 'filterscripts', 'plugins', 'logs'];
const SAFE_UPLOAD_EXTENSIONS = ['.pwn', '.amx', '.cfg', '.ini', '.txt', '.json', '.yml', '.yaml', '.log', '.inc', '.dll', '.so'];
const CRITICAL_PATTERNS = ['server.cfg', 'server.ini', 'plugins/', 'gamemodes/', 'filterscripts/', 'scriptfiles/'];
const EDITABLE_EXTENSIONS = ['.pwn', '.cfg', '.ini', '.txt', '.json', '.yml', '.yaml', '.inc'];

const getExtension = (name) => `.${name.split('.').pop().toLowerCase()}`;
const isEditableFile = (name) => EDITABLE_EXTENSIONS.includes(getExtension(name));
const isCriticalFile = (relativePath) =>
  CRITICAL_PATTERNS.some((pattern) =>
    pattern.endsWith('/')
      ? relativePath.toLowerCase().startsWith(pattern)
      : relativePath.toLowerCase() === pattern
  );

const formatFileSize = (bytes) => {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${bytes} B`;
};

const FileManager = () => {
  const [servers, setServers] = useState([]);
  const [selectedServer, setSelectedServer] = useState(null);
  const [activePath, setActivePath] = useState('');
  const [items, setItems] = useState([]);
  const [selectedFile, setSelectedFile] = useState(null);
  const [fileContent, setFileContent] = useState('');
  const [selectedPaths, setSelectedPaths] = useState([]);
  const [filter, setFilter] = useState('');
  const [uploadFilesQueue, setUploadFilesQueue] = useState([]);
  const [newFolderName, setNewFolderName] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [compiling, setCompiling] = useState(false);
  const [restarting, setRestarting] = useState(false);
  const [logAutoRefresh, setLogAutoRefresh] = useState(false);

  const loadServers = useCallback(async () => {
    try {
      const { data } = await fetchServers();
      setServers(data || []);
      setSelectedServer(data?.[0] || null);
    } catch (err) {
      setError('Não foi possível carregar servidores.');
    }
  }, []);

  const loadDirectory = useCallback(
    async (path = '') => {
      if (!selectedServer) {
        return;
      }

      setLoading(true);
      setError('');
      setMessage('');
      setSelectedFile(null);
      setFileContent('');
      setSelectedPaths([]);

      try {
        const { data } = await getFiles(selectedServer.id, path);
        setItems(data.items || []);
        setActivePath(data.path || path);
      } catch (err) {
        setError('Não foi possível carregar o conteúdo da pasta.');
      } finally {
        setLoading(false);
      }
    },
    [selectedServer]
  );

  useEffect(() => {
    loadServers();
  }, [loadServers]);

  useEffect(() => {
    if (selectedServer) {
      setActivePath('');
      loadDirectory('');
      setFilter('');
    }
  }, [selectedServer, loadDirectory]);

  const filteredItems = useMemo(() => {
    if (!filter.trim()) return items;
    return items.filter((item) => item.name.toLowerCase().includes(filter.toLowerCase()));
  }, [items, filter]);

  const selectServer = (serverId) => {
    const server = servers.find((item) => item.id === serverId);
    setSelectedServer(server || null);
  };

  const openEntry = async (entry) => {
    if (entry.type === 'directory') {
      await loadDirectory(entry.path);
      return;
    }

    if (!selectedServer) {
      setError('Selecione um servidor antes de abrir um arquivo.');
      return;
    }

    setLoading(true);
    setError('');
    setSelectedFile(null);
    setFileContent('');

    try {
      const { data } = await getFileContent(selectedServer.id, entry.path);
      setSelectedFile({ ...entry, critical: isCriticalFile(entry.path) });
      setFileContent(data.content || '');
    } catch (err) {
      setError('Falha ao carregar o arquivo.');
    } finally {
      setLoading(false);
    }
  };

  const handleSelectEntry = async (entry) => {
    await openEntry(entry);
  };

  const uploadAllowed = (file) => {
    const extension = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.')).toLowerCase() : '';
    return SAFE_UPLOAD_EXTENSIONS.includes(extension);
  };

  const handleUploadFiles = async (event) => {
    const fileList = Array.from(event.target.files || []);
    if (!fileList.length) return;

    const filtered = fileList.filter((file) => uploadAllowed(file));
    if (!filtered.length) {
      setError('Apenas extensões seguras são permitidas para upload.');
      return;
    }

    const items = filtered.map((file) => ({
      file,
      path: (file.webkitRelativePath || file.name).replace(/^[\\/]+/, ''),
      id: `${Date.now()}-${file.name}`,
    }));

    setUploadFilesQueue((current) => [...current, ...items]);
    event.target.value = '';
  };

  const handleUploadSubmit = async (event) => {
    event.preventDefault();

    if (!uploadFilesQueue.length) {
      setError('Selecione arquivos para upload.');
      return;
    }
    if (!selectedServer) {
      setError('Selecione um servidor antes de enviar arquivos.');
      return;
    }

    const formData = new FormData();
    uploadFilesQueue.forEach((item) => {
      formData.append('files[]', item.file, item.path);
    });
    formData.append('server_id', selectedServer.id);
    if (activePath) {
      formData.append('root_folder', activePath);
    }

    try {
      setLoading(true);
      await uploadFiles(formData);
      setMessage('Upload concluído com sucesso.');
      setUploadFilesQueue([]);
      await loadDirectory(activePath);
    } catch (err) {
      setError('Falha durante o upload.');
    } finally {
      setLoading(false);
    }
  };

  const handleRemoveQueuedFile = (path) => {
    setUploadFilesQueue((current) => current.filter((item) => item.path !== path));
  };

  const handleSaveFile = async () => {
    if (!selectedServer || !selectedFile) {
      setError('Nenhum arquivo selecionado para salvar.');
      return;
    }

    if (!isEditableFile(selectedFile.name)) {
      setError('Este arquivo não pode ser editado no painel.');
      return;
    }

    setSaving(true);
    setError('');
    setMessage('');

    try {
      await updateFile(selectedServer.id, selectedFile.path, fileContent);
      setMessage('Alterações aplicadas com sucesso.');
    } catch (err) {
      setError('Falha ao salvar o arquivo.');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (entry) => {
    if (!selectedServer) return;
    const isCritical = isCriticalFile(entry.path);
    const confirmation = window.confirm(
      isCritical
        ? `Excluir ${entry.name} é perigoso e pode derrubar o servidor. Confirma a exclusão?`
        : `Excluir ${entry.name}?`
    );

    if (!confirmation) return;

    setLoading(true);
    setError('');
    setMessage('');

    try {
      await deleteFile(selectedServer.id, entry.path);
      setMessage('Arquivo excluído com sucesso.');
      if (selectedFile?.path === entry.path) {
        setSelectedFile(null);
        setFileContent('');
      }
      await loadDirectory(activePath);
    } catch (err) {
      setError('Falha ao excluir o arquivo.');
    } finally {
      setLoading(false);
    }
  };

  const handleDownload = async (entry) => {
    if (!selectedServer || !entry || entry.type !== 'file') return;
    setError('');
    setMessage('');

    try {
      const response = await downloadFile(selectedServer.id, entry.path);
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', entry.name);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
      setMessage(`Download de ${entry.name} iniciado.`);
    } catch (err) {
      setError('Falha ao baixar o arquivo.');
    }
  };

  const handleCreateFolder = async (folderName) => {
    if (!selectedServer || !folderName.trim()) return;

    setLoading(true);
    setError('');
    setMessage('');

    try {
      await createFolder(selectedServer.id, activePath, folderName.trim());
      setMessage(`Pasta ${folderName.trim()} criada com sucesso.`);
      await loadDirectory(activePath);
    } catch (err) {
      setError('Falha ao criar a pasta.');
    } finally {
      setLoading(false);
    }
  };

  const handleCreateFolderSubmit = async (event) => {
    event.preventDefault();
    if (!newFolderName.trim()) return;
    await handleCreateFolder(newFolderName.trim());
    setNewFolderName('');
  };

  const handleRename = async (entry) => {
    if (!selectedServer) return;
    const newName = window.prompt('Novo nome ou caminho relativo:', entry.path);
    if (!newName || newName.trim() === entry.path) return;

    setLoading(true);
    setError('');
    setMessage('');

    try {
      await renameFile(selectedServer.id, entry.path, newName.trim());
      setMessage('Arquivo renomeado com sucesso.');
      await loadDirectory(activePath);
      if (selectedFile?.path === entry.path) {
        setSelectedFile(null);
        setFileContent('');
      }
    } catch (err) {
      setError('Falha ao renomear o arquivo.');
    } finally {
      setLoading(false);
    }
  };

  const handleCompile = async () => {
    if (!selectedServer || !selectedFile) return;
    if (!selectedFile.name.toLowerCase().endsWith('.pwn')) {
      setError('Apenas arquivos .pwn podem ser compilados.');
      return;
    }

    setCompiling(true);
    setError('');
    setMessage('');

    try {
      await compileSource(selectedServer.id, selectedFile.path);
      setMessage('Compilação iniciada, verifique se o arquivo .amx foi criado.');
      await loadDirectory(activePath);
    } catch (err) {
      setError('Falha ao compilar o arquivo.');
    } finally {
      setCompiling(false);
    }
  };

  const handleRestartServer = async () => {
    if (!selectedServer) return;
    const confirmation = window.confirm('Reiniciar o servidor agora? Isso afetará todos os jogadores conectados.');
    if (!confirmation) return;

    setRestarting(true);
    setError('');
    setMessage('');

    try {
      await restartServerNow(selectedServer.id);
      setMessage('Servidor reiniciado com sucesso.');
    } catch (err) {
      setError('Falha ao reiniciar o servidor.');
    } finally {
      setRestarting(false);
    }
  };

  const handleToggleSelect = (path) => {
    setSelectedPaths((current) =>
      current.includes(path) ? current.filter((item) => item !== path) : [...current, path]
    );
  };

  const handleMultiDelete = async () => {
    if (!selectedServer || !selectedPaths.length) return;
    const confirmation = window.confirm(
      `Excluir ${selectedPaths.length} item(s)? Isso pode afetar o servidor.`
    );
    if (!confirmation) return;

    for (const path of selectedPaths) {
      await deleteFile(selectedServer.id, path);
    }

    setSelectedPaths([]);
    await loadDirectory(activePath);
    setMessage('Ações concluídas.');
  };

  const breadcrumbs = useMemo(() => {
    const segments = activePath ? activePath.split('/') : [];
    return [{ label: 'Raiz', path: '' }, ...segments.map((segment, index) => ({ label: segment, path: segments.slice(0, index + 1).join('/') }))];
  }, [activePath]);

  useEffect(() => {
    const handleKeyDown = (event) => {
      if (event.key === 'Delete' && selectedPaths.length) {
        handleMultiDelete();
      }
      if (event.key === 'F2' && selectedFile) {
        handleRename(selectedFile);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [selectedPaths, selectedFile]);

  return (
    <motion.div
      className="file-manager-shell"
      initial={{ opacity: 0, y: 24 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.45, ease: 'easeOut' }}
    >
      <motion.div
        className="file-manager-header"
        initial={{ opacity: 0, y: -16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35, delay: 0.05 }}
      >
        <div>
          <h2>Gerenciador de Arquivos SA-MP</h2>
          <p>Visualize, edite e proteja os scripts e configurações do servidor com segurança.</p>
        </div>
        <div className="file-manager-status">
          <span className={`status-badge ${selectedServer?.status === 'online' ? 'online' : 'offline'}`}>
            {selectedServer?.status?.toUpperCase() || 'OFFLINE'}
          </span>
          <button className="primary-button" onClick={handleRestartServer} disabled={!selectedServer || restarting}>
            {restarting ? 'Reiniciando…' : 'Reiniciar servidor'}
          </button>
        </div>
      </motion.div>

      <div className="file-manager-content">
        <FileSidebar
          server={selectedServer}
          servers={servers}
          activeFolder={activePath || ''}
          folders={ROOT_FOLDERS}
          onSelectServer={selectServer}
          onSelectFolder={(folder) => loadDirectory(folder)}
        />

        <div className="file-manager-main">
          <div className="file-manager-toolbar">
            <div className="search-box">
              <input
                type="text"
                placeholder="Pesquisar arquivos..."
                value={filter}
                onChange={(e) => setFilter(e.target.value)}
              />
            </div>

            <div className="upload-actions-bar">
              <label className="upload-button secondary-button">
                Selecionar arquivos
                <input type="file" multiple onChange={handleUploadFiles} hidden />
              </label>
              <button className="primary-button" onClick={handleUploadSubmit} disabled={!uploadFilesQueue.length || loading}>
                Enviar {uploadFilesQueue.length ? `(${uploadFilesQueue.length})` : ''}
              </button>
            </div>
          </div>

          {message && <div className="message success">{message}</div>}
          {error && <div className="message error">{error}</div>}

          {uploadFilesQueue.length > 0 && (
            <div className="upload-queue">
              <strong>Arquivos preparados para upload</strong>
              <div className="upload-queue-list">
                {uploadFilesQueue.map((item) => (
                  <div key={item.id} className="upload-queue-item">
                    <span>{item.path}</span>
                    <button className="secondary-button" type="button" onClick={() => handleRemoveQueuedFile(item.path)}>
                      Remover
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="file-manager-grid">
            <div className="file-manager-panel">
              <div className="breadcrumb-row">
                <div className="breadcrumb-links">
                  {breadcrumbs.map((crumb, index) => (
                    <button key={crumb.path || 'root'} className="breadcrumb-item" onClick={() => loadDirectory(crumb.path)}>
                      {crumb.label}
                    </button>
                  ))}
                </div>

                <form className="create-folder-inline" onSubmit={handleCreateFolderSubmit}>
                  <input
                    type="text"
                    placeholder="Nova pasta"
                    value={newFolderName}
                    onChange={(e) => setNewFolderName(e.target.value)}
                  />
                  <button className="secondary-button" type="submit" disabled={!selectedServer || !newFolderName.trim()}>
                    Criar pasta
                  </button>
                </form>
              </div>

              <FileList
                items={filteredItems}
                activePath={activePath}
                loading={loading}
                selectedFilePath={selectedFile?.path}
                selectedPaths={selectedPaths}
                onOpenEntry={handleSelectEntry}
                onToggleSelect={handleToggleSelect}
                onDownload={handleDownload}
                onRename={handleRename}
                onDelete={handleDelete}
                onCompile={handleCompile}
              />
            </div>

            <div className="file-manager-panel file-editor-panel">
              <FileEditor
                file={selectedFile}
                content={fileContent}
                onChange={setFileContent}
                loading={loading}
                saving={saving}
                compiling={compiling}
                onSave={handleSaveFile}
                onCompile={handleCompile}
                serverStatus={selectedServer?.status}
                isEditable={selectedFile ? isEditableFile(selectedFile.name) : false}
                critical={selectedFile ? selectedFile.critical : false}
              />

              <LogsViewer serverId={selectedServer?.id} live={logAutoRefresh} onToggleLive={() => setLogAutoRefresh((current) => !current)} />
            </div>
          </div>
        </div>
      </div>
    </motion.div>
  );
};

export default FileManager;
