export interface Tier {
  value: string;
  label: string;
  maxActivations: number;
  features: string[];
}

export interface License {
  id: number;
  keyPrefix: string;
  customerName: string;
  customerEmail: string;
  role: 'owner' | 'customer';
  tier: string;
  status: string;
  maxActivations: number;
  currentActivations: number;
  paymentInterval: string;
  autoRenewal: boolean;
  notes: string | null;
  createdAt: string;
  validUntil: string;
}

export interface AdminConfig {
  restBase: string;
  nonce: string;
  tiers: Tier[];
  pageTitle: string;
  status: string;
  encryptionKeySource: 'constant' | 'database';
  developmentMode: boolean;
}
