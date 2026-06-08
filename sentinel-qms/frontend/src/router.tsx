import { lazy, Suspense, type ReactNode } from 'react';
import { Route, Routes } from 'react-router-dom';
import { Layout } from './components/Layout';
import { ProtectedRoute } from './components/ProtectedRoute';
import type { Capability } from './lib/rbac';

const LoginPage = lazy(() => import('./pages/LoginPage'));
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const ExecutiveDashboardPage = lazy(() => import('./pages/ExecutiveDashboardPage'));
const StandardsPage = lazy(() => import('./pages/standards/StandardsPage'));
const StandardDetailPage = lazy(() => import('./pages/standards/StandardDetailPage'));
const CounterfeitPage = lazy(() => import('./pages/counterfeit/CounterfeitPage'));
const ApqpListPage = lazy(() => import('./pages/apqp/ApqpListPage'));
const ApqpDetailPage = lazy(() => import('./pages/apqp/ApqpDetailPage'));
const FodPage = lazy(() => import('./pages/fod/FodPage'));
const MsaPage = lazy(() => import('./pages/msa/MsaPage'));
const KcListPage = lazy(() => import('./pages/spc/KcListPage'));
const KcDetailPage = lazy(() => import('./pages/spc/KcDetailPage'));
const ConcessionsPage = lazy(() => import('./pages/concessions/ConcessionsPage'));
const CustomersPage = lazy(() => import('./pages/customers/CustomersPage'));
const ContractDetailPage = lazy(() => import('./pages/customers/ContractDetailPage'));
const AuditProgramsPage = lazy(() => import('./pages/auditPrograms/AuditProgramsPage'));
const AuditProgramDetailPage = lazy(() => import('./pages/auditPrograms/AuditProgramDetailPage'));
const DocumentationPage = lazy(() => import('./pages/docs/DocumentationPage'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));

const NcrListPage = lazy(() => import('./pages/nonconformances/NcrListPage'));
const NcrDetailPage = lazy(() => import('./pages/nonconformances/NcrDetailPage'));
const CapaListPage = lazy(() => import('./pages/capa/CapaListPage'));
const CapaDetailPage = lazy(() => import('./pages/capa/CapaDetailPage'));
const DocumentListPage = lazy(() => import('./pages/documents/DocumentListPage'));
const DocumentDetailPage = lazy(() => import('./pages/documents/DocumentDetailPage'));
const AuditListPage = lazy(() => import('./pages/audits/AuditListPage'));
const AuditDetailPage = lazy(() => import('./pages/audits/AuditDetailPage'));
const SupplierListPage = lazy(() => import('./pages/suppliers/SupplierListPage'));
const SupplierDetailPage = lazy(() => import('./pages/suppliers/SupplierDetailPage'));
const CalibrationListPage = lazy(() => import('./pages/calibration/CalibrationListPage'));
const CalibrationDetailPage = lazy(() => import('./pages/calibration/CalibrationDetailPage'));
const TrainingListPage = lazy(() => import('./pages/training/TrainingListPage'));
const ChangeListPage = lazy(() => import('./pages/changes/ChangeListPage'));
const ChangeDetailPage = lazy(() => import('./pages/changes/ChangeDetailPage'));
const RiskListPage = lazy(() => import('./pages/risks/RiskListPage'));
const InspectionListPage = lazy(() => import('./pages/inspections/InspectionListPage'));
const InspectionDetailPage = lazy(() => import('./pages/inspections/InspectionDetailPage'));
const MgmtReviewListPage = lazy(() => import('./pages/mgmtReviews/MgmtReviewListPage'));
const MgmtReviewDetailPage = lazy(() => import('./pages/mgmtReviews/MgmtReviewDetailPage'));
const ComplaintListPage = lazy(() => import('./pages/complaints/ComplaintListPage'));
const ComplaintDetailPage = lazy(() => import('./pages/complaints/ComplaintDetailPage'));
const UsersPage = lazy(() => import('./pages/admin/UsersPage'));
const RolesPage = lazy(() => import('./pages/admin/RolesPage'));
const PermissionsPage = lazy(() => import('./pages/admin/PermissionsPage'));
const AuditTrailPage = lazy(() => import('./pages/admin/AuditTrailPage'));
const SettingsPage = lazy(() => import('./pages/admin/SettingsPage'));
const AnalyticsPage = lazy(() => import('./pages/AnalyticsPage'));
const ReportsPage = lazy(() => import('./pages/ReportsPage'));

function PageFallback() {
  return (
    <div className="loading-block" style={{ minHeight: '50vh' }}>
      <span className="spinner spinner--lg" />
    </div>
  );
}

function Guard({
  capability,
  page,
  children,
}: {
  capability?: Capability;
  page?: string;
  children: ReactNode;
}) {
  return (
    <ProtectedRoute capability={capability} page={page}>
      {children}
    </ProtectedRoute>
  );
}

export function AppRouter() {
  return (
    <Suspense fallback={<PageFallback />}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />

        <Route
          element={
            <Guard>
              <Layout />
            </Guard>
          }
        >
          <Route index element={<Guard page="dashboard" capability="ncr.read"><DashboardPage /></Guard>} />
          <Route
            path="executive"
            element={<Guard page="dashboard" capability="ncr.read"><ExecutiveDashboardPage /></Guard>}
          />
          <Route path="standards">
            <Route index element={<Guard page="standards" capability="ncr.read"><StandardsPage /></Guard>} />
            <Route path=":id" element={<Guard page="standards" capability="ncr.read"><StandardDetailPage /></Guard>} />
          </Route>
          <Route
            path="counterfeit"
            element={<Guard page="suppliers" capability="suppliers.read"><CounterfeitPage /></Guard>}
          />
          <Route path="apqp">
            <Route index element={<Guard page="inspections" capability="inspections.read"><ApqpListPage /></Guard>} />
            <Route path=":id" element={<Guard page="inspections" capability="inspections.read"><ApqpDetailPage /></Guard>} />
          </Route>
          <Route
            path="fod"
            element={<Guard page="inspections" capability="inspections.read"><FodPage /></Guard>}
          />
          <Route
            path="msa"
            element={<Guard page="calibration" capability="calibration.read"><MsaPage /></Guard>}
          />
          <Route path="key-characteristics">
            <Route index element={<Guard page="inspections" capability="inspections.read"><KcListPage /></Guard>} />
            <Route path=":id" element={<Guard page="inspections" capability="inspections.read"><KcDetailPage /></Guard>} />
          </Route>
          <Route
            path="concessions"
            element={<Guard page="nonconformances" capability="ncr.read"><ConcessionsPage /></Guard>}
          />
          <Route path="customers">
            <Route index element={<Guard page="suppliers" capability="suppliers.read"><CustomersPage /></Guard>} />
            <Route path="contracts/:id" element={<Guard page="suppliers" capability="suppliers.read"><ContractDetailPage /></Guard>} />
          </Route>
          <Route path="audit-programs">
            <Route index element={<Guard page="audits" capability="audits.read"><AuditProgramsPage /></Guard>} />
            <Route path=":id" element={<Guard page="audits" capability="audits.read"><AuditProgramDetailPage /></Guard>} />
          </Route>

          <Route
            path="docs"
            element={<Guard page="documentation" capability="docs.read"><DocumentationPage /></Guard>}
          />

          <Route
            path="analytics"
            element={<Guard page="analytics" capability="ncr.read"><AnalyticsPage /></Guard>}
          />

          <Route
            path="reports"
            element={<Guard page="analytics" capability="ncr.read"><ReportsPage /></Guard>}
          />

          <Route path="nonconformances">
            <Route index element={<Guard page="nonconformances" capability="ncr.read"><NcrListPage /></Guard>} />
            <Route path=":id" element={<Guard page="nonconformances" capability="ncr.read"><NcrDetailPage /></Guard>} />
          </Route>

          <Route path="capa">
            <Route index element={<Guard page="capa" capability="capa.read"><CapaListPage /></Guard>} />
            <Route path=":id" element={<Guard page="capa" capability="capa.read"><CapaDetailPage /></Guard>} />
          </Route>

          <Route path="documents">
            <Route index element={<Guard page="documents" capability="documents.read"><DocumentListPage /></Guard>} />
            <Route path=":id" element={<Guard page="documents" capability="documents.read"><DocumentDetailPage /></Guard>} />
          </Route>

          <Route path="audits">
            <Route index element={<Guard page="audits" capability="audits.read"><AuditListPage /></Guard>} />
            <Route path=":id" element={<Guard page="audits" capability="audits.read"><AuditDetailPage /></Guard>} />
          </Route>

          <Route path="suppliers">
            <Route index element={<Guard page="suppliers" capability="suppliers.read"><SupplierListPage /></Guard>} />
            <Route path=":id" element={<Guard page="suppliers" capability="suppliers.read"><SupplierDetailPage /></Guard>} />
          </Route>

          <Route path="calibration">
            <Route index element={<Guard page="calibration" capability="calibration.read"><CalibrationListPage /></Guard>} />
            <Route path=":id" element={<Guard page="calibration" capability="calibration.read"><CalibrationDetailPage /></Guard>} />
          </Route>

          <Route
            path="training"
            element={<Guard page="training" capability="training.read"><TrainingListPage /></Guard>}
          />

          <Route path="changes">
            <Route index element={<Guard page="changes" capability="changes.read"><ChangeListPage /></Guard>} />
            <Route path=":id" element={<Guard page="changes" capability="changes.read"><ChangeDetailPage /></Guard>} />
          </Route>

          <Route
            path="risks"
            element={<Guard page="risks" capability="risks.read"><RiskListPage /></Guard>}
          />

          <Route path="inspections">
            <Route index element={<Guard page="inspections" capability="inspections.read"><InspectionListPage /></Guard>} />
            <Route path=":id" element={<Guard page="inspections" capability="inspections.read"><InspectionDetailPage /></Guard>} />
          </Route>

          <Route path="mgmt-reviews">
            <Route index element={<Guard page="mgmt_reviews" capability="mgmt_reviews.read"><MgmtReviewListPage /></Guard>} />
            <Route path=":id" element={<Guard page="mgmt_reviews" capability="mgmt_reviews.read"><MgmtReviewDetailPage /></Guard>} />
          </Route>

          <Route path="complaints">
            <Route index element={<Guard page="complaints" capability="complaints.read"><ComplaintListPage /></Guard>} />
            <Route path=":id" element={<Guard page="complaints" capability="complaints.read"><ComplaintDetailPage /></Guard>} />
          </Route>

          <Route path="admin">
            <Route path="users" element={<Guard page="users" capability="admin.users"><UsersPage /></Guard>} />
            <Route path="roles" element={<Guard page="roles" capability="admin.roles"><RolesPage /></Guard>} />
            <Route path="permissions" element={<Guard page="permissions" capability="admin.roles"><PermissionsPage /></Guard>} />
            <Route path="audit-trail" element={<Guard page="audit_trail" capability="admin.users"><AuditTrailPage /></Guard>} />
            <Route path="settings" element={<Guard capability="admin.users"><SettingsPage /></Guard>} />
          </Route>

          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
    </Suspense>
  );
}
