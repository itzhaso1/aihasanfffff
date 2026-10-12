from pathlib import Path
path = Path(r"D:\projects\aihasan\apps\hasim\lib\features\settings\presentation\ai_settings_screen.dart")
text = path.read_text(encoding="utf-8")
needle = "part 'ai_employees_tab.dart';\n"
insert = "part 'ai_employees_tab.dart';\npart 'company_workers_section.dart';\n"
if needle not in text:
    raise SystemExit('needle missing')
path.write_text(text.replace(needle, insert, 1), encoding="utf-8")
print('updated')
