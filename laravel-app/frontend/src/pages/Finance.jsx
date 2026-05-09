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

  return (
    <div className="finance-page">
      <h2>Financeiro</h2>
      {message && <div className="message">{message}</div>}
      <section className="plan-form-section">
        <h3>Cadastro de Plano</h3>
        <form className="plan-form" onSubmit={handleCreatePlan}>
          <input
            type="text"
            placeholder="Nome do plano"
            value={newPlan.name}
            onChange={(e) => setNewPlan({ ...newPlan, name: e.target.value })}
            required
          />
          <input
            type="number"
            placeholder="Slots"
            value={newPlan.slots}
            onChange={(e) => setNewPlan({ ...newPlan, slots: e.target.value })}
            required
          />
          <input
            type="number"
            step="0.01"
            placeholder="Preço"
            value={newPlan.price}
            onChange={(e) => setNewPlan({ ...newPlan, price: e.target.value })}
            required
          />
          <input
            type="text"
            placeholder="Descrição (opcional)"
            value={newPlan.description}
            onChange={(e) => setNewPlan({ ...newPlan, description: e.target.value })}
          />
          <button type="submit">Criar plano</button>
        </form>
      </section>

      <section className="plan-list-section">
        <h3>Planos cadastrados</h3>
        {plans.length ? (
          <div className="plan-list">
            {plans.map((plan) => (
              <div key={plan.id} className="plan-card">
                <strong>{plan.name}</strong>
                <p>Slots: {plan.slots}</p>
                <p>Preço: R$ {plan.price}</p>
                {plan.description && <p>{plan.description}</p>}
                <div className="plan-actions">
                  <button type="button" onClick={() => handleEditPlan(plan)}>Editar</button>
                  <button type="button" className="delete-button" onClick={() => handleDeletePlan(plan.id)}>Excluir</button>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p>Nenhum plano cadastrado ainda.</p>
        )}
      </section>
    </div>
  );
};

export default Finance;
