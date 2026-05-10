import React, { useEffect, useState } from 'react';
import { fetchPlans, createPlan, updatePlan, deletePlan, getApiErrorMessage } from '../services/api';

const Finance = () => {
  const [plans, setPlans] = useState([]);
  const [message, setMessage] = useState(null);
  const [newPlan, setNewPlan] = useState({ name: '', slots: '', price: '', description: '' });

  useEffect(() => {
    loadPlans();
  }, []);

  const loadPlans = async () => {
    try {
      const { data } = await fetchPlans();
      setPlans(data);
    } catch (error) {
      setMessage(getApiErrorMessage(error) || 'Falha ao carregar planos.');
    }
  };

  const handleCreatePlan = async (event) => {
    event.preventDefault();
    try {
      await createPlan({
        ...newPlan,
        slots: Number(newPlan.slots),
        price: Number(newPlan.price),
      });
      setMessage('Plano criado com sucesso.');
      setNewPlan({ name: '', slots: '', price: '', description: '' });
      loadPlans();
    } catch (error) {
      setMessage(getApiErrorMessage(error) || 'Erro ao criar plano.');
    }
  };

  const handleEditPlan = async (plan) => {
    const name = window.prompt('Nome do plano:', plan.name);
    const slots = window.prompt('Slots do plano:', plan.slots);
    const price = window.prompt('Preço do plano:', plan.price);
    const description = window.prompt('Descrição do plano:', plan.description || '');

    if (name === null || slots === null || price === null) return;

    try {
      await updatePlan(plan.id, {
        name,
        slots: Number(slots),
        price: Number(price),
        description,
      });
      setMessage('Plano atualizado com sucesso.');
      loadPlans();
    } catch (error) {
      setMessage(getApiErrorMessage(error) || 'Erro ao atualizar plano.');
    }
  };

  const handleDeletePlan = async (planId) => {
    if (!window.confirm('Tem certeza de que deseja excluir este plano?')) return;
    try {
      await deletePlan(planId);
      setMessage('Plano excluído com sucesso.');
      loadPlans();
    } catch (error) {
      setMessage(getApiErrorMessage(error) || 'Erro ao excluir plano.');
    }
  };

  const totalPlans = plans.length;
  const totalSlots = plans.reduce((sum, plan) => sum + Number(plan.slots || 0), 0);
  const averagePrice = plans.length ? (plans.reduce((sum, plan) => sum + Number(plan.price || 0), 0) / plans.length) : 0;

  return (
    <div className="plans-page">
      <div className="plans-header">
        <div>
          <p className="section-overline">Planos</p>
          <h1>Gerenciar planos</h1>
          <p className="plans-subtitle">Crie, edite e mantenha os planos disponíveis para os seus servidores.</p>
        </div>
        {message && <div className="servers-message">{message}</div>}
      </div>

      <div className="plans-stats-grid">
        <div className="plan-stat-card">
          <span className="stat-label">Planos ativos</span>
          <h3>{totalPlans}</h3>
          <p>Total de opções disponíveis para contratação.</p>
        </div>
        <div className="plan-stat-card">
          <span className="stat-label">Slots totais</span>
          <h3>{totalSlots}</h3>
          <p>Soma de slots oferecidos em todos os planos.</p>
        </div>
        <div className="plan-stat-card">
          <span className="stat-label">Preço médio</span>
          <h3>R$ {averagePrice.toFixed(2)}</h3>
          <p>Valor médio dos planos cadastrados.</p>
        </div>
      </div>

      <div className="plans-grid">
        <section className="card plan-form-card">
          <div className="card-header">
            <div>
              <h3>Criar novo plano</h3>
              <small>Defina os parâmetros do plano e disponibilize para os usuários.</small>
            </div>
          </div>

          <form className="plan-form-grid" onSubmit={handleCreatePlan}>
            <div className="form-field">
              <label htmlFor="plan-name">Nome do plano</label>
              <input
                id="plan-name"
                type="text"
                placeholder="Nome do plano"
                value={newPlan.name}
                onChange={(e) => setNewPlan({ ...newPlan, name: e.target.value })}
                required
              />
            </div>
            <div className="form-field">
              <label htmlFor="plan-slots">Slots</label>
              <input
                id="plan-slots"
                type="number"
                placeholder="Slots"
                value={newPlan.slots}
                onChange={(e) => setNewPlan({ ...newPlan, slots: e.target.value })}
                required
              />
            </div>
            <div className="form-field">
              <label htmlFor="plan-price">Preço</label>
              <input
                id="plan-price"
                type="number"
                step="0.01"
                placeholder="Preço"
                value={newPlan.price}
                onChange={(e) => setNewPlan({ ...newPlan, price: e.target.value })}
                required
              />
            </div>
            <div className="form-field form-field-full">
              <label htmlFor="plan-description">Descrição</label>
              <input
                id="plan-description"
                type="text"
                placeholder="Descrição (opcional)"
                value={newPlan.description}
                onChange={(e) => setNewPlan({ ...newPlan, description: e.target.value })}
              />
            </div>
            <div className="form-actions-row" style={{ gridColumn: '1 / -1' }}>
              <button type="submit" className="button primary-button">Criar plano</button>
            </div>
          </form>
        </section>

        <section className="card plans-list-card">
          <div className="card-header">
            <div>
              <h3>Planos cadastrados</h3>
              <small>Veja, edite ou exclua planos existentes.</small>
            </div>
          </div>

          {plans.length ? (
            <div className="plans-table-wrapper">
              <table className="plans-table">
                <thead>
                  <tr>
                    <th>Nome</th>
                    <th>Slots</th>
                    <th>Preço</th>
                    <th>Descrição</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  {plans.map((plan) => (
                    <tr key={plan.id}>
                      <td data-label="Nome"><strong>{plan.name}</strong></td>
                      <td data-label="Slots">{plan.slots}</td>
                      <td data-label="Preço">R$ {Number(plan.price).toFixed(2)}</td>
                      <td data-label="Descrição">{plan.description || '—'}</td>
                      <td data-label="Ações">
                        <div className="plan-actions">
                          <button type="button" className="button secondary-button" onClick={() => handleEditPlan(plan)}>Editar</button>
                          <button type="button" className="button danger-button" onClick={() => handleDeletePlan(plan.id)}>Excluir</button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="empty-state">Nenhum plano cadastrado ainda.</div>
          )}
        </section>
      </div>
    </div>
  );
};

export default Finance;
