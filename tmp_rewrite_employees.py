from pathlib import Path
path = Path(r"D:\projects\aihasan\apps\hasim\lib\features\settings\presentation\ai_employees_tab.dart")
text = path.read_text(encoding="utf-8")
start = text.index("class _EmployeesTab extends ConsumerWidget {")
end = text.index("class _EmployeeCard extends StatelessWidget {")
replacement = r"""PLACEHOLDER"""
path.write_text(text[:start] + replacement + text[end:], encoding="utf-8")
print("updated")
