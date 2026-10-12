import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// أسماء نظام التصميم. القيم نفسها في [HasimTokens] حتى لا يتكرر اللون.
abstract final class AppColors {
  static const background = HasimTokens.background;
  static const primary = HasimTokens.primary;
  static const primaryLight = HasimTokens.mintSoft;
  static const text = HasimTokens.ink;
  static const secondary = HasimTokens.muted;
  static const border = HasimTokens.line;
  static const danger = HasimTokens.danger;
  static const dangerLight = HasimTokens.dangerSoft;
}

abstract final class AppSpacing {
  static const xs = HasimTokens.spaceXs;
  static const sm = HasimTokens.spaceSm;
  static const md = HasimTokens.spaceMd;
  static const lg = HasimTokens.spaceLg;
  static const xl = HasimTokens.spaceXl;
}

abstract final class AppRadius {
  static const icon = 14.0;
  static const button = HasimTokens.radiusButton;
  static const input = HasimTokens.radiusMd;
}

abstract final class AppTextStyles {
  static TextStyle screenTitle({Color color = AppColors.text}) => GoogleFonts.cairo(
        fontSize: 20,
        fontWeight: FontWeight.w700,
        height: 1.25,
        color: color,
      );

  static TextStyle sectionTitle({Color color = AppColors.text}) => GoogleFonts.cairo(
        fontSize: 16,
        fontWeight: FontWeight.w700,
        height: 1.35,
        color: color,
      );

  static TextStyle body({Color color = AppColors.text}) => GoogleFonts.cairo(
        fontSize: 16,
        fontWeight: FontWeight.w600,
        height: 1.35,
        color: color,
      );

  static TextStyle secondary({Color color = AppColors.secondary}) => GoogleFonts.cairo(
        fontSize: 13,
        fontWeight: FontWeight.w500,
        height: 1.4,
        color: color,
      );
}

class AppIconContainer extends StatelessWidget {
  const AppIconContainer({
    super.key,
    required this.icon,
    this.danger = false,
  });

  final IconData icon;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 40,
      height: 40,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: danger ? HasimTokens.dangerSoftColor(context) : HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(AppRadius.icon),
      ),
      child: Icon(
        icon,
        size: 21,
        color: danger ? HasimTokens.danger : HasimTokens.primary,
      ),
    );
  }
}

class AppSectionHeader extends StatelessWidget {
  const AppSectionHeader({
    super.key,
    required this.title,
    this.subtitle,
  });

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(4, 8, 4, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: AppTextStyles.sectionTitle(color: HasimTokens.inkColor(context))),
          if (subtitle != null) ...[
            const SizedBox(height: 4),
            Text(subtitle!, style: AppTextStyles.secondary(color: HasimTokens.mutedColor(context))),
          ],
        ],
      ),
    );
  }
}

class AppSettingsTile extends StatelessWidget {
  const AppSettingsTile({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.subtitleDirection,
    this.trailing,
    this.onTap,
    this.danger = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final TextDirection? subtitleDirection;
  final Widget? trailing;
  final VoidCallback? onTap;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final ink = danger ? HasimTokens.danger : HasimTokens.inkColor(context);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(4, 14, 4, 14),
          child: Row(
            children: [
              AppIconContainer(icon: icon, danger: danger),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, maxLines: 2, overflow: TextOverflow.ellipsis, style: AppTextStyles.body(color: ink)),
                    if (subtitle != null) ...[
                      const SizedBox(height: 3),
                      Text(
                        subtitle!,
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                        textDirection: subtitleDirection,
                        style: AppTextStyles.secondary(color: HasimTokens.mutedColor(context)),
                      ),
                    ],
                  ],
                ),
              ),
              if (trailing != null) ...[
                const SizedBox(width: 8),
                trailing!,
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class AppSwitch extends StatelessWidget {
  const AppSwitch({super.key, required this.value, required this.onChanged});

  final bool value;
  final ValueChanged<bool>? onChanged;

  @override
  Widget build(BuildContext context) {
    return Switch(value: value, onChanged: onChanged);
  }
}

class AppPrimaryButton extends StatelessWidget {
  const AppPrimaryButton({super.key, required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return FilledButton(onPressed: onPressed, child: Text(label));
  }
}

class AppSecondaryButton extends StatelessWidget {
  const AppSecondaryButton({super.key, required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton(onPressed: onPressed, child: Text(label));
  }
}
