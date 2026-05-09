import React, { useEffect, useState } from 'react';
import { getSecurityEvents, getApiErrorMessage } from '../services/api';

const Security = () => {
  const [events, setEvents] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const loadEvents = async () => {
      try {
        const { data } = await getSecurityEvents();
        setEvents(data.events || []);
      } catch (err) {
        setError(getApiErrorMessage(err));
      } finally {
        setLoading(false);
      }
    };

    loadEvents();
  }, []);

  return (
    <div className="security-page">
      <h2>Segurança</h2>
      <p>Monitoramento de tentativas de login, bloqueios e eventos de autorização.</p>

      {loading && <p>Carregando eventos de segurança...</p>}
      {error && <p className="error">{error}</p>}

      {!loading && !error && (
        <section className="logs-page">
          {events.length === 0 ? (
            <p>Nenhum evento de segurança encontrado.</p>
          ) : (
            <pre className="logs-output">{events.join('\n')}</pre>
          )}
        </section>
      )}
    </div>
  );
};

export default Security;
