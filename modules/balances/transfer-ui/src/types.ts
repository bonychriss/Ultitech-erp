export type TransferAccount = {
  id: number;
  name: string;
  type: string;
  currency: string;
  balance: number;
  bucket: string;
};

export type TransferInit = {
  success: true;
  accounts: TransferAccount[];
  defaults: {
    transferDate: string;
    referenceNo: string;
    currency: string;
    exchangeRate: string;
  };
  historyUrl: string;
  transferUrl: string;
  flashSuccess: string;
};

export type TransferFormState = {
  transferDate: string;
  referenceNo: string;
  description: string;
  fromAccount: number;
  toAccount: number;
  currency: string;
  amount: string;
  exchangeRate: string;
};
