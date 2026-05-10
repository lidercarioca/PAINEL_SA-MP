import React, { useEffect, useState } from 'react';
import {
  fetchUsers,
  createUser,
  updateUser,
  resetUserPassword,
  toggleBlockUser,
  deleteUser,
  getUserActivity,
  getApiErrorMessage,
} from '../services/api';

const Users = () => {
  const [users, setUsers] = useState([]);
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(true);
  const [selectedUser, setSelectedUser] = useState(null);
  const [activity, setActivity] = useState([]);
  const [newUser, setNewUser] = useState({
    name: '',
    email: '',
    password: '',
    role: 'client',
  });

  const loadUsers = async () => {
    setLoading(true);
    try {
      const { data } = await fetchUsers();
      setUsers(data);
    } catch (error) {
      setMessage(getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadUsers();
  }, []);

  const handleCreate = async (event) => {
    event.preventDefault();
    try {
      await createUser(newUser);
      setMessage('Usuário criado com sucesso.');
      setNewUser({ name: '', email: '', password: '', role: 'client' });
      loadUsers();
    } catch (error) {
      setMessage(getApiErrorMessage(error));
    }
  };

  const handleToggleBlock = async (userId) => {
    try {
      await toggleBlockUser(userId);
      setMessage('Status de bloqueio atualizado.');
      loadUsers();
    } catch (error) {
      setMessage(getApiErrorMessage(error));
    }
  };

  const handleResetPassword = async (userId) => {
    const password = window.prompt('Digite a nova senha:');
    if (!password) {
      return;
    }

    try {
      await resetUserPassword(userId, password);
      setMessage('Senha redefinida com sucesso.');
      loadUsers();
    } catch (error) {
      setMessage(getApiErrorMessage(error));
    }
  };

  const handleDelete = async (userId) => {
    if (!window.confirm('Deseja excluir este usuário?')) {
      return;
    }

    try {
      await deleteUser(userId);
      setMessage('Usuário excluído.');
      if (selectedUser?.id === userId) {
        setSelectedUser(null);
        setActivity([]);
      }
      loadUsers();
    } catch (error) {
      setMessage(getApiErrorMessage(error));
    }
  };

  const handleViewActivity = async (user) => {
    setSelectedUser(user);
    try {
      const { data } = await getUserActivity(user.id);
      setActivity(data.activity || []);
      setMessage('');
    } catch (error) {
      setMessage(getApiErrorMessage(error));
    }
  };

  const handleUpdate = async (userId) => {
    const user = users.find((item) => item.id === userId);
    if (!user) return;

    const name = window.prompt('Novo nome:', user.name);
    const email = window.prompt('Novo e-mail:', user.email);
    const role = window.prompt('Papel (admin/client):', user.role);
    if (!name || !email || !role) {
      return;
    }

    try {
      await updateUser(userId, { name, email, role });
      setMessage('Usuário atualizado com sucesso.');
      loadUsers();
    } catch (error) {
      setMessage(getApiErrorMessage(error));
    }
  };

  const totalUsers = users.length;
  const adminCount = users.filter((item) => item.role === 'admin').length;
  const clientCount = users.filter((item) => item.role === 'client').length;

  return (
    <div className="users-page">
      <div className="users-header">
        <div>
          <p className="section-overline">Usuários</p>
          <h1>Gerenciar usuários</h1>
          <p className="users-subtitle">Crie, edite e controle acessos dos usuários do painel</p>
        </div>
        {message && <div className="servers-message">{message}</div>}
      </div>

      <div className="users-stats-grid">
        <div className="user-stat-card">
          <span className="stat-label">Total de contas</span>
          <h3>{totalUsers}</h3>
          <p>Contas ativas e pendentes de gestão.</p>
        </div>
        <div className="user-stat-card">
          <span className="stat-label">Admins</span>
          <h3>{adminCount}</h3>
          <p>Usuários com acesso completo ao painel.</p>
        </div>
        <div className="user-stat-card">
          <span className="stat-label">Clientes</span>
          <h3>{clientCount}</h3>
          <p>Usuários com acesso restrito aos próprios servidores.</p>
        </div>
      </div>

      <div className="users-grid">
        <section className="card user-form-card">
          <div className="card-header">
            <div>
              <h3>Criar nova conta</h3>
              <small>Preencha os dados para adicionar um usuário ao painel.</small>
            </div>
          </div>

          <form className="user-form-grid" onSubmit={handleCreate}>
            <div className="form-field">
              <label htmlFor="new-user-name">Nome</label>
              <input
                id="new-user-name"
                type="text"
                placeholder="Nome"
                value={newUser.name}
                onChange={(e) => setNewUser({ ...newUser, name: e.target.value })}
                required
              />
            </div>
            <div className="form-field">
              <label htmlFor="new-user-email">E-mail</label>
              <input
                id="new-user-email"
                type="email"
                placeholder="E-mail"
                value={newUser.email}
                onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
                required
              />
            </div>
            <div className="form-field">
              <label htmlFor="new-user-password">Senha</label>
              <input
                id="new-user-password"
                type="password"
                placeholder="Senha"
                value={newUser.password}
                onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
                required
              />
            </div>
            <div className="form-field">
              <label htmlFor="new-user-role">Tipo de conta</label>
              <select
                id="new-user-role"
                value={newUser.role}
                onChange={(e) => setNewUser({ ...newUser, role: e.target.value })}
              >
                <option value="client">Cliente</option>
                <option value="admin">Administrador</option>
              </select>
            </div>
            <div className="form-actions-row" style={{ gridColumn: '1 / -1' }}>
              <button type="submit" className="button primary-button">
                Criar usuário
              </button>
            </div>
          </form>
        </section>

        <section className="card users-list-card">
          <div className="card-header">
            <div>
              <h3>Contas</h3>
              <small>Gerencie permissões e ações de cada usuário cadastrado.</small>
            </div>
          </div>

          {loading ? (
            <div className="empty-state">Carregando usuários...</div>
          ) : users.length === 0 ? (
            <div className="empty-state">Nenhum usuário encontrado.</div>
          ) : (
            <div className="users-table-wrapper">
              <table className="users-table">
                <thead>
                  <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Função</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((userItem) => (
                    <tr key={userItem.id}>
                      <td data-label="Nome">
                        <strong>{userItem.name || '—'}</strong>
                        <div className="table-note">ID {userItem.id}</div>
                      </td>
                      <td data-label="E-mail">{userItem.email}</td>
                      <td data-label="Função">
                        <span className={`user-role-badge ${userItem.role}`}>
                          {userItem.role === 'admin' ? 'Administrador' : 'Cliente'}
                        </span>
                      </td>
                      <td data-label="Status">
                        <span className={`user-status-badge ${userItem.blocked ? 'blocked' : 'active'}`}>
                          {userItem.blocked ? 'Bloqueado' : 'Ativo'}
                        </span>
                      </td>
                      <td data-label="Data">{userItem.created_at ? new Date(userItem.created_at).toLocaleDateString('pt-BR') : '—'}</td>
                      <td data-label="Ações">
                        <div className="user-actions">
                          <button type="button" className="button secondary-button" onClick={() => handleViewActivity(userItem)}>
                            Atividade
                          </button>
                          <button type="button" className="button secondary-button" onClick={() => handleResetPassword(userItem.id)}>
                            Resetar senha
                          </button>
                          <button type="button" className="button secondary-button" onClick={() => handleToggleBlock(userItem.id)}>
                            {userItem.blocked ? 'Desbloquear' : 'Bloquear'}
                          </button>
                          <button type="button" className="button secondary-button" onClick={() => handleUpdate(userItem.id)}>
                            Editar
                          </button>
                          <button type="button" className="button danger-button" onClick={() => handleDelete(userItem.id)}>
                            Excluir
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>

      {selectedUser && (
        <section className="card user-activity-card">
          <div className="card-header">
            <div>
              <h3>Atividade de {selectedUser.name}</h3>
              <small>Histórico de ações recentes do usuário.</small>
            </div>
          </div>
          {activity.length === 0 ? (
            <div className="empty-state">Nenhuma atividade encontrada para este usuário.</div>
          ) : (
            <pre className="logs-output">{activity.join('\n')}</pre>
          )}
        </section>
      )}
    </div>
  );
};

export default Users;
