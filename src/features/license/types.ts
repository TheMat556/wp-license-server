export type LicenseStatus =
  | 'active'
  | 'expired'
  | 'suspended'
  | 'cancelled'
  | 'unknown'
  | 'disabled'
  | 'grace';

export type LicenseFeature = 'chat' | 'advanced_analytics' | 'custom_branding';

export interface License {
  id: number;
  key: string;
  status: LicenseStatus;
  features: LicenseFeature[];
  expiresAt: string | null;
  domain: string;
  graceDaysRemaining?: number;
}
