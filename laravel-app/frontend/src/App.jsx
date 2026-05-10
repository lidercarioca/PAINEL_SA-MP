import React from 'react';
import { createBrowserRouter, RouterProvider, Navigate, Outlet, NavLink, useLocation, useNavigate } from 'react-router-dom';
import {
  Home,
  HardDrive,
  Plus,
  Users,
  Shield,
  Settings,
  Puzzle,
  FileText,
  MessageCircle,
  LogOut,
  Menu,
  X,
  TrendingUp,
} from 'lucide-react';
import Dashboard from './pages/Dashboard';
import Login from './pages/Login';
import Logs from './pages/Logs';
import Console from './pages/Console';
import Files from './pages/Files';
import UsersPage from './pages/Users';
import Security from './pages/Security';
import Finance from './pages/Finance';
import Servers from './pages/Servers';
import CreateServer from './pages/CreateServer';
import PlaceholderPage from './pages/PlaceholderPage';
import { logout, getApiErrorMessage } from './services/api';

const router = createBrowserRouter(
  [
    {
      path: '/',
      element: <NavigateToRoot />,
    },
    {
      path: '/login',
      element: <Login />,
    },
    {
      path: '/',
      element: <RootLayout />,
      children: [
        {
          path: 'dashboard',
          element: <Dashboard />,
        },
        {
          path: 'server',
          element: <Servers />,
        },
        {
          path: 'create-server',
          element: (
            <RequireAdmin>
              <CreateServer />
            </RequireAdmin>
          ),
        },
        {
          path: 'console',
          element: <PlaceholderPage title="Console" description="Visualize os logs e a saída do servidor." />,
        },
        {
          path: 'console/:serverId',
          element: <Console />,
        },
        {
          path: 'players',
          element: <PlaceholderPage title="Jogadores" description="Gerencie jogadores online e offline." />,
        },
        {
          path: 'banned',
          element: <PlaceholderPage title="Banidos" description="Lista de jogadores banidos e ações de unban." />,
        },
        {
          path: 'settings',
          element: <PlaceholderPage title="Configurações" description="Ajustes e configurações do servidor." />,
        },
        {
          path: 'files',
          element: <Files />,
        },
        {
          path: 'backups',
          element: <PlaceholderPage title="Backups" description="Gerenciamento de backups do servidor." />,
        },
        {
          path: 'scheduler',
          element: <PlaceholderPage title="Agendador" description="Agende tarefas automáticas para o servidor." />,
        },
        {
          path: 'database',
          element: <PlaceholderPage title="Banco de Dados" description="Acesso e gerenciamento do banco de dados." />,
        },
        {
          path: 'plugins',
          element: <PlaceholderPage title="Plugins" description="Gerencie plugins e scripts do servidor." />,
        },
        {
          path: 'ports',
          element: <PlaceholderPage title="Portas" description="Configuração de portas e regras de rede." />,
        },
        {
          path: 'subdomains',
          element: <PlaceholderPage title="Subdomínios" description="Configuração de subdomínios e DNS." />,
        },
        {
          path: 'tools',
          element: <PlaceholderPage title="Ferramentas" description="Utilitários adicionais do painel." />,
        },
        {
          path: 'users',
          element: <UsersPage />,
        },
        {
          path: 'security',
          element: <Security />,
        },
        {
          path: 'finance',
          element: <Finance />,
        },
        {
          path: 'logs',
          element: (
            <RequireAdmin>
              <Logs />
            </RequireAdmin>
          ),
        },
      ],
    },
    {
      path: '*',
      element: <Navigate to="/" replace />,
    },
  ],
  {
    basename: '/',
    future: {
      v7_startTransition: true,
      v7_relativeSplatPath: true,
    },
  }
);

function RequireAdmin({ children }) {
  const [user, setUser] = React.useState(null);

  React.useEffect(() => {
    const authUser = localStorage.getItem('auth_user');
    if (authUser) {
      try {
        setUser(JSON.parse(authUser));
      } catch {
        setUser(null);
      }
    } else {
      setUser(null);
    }
  }, []);

  if (user === null) {
    return null;
  }

  if (user?.role !== 'admin') {
    return <Navigate to="/dashboard" replace />;
  }

  return children;
}

function RootLayout() {
  const [user, setUser] = React.useState(null);
  const [sidebarOpen, setSidebarOpen] = React.useState(true);
  const location = useLocation();
  const navigate = useNavigate();

  React.useEffect(() => {
    const authUser = localStorage.getItem('auth_user');
    if (authUser) {
      try {
        setUser(JSON.parse(authUser));
      } catch {
        setUser(null);
      }
    }
  }, []);

  const handleLogout = async () => {
    try {
      await logout();
      localStorage.removeItem('auth_token');
      localStorage.removeItem('auth_user');
      navigate('/login');
    } catch (error) {
      console.warn('Logout falhou:', error);
      // Mesmo se falhar, limpa dados locais e redireciona
      localStorage.removeItem('auth_token');
      localStorage.removeItem('auth_user');
      navigate('/login');
    }
  };

  const getBreadcrumbs = () => {
    const path = location.pathname;
    const pathMap = {
      '/dashboard': { label: 'Dashboard', parent: null },
      '/server': { label: 'Servidores', parent: 'Geral' },
      '/files': { label: 'Arquivos', parent: 'Geral' },
      '/console': { label: 'Console', parent: 'Configurações' },
      '/players': { label: 'Jogadores', parent: 'Geral' },
      '/banned': { label: 'Banidos', parent: 'Suporte' },
      '/settings': { label: 'Configurações', parent: 'Configurações' },
      '/logs': { label: 'Logs', parent: 'Configurações' },
      '/users': { label: 'Usuários', parent: 'Usuários' },
      '/security': { label: 'Segurança', parent: 'Usuários' },
      '/finance': { label: 'Planos', parent: 'Financeiro' },
    };

    if (path.startsWith('/console')) {
      return { label: 'Console', parent: 'Configurações' };
    }

    return pathMap[path] || { label: 'Página', parent: null };
  };

  const breadcrumb = getBreadcrumbs();

  const menuGroups = [
    {
      label: 'GERAL',
      items: [
        { path: '/dashboard', label: 'Dashboard', icon: Home },
        { path: '/server', label: 'Servidores', icon: HardDrive },
        { path: '/create-server', label: 'Criar Servidor', icon: Plus, adminOnly: true },
        { path: '/dashboard', label: 'Backups', icon: HardDrive, adminOnly: true },
      ],
    },
    {
      label: 'USUÁRIOS',
      items: [
        { path: '/users', label: 'Usuários', icon: Users, adminOnly: true },
        { path: '/dashboard', label: 'Subusuários', icon: Users, adminOnly: true },
        { path: '/security', label: 'Permissões', icon: Shield, adminOnly: true },
      ],
    },
    {
      label: 'CONFIGURAÇÕES',
      items: [
        { path: '/settings', label: 'Configurações', icon: Settings, adminOnly: true },
        { path: '/dashboard', label: 'Plugins', icon: Puzzle, adminOnly: true },
        { path: '/logs', label: 'Logs', icon: FileText, adminOnly: true },
      ],
    },
    {
      label: 'FINANCEIRO',
      items: [
        { path: '/finance', label: 'Planos', icon: TrendingUp, adminOnly: true },
      ],
    },
    {
      label: 'SUPORTE',
      items: [
        { path: '/dashboard', label: 'Tickets', icon: MessageCircle },
        { path: '/dashboard', label: 'Abrir Ticket', icon: Plus },
      ],
    },
  ];

  return (
    <div className="app-layout">
      <aside className={`app-sidebar ${sidebarOpen ? 'open' : 'closed'}`}>
        <div className="sidebar-header">
          <div className="sidebar-brand">
            <div className="sidebar-logo">SA-MP</div>
            <div className="sidebar-title">PAINEL</div>
          </div>
          <button
            className="sidebar-toggle"
            onClick={() => setSidebarOpen(!sidebarOpen)}
            aria-label="Toggle sidebar"
          >
            {sidebarOpen ? <X size={20} /> : <Menu size={20} />}
          </button>
        </div>

        <nav className="app-nav">
          {menuGroups.map((group) => (
            <div key={group.label} className="nav-group">
              <div className="nav-group-label">{group.label}</div>
              {group.items
                .filter((item) => !item.adminOnly || user?.role === 'admin')
                .map((item) => (
                  <NavLink
                    key={item.path + item.label}
                    to={item.path}
                    title={!sidebarOpen ? item.label : undefined}
                    data-tooltip={item.label}
                    aria-label={item.label}
                    className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}
                  >
                    <item.icon size={18} className="nav-icon" />
                    <span className="nav-label">{item.label}</span>
                  </NavLink>
                ))}
            </div>
          ))}
        </nav>
      </aside>

      <div className="app-main">
        <header className="app-topbar">
          <div className="topbar-left">
            <button
              className="topbar-toggle"
              onClick={() => setSidebarOpen(!sidebarOpen)}
              aria-label="Toggle sidebar"
            >
              <Menu size={24} />
            </button>
            <div className="breadcrumb">
              <span className="breadcrumb-label">Dashboard</span>
              <span className="breadcrumb-separator">/</span>
              <span className="breadcrumb-current">{breadcrumb.label}</span>
            </div>
          </div>
          <div className="topbar-right">
            <div className="topbar-user-info">
              <span className="topbar-greeting">Olá, {user?.name || 'Usuário'}</span>
              <div className="topbar-avatar">{user?.name?.charAt(0) || '?'}</div>
            </div>
            <button
              className="topbar-logout-btn"
              onClick={handleLogout}
              title="Sair"
              aria-label="Logout"
            >
              <LogOut size={20} />
              <span className="logout-text">Sair</span>
            </button>
          </div>
        </header>

        <main className="app-content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}

function NavigateToRoot() {
  const token = localStorage.getItem('auth_token');
  return token ? <Navigate to="/dashboard" /> : <Navigate to="/login" />;
}

function App() {
  return (
    <RouterProvider
      router={router}
      future={{
        v7_startTransition: true,
        v7_relativeSplatPath: true,
      }}
    />
  );
}

export default App;
