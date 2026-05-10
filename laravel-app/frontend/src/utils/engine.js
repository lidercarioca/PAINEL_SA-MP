export const resolveEngine = (server) => {
  if (!server) {
    return 'samp';
  }

  const engine = String(server.engine || '').toLowerCase();

  if (engine === 'fivem') {
    return 'fivem';
  }

  if (engine === 'samp') {
    return 'samp';
  }

  const gamemode = String(server.game_mode || server.gamemode || server.gamemode_name || '').toLowerCase();
  const port = Number(server.port);

  if (gamemode.includes('fivem') || port === 30120) {
    return 'fivem';
  }

  return 'samp';
};

export const getEngineLabel = (engine) => {
  return engine === 'fivem' ? 'FiveM' : 'SA-MP';
};

export const getEngineIcon = (engine) => {
  return engine === 'fivem' ? '🚀' : '🎮';
};
