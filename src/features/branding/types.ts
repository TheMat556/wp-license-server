export interface Branding {
  siteName: string;
  logoUrl: string | null;
  primaryColor: string;
  openInNewTabPatterns: string[];
}

export interface BrandingDraft {
  siteName?: string;
  logoUrl?: string | null;
  primaryColor?: string;
  openInNewTabPatterns?: string[];
}

export interface BrandingApiSaveResult {
  success: boolean;
  data: Branding;
}
