import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimSearchBar extends StatefulWidget {
  const HasimSearchBar({
    super.key,
    this.controller,
    required this.hintText,
    this.onChanged,
    this.onSubmitted,
    this.onClear,
    this.filledMint = false,
    this.flat = false,
    this.autofocus = false,
  });

  final TextEditingController? controller;
  final String hintText;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final VoidCallback? onClear;
  final bool filledMint;
  final bool flat;
  final bool autofocus;

  @override
  State<HasimSearchBar> createState() => _HasimSearchBarState();
}

class _HasimSearchBarState extends State<HasimSearchBar> {
  late final TextEditingController _controller;
  late final bool _ownsController;
  final _focus = FocusNode();

  @override
  void initState() {
    super.initState();
    _ownsController = widget.controller == null;
    _controller = widget.controller ?? TextEditingController();
    _controller.addListener(_onText);
  }

  @override
  void dispose() {
    _controller.removeListener(_onText);
    if (_ownsController) {
      _controller.dispose();
    }
    _focus.dispose();
    super.dispose();
  }

  void _onText() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final showClear = _controller.text.isNotEmpty;

    final fill = widget.flat
        ? (HasimTokens.isDark(context)
              ? HasimTokens.darkCard
              : const Color(0xFFF0F2F5))
        : widget.filledMint
        ? (HasimTokens.isDark(context)
              ? HasimTokens.darkCard
              : HasimTokens.mintLight)
        : HasimTokens.cardBg(context);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: fill,
        borderRadius: BorderRadius.circular(HasimTokens.radiusPill),
        border: widget.filledMint || widget.flat
            ? null
            : Border.all(color: HasimTokens.lineColor(context)),
        boxShadow: widget.filledMint || widget.flat
            ? const []
            : HasimTokens.softShadow(context),
      ),
      child: TextField(
        controller: _controller,
        focusNode: _focus,
        autofocus: widget.autofocus,
        textInputAction: TextInputAction.search,
        textAlign: TextAlign.start,
        onChanged: widget.onChanged,
        onSubmitted: widget.onSubmitted,
        decoration: InputDecoration(
          hintText: widget.hintText,
          hintStyle: theme.textTheme.bodyMedium?.copyWith(
            color: HasimTokens.mutedColor(context),
            fontWeight: FontWeight.w500,
          ),
          prefixIcon: Icon(
            Icons.search_rounded,
            size: 20,
            color: HasimTokens.mutedColor(context),
          ),
          prefixIconConstraints: const BoxConstraints(
            minWidth: 40,
            minHeight: 40,
          ),
          suffixIcon: showClear
              ? IconButton(
                  tooltip: 'مسح',
                  visualDensity: VisualDensity.compact,
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(
                    minWidth: 36,
                    minHeight: 40,
                  ),
                  onPressed: () {
                    final keepFocus = _focus.hasFocus;
                    _controller.clear();
                    widget.onClear?.call();
                    widget.onChanged?.call('');
                    if (keepFocus) _focus.requestFocus();
                  },
                  icon: Icon(
                    Icons.close_rounded,
                    size: 18,
                    color: HasimTokens.mutedColor(context),
                  ),
                )
              : null,
          suffixIconConstraints: const BoxConstraints(
            minWidth: 36,
            minHeight: 40,
          ),
          filled: true,
          fillColor: Colors.transparent,
          isDense: true,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 16,
            vertical: 10,
          ),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(HasimTokens.radiusSearch),
            borderSide: BorderSide.none,
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(HasimTokens.radiusSearch),
            borderSide: BorderSide.none,
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(HasimTokens.radiusSearch),
            borderSide: widget.flat
                ? BorderSide.none
                : const BorderSide(color: AppTheme.brand, width: 1.2),
          ),
        ),
      ),
    );
  }
}
