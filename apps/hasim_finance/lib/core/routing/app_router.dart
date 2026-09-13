import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/routing/finance_shell.dart';
import 'package:hasim_finance/features/auth/forgot_password_screen.dart';
import 'package:hasim_finance/features/auth/login_screen.dart';
import 'package:hasim_finance/features/auth/workspace_gate_screens.dart';
import 'package:hasim_finance/features/customers/customers_screens.dart';
import 'package:hasim_finance/features/dashboard/dashboard_screen.dart';
import 'package:hasim_finance/features/invoices/invoices_screens.dart';
import 'package:hasim_finance/features/modules/module_screens.dart';
import 'package:hasim_finance/features/quotes/quotes_screens.dart';
import 'package:hasim_finance/features/hubs/hub_screens.dart';
import 'package:hasim_finance/features/catalog/catalog_screens.dart';
import 'package:hasim_finance/features/people/people_screens.dart';

const _publicAuth = {'/login', '/forgot-password', '/reset-password'};

final appRouterProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<int>(0);
  ref.onDispose(refresh.dispose);
  ref.listen<AuthState>(authControllerProvider, (prev, next) {
    if (prev?.isAuthenticated != next.isAuthenticated ||
        prev?.isLoading != next.isLoading ||
        prev?.financeEnabled != next.financeEnabled ||
        prev?.needsWorkspaceSelection != next.needsWorkspaceSelection ||
        prev?.workspace?.id != next.workspace?.id) {
      refresh.value++;
    }
  });

  return GoRouter(
    initialLocation: '/splash',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final loc = state.matchedLocation;
      if (auth.isLoading) {
        return loc == '/splash' ? null : '/splash';
      }
      if (!auth.isAuthenticated) {
        if (_publicAuth.contains(loc)) return null;
        return '/login';
      }
      if (auth.needsWorkspaceSelection) {
        return loc == '/workspaces' ? null : '/workspaces';
      }
      if (!auth.financeEnabled) {
        return loc == '/finance-unavailable' ? null : '/finance-unavailable';
      }
      if (loc == '/login' ||
          loc == '/splash' ||
          loc == '/forgot-password' ||
          loc == '/reset-password' ||
          loc == '/workspaces' ||
          loc == '/finance-unavailable') {
        return '/dashboard';
      }
      return null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const SplashScreen()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/forgot-password', builder: (_, _) => const ForgotPasswordScreen()),
      GoRoute(path: '/reset-password', builder: (_, _) => const ResetPasswordScreen()),
      GoRoute(path: '/workspaces', builder: (_, _) => const WorkspaceSelectScreen()),
      GoRoute(path: '/finance-unavailable', builder: (_, _) => const FinanceUnavailableScreen()),
      ShellRoute(
        builder: (context, state, child) => FinanceShell(child: child),
        routes: [
          GoRoute(path: '/dashboard', builder: (_, _) => const DashboardScreen()),
          GoRoute(path: '/search', builder: (_, _) => const FinanceSearchScreen()),
          GoRoute(path: '/customers', builder: (_, _) => const CustomersScreen()),
          GoRoute(path: '/customers/new', builder: (_, _) => const CustomerFormScreen()),
          GoRoute(
            path: '/customers/:id',
            builder: (_, state) => CustomerDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/customers/:id/edit',
            builder: (_, state) => CustomerFormScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/quotes', builder: (_, _) => const QuotesScreen()),
          GoRoute(path: '/quotes/new', builder: (_, _) => const QuoteFormScreen()),
          GoRoute(
            path: '/quotes/:id',
            builder: (_, state) => QuoteDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/quotes/:id/edit',
            builder: (_, state) => QuoteFormScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/invoices', builder: (_, _) => const InvoicesScreen()),
          GoRoute(path: '/invoices/new', builder: (_, _) => const InvoiceFormScreen()),
          GoRoute(
            path: '/invoices/:id',
            builder: (_, state) => InvoiceDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/invoices/:id/edit',
            builder: (_, state) => InvoiceFormScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/payments', builder: (_, _) => const PaymentsScreen()),
          GoRoute(
            path: '/payments/:id',
            builder: (_, state) => PaymentDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/receipts', builder: (_, _) => const ReceiptsScreen()),
          GoRoute(
            path: '/receipts/:id',
            builder: (_, state) => ReceiptDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/statements',
            builder: (_, state) {
              final raw = state.uri.queryParameters['customer_id'];
              return StatementScreen(customerId: int.tryParse(raw ?? ''));
            },
          ),
          GoRoute(path: '/notes', builder: (_, _) => const NotesScreen()),
          GoRoute(
            path: '/notes/new',
            builder: (_, state) {
              final raw = state.uri.queryParameters['invoice_id'];
              return NoteFormScreen(invoiceId: int.tryParse(raw ?? ''));
            },
          ),
          GoRoute(
            path: '/notes/:id',
            builder: (_, state) => NoteDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/contracts', builder: (_, _) => const ContractsScreen()),
          GoRoute(path: '/contracts/new', builder: (_, _) => const ContractFormScreen()),
          GoRoute(
            path: '/contracts/:id/edit',
            builder: (_, state) => ContractFormScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/contracts/:id',
            builder: (_, state) => ContractDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/expenses', builder: (_, _) => const ExpensesScreen()),
          GoRoute(path: '/expenses/new', builder: (_, _) => const ExpenseFormScreen()),
          GoRoute(
            path: '/expenses/:id/edit',
            builder: (_, state) => ExpenseFormScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/expenses/:id',
            builder: (_, state) => ExpenseDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/purchases', builder: (_, _) => const PurchasesScreen()),
          GoRoute(path: '/purchases/new', builder: (_, _) => const PurchaseFormScreen()),
          GoRoute(
            path: '/purchases/:id/edit',
            builder: (_, state) => PurchaseFormScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/purchases/:id',
            builder: (_, state) => PurchaseDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/reports', builder: (_, _) => const ReportsScreen()),
          GoRoute(path: '/settings', builder: (_, _) => const SettingsScreen()),
          GoRoute(path: '/sales', builder: (_, _) => const SalesHubScreen()),
          GoRoute(path: '/billing', builder: (_, _) => const BillingHubScreen()),
          GoRoute(path: '/vat', builder: (_, _) => const VatHubScreen()),
          GoRoute(path: '/alerts', builder: (_, _) => const AlertsScreen()),
          GoRoute(path: '/accounting', builder: (_, _) => const AccountingHubScreen()),
          GoRoute(path: '/copilot', builder: (_, _) => const CopilotScreen()),
          GoRoute(path: '/banks', builder: (_, _) => const BanksScreen()),
          GoRoute(path: '/treasury', builder: (_, _) => const TreasuryScreen()),
          GoRoute(
            path: '/treasury/statements/:id',
            builder: (_, state) => BankStatementScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/exports', builder: (_, _) => const ExportsScreen()),
          GoRoute(path: '/fiscal-years', builder: (_, _) => const FiscalYearsScreen()),
          GoRoute(
            path: '/fiscal-years/:id',
            builder: (_, state) => FiscalYearDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/products', builder: (_, _) => const ProductsScreen()),
          GoRoute(
            path: '/products/:id',
            builder: (_, state) => ProductDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/inventory', builder: (_, _) => const InventoryScreen()),
          GoRoute(path: '/projects', builder: (_, _) => const ProjectsScreen()),
          GoRoute(path: '/projects/new', builder: (_, _) => const ProjectFormScreen()),
          GoRoute(
            path: '/projects/:id',
            builder: (_, state) => ProjectDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/price-lists', builder: (_, _) => const PriceListsScreen()),
          GoRoute(path: '/price-lists/new', builder: (_, _) => const PriceListFormScreen()),
          GoRoute(
            path: '/price-lists/:id',
            builder: (_, state) => PriceListDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/purchase-orders', builder: (_, _) => const PurchaseOrdersScreen()),
          GoRoute(path: '/purchase-orders/new', builder: (_, _) => const PurchaseOrderFormScreen()),
          GoRoute(
            path: '/purchase-orders/:id',
            builder: (_, state) => PurchaseOrderDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/leads', builder: (_, _) => const LeadsScreen()),
          GoRoute(path: '/leads/new', builder: (_, _) => const LeadFormScreen()),
          GoRoute(
            path: '/leads/:id',
            builder: (_, state) => LeadDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/suppliers', builder: (_, _) => const SuppliersScreen()),
          GoRoute(path: '/suppliers/new', builder: (_, _) => const SupplierFormScreen()),
          GoRoute(
            path: '/suppliers/:id',
            builder: (_, state) => SupplierDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/suppliers/:id/edit',
            builder: (_, state) => SupplierFormScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/people', builder: (_, _) => const PeopleScreen()),
          GoRoute(path: '/people/new', builder: (_, _) => const PersonFormScreen()),
          GoRoute(
            path: '/people/:id',
            builder: (_, state) => PersonDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/people/:id/edit',
            builder: (_, state) => PersonFormScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(path: '/payroll', builder: (_, _) => const PayrollOverviewScreen()),
          GoRoute(path: '/advances', builder: (_, _) => const SalaryAdvancesScreen()),
          GoRoute(path: '/allowances', builder: (_, _) => const PayrollAdjustmentsScreen(type: 'allowance')),
          GoRoute(path: '/bonuses', builder: (_, _) => const PayrollAdjustmentsScreen(type: 'bonus')),
          GoRoute(path: '/deductions', builder: (_, _) => const PayrollAdjustmentsScreen(type: 'deduction')),
        ],
      ),
    ],
  );
});
