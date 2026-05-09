import React from 'react';

const LoadingButton = ({ children, loading = false, disabled = false, variant = 'primary', className = '', ...props }) => {
  const isDisabled = disabled || loading;

  return (
    <button
      className={`loading-button ${variant}-button ${loading ? 'loading' : ''} ${className}`.trim()}
      disabled={isDisabled}
      {...props}
    >
      {loading && <span className="spinner" aria-hidden="true" />}
      <span className="button-label">{loading ? 'Aguarde...' : children}</span>
    </button>
  );
};

export default LoadingButton;
