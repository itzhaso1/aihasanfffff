import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/features/settings/data/company_worker_engagement_capabilities_repository.dart';
import 'package:hasim/features/settings/providers/company_worker_engagement_capabilities_controller.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_capability_presentation.dart';

const companyWorkerEngagementCapabilitiesLocation =
    '/company-worker-engagements';

class CompanyWorkerEngagementCapabilitiesScreen extends ConsumerWidget {
  const CompanyWorkerEngagementCapabilitiesScreen({
    super.key,
    required this.engagementId,
    this.engagement,
  });

  final int engagementId;
  final CompanyWorkerOpenEngagement? engagement;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(
      companyWorkerEngagementCapabilitiesControllerProvider(engagementId),
    );
    final controller = ref.read(
      companyWorkerEngagementCapabilitiesControllerProvider(
        engagementId,
      ).notifier,
    );

    final content = Directionality(
      textDirection: TextDirection.rtl,
      child: _CapabilitiesBody(
        state: state,
        engagement: engagement,
        onRetry: controller.load,
        onToggle: controller.toggle,
      ),
    );

    if (HasimLayout.isCompact(context)) {
      return HasimMobileScaffold(title: 'صلاحيات الموظف', body: content);
    }

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text('صلاحيات الموظف'),
        backgroundColor: HasimTokens.pageBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      body: content,
    );
  }
}

class _CapabilitiesBody extends StatelessWidget {
  const _CapabilitiesBody({
    required this.state,
    required this.onRetry,
    required this.onToggle,
    this.engagement,
  });

  final CompanyWorkerEngagementCapabilitiesState state;
  final CompanyWorkerOpenEngagement? engagement;
  final Future<void> Function() onRetry;
  final Future<bool> Function(
    CompanyWorkerEngagementCapability capability,
    bool allowed,
  )
  onToggle;

  @override
  Widget build(BuildContext context) {
    if (state.loading && state.capabilities.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (state.error != null && state.capabilities.isEmpty) {
      return EmptyState(
        icon: Icons.lock_outline_rounded,
        title: state.error!,
        actionLabel: 'إعادة المحاولة',
        onAction: onRetry,
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
      children: [
        if (engagement != null) ...[
          _EngagementSummary(engagement: engagement!),
          const SizedBox(height: 14),
        ],
        if (state.loading)
          const Padding(
            padding: EdgeInsets.only(bottom: 10),
            child: LinearProgressIndicator(minHeight: 2),
          ),
        if (state.actionError != null) ...[
          _InlineError(message: state.actionError!),
          const SizedBox(height: 10),
        ],
        _CapabilitiesCard(state: state, onToggle: onToggle),
      ],
    );
  }
}

class _EngagementSummary extends StatelessWidget {
  const _EngagementSummary({required this.engagement});

  final CompanyWorkerOpenEngagement engagement;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          children: [
            CircleAvatar(
              backgroundColor: HasimTokens.mintFill(context),
              child: Icon(Icons.badge_outlined, color: HasimTokens.primary),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    engagement.workerDisplayName,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    engagement.jobTitle,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            _StatusPill(label: engagement.statusLabel),
          ],
        ),
      ),
    );
  }
}

class _CapabilitiesCard extends StatelessWidget {
  const _CapabilitiesCard({required this.state, required this.onToggle});

  final CompanyWorkerEngagementCapabilitiesState state;
  final Future<bool> Function(
    CompanyWorkerEngagementCapability capability,
    bool allowed,
  )
  onToggle;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      key: const Key('company-engagement-capabilities'),
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 14, 12, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'صلاحيات الموظف',
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 4),
            Text(
              'إدارة صلاحيات هذا الارتباط فقط. لا تتحول هذه الصلاحيات إلى عضوية في مساحة العمل.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: HasimTokens.mutedColor(context),
                height: 1.45,
              ),
            ),
            const SizedBox(height: 8),
            for (var index = 0; index < state.capabilities.length; index++) ...[
              _CapabilityTile(
                capability: state.capabilities[index],
                saving: state.savingKey == state.capabilities[index].key,
                onToggle: onToggle,
              ),
              if (index < state.capabilities.length - 1)
                Divider(height: 1, color: HasimTokens.lineColor(context)),
            ],
          ],
        ),
      ),
    );
  }
}

class _CapabilityTile extends StatelessWidget {
  const _CapabilityTile({
    required this.capability,
    required this.saving,
    required this.onToggle,
  });

  final CompanyWorkerEngagementCapability capability;
  final bool saving;
  final Future<bool> Function(
    CompanyWorkerEngagementCapability capability,
    bool allowed,
  )
  onToggle;

  @override
  Widget build(BuildContext context) {
    final presentation = WorkerCapabilityPresentation.fromCapability(
      WorkerCapability(
        key: capability.key,
        displayName: capability.displayName,
      ),
    );
    final statusLabel = capability.allowed ? 'مفعلة' : 'غير مفعلة';

    return Material(
      color: Colors.transparent,
      child: SwitchListTile(
        key: Key('company-engagement-capability-${capability.key}'),
        contentPadding: const EdgeInsets.symmetric(horizontal: 2, vertical: 4),
        secondary: saving
            ? const SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Icon(presentation.icon, color: presentation.categoryColor),
        title: Text(
          presentation.title,
          style: const TextStyle(fontWeight: FontWeight.w800),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 3),
            Text(
              presentation.description,
              style: TextStyle(
                color: HasimTokens.mutedColor(context),
                height: 1.35,
              ),
            ),
            const SizedBox(height: 3),
            Text(
              statusLabel,
              key: Key(
                'company-engagement-capability-status-${capability.key}',
              ),
              style: TextStyle(
                color: capability.allowed
                    ? HasimTokens.success
                    : HasimTokens.mutedColor(context),
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
        value: capability.allowed,
        onChanged: saving
            ? null
            : (value) {
                onToggle(capability, value);
              },
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
        child: Text(
          label,
          style: TextStyle(
            color: HasimTokens.accentDark,
            fontWeight: FontWeight.w700,
            fontSize: 11,
          ),
        ),
      ),
    );
  }
}

class _InlineError extends StatelessWidget {
  const _InlineError({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.dangerSoftColor(context),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Text(
          message,
          style: TextStyle(
            color: HasimTokens.danger,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}
