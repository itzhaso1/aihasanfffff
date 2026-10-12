import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/conversations/presentation/widgets/customer_info_drawer.dart';

/// ملف العميل على الهاتف — نفس لوحة «معلومات العميل» في سطح المكتب.
class CustomerProfileScreen extends ConsumerStatefulWidget {
  const CustomerProfileScreen({
    super.key,
    required this.customerId,
    this.initialCustomer,
  });

  final int customerId;
  final CustomerModel? initialCustomer;

  @override
  ConsumerState<CustomerProfileScreen> createState() =>
      _CustomerProfileScreenState();
}

class _CustomerProfileScreenState extends ConsumerState<CustomerProfileScreen> {
  CustomerModel? _customer;
  List<ConversationModel> _conversations = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _customer = widget.initialCustomer;
    _loading = _customer == null;
    _load();
  }

  Future<void> _load() async {
    if (_customer == null) {
      setState(() {
        _loading = true;
        _error = null;
      });
    } else {
      setState(() => _error = null);
    }
    try {
      final repo = ref.read(customerRepositoryProvider);
      final customer = await repo.show(widget.customerId);
      final conversations = await repo.conversations(widget.customerId);
      if (!mounted) return;
      setState(() {
        _customer = customer;
        _conversations = conversations;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      if (_customer != null) {
        setState(() => _loading = false);
        return;
      }
      setState(() {
        _error = 'تعذر تحميل ملف العميل.';
        _loading = false;
      });
    }
  }

  ConversationModel _conversationFor(CustomerModel customer) {
    if (_conversations.isEmpty) {
      return ConversationModel(
        id: 0,
        channel: 'manual',
        status: 'open',
        customer: customer,
      );
    }
    final first = _conversations.first;
    return ConversationModel(
      id: first.id,
      channel: first.channel,
      status: first.status,
      externalId: first.externalId,
      aiEnabled: first.aiEnabled,
      lastMessageAt: first.lastMessageAt,
      customer: first.customer ?? customer,
      unreadCount: first.unreadCount,
      lastMessage: first.lastMessage,
      muted: first.muted,
      archived: first.archived,
    );
  }

  @override
  Widget build(BuildContext context) {
    final customer = _customer;
    return Scaffold(
      backgroundColor: const Color(0xFFF5FBFA),
      body: _loading
          ? const SafeArea(child: SkeletonList())
          : _error != null || customer == null
          ? SafeArea(
              child: Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(_error ?? 'تعذر تحميل ملف العميل.'),
                    TextButton(
                      onPressed: _load,
                      child: const Text('إعادة المحاولة'),
                    ),
                    TextButton(
                      onPressed: () => context.pop(),
                      child: const Text('رجوع'),
                    ),
                  ],
                ),
              ),
            )
          : CustomerInfoDrawer(
              conversation: _conversationFor(customer),
              asPage: true,
              onClose: () => context.pop(),
            ),
    );
  }
}
