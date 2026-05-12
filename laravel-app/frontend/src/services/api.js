import axios from 'axios';

const api = axios.create({
  baseURL: process.env.REACT_APP_API_URL || '/api',
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export const getApiErrorMessage = (error) => {
  if (error.response?.data) {
    return error.response.data.error || error.response.data.message || error.message;
  }

  return error.message || 'Erro de rede.';
};

export const login = (email, password) => api.post('/login', { email, password });
export const logout = () => api.post('/logout');
export const fetchServers = () => api.get('/servers', { params: { t: Date.now() } });
export const createServer = (data) => api.post('/servers/create', data);
export const deleteServer = (serverId) => api.post('/servers/delete', { server_id: serverId });
export const startServer = (serverId) => api.post('/servers/start', { server_id: serverId });
export const stopServer = (serverId) => api.post('/servers/stop', { server_id: serverId });
export const restartServer = (serverId) => api.post('/servers/restart', { server_id: serverId });
export const sendServerCommand = (serverId, command, mode = 'local', sshUser = '', sshPassword = '') =>
  api.post('/servers/command', { server_id: serverId, command, mode, ssh_user: sshUser, ssh_password: sshPassword });
export const sendRconCommand = (serverId, command) => api.post('/rcon/send', { server_id: serverId, command });
export const fetchLogs = (serverId, file = 'server_log.txt') =>
  api.get('/logs', { params: { server_id: serverId, file } });
export const fetchServerConsoleStream = (serverId, offset = 0) =>
  api.get(`/servers/${serverId}/console-stream`, { params: { offset } });
export const fetchPlayers = (serverId) => api.get(`/servers/${serverId}/players`);
export const fetchServerStats = (serverId) => api.get(`/servers/${serverId}/stats`);
export const refreshServerStatus = (serverId) => api.get(`/servers/${serverId}/status`);
export const createBackup = (serverId) => api.post(`/servers/${serverId}/backup`);
export const fetchBackups = (serverId) => api.get(`/servers/${serverId}/backups`);
export const deleteBackup = (serverId, backupName) => api.delete(`/servers/${serverId}/backups/${encodeURIComponent(backupName)}`);
export const downloadBackup = (serverId, backupName) =>
  api.get(`/servers/${serverId}/backups/${encodeURIComponent(backupName)}`, { responseType: 'blob' });
export const downloadBackupUrl = (serverId, backupName) => `/api/servers/${serverId}/backups/${encodeURIComponent(backupName)}`;
export const updateServer = (data) => api.post('/servers/update', data);
export const getFiles = (serverId, path = '') =>
  api.get('/files', { params: { server_id: serverId, path } });
export const getFileContent = (serverId, name) =>
  api.get(`/files/${encodeURIComponent(name)}`, { params: { server_id: serverId } });
export const downloadFile = (serverId, name) =>
  api.get(`/files/${encodeURIComponent(name)}`, {
    params: { server_id: serverId, download: 1 },
    responseType: 'blob',
  });
export const updateFile = (serverId, filePath, content) =>
  api.put(`/files/${encodeURIComponent(filePath)}`, { server_id: serverId, content });
export const deleteFile = (serverId, name) =>
  api.delete(`/files/${encodeURIComponent(name)}`, { params: { server_id: serverId } });
export const uploadFiles = (formData) => api.post('/upload', formData);
export const createFolder = (serverId, path, folderName) =>
  api.post('/files/folder', { server_id: serverId, path, folder_name: folderName });
export const renameFile = (serverId, oldPath, newPath) =>
  api.post('/rename', { server_id: serverId, old_path: oldPath, new_path: newPath });
export const moveFile = (serverId, source, destination) =>
  api.post('/move', { server_id: serverId, source, destination });
export const compileSource = (serverId, sourcePath) =>
  api.post('/compile', { server_id: serverId, source_path: sourcePath });
export const restartServerNow = (serverId) =>
  api.post('/restart-server', { server_id: serverId });
export const getResources = () => api.get('/resources');
export const suspendServer = (serverId) => api.post('/servers/suspend', { server_id: serverId });
export const fetchUsers = () => api.get('/users');
export const createUser = (data) => api.post('/users', data);
export const updateUser = (id, data) => api.put(`/users/${id}`, data);
export const resetUserPassword = (id, password) => api.post(`/users/${id}/reset-password`, { password });
export const toggleBlockUser = (id) => api.post(`/users/${id}/toggle-block`);
export const deleteUser = (id) => api.delete(`/users/${id}`);
export const getUserActivity = (id) => api.get(`/users/${id}/activity`);
export const getSecurityEvents = () => api.get('/security');
export const fetchPlans = () => api.get('/plans');
export const createPlan = (data) => api.post('/plans', data);
export const updatePlan = (id, data) => api.put(`/plans/${id}`, data);
export const deletePlan = (id) => api.delete(`/plans/${id}`);

// FiveM Resources
export const fetchFiveMResources = (serverId) => api.get(`/servers/${serverId}/fivem/resources`);
export const executeFiveMResourceAction = (serverId, resourceName, action) =>
  api.post(`/servers/${serverId}/fivem/resources/${action}`, { resource: resourceName });

export default api;
