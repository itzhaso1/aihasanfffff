import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimProfileBubble extends StatelessWidget {
  const HasimProfileBubble({
    super.key,
    required this.label,
    required this.onTap,
    required this.onAdd,
    this.avatarPath,
    this.hasStories = false,
    this.radius = 22,
  });

  final String label;
  final String? avatarPath;
  final bool hasStories;
  final VoidCallback onTap;
  final VoidCallback? onAdd;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final hasHttp = avatarPath != null && avatarPath!.startsWith('http');
    final ring = hasStories ? AppTheme.brand : HasimTokens.fieldBorder;

    return SizedBox(
      width: radius * 2 + 18,
      height: radius * 2 + 14,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Align(
            alignment: Alignment.center,
            child: InkWell(
              onTap: onTap,
              customBorder: const CircleBorder(),
              child: Container(
                padding: const EdgeInsets.all(2.5),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: ring, width: hasStories ? 2 : 1.2),
                ),
                child: CircleAvatar(
                  radius: radius,
                  backgroundColor: HasimTokens.mintLight,
                  backgroundImage: hasHttp
                      ? CachedNetworkImageProvider(avatarPath!)
                      : null,
                  child: hasHttp
                      ? null
                      : Text(
                          label.isNotEmpty ? label[0] : 'ح',
                          style: TextStyle(
                            fontWeight: FontWeight.w800,
                            fontSize: radius * 0.78,
                            color: AppTheme.brandDark,
                          ),
                        ),
                ),
              ),
            ),
          ),
          if (onAdd != null)
            PositionedDirectional(
              end: 0,
              bottom: 0,
              child: Material(
                color: AppTheme.brand,
                shape: const CircleBorder(),
                elevation: 1,
                child: InkWell(
                  key: const Key('updates-profile-add'),
                  customBorder: const CircleBorder(),
                  onTap: onAdd,
                  child: const Padding(
                    padding: EdgeInsets.all(4),
                    child: Icon(Icons.add, size: 14, color: Colors.white),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
