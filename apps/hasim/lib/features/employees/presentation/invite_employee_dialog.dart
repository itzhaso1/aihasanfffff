import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/employees/providers/employees_controller.dart';

class InviteEmployeeDialog extends ConsumerStatefulWidget {
  const InviteEmployeeDialog({super.key, required this.assignableRoles});

  final List<String> assignableRoles;

  @override
  ConsumerState<InviteEmployeeDialog> createState() =>
      _InviteEmployeeDialogState();
}

class _InviteEmployeeDialogState extends ConsumerState<InviteEmployeeDialog> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  late String _selectedRole;
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    _selectedRole = widget.assignableRoles.contains('agent')
        ? 'agent'
        : (widget.assignableRoles.isNotEmpty ? widget.assignableRoles.first : 'agent');
  }

  @override
  void dispose() {
    _emailController.dispose();
    super.dispose();
  }

  String _roleLabel(String role) {
    return switch (role.toLowerCase()) {
      'admin' => 'مدير النظام (Admin)',
      'manager' => 'مدير العمليات (Manager)',
      'agent' => 'وكيل خدمة العملاء (Agent)',
      'accountant' => 'محاسب (Accountant)',
      'receptionist' => 'موظف استقبال (Receptionist)',
      'staff_doctor' => 'طبيب / اختصاصي',
      'staff' => 'موظف (Staff)',
      _ => role,
    };
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _submitting = true);

    final success = await ref
        .read(employeesControllerProvider.notifier)
        .invite(email: _emailController.text.trim(), role: _selectedRole);

    if (!mounted) return;
    setState(() => _submitting = false);

    if (success) {
      Navigator.of(context).pop(true);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('تم إرسال دعوة الانضمام بنجاح.'),
          backgroundColor: HasimTokens.chatGreen,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('تعذر إرسال الدعوة. يرجى التحقق من البريد والصلاحيات.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Row(
        children: [
          Icon(Icons.person_add_outlined, color: HasimTokens.primary),
          SizedBox(width: 8),
          Text('دعوة موظف جديد', style: TextStyle(fontWeight: FontWeight.bold)),
        ],
      ),
      content: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'أدخل البريد الإلكتروني للموظف وحدد الدور الوظيفي لمنحه الصلاحيات المناسبة داخل مساحة العمل.',
                style: TextStyle(fontSize: 13, color: Colors.grey),
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _emailController,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(
                  labelText: 'البريد الإلكتروني',
                  hintText: 'name@example.com',
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.email_outlined),
                ),
                validator: (val) {
                  if (val == null || val.trim().isEmpty) {
                    return 'البريد الإلكتروني مطلوب';
                  }
                  if (!val.contains('@') || !val.contains('.')) {
                    return 'صيغة البريد غير صحيحة';
                  }
                  return null;
                },
              ),
              const SizedBox(height: 16),
              DropdownButtonFormField<String>(
                initialValue: _selectedRole,
                decoration: const InputDecoration(
                  labelText: 'الدور والصلاحية',
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.shield_outlined),
                ),
                items: widget.assignableRoles.map((role) {
                  return DropdownMenuItem(
                    value: role,
                    child: Text(_roleLabel(role)),
                  );
                }).toList(),
                onChanged: (val) {
                  if (val != null) setState(() => _selectedRole = val);
                },
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _submitting ? null : () => Navigator.of(context).pop(),
          child: const Text('إلغاء'),
        ),
        FilledButton(
          style: FilledButton.styleFrom(
            backgroundColor: HasimTokens.chatGreen,
          ),
          onPressed: _submitting ? null : _submit,
          child: _submitting
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : const Text('إرسال الدعوة'),
        ),
      ],
    );
  }
}
