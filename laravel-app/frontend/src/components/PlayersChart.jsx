import React, { useEffect, useState } from 'react';
import { Line } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Filler,
  Legend,
} from 'chart.js';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Filler,
  Legend
);

const PlayersChart = ({ players = 0, maxSlots = 32, chartHistory = [] }) => {
  const [chartData, setChartData] = useState(null);

  useEffect(() => {
    if (!chartHistory || chartHistory.length === 0) {
      return;
    }

    const labels = chartHistory.map((entry, idx) => {
      const totalEntries = chartHistory.length;
      const interval = Math.max(1, Math.floor(totalEntries / 6));
      return idx % interval === 0
        ? new Date(entry.timestamp).toLocaleTimeString('pt-BR', {
            hour: '2-digit',
            minute: '2-digit',
          })
        : '';
    });

    const data = {
      labels,
      datasets: [
        {
          label: 'Jogadores Online',
          data: chartHistory.map((entry) => entry.players),
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59, 130, 246, 0.08)',
          borderWidth: 2.5,
          pointBackgroundColor: '#3b82f6',
          pointBorderColor: 'rgba(13, 24, 48, 0.95)',
          pointBorderWidth: 2,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointHoverBackgroundColor: '#38bdf8',
          fill: true,
          tension: 0.45,
          segment: {
            borderDash: [],
          },
        },
      ],
    };

    setChartData(data);
  }, [chartHistory]);

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
      mode: 'index',
      intersect: false,
    },
    plugins: {
      legend: {
        display: false,
      },
      tooltip: {
        enabled: true,
        backgroundColor: 'rgba(13, 24, 48, 0.95)',
        titleColor: '#f1f5f9',
        bodyColor: '#94a3b8',
        borderColor: 'rgba(59, 130, 246, 0.4)',
        borderWidth: 1,
        padding: 12,
        titleFont: {
          size: 13,
          weight: 700,
        },
        bodyFont: {
          size: 12,
        },
        displayColors: false,
        callbacks: {
          title: function (context) {
            return context[0]?.label || '';
          },
          label: function (context) {
            return `${context.parsed.y} jogadores`;
          },
          afterLabel: function (context) {
            const maxSlots = 32;
            const percentage = Math.round(
              (context.parsed.y / maxSlots) * 100
            );
            return `${percentage}% ocupado`;
          },
        },
      },
    },
    scales: {
      y: {
        beginAtZero: true,
        max: maxSlots,
        grid: {
          color: 'rgba(255, 255, 255, 0.05)',
          drawBorder: false,
        },
        ticks: {
          color: '#64748b',
          font: {
            size: 11,
            weight: 600,
          },
          padding: 8,
        },
      },
      x: {
        grid: {
          display: false,
          drawBorder: false,
        },
        ticks: {
          color: '#64748b',
          font: {
            size: 11,
            weight: 600,
          },
        },
      },
    },
  };

  if (!chartHistory || chartHistory.length === 0) {
    return (
      <div className="chart-empty-state">
        <div className="chart-empty-icon">
          <svg
            width="40"
            height="40"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
          >
            <polyline points="23 6 13.5 15.5 8.5 10.5 1 17"></polyline>
            <polyline points="17 6 23 6 23 12"></polyline>
          </svg>
        </div>
        <p>Nenhuma atividade recente</p>
        <span>Dados de jogadores aparecerão aqui</span>
      </div>
    );
  }

  return (
    <div className="chart-container">
      {chartData && <Line data={chartData} options={options} height={160} />}
    </div>
  );
};

export default PlayersChart;
