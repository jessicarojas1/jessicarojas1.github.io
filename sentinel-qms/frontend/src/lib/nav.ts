import type { LucideIcon } from 'lucide-react';
import {
  Award,
  BookOpen,
  Boxes,
  Briefcase,
  ClipboardCheck,
  FileBarChart,
  FileText,
  FlaskConical,
  GaugeCircle,
  GitPullRequestArrow,
  GraduationCap,
  History,
  KeyRound,
  LayoutDashboard,
  MessageSquareWarning,
  ScrollText,
  Settings,
  ShieldAlert,
  TrendingUp,
  Truck,
  Users,
  Wrench,
} from 'lucide-react';
import type { Capability } from './rbac';

export interface NavItem {
  label: string;
  to: string;
  icon: LucideIcon;
  /** Static capability used as the lockout-safe fallback. */
  capability: Capability;
  /** Dynamic page key, gated via usePagePerms().canView. */
  page?: string;
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

export const NAV_GROUPS: NavGroup[] = [
  {
    label: 'Overview',
    items: [
      { label: 'Dashboard', to: '/', icon: LayoutDashboard, capability: 'ncr.read', page: 'dashboard' },
      { label: 'Executive', to: '/executive', icon: Briefcase, capability: 'ncr.read', page: 'dashboard' },
      { label: 'Analytics', to: '/analytics', icon: TrendingUp, capability: 'ncr.read', page: 'analytics' },
      { label: 'Reports', to: '/reports', icon: FileBarChart, capability: 'ncr.read', page: 'analytics' },
      { label: 'Documentation', to: '/docs', icon: BookOpen, capability: 'docs.read', page: 'documentation' },
    ],
  },
  {
    label: 'Quality Events',
    items: [
      { label: 'Nonconformances', to: '/nonconformances', icon: ShieldAlert, capability: 'ncr.read', page: 'nonconformances' },
      { label: 'CAPA', to: '/capa', icon: ClipboardCheck, capability: 'capa.read', page: 'capa' },
      { label: 'Complaints / RMA', to: '/complaints', icon: MessageSquareWarning, capability: 'complaints.read', page: 'complaints' },
      { label: 'Risk Register', to: '/risks', icon: ShieldAlert, capability: 'risks.read', page: 'risks' },
    ],
  },
  {
    label: 'Control',
    items: [
      { label: 'Documents', to: '/documents', icon: FileText, capability: 'documents.read', page: 'documents' },
      { label: 'Change Control', to: '/changes', icon: GitPullRequestArrow, capability: 'changes.read', page: 'changes' },
      { label: 'Audits', to: '/audits', icon: ScrollText, capability: 'audits.read', page: 'audits' },
      { label: 'Inspections / FAI', to: '/inspections', icon: FlaskConical, capability: 'inspections.read', page: 'inspections' },
    ],
  },
  {
    label: 'Operations',
    items: [
      { label: 'Suppliers', to: '/suppliers', icon: Truck, capability: 'suppliers.read', page: 'suppliers' },
      { label: 'Calibration', to: '/calibration', icon: Wrench, capability: 'calibration.read', page: 'calibration' },
      { label: 'Training', to: '/training', icon: GraduationCap, capability: 'training.read', page: 'training' },
      { label: 'Management Review', to: '/mgmt-reviews', icon: GaugeCircle, capability: 'mgmt_reviews.read', page: 'mgmt_reviews' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { label: 'Users', to: '/admin/users', icon: Users, capability: 'admin.users', page: 'users' },
      { label: 'Roles', to: '/admin/roles', icon: Award, capability: 'admin.roles', page: 'roles' },
      { label: 'Permissions', to: '/admin/permissions', icon: KeyRound, capability: 'admin.roles', page: 'permissions' },
      { label: 'Audit Trail', to: '/admin/audit-trail', icon: History, capability: 'admin.users', page: 'audit_trail' },
      { label: 'Settings', to: '/admin/settings', icon: Settings, capability: 'admin.users' },
    ],
  },
];

// Re-export icon used by brand for convenience.
export const BrandIcon = Boxes;
