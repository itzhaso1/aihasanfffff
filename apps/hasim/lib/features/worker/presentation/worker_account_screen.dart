import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/worker/presentation/worker_account_sections.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/router/worker_nav.dart';

class WorkerAccountScreen extends ConsumerWidget {
  const WorkerAccountScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final body = SafeArea(
      child: Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 760),
          child: ListView.builder(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
            itemCount: 21,
            itemBuilder: (context, index) => _item(context, ref, index),
          ),
        ),
      ),
    );

    if (embedded) {
      return body;
    }

    return WorkerScaffold(selectedIndex: WorkerNav.accountIndex, body: body);
  }

  Widget _item(BuildContext context, WidgetRef ref, int index) {
    return switch (index) {
      0 => const HasimPageHeader(
        title: 'المعلومات والتوثيق',
        subtitle: 'معلومات العامل والتحقق من الحساب',
        icon: Icons.badge_outlined,
      ),
      1 => const SizedBox(height: 16),
      2 => const WorkerProfessionalSection(),
      3 => const SizedBox(height: 18),
      4 => const _WorkerInfoGroupHeader(
        icon: Icons.auto_awesome_outlined,
        title: 'المهارات واللغات',
        subtitle: 'القدرات التي تساعد الشركات على فهم خبرتك.',
      ),
      5 => const SizedBox(height: 10),
      6 => const WorkerSkillsSection(),
      7 => const SizedBox(height: 12),
      8 => const WorkerLanguagesSection(),
      9 => const SizedBox(height: 18),
      10 => const _WorkerInfoGroupHeader(
        icon: Icons.work_history_outlined,
        title: 'الخبرات العملية',
        subtitle: 'سجّل الخبرات السابقة التي تدعم ملفك المهني.',
      ),
      11 => const SizedBox(height: 10),
      12 => const WorkerExperienceSection(),
      13 => const SizedBox(height: 18),
      14 => const _WorkerInfoGroupHeader(
        icon: Icons.verified_user_outlined,
        title: 'الهوية والتوثيق',
        subtitle: 'أكمل بيانات الهوية ومستندات التحقق بأمان.',
      ),
      15 => const SizedBox(height: 10),
      16 => const WorkerIdentitySection(),
      17 => const SizedBox(height: 12),
      18 => const WorkerVerificationSection(),
      19 => const SizedBox(height: 24),
      20 => OutlinedButton.icon(
        key: const Key('worker-logout'),
        onPressed: ref.read(authControllerProvider.notifier).logout,
        icon: const Icon(Icons.logout_outlined),
        label: const Text('تسجيل الخروج'),
        style: OutlinedButton.styleFrom(
          foregroundColor: HasimTokens.danger,
          minimumSize: const Size.fromHeight(48),
          side: BorderSide(color: HasimTokens.danger.withValues(alpha: 0.45)),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(HasimTokens.radiusButton),
          ),
        ),
      ),
      _ => const SizedBox.shrink(),
    };
  }
}

class _WorkerInfoGroupHeader extends StatelessWidget {
  const _WorkerInfoGroupHeader({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        DecoratedBox(
          decoration: BoxDecoration(
            color: HasimTokens.mintFill(context),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Padding(
            padding: const EdgeInsets.all(8),
            child: Icon(icon, size: 20, color: HasimTokens.primary),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 2),
              Text(
                subtitle,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: HasimTokens.mutedColor(context),
                  height: 1.4,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
