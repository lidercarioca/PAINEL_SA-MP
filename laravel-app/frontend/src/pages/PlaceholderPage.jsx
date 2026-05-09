import React from 'react';

const PlaceholderPage = ({ title, description }) => {
  return (
    <div className="placeholder-page">
      <header className="page-header">
        <h2>{title}</h2>
        <p>{description || 'Esta página ainda não foi totalmente implementada, mas a rota já está disponível.'}</p>
      </header>
      <section className="placeholder-content">
        <div className="placeholder-card">
          <p>Funcionalidade em desenvolvimento.</p>
          <p>Se quiser, posso integrar este painel com o backend e os dados reais.</p>
        </div>
      </section>
    </div>
  );
};

export default PlaceholderPage;
