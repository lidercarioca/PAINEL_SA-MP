from pathlib import Path
path = Path(r'c:\servidores\BCG\gamemodes\BCG.pwn')
text = path.read_text(encoding='utf-8')
start = text.find('public OnPlayerLogin(playerid)\n')
if start == -1:
    raise SystemExit('OnPlayerLogin start not found')
end = text.find('\nforward SalvarPlayer(playerid);', start)
if end == -1:
    raise SystemExit('OnPlayerLogin end not found')
text = text[:start] + 'public OnPlayerLogin(playerid)\n{\n    LoadPlayerStatsFromMySQL(playerid);\n    return 1;\n}\nforward SalvarPlayer(playerid);' + text[end:]
path.write_text(text, encoding='utf-8')
print('OnPlayerLogin replaced')
