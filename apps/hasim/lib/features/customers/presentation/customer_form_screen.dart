import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class CustomerFormScreen extends ConsumerStatefulWidget {
  const CustomerFormScreen({super.key, this.customer});

  final CustomerModel? customer;

  bool get isEditing => customer != null;

  @override
  ConsumerState<CustomerFormScreen> createState() => _CustomerFormScreenState();
}

class _CustomerFormScreenState extends ConsumerState<CustomerFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _nameController;
  late final TextEditingController _phoneController;
  late final TextEditingController _emailController;
  late final TextEditingController _whatsappController;
  late final TextEditingController _cityController;
  late final TextEditingController _notesController;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _nameController = TextEditingController(text: widget.customer?.name ?? '');
    _phoneController = TextEditingController(
      text: widget.customer?.phone ?? '',
    );
    _emailController = TextEditingController(
      text: widget.customer?.email ?? '',
    );
    _whatsappController = TextEditingController(
      text: widget.customer?.whatsapp ?? '',
    );
    _cityController = TextEditingController(text: widget.customer?.city ?? '');
    _notesController = TextEditingController();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    _whatsappController.dispose();
    _cityController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final repo = ref.read(customerRepositoryProvider);
      if (widget.isEditing) {
        await repo.update(
          widget.customer!.id,
          name: _nameController.text.trim(),
          phone: _phoneController.text.trim(),
          email: _emailController.text.trim(),
          whatsapp: _whatsappController.text.trim(),
          city: _cityController.text.trim(),
        );
      } else {
        await repo.create(
          name: _nameController.text.trim(),
          phone: _phoneController.text.trim(),
          email: _emailController.text.trim(),
          whatsapp: _whatsappController.text.trim(),
          city: _cityController.text.trim(),
          notes: _notesController.text.trim(),
        );
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            widget.isEditing
                ? 'تم تحديث بيانات العميل بنجاح.'
                : 'تمت إضافة العميل الجديد بنجاح.',
          ),
          backgroundColor: HasimTokens.chatGreen,
        ),
      );
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceAll('Exception: ', '');
        _submitting = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(
          widget.isEditing ? 'تعديل بيانات العميل' : 'إضافة عميل جديد',
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (_error != null)
                Container(
                  padding: const EdgeInsets.all(12),
                  margin: const EdgeInsets.only(bottom: 16),
                  decoration: BoxDecoration(
                    color: Colors.red.shade50,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.red.shade200),
                  ),
                  child: Row(
                    children: [
                      Icon(
                        Icons.error_outline,
                        color: Colors.red.shade700,
                        size: 20,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          _error!,
                          style: TextStyle(
                            color: Colors.red.shade800,
                            fontSize: 13,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              _buildField(
                controller: _nameController,
                label: 'اسم العميل *',
                hint: 'مثال: محمد عبدالله',
                icon: Icons.person_outline,
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? 'الاسم مطلوب' : null,
              ),
              const SizedBox(height: 16),
              _buildField(
                controller: _phoneController,
                label: 'رقم الهاتف',
                hint: 'مثال: 966501234567',
                icon: Icons.phone_outlined,
                keyboardType: TextInputType.phone,
              ),
              const SizedBox(height: 16),
              _buildField(
                controller: _emailController,
                label: 'البريد الإلكتروني',
                hint: 'name@example.com',
                icon: Icons.mail_outline,
                keyboardType: TextInputType.emailAddress,
              ),
              const SizedBox(height: 16),
              _buildField(
                controller: _whatsappController,
                label: 'رقم الواتساب',
                hint: 'مثال: 966501234567',
                icon: Icons.chat_bubble_outline,
                keyboardType: TextInputType.phone,
              ),
              const SizedBox(height: 16),
              _buildField(
                controller: _cityController,
                label: 'المدينة / المنطقة',
                hint: 'مثال: الرياض',
                icon: Icons.location_on_outlined,
              ),
              if (!widget.isEditing) ...[
                const SizedBox(height: 16),
                _buildField(
                  controller: _notesController,
                  label: 'ملاحظة أولية (اختياري)',
                  hint: 'أية ملاحظات أو تفاصيل عن العميل...',
                  icon: Icons.note_outlined,
                  maxLines: 3,
                ),
              ],
              const SizedBox(height: 28),
              FilledButton(
                key: const Key('save-customer-button'),
                onPressed: _submitting ? null : _submit,
                style: FilledButton.styleFrom(
                  backgroundColor: HasimTokens.chatGreen,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
                  ),
                ),
                child: _submitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : Text(
                        widget.isEditing ? 'حفظ التعديلات' : 'إضافة العميل',
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildField({
    required TextEditingController controller,
    required String label,
    required String hint,
    required IconData icon,
    TextInputType keyboardType = TextInputType.text,
    int maxLines = 1,
    String? Function(String?)? validator,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 6),
        TextFormField(
          controller: controller,
          keyboardType: keyboardType,
          maxLines: maxLines,
          validator: validator,
          decoration: InputDecoration(
            hintText: hint,
            prefixIcon: Icon(
              icon,
              size: 20,
              color: HasimTokens.mutedColor(context),
            ),
            filled: true,
            fillColor: HasimTokens.cardBg(context),
            contentPadding: const EdgeInsets.symmetric(
              horizontal: 14,
              vertical: 12,
            ),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              borderSide: BorderSide(
                color: HasimTokens.lineColor(context),
                width: 0.8,
              ),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              borderSide: BorderSide(
                color: HasimTokens.lineColor(context),
                width: 0.8,
              ),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              borderSide: const BorderSide(
                color: HasimTokens.chatGreen,
                width: 1.5,
              ),
            ),
          ),
        ),
      ],
    );
  }
}
