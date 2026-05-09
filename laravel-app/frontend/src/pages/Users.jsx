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

  return (
    <div className="users-page">
      <h2>Gerenciar usuários</h2>
      {message && <div className="message">{message}</div>}

      <section className="user-form-section">
        <h3>Criar nova conta</h3>
        <form className="server-form" onSubmit={handleCreate}>
          <input
            type="text"
            placeholder="Nome"
            value={newUser.name}
            onChange={(e) => setNewUser({ ...newUser, name: e.target.value })}
            required
          />
          <input
            type="email"
            placeholder="E-mail"
            value={newUser.email}
            onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
            required
          />
          <input
            type="password"
            placeholder="Senha"
            value={newUser.password}
            onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
            required
          />
          <select
            value={newUser.role}
            onChange={(e) => setNewUser({ ...newUser, role: e.target.value })}
          >
            <option value="client">Cliente</option>
            <option value="admin">Administrador</option>
          </select>
          <button type="submit">Criar usuário</button>
        </form>
      </section>

      <section className="user-list-section">
        <h3>Contas</h3>
        {loading ? (
          <p>Carregando usuários...</p>
        ) : (
          <ul className="user-list">
            {users.map((user) => (
              <li key={user.id}>
                <div>
                  <strong>{user.name}</strong> ({user.email})
                  <br />
                  <small>{user.role} {user.blocked ? '• bloqueado' : ''}</small>
                </div>
                <div className="user-actions">
                  <button type="button" onClick={() => handleViewActivity(user)}>Atividade</button>
                  <button type="button" onClick={() => handleResetPassword(user.id)}>Resetar senha</button>
                  <button type="button" onClick={() => handleToggleBlock(user.id)}>
                    {user.blocked ? 'Desbloquear' : 'Bloquear'}
                  </button>
                  <button type="button" onClick={() => handleUpdate(user.id)}>Editar</button>
                  <button type="button" className="delete-button" onClick={() => handleDelete(user.id)}>Excluir</button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      {selectedUser && (
        <section className="user-activity-section">
          <h3>Atividade de {selectedUser.name}</h3>
          {activity.length === 0 ? (
            <p>Nenhuma atividade encontrada para este usuário.</p>
          ) : (
            <pre className="logs-output">{activity.join('\n')}</pre>
          )}
        </section>
      )}
    </div>
  );
};

export default Users;
