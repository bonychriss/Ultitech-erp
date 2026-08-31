import type { FinancialAccount } from '../types';

/** Map payment method label to financial_account `type` values. */
export function accountTypesForPaymentMethod(method: string): string[] | null {
  const normalized = method.trim().toLowerCase();
  if (!normalized) {
    return null;
  }

  if (normalized === 'cash') {
    return ['cash'];
  }

  if (normalized === 'mobile money') {
    return ['mobile'];
  }

  if (
    normalized === 'bank transfer'
    || normalized === 'rtgs / swift'
    || normalized === 'cheque'
    || normalized.includes('bank')
    || normalized.includes('swift')
    || normalized.includes('rtgs')
    || normalized.includes('cheque')
  ) {
    return ['bank'];
  }

  if (normalized.includes('mobile')) {
    return ['mobile'];
  }

  if (normalized.includes('cash')) {
    return ['cash'];
  }

  return null;
}

export function filterAccountsByPaymentMethod(
  accounts: FinancialAccount[],
  paymentMethod: string,
): FinancialAccount[] {
  const types = accountTypesForPaymentMethod(paymentMethod);
  if (!types) {
    return [];
  }

  return accounts.filter((account) => types.includes(account.type.trim().toLowerCase()));
}
