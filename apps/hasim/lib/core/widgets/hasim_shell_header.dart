import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/widgets/hasim_more_menu.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

/// رأس التطبيق الموحد (RTL):
/// يمين الشاشة = كلمة «حاسم» فقط (بدون صورة/أفاتار)
/// يسار الشاشة = ⋯ المزيد
class HasimShellHeader extends ConsumerWidget implements PreferredSizeWidget {
  const HasimShellHeader({
    super.key,
    this.showBrand = true,
    this.extraActions = const [],
    this.backgroundColor,
  });

  final bool showBrand;
  final List<Widget> extraActions;
  final Color? backgroundColor;

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final theme = Theme.of(context);

    return AppBar(
      automaticallyImplyLeading: false,
      centerTitle: false,
      titleSpacing: 16,
      backgroundColor: backgroundColor,
      surfaceTintColor: Colors.transparent,
      scrolledUnderElevation: backgroundColor != null ? 0 : null,
      title: showBrand
          ? InkWell(
              onTap: () => context.push('/profile'),
              borderRadius: BorderRadius.circular(8),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 2),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      'حاسم',
                      style: theme.textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: theme.colorScheme.primary,
                      ),
                    ),
                    if (auth.workspace != null && auth.workspaces.length > 1)
                      Text(
                        auth.workspace!.name,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                  ],
                ),
              ),
            )
          : null,
      // في RTL: actions تظهر يسار الشاشة → ⋮
      actions: [
        ...extraActions,
        Semantics(
          button: true,
          label: 'المزيد',
          child: Builder(
            builder: (buttonContext) {
              return IconButton(
                key: const Key('hasim-more-btn'),
                tooltip: 'المزيد',
                visualDensity: VisualDensity.standard,
                style: IconButton.styleFrom(
                  minimumSize: const Size(44, 44),
                  tapTargetSize: MaterialTapTargetSize.padded,
                ),
                onPressed: () => showHasimMoreMenu(buttonContext, ref),
                icon: const Icon(Icons.more_vert),
              );
            },
          ),
        ),
      ],
    );
  }
}
