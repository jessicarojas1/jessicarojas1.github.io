export type Role =
  | 'admin'
  | 'quality_manager'
  | 'quality_engineer'
  | 'auditor'
  | 'supplier_quality'
  | 'operator'
  | 'read_only'
  | 'customer';

export const ROLE_LABELS: Record<Role, string> = {
  admin: 'Administrator',
  quality_manager: 'Quality Manager',
  quality_engineer: 'Quality Engineer',
  auditor: 'Auditor',
  supplier_quality: 'Supplier Quality',
  operator: 'Operator',
  read_only: 'Read-Only',
  customer: 'Customer',
};

export interface User {
  id: string;
  username: string;
  email: string;
  full_name: string;
  roles: Role[];
  department?: string;
  title?: string;
  is_active: boolean;
  last_login_at?: string;
  created_at: string;
}

export interface LoginRequest {
  username: string;
  password: string;
}

export interface TokenResponse {
  access_token: string;
  refresh_token: string;
  token_type: 'bearer';
  expires_in: number;
}

export interface RefreshRequest {
  refresh_token: string;
}
