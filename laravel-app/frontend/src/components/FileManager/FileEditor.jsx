import React from 'react';
import LoadingButton from '../LoadingButton';

const FileEditor = ({ file, content, onChange, onSave, onCompile, loading, saving, compiling, isEditable, critical, serverStatus }) => {
  if (!file) {
    return (
      <div className="file-editor-card empty">
        <h3>Editor</h3>
        <p>Selecione um arquivo para abrir o editor e aplicar alterações.</p>
      </div>
    );
  }

  return (
    <div className="file-editor-card">
      <div className="editor-header">
        <div>
          <h3>{file.name}</h3>
          <p>{file.path}</p>
        </div>
        <div className="editor-meta">
          <span className={`badge ${critical ? 'critical' : 'normal'}`}>
            {critical ? 'Crítico' : 'Normal'}
          </span>
          <span className={`badge ${isEditable ? 'editable' : 'readonly'}`}>
            {isEditable ? 'Editável' : 'Somente leitura'}
          </span>
        </div>
      </div>

      {critical && (
        <div className="editor-alert">
          ⚠️ Este arquivo pode afetar o funcionamento do servidor. Edite apenas se souber o que está fazendo.
        </div>
      )}

      <textarea
        className="code-editor"
        value={content}
        onChange={(e) => onChange(e.target.value)}
        disabled={!isEditable || loading}
        rows={18}
      />

      <div className="editor-actions">
        <LoadingButton className="primary-button" onClick={onSave} loading={saving} disabled={!isEditable || loading}>
          Aplicar mudanças
        </LoadingButton>
        {file.name.toLowerCase().endsWith('.pwn') && (
          <LoadingButton className="secondary-button" onClick={onCompile} loading={compiling} disabled={loading}>
            Compilar
          </LoadingButton>
        )}
      </div>

      <div className="editor-footer">
        <span>Status do servidor: {serverStatus?.toUpperCase() || 'DESCONHECIDO'}</span>
      </div>
    </div>
  );
};

export default FileEditor;
