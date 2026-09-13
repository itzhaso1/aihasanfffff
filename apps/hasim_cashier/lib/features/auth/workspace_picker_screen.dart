import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/cashier_api.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/auth/cashier_cloud_link_service.dart';
import '../../core/theme/hasim_colors.dart';
import '../../core/widgets/hasim_widgets.dart';

class WorkspacePickerScreen extends ConsumerStatefulWidget {
  const WorkspacePickerScreen({super.key});

  @override
  ConsumerState<WorkspacePickerScreen> createState() =>
      _WorkspacePickerScreenState();
}

class _WorkspacePickerScreenState extends ConsumerState<WorkspacePickerScreen> {
  List<Map<String, dynamic>> _items = const [];
  var _loading = true;
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
      final data = await ref.read(cashierApiProvider).get('/workspaces');
      final list = CashierCloudLinkService.parseWorkspaceList(data);
      setState(() {
        _items = list;
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = e is ApiException ? e.message : 'تعذر تحميل مساحات العمل.';
        _loading = false;
      });
    }
  }

  Future<void> _select(Map<String, dynamic> workspace) async {
    final posEnabled = CashierCloudLinkService.isPosEnabled(workspace);
    if (!posEnabled) {
      setState(() {
        _error = 'الكاشير غير متاح في باقتك الحالية';
      });
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ref.read(authControllerProvider.notifier).selectWorkspace(workspace);
      if (!mounted) return;
      context.go('/login');
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e is ApiException ? e.message : e.toString();
      });
    }
  }

  Future<void> _cancel() async {
    await ref.read(authControllerProvider.notifier).abortCloudSetup();
    if (!mounted) return;
    context.go('/login');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HasimColors.page,
      appBar: AppBar(
        title: const Text('اختر مساحة العمل'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          onPressed: _cancel,
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                    child: HsEmpty(title: _error!),
                  ),
                Expanded(
                  child: _items.isEmpty
                      ? Center(
                          child: HsEmpty(
                            title: _error ?? 'لا توجد مساحات عمل متاحة.',
                          ),
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.all(16),
                          itemCount: _items.length,
                          separatorBuilder: (context, index) =>
                              const SizedBox(height: 8),
                          itemBuilder: (context, index) {
                            final ws = _items[index];
                            final posEnabled =
                                CashierCloudLinkService.isPosEnabled(ws);
                            return Material(
                              color: HasimColors.surface,
                              borderRadius: BorderRadius.circular(14),
                              child: InkWell(
                                borderRadius: BorderRadius.circular(14),
                                onTap: () => _select(ws),
                                child: Ink(
                                  decoration: BoxDecoration(
                                    borderRadius: BorderRadius.circular(14),
                                    border: Border.all(
                                      color: HasimColors.border,
                                    ),
                                  ),
                                  padding: const EdgeInsets.all(14),
                                  child: Row(
                                    children: [
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              (ws['name'] as String?) ??
                                                  'Workspace',
                                              style: const TextStyle(
                                                fontWeight: FontWeight.w800,
                                              ),
                                            ),
                                            Text(
                                              posEnabled
                                                  ? 'الكاشير متاح'
                                                  : 'الكاشير غير متاح في الباقة',
                                              style: TextStyle(
                                                fontSize: 12,
                                                color: posEnabled
                                                    ? HasimColors.ctaDark
                                                    : HasimColors.muted,
                                                fontWeight: FontWeight.w600,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                      const Icon(Icons.chevron_left),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
                ),
              ],
            ),
    );
  }
}
