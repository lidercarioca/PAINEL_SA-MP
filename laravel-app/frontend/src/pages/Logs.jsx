import React, { useEffect, useState } from 'react';
import api from '../services/api';

const Logs = () => {
  const [lines, setLines] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const loadLogs = async () => {
      try {
        const { data } = await api.get('/logs');
        setLines(data.lines || []);
      } catch (err) {
        setError('Não foi possível carregar os logs.');
      } finally {
        setLoading(false);
      }
    };

    loadLogs();
  }, []);

  return (
    <div className="logs-page">
      <h2>Logs do Painel</h2>
      {loading && <p>Carregando logs...</p>}
      {error && <p className="error">{error}</p>}
      {!loading && !error && (
        <pre className="logs-output">
          {lines.length > 0 ? lines.join('\n') : 'Nenhum log encontrado.'}
        </pre>
      )}
    </div>
  );
};

export default Logs;
