class FinancePermissions {
  const FinancePermissions(this._raw);

  final Map<String, bool> _raw;

  bool can(String key) => _raw[key] == true;

  bool get customersView => can('customers.view');
  bool get customersCreate => can('customers.create') || can('customers.edit');
  bool get quotesView => can('quotes.view');
  bool get quotesCreate => can('quotes.create');
  bool get invoicesView => can('invoices.view');
  bool get invoicesCreate => can('invoices.create');
  bool get invoicesDelete => can('invoices.delete');
  bool get quotesDelete => can('quotes.delete');
  bool get paymentsView => can('payments.view');
  bool get paymentsManage => can('payments.manage');
  bool get receiptsView => can('receipts.view');
  bool get statementsView => can('statements.view') || can('invoices.view');
  bool get notesView => can('notes.view') || can('invoices.view');
  bool get contractsView => can('contracts.view');
  bool get expensesView => can('expenses.view');
  bool get purchasesView => can('purchases.view');
  bool get purchasesCreate => can('purchases.create') || can('purchases.edit') || can('purchases.manage');
  bool get purchasesManage => can('purchases.manage') || can('purchases.create');
  bool get expensesCreate => can('expenses.create') || can('expenses.edit');
  bool get contractsCreate => can('contracts.create') || can('contracts.edit') || can('contracts.manage');
  bool get contractsManage => can('contracts.manage') || can('contracts.edit') || can('contracts.create');
  bool get notesCreate => can('notes.create') || can('invoices.credit');
  bool get reportsView => can('reports.view');
  bool get settings => can('finance.settings');
  bool get financeView => can('finance.view');
  bool get financeManage => can('finance.manage');
  bool get accountingView => can('accounting.view');
  bool get accountingManage => can('accounting.manage');
  bool get priceListsView => can('finance.price_lists.view');
  bool get priceListsManage => can('finance.price_lists.manage');
  bool get fiscalYearsView => can('finance.fiscal_years.view');
  bool get fiscalYearsManage => can('finance.fiscal_years.manage');
  bool get payrollView => can('payroll.view');
  bool get payrollManage => can('payroll.manage');
  bool get adjustmentsView => can('finance.adjustments.view');
  bool get adjustmentsManage => can('finance.adjustments.manage');
  bool get salaryAdvancesView => can('finance.salary_advances.view');
  bool get salaryAdvancesManage => can('finance.salary_advances.manage');

  FinancePermissions copyWith(Map<String, bool> next) => FinancePermissions(next);
}
