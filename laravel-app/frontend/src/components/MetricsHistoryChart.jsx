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

const MetricsHistoryChart = ({ metricsHistory = [] }) => {
  const [chartData, setChartData] = useState(null);

  useEffect(() => {
    if (!metricsHistory || metricsHistory.length === 0) {
      return;
    }

    const labels = metricsHistory.map((entry, idx) => {
      const totalEntries = metricsHistory.length;
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
          label: 'CPU %',
          data: metricsHistory.map((entry) => entry.cpu || 0),
          borderColor: '#ef4444',
          backgroundColor: 'rgba(239, 68, 68, 0.08)',
          borderWidth: 2,
          pointBackgroundColor: '#ef4444',
          pointBorderColor: 'rgba(13, 24, 48, 0.95)',
          pointBorderWidth: 1.5,
          pointRadius: 3,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: '#f87171',
          fill: false,
          tension: 0.4,
          yAxisID: 'y',
        },
        {
          label: 'RAM %',
          data: metricsHistory.map((entry) => entry.memory || 0),
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59, 130, 246, 0.08)',
          borderWidth: 2,
          pointBackgroundColor: '#3b82f6',
          pointBorderColor: 'rgba(13, 24, 48, 0.95)',
          pointBorderWidth: 1.5,
          pointRadius: 3,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: '#60a5fa',
          fill: false,
          tension: 0.4,
          yAxisID: 'y',
        },
        {
          label: 'Ping ms',
          data: metricsHistory.map((entry) => entry.ping || 0),
          borderColor: '#10b981',
          backgroundColor: 'rgba(16, 185, 129, 0.08)',
          borderWidth: 2,
          pointBackgroundColor: '#10b981',
          pointBorderColor: 'rgba(13, 24, 48, 0.95)',
          pointBorderWidth: 1.5,
          pointRadius: 3,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: '#34d399',
          fill: false,
          tension: 0.4,
          yAxisID: 'y1',
        },
      ],
    };

    setChartData(data);
  }, [metricsHistory]);

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
      mode: 'index',
      intersect: false,
    },
    plugins: {
      legend: {
        display: true,
        position: 'top',
        labels: {
          usePointStyle: true,
          padding: 15,
          font: {
            size: 11,
            weight: 600,
          },
          color: '#64748b',
        },
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
        callbacks: {
          title: function (context) {
            return context[0]?.label || '';
          },
          label: function (context) {
            const datasetLabel = context.dataset.label;
            const value = context.parsed.y;

            if (datasetLabel === 'Ping ms') {
              return `${datasetLabel}: ${value}ms`;
            }

            return `${datasetLabel}: ${value}%`;
          },
        },
      },
    },
    scales: {
      y: {
        type: 'linear',
        display: true,
        position: 'left',
        beginAtZero: true,
        max: 100,
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
          callback: function (value) {
            return value + '%';
          },
        },
        title: {
          display: true,
          text: 'CPU / RAM',
          color: '#64748b',
          font: {
            size: 11,
            weight: 600,
          },
        },
      },
      y1: {
        type: 'linear',
        display: true,
        position: 'right',
        beginAtZero: true,
        max: 500,
        grid: {
          drawOnChartArea: false,
        },
        ticks: {
          color: '#64748b',
          font: {
            size: 11,
            weight: 600,
          },
          padding: 8,
          callback: function (value) {
            return value + 'ms';
          },
        },
        title: {
          display: true,
          text: 'Ping',
          color: '#64748b',
          font: {
            size: 11,
            weight: 600,
          },
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

  if (!metricsHistory || metricsHistory.length === 0) {
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
            <rect x="3" y="4" width="18" height="12" rx="2" ry="2"></rect>
            <line x1="7" y1="8" x2="17" y2="8"></line>
            <line x1="7" y1="12" x2="17" y2="12"></line>
            <line x1="7" y1="16" x2="13" y2="16"></line>
          </svg>
        </div>
        <p>Aguardando métricas...</p>
        <span>Dados de CPU, RAM e ping aparecerão aqui</span>
      </div>
    );
  }

  return (
    <div className="chart-container">
      {chartData && <Line data={chartData} options={options} height={160} />}
    </div>
  );
};

export default MetricsHistoryChart;