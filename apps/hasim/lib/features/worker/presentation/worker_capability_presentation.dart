import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';

class WorkerCapabilityPresentation {
  const WorkerCapabilityPresentation({
    required this.key,
    required this.title,
    required this.description,
    required this.category,
    required this.icon,
    required this.categoryColor,
  });

  final String key;
  final String title;
  final String description;
  final String category;
  final IconData icon;
  final Color categoryColor;

  static WorkerCapabilityPresentation fromCapability(
    WorkerCapability capability,
  ) {
    return switch (capability.key) {
      'inbox.messages.read' => const WorkerCapabilityPresentation(
        key: 'inbox.messages.read',
        title: 'قراءة الرسائل الواردة',
        description: 'قراءة الرسائل من صندوق الوارد المصرّح لك به.',
        category: 'المحادثات',
        icon: Icons.chat_bubble_outline_rounded,
        categoryColor: HasimTokens.accentDark,
      ),
      'inbox.messages.send' => const WorkerCapabilityPresentation(
        key: 'inbox.messages.send',
        title: 'إرسال رسائل الوارد',
        description: 'إرسال الردود من صندوق الوارد المصرّح لك به.',
        category: 'المحادثات',
        icon: Icons.send_outlined,
        categoryColor: HasimTokens.primary,
      ),
      'customers.view' => const WorkerCapabilityPresentation(
        key: 'customers.view',
        title: 'عرض العملاء',
        description: 'عرض سجلات العملاء المتاحة ضمن الارتباط.',
        category: 'العملاء',
        icon: Icons.groups_outlined,
        categoryColor: HasimTokens.accentDark,
      ),
      'customers.phone.view' => const WorkerCapabilityPresentation(
        key: 'customers.phone.view',
        title: 'عرض أرقام هواتف العملاء',
        description: 'عرض أرقام هواتف العملاء عند منح هذه الصلاحية.',
        category: 'العملاء',
        icon: Icons.phone_outlined,
        categoryColor: HasimTokens.primary,
      ),
      'customers.email.view' => const WorkerCapabilityPresentation(
        key: 'customers.email.view',
        title: 'عرض عناوين البريد الإلكتروني للعملاء',
        description:
            'عرض عناوين البريد الإلكتروني للعملاء عند منح هذه الصلاحية.',
        category: 'العملاء',
        icon: Icons.mail_outline_rounded,
        categoryColor: HasimTokens.danger,
      ),
      'catalog.products.view' => const WorkerCapabilityPresentation(
        key: 'catalog.products.view',
        title: 'عرض المنتجات',
        description: 'عرض المنتجات المتاحة في كتالوج الشركة.',
        category: 'المنتجات',
        icon: Icons.inventory_2_outlined,
        categoryColor: HasimTokens.accentDark,
      ),
      'commerce.orders.view' => const WorkerCapabilityPresentation(
        key: 'commerce.orders.view',
        title: 'عرض الطلبات',
        description: 'عرض معلومات الطلبات المتاحة ضمن الارتباط.',
        category: 'الطلبات',
        icon: Icons.shopping_bag_outlined,
        categoryColor: HasimTokens.warning,
      ),
      'commerce.orders.create' => const WorkerCapabilityPresentation(
        key: 'commerce.orders.create',
        title: 'إنشاء الطلبات',
        description: 'إنشاء الطلبات من خلال عملية الشركة المصرّح بها.',
        category: 'الطلبات',
        icon: Icons.add_shopping_cart_outlined,
        categoryColor: HasimTokens.success,
      ),
      'commerce.payment_links.create' => const WorkerCapabilityPresentation(
        key: 'commerce.payment_links.create',
        title: 'إنشاء روابط الدفع',
        description: 'إنشاء روابط دفع من خلال عملية الشركة المصرّح بها.',
        category: 'الدفع',
        icon: Icons.link_rounded,
        categoryColor: HasimTokens.success,
      ),
      'reports.view' => const WorkerCapabilityPresentation(
        key: 'reports.view',
        title: 'عرض التقارير',
        description: 'عرض تقارير الشركة المتاحة ضمن الارتباط.',
        category: 'التقارير',
        icon: Icons.bar_chart_rounded,
        categoryColor: HasimTokens.primary,
      ),
      'email.view' => const WorkerCapabilityPresentation(
        key: 'email.view',
        title: 'عرض البريد',
        description: 'عرض رسائل البريد وبياناتها للارتباط المصرّح به.',
        category: 'البريد',
        icon: Icons.mail_outline_rounded,
        categoryColor: HasimTokens.danger,
      ),
      'stories.view' => const WorkerCapabilityPresentation(
        key: 'stories.view',
        title: 'عرض التحديثات',
        description: 'عرض تحديثات الشركة دون نشر أو حذف تحديثات.',
        category: 'التحديثات',
        icon: Icons.auto_stories_outlined,
        categoryColor: HasimTokens.accentDark,
      ),
      _ => WorkerCapabilityPresentation(
        key: capability.key,
        title: 'صلاحية تشغيلية إضافية',
        description: 'أداة متاحة لك ضمن هذا الارتباط.',
        category: 'أدوات أخرى',
        icon: Icons.extension_outlined,
        categoryColor: HasimTokens.muted,
      ),
    };
  }
}
