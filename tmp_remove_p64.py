from pathlib import Path
# dashboard_cache.php
p = Path('dashboard_cache.php')
s = p.read_text(encoding='utf-8')
s = s.replace("    $p64_teams = $tc['p64_teams'];\n", "")
start = s.find("        [\n            'id'=>'P64',")
end = s.find("        ['id'=>'P65',", start)
if start == -1 or end == -1:
    raise SystemExit('P64 block not found')
s = s[:start] + s[end:]
p.write_text(s, encoding='utf-8')

# dashboard_config.php
c = Path('dashboard_config.php')
t = c.read_text(encoding='utf-8')
t = t.replace("    'p64_teams' => ['Liverpool (V)','Napoli (V)','Bayern Munchen (V)','FC Koln (V)','FSV Mainz 05 (V)','Lille OSC (V)'],\n", "")
c.write_text(t, encoding='utf-8')

# dashboard.js
j = Path('dashboard.js')
u = j.read_text(encoding='utf-8')
start = u.find('\t\t\tcase "P64":')
end = u.find('\t\t\tcase "P65":', start)
if start == -1 or end == -1:
    raise SystemExit('P64 case not found')
u = u[:start] + u[end:]
j.write_text(u, encoding='utf-8')
