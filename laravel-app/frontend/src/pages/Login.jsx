import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { login, getApiErrorMessage } from '../services/api';

const Login = () => {
  const [email, setEmail] = useState('admin@example.com');
  const [password, setPassword] = useState('admin123');
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (event) => {
    event.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await login(email, password);
      const token = response.data.token;
      const user = response.data.user;
      localStorage.setItem('auth_token', token);
      localStorage.setItem('auth_user', JSON.stringify(user));
      navigate('/dashboard');
    } catch (err) {
      setError(getApiErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-screen">
      <div className="login-panel">
        <div className="login-brand">
          <span className="login-badge">SA-MP / FiveM Panel</span>
          <h1>Entrar no painel</h1>
          <p>Acesse sua conta para gerenciar seus servidores</p>
        </div>

        <form onSubmit={handleSubmit} className="login-form">
          {error && <div className="login-error">{error}</div>}

          <div className="login-field">
            <label htmlFor="login-email">Email</label>
            <div className="input-with-icon">
              <span className="input-icon">@</span>
              <input
                id="login-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="Digite seu e-mail"
                required
                disabled={loading}
              />
            </div>
          </div>

          <div className="login-field">
            <label htmlFor="login-password">Senha</label>
            <div className="input-with-icon">
              <span className="input-icon">🔒</span>
              <input
                id="login-password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Digite sua senha"
                required
                disabled={loading}
              />
            </div>
          </div>

          <button type="submit" className="button primary-button login-submit" disabled={loading}>
            {loading ? 'Entrando...' : 'Entrar'}
          </button>

          <div className="login-footer">
            <a href="#" onClick={(event) => event.preventDefault()} className="login-forgot">
              Esqueci minha senha
            </a>
            <span className="login-version">v{process.env.REACT_APP_VERSION || '1.0.0'}</span>
          </div>
        </form>
      </div>
    </div>
  );
};

export default Login;
