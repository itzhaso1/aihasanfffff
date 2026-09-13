import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';

class NotificationPreferencesScreen extends ConsumerStatefulWidget {
  const NotificationPreferencesScreen({super.key});

  @override
  ConsumerState<NotificationPreferencesScreen> createState() => _NotificationPreferencesScreenState();
}

class _NotificationPreferencesScreenState extends ConsumerState<NotificationPreferencesScreen> {
  bool _messages = true;
  bool _email = true;
  bool _marketing = false;
  bool _loading = true;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final prefs = await ref.read(notificationRepositoryProvider).preferences();
      if (!mounted) return;
      setState(() {
        _messages = prefs['messages'] != false;
        _email = prefs['email'] != false;
        _marketing = prefs['marketing'] == true;
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
      setState(() {
        _error = 'تعذر تحميل التفضيلات.';
        _loading = false;
      });
    }
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      await ref.read(notificationRepositoryProvider).updatePreferences({
        'messages': _messages,
        'email': _email,
        'marketing': _marketing,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم حفظ التفضيلات')));
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر الحفظ')));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('تفضيلات الإشعارات')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_error!),
                      TextButton(onPressed: _load, child: const Text('إعادة المحاولة')),
                    ],
                  ),
                )
              : ListView(
                  children: [
                    SwitchListTile(
                      title: const Text('رسائل المحادثات'),
                      value: _messages,
                      onChanged: (v) => setState(() => _messages = v),
                    ),
                    SwitchListTile(
                      title: const Text('البريد'),
                      value: _email,
                      onChanged: (v) => setState(() => _email = v),
                    ),
                    SwitchListTile(
                      title: const Text('التسويق'),
                      value: _marketing,
                      onChanged: (v) => setState(() => _marketing = v),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(16),
                      child: FilledButton(
                        onPressed: _saving ? null : _save,
                        child: Text(_saving ? 'جارٍ الحفظ...' : 'حفظ'),
                      ),
                    ),
                  ],
                ),
    );
  }
}
