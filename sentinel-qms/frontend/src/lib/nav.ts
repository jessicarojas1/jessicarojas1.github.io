import type { LucideIcon } from 'lucide-react';
import {
  Award,
  BookOpen,
  Boxes,
  ClipboardCheck,
  FileText,
  FlaskConical,
  GaugeCircle,
  GitPullRequestArrow,
  GraduationCap,
  History,
  LayoutDashboard,
  MessageSquareWarning,
  ScrollText,
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
  capability: Capability;
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

export const NAV_GROUPS: NavGroup[] = [
  {
    label: 'Overview',
    items: [
      { label: 'Dashboard', to: '/', icon: LayoutDashboard, capability: 'ncr.read' },
      { label: 'Analytics', to: '/analytics', icon: TrendingUp, capability: 'ncr.read' },
      { label: 'Documentation', to: '/docs', icon: BookOpen, capability: 'docs.read' },
    ],
  },
  {
    label: 'Quality Events',
    items: [
      { label: 'Nonconformances', to: '/nonconformances', icon: ShieldAlert, capability: 'ncr.read' },
      { label: 'CAPA', to: '/capa', icon: ClipboardCheck, capability: 'capa.read' },
      { label: 'Complaints / RMA', to: '/complaints', icon: MessageSquareWarning, capability: 'complaints.read' },
      { label: 'Risk Register', to: '/risks', icon: ShieldAlert, capability: 'risks.read' },
    ],
  },
  {
    label: 'Control',
    items: [
      { label: 'Documents', to: '/documents', icon: FileText, capability: 'documents.read' },
      { label: 'Change Control', to: '/changes', icon: GitPullRequestArrow, capability: 'changes.read' },
      { label: 'Audits', to: '/audits', icon: ScrollText, capability: 'audits.read' },
      { label: 'Inspections / FAI', to: '/inspections', icon: FlaskConical, capability: 'inspections.read' },
    ],
  },
  {
    label: 'Operations',
    items: [
      { label: 'Suppliers', to: '/suppliers', icon: Truck, capability: 'suppliers.read' },
      { label: 'Calibration', to: '/calibration', icon: Wrench, capability: 'calibration.read' },
      { label: 'Training', to: '/training', icon: GraduationCap, capability: 'training.read' },
      { label: 'Management Review', to: '/mgmt-reviews', icon: GaugeCircle, capability: 'mgmt_reviews.read' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { label: 'Users', to: '/admin/users', icon: Users, capability: 'admin.users' },
      { label: 'Roles', to: '/admin/roles', icon: Award, capability: 'admin.roles' },
      { label: 'Audit Trail', to: '/admin/audit-trail', icon: History, capability: 'admin.users' },
    ],
  },
];

// Re-export icon used by brand for convenience.
export const BrandIcon = Boxes;
